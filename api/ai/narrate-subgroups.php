<?php
// POST /api/ai/narrate-subgroups.php
// Body: {
//   "snapshot": {
//     "group_label":   <string>,
//     "outcome_label": <string>,
//     "overall_n":     <int>,
//     "overall_mean":  <float|null>,
//     "visible_count": <int>,
//     "hidden_count":  <int>,
//     "k_threshold":   <int>,
//     "rows": [
//       { "label": <string>, "n": <int>, "mean": <float>, "sd": <float>,
//         "d": <float|null>, "d_ci_low": <float|null>, "d_ci_high": <float|null> }
//     ]
//   }
// }
//
// Phase 65. AI narrator for the Subgroup Breakdown tab. Reads the per
// subgroup outcomes plus Cohen's d versus everyone else and writes a
// plain-language summary an equity / HR lead can act on.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_subgroups:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$groupLabel    = clean_string((string)($snap['group_label']   ?? ''), 120);
$outcomeLabel  = clean_string((string)($snap['outcome_label'] ?? ''), 120);
$overallN      = max(0, (int)($snap['overall_n'] ?? 0));
$overallMean   = is_numeric($snap['overall_mean'] ?? null) ? round((float)$snap['overall_mean'], 3) : null;
$visibleCount  = max(0, (int)($snap['visible_count'] ?? 0));
$hiddenCount   = max(0, (int)($snap['hidden_count']  ?? 0));
$kThreshold    = max(0, (int)($snap['k_threshold']   ?? 0));

if ($visibleCount < 1) {
    fail('insufficient_data', 'Need at least one visible subgroup to narrate.');
}

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$rowsIn = is_array($snap['rows'] ?? null) ? $snap['rows'] : [];
$rows = [];
foreach ($rowsIn as $r) {
    if (!is_array($r)) continue;
    $rows[] = [
        'label'     => clean_string((string)($r['label'] ?? ''), 80),
        'n'         => max(0, (int)($r['n'] ?? 0)),
        'mean'      => $normFloat($r['mean']      ?? null, -1000, 1000),
        'sd'        => $normFloat($r['sd']        ?? null, 0,     1000),
        'd'         => $normFloat($r['d']         ?? null, -10,   10),
        'd_ci_low'  => $normFloat($r['d_ci_low']  ?? null, -10,   10),
        'd_ci_high' => $normFloat($r['d_ci_high'] ?? null, -10,   10),
    ];
    if (count($rows) >= 12) break;
}

$rowLines = [];
foreach ($rows as $r) {
    $line = sprintf(
        '  %s: n=%d, mean=%s, SD=%s, Cohen d=%s',
        $r['label'],
        $r['n'],
        $r['mean'] === null ? 'NA' : (string)$r['mean'],
        $r['sd']   === null ? 'NA' : (string)$r['sd'],
        $r['d']    === null ? 'NA' : (string)$r['d']
    );
    if ($r['d_ci_low'] !== null && $r['d_ci_high'] !== null) {
        $line .= sprintf(' (95%% CI [%s, %s])', (string)$r['d_ci_low'], (string)$r['d_ci_high']);
    }
    $rowLines[] = $line;
}
$rowBlock = count($rowLines) ? implode("\n", $rowLines) : '  (no visible subgroups)';

$snapshotBlock  = 'Group axis: ' . ($groupLabel === '' ? 'group' : $groupLabel) . "\n";
$snapshotBlock .= 'Outcome: ' . ($outcomeLabel === '' ? 'composite Likert mean' : $outcomeLabel) . "\n";
$snapshotBlock .= 'Overall n = ' . $overallN . ', overall mean = ' . ($overallMean === null ? 'NA' : (string)$overallMean) . "\n";
$snapshotBlock .= 'Visible subgroups: ' . $visibleCount . "\n";
if ($hiddenCount > 0) {
    $snapshotBlock .= 'Hidden for privacy (n < ' . $kThreshold . "): " . $hiddenCount . "\n";
}
$snapshotBlock .= "\nPer-subgroup (mean, SD, Cohen's d vs everyone else):\n" . $rowBlock;

$system = <<<SYS
You are a measurement researcher narrating the Subgroup Breakdown tab of a survey app. The user is an equity, HR, or program lead looking at per-subgroup means with effect sizes against everyone else. Privacy is taken seriously: small cells were hidden before they reached you, and you should never speculate about hidden groups.

Tone tiers for the visual pill:
  - "good" : all Cohen d magnitudes below 0.20 (no meaningful subgroup gaps), or pattern is consistent and benign.
  - "ok"   : one subgroup with a small effect (0.20 to 0.50 in absolute value).
  - "warn" : at least one subgroup with a medium effect (0.50 to 0.80 in absolute value).
  - "bad"  : at least one subgroup with a large effect (0.80 or above in absolute value).

Voice:
  - Open with the bottom line. Are subgroup outcomes roughly equal, or is there a clear gap?
  - Name the standout subgroups by label and direction (higher or lower than everyone else). Use plain language: "Department X reports about half a standard deviation lower than the rest of the org."
  - Mention the 95% CI on Cohen's d only when it is wide enough to cross zero. If it does cross zero, say so plainly ("this gap could be noise").
  - If privacy hid subgroups, mention that briefly so the reader knows the picture is incomplete.
  - Avoid jargon (the word "effect size" is fine; "d-statistic", "pooled variance" are not). Two to four sentences. No bullet lists in the paragraph.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Largest gap: X versus rest", "Effect could be noise (CI crosses 0)", "N subgroups hidden for privacy", "Outcomes effectively equal across groups."

Headline:
  - One sentence verdict in plain language.

Affected items (Phase 105):
  - When the paragraph or any highlight names specific subgroups, also list them in an affected_items array.
  - Each entry has shape { "type": "subgroup", "id": "<exact subgroup label as shown in the snapshot>" }.
  - The id must match a label that appears in the per-subgroup rows block.
  - Empty array is fine when the narration only talks about the overall picture.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Outcomes broadly equal' or 'Clear gap detected'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "subgroup", "id": "<subgroup label called out above>" }
  ]
}
SYS;

$userPrompt = "Subgroup snapshot:\n\n" . $snapshotBlock . "\n\nProduce the subgroup narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Outcomes broadly equal',
    'ok'   => 'Small subgroup differences',
    'warn' => 'Medium-sized gap detected',
    'bad'  => 'Large gap detected',
];
$toneLabel = clean_string((string)($parsed['tone_label'] ?? $defaultLabels[$tone]), 48);
if ($toneLabel === '') $toneLabel = $defaultLabels[$tone];

$headline  = clean_string((string)($parsed['headline']  ?? ''), 220);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 900);

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

// Phase 105: normalize affected_items. Whitelist type='subgroup' and validate
// the id is one of the visible subgroup labels.
$affectedItems = [];
$validLabels = [];
foreach ($rows as $r) { if (!empty($r['label'])) $validLabels[$r['label']] = true; }
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 80);
        if ($type !== 'subgroup') continue;
        if (!isset($validLabels[$id])) continue;
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
