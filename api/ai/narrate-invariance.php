<?php
// POST /api/ai/narrate-invariance.php
// Body: {
//   "snapshot": {
//     "group_label":   <string>,
//     "group_count":   <int>,
//     "group_sizes":   [<int>, ...],
//     "group_labels":  [<string>, ...],
//     "k":             <int>,
//     "m":             <int>,
//     "achieved_level": "scalar" | "metric" | "configural" | "none",
//     "config_fit_ok": <bool>,
//     "models": {
//       "configural": { "chi2": <float>, "df": <int>, "cfi": <float>, "rmsea": <float>, "srmr": <float> },
//       "metric":     { ..., "d_cfi": <float>, "d_rmsea": <float>, "d_srmr": <float>, "verdict": <string> },
//       "scalar":     { ..., "d_cfi": <float>, "d_rmsea": <float>, "d_srmr": <float>, "verdict": <string> }
//     }
//   }
// }
//
// Phase 66. AI narrator for the measurement-invariance card on the
// Validity tab. Reads the three-level sequence (configural, metric,
// scalar) and explains in plain language whether the survey measures the
// same thing the same way across the chosen group axis, and what kinds
// of comparisons are licensed by the level achieved.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_invariance:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$groupLabel    = clean_string((string)($snap['group_label'] ?? ''), 120);
$groupCount    = max(0, (int)($snap['group_count'] ?? 0));
$k             = max(0, (int)($snap['k'] ?? 0));
$mFactors      = max(0, (int)($snap['m'] ?? 0));
$achievedLevel = (string)($snap['achieved_level'] ?? 'none');
$configFitOk   = !empty($snap['config_fit_ok']);

if ($groupCount < 2 || $k < 3) {
    fail('insufficient_data', 'Invariance narration needs at least 2 groups and 3 items.');
}

$validAchieved = ['scalar', 'metric', 'configural', 'none'];
if (!in_array($achievedLevel, $validAchieved, true)) $achievedLevel = 'none';

$groupSizes  = [];
if (is_array($snap['group_sizes'] ?? null)) {
    foreach ($snap['group_sizes'] as $n) $groupSizes[] = max(0, (int)$n);
}
$groupLabels = [];
if (is_array($snap['group_labels'] ?? null)) {
    foreach ($snap['group_labels'] as $lab) $groupLabels[] = clean_string((string)$lab, 80);
}

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$readModel = function (array $m, bool $hasDeltas) use ($normFloat) {
    $out = [
        'chi2'  => $normFloat($m['chi2']  ?? null, 0, 1.0e9, 2),
        'df'    => (int)($m['df'] ?? 0),
        'cfi'   => $normFloat($m['cfi']   ?? null, -1, 2),
        'rmsea' => $normFloat($m['rmsea'] ?? null, 0, 1),
        'srmr'  => $normFloat($m['srmr']  ?? null, 0, 1),
    ];
    if ($hasDeltas) {
        $out['d_cfi']   = $normFloat($m['d_cfi']   ?? null, -2, 2);
        $out['d_rmsea'] = $normFloat($m['d_rmsea'] ?? null, -1, 1);
        $out['d_srmr']  = $normFloat($m['d_srmr']  ?? null, -1, 1);
        $out['verdict'] = clean_string((string)($m['verdict'] ?? ''), 24);
    }
    return $out;
};

$modelsIn = is_array($snap['models'] ?? null) ? $snap['models'] : [];
$mConfig  = is_array($modelsIn['configural'] ?? null) ? $readModel($modelsIn['configural'], false) : null;
$mMetric  = is_array($modelsIn['metric']     ?? null) ? $readModel($modelsIn['metric'],     true)  : null;
$mScalar  = is_array($modelsIn['scalar']     ?? null) ? $readModel($modelsIn['scalar'],     true)  : null;
if (!$mConfig || !$mMetric || !$mScalar) fail('bad_input', 'Missing model fits.');

$fmt = function ($v) { return $v === null ? 'NA' : (string)$v; };

$groupBlock = '';
for ($i = 0; $i < count($groupSizes); $i++) {
    $lab = $groupLabels[$i] ?? ('Group ' . ($i + 1));
    $groupBlock .= '  - ' . $lab . ': n = ' . $groupSizes[$i] . "\n";
}

$snapshotBlock  = 'Group axis: ' . ($groupLabel === '' ? 'group' : $groupLabel) . "\n";
$snapshotBlock .= 'Groups (' . $groupCount . '):' . "\n" . $groupBlock;
$snapshotBlock .= 'Items: ' . $k . ', Factors: ' . $mFactors . "\n";
$snapshotBlock .= 'Configural fit acceptable: ' . ($configFitOk ? 'yes' : 'no') . "\n";
$snapshotBlock .= 'Achieved invariance level: ' . $achievedLevel . "\n\n";

