<?php
// POST /api/mm/code-existing-semantic.php
// Body: { project_id }
//
// Semantically codes every text response against the project's EXISTING themes
// using ReliCheck Intelligence (Claude). This is the semantic counterpart to
// code-existing.php (which does literal keyword overlap and misses concepts
// expressed in different words — "culture" vs "cultural", "budget" vs
// "resources"). It UNDERSTANDS meaning, so a theme like "Cultural_Mismatch"
// matches responses that never use the literal word.
//
// Unlike build.php, it NEVER discovers, inserts, renames, or deletes
// categories. It reads the themes already defined for the project and only
// writes mm_coded_responses + mm_sentiment_scores, so the user's hand-built
// codebook is preserved exactly (no duplicate themes).
//
// Mirrors code-existing.php's request/response contract: returns
// { ok, coded_rows, ... } so the front-end can swap it in with no other change.
//
// The coding prompt + parsing are lifted verbatim from build.php Pass 2 so the
// two AI coders stay behaviorally identical.

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

check_rate_limit('mm_code_semantic:user:' . $uid, 30, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// ----- Existing themes (NO discovery, NO insert) -----
$catStmt = $pdo->prepare(
    'SELECT id, name, COALESCE(description, "") AS description
     FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$catStmt->execute([':p' => $projectId]);
$cats = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($cats) === 0) fail('mm_no_categories', 'No themes exist yet. Add or discover themes first, then re-tag.');

// Map theme name (lower) -> id, and build the labelled category block for the
// prompt. Descriptions are included so the model knows what each theme MEANS,
// which is the whole point of semantic coding.
$catIdByLower  = [];
$categoryNames = [];
$catLines      = [];
foreach ($cats as $c) {
    $nm  = (string)$c['name'];
    $key = mb_strtolower($nm);
    if (!isset($catIdByLower[$key])) $catIdByLower[$key] = (int)$c['id'];
    $categoryNames[] = $nm;
    $desc = (string)$c['description'];
    $catLines[] = '- ' . $nm . ($desc !== '' ? ': ' . $desc : '');
}

// ----- Responses -----
$responses  = mm_load_responses($pdo, $projectId, 3000);
$totalCount = count($responses);
if ($totalCount < 1) fail('insufficient_data', 'This project has no text responses to code.');

$truncate = function (string $t, int $max = 600): string {
    if (strlen($t) > $max) return substr($t, 0, $max) . '...';
    return $t;
};

// ----- Coding prompt (verbatim from build.php Pass 2) -----
$systemCode = <<<SYS
You are a mixed-methods qualitative coder. You are given a fixed category set and a numbered batch of open-ended responses. Code each response with rich per-category metadata.

Rules:
- Use ONLY the categories listed. Do not invent new ones, do not rename them.
- For each response, output its number, sentiment, confidence, and a list of per-category items. Each per-category item has:
    * "name"        (must match a category in the list exactly)
    * "intensity"   ("low" = passing mention, "moderate" = clear mention, "high" = central focus of the response)
    * "relevance"   ("usable" = clearly about this category, "unclear" = possible but ambiguous, "off_topic" = does not really fit)
    * "quote_worthy" (true if the response would make a strong pull-quote for a report, false otherwise)
- A response may match one OR many categories. If a response is off-topic to all categories, do NOT force a match — return an empty categories list for it.
- Sentiment is per response (not per category): positive, neutral, negative, or mixed.
- Confidence is per response: high, moderate, or low.

Output a single JSON object with this exact shape:

{
  "codes": [
    {
      "response_n": <integer>,
      "sentiment":  "positive" | "neutral" | "negative" | "mixed",
      "confidence": "high" | "moderate" | "low",
      "categories": [
        {
          "name":          "<category name>",
          "intensity":     "low" | "moderate" | "high",
          "relevance":     "usable" | "unclear" | "off_topic",
          "quote_worthy":  true | false
        }
      ]
    }
  ]
}

Output the raw JSON only. No markdown fences.
SYS;

// ----- Replace prior codes for a clean re-tag (same as code-existing.php) -----
$pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p AND coder_id = :u')->execute([':p' => $projectId, ':u' => $uid]);
$pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p')->execute([':p' => $projectId]);

$insertCode = $pdo->prepare(
    'INSERT INTO mm_coded_responses
     (project_id, response_id, category_id, coder_id, confidence, intensity, relevance, quote_worthy)
     VALUES (:p, :r, :c, :u, :cf, :int, :rel, :qw)
     ON DUPLICATE KEY UPDATE
        confidence   = VALUES(confidence),
        intensity    = VALUES(intensity),
        relevance    = VALUES(relevance),
        quote_worthy = VALUES(quote_worthy)'
);
$insertSent = $pdo->prepare(
    'INSERT INTO mm_sentiment_scores (project_id, response_id, sentiment, confidence)
     VALUES (:p, :r, :s, :cf)
     ON DUPLICATE KEY UPDATE sentiment = VALUES(sentiment), confidence = VALUES(confidence)'
);

$batchSize          = 100;
$codeRows           = 0;   // total (response, theme) rows written
$responsesWithCode  = 0;   // responses that matched at least one theme
$batchFailures      = 0;

for ($offset = 0; $offset < $totalCount; $offset += $batchSize) {
    $batch   = array_slice($responses, $offset, $batchSize);
    $idIndex = [];
    $lines   = [];
    foreach ($batch as $i => $r) {
        $n = $i + 1;
        $idIndex[$n] = (int)$r['id'];
        $lines[] = $n . '. ' . $truncate((string)$r['text']);
    }
    $userMsg  = "Category set:\n" . implode("\n", $catLines) . "\n\n";
    $userMsg .= "Batch (" . count($batch) . " responses, offset " . $offset . " of " . $totalCount . "):\n";
    $userMsg .= implode("\n", $lines) . "\n\n";
    $userMsg .= "Code every response in this batch.";

    try {
        $bresp   = ai_complete($systemCode, [['role' => 'user', 'content' => $userMsg]], 4000);
        $bparsed = ai_extract_json($bresp['text']);
        if (!$bparsed || !isset($bparsed['codes']) || !is_array($bparsed['codes'])) {
            $batchFailures++;
            continue;
        }
    } catch (Throwable $e) {
        $batchFailures++;
        continue;
    }

    foreach ($bparsed['codes'] as $code) {
        if (!is_array($code)) continue;
        $n = (int)($code['response_n'] ?? 0);
        if (!isset($idIndex[$n])) continue;
        $responseId = $idIndex[$n];
        $codeConf = strtolower(clean_string((string)($code['confidence'] ?? 'moderate'), 16));
        if (!in_array($codeConf, ['high','moderate','low'], true)) $codeConf = 'moderate';
        $sent = strtolower(clean_string((string)($code['sentiment'] ?? 'neutral'), 16));
        if (!in_array($sent, ['positive','neutral','negative','mixed'], true)) $sent = 'neutral';

        $cats2 = (isset($code['categories']) && is_array($code['categories'])) ? $code['categories'] : [];
        $anyMatched = false;
        foreach ($cats2 as $catEntry) {
            // Tolerate both shapes: a string (legacy) or an object with metadata.
            if (is_string($catEntry)) {
                $cnClean     = clean_string($catEntry, 200);
                $intensity   = 'moderate';
                $relevance   = 'usable';
                $quoteWorthy = 0;
            } elseif (is_array($catEntry)) {
                $cnClean = clean_string((string)($catEntry['name'] ?? ''), 200);
                $intensity = strtolower(clean_string((string)($catEntry['intensity'] ?? 'moderate'), 16));
                if (!in_array($intensity, ['low','moderate','high'], true)) $intensity = 'moderate';
                $relevance = strtolower(clean_string((string)($catEntry['relevance'] ?? 'usable'), 16));
                if (!in_array($relevance, ['usable','unclear','off_topic'], true)) $relevance = 'usable';
                $quoteWorthy = !empty($catEntry['quote_worthy']) ? 1 : 0;
            } else {
                continue;
            }
            if ($cnClean === '') continue;
            $key = mb_strtolower($cnClean);
            if (!isset($catIdByLower[$key])) continue; // model named a theme we don't have — skip
            $insertCode->execute([
                ':p'   => $projectId,
                ':r'   => $responseId,
                ':c'   => $catIdByLower[$key],
                ':u'   => $uid,
                ':cf'  => $codeConf,
                ':int' => $intensity,
                ':rel' => $relevance,
                ':qw'  => $quoteWorthy,
            ]);
            $codeRows++;
            $anyMatched = true;
        }
        $insertSent->execute([
            ':p' => $projectId, ':r' => $responseId, ':s' => $sent, ':cf' => $codeConf,
        ]);
        if ($anyMatched) $responsesWithCode++;
    }
}

if ($batchFailures > 0 && $codeRows === 0) {
    fail('mm_semantic_code_failed', 'ReliCheck Intelligence could not code the responses (all batches failed). Try again.', 502);
}

json_out([
    'ok'                              => true,
    'engine'                          => 'semantic_coder_v1',
    'theme_count'                     => count($cats),
    'response_count'                  => $totalCount,
    'coded_rows'                      => $responsesWithCode, // toast: "Tagged N responses to themes"
    'code_row_total'                  => $codeRows,
    'responses_with_at_least_one_code'=> $responsesWithCode,
    'batch_failures'                  => $batchFailures,
    'model'                           => ai_config()['model'],
]);
