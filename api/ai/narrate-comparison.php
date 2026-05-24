<?php
// POST /api/ai/narrate-comparison.php
// Body: {
//   "snapshot": {
//     "test":        "welch" | "mwu" | "anova" | "kw",
//     "test_label":  <string>,
//     "outcome_label": <string>,
//     "group_var":   <string>,
//     "groups":      [ { "label": <string>, "n": <int>, "mean": <float>, "sd": <float> } ],
//     "statistic":   <float|null>,
//     "p_value":     <float|null>,
//     "cohens_d":    <float|null>,
//     "cohens_d_ci_low":  <float|null>,
//     "cohens_d_ci_high": <float|null>,
//     "eta_sq":      <float|null>,
//     "effect_tier": "small" | "medium" | "large" | "unknown",
//     "significant": <bool>
//   }
// }
//
// Dashboard Narrator for the Compare tab (Phase 61). Same I/O shape as
// the other narrate-*.php endpoints (tone / tone_label / headline /
// paragraph / highlights). Reads test results, group means, and effect
// size; translates them into a plain-language paragraph about whether
// and how the groups differ.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_comparison:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$validTests = ['welch', 'mwu', 'anova', 'kw'];
$test = (string)($snap['test'] ?? '');
if (!in_array($test, $validTests, true)) fail('bad_input', 'Unknown test type.');

$testLabel    = clean_string((string)($snap['test_label']    ?? ''), 60);
$outcomeLabel = clean_string((string)($snap['outcome_label'] ?? ''), 100);
$groupVar     = clean_string((string)($snap['group_var']     ?? ''), 100);

$groupsIn = is_array($snap['groups'] ?? null) ? $snap['groups'] : [];
$groups = [];
foreach ($groupsIn as $g) {
    if (!is_array($g)) continue;
    $label = clean_string((string)($g['label'] ?? ''), 60);
    if ($label === '') continue;
    $groups[] = [
        'label' => $label,
        'n'     => max(0, (int)($g['n'] ?? 0)),
        'mean'  => is_numeric($g['mean'] ?? null) ? round((float)$g['mean'], 2) : null,
        'sd'    => is_numeric($g['sd']   ?? null) ? round((float)$g['sd'],   2) : null,
    ];
    if (count($groups) >= 8) break;
}
if (count($groups) < 2) fail('insufficient_data', 'Need at least 2 groups to narrate.');

$normFloat = function ($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, 3);
};

$statistic    = $normFloat($snap['statistic']        ?? null, -1e6, 1e6);
$pValue       = $normFloat($snap['p_value']          ?? null, 0.0, 1.0);
$cohensD      = $normFloat($snap['cohens_d']         ?? null, -10.0, 10.0);
$cohensDLow   = $normFloat($snap['cohens_d_ci_low']  ?? null, -10.0, 10.0);
$cohensDHigh  = $normFloat($snap['cohens_d_ci_high'] ?? null, -10.0, 10.0);
$etaSq        = $normFloat($snap['eta_sq']           ?? null, 0.0, 1.0);

$validTiers   = ['small', 'medium', 'large', 'unknown'];
$effectTier   = (string)($snap['effect_tier'] ?? 'unknown');
if (!in_array($effectTier, $validTiers, true)) $effectTier = 'unknown';

$significant  = !empty($snap['significant']);

// Build snapshot block.
$groupLines = [];
foreach ($groups as $g) {
    $groupLines[] = sprintf(
        '  - "%s" (n=%d): mean=%s, sd=%s',
        $g['label'], $g['n'],
        $g['mean'] === null ? 'n/a' : (string)$g['mean'],
        $g['sd']   === null ? 'n/a' : (string)$g['sd']
    );
}

