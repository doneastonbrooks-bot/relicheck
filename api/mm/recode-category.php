<?php
// POST /api/mm/recode-category.php
// Body: { project_id, category_id }
//
// Re-runs the AI coding pass for one category only. Used after a definition
// edit so the user does not have to rebuild the whole project. Wipes the
// coded rows tied to this category, then re-codes each response against the
// fresh definition.

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

check_rate_limit('mm_recode:user:' . $uid, 30, 3600);

$body       = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$categoryId = (int)($body['category_id'] ?? 0);
if ($projectId <= 0 || $categoryId <= 0) fail('bad_input', 'project_id and category_id are required.');
mm_require_project($pdo, $uid, $projectId);

$cstmt = $pdo->prepare('SELECT * FROM mm_theme_categories WHERE id = :i AND project_id = :p');
$cstmt->execute([':i' => $categoryId, ':p' => $projectId]);
$cat = $cstmt->fetch(PDO::FETCH_ASSOC);
if (!$cat) fail('mm_category_not_found', 'Category not found in this project.', 404);

$responses = mm_load_responses($pdo, $projectId, 1500);
$total = count($responses);
if ($total < 3) fail('insufficient_data', 'Need at least 3 responses.');

$catName = (string)$cat['name'];
$catDef  = (string)($cat['definition'] ?? $cat['description'] ?? '');

$truncate = function (string $t, int $max = 600): string {
    if (strlen($t) > $max) return substr($t, 0, $max) . '...';
    return $t;
};

$system = <<<SYS
You are a mixed-methods qualitative coder. You are given ONE category and a numbered batch of open-ended responses. Decide which responses fit this category, and label each match with intensity, relevance, sentiment, confidence, and a quote_worthy flag.

Rules:
- Only output responses that fit this category, with at least relevance "usable" or "unclear". Skip responses that are clearly off-topic.
- A response can match this category exactly once (no duplicates).
- "intensity": "low" (passing mention), "moderate" (clear mention), "high" (central focus).
- "relevance": "usable" (clearly about this category), "unclear" (possible but ambiguous), "off_topic" (does not fit, do not include).
- "quote_worthy": true if the response would make a strong pull-quote, false otherwise.
- Output sentiment per response: positive, neutral, negative, or mixed.
- Output confidence per response: high, moderate, or low.

Output a single JSON object:

{
  "matches": [
    {
      "response_n":   <int>,
      "intensity":    "low" | "moderate" | "high",
      "relevance":    "usable" | "unclear",
      "quote_worthy": true | false,
      "sentiment":    "positive" | "neutral" | "negative" | "mixed",
      "confidence":   "high" | "moderate" | "low"
    }
  ]
}

Output raw JSON only. No markdown fences.
SYS;

$pdo->beginTransaction();
try {
    // Wipe existing codes for this category.
    $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p AND category_id = :c AND coder_id = :u')
        ->execute([':p' => $projectId, ':c' => $categoryId, ':u' => $uid]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_recode_purge_failed', 'Could not clear existing codes: ' . $e->getMessage(), 500);
}

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

$batchSize     = 100;
$matchedCount  = 0;
$batchFailures = 0;

for ($offset = 0; $offset < $total; $offset += $batchSize) {
    $batch = array_slice($responses, $offset, $batchSize);
    $idIndex = [];
    $lines = [];
    foreach ($batch as $i => $r) {
        $n = $i + 1;
        $idIndex[$n] = (int)$r['id'];
        $lines[] = $n . '. ' . $truncate((string)$r['text']);
    }

    $userMsg  = "Category name: " . $catName . "\n";
    if ($catDef !== '') $userMsg .= "Definition: " . $catDef . "\n";
    $userMsg .= "\nBatch (" . count($batch) . " responses, offset " . $offset . " of " . $total . "):\n";
    $userMsg .= implode("\n", $lines) . "\n\n";
    $userMsg .= "List responses that fit this category.";

    try {
        $bresp = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 3000);
        $bparsed = ai_extract_json($bresp['text']);
        if (!$bparsed || !isset($bparsed['matches']) || !is_array($bparsed['matches'])) {
            $batchFailures++;
            continue;
        }
    } catch (Throwable $e) {
        $batchFailures++;
        continue;
    }

    foreach ($bparsed['matches'] as $m) {
        if (!is_array($m)) continue;
        $n = (int)($m['response_n'] ?? 0);
        if (!isset($idIndex[$n])) continue;
        $responseId = $idIndex[$n];
        $codeConf = strtolower(clean_string((string)($m['confidence'] ?? 'moderate'), 16));
        if (!in_array($codeConf, ['high','moderate','low'], true)) $codeConf = 'moderate';
        $sent = strtolower(clean_string((string)($m['sentiment'] ?? 'neutral'), 16));
        if (!in_array($sent, ['positive','neutral','negative','mixed'], true)) $sent = 'neutral';
        $intensity = strtolower(clean_string((string)($m['intensity'] ?? 'moderate'), 16));
        if (!in_array($intensity, ['low','moderate','high'], true)) $intensity = 'moderate';
        $relevance = strtolower(clean_string((string)($m['relevance'] ?? 'usable'), 16));
        if (!in_array($relevance, ['usable','unclear','off_topic'], true)) $relevance = 'usable';
        if ($relevance === 'off_topic') continue;
        $qw = !empty($m['quote_worthy']) ? 1 : 0;

        $insertCode->execute([
            ':p'   => $projectId,
            ':r'   => $responseId,
            ':c'   => $categoryId,
            ':u'   => $uid,
            ':cf'  => $codeConf,
            ':int' => $intensity,
            ':rel' => $relevance,
            ':qw'  => $qw,
        ]);
        $insertSent->execute([
            ':p' => $projectId, ':r' => $responseId, ':s' => $sent, ':cf' => $codeConf,
        ]);
        $matchedCount++;
    }
}

json_out([
    'ok'            => true,
    'category_id'   => $categoryId,
    'matched'       => $matchedCount,
    'batch_failures'=> $batchFailures,
    'model'         => ai_config()['model'],
]);
