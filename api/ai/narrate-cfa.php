<?php
// POST /api/ai/narrate-cfa.php
// Body: {
//   "snapshot": {
//     "n":             <int>,
//     "k":             <int>,
//     "m":             <int>,
//     "df":            <int>,
//     "chi2":          <float>,
//     "p":             <float|null>,
//     "baseline_chi2": <float>,
//     "baseline_df":   <int>,
//     "cfi":           <float>,
//     "tli":           <float>,
//     "rmsea":         <float>,
//     "rmsea_lo":      <float|null>,
//     "rmsea_hi":      <float|null>,
//     "srmr":          <float>,
//     "converged":     <bool>,
//     "iterations":    <int>,
//     "factors": [
//       { "name": <string>, "item_count": <int>,
//         "mean_loading": <float>, "min_loading": <float>, "max_loading": <float>,
//         "weak_count": <int>,
//         "top_items":  [ { "prompt": <string>, "lambda": <float> }, ... ],
//         "weak_items": [ { "prompt": <string>, "lambda": <float> }, ... ]
//       }, ...
//     ],
//     "phi_pairs": [ { "a": <string>, "b": <string>, "phi": <float> }, ... ],
//     "items_excluded":   <int>,
//     "factors_excluded": <int>
//   }
// }
//
// Phase 64. AI narrator for the Confirmatory Factor Analysis card. Reads
// the fit indices and writes a plain-language summary that translates
// chi-square, CFI, TLI, RMSEA, and SRMR into something a non-statistician
// can act on.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_cfa:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$n  = max(0, (int)($snap['n']  ?? 0));
$k  = max(0, (int)($snap['k']  ?? 0));
$m  = max(0, (int)($snap['m']  ?? 0));
$df = (int)($snap['df'] ?? 0);
if ($n < 5 || $k < 3) {
    fail('insufficient_data', 'CFA needs at least 5 complete responses and 3 items assigned to constructs.');
}

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$chi2      = $normFloat($snap['chi2']         ?? null, 0.0, 1.0e9, 2);
$pVal      = $normFloat($snap['p']            ?? null, 0.0, 1.0);
$baseChi2  = $normFloat($snap['baseline_chi2'] ?? null, 0.0, 1.0e9, 2);
$baseDf    = (int)($snap['baseline_df'] ?? 0);
$cfi       = $normFloat($snap['cfi']    ?? null, -1.0, 2.0);
$tli       = $normFloat($snap['tli']    ?? null, -2.0, 2.0);
$rmsea     = $normFloat($snap['rmsea']  ?? null, 0.0, 1.0);
$rmseaLo   = $normFloat($snap['rmsea_lo'] ?? null, 0.0, 1.0);
$rmseaHi   = $normFloat($snap['rmsea_hi'] ?? null, 0.0, 1.0);
$srmr      = $normFloat($snap['srmr']   ?? null, 0.0, 1.0);
$converged = !empty($snap['converged']);
$iter      = max(0, (int)($snap['iterations'] ?? 0));
$itemsExcluded   = max(0, (int)($snap['items_excluded']   ?? 0));
$factorsExcluded = max(0, (int)($snap['factors_excluded'] ?? 0));

