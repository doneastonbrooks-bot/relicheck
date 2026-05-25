<?php
// POST /api/mm/build.php
// Body: { project_id, mode: "auto"|"guided"|"hybrid", target_clusters?, user_categories?, outcome_focus? }
//
// Two-pass builder so the AI never has to invent categories and code hundreds
// of responses in a single call:
//   Pass 1 (Discovery): sample up to 150 responses, ask Claude to produce the
//   category set only. Smaller prompt = clean JSON.
//   Pass 2 (Coding): code the FULL response set in batches of 100. Each batch
//   just maps response numbers to the existing categories + sentiment.

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
// Per-question theme generation (tester feedback May 2026): when a
// question_id is supplied, responses + saved categories are scoped to
// THAT question only. The caller is expected to loop over each
// open-ended question and call this endpoint once per question, so
// the resulting codebook has question-specific themes (matching how
// the main Analyze "Find themes" button already works), not generic
// blob themes spanning every open-ended item.
$questionId      = isset($body['question_id']) && $body['question_id'] !== '' && $body['question_id'] !== null
                     ? (int)$body['question_id']
                     : null;

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
$responses  = mm_load_responses($pdo, $projectId, 3000);
// If a question_id was supplied, scope the response set to just that
// question. Responses are stored with question_id_raw per row when the
// project was loaded with proper question-level metadata.
if ($questionId !== null) {
    $responses = array_values(array_filter($responses, function ($r) use ($questionId) {
        return isset($r['question_id_raw']) && (int)$r['question_id_raw'] === $questionId;
    }));
}
$totalCount = count($responses);
if ($totalCount < 3) {
    fail('insufficient_data',
        $questionId !== null
            ? 'This question has only ' . $totalCount . ' text response(s). The builder needs at least 3 to find meaningful themes.'
            : 'The project needs at least 3 text responses before the builder can run.');
}

$truncate = function (string $t, int $max = 600): string {
    if (strlen($t) > $max) return substr($t, 0, $max) . '...';
    return $t;
};

// ----- Pass 1: Discovery (category set only) -----
$sampleSize = min(150, $totalCount);
$sample = $responses;
if ($totalCount > $sampleSize) {
    $step = (int)floor($totalCount / $sampleSize);
    if ($step < 1) $step = 1;
    $picked = [];
    for ($i = 0; $i < $totalCount && count($picked) < $sampleSize; $i += $step) {
        $picked[] = $responses[$i];
    }
    $sample = $picked;
}

$sampleLines = [];
foreach ($sample as $i => $r) {
    $sampleLines[] = ($i + 1) . '. ' . $truncate((string)$r['text']);
}
$sampleBlock = implode("\n", $sampleLines);

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
$focusLine = $outcomeFocus !== '' ? ("Analysis lens: " . $outcomeFocus . "\n") : '';

$systemDiscover = <<<SYS
You are a mixed-methods qualitative coder. The user gives you a sample of open-ended responses. Your only job in this turn is to produce a CATEGORY SET that describes the recurring patterns.

Rules:
- Categories are short noun phrases, 2-5 words, sentence case.
- A category is a recurring pattern across multiple responses, not a paraphrase of one response.
- For each category include a one-sentence description, a confidence (high, moderate, low), and 2-3 short example quotes drawn verbatim from the responses.
- Do not assign codes to individual responses in this turn.
- Never invent quotes.

Output a single JSON object with this exact shape:

{
  "categories": [
    {
      "name":        "<short>",
      "description": "<one sentence>",
      "confidence":  "high" | "moderate" | "low",
      "examples":    ["<verbatim quote>", "<verbatim quote>"]
    }
  ],
  "summary": "<one sentence>"
}

Output raw JSON only. No markdown fences, no prose.
SYS;

$discoverPrompt  = $modeLine . "\n" . $focusLine;
$discoverPrompt .= "Sample size: " . count($sample) . " of " . $totalCount . " total responses.\n\n";
$discoverPrompt .= "Sample:\n" . $sampleBlock . "\n\n";
$discoverPrompt .= "Produce the category set now.";

