<?php
// POST /api/ai/narrate-description.php
// Body: {
//   "snapshot": {
//     "totals": {
//       "total_responses":    <int>,
//       "complete_responses": <int>,
//       "likert_items":       <int>,
//       "other_items":        <int>
//     },
//     "likert_aggregate": {
//       "items_balanced": <int>, "items_ceiling": <int>, "items_floor": <int>,
//       "items_high_sd":  <int>, "items_low_sd":  <int>,
//       "mean_of_means":  <float>, "mean_of_sds": <float>
//     },
//     "likert_items": [
//       { "idx": <int>, "prompt": <string>, "n": <int>,
//         "mean": <float>, "sd": <float>, "skew": <float|null>, "points": <int> }
//     ]
//   }
// }
//
// Dashboard Narrator for the Description Analysis tab (Phase 54). One
// paragraph plus up to three highlights describing the response pattern.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_description:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$totals = $snap['totals'] ?? [];
$agg    = $snap['likert_aggregate'] ?? [];
$itemsIn = is_array($snap['likert_items'] ?? null) ? $snap['likert_items'] : [];

$totalResp    = max(0, (int)($totals['total_responses']    ?? 0));
$completeResp = max(0, (int)($totals['complete_responses'] ?? 0));
$likertCount  = max(0, (int)($totals['likert_items']       ?? 0));
$otherCount   = max(0, (int)($totals['other_items']        ?? 0));

if ($totalResp === 0) fail('no_responses', 'Collect at least one response before requesting a description narration.');
if ($likertCount === 0 && $otherCount === 0) fail('no_items', 'No analyzable items in this survey.');

$items = [];
foreach ($itemsIn as $it) {
    if (!is_array($it)) continue;
    $idx = (int)($it['idx'] ?? -1);
    if ($idx < 0) continue;
    $prompt = clean_string((string)($it['prompt'] ?? ''), 80);
    if ($prompt === '') $prompt = 'Item ' . ($idx + 1);
    $items[] = [
        'idx'    => $idx,
        'prompt' => $prompt,
        'n'      => max(0, (int)($it['n'] ?? 0)),
        'mean'   => is_numeric($it['mean'] ?? null) ? round((float)$it['mean'], 2) : null,
        'sd'     => is_numeric($it['sd']   ?? null) ? round((float)$it['sd'],   2) : null,
        'skew'   => is_numeric($it['skew'] ?? null) ? round((float)$it['skew'], 2) : null,
        'points' => max(0, (int)($it['points'] ?? 0)),
    ];
    if (count($items) >= 40) break;
}

$lines = [];
$lines[] = "Totals: " . $totalResp . " responses (" . $completeResp . " complete), " . $likertCount . " Likert items, " . $otherCount . " other items.";
$lines[] = "Aggregate Likert picture: " .
    (int)($agg['items_balanced'] ?? 0) . " balanced, " .
    (int)($agg['items_ceiling']  ?? 0) . " ceiling, " .
    (int)($agg['items_floor']    ?? 0) . " floor, " .
    (int)($agg['items_high_sd']  ?? 0) . " high SD, " .
    (int)($agg['items_low_sd']   ?? 0) . " low SD. " .
    "Mean-of-means " . (is_numeric($agg['mean_of_means'] ?? null) ? round((float)$agg['mean_of_means'], 2) : 'n/a') .
    "; mean-of-SDs " . (is_numeric($agg['mean_of_sds']   ?? null) ? round((float)$agg['mean_of_sds'],   2) : 'n/a') . ".";
$lines[] = "";
$lines[] = "Per-item (idx. \"prompt\", n / mean / sd / skew / points):";
foreach ($items as $it) {
    $lines[] = sprintf(
        '  %d. "%s", n=%d, mean=%s, sd=%s, skew=%s, %d-pt',
        $it['idx'] + 1,
        $it['prompt'],
        $it['n'],
        $it['mean'] === null ? 'n/a' : (string)$it['mean'],
        $it['sd']   === null ? 'n/a' : (string)$it['sd'],
        $it['skew'] === null ? 'n/a' : (string)$it['skew'],
        $it['points']
    );
}
$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card for the Description Analysis tab of a survey app. The user is not a statistician. You explain the response pattern across items in plain language.

Tone tiers for the visual pill (pick the one that best fits the picture):
  - "good" : Items are mostly balanced; means and SDs are reasonable; no major ceiling/floor concerns.
  - "ok"   : Mixed picture; a few items show ceiling/floor or unusual SD; overall usable.
  - "warn" : Multiple items show ceiling/floor or low/high SD; reporting needs care.
  - "bad"  : Most items are skewed or compressed; reporting could be misleading.

Voice:
  - Lead with the overall response pattern (balanced? positively skewed? ceiling-heavy?).
  - Mention the strongest signal: the most extreme item (highest mean / lowest mean / highest SD) by quoting its prompt.
  - Avoid statistical jargon like "skewness" or "kurtosis"; use phrases like "leans toward agreement", "responses are tightly clustered", "wide disagreement".
  - 2-4 sentences. Plain prose, no bullets inside the paragraph.

Highlights (0-3): short items that surface specific patterns the user should know.
  - Each has: label (3-6 words, e.g. "Highest agreement", "Largest disagreement", "Compressed responses"), detail (one short sentence with the actual numbers).

Headline:
  - One sentence that summarizes the picture in plain language.

Affected items (Phase 104):
  - When the paragraph or any highlight names specific items by prompt text or item number, also list them in an affected_items array.
  - Each entry has shape { "type": "item", "id": "<0-based index as string>" }.
  - The id must match the idx field in the per-item snapshot block (so "Item 4" in the snapshot becomes id "3").
  - Empty array is fine when the narration does not call out specific items.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Balanced picture'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": [
    { "type": "item", "id": "<0-based index of an item called out above>" }
  ]
}
SYS;

$userPrompt = "Description snapshot:\n\n" . $snapshotBlock . "\n\nProduce the description narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = ['good' => 'Healthy picture', 'ok' => 'Mixed picture', 'warn' => 'Read with care', 'bad' => 'Reporting risk'];
$toneLabel = clean_string((string)($parsed['tone_label'] ?? $defaultLabels[$tone]), 48);
if ($toneLabel === '') $toneLabel = $defaultLabels[$tone];

$headline  = clean_string((string)($parsed['headline']  ?? ''), 220);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 800);

$highlights = [];
if (is_array($parsed['highlights'] ?? null)) {
    foreach ($parsed['highlights'] as $h) {
        if (!is_array($h)) continue;
        $label  = clean_string((string)($h['label']  ?? ''), 60);
        $detail = clean_string((string)($h['detail'] ?? ''), 240);
        if ($label === '' || $detail === '') continue;
        $highlights[] = ['label' => $label, 'detail' => $detail];
        if (count($highlights) >= 3) break;
    }
}

// Phase 104: normalize affected_items. Whitelist type='item' and validate the
// id maps to a known item idx in this snapshot. Drop anything else so the
// client never receives untrusted target selectors.
$affectedItems = [];
$validIds = [];
foreach ($items as $it) { $validIds[(string)$it['idx']] = true; }
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'item') continue;
        if (!isset($validIds[$id])) continue;
        $affectedItems[] = ['type' => $type, 'id' => $id];
        if (count($affectedItems) >= 12) break;
    }
}

json_out([
    'ok'             => true,
    'tone'           => $tone,
    'tone_label'     => $toneLabel,
    'headline'       => $headline,
    'paragraph'      => $paragraph,
    'highlights'     => $highlights,
    'affected_items' => $affectedItems,
    'model'          => ai_config()['model'],
]);
