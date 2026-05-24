<?php
// POST /api/ai/narrate-equity-gaps.php
// Body: {
//   "snapshot": {
//     "score":        <int 0-100>,
//     "status":       "parity" | "small" | "meaningful" | "large",
//     "status_label": <str>,
//     "n_total":      <int>,
//     "k_min":        <int>,
//     "hidden_groups": { "axisCount":<int>, "groupCount":<int> },
//     "axes": [{
//       "axis_label":    <str>,
//       "verdict":       "parity" | "small" | "meaningful" | "large",
//       "verdict_label": <str>,
//       "max_abs_d":     <float>,
//       "anova_p":       <float|null>,
//       "eta_sq":        <float|null>,
//       "groups":        [{ "label":<str>, "n":<int>, "mean":<float> }],
//       "top_pair":      { "a_label":<str>, "b_label":<str>, "d":<float> } | null
//     }]
//   }
// }
//
// Phase 145 Equity Gaps narrator. Same I/O contract as every other
// narrate-*.php endpoint (tone / tone_label / headline / paragraph /
// highlights / affected_items). Reads the cross-axis equity snapshot and
// emits an HR-friendly read of where the gaps are, with explicit named
// group comparisons (so the narration anchors on the actual axis label
// the user picked, not abstract phrases like "demographic group").

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_equity:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};
$numOrNull = function ($v) {
    if ($v === null) return null;
    if (!is_numeric($v)) return null;
    return round((float)$v, 3);
};

$score   = $clampPct($snap['score']  ?? 0);
$status  = (string)($snap['status']  ?? 'small');
$validStatus = ['parity', 'small', 'meaningful', 'large'];
if (!in_array($status, $validStatus, true)) $status = 'small';
$statusLabel = clean_string((string)($snap['status_label'] ?? ''), 64);

$nTotal = max(0, (int)($snap['n_total'] ?? 0));
$kMin   = max(0, (int)($snap['k_min']   ?? 0));

$hidden = is_array($snap['hidden_groups'] ?? null) ? $snap['hidden_groups'] : [];
$hiddenGroups = [
    'axisCount'  => max(0, (int)($hidden['axisCount']  ?? 0)),
    'groupCount' => max(0, (int)($hidden['groupCount'] ?? 0)),
];

$axesIn = is_array($snap['axes'] ?? null) ? $snap['axes'] : [];
$axes = [];
foreach ($axesIn as $ax) {
    if (!is_array($ax)) continue;
    $label = clean_string((string)($ax['axis_label'] ?? ''), 100);
    if ($label === '') continue;
    $verdict = (string)($ax['verdict'] ?? 'small');
    if (!in_array($verdict, ['parity', 'small', 'meaningful', 'large'], true)) $verdict = 'small';
    $groupsIn = is_array($ax['groups'] ?? null) ? $ax['groups'] : [];
    $groups = [];
    foreach ($groupsIn as $g) {
        if (!is_array($g)) continue;
        $glabel = clean_string((string)($g['label'] ?? ''), 60);
        if ($glabel === '') continue;
        $groups[] = [
            'label' => $glabel,
            'n'     => max(0, (int)($g['n'] ?? 0)),
            'mean'  => $numOrNull($g['mean'] ?? null),
        ];
        if (count($groups) >= 8) break;
    }
    $topPair = null;
    if (is_array($ax['top_pair'] ?? null)) {
        $tp = $ax['top_pair'];
        $aLabel = clean_string((string)($tp['a_label'] ?? ''), 60);
        $bLabel = clean_string((string)($tp['b_label'] ?? ''), 60);
        if ($aLabel !== '' && $bLabel !== '') {
            $topPair = [
                'a_label' => $aLabel,
                'b_label' => $bLabel,
                'd'       => $numOrNull($tp['d'] ?? null),
            ];
        }
    }
    $axes[] = [
        'axis_label'    => $label,
        'verdict'       => $verdict,
        'verdict_label' => clean_string((string)($ax['verdict_label'] ?? ''), 32),
        'max_abs_d'     => $numOrNull($ax['max_abs_d'] ?? null),
        'anova_p'       => $numOrNull($ax['anova_p']   ?? null),
        'eta_sq'        => $numOrNull($ax['eta_sq']    ?? null),
        'groups'        => $groups,
        'top_pair'      => $topPair,
    ];
    if (count($axes) >= 6) break;
}

if (!count($axes)) fail('insufficient_axes', 'Equity gap analysis needs at least one axis with comparable groups.');

