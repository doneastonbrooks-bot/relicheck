<?php
// POST /api/ai/narrate-moderation.php
// Body: {
//   "snapshot": {
//     "n":               <int>,
//     "x_label":         <string>,
//     "w_label":         <string>,
//     "y_label":         <string>,
//     "r2":              <float|null>,
//     "f_stat":          <float|null>,
//     "f_p":             <float|null>,
//     "b1":              <float|null>,  // main effect of X (centered)
//     "b2":              <float|null>,  // main effect of W (centered)
//     "b3":              <float|null>,  // interaction X*W
//     "b3_p":            <float|null>,
//     "interaction_sig": <bool>,
//     "slope_low":       { "W": <float>, "slope": <float>, "p": <float> },
//     "slope_mean":      { "W": <float>, "slope": <float>, "p": <float> },
//     "slope_high":      { "W": <float>, "slope": <float>, "p": <float> },
//     "jn_low":          <float|null>,
//     "jn_high":         <float|null>,
//     "w_min":           <float>,
//     "w_max":           <float>
//   }
// }
//
// Phase 69. AI narrator for the Moderation card. Reads the interaction
// term, the conditional slopes at three values of W, and the
// Johnson-Neyman boundaries, and explains in plain language whether and
// where the X-to-Y relationship strengthens or weakens.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_moderation:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$n      = max(0, (int)($snap['n'] ?? 0));
$xLabel = clean_string((string)($snap['x_label'] ?? ''), 120);
$wLabel = clean_string((string)($snap['w_label'] ?? ''), 120);
$yLabel = clean_string((string)($snap['y_label'] ?? ''), 120);

