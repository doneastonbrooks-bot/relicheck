<?php
// POST /api/ai/score-sentiment.php
// Body: { "texts": ["...", "...", ...] }
//
// Returns one sentiment classification per input text, in the same order:
//   { results: [ { sentiment, reason }, ... ], total: <int> }
//
// "sentiment" is one of: positive, neutral, negative, mixed.
// "reason"    is a short phrase (under 60 chars) explaining the classification.
//
// Works for surveys, datasets, or any caller that has open-ended text in hand.
// The endpoint never sees response IDs or any PII - just the raw text.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 30 batches per user per hour. Each batch can score up to 200 texts.
check_rate_limit('ai_sent:user:' . (int)$user['id'], 30, 3600);

$body  = read_json_body();
$texts = is_array($body['texts'] ?? null) ? $body['texts'] : [];

if (count($texts) === 0) fail('no_texts', 'Send at least one text to score.');

// Normalize: strings only, trimmed, capped at 600 chars per text, 200 texts max.
$cleanTexts = [];
foreach ($texts as $t) {
    if (!is_string($t)) continue;
    $t = trim($t);
    if ($t === '') continue;
    if (strlen($t) > 600) $t = substr($t, 0, 600) . '...';
    $cleanTexts[] = $t;
    if (count($cleanTexts) === 200) break;
}
if (count($cleanTexts) === 0) fail('no_texts', 'No usable text to score.');

// Build a numbered list for the model.
$lines = [];
foreach ($cleanTexts as $i => $t) {
    $lines[] = ($i + 1) . '. ' . $t;
}
$listBlock = implode("\n", $lines);
$total = count($cleanTexts);

$system = <<<SYS
You are a sentiment classifier. The user gives you a numbered list of short open-ended survey responses. Classify each one as one of:
- positive   - clearly favorable, happy, satisfied, complimentary, or expressing a wish that things stay the way they are.
- negative   - clearly unfavorable, frustrated, dissatisfied, complaining, or expressing a desire for change because something is wrong.
- mixed      - meaningful positive AND negative content in the same response.
- neutral    - descriptive, factual, neither positive nor negative, or unclear without more context.

For each response, also provide a "reason": a single short phrase under 60 characters that explains why you classified it that way. The reason should be specific (e.g., "praises the new manager", "complaint about workload", "factual description with no affect").

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "results": [
    { "sentiment": "positive" | "neutral" | "negative" | "mixed", "reason": "<short phrase>" },
    { "sentiment": "...", "reason": "..." }
  ]
}

Rules:
- The "results" array must have exactly the same number of entries as the input list, in the same order. If you cannot read a response, classify it neutral with reason "unclear".
- Be honest about ambiguity. Do not over-classify as positive or negative when the response is genuinely neutral.
- Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt  = "Total responses: {$total}\n\n";
$userPrompt .= "Responses:\n" . $listBlock . "\n\n";
$userPrompt .= "Classify all {$total} responses now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 4000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['results']) || !is_array($parsed['results'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$valid = ['positive', 'neutral', 'negative', 'mixed'];
$results = [];
foreach ($parsed['results'] as $r) {
    if (!is_array($r)) {
        $results[] = ['sentiment' => 'neutral', 'reason' => 'unclear'];
        continue;
    }
    $s = strtolower((string)($r['sentiment'] ?? 'neutral'));
    if (!in_array($s, $valid, true)) $s = 'neutral';
    $reason = clean_string((string)($r['reason'] ?? ''), 120);
    $results[] = ['sentiment' => $s, 'reason' => $reason];
    if (count($results) === $total) break;
}

// If the model returned fewer than expected, pad with neutrals so the indices
// stay aligned with the caller's input.
while (count($results) < $total) {
    $results[] = ['sentiment' => 'neutral', 'reason' => 'unclear'];
}

// Tally for convenience.
$tally = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
foreach ($results as $r) {
    $tally[$r['sentiment']]++;
}

json_out([
    'ok'      => true,
    'results' => $results,
    'tally'   => $tally,
    'total'   => $total,
    'model'   => ai_config()['model'],
]);
