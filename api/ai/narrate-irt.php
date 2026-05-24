<?php
// POST /api/ai/narrate-irt.php
// Body: {
//   "snapshot": {
//     "n":                     <int>,
//     "item_count":            <int>,
//     "marginal_reliability":  <float|null>,
//     "mean_discrimination":   <float|null>,
//     "peak_info_value":       <float|null>,
//     "peak_info_theta":       <float|null>,
//     "converged":             <bool>,
//     "iterations":            <int>,
//     "items": [
//       { "index": <int>, "prompt": <string>,
//         "a": <float|null>, "a_se": <float|null>,
//         "peak_info": <float|null>, "peak_theta": <float|null>,
//         "strength": "strong"|"moderate"|"weak" }
//     ],
//     "top_items":  [...subset of items...],
//     "weak_items": [...subset of items...]
//   }
// }
//
// Phase 70. AI narrator for the Item Response Theory card on the
// Validity tab. Reads discrimination, marginal reliability, and the
// test information function, then explains in plain language where the
// instrument measures precisely and which items pull their weight.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_irt:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$n           = max(0, (int)($snap['n'] ?? 0));
$itemCount   = max(0, (int)($snap['item_count'] ?? 0));
$converged   = !empty($snap['converged']);
$iterations  = max(0, (int)($snap['iterations'] ?? 0));
$irtModel    = clean_string((string)($snap['model'] ?? 'graded'), 16);
$dimensions  = isset($snap['dimensions']) ? max(1, (int)$snap['dimensions']) : 1;
$rotation    = clean_string((string)($snap['rotation'] ?? 'none'), 16);