$snapshotBlock .= 'Configural model:' . "\n";
$snapshotBlock .= '  chi2 = ' . $fmt($mConfig['chi2']) . ', df = ' . $mConfig['df']
              . ', CFI = ' . $fmt($mConfig['cfi'])
              . ', RMSEA = ' . $fmt($mConfig['rmsea'])
              . ', SRMR = ' . $fmt($mConfig['srmr']) . "\n";

$snapshotBlock .= 'Metric model (loadings constrained):' . "\n";
$snapshotBlock .= '  chi2 = ' . $fmt($mMetric['chi2']) . ', df = ' . $mMetric['df']
              . ', CFI = ' . $fmt($mMetric['cfi'])
              . ', RMSEA = ' . $fmt($mMetric['rmsea'])
              . ', SRMR = ' . $fmt($mMetric['srmr'])
              . ', delta CFI = ' . $fmt($mMetric['d_cfi'])
              . ', delta RMSEA = ' . $fmt($mMetric['d_rmsea'])
              . ', delta SRMR = ' . $fmt($mMetric['d_srmr'])
              . ', verdict = ' . ($mMetric['verdict'] ?: 'NA') . "\n";

$snapshotBlock .= 'Scalar model (loadings + intercepts constrained):' . "\n";
$snapshotBlock .= '  chi2 = ' . $fmt($mScalar['chi2']) . ', df = ' . $mScalar['df']
              . ', CFI = ' . $fmt($mScalar['cfi'])
              . ', RMSEA = ' . $fmt($mScalar['rmsea'])
              . ', SRMR = ' . $fmt($mScalar['srmr'])
              . ', delta CFI = ' . $fmt($mScalar['d_cfi'])
              . ', delta RMSEA = ' . $fmt($mScalar['d_rmsea'])
              . ', delta SRMR = ' . $fmt($mScalar['d_srmr'])
              . ', verdict = ' . ($mScalar['verdict'] ?: 'NA') . "\n";

$system = <<<SYS
You are a measurement researcher narrating the Measurement Invariance card of a survey app. The user is an equity, HR, evaluation, or academic lead trying to figure out whether their survey measures the same thing the same way across the group axis they picked. The user is not a statistician.

Tone tiers for the visual pill:
  - "good" : achieved level is "scalar". The survey is fully invariant for the selected groups; latent mean comparisons are licensed.
  - "ok"   : achieved level is "metric". The items relate to the construct the same way, but intercepts shifted. Group means are not directly comparable in raw form.
  - "warn" : achieved level is "configural". Same factor structure but different relationships; group comparisons risky.
  - "bad"  : achieved level is "none". The configural model itself does not fit; revisit the construct mapping and CFA before claiming invariance.

Voice:
  - Open with the bottom line in plain language. State the achieved level and what it licenses.
  - Translate the levels into something a non-statistician can act on:
    - scalar: "you can compare scores across groups directly"
    - metric: "the items work the same way but baseline scores shift; comparing raw means is risky"
    - configural: "the same factor structure shows up but items behave differently across groups; comparing scores is not advisable"
    - none: "the model does not fit; revisit construct mapping before pursuing invariance"
  - If metric or scalar invariance failed, point at which items most plausibly drove it (rephrasing or cultural interpretation differences are common reasons).
  - Two to four sentences. Plain prose. No bullet lists in the paragraph.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Scalar invariance holds", "Metric step failed", "Configural fit weak", "Latent mean comparison licensed", "Item intercepts differ between groups".

Headline:
  - One sentence verdict.

Affected items (Phase 105):
  - When the paragraph or any highlight calls out a specific invariance level, also list it in an affected_items array.
  - Each entry has shape { "type": "level", "id": "configural" | "metric" | "scalar" }.
  - The id must be one of the three sequential models. Pick the level the narrator is critiquing or praising.
  - Empty array is fine when the narration speaks only about the overall conclusion.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Scalar invariance achieved'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "level", "id": "configural" | "metric" | "scalar" }
  ]
}
SYS;

$userPrompt = "Invariance snapshot:\n\n" . $snapshotBlock . "\n\nProduce the invariance narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Scalar invariance achieved',
    'ok'   => 'Metric invariance achieved',
    'warn' => 'Configural only',
    'bad'  => 'Model does not fit',
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

// Phase 105: normalize affected_items. Whitelist type='level' and validate
// id is one of the three fixed level keys.
$affectedItems = [];
$validLevelIds = ['configural' => true, 'metric' => true, 'scalar' => true];
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'level') continue;
        if (!isset($validLevelIds[$id])) continue;
        $affectedItems[] = ['type' => $type, 'id' => $id];
        if (count($affectedItems) >= 3) break;
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