$lines = [];
$lines[] = "Equity Gap snapshot:";
$lines[] = "  - Equity Gap Score (0-100, 100 = parity): " . $score;
$lines[] = "  - Status: " . $status . " (" . ($statusLabel ?: 'no label') . ")";
$lines[] = "  - Total respondents: " . $nTotal . ", k-anonymity floor: " . $kMin;
if ($hiddenGroups['groupCount'] > 0) {
    $lines[] = "  - " . $hiddenGroups['groupCount'] . " subgroup(s) hidden across " . $hiddenGroups['axisCount'] . " dropped axis(es) for k-anonymity.";
}
$lines[] = "";
$lines[] = "Per-axis findings (sorted by largest gap):";
foreach ($axes as $ax) {
    $lines[] = "  - Axis: \"" . $ax['axis_label'] . "\"";
    $lines[] = "      Verdict: " . $ax['verdict'] . ", max |Cohen's d|: " . ($ax['max_abs_d'] ?? 'n/a');
    $apTxt = $ax['anova_p'] === null ? 'n/a' : ($ax['anova_p'] < 0.001 ? '<.001' : (string)$ax['anova_p']);
    $lines[] = "      ANOVA p: " . $apTxt . ", eta-squared: " . ($ax['eta_sq'] ?? 'n/a');
    foreach ($ax['groups'] as $g) {
        $lines[] = "      Group \"" . $g['label'] . "\": n=" . $g['n'] . ", mean=" . ($g['mean'] ?? 'n/a');
    }
    if ($ax['top_pair']) {
        $lines[] = "      Top pair: \"" . $ax['top_pair']['a_label'] . "\" vs \"" . $ax['top_pair']['b_label'] . "\", d=" . ($ax['top_pair']['d'] ?? 'n/a');
    }
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card pinned to the top of the Equity Gap analysis tab. The audience is an HR partner, evaluator, or DEI leader looking for outcome differences across protected-class or program groups (gender, race or ethnicity, age band, role, tenure, etc.). The user is not a statistician.

The Equity Gap Score is 0-100 where 100 means near parity across every grouping axis. Each axis penalizes the score in proportion to its largest pairwise Cohen's d. Lower scores mean larger across-group differences.

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Score 85+, no axis with |d| >= 0.50. Near parity across every grouping axis.
  - "ok"   : Score 70-84. Small gaps detected but nothing clinically meaningful.
  - "warn" : Score 50-69 OR at least one axis with |d| 0.50 to 0.79. Meaningful gaps to investigate.
  - "bad"  : Score below 50 OR at least one axis with |d| >= 0.80. Large gaps; act now.

Voice:
  - Lead with the practical answer ("Outcomes look near parity across every group axis", "There is one meaningful gap worth investigating", "Two large gaps need attention before this rolls into action").
  - When a gap stands out, name the axis by its actual label AND the two specific groups in the top pair (e.g., "Engagement on this scale is 0.71 standard deviations lower for Hispanic/Latino respondents than for White respondents on the race or ethnicity axis").
  - Translate Cohen's d for HR readers: |d| < 0.20 = trivial, 0.20-0.49 = small, 0.50-0.79 = meaningful, 0.80+ = large. Use those plain-language terms in the prose.
  - 3 to 5 sentences. Plain prose. Avoid jargon ("omnibus", "significance threshold", "null hypothesis"); use "the difference is unlikely to be sampling noise" or "could be sampling noise". Do not name p-values inline; the verdict already encodes them.
  - Never accuse the survey owner of bias. Frame findings as patterns to understand and act on.
  - When subgroups were hidden for k-anonymity, mention the hidden count once so the reader knows a smaller-group story may be missing from this analysis.

Highlights (0 to 3): short items naming specific findings.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - One should call out the largest gap by axis name + top pair + d.
  - One can name the axis nearest to parity (positive signal).
  - One can call out the response count and k-anonymity floor when meaningful for the read.

Headline:
  - One sentence summarizing where the gaps are AND the single most important next step.

Affected items: empty array for now.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Near parity'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 3-5 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": []
}
SYS;

$userPrompt = "Equity Gap snapshot:\n\n" . $snapshotBlock . "\n\nProduce the equity gap narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1100);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Near parity',
    'ok'   => 'Small gaps',
    'warn' => 'Meaningful gaps',
    'bad'  => 'Large gaps',
];
$toneLabel = clean_string((string)($parsed['tone_label'] ?? $defaultLabels[$tone]), 48);
if ($toneLabel === '') $toneLabel = $defaultLabels[$tone];

$headline  = clean_string((string)($parsed['headline']  ?? ''), 240);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 1100);

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
    'ok'             => true,
    'tone'           => $tone,
    'tone_label'     => $toneLabel,
    'headline'       => $headline,
    'paragraph'      => $paragraph,
    'highlights'     => $highlights,
    'affected_items' => [],
    'model'          => ai_config()['model'],
]);
