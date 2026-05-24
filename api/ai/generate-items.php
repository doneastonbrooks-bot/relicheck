<?php
// POST /api/ai/generate-items.php
// Body: { "topic": "<text>", "count": 5|10|15, "type": "likert" }
//
// Phase 79. Takes a topic / purpose / standard and returns an array of
// suggested Likert items. Each item includes the prompt, a recommended
// reverse flag, an optional construct tag, and a short rationale. The
// builder shows them in a preview modal with checkboxes; the user
// chooses which to add.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_generate_items:user:' . (int)$user['id'], 30, 3600);

$body  = read_json_body();
$topic = clean_string((string)($body['topic'] ?? ''), 1000);
$count = (int)($body['count'] ?? 10);
if ($count < 3) $count = 3;
if ($count > 20) $count = 20;
$type  = (string)($body['type'] ?? 'likert');
if ($type !== 'likert') $type = 'likert'; // Phase 79 ships likert only.

if ($topic === '') fail('bad_input', 'Tell us what you want to measure.');

$system = <<<SYS
You are a survey methodology assistant. Generate Likert items for a survey based on the user's topic. Items should be:
  - Statements (not questions). Likert items are agree/disagree statements.
  - One idea per item (no double-barreled wording).
  - Neutral in tone (no leading words or charged language).
  - At a 9th-10th grade reading level. Plain English.
  - Mostly positively worded, with one or two reverse-scored items mixed in for response-set protection on longer scales.
  - Anchored to the same target (the respondent's own experience, behavior, attitude, etc.).

Output exactly the requested number of items. Each item has:
  - prompt: the statement text, 6-20 words.
  - reverse: true if the statement is worded so a low Likert score = high construct level (e.g., "I do not feel..."). Otherwise false.
  - construct: a short construct label (1-3 words) suggesting what the item measures. Use the same label across items meant to load on the same factor.
  - rationale: one short sentence on why the item is included.

Output format: a single JSON object with one field, "items", an array. No prose, no markdown.

Example for topic "team psychological safety":
{
  "items": [
    { "prompt": "I can speak up about problems on my team.", "reverse": false, "construct": "Voice", "rationale": "Direct indicator of voice behavior." },
    { "prompt": "It feels risky to disagree with my manager.", "reverse": true, "construct": "Voice", "rationale": "Reverse-worded to catch response-set bias." }
  ]
}
SYS;

$userPrompt = "Topic: " . $topic . "\n\nGenerate exactly " . $count . " Likert items. Return JSON only.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1800);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !is_array($parsed['items'] ?? null)) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try a more specific topic.', 502);
}

$items = [];
foreach ($parsed['items'] as $it) {
    if (!is_array($it)) continue;
    $prompt = clean_string((string)($it['prompt'] ?? ''), 500);
    if ($prompt === '') continue;
    $reverse = !empty($it['reverse']);
    $construct = clean_string((string)($it['construct'] ?? ''), 60);
    $rationale = clean_string((string)($it['rationale'] ?? ''), 200);
    $items[] = [
        'prompt'    => $prompt,
        'reverse'   => $reverse,
        'construct' => $construct,
        'rationale' => $rationale,
    ];
    if (count($items) >= 20) break;
}

if (!$items) fail('ai_empty', 'No items came back. Try a more specific topic.', 502);

json_out([
    'ok'    => true,
    'topic' => $topic,
    'items' => $items,
    'model' => ai_config()['model'],
]);
