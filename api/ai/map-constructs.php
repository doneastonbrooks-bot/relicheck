<?php
// POST /api/ai/map-constructs.php
// Body: {
//   "items_to_map": [
//     { "id": <string>, "prompt": <string>, "type": <string> },
//     ...
//   ],
//   "existing_constructs": [        // optional context: constructs the user
//     { "name": <string> },         // already assigned to OTHER items
//     ...
//   ]
// }
//
// AI Construct Mapper (Phase 56). The client sends only the items that
// need a construct assignment (typically the ones whose `construct`
// field is blank) plus the names of any constructs already in use on
// other items, so the model can reuse them where appropriate.
//
// Output: {
//   ok,
//   constructs: [
//     { "name": <string>, "item_ids": [<id>, ...], "rationale": <string> }
//   ],
//   model
// }
//
// Guarantee: every id from items_to_map appears in exactly one construct's
// item_ids list (server validates).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_map_constructs:user:' . (int)$user['id'], 15, 3600);

$body = read_json_body();
$itemsIn      = $body['items_to_map']       ?? null;
$existingIn   = $body['existing_constructs'] ?? [];

if (!is_array($itemsIn) || count($itemsIn) === 0) {
    fail('bad_input', 'Missing items_to_map.');
}

// ---- Normalize items.
$items = [];
$seenIds = [];
foreach ($itemsIn as $row) {
    if (!is_array($row)) continue;
    $id = clean_string((string)($row['id'] ?? ''), 80);
    if ($id === '' || isset($seenIds[$id])) continue;
    $prompt = clean_string((string)($row['prompt'] ?? ''), 240);
    if ($prompt === '') continue;
    $type = clean_string((string)($row['type'] ?? 'likert'), 16);
    $items[] = ['id' => $id, 'prompt' => $prompt, 'type' => $type];
    $seenIds[$id] = true;
    if (count($items) >= 60) break;
}

if (count($items) === 0) {
    fail('insufficient_data', 'No usable items in the payload.');
}
if (count($items) < 2) {
    fail('insufficient_data', 'Construct mapping needs at least 2 items.');
}

// ---- Normalize existing constructs.
$existing = [];
if (is_array($existingIn)) {
    foreach ($existingIn as $c) {
        $name = is_array($c) ? clean_string((string)($c['name'] ?? ''), 60)
                             : clean_string((string)$c, 60);
        if ($name === '') continue;
        $existing[$name] = true;
        if (count($existing) >= 20) break;
    }
}
$existingList = array_keys($existing);

// ---- Build the user prompt.
$itemLines = [];
foreach ($items as $i => $it) {
    $itemLines[] = sprintf('  [%s] (%s) %s', $it['id'], $it['type'], $it['prompt']);
}
$itemsBlock = implode("\n", $itemLines);

$existingBlock = count($existingList)
    ? ('Constructs already in use on other items in this survey (reuse where the new item fits):' . "\n  - " . implode("\n  - ", $existingList))
    : 'No constructs are currently assigned on other items; propose fresh construct names.';

$system = <<<SYS
You are a measurement researcher helping a survey owner organize items into the constructs they measure. Given a list of survey items, you propose 2-6 construct groupings so each item is assigned to exactly one construct.

Constraints:
  - Every item id provided MUST appear in exactly one construct's item_ids list. Do not omit items. Do not duplicate items across constructs.
  - Aim for 2-6 constructs total. When the survey is small (< 8 items), 2-3 constructs is usually right. When larger (12+ items), 3-5 is typical. Never produce more constructs than items.
  - A construct should have at least 2 items when the survey has enough items to support it. Single-item constructs are acceptable only when the survey clearly has a lone item that fits no other group.
  - Construct names are 1-3 word noun phrases in Title Case (e.g. "Trust", "Communication", "Workload", "Psychological Safety", "Belonging", "Academic Self-Efficacy"). Avoid generic words like "Group A", "Cluster 1", "Other", "Miscellaneous".
  - Match the survey's domain language. If items use workplace vocabulary, propose workplace constructs. If items use education vocabulary, propose education constructs.

Reuse:
  - If the user has constructs already assigned on other items, REUSE those names when a new item plausibly fits. Only propose new names for items that don't match any existing construct.
  - Do NOT rename existing constructs.

Each construct entry returns:
  - name: the construct label
  - item_ids: array of item ids assigned to this construct (in the same order the items were provided where possible)
  - rationale: ONE short sentence (max ~18 words) explaining what these items have in common. No jargon. Do not repeat the construct name.

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "constructs": [
    {
      "name": "<construct name>",
      "item_ids": ["<id>", "<id>", ...],
      "rationale": "<one short sentence>"
    }
  ]
}
SYS;

$userPrompt  = "Items to map:\n" . $itemsBlock . "\n\n" . $existingBlock . "\n\n";
$userPrompt .= "Produce the constructs JSON now. Every item id above MUST appear in exactly one construct.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1400);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['constructs']) || !is_array($parsed['constructs'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

// ---- Validate and clean the model output.
$validIds = array_column($items, 'id');
$validIdSet = array_flip($validIds);
$assigned = [];
$constructs = [];
foreach ($parsed['constructs'] as $c) {
    if (!is_array($c)) continue;
    $name = clean_string((string)($c['name'] ?? ''), 60);
    $name = rtrim($name, ". \t\n\r");
    if ($name === '') continue;
    $rationale = clean_string((string)($c['rationale'] ?? ''), 200);

    $ids = [];
    if (is_array($c['item_ids'] ?? null)) {
        foreach ($c['item_ids'] as $id) {
            if (!is_string($id)) continue;
            $clean = clean_string($id, 80);
            if (!isset($validIdSet[$clean])) continue;
            if (isset($assigned[$clean])) continue;       // already placed elsewhere
            $assigned[$clean] = true;
            $ids[] = $clean;
        }
    }
    if (count($ids) === 0) continue;

    $constructs[] = [
        'name'      => $name,
        'item_ids'  => $ids,
        'rationale' => $rationale,
    ];
    if (count($constructs) >= 8) break;
}

// ---- Fill any unassigned ids into a fallback construct so the contract holds.
$unassigned = [];
foreach ($validIds as $id) {
    if (!isset($assigned[$id])) $unassigned[] = $id;
}
if (count($unassigned) > 0) {
    $constructs[] = [
        'name'      => 'Unassigned',
        'item_ids'  => $unassigned,
        'rationale' => 'AI could not confidently group these items; review manually.',
    ];
}

if (count($constructs) === 0) {
    fail('ai_parse_failed', 'AI returned no usable groupings. Try again.', 502);
}

json_out([
    'ok'         => true,
    'constructs' => $constructs,
    'model'      => ai_config()['model'],
]);