$lines = [];
$lines[] = "Comparison setup:";
$lines[] = "  - Test: " . $testLabel . " (" . $test . ")";
$lines[] = "  - Group variable: " . $groupVar;
$lines[] = "  - Outcome: " . $outcomeLabel;
$lines[] = "";
$lines[] = "Per-group:";
$lines[] = implode("\n", $groupLines);
$lines[] = "";
$lines[] = "Test result:";
$lines[] = "  - Test statistic: " . ($statistic === null ? 'n/a' : (string)$statistic);
$lines[] = "  - p-value: "       . ($pValue === null ? 'n/a' : (string)$pValue);
$lines[] = "  - Statistically significant (p < 0.05): " . ($significant ? 'yes' : 'no');
$lines[] = "";
$lines[] = "Effect size:";
if ($cohensD !== null) {
    $lines[] = "  - Cohen's d: " . $cohensD;
    if ($cohensDLow !== null && $cohensDHigh !== null) {
        $lines[] = "  - 95% CI for d: [" . $cohensDLow . ", " . $cohensDHigh . "]";
    }
}
if ($etaSq !== null) {
    $lines[] = "  - eta-squared: " . $etaSq;
}
$lines[] = "  - Effect tier (from absolute value of d or eta-squared): " . $effectTier;

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card for the Compare tab of a survey app. The user is not a statistician. You explain whether and how groups differ on the chosen outcome, in plain language.

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Clear, sizeable difference. p < 0.05 AND effect tier is medium or large. The user can act on this.
  - "ok"   : Detectable difference. p < 0.05 AND effect tier is small. Real but modest.
  - "warn" : Inconclusive. p >= 0.05 but effect tier is medium or large (sample may be too small to detect a real difference).
  - "bad"  : No detectable difference. p >= 0.05 AND effect tier is small or unknown.

Voice:
  - Lead with the practical answer ("Groups differ meaningfully" / "Groups look similar" / "We can't tell from this sample").
  - Reference the largest and smallest group means by their actual labels.
  - Translate the effect size into plain language. Avoid the words "Cohen's d", "eta-squared", or "statistical significance". Say "the difference is meaningful and would be visible in your population" for medium/large, "the difference is small" for small, "we can't tell from this sample" for non-significant with small effects.
  - 2-4 sentences. Plain prose.

Per-test guidance:
  - For Welch's t-test or Mann-Whitney U with two groups: name both groups, quote their means, say which is higher.
  - For one-way ANOVA or Kruskal-Wallis with 3+ groups: name the highest and lowest group; say "the spread between groups" if effect is meaningful.
  - When p < 0.05 but the effect tier is "small": note that the difference is statistically detectable but practically modest.
  - When p >= 0.05 but the effect tier is "medium" or "large": note that the pattern suggests a real difference but the sample is too small to confirm.

Highlights (0-3): short items surfacing specific points.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - For two-group: a highlight quoting the mean difference. For multi-group: a highlight naming the high-low spread.

Headline:
  - One sentence summarizing whether groups differ.

Affected items (Phase 104):
  - When the paragraph or any highlight names specific groups, also list them in an affected_items array.
  - Each entry has shape { "type": "group", "id": "<exact group label as shown in the snapshot>" }.
  - The id must match a group label that appears in the per-group snapshot block.
  - Empty array is fine when the narration does not call out specific groups (e.g. talks about overall test results only).

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Clear difference'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "group", "id": "<group label called out above>" }
  ]
}
SYS;

$userPrompt = "Comparison snapshot:\n\n" . $snapshotBlock . "\n\nProduce the comparison narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = ['good' => 'Clear difference', 'ok' => 'Detectable difference', 'warn' => 'Inconclusive', 'bad' => 'No difference detected'];
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

// Phase 104: normalize affected_items. Whitelist type='group' and validate
// the id is one of the group labels we sent in. Drop anything else.
$affectedItems = [];
$validLabels = [];
foreach ($groups as $g) { $validLabels[$g['label']] = true; }
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 60);
        if ($type !== 'group') continue;
        if (!isset($validLabels[$id])) continue;
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
