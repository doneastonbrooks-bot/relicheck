<?php
// POST /api/mm/build.php
// Body: {
//   project_id,
//   mode: "auto" | "guided" | "hybrid",
//   target_clusters?: number (3-15, default 6),
//   user_categories?: [string, ...],         // for guided / hybrid seed
//   outcome_focus?: string                   // optional analysis lens
// }
//
// Runs the Qualitative-to-Quantitative Builder end-to-end:
//   1. pulls the project's text responses
//   2. asks the AI to produce categories, sentiment, and per-response codes
//   3. writes mm_theme_categories, mm_coded_responses, mm_sentiment_scores
//   4. returns a structured payload the front end can render and let the
//      user review (rename / merge / split / delete / add) before finalizing.
//
// Re-running this endpoint replaces the auto-generated categories and codes
// for the project. User-edited categories (source_mode = 'user') survive,
// but their coding is recomputed against the new pass.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

check_rate_limit('mm_build:user:' . $uid, 30, 3600);

$body            = read_json_body();
$projectId       = (int)($body['project_id'] ?? 0);
$mode            = clean_string((string)($body['mode'] ?? 'hybrid'), 16);
$targetClusters  = (int)($body['target_clusters'] ?? 6);
$rawCategories   = $body['user_categories'] ?? [];
$outcomeFocus    = clean_string((string)($body['outcome_focus'] ?? ''), 200);

if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
if (!in_array($mode, ['auto', 'guided', 'hybrid'], true)) {
    fail('bad_input', 'Mode must be auto, guided, or hybrid.');
}
if ($targetClusters < 3) $targetClusters = 3;
if ($targetClusters > 15) $targetClusters = 15;

$userCategories = [];
if (is_array($rawCategories)) {
    foreach ($rawCategories as $c) {
        $name = clean_string((string)$c, 120);
        if ($name !== '') $userCategories[] = $name;
        if (count($userCategories) >= 15) break;
    }
}
if ($mode === 'guided' && count($userCategories) < 2) {
    fail('bad_input', 'Guided mode needs at least 2 user_categories.');
}

mm_require_project($pdo, $uid, $projectId);
$responses = mm_load_responses($pdo, $projectId, 600);
if (count($responses) < 3) {
    fail('insufficient_data', 'The project needs at least 3 text responses before the builder can run.');
}

// Build the numbered prompt block. Cap each response so the total token
// budget stays predictable.
$lines  = [];
$idIndex = [];
foreach ($responses as $i => $r) {
    $text = (string)$r['text'];
    if (strlen($text) > 800) $text = substr($text, 0, 800) . '...';
    $n = $i + 1;
    $lines[] = $n . '. ' . $text;
    $idIndex[$n] = (int)$r['id'];
}
$responseBlock = implode("\n", $lines);
$totalCount    = count($responses);

$modeLine = '';
if ($mode === 'auto') {
    $modeLine = "Mode: AUTO. Discover the categories yourself. Produce " . $targetClusters . " categories.";
} elseif ($mode === 'guided') {
    $modeLine = "Mode: GUIDED. Use these categories and these only: "
        . implode(', ', $userCategories) . ". A response may carry more than one.";
} else {
    $seed = count($userCategories) > 0 ? (' Seed suggestions from the user: ' . implode(', ', $userCategories) . '.') : '';
    $modeLine = "Mode: HYBRID. Propose " . $targetClusters . " categories." . $seed
        . " You may keep, rename, merge, or replace the seed suggestions as the data demands.";
}
$focusLine = $outcomeFocus !== '' ? ("Analysis lens: " . $outcomeFocus) : '';

$system = <<<SYS
You are a mixed-methods qualitative coder. The user gives you a numbered list of open-ended responses. Produce a category set and per-response codes so the responses can be analyzed as structured data.