if ($n < 30 || $itemCount < 5) fail('insufficient_data', 'IRT narration needs at least 30 respondents and 5 items.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$marRel    = $normFloat($snap['marginal_reliability'] ?? null, -1, 2);
$aBar      = $normFloat($snap['mean_discrimination'] ?? null, 0, 10);
$peakInfo  = $normFloat($snap['peak_info_value'] ?? null, 0, 1e6, 2);
$peakTheta = $normFloat($snap['peak_info_theta'] ?? null, -10, 10);

$itemsIn = is_array($snap['items'] ?? null) ? $snap['items'] : [];
$items = [];
foreach ($itemsIn as $it) {
    if (!is_array($it)) continue;
    $items[] = [
        'index'      => (int)($it['index'] ?? 0),
        'prompt'     => clean_string((string)($it['prompt'] ?? ''), 100),
        'a'          => $normFloat($it['a']      ?? null, 0, 10),
        'a_se'       => $normFloat($it['a_se']   ?? null, 0, 10),
        'peak_info'  => $normFloat($it['peak_info']  ?? null, 0, 1e6, 2),
        'peak_theta' => $normFloat($it['peak_theta'] ?? null, -10, 10),
        'strength'   => (string)($it['strength'] ?? 'moderate'),
    ];
    if (count($items) >= 30) break;
}

$readSubset = function ($key) use ($snap, $normFloat) {
    if (!is_array($snap[$key] ?? null)) return [];
    $out = [];
    foreach ($snap[$key] as $it) {
        if (!is_array($it)) continue;
        $out[] = [
            'index'  => (int)($it['index'] ?? 0),
            'prompt' => clean_string((string)($it['prompt'] ?? ''), 100),
            'a'      => $normFloat($it['a'] ?? null, 0, 10),
            'peak_theta' => $normFloat($it['peak_theta'] ?? null, -10, 10),
        ];
        if (count($out) >= 5) break;
    }
    return $out;
};
$topItems  = $readSubset('top_items');
$weakItems = $readSubset('weak_items');

$fmt = function ($v) { return $v === null ? 'NA' : (string)$v; };

$topBlock = '';
foreach ($topItems as $it) {
    $topBlock .= sprintf('  - Item %d: "%s" (a = %s, peak info near theta = %s)' . "\n",
        $it['index'], $it['prompt'], $fmt($it['a']), $fmt($it['peak_theta']));
}
$weakBlock = '';
foreach ($weakItems as $it) {
    $weakBlock .= sprintf('  - Item %d: "%s" (a = %s)' . "\n",
        $it['index'], $it['prompt'], $fmt($it['a']));
}

$modelLabel = $irtModel === 'mirt-grm' ? 'Two-dimensional Graded Response (MIRT)'
            : ($irtModel === '3pl' ? 'Three-Parameter Logistic (dichotomous, with guessing)'
              : ($irtModel === '2pl' ? 'Two-Parameter Logistic (dichotomous)' : 'Single-factor Graded Response Model'));

if ($irtModel === 'mirt-grm') {
    // Separate snapshot shape for MIRT.
    $r1 = $normFloat($snap['trait_reliability_1'] ?? null, -1, 2);
    $r2 = $normFloat($snap['trait_reliability_2'] ?? null, -1, 2);
    $crossN = max(0, (int)($snap['cross_loader_count'] ?? 0));
    $snapshotBlock  = $modelLabel . ".\n";
    $snapshotBlock .= "Rotation: " . ($rotation === 'varimax' ? 'Varimax (orthogonal)' : 'None') . "\n";
    $snapshotBlock .= "n = " . $n . ', items = ' . $itemCount . ', dimensions = ' . $dimensions . "\n";
    $snapshotBlock .= "Trait 1 reliability = " . $fmt($r1) . "\n";
    $snapshotBlock .= "Trait 2 reliability = " . $fmt($r2) . "\n";
    $snapshotBlock .= "Cross-loaders (Hofmann complexity >= 0.30): " . $crossN . "\n";
    $snapshotBlock .= "Solver: " . ($converged ? 'converged' : 'did not fully converge') . " in " . $iterations . " EM iterations\n\n";
    // Cross-loader list (snapshot includes top 5).
    $clRaw = is_array($snap['cross_loaders'] ?? null) ? $snap['cross_loaders'] : [];
    $clBlock = '';
    foreach ($clRaw as $it) {
        if (!is_array($it)) continue;
        $a = is_array($it['a'] ?? null) ? $it['a'] : [null, null];
        $clBlock .= sprintf('  - Item %d: "%s" (a_1 = %s, a_2 = %s, complexity = %s)' . "\n",
            (int)($it['index'] ?? 0),
            clean_string((string)($it['prompt'] ?? ''), 100),
            $fmt($normFloat($a[0] ?? null, -10, 10, 2)),
            $fmt($normFloat($a[1] ?? null, -10, 10, 2)),
            $fmt($normFloat($it['complexity'] ?? null, 0, 1, 2))
        );
    }
    // Strongest per-trait item (largest |a_d|).
    $trait1Best = null; $trait2Best = null;
    foreach ($itemsIn as $it) {
        if (!is_array($it)) continue;
        $a = is_array($it['a'] ?? null) ? $it['a'] : [null, null];
        $a1 = is_numeric($a[0] ?? null) ? abs((float)$a[0]) : 0;
        $a2 = is_numeric($a[1] ?? null) ? abs((float)$a[1]) : 0;
        if ($trait1Best === null || $a1 > $trait1Best['mag']) $trait1Best = ['mag' => $a1, 'it' => $it];
        if ($trait2Best === null || $a2 > $trait2Best['mag']) $trait2Best = ['mag' => $a2, 'it' => $it];
    }
    $bestLine = function ($best, $traitNo) use ($fmt, $normFloat) {
        if (!$best) return '';
        $a = is_array($best['it']['a'] ?? null) ? $best['it']['a'] : [null, null];
        return sprintf('  - Trait %d strongest: Item %d "%s" (a_%d = %s)' . "\n",
            $traitNo,
            (int)($best['it']['index'] ?? 0),
            clean_string((string)($best['it']['prompt'] ?? ''), 100),
            $traitNo,
            $fmt($normFloat($a[$traitNo - 1] ?? null, -10, 10, 2))
        );
    };
    $snapshotBlock .= "Strongest item per trait:\n" . $bestLine($trait1Best, 1) . $bestLine($trait2Best, 2) . "\n";
    $snapshotBlock .= "Cross-loading items (up to 5):\n" . ($clBlock === '' ? '  (none)' : $clBlock);
} else {
    $snapshotBlock  = $modelLabel . ".\n";
    $snapshotBlock .= "n = " . $n . ', items = ' . $itemCount . "\n";
    $snapshotBlock .= "Marginal reliability = " . $fmt($marRel) . "\n";
    $snapshotBlock .= "Mean discrimination (a) = " . $fmt($aBar) . "\n";
    $snapshotBlock .= "Peak test information = " . $fmt($peakInfo) . " at theta = " . $fmt($peakTheta) . "\n";
    if ($irtModel === '3pl') {
        $cs = [];
        foreach ($itemsIn as $it) {
            if (is_array($it) && isset($it['c']) && is_numeric($it['c'])) $cs[] = (float)$it['c'];
        }
        if (count($cs)) {
            $cBar = array_sum($cs) / count($cs);
            $snapshotBlock .= "Mean guessing parameter (c) = " . number_format($cBar, 3) . "\n";
        }
    }
    $snapshotBlock .= "Solver: " . ($converged ? 'converged' : 'did not fully converge') . " in " . $iterations . " EM iterations\n\n";
    $snapshotBlock .= "Top discriminators:\n" . ($topBlock === '' ? '  (none)' : $topBlock) . "\n";
    $snapshotBlock .= "Weak items (a < 0.8):\n" . ($weakBlock === '' ? '  (none)' : $weakBlock);
}

$system = <<<SYS
You are a measurement researcher narrating the Item Response Theory (IRT) card of a survey app. The user is a researcher or an HR / evaluation lead with at least working familiarity. The fitted model is one of:
  - Graded Response Model: polytomous IRT for ordered Likert items (default).
  - 2PL: two-parameter logistic for dichotomous items (a = discrimination, b = difficulty).
  - 3PL: three-parameter logistic adds a lower-asymptote / guessing parameter c that captures the chance respondents endorse correctly without knowing.
  - MIRT: two-dimensional Graded Response Model. Each item has loadings a_1 and a_2 on two latent traits. Rotation (Varimax) is applied to make the loading structure interpretable. Reports per-trait marginal reliability, an item loading matrix with communality (h^2) and Hofmann complexity, and a count of cross-loaders (items with complexity at or above 0.30). The MIRT snapshot does not include peak test info or mean discrimination because those are trait-specific.
Mention the model by name early in the paragraph so the reader knows which one is in play. For 3PL, the mean c parameter indicates how much guessing the data show: a mean c near zero is what you want; a mean c above ~0.2 suggests the items are guessable. Items dichotomized at the median for 2PL/3PL when no native dichotomous items exist.

When the model is MIRT, your job is different:
  - Open with whether the two-trait structure earns its keep. Both trait reliabilities at or above 0.70 with few cross-loaders means MIRT is doing real work; one weak trait or many cross-loaders means a one-factor (Graded) model would tell the same story more cleanly.
  - Name the strongest item per trait by prompt text and call out any high-complexity items by prompt text (these are items the user should reconsider; they could be moved to one trait, dropped, or split into two items).
  - If rotation is Varimax, the loadings are already in simple-structure form. If rotation is None, mention that loadings are unrotated and harder to read.
  - The tone tiers below shift for MIRT: "good" requires both trait reliabilities at or above 0.80 and cross-loader count below 20% of items; "ok" requires both at or above 0.70; "warn" if any trait is below 0.70 OR cross-loaders exceed 30% of items; "bad" if either trait reliability is below 0.60 OR solver did not converge.

Tone tiers for the visual pill:
  - "good" : marginal reliability >= 0.85 AND mean discrimination >= 1.2 with most items pulling their weight.
  - "ok"   : reliability 0.75 to 0.85, mean discrimination 0.8 to 1.2, or one or two weak items.
  - "warn" : reliability 0.65 to 0.75 or multiple weak items.
  - "bad"  : reliability below 0.65 or solver did not converge.

Voice:
  - Open with the bottom line in plain language. How well does the scale measure the underlying trait, and where on the trait continuum is it most precise?
  - Translate the indices:
      "Marginal reliability is the IRT-version of Cronbach's alpha, averaged over the trait distribution."
      "Discrimination (a) tells you how sharply each item separates respondents at different trait levels. Higher is better, with above 1.5 strong and below 0.8 weak."
      "Test information peaks at theta = X means the scale measures most precisely around that point on the latent trait."
  - Name the strongest discriminator by prompt text, and call out weak items by prompt text with a recommendation to revise or drop.
  - If the peak information is in the middle of the trait scale (theta near 0), say so; if it is off-center, say where the scale is most useful (e.g., "for high-trait respondents, the scale is informative but less precise at the low end").
  - Two to four sentences. Plain prose. No bullet lists in the paragraph. Avoid "p < 0.05" style notation.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Strong discrimination overall", "Item X is weak", "Most precise at low trait", "Reliability above target", "Solver did not converge".

Headline:
  - One sentence verdict.

Affected items (Phase 93):
  - When the paragraph or any highlight names specific items (by item number or prompt quote), also list them in an affected_items array.
  - Each entry has shape { "type": "item", "id": "<0-based item index as string>" }.
  - The snapshot lists items with 1-based "Item N" labels. Convert to 0-based for the id ("Item 4" becomes id "3").
  - Applies to Graded, 2PL, 3PL, and MIRT modes. For MIRT, refer to per-trait items by their index in the loading matrix.
  - Empty array is fine when the narration does not call out specific items.

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
    { "type": "item", "id": "<0-based index of an item called out above>" }
  ]
}
SYS;

$userPrompt = "IRT snapshot:\n\n" . $snapshotBlock . "\n\nProduce the IRT narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Strong measurement',
    'ok'   => 'Workable measurement',
    'warn' => 'Mixed measurement',
    'bad'  => 'Weak measurement',
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

// Phase 93: normalize affected_items. Whitelist type='item' and validate the
// id is a non-negative integer less than itemCount.
$affectedItems = [];
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'item') continue;
        if (!ctype_digit($id)) continue;
        $idx = (int)$id;
        if ($idx < 0 || $idx >= $itemCount) continue;
        $affectedItems[] = ['type' => $type, 'id' => (string)$idx];
        if (count($affectedItems) >= 10) break;
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
