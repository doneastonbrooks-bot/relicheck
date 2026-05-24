<?php
// POST /api/ai/narrate-predictors.php
// Body: {
//   "snapshot": {
//     "model_type": "ols" | "logistic",
//     "outcome":    <string>,
//     "n":          <int>,
//     "k":          <int>,
//     "r2":         <float|null>,
//     "adj_r2":     <float|null>,
//     "f_stat":     <float|null>,
//     "f_p":        <float|null>,
//     "pseudo_r2":  <float|null>,
//     "deviance":   <float|null>,
//     "deviance_df":<int|null>,
//     "deviance_p": <float|null>,
//     "converged":  <bool>,
//     "rows": [
//       { "label": <string>, "beta": <float|null>, "se": <float|null>,
//         "stat": <float|null>, "p": <float|null>,
//         "ci_low": <float|null>, "ci_high": <float|null>,
//         "std_beta":  <float|null>, "odds_ratio": <float|null> }
//     ],
//     "dropped_rows": <int>,
//     "dropped_cols": [<string>, ...]
//   }
// }
//
// Phase 68. AI narrator for the Predictor Analysis tab. Reads the
// regression fit and produces a plain-language summary that names the
// strongest predictors, flags any non-significant ones, and reports
// model fit in language the HR / evaluation audience can act on.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_predictors:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$modelType  = (string)($snap['model_type'] ?? 'ols');
if (!in_array($modelType, ['ols', 'logistic'], true)) $modelType = 'ols';

$outcome    = clean_string((string)($snap['outcome'] ?? ''), 200);
$n          = max(0, (int)($snap['n'] ?? 0));
$kCoef      = max(0, (int)($snap['k'] ?? 0));
$converged  = !empty($snap['converged']);

if ($n < 5 || $kCoef < 2) fail('insufficient_data', 'Regression narration needs at least 5 rows and 2 columns (intercept plus one predictor).');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$r2         = $normFloat($snap['r2']         ?? null, -1, 2);
$adjR2      = $normFloat($snap['adj_r2']     ?? null, -1, 2);
$fStat      = $normFloat($snap['f_stat']     ?? null, 0, 1e9, 2);
$fP         = $normFloat($snap['f_p']        ?? null, 0, 1, 4);
$pseudoR2   = $normFloat($snap['pseudo_r2']  ?? null, -1, 2);
$deviance   = $normFloat($snap['deviance']   ?? null, 0, 1e9, 2);
$devianceDf = (int)($snap['deviance_df'] ?? 0);
$devianceP  = $normFloat($snap['deviance_p'] ?? null, 0, 1, 4);
$droppedRows = max(0, (int)($snap['dropped_rows'] ?? 0));

$droppedCols = [];
if (is_array($snap['dropped_cols'] ?? null)) {
    foreach ($snap['dropped_cols'] as $c) {
        $clean = clean_string((string)$c, 100);
        if ($clean !== '') $droppedCols[] = $clean;
        if (count($droppedCols) >= 6) break;
    }
}

$rowsIn = is_array($snap['rows'] ?? null) ? $snap['rows'] : [];
$rows = [];
foreach ($rowsIn as $r) {
    if (!is_array($r)) continue;
    $rows[] = [
        'label'      => clean_string((string)($r['label'] ?? ''), 100),
        'beta'       => $normFloat($r['beta']      ?? null, -1e6, 1e6, 4),
        'se'         => $normFloat($r['se']        ?? null, 0,    1e6, 4),
        'stat'       => $normFloat($r['stat']      ?? null, -1e3, 1e3, 3),
        'p'          => $normFloat($r['p']         ?? null, 0,    1,   4),
        'ci_low'     => $normFloat($r['ci_low']    ?? null, -1e6, 1e6, 4),
        'ci_high'    => $normFloat($r['ci_high']   ?? null, -1e6, 1e6, 4),
        'std_beta'   => $normFloat($r['std_beta']  ?? null, -10,  10,  3),
        'odds_ratio' => $normFloat($r['odds_ratio'] ?? null, 0,   1e6, 3),
    ];
    if (count($rows) >= 16) break;
}

$fmt = function ($v) { return $v === null ? 'NA' : (string)$v; };
$fmtP = function ($v) {
    if ($v === null) return 'NA';
    if ($v < 0.001) return 'p < .001';
    return 'p = ' . number_format($v, 3);
};

$rowLines = [];
foreach ($rows as $r) {
    $line = '  - ' . $r['label']
        . ': b = ' . $fmt($r['beta'])
        . ' (SE ' . $fmt($r['se']) . ')'
        . ', ' . $fmtP($r['p']);
    if ($modelType === 'ols' && $r['std_beta'] !== null) {
        $line .= ', std beta ' . $fmt($r['std_beta']);
    }
    if ($modelType === 'logistic' && $r['odds_ratio'] !== null) {
        $line .= ', odds ratio ' . $fmt($r['odds_ratio']);
    }
    if ($r['ci_low'] !== null && $r['ci_high'] !== null) {
        $line .= ', 95% CI [' . $fmt($r['ci_low']) . ', ' . $fmt($r['ci_high']) . ']';
    }
    $rowLines[] = $line;
}
$rowBlock = count($rowLines) ? implode("\n", $rowLines) : '  (no predictors reported)';