$factorsIn = is_array($snap['factors'] ?? null) ? $snap['factors'] : [];
$factors = [];
foreach ($factorsIn as $f) {
    if (!is_array($f)) continue;
    $name      = clean_string((string)($f['name'] ?? ''), 60);
    if ($name === '') $name = 'Factor ' . (count($factors) + 1);
    $itemCount = max(0, (int)($f['item_count'] ?? 0));
    $mean      = $normFloat($f['mean_loading'] ?? null, -1.5, 1.5);
    $minL      = $normFloat($f['min_loading']  ?? null, -1.5, 1.5);
    $maxL      = $normFloat($f['max_loading']  ?? null, -1.5, 1.5);
    $weak      = max(0, (int)($f['weak_count'] ?? 0));

    $topItems = [];
    if (is_array($f['top_items'] ?? null)) {
        foreach ($f['top_items'] as $ti) {
            if (!is_array($ti)) continue;
            $prompt = clean_string((string)($ti['prompt'] ?? ''), 80);
            $lam    = $normFloat($ti['lambda'] ?? null, -1.5, 1.5);
            if ($prompt === '' || $lam === null) continue;
            $topItems[] = ['prompt' => $prompt, 'lambda' => $lam];
            if (count($topItems) >= 3) break;
        }
    }
    $weakItems = [];
    if (is_array($f['weak_items'] ?? null)) {
        foreach ($f['weak_items'] as $wi) {
            if (!is_array($wi)) continue;
            $prompt = clean_string((string)($wi['prompt'] ?? ''), 80);
            $lam    = $normFloat($wi['lambda'] ?? null, -1.5, 1.5);
            if ($prompt === '' || $lam === null) continue;
            $weakItems[] = ['prompt' => $prompt, 'lambda' => $lam];
            if (count($weakItems) >= 3) break;
        }
    }

    $factors[] = [
        'name'         => $name,
        'item_count'   => $itemCount,
        'mean_loading' => $mean,
        'min_loading'  => $minL,
        'max_loading'  => $maxL,
        'weak_count'   => $weak,
        'top_items'    => $topItems,
        'weak_items'   => $weakItems,
    ];
    if (count($factors) >= 8) break;
}

$phiPairs = [];
if (is_array($snap['phi_pairs'] ?? null)) {
    foreach ($snap['phi_pairs'] as $pp) {
        if (!is_array($pp)) continue;
        $a   = clean_string((string)($pp['a'] ?? ''), 60);
        $b   = clean_string((string)($pp['b'] ?? ''), 60);
        $phi = $normFloat($pp['phi'] ?? null, -1.5, 1.5);
        if ($a === '' || $b === '' || $phi === null) continue;
        $phiPairs[] = ['a' => $a, 'b' => $b, 'phi' => $phi];
        if (count($phiPairs) >= 12) break;
    }
}

// ---- Build the snapshot block fed to the model ----------------------------

$fmt = function ($v) { return $v === null ? 'not computable' : (string)$v; };

$ratio = ($df > 0 && $chi2 !== null) ? round($chi2 / $df, 2) : null;

$pTxt = $pVal === null
    ? 'not computable'
    : ($pVal < 0.001 ? 'p < .001' : 'p = ' . number_format($pVal, 3));

$rmseaCI = ($rmseaLo === null && $rmseaHi === null)
    ? ''
    : ' (90% CI [' . $fmt($rmseaLo) . ', ' . $fmt($rmseaHi) . '])';

$factorLines = [];
foreach ($factors as $i => $f) {
    $line = sprintf(
        '  %d. %s, %d items, mean loading %s (range %s to %s)',
        $i + 1, $f['name'], $f['item_count'],
        $fmt($f['mean_loading']), $fmt($f['min_loading']), $fmt($f['max_loading'])
    );
    if ($f['weak_count'] > 0) $line .= ', ' . $f['weak_count'] . ' weak item(s)';
    if (!empty($f['top_items'])) {
        $tops = array_map(function ($t) { return '"' . $t['prompt'] . '" (lambda ' . $t['lambda'] . ')'; }, $f['top_items']);
        $line .= ".\n     Strongest: " . implode('; ', $tops);
    }
    if (!empty($f['weak_items'])) {
        $weaks = array_map(function ($w) { return '"' . $w['prompt'] . '" (lambda ' . $w['lambda'] . ')'; }, $f['weak_items']);
        $line .= "\n     Weakest: " . implode('; ', $weaks);
    }
    $factorLines[] = $line;
}
$factorBlock = count($factorLines) ? implode("\n", $factorLines) : '  (none reported)';

$phiLines = [];
foreach ($phiPairs as $pp) {
    $phiLines[] = sprintf('  %s <-> %s : phi = %s', $pp['a'], $pp['b'], $fmt($pp['phi']));
}
$phiBlock = count($phiLines) ? implode("\n", $phiLines) : '  (single-factor model; no factor correlations)';