$resp = ai_complete($systemDiscover, [['role' => 'user', 'content' => $discoverPrompt]], 4000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['categories']) || !is_array($parsed['categories'])) {
    $tighter = $systemDiscover . "\n\nIMPORTANT: Your last response was not valid JSON. Return ONLY a JSON object starting with { and ending with }. No prose, no fences.";
    $resp2 = ai_complete($tighter, [['role' => 'user', 'content' => $discoverPrompt]], 4000);
    $parsed = ai_extract_json($resp2['text']);
    if (!$parsed || !isset($parsed['categories']) || !is_array($parsed['categories'])) {
        fail('ai_parse_failed', 'AI could not produce a clean category set on a sample of ' . count($sample) . ' responses. Try again, or split the project by question.', 502);
    }
}

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
    $catRows[] = ['name' => $name, 'description' => $desc, 'confidence' => $conf, 'examples' => $examples];
    if (count($catRows) >= 20) break;
}
if (count($catRows) === 0) fail('ai_empty_result', 'AI returned no categories. Try again.', 502);

$summary = clean_string((string)($parsed['summary'] ?? ''), 600);
$categoryNames = array_map(function ($r) { return $r['name']; }, $catRows);

// Persist categories first.
// When question_id is supplied, the delete + insert is scoped to that
// question so a per-question loop doesn't wipe out the previous
// question's themes. When question_id is null, the legacy whole-project
// behavior is preserved.
$pdo->beginTransaction();
try {
    if ($questionId !== null) {
        // Scoped delete: only auto-generated categories for this specific
        // question. Coded responses linked to those categories cascade
        // through the category_id reference.
        $deleteCat = $pdo->prepare(
            'DELETE FROM mm_theme_categories
             WHERE project_id = :p AND question_id = :q AND source_mode <> "user"'
        );
        $deleteCat->execute([':p' => $projectId, ':q' => $questionId]);
        // Sentiment + coded responses are still cleared because they'll
        // be re-derived from the new categories in Pass 2.
        $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p AND coder_id = :u')->execute([':p' => $projectId, ':u' => $uid]);
        $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p')->execute([':p' => $projectId]);
    } else {
        // Legacy whole-project delete.
        $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p AND coder_id = :u')->execute([':p' => $projectId, ':u' => $uid]);
        $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p')->execute([':p' => $projectId]);
        $pdo->prepare('DELETE FROM mm_theme_categories WHERE project_id = :p AND source_mode <> "user"')->execute([':p' => $projectId]);
    }

    $insertCat = $pdo->prepare(
        'INSERT INTO mm_theme_categories (project_id, name, description, source_mode, confidence, position, question_id)
         VALUES (:p, :n, :d, :sm, :c, :pos, :q)'
    );
    $catIdByLower = [];
    $userCatStmt = $pdo->prepare('SELECT id, name FROM mm_theme_categories WHERE project_id = :p');
    $userCatStmt->execute([':p' => $projectId]);
    foreach ($userCatStmt->fetchAll(PDO::FETCH_ASSOC) as $uc) {
        $catIdByLower[mb_strtolower($uc['name'])] = (int)$uc['id'];
    }
    foreach ($catRows as $i => $cr) {
        $insertCat->execute([
            ':p'   => $projectId, ':n' => $cr['name'], ':d' => $cr['description'],
            ':sm'  => $mode,      ':c' => $cr['confidence'], ':pos' => $i + 1,
            ':q'   => $questionId, // null is fine — column allows NULL
        ]);
        $catIdByLower[mb_strtolower($cr['name'])] = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_build_failed', 'Could not save categories: ' . $e->getMessage(), 500);
}

// ----- Pass 2: Coding (full set, batched) -----
$systemCode = <<<SYS
You are a mixed-methods qualitative coder. You are given a fixed category set and a numbered batch of open-ended responses. Code each response with rich per-category metadata.

Rules:
- Use ONLY the categories listed. Do not invent new ones, do not rename them.
- For each response, output its number, sentiment, confidence, and a list of per-category items. Each per-category item has:
    * "name"        (must match a category in the list exactly)
    * "intensity"   ("low" = passing mention, "moderate" = clear mention, "high" = central focus of the response)
    * "relevance"   ("usable" = clearly about this category, "unclear" = possible but ambiguous, "off_topic" = does not really fit)
    * "quote_worthy" (true if the response would make a strong pull-quote for a report, false otherwise)
- A response may match one OR many categories. If a response is off-topic to all categories, still output the single closest category with relevance "off_topic" and intensity "low".
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

$insertCode = $pdo->prepare(
    'INSERT INTO mm_coded_responses
     (project_id, response_id, category_id, coder_id, confidence, intensity, relevance, quote_worthy)
     VALUES (:p, :r, :c, :u, :cf, :int, :rel, :qw)
     ON DUPLICATE KEY UPDATE
        confidence = VALUES(confidence),
        intensity  = VALUES(intensity),
        relevance  = VALUES(relevance),
        quote_worthy = VALUES(quote_worthy)'
);
$insertSent = $pdo->prepare(
    'INSERT INTO mm_sentiment_scores (project_id, response_id, sentiment, confidence)
     VALUES (:p, :r, :s, :cf)
     ON DUPLICATE KEY UPDATE sentiment = VALUES(sentiment), confidence = VALUES(confidence)'
);

$batchSize  = 100;
$totalCoded = 0;
$batchFailures = 0;

for ($offset = 0; $offset < $totalCount; $offset += $batchSize) {
    $batch = array_slice($responses, $offset, $batchSize);
    $idIndex = [];
    $lines = [];
    foreach ($batch as $i => $r) {
        $n = $i + 1;
        $idIndex[$n] = (int)$r['id'];
        $lines[] = $n . '. ' . $truncate((string)$r['text']);
    }
    $userMsg  = "Category set:\n- " . implode("\n- ", $categoryNames) . "\n\n";
    $userMsg .= "Batch (" . count($batch) . " responses, offset " . $offset . " of " . $totalCount . "):\n";
    $userMsg .= implode("\n", $lines) . "\n\n";
    $userMsg .= "Code every response in this batch.";

    try {
        $bresp = ai_complete($systemCode, [['role' => 'user', 'content' => $userMsg]], 4000);
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

        $cats = (isset($code['categories']) && is_array($code['categories'])) ? $code['categories'] : [];
        $anyMatched = false;
        foreach ($cats as $catEntry) {
            // Tolerate both shapes: a string (legacy) or an object with metadata.
            if (is_string($catEntry)) {
                $cnClean = clean_string($catEntry, 200);
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
            if (!isset($catIdByLower[$key])) continue;
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
            $anyMatched = true;
        }
        $insertSent->execute([
            ':p' => $projectId, ':r' => $responseId, ':s' => $sent, ':cf' => $codeConf,
        ]);
        if ($anyMatched) $totalCoded++;
    }
}

// Final assembly.
$catOut = [];
$catLookup = $pdo->prepare(
    'SELECT c.id, c.name, c.description, c.confidence, c.source_mode,
            (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS coded_count
     FROM mm_theme_categories c WHERE c.project_id = :p ORDER BY c.position ASC, c.id ASC'
);
$catLookup->execute([':p' => $projectId]);
foreach ($catLookup->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $catOut[] = [
        'id' => (int)$r['id'], 'name' => (string)$r['name'],
        'description' => (string)($r['description'] ?? ''), 'confidence' => (string)$r['confidence'],
        'source_mode' => (string)$r['source_mode'], 'coded_count' => (int)$r['coded_count'],
    ];
}

$sentDist = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
$sentStmt = $pdo->prepare('SELECT sentiment, COUNT(*) AS n FROM mm_sentiment_scores WHERE project_id = :p GROUP BY sentiment');
$sentStmt->execute([':p' => $projectId]);
foreach ($sentStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = (string)$r['sentiment'];
    if (isset($sentDist[$key])) $sentDist[$key] = (int)$r['n'];
}

json_out([
    'ok'              => true,
    'mode'            => $mode,
    'total_responses' => $totalCount,
    'sample_size'     => count($sample),
    'batch_failures'  => $batchFailures,
    'categories'      => $catOut,
    'sentiment'       => $sentDist,
    'summary'         => $summary,
    'model'           => ai_config()['model'],
]);
