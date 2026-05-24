<?php
// POST /api/ai/narrate-key-drivers.php
// Body: {
//   "snapshot": {
//     "is_logistic":   <bool>,
//     "outcome_label": <string>,
//     "n":             <int>,
//     "model_r2":      <float|null>,
//     "drivers_count": <int>,
//     "rw_available":  <bool>,
//     "rw_r2_total":   <float|null>,
//     "top_drivers": [
//       { "rank": <int>, "label": <string>,
//         "r": <float|null>, "r_p": <float|null>,
//         "beta_std": <float|null>, "beta_p": <float|null>,
//         "ci_low": <float|null>, "ci_high": <float|null>,
//         "rw_pct": <float|null> }
//     ]
//   }
// }
//
// Phase 86. AI narrator for the Key Driver Analysis card. Reads the
// ranked drivers and writes a plain-language summary explaining which
// survey factors matter most for the chosen outcome.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_keydrivers:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$n            = max(0, (int)($snap['n'] ?? 0));
$driversCount = max(0, (int)($snap['drivers_count'] ?? 0));
$isLogistic   = !empty($snap['is_logistic']);
$outcomeLabel = clean_string((string)($snap['outcome_label'] ?? ''), 200);
$rwAvailable  = !empty($snap['rw_available']);
if ($n < 15 || $driversCount < 1) fail('insufficient_data', 'Key driver narration needs at least 15 respondents and 1 driver.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$modelR2   = $normFloat($snap['model_r2'] ?? null, -1, 2);
$rwR2Total = $normFloat($snap['rw_r2_total'] ?? null, -1, 2);

$drivers = [];
if (is_array($snap['top_drivers'] ?? null)) {
    foreach ($snap['top_drivers'] as $d) {
        if (!is_array($d)) continue;
        $drivers[] = [
            'rank'     => max(0, (int)($d['rank'] ?? 0)),
            'label'    => clean_string((string)($d['label'] ?? ''), 160),
            'r'        => $normFloat($d['r']        ?? null, -1, 1),
            'r_p'      => $normFloat($d['r_p']      ?? null, 0, 1, 4),
            'beta_std' => $normFloat($d['beta_std'] ?? null, -10, 10),
            'beta_p'   => $normFloat($d['beta_p']   ?? null, 0, 1, 4),
            'ci_low'   => $normFloat($d['ci_low']   ?? null, -10, 10),
            'ci_high'  => $normFloat($d['ci_high']  ?? null, -10, 10),
            'rw_pct'   => $normFloat($d['rw_pct']   ?? null, 0, 1, 4),
        ];
        if (count($drivers) >= 8) break;
    }
}

$fmt  = function ($v) { return $v === null ? 'NA' : (string)$v; };
$fmtP = function ($v) {
    if ($v === null) return 'NA';
    if ($v < 0.001) return 'p < .001';
    return 'p = ' . number_format($v, 3);
};

$modelLabel = $isLogistic ? 'Logistic regression (binary outcome)' : 'Multiple regression with Johnson Relative Weights (continuous outcome)';

$snapshotBlock  = $modelLabel . ".\n";
$snapshotBlock .= 'Outcome: ' . ($outcomeLabel === '' ? '(unnamed)' : $outcomeLabel) . "\n";
$snapshotBlock .= 'n = ' . $n . ', drivers tested = ' . $driversCount . "\n";
$snapshotBlock .= ($isLogistic ? 'McFadden R^2 = ' : 'R^2 = ') . $fmt($modelR2) . "\n";
if (!$isLogistic && $rwAvailable && $rwR2Total !== null) {
    $snapshotBlock .= 'Relative-Weights R^2 total (sum of RW): ' . $fmt($rwR2Total) . "\n";
}
$snapshotBlock .= "\nTop drivers (ranked by " . ($isLogistic ? 'absolute standardized log-odds coefficient' : "Johnson's Relative Weight") . "):\n";
foreach ($drivers as $d) {
    $rwTxt = '';
    if (!$isLogistic && $d['rw_pct'] !== null) {
        $rwTxt = sprintf(', RW = %.1f%% of R^2', $d['rw_pct'] * 100);
    }
    $snapshotBlock .= sprintf(
        "  %d. %s  (Pearson r = %s, %s; std beta = %s, %s, 95%% CI [%s, %s]%s)\n",
        $d['rank'],
        $d['label'],
        $fmt($d['r']),
        $fmtP($d['r_p']),
        $fmt($d['beta_std']),
        $fmtP($d['beta_p']),
        $fmt($d['ci_low']),
        $fmt($d['ci_high']),
        $rwTxt
    );
}

$system = <<<SYS
You are a measurement researcher narrating the Key Driver Analysis card of a survey app. The audience is an HR / evaluation / education lead who wants to know which survey factors most strongly predict an outcome (satisfaction, belonging, engagement, retention intention, program effectiveness). Most readers are not statisticians.

The model is one of:
  - Multiple regression on standardized predictors with Johnson Relative Weights apportioning R-squared across correlated predictors. Use this for continuous outcomes. The headline ranking is by Relative Weight share.
  - Logistic regression on standardized predictors. Use this for binary outcomes. The headline ranking is by absolute standardized log-odds coefficient.

Tone tiers for the visual pill:
  - "good" : R-squared (or McFadden pseudo R-squared) at or above 0.30 AND at least one driver is significant AND the top driver's RW share is at or above 15%. The model has clear levers to pull.
  - "ok"   : R-squared between 0.10 and 0.30, or significant drivers exist but importance shares are spread thinly. There are levers but no dominant one.
  - "warn" : R-squared between 0.05 and 0.10, or no driver is statistically significant despite a reasonable sample. The chosen factors do not move the outcome much.
  - "bad"  : R-squared below 0.05 (or pseudo R-squared below 0.05 for logistic). The model finds essentially nothing.

Voice:
  - Open with the bottom line in plain language: are there clear key drivers, and which one matters most?
  - Name the top one to three drivers by their full label text, not by symbol or rank number. Quote the importance metric: for continuous outcomes, RW as a percent of R-squared (e.g., "Workload accounts for 28% of the explained variance in engagement"). For binary outcomes, the standardized log-odds coefficient (e.g., "a one-standard-deviation increase in psychological safety roughly doubles the odds of intention to stay").
  - When a Pearson r is large but the standardized beta is near zero, mention that the driver looks important on its own but its effect is absorbed by other predictors. This is the multicollinearity story; explain it plainly without the jargon.
  - If RW is not available (logistic outcome), say so briefly and lean on standardized betas instead.
  - When no driver is significant despite reasonable n, advise the reader to either add stronger candidate drivers or collect more responses; do not over-interpret near-zero coefficients.
  - Two to four sentences. Plain prose. No bullet lists in the paragraph. Avoid "p < 0.05" style notation; say "significant" or "reliable" instead.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Workload tops the ranking", "Pay shows zero unique effect", "Three drivers explain most variance", "Model finds no clear lever", "Pseudo R-squared above target".

Headline:
  - One sentence verdict.

Affected drivers (Phase 93):
  - When the paragraph or any highlight names specific drivers by label, also list them in an affected_items array.
  - Each entry has shape { "type": "driver", "id": "<rank>" } where rank is the integer rank shown in the snapshot (1, 2, 3, ...).
  - Empty array is fine when the narration is generic.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "driver", "id": "<rank of a driver named above>" }
  ]
}
SYS;

$userPrompt = "Key Driver Analysis snapshot:\n\n" . $snapshotBlock . "\n\nProduce the Key Drivers narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Clear key drivers',
    'ok'   => 'Workable drivers',
    'warn' => 'Weak signal',
    'bad'  => 'No clear drivers',
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

// Phase 93: normalize affected_items. Whitelist type='driver' and validate
// the id is a positive integer within the number of drivers we sent.
$affectedItems = [];
$maxRank = $driversCount;
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'driver') continue;
        if (!ctype_digit($id)) continue;
        $rank = (int)$id;
        if ($rank < 1 || $rank > $maxRank) continue;
        $affectedItems[] = ['type' => $type, 'id' => (string)$rank];
        if (count($affectedItems) >= 8) break;
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