if ($n < 10) fail('insufficient_data', 'Moderation narration needs at least 10 rows.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$r2     = $normFloat($snap['r2']     ?? null, -1, 2);
$fStat  = $normFloat($snap['f_stat'] ?? null, 0, 1e9, 2);
$fP     = $normFloat($snap['f_p']    ?? null, 0, 1, 4);
$b1     = $normFloat($snap['b1']     ?? null, -1e6, 1e6);
$b2     = $normFloat($snap['b2']     ?? null, -1e6, 1e6);
$b3     = $normFloat($snap['b3']     ?? null, -1e6, 1e6);
$b3P    = $normFloat($snap['b3_p']   ?? null, 0, 1, 4);
$interactionSig = !empty($snap['interaction_sig']);
$jnLow  = $normFloat($snap['jn_low']  ?? null, -1e6, 1e6);
$jnHigh = $normFloat($snap['jn_high'] ?? null, -1e6, 1e6);
$wMin   = $normFloat($snap['w_min']   ?? null, -1e6, 1e6);
$wMax   = $normFloat($snap['w_max']   ?? null, -1e6, 1e6);

$readSlope = function ($s) use ($normFloat) {
    if (!is_array($s)) return null;
    return [
        'W'     => $normFloat($s['W']     ?? null, -1e6, 1e6),
        'slope' => $normFloat($s['slope'] ?? null, -1e6, 1e6),
        'p'     => $normFloat($s['p']     ?? null, 0, 1, 4),
    ];
};
$slopeLow  = $readSlope($snap['slope_low']  ?? null);
$slopeMean = $readSlope($snap['slope_mean'] ?? null);
$slopeHigh = $readSlope($snap['slope_high'] ?? null);

$fmt  = function ($v) { return $v === null ? 'NA' : (string)$v; };
$fmtP = function ($v) {
    if ($v === null) return 'NA';
    if ($v < 0.001) return 'p < .001';
    return 'p = ' . number_format($v, 3);
};

$yBinary = !empty($snap['y_binary']);
$r2Kind  = clean_string((string)($snap['r2_kind'] ?? 'ols'), 16);
$snapshotBlock  = 'Variables: X = ' . ($xLabel === '' ? 'predictor' : $xLabel)
                . ', W = ' . ($wLabel === '' ? 'moderator' : $wLabel)
                . ', Y = ' . ($yLabel === '' ? 'outcome' : $yLabel) . ($yBinary ? ' (binary; logistic interaction model)' : '') . "\n";
$snapshotBlock .= 'n = ' . $n
                . ', ' . ($yBinary ? 'McFadden pseudo-R^2 = ' : 'R^2 = ') . $fmt($r2)
                . ', ' . ($yBinary ? 'Deviance chi-square: ' : 'F-test: ') . $fmt($fStat) . ' ' . $fmtP($fP) . "\n\n";
$snapshotBlock .= 'Main effect X (b1): ' . $fmt($b1) . "\n";
$snapshotBlock .= 'Main effect W (b2): ' . $fmt($b2) . "\n";
$snapshotBlock .= 'Interaction X*W (b3): ' . $fmt($b3) . ', ' . $fmtP($b3P) . ', significant: ' . ($interactionSig ? 'yes' : 'no') . "\n\n";

if ($slopeLow && $slopeMean && $slopeHigh) {
    $snapshotBlock .= 'Conditional slope of X at three values of W:' . "\n";
    $snapshotBlock .= '  W (mean - SD) = ' . $fmt($slopeLow['W'])  . ': slope = ' . $fmt($slopeLow['slope'])  . ', ' . $fmtP($slopeLow['p'])  . "\n";
    $snapshotBlock .= '  W (mean)      = ' . $fmt($slopeMean['W']) . ': slope = ' . $fmt($slopeMean['slope']) . ', ' . $fmtP($slopeMean['p']) . "\n";
    $snapshotBlock .= '  W (mean + SD) = ' . $fmt($slopeHigh['W']) . ': slope = ' . $fmt($slopeHigh['slope']) . ', ' . $fmtP($slopeHigh['p']) . "\n";
}

if ($jnLow !== null && $jnHigh !== null) {
    $snapshotBlock .= "\nJohnson-Neyman: slope of X on Y is NOT statistically significant when W is between "
                   . $fmt($jnLow) . ' and ' . $fmt($jnHigh)
                   . ' (W range in data: ' . $fmt($wMin) . ' to ' . $fmt($wMax) . ').' . "\n";
} else {
    $snapshotBlock .= "\nJohnson-Neyman: slope is significant across the entire observed range of W, or no boundary exists." . "\n";
}

$system = <<<SYS
You are a measurement researcher narrating the Moderation card of a survey app. The user is an HR / evaluation / academic lead trying to figure out whether the X-to-Y relationship depends on a third variable W. The user is not a statistician.

Tone tiers for the visual pill:
  - "good" : interaction is statistically reliable AND the conditional slopes flip sign or move meaningfully (e.g., from negative at low W to positive at high W).
  - "ok"   : interaction is reliable but the magnitude is modest (slopes change in size but not direction).
  - "warn" : interaction p-value just above conventional cutoffs but pattern suggests something worth investigating with more data.
  - "bad"  : no interaction effect; the X to Y relationship is the same across the observed range of W.

Voice:
  - Open with the bottom line. Does the X-to-Y relationship change across W?
  - Translate the slopes: "When W is low, the relationship between X and Y is [slope_low]"; "When W is high, it shifts to [slope_high]". Use plain words like "weaker", "stronger", "flips direction" rather than statistical jargon.
  - Mention the Johnson-Neyman region only when it's inside the observed W range. If the slope is significant everywhere in the data, do not introduce JN into the narrative.
  - Avoid "p < 0.05"; say "the interaction is statistically reliable" or "not reliable."
  - Two to four sentences. Plain prose. No bullet lists in the paragraph.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Effect flips sign across W", "Effect strengthens at high W", "Interaction not reliable", "Slope significant only at high W".

Headline:
  - One sentence verdict.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ]
}
SYS;

$userPrompt = "Moderation snapshot:\n\n" . $snapshotBlock . "\n\nProduce the moderation narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Moderation detected',
    'ok'   => 'Modest interaction',
    'warn' => 'Interaction borderline',
    'bad'  => 'No moderation',
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

json_out([
    'ok'         => true,
    'tone'       => $tone,
    'tone_label' => $toneLabel,
    'headline'   => $headline,
    'paragraph'  => $paragraph,
    'highlights' => $highlights,
    'model'      => ai_config()['model'],
]);
