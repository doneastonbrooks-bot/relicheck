<?php
// POST /api/ai/narrate-mlm.php
// Body: {
//   "snapshot": {
//     "outcome_label":     <string>,
//     "group_label":       <string>,
//     "n_total":           <int>,
//     "n_groups":          <int>,
//     "median_group_size": <int>,
//     "icc":               <float|null>,
//     "marginal_r2":       <float|null>,
//     "conditional_r2":    <float|null>,
//     "lrt_chi2":          <float|null>,
//     "lrt_df":            <int>,
//     "lrt_p":             <float|null>,
//     "lrt_significant":   <bool>,
//     "aic":               <float|null>,
//     "bic":               <float|null>,
//     "converged":         <bool>,
//     "random_slopes_count": <int>,
//     "fixed_effects": [
//       { "term": <string>, "estimate": <float>, "se": <float>,
//         "t": <float>, "p": <float>, "significant": <bool>,
//         "random_slope": <bool> }
//     ],
//     "variance_components": [
//       { "term": <string>, "variance": <float>, "sd": <float> }
//     ]
//   }
// }
//
// Phase 81. AI narrator for the Multilevel Model card on the MLM tab.
// Reads ICC, variance components, and significant fixed effects, then
// writes a plain-language paragraph explaining whether the nesting
// matters and what predicts the outcome.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_mlm:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$n      = max(0, (int)($snap['n_total']  ?? 0));
$nGroups = max(0, (int)($snap['n_groups'] ?? 0));
if ($n < 20 || $nGroups < 3) fail('insufficient_data', 'MLM narration needs at least 20 respondents and 3 groups.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$outcomeLabel    = clean_string((string)($snap['outcome_label'] ?? ''), 120);
$groupLabel      = clean_string((string)($snap['group_label']   ?? ''), 120);
$groupLabelL3    = clean_string((string)($snap['group_label_l3'] ?? ''), 120);
$modelKind       = clean_string((string)($snap['model_kind']    ?? 'gaussian-2'), 32);
$dfMethod        = clean_string((string)($snap['df_method']     ?? 'wald'), 32);
$krInflation     = $normFloat($snap['kr_inflation']      ?? null, 0, 10, 3);
$medianGroupSize = max(0, (int)($snap['median_group_size'] ?? 0));
$icc             = $normFloat($snap['icc']               ?? null, -1, 2);
$iccL2           = $normFloat($snap['icc_l2']            ?? null, -1, 2);
$iccL3           = $normFloat($snap['icc_l3']            ?? null, -1, 2);
$iccCombined     = $normFloat($snap['icc_combined']      ?? null, -1, 2);
$marginalR2      = $normFloat($snap['marginal_r2']       ?? null, -1, 2);
$conditionalR2   = $normFloat($snap['conditional_r2']    ?? null, -1, 2);
$lrtChi2         = $normFloat($snap['lrt_chi2']          ?? null, 0, 1e9, 2);
$lrtDf           = max(0, (int)($snap['lrt_df'] ?? 0));
$lrtP            = $normFloat($snap['lrt_p']             ?? null, 0, 1, 4);
$lrtSig          = !empty($snap['lrt_significant']);
$lrt3Chi2        = $normFloat($snap['lrt_3level_chi2']   ?? null, 0, 1e9, 2);
$lrt3P           = $normFloat($snap['lrt_3level_p']      ?? null, 0, 1, 4);
$nGroupsL3       = isset($snap['n_groups_l3']) && is_numeric($snap['n_groups_l3']) ? (int)$snap['n_groups_l3'] : null;
$converged       = !empty($snap['converged']);
$randomSlopes    = max(0, (int)($snap['random_slopes_count'] ?? 0));
$aic             = $normFloat($snap['aic'] ?? null, -1e9, 1e9, 2);
$bic             = $normFloat($snap['bic'] ?? null, -1e9, 1e9, 2);

$fixedEffects = [];
if (is_array($snap['fixed_effects'] ?? null)) {
    foreach ($snap['fixed_effects'] as $fe) {
        if (!is_array($fe)) continue;
        $row = [
            'term'         => clean_string((string)($fe['term'] ?? ''), 120),
            'estimate'     => $normFloat($fe['estimate'] ?? null, -1e6, 1e6, 3),
            'se'           => $normFloat($fe['se']       ?? null, 0, 1e6, 3),
            't'            => $normFloat($fe['t']        ?? null, -1e6, 1e6, 2),
            'p'            => $normFloat($fe['p']        ?? null, 0, 1, 4),
            'significant'  => !empty($fe['significant']),
            'random_slope' => !empty($fe['random_slope']),
        ];
        if (isset($fe['odds_ratio'])) $row['odds_ratio'] = $normFloat($fe['odds_ratio'], 0, 1e6, 3);
        if (isset($fe['or_low']))     $row['or_low']     = $normFloat($fe['or_low'],     0, 1e6, 3);
        if (isset($fe['or_high']))    $row['or_high']    = $normFloat($fe['or_high'],    0, 1e6, 3);
        if (isset($fe['df_corrected'])) $row['df_corrected'] = $normFloat($fe['df_corrected'], 0, 1e6, 1);
        $fixedEffects[] = $row;
        if (count($fixedEffects) >= 15) break;
    }
}
$varianceComponents = [];
if (is_array($snap['variance_components'] ?? null)) {
    foreach ($snap['variance_components'] as $vc) {
        if (!is_array($vc)) continue;
        $varianceComponents[] = [
            'term'     => clean_string((string)($vc['term'] ?? ''), 120),
            'variance' => $normFloat($vc['variance'] ?? null, 0, 1e6, 4),
            'sd'       => $normFloat($vc['sd']       ?? null, 0, 1e6, 4),
        ];
        if (count($varianceComponents) >= 10) break;
    }
}

$fmt = function ($v) { return $v === null ? 'NA' : (string)$v; };

$kindLabel = 'Two-level linear mixed-effects model (REML)';
if ($modelKind === 'logistic-2') $kindLabel = 'Two-level logistic GLMM (PQL/Laplace)';
elseif ($modelKind === 'gaussian-3') $kindLabel = 'Three-level linear mixed-effects model (REML)';

$dfLabel = 'Wald z';
if ($dfMethod === 'satterthwaite') $dfLabel = 'Satterthwaite-approximated t';
elseif ($dfMethod === 'kenward-roger') $dfLabel = 'Kenward-Roger small-sample t';

$fixedBlock = '';
foreach ($fixedEffects as $fe) {
    $tag = $fe['random_slope'] ? ' [random slope]' : '';
    $star = $fe['significant'] ? ' (significant)' : '';
    $or = '';
    if (isset($fe['odds_ratio'])) {
        $or = sprintf(', OR = %s (95%% CI %s to %s)', $fmt($fe['odds_ratio']), $fmt($fe['or_low'] ?? null), $fmt($fe['or_high'] ?? null));
    }
    $dfStr = isset($fe['df_corrected']) ? sprintf(', df = %s', $fmt($fe['df_corrected'])) : '';
    $fixedBlock .= sprintf('  - %s: estimate %s, SE %s, t = %s, p = %s%s%s%s%s' . "\n",
        $fe['term'], $fmt($fe['estimate']), $fmt($fe['se']),
        $fmt($fe['t']), $fmt($fe['p']), $dfStr, $or, $star, $tag);
}
$varBlock = '';
foreach ($varianceComponents as $vc) {
    $varBlock .= sprintf('  - %s: variance %s (SD %s)' . "\n",
        $vc['term'], $fmt($vc['variance']), $fmt($vc['sd']));
}

$snapshotBlock  = $kindLabel . ".\n";
$snapshotBlock .= "Fixed-effect inference: " . $dfLabel . "\n";
$snapshotBlock .= "Outcome: " . ($outcomeLabel === '' ? '(unnamed)' : $outcomeLabel) . "\n";
$snapshotBlock .= "Grouping: " . ($groupLabel === '' ? '(unnamed)' : $groupLabel) . ($groupLabelL3 !== '' ? (' | L3: ' . $groupLabelL3) : '') . "\n";
$snapshotBlock .= "N = " . $n . " across " . $nGroups . " L2 group(s)" . ($nGroupsL3 !== null ? (' inside ' . $nGroupsL3 . ' L3 unit(s)') : '') . " (median L2 group size " . $medianGroupSize . ")\n";
if ($modelKind === 'gaussian-3') {
    $snapshotBlock .= "ICC at L2 = " . $fmt($iccL2) . ", ICC at L3 = " . $fmt($iccL3) . ", combined ICC = " . $fmt($iccCombined) . "\n";
    if ($lrt3Chi2 !== null) {
        $snapshotBlock .= "LR test vs 2-level (L2 only): chi-square = " . $fmt($lrt3Chi2) . ", p = " . $fmt($lrt3P) . "\n";
    }
} else {
    $snapshotBlock .= "ICC = " . $fmt($icc) . ($modelKind === 'logistic-2' ? ' (latent / logit scale)' : '') . "\n";
}
if ($modelKind !== 'logistic-2') {
    $snapshotBlock .= "Marginal R^2 (fixed only) = " . $fmt($marginalR2) . "\n";
    $snapshotBlock .= "Conditional R^2 (fixed + random) = " . $fmt($conditionalR2) . "\n";
    if ($modelKind === 'gaussian-2' && $lrtChi2 !== null) {
        $snapshotBlock .= "Likelihood-ratio test vs OLS null: chi-square = " . $fmt($lrtChi2) . ", df = " . $lrtDf . ", p = " . $fmt($lrtP) . " (" . ($lrtSig ? 'significant' : 'not significant') . ")\n";
    }
    $snapshotBlock .= "AIC = " . $fmt($aic) . ", BIC = " . $fmt($bic) . "\n";
}
if ($dfMethod === 'kenward-roger' && $krInflation !== null) {
    $snapshotBlock .= "Kenward-Roger variance inflation factor: " . $fmt($krInflation) . "\n";
}
$snapshotBlock .= "Random slopes fitted: " . $randomSlopes . "\n";
$snapshotBlock .= "Solver: " . ($converged ? 'converged' : 'did not fully converge') . "\n\n";
$snapshotBlock .= "Fixed effects:\n" . ($fixedBlock === '' ? '  (none)' : $fixedBlock) . "\n";
$snapshotBlock .= "Variance components:\n" . ($varBlock === '' ? '  (none)' : $varBlock);

$system = <<<SYS
You are a measurement researcher narrating the Multilevel Model card of a survey app. The user is a researcher, evaluation lead, or HR analyst with working familiarity. The model may be:
  - Two-level linear mixed-effects model (REML, EM) for a continuous outcome with random intercepts and optional random slopes (this is the default).
  - Two-level logistic GLMM via Penalized Quasi-Likelihood (PQL) for a binary outcome with a random intercept on the log-odds scale. Estimates are unbiased for medium-to-large J; with small clusters or extreme probabilities PQL slightly underestimates random-effect variance. Mention this caveat only if J is small or the ICC is near zero.
  - Three-level linear mixed-effects model with random intercepts at both L2 and L3 (no slopes). The snapshot reports ICC at L2 and L3 separately and a likelihood-ratio test against the corresponding two-level model.
The fixed-effect inference may be Wald-z (default), Satterthwaite, or Kenward-Roger. With KR, the snapshot includes the variance inflation factor; mention it only when it is materially above 1 (above ~1.05).

Tone tiers for the visual pill:
  - "good" : substantial clustering (ICC >= 0.10 in the relevant sense, or non-trivial L3 variance in the 3-level case) AND at least one significant fixed effect (not the intercept). The nesting matters and the model finds signal.
  - "ok"   : moderate clustering (ICC between 0.05 and 0.10), or fixed effects present without significance. The model is workable.
  - "warn" : weak clustering (ICC below 0.05), OR solver did not converge, OR no fixed effects different from zero. Worth a second look.
  - "bad"  : ICC near 0 AND no meaningful predictors AND, when applicable, the LR test rejects the extra layer. The multilevel structure adds nothing in this dataset.

Voice:
  - Open with the bottom line in plain language. Does the grouping account for variance, and does anything predict the outcome?
  - For continuous two-level: translate marginal vs conditional R-squared and ICC the same way as before.
  - For logistic GLMM: report odds ratios for any significant predictors ("a one-unit increase doubles the odds" if OR is near 2, "halves the odds" if near 0.5, etc.). Latent ICC is interpreted on the log-odds scale.
  - For three-level: explicitly compare ICC at L2 and L3 ("most of the higher-level variation sits at the school level rather than the classroom level"). If the LR test against the 2-level model is non-significant, say the L3 layer is not pulling its weight here.
  - Name the strongest significant fixed effect by term, with sign and magnitude. If random slopes were fitted (continuous 2-level only), mention whether their variance is substantial.
  - If df_method is Satterthwaite or Kenward-Roger and J < 20, briefly note that those corrections are doing real work; otherwise stay quiet about the choice.
  - If the solver did not converge, lead with that and suggest dropping one random slope or simplifying the model.
  - Two to four sentences. Plain prose. No bullet lists in the paragraph. Avoid "p < 0.05" style notation; say "significant" or quote the p value directly.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "ICC above 0.10", "Group nesting matters", "Workload predicts outcome", "Odds nearly doubled", "L3 layer adds little", "Random slope variance large", "Solver did not converge", "No meaningful predictors".

Headline:
  - One sentence verdict.

Affected items (Phase 105):
  - When the paragraph or any highlight names specific fixed effects, also list them in an affected_items array.
  - Each entry has shape { "type": "fixed_effect", "id": "<exact term name as shown in the fixed_effects rows>" }.
  - The id must match a term that appears in the snapshot. Skip "(Intercept)" rows.
  - Empty array is fine when the narration is about overall fit / ICC / nesting only.

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
    { "type": "fixed_effect", "id": "<fixed effect term called out above>" }
  ]
}
SYS;

$userPrompt = "Multilevel model snapshot:\n\n" . $snapshotBlock . "\n\nProduce the MLM narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Nesting matters',
    'ok'   => 'Workable model',
    'warn' => 'Mixed signal',
    'bad'  => 'No nesting effect',
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

// Phase 105: normalize affected_items. Whitelist type='fixed_effect' and
// validate the id is one of the fixed-effect term names (excluding Intercept).
$affectedItems = [];
$validTerms = [];
foreach ($fixedEffects as $fe) {
    $term = (string)$fe['term'];
    if ($term === '' || stripos($term, 'intercept') !== false) continue;
    $validTerms[$term] = true;
}
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 120);
        if ($type !== 'fixed_effect') continue;
        if (!isset($validTerms[$id])) continue;
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
