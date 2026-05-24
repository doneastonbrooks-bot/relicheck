<?php
// POST /api/ai/narrate-mediation.php
// Body: {
//   "snapshot": {
//     "verdict":   "full" | "partial" | "no-mediation" | "no-effect",
//     "n":         <int>,
//     "x_label":   <string>,
//     "m_label":   <string>,
//     "y_label":   <string>,
//     "a":         <float|null>, "a_p": <float|null>,
//     "b":         <float|null>, "b_p": <float|null>,
//     "c":         <float|null>, "c_p": <float|null>,
//     "c_prime":   <float|null>, "c_prime_p": <float|null>,
//     "indirect":  <float|null>,
//     "boot_ci":   [<float|null>, <float|null>],
//     "boot_b":    <int>,
//     "sobel_z":   <float|null>, "sobel_p": <float|null>,
//     "proportion_mediated": <float|null>
//   }
// }
//
// Phase 69. AI narrator for the Mediation card. Reads the indirect
// effect with bootstrap CI and explains in plain language whether (and
// how much of) the X to Y relationship runs through the mediator.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_mediation:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$verdict = (string)($snap['verdict'] ?? 'no-effect');
$validVerdicts = ['full', 'partial', 'no-mediation', 'no-effect'];
if (!in_array($verdict, $validVerdicts, true)) $verdict = 'no-effect';

$n       = max(0, (int)($snap['n'] ?? 0));
$xLabel  = clean_string((string)($snap['x_label'] ?? ''), 120);
$mLabel  = clean_string((string)($snap['m_label'] ?? ''), 120);
$yLabel  = clean_string((string)($snap['y_label'] ?? ''), 120);