$snapshotBlock  = "CFA model: " . $k . " items, " . $m . " factor(s), df = " . $df . ", n = " . $n . ".\n";
$snapshotBlock .= "Chi-square = " . $fmt($chi2) . ($ratio !== null ? " (chi2/df = " . $ratio . ")" : '') . ", " . $pTxt . ".\n";
$snapshotBlock .= "Baseline (independence) chi-square = " . $fmt($baseChi2) . ", df = " . $baseDf . ".\n";
$snapshotBlock .= "CFI = " . $fmt($cfi) . ".\n";
$snapshotBlock .= "TLI = " . $fmt($tli) . ".\n";
$snapshotBlock .= "RMSEA = " . $fmt($rmsea) . $rmseaCI . ".\n";
$snapshotBlock .= "SRMR = " . $fmt($srmr) . ".\n";
$snapshotBlock .= "Solver: " . ($converged ? 'converged' : 'did not fully converge') . " in " . $iter . " iterations.\n";
if ($itemsExcluded > 0 || $factorsExcluded > 0) {
    $snapshotBlock .= "Excluded from model: " . $itemsExcluded . " item(s) without a construct, " . $factorsExcluded . " single-item factor(s).\n";
}
$snapshotBlock .= "\nPer-factor:\n" . $factorBlock . "\n";
$snapshotBlock .= "\nFactor correlations:\n" . $phiBlock;

// ---- System prompt --------------------------------------------------------

$system = <<<SYS
You are a measurement researcher narrating the Confirmatory Factor Analysis (CFA) card of a survey app. The user has assigned each Likert item to a construct, and CFA tests whether the data fits that structure. The user is not a statistician.

Tone tiers for the visual pill:
  - "good" : CFI and TLI both at or above 0.95, RMSEA at or below 0.06 with upper CI under 0.08, SRMR at or below 0.08, and all factor mean loadings at or above 0.60.
  - "ok"   : CFI and TLI in 0.90 to 0.949, RMSEA in 0.061 to 0.08, SRMR in 0.081 to 0.10, or one factor with a few weak items.
  - "warn" : CFI or TLI in 0.85 to 0.899, RMSEA in 0.081 to 0.10, SRMR in 0.101 to 0.12, or a factor with many weak items, or a factor correlation above 0.85.
  - "bad"  : CFI or TLI below 0.85, RMSEA above 0.10, SRMR above 0.12, or the solver did not converge.

Voice:
  - Open with the bottom line in plain language. Does the data fit the proposed construct structure or not?
  - Translate the indices into something a researcher or program lead can act on. Avoid the phrase "chi-square distributed." Treat CFI and TLI as "how much better than nothing" indices; RMSEA as "how much error per item the model leaves on the table"; SRMR as "average size of the leftover correlations."
  - If a factor has weak items, name one or two by their prompt text and recommend revising or dropping them.
  - If two factors correlate above 0.85, flag that they may be measuring the same thing.
  - Two to four sentences. Plain prose. No bullet lists in the paragraph.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "RMSEA outside the comfort band", "Factor X has two weak items", "Factor X and Y largely overlap", "Solver did not converge."

Headline:
  - One sentence verdict. Avoid jargon.

Affected items (Phase 105):
  - When the paragraph or any highlight names specific factors (constructs), also list them in an affected_items array.
  - Each entry has shape { "type": "factor", "id": "<exact factor name as shown in the snapshot>" }.
  - The id must match a factor name in the factors array.
  - Empty array is fine when the narration is purely about overall fit indices.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Good model fit'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "factor", "id": "<factor name called out above>" }
  ]
}
SYS;

$userPrompt = "CFA snapshot:\n\n" . $snapshotBlock . "\n\nProduce the CFA narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Good model fit',
    'ok'   => 'Acceptable model fit',
    'warn' => 'Mixed model fit',
    'bad'  => 'Poor model fit',
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

// Phase 105: normalize affected_items. Whitelist type='factor' and validate
// the id matches a factor name in this snapshot.
$affectedItems = [];
$validFactorNames = [];
foreach ($factors as $fac) {
    if (!empty($fac['name'])) $validFactorNames[$fac['name']] = true;
}
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 60);
        if ($type !== 'factor') continue;
        if (!isset($validFactorNames[$id])) continue;
        $affectedItems[] = ['type' => $type, 'id' => $id];
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