Rules:
- Categories are short noun phrases, 2-5 words, sentence case. Examples: "Communication gaps", "Workload pressure", "Supportive supervisor".
- A category is a recurring pattern across multiple responses, not a paraphrase of one response.
- For each category include a one-sentence description, a confidence (high, moderate, low), and 2-3 short example quotes drawn verbatim from the responses.
- For every response, assign it to one or more categories from your set, and label its sentiment (positive, neutral, negative, mixed).
- If a response is ambiguous or off-topic, still pick the closest category and mark sentiment "neutral" with confidence "low".
- Never invent quotes or responses.

Output a single JSON object with this exact shape:

{
  "categories": [
    {
      "name":        "<short category name>",
      "description": "<one sentence>",
      "confidence":  "high" | "moderate" | "low",
      "examples":    ["<verbatim quote>", "<verbatim quote>"]
    }
  ],
  "codes": [
    {
      "response_n":  <integer from the numbered list>,
      "categories":  ["<category name>", "..."],
      "sentiment":   "positive" | "neutral" | "negative" | "mixed",
      "confidence":  "high" | "moderate" | "low"
    }
  ],
  "summary": "<one sentence summarizing the dominant signal>"
}

Do not wrap the JSON in markdown fences. Output the raw JSON.
SYS;

$userPrompt  = $modeLine . "\n";
if ($focusLine !== '') $userPrompt .= $focusLine . "\n";
$userPrompt .= "Total responses: " . $totalCount . "\n\n";
$userPrompt .= "Responses:\n" . $responseBlock . "\n\n";
$userPrompt .= "Produce categories and codes now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 6000);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['categories']) || !is_array($parsed['categories'])) {
    fail('ai_parse_failed', 'AI did not return a usable response. Try again.', 502);
}

// Clean and validate categories.
$catRows = [];
$catKeyByName = [];
foreach ($parsed['categories'] as $c) {
    if (!is_array($c)) continue;
    $name = clean_string((string)($c['name'] ?? ''), 200);
    if ($name === '') continue;
    $desc = clean_string((string)($c['description'] ?? ''), 600);
    $conf = strtolower(clean_string((string)($c['confidence'] ?? 'moderate'), 16));
    if (!in_array($conf, ['high','moderate','low'], true)) $conf = 'moderate';
    $examples = [];
    if (isset($c['examples']) && is_array($c['examples'])) {
        foreach ($c['examples'] as $ex) {
            $exClean = clean_string((string)$ex, 600);
            if ($exClean !== '') $examples[] = $exClean;
            if (count($examples) === 3) break;
        }
    }
    $key = mb_strtolower($name);
    if (isset($catKeyByName[$key])) continue;
    $catKeyByName[$key] = count($catRows);
    $catRows[] = [
        'name'        => $name,
        'description' => $desc,
        'confidence'  => $conf,
        'examples'    => $examples,
    ];
    if (count($catRows) >= 20) break;
}

if (count($catRows) === 0) {
    fail('ai_empty_result', 'AI returned no categories. Try again.', 502);
}

