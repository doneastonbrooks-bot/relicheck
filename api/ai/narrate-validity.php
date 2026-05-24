<?php
// POST /api/ai/narrate-validity.php
// Body: {
//   "snapshot": {
//     "n": <int>, "k": <int>,
//     "kmo_overall":          <float|null>,
//     "bartlett_p":           <float|null>,
//     "n_factors_retained":   <int>,
//     "cumulative_variance_explained_pct": <int|null>,
//     "rotation":             <string>,
//     "method":               <string>,
//     "factors": [
//       { "idx": <int>, "name": <string>, "item_count": <int>,
//         "variance_share_pct": <int>, "top_items": [<string>, ...] }
//     ],
//     "cross_loaded_items": <int>
//   }
// }
//
// Dashboard Narrator for the Validity Analysis tab (Phase 54).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_validity:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$n = max(0, (int)($snap['n'] ?? 0));
$k = max(0, (int)($snap['k'] ?? 0));
if ($n < 3 || $k < 3) fail('insufficient_data', 'Factor analysis needs at least 3 Likert items and 3 complete responses.');

$normFloat = function ($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, 3);
};

$kmo        = $normFloat($snap['kmo_overall'] ?? null, 0.0, 1.0);
$bartlettP  = $normFloat($snap['bartlett_p']  ?? null, 0.0, 1.0);
$nFactors   = max(0, (int)($snap['n_factors_retained'] ?? 0));
$cumVarPct  = is_numeric($snap['cumulative_variance_explained_pct'] ?? null)
    ? max(0, min(100, (int)round((float)$snap['cumulative_variance_explained_pct'])))
    : null;
$rotation   = clean_string((string)($snap['rotation'] ?? 'none'), 16);
$method     = clean_string((string)($snap['method']   ?? 'pca'),  16);
$crossLoad  = max(0, (int)($snap['cross_loaded_items'] ?? 0));

$factorsIn = is_array($snap['factors'] ?? null) ? $snap['factors'] : [];
$factors = [];
foreach ($factorsIn as $f) {
    if (!is_array($f)) continue;
    $idx       = (int)($f['idx'] ?? count($factors));
    $name      = clean_string((string)($f['name'] ?? ''), 60);
    if ($name === '') $name = 'Factor ' . ($idx + 1);
    $itemCount = max(0, (int)($f['item_count'] ?? 0));
    $share     = is_numeric($f['variance_share_pct'] ?? null)
        ? max(0, min(100, (int)round((float)$f['variance_share_pct'])))
        : 0;
    $topItems = [];
    if (is_array($f['top_items'] ?? null)) {
        foreach ($f['top_items'] as $ti) {
            if (!is_string($ti)) continue;
            $clean = clean_string($ti, 80);
            if ($clean === '') continue;
            $topItems[] = $clean;
            if (count($topItems) >= 4) break;
        }
    }
    $factors[] = [
        'idx'        => $idx,
        'name'       => $name,
        'item_count' => $itemCount,
        'share_pct'  => $share,
        'top_items'  => $topItems,
    ];
    if (count($factors) >= 8) break;
}

$kmoTxt   = $kmo       === null ? 'not computable' : (string)$kmo;
$bartTxt  = $bartlettP === null ? 'not computable' : (string)$bartlettP;
$varTxt   = $cumVarPct === null ? 'not computable' : ($cumVarPct . '%');

$factorLines = [];
foreach ($factors as $f) {
    $top = count($f['top_items']) ? ' top items: "' . implode('"; "', $f['top_items']) . '"' : '';
    $factorLines[] = sprintf(
        '  %d. %s, %d items, %d%% variance share.%s',
        $f['idx'] + 1, $f['name'], $f['item_count'], $f['share_pct'], $top
    );
}
$factorBlock = count($factorLines) ? implode("\n", $factorLines) : '  (none reported)';

$snapshotBlock  = "Scale: " . $k . " Likert items, " . $n . " complete responses.\n";
$snapshotBlock .= "Extraction: method=" . $method . ", rotation=" . $rotation . ".\n";
$snapshotBlock .= "KMO sampling adequacy: " . $kmoTxt . ".\n";
$snapshotBlock .= "Bartlett's test of sphericity, p = " . $bartTxt . ".\n";
$snapshotBlock .= "Factors retained: " . $nFactors . ".\n";
$snapshotBlock .= "Cumulative variance explained: " . $varTxt . ".\n";
$snapshotBlock .= "Cross-loaded items: " . $crossLoad . ".\n\n";
$snapshotBlock .= "Per-factor:\n" . $factorBlock;

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card for the Validity Analysis tab of a survey app. The user is not a statistician. You explain what the factor analysis is saying about how items group together.

Tone tiers for the visual pill:
  - "good" : KMO >= 0.80 AND clear structure (factors well-separated, low cross-loading, >= 60% cumulative variance).
  - "ok"   : KMO 0.70-0.79 OR good KMO with some cross-loaded items OR cumulative variance 50-60%.
  - "warn" : KMO 0.60-0.69 OR meaningful cross-loading OR cumulative variance below 50%.
  - "bad"  : KMO < 0.60, singular matrix, or unclear structure.

Voice:
  - Lead with the structural story (single dimension? Two clean dimensions? Mixed picture?).
  - Translate KMO and Bartlett into plain language ("the data is well-suited to factor analysis", "the items have meaningful shared patterns").
  - Quote factor names where helpful.
  - Avoid jargon like "eigenvalue", "loading", "Bartlett's test". Use "items grouped together", "patterns", "the data supports".
  - 2-4 sentences. Plain prose.

Highlights (0-3): short items surfacing specific structural notes.
  - Each has: label (3-6 words), detail (one short sentence).

Headline:
  - One sentence summarizing the validity picture.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Clear two-factor structure'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence>" }
  ]
}
SYS;

$userPrompt = "Validity snapshot:\n\n" . $snapshotBlock . "\n\nProduce the validity narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = ['good' => 'Clear structure', 'ok' => 'Workable structure', 'warn' => 'Mixed structure', 'bad' => 'Unclear structure'];
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

json_out([
    'ok'         => true,
    'tone'       => $tone,
    'tone_label' => $toneLabel,
    'headline'   => $headline,
    'paragraph'  => $paragraph,
    'highlights' => $highlights,
    'model'      => ai_config()['model'],
]);