if ($n < 10) fail('insufficient_data', 'Mediation narration needs at least 10 rows.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$a       = $normFloat($snap['a']       ?? null, -1e6, 1e6);
$aP      = $normFloat($snap['a_p']     ?? null, 0, 1, 4);
$b       = $normFloat($snap['b']       ?? null, -1e6, 1e6);
$bP      = $normFloat($snap['b_p']     ?? null, 0, 1, 4);
$c       = $normFloat($snap['c']       ?? null, -1e6, 1e6);
$cP      = $normFloat($snap['c_p']     ?? null, 0, 1, 4);
$cPrime  = $normFloat($snap['c_prime'] ?? null, -1e6, 1e6);
$cPrimeP = $normFloat($snap['c_prime_p'] ?? null, 0, 1, 4);
$indirect = $normFloat($snap['indirect'] ?? null, -1e6, 1e6);
$sobelZ  = $normFloat($snap['sobel_z'] ?? null, -100, 100, 2);
$sobelP  = $normFloat($snap['sobel_p'] ?? null, 0, 1, 4);
$propMed = $normFloat($snap['proportion_mediated'] ?? null, -10, 10);
$yBinary  = !empty($snap['y_binary']);
$mBinary  = !empty($snap['m_binary']);
$ciMethod = clean_string((string)($snap['ci_method'] ?? 'percentile'), 16);
$bcaZ0    = $normFloat($snap['bca_z0'] ?? null, -10, 10);
$bcaA     = $normFloat($snap['bca_a']  ?? null, -1, 1);

$bootCi = ['low' => null, 'high' => null];
if (is_array($snap['boot_ci'] ?? null) && count($snap['boot_ci']) === 2) {
    $bootCi['low']  = $normFloat($snap['boot_ci'][0], -1e6, 1e6);
    $bootCi['high'] = $normFloat($snap['boot_ci'][1], -1e6, 1e6);
}
$bootB = max(0, (int)($snap['boot_b'] ?? 0));

$fmt  = function ($v) { return $v === null ? 'NA' : (string)$v; };
$fmtP = function ($v) {
    if ($v === null) return 'NA';
    if ($v < 0.001) return 'p < .001';
    return 'p = ' . number_format($v, 3);
};

$snapshotBlock  = 'Variables: X = ' . ($xLabel === '' ? 'predictor' : $xLabel)
                . ', M = ' . ($mLabel === '' ? 'mediator' : $mLabel) . ($mBinary ? ' (binary; logistic M model)' : '')
                . ', Y = ' . ($yLabel === '' ? 'outcome' : $yLabel) . ($yBinary ? ' (binary; logistic Y model)' : '') . "\n";
$snapshotBlock .= 'n = ' . $n . ', verdict = ' . $verdict . "\n";
$snapshotBlock .= 'Bootstrap CI method: ' . ($ciMethod === 'bca' ? 'BCa (bias-corrected and accelerated)' : 'percentile')
                . ($ciMethod === 'bca' && ($bcaZ0 !== null || $bcaA !== null)
                    ? ' (z0 = ' . ($bcaZ0 === null ? 'NA' : (string)$bcaZ0) . ', a = ' . ($bcaA === null ? 'NA' : (string)$bcaA) . ')'
                    : '') . "\n\n";
$snapshotBlock .= 'Paths:' . "\n";
$snapshotBlock .= '  a (X -> M)              : ' . $fmt($a)       . ', ' . $fmtP($aP)      . "\n";
$snapshotBlock .= '  b (M -> Y given X)      : ' . $fmt($b)       . ', ' . $fmtP($bP)      . "\n";
$snapshotBlock .= '  c (X -> Y total)        : ' . $fmt($c)       . ', ' . $fmtP($cP)      . "\n";
$snapshotBlock .= '  c\' (X -> Y direct)     : ' . $fmt($cPrime)  . ', ' . $fmtP($cPrimeP) . "\n";
$snapshotBlock .= '  Indirect effect (a*b)   : ' . $fmt($indirect)
                . ', bootstrap 95% CI [' . $fmt($bootCi['low']) . ', ' . $fmt($bootCi['high']) . ']'
                . ' (' . $bootB . ' resamples)' . "\n";
$snapshotBlock .= '  Sobel test              : z = ' . $fmt($sobelZ) . ', ' . $fmtP($sobelP) . "\n";
if ($propMed !== null) {
    $snapshotBlock .= 'Proportion of total effect mediated through M: ' . number_format($propMed * 100, 0) . '%' . "\n";
}

$system = <<<SYS
You are a measurement researcher narrating the Mediation card of a survey app. The user is an HR / evaluation / academic lead who wants to know whether their predictor's effect on the outcome runs through the mediator. The user is not a statistician.

Tone tiers for the visual pill:
  - "good" : verdict is "full" or "partial". The mediator carries a real, bootstrap-significant share of the X to Y relationship.
  - "ok"   : the indirect effect is positive but the bootstrap CI is wide (touches zero or barely excludes it).
  - "warn" : verdict is "no-mediation". X affects Y but not through this M. The user picked the wrong mediator or there are other paths.
  - "bad"  : verdict is "no-effect". There is no X to Y relationship to mediate.

Voice:
  - Open with the bottom line in plain language. Does M carry the effect? How much of it?
  - Translate the paths: "Variation in X drives variation in M (path a)"; "Variation in M drives variation in Y once we account for X (path b)"; "What's left after M is the direct path c'."
  - If M or Y is binary the relevant path coefficient lives on the log-odds scale. Don't talk about raw "values" of a binary mediator or outcome; say "odds of M" or "odds of Y" and (when helpful) translate a coefficient into odds-ratio language using exp(b). Note that the indirect effect a*b in a binary-Y model is on a hybrid scale; the bootstrap CI for it is still interpretable as "is the path reliable" but the numeric magnitude is not a probability difference.
  - If the bootstrap CI method is BCa, mention briefly that BCa is more accurate than the percentile interval for skewed bootstrap distributions and is the recommended choice for mediation. Only call attention to z0 / a if they are materially different from zero.
  - If the verdict is partial, quote the proportion of the total effect mediated (or report it qualitatively if the proportion is unstable). Skip the proportion when Y is binary; the ratio of two log-odds coefficients does not equal a meaningful share.
  - Avoid the phrase "p < 0.05"; say "bootstrap CI excludes zero" or "the indirect effect is reliable."
  - Two to four sentences. Plain prose. No bullet lists in the paragraph.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Full mediation through M", "Partial mediation: X percent", "No effect to mediate", "Direct path also strong".

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

$userPrompt = "Mediation snapshot:\n\n" . $snapshotBlock . "\n\nProduce the mediation narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Mediation supported',
    'ok'   => 'Mediation plausible',
    'warn' => 'No mediation detected',
    'bad'  => 'No X to Y effect',
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