// Persist: replace prior auto/guided/hybrid categories and the coded/sentiment
// rows that hang off them. User-edited categories are preserved.
$pdo->beginTransaction();
try {
    // Wipe non-user categories and dependent rows for this project.
    $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_theme_categories WHERE project_id = :p AND source_mode <> "user"')->execute([':p' => $projectId]);

    $insertCat = $pdo->prepare(
        'INSERT INTO mm_theme_categories (project_id, name, description, source_mode, confidence, position)
         VALUES (:p, :n, :d, :sm, :c, :pos)'
    );
    $catIdByLower = [];
    // Re-read existing user-edited categories so codes can also point to them.
    $userCatStmt = $pdo->prepare('SELECT id, name FROM mm_theme_categories WHERE project_id = :p');
    $userCatStmt->execute([':p' => $projectId]);
    foreach ($userCatStmt->fetchAll(PDO::FETCH_ASSOC) as $uc) {
        $catIdByLower[mb_strtolower($uc['name'])] = (int)$uc['id'];
    }
    foreach ($catRows as $i => $cr) {
        $insertCat->execute([
            ':p'   => $projectId,
            ':n'   => $cr['name'],
            ':d'   => $cr['description'],
            ':sm'  => $mode,
            ':c'   => $cr['confidence'],
            ':pos' => $i + 1,
        ]);
        $catIdByLower[mb_strtolower($cr['name'])] = (int)$pdo->lastInsertId();
    }

    // Codes.
    $insertCode = $pdo->prepare(
        'INSERT IGNORE INTO mm_coded_responses (project_id, response_id, category_id, confidence)
         VALUES (:p, :r, :c, :cf)'
    );
    $insertSent = $pdo->prepare(
        'INSERT INTO mm_sentiment_scores (project_id, response_id, sentiment, confidence)
         VALUES (:p, :r, :s, :cf)
         ON DUPLICATE KEY UPDATE sentiment = VALUES(sentiment), confidence = VALUES(confidence)'
    );
    if (isset($parsed['codes']) && is_array($parsed['codes'])) {
        foreach ($parsed['codes'] as $code) {
            if (!is_array($code)) continue;
            $n = (int)($code['response_n'] ?? 0);
            if (!isset($idIndex[$n])) continue;
            $responseId = $idIndex[$n];
            $codeConf = strtolower(clean_string((string)($code['confidence'] ?? 'moderate'), 16));
            if (!in_array($codeConf, ['high','moderate','low'], true)) $codeConf = 'moderate';
            $sent = strtolower(clean_string((string)($code['sentiment'] ?? 'neutral'), 16));
            if (!in_array($sent, ['positive','neutral','negative','mixed'], true)) $sent = 'neutral';

            $cats = (isset($code['categories']) && is_array($code['categories'])) ? $code['categories'] : [];
            foreach ($cats as $cn) {
                $cnClean = clean_string((string)$cn, 200);
                if ($cnClean === '') continue;
                $key = mb_strtolower($cnClean);
                if (!isset($catIdByLower[$key])) continue;
                $insertCode->execute([
                    ':p'  => $projectId,
                    ':r'  => $responseId,
                    ':c'  => $catIdByLower[$key],
                    ':cf' => $codeConf,
                ]);
            }
            $insertSent->execute([
                ':p'  => $projectId,
                ':r'  => $responseId,
                ':s'  => $sent,
                ':cf' => $codeConf,
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_build_failed', 'Could not save builder output: ' . $e->getMessage(), 500);
}

// Build the response payload (category counts + previews).
$catOut = [];
$catLookup = $pdo->prepare(
    'SELECT c.id, c.name, c.description, c.confidence, c.source_mode,
            (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS coded_count
     FROM mm_theme_categories c WHERE c.project_id = :p ORDER BY c.position ASC, c.id ASC'
);
$catLookup->execute([':p' => $projectId]);
foreach ($catLookup->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $catOut[] = [
        'id'          => (int)$r['id'],
        'name'        => (string)$r['name'],
        'description' => (string)($r['description'] ?? ''),
        'confidence'  => (string)$r['confidence'],
        'source_mode' => (string)$r['source_mode'],
        'coded_count' => (int)$r['coded_count'],
    ];
}

// Sentiment distribution.
$sentDist = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
$sentStmt = $pdo->prepare(
    'SELECT sentiment, COUNT(*) AS n FROM mm_sentiment_scores WHERE project_id = :p GROUP BY sentiment'
);
$sentStmt->execute([':p' => $projectId]);
foreach ($sentStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = (string)$r['sentiment'];
    if (isset($sentDist[$key])) $sentDist[$key] = (int)$r['n'];
}

json_out([
    'ok'             => true,
    'mode'           => $mode,
    'total_responses'=> $totalCount,
    'categories'     => $catOut,
    'sentiment'      => $sentDist,
    'summary'        => clean_string((string)($parsed['summary'] ?? ''), 600),
    'model'          => ai_config()['model'],
]);