$snapshotBlock  = 'Model: ' . ($modelType === 'ols' ? 'OLS regression' : 'Logistic regression') . "\n";
$snapshotBlock .= 'Outcome: ' . ($outcome === '' ? 'unspecified' : $outcome) . "\n";
$snapshotBlock .= 'n = ' . $n . ', coefficients (incl. intercept) = ' . $kCoef . "\n";
if ($modelType === 'ols') {
    $snapshotBlock .= 'R^2 = ' . $fmt($r2) . ', Adj R^2 = ' . $fmt($adjR2) . "\n";
    $snapshotBlock .= 'F-test: F = ' . $fmt($fStat) . ', ' . $fmtP($fP) . "\n";
} else {
    $snapshotBlock .= "McFadden pseudo-R^2 = " . $fmt($pseudoR2) . "\n";
    $snapshotBlock .= 'Deviance chi-square: ' . $fmt($deviance) . ', df = ' . $devianceDf . ', ' . $fmtP($devianceP) . "\n";
    $snapshotBlock .= 'IRLS converged: ' . ($converged ? 'yes' : 'no') . "\n";
}
if ($droppedRows > 0) {
    $snapshotBlock .= 'Rows dropped for missing data: ' . $droppedRows . "\n";
}
if (count($droppedCols) > 0) {
    $snapshotBlock .= 'Columns dropped for collinearity / no variance: ' . implode('; ', $droppedCols) . "\n";
}
$snapshotBlock .= "\nCoefficients:\n" . $rowBlock;

$system = <<<SYS
You are a measurement researcher narrating the Predictor Analysis tab of a survey app. The user is an HR, evaluation, or program lead asking "what predicts this outcome?" The user is not a statistician.

Tone tiers for the visual pill:
  - "good" : strong, interpretable model. For OLS: R^2 >= 0.30 with at least one significant predictor and the F-test significant. For logistic: pseudo-R^2 >= 0.20 with at least one significant predictor and deviance test significant.
  - "ok"   : workable model with mixed predictors. R^2 (or pseudo-R^2) 0.10 to 0.30 (0.05 to 0.20 for logistic), one or two significant predictors.
  - "warn" : weak model. R^2 below 0.10 (pseudo-R^2 below 0.05), or no individually-significant predictor, or the omnibus test is not significant.
  - "bad"  : model failed or did not converge.

Voice:
  - Open with the bottom line in plain language: how well do these predictors collectively explain the outcome, and which one matters most?
  - Translate the indices: R^2 as "the model explains X percent of the variation"; logistic pseudo-R^2 as "the model picks up real signal but does not predict perfectly"; odds ratios as "respondents who are higher on X are Y times more likely to be in the positive class."
  - Name the strongest one or two significant predictors and the direction of their effect. Use the standardized beta when comparing predictors on different scales.
  - If a predictor a HR lead would expect to matter is NOT significant, name it explicitly so the user knows that information.
  - If columns were dropped for collinearity, mention it briefly.
  - Two to four sentences. Plain prose. No bullet lists in the paragraph. Do NOT say "p < 0.05"; say "the relationship is statistically reliable" instead.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Strongest driver: X", "X does not predict Y", "Multicollinearity flagged", "Model explains modest variance".

Headline:
  - One sentence verdict, plain language.

Affected items (Phase 105):
  - When the paragraph or any highlight names specific predictors, also list them in an affected_items array.
  - Each entry has shape { "type": "predictor", "id": "<exact predictor label as shown in the snapshot rows>" }.
  - The id must match a label in the rows block. Skip "(Intercept)" rows; they are model metadata, not predictors.
  - Empty array is fine when the narration only talks about overall model fit.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Strong predictive model'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "predictor", "id": "<predictor label called out above>" }
  ]
}
SYS;

$userPrompt = "Regression snapshot:\n\n" . $snapshotBlock . "\n\nProduce the predictor-analysis narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Strong predictive model',
    'ok'   => 'Workable model',
    'warn' => 'Weak predictive signal',
    'bad'  => 'Model did not fit',
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

// Phase 105: normalize affected_items. Whitelist type='predictor' and
// validate the id is one of the predictor row labels (excluding Intercept).
$affectedItems = [];
$validLabels = [];
foreach ($rows as $r) {
    $lab = (string)$r['label'];
    if ($lab === '' || stripos($lab, 'intercept') !== false) continue;
    $validLabels[$lab] = true;
}
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 100);
        if ($type !== 'predictor') continue;
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
