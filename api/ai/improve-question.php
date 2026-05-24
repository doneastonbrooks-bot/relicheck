<?php
// POST /api/ai/improve-question.php
// Body: {
//   "prompt": "the current question text",
//   "type":   "likert" | "single" | "multi" | "open",
//   "context": "optional 1-2 sentence context, e.g. survey title or topic"
// }
// Returns three improved rewrites, each with a one-line rationale, so the
// user can pick the version they like best.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 30 rewrites per user per hour. Cheaper call than full survey generation.
check_rate_limit('ai_improve:user:' . (int)$user['id'], 30, 3600);

$body    = read_json_body();
$prompt  = clean_string($body['prompt']  ?? '', 4000);
$type    = (string)($body['type']        ?? 'likert');
$context = clean_string($body['context'] ?? '', 600);

if ($prompt === '') fail('bad_prompt', 'Send the current question text.');
if (!in_array($type, ['likert','single','multi','open'], true)) {
    fail('bad_type', 'type must be one of likert, single, multi, open.');
}

$typeGuidance = [
    'likert' => 'a single-stem statement that respondents can agree or disagree with on a Likert scale. It should not be a question, it should be a statement.',
    'single' => 'a question that asks the respondent to pick exactly one option from a list.',
    'multi'  => 'a question that asks the respondent to pick one or more options from a list.',
    'open'   => 'an open-ended question that invites a short written answer.',
][$type];

$system = <<<SYS
You are a survey methodologist. The user gives you one survey item and you produce three improved rewrites of it.

Quality bar:
- Single-barreled (one concept per item).
- Neutral wording, no leading or loaded language.
- No double negatives.
- Plain, accessible language at roughly an 8th-grade reading level.
- Under 25 words per item.
- Stay close to the original meaning. Do not change the underlying construct.
- The item type is fixed: produce {$type} items only, where the format is {$typeGuidance}

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "suggestions": [
    { "prompt": "<rewritten item>", "why": "<one short sentence on what changed and why>" },
    { "prompt": "<rewritten item>", "why": "<one short sentence on what changed and why>" },
    { "prompt": "<rewritten item>", "why": "<one short sentence on what changed and why>" }
  ]
}

Rules:
- Always return exactly three suggestions.
- Each rewrite must be meaningfully different from the others (different angle, different phrasing, different emphasis).
- Do not number the items in the prompt text.
- Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt = "Original item: \"" . $prompt . "\"\n";
if ($context !== '') $userPrompt .= "Survey context: " . $context . "\n";
$userPrompt .= "Item type: " . $type . "\n";
$userPrompt .= "Generate three rewrites now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 1200);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$suggestions = [];
foreach ($parsed['suggestions'] as $s) {
    if (!is_array($s)) continue;
    $sp = clean_string((string)($s['prompt'] ?? ''), 4000);
    $sw = clean_string((string)($s['why']    ?? ''), 600);
    if ($sp === '') continue;
    $suggestions[] = ['prompt' => $sp, 'why' => $sw];
    if (count($suggestions) === 3) break;
}

if (count($suggestions) === 0) {
    fail('ai_empty_result', 'AI did not return any usable suggestions. Try again.', 502);
}

json_out([
    'ok'          => true,
    'original'    => $prompt,
    'type'        => $type,
    'suggestions' => $suggestions,
    'model'       => ai_config()['model'],
]);
