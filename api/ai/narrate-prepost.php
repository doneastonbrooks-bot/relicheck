<?php
// POST /api/ai/narrate-prepost.php
// Body: {
//   "snapshot": {
//     "test":         "paired" | "wilcoxon",
//     "test_label":   <string>,
//     "outcome_label": <string>,
//     "pre_value":    <string>,
//     "post_value":   <string>,
//     "n_pairs":      <int>,
//     "mean_pre":     <float>,
//     "mean_post":    <float>,
//     "mean_change":  <float>,
//     "statistic":    <float|null>,
//     "p_value":      <float|null>,
//     "cohens_dz":    <float|null>,
//     "effect_tier":  "small" | "medium" | "large" | "unknown",
//     "significant":  <bool>,
//     "rci": {
//       "reliability":      <float>,
//       "sd_pre":           <float>,
//       "sem":              <float>,
//       "se_diff":          <float>,
//       "z_critical":       <float>,
//       "confidence_level": <float, 0.90 / 0.95 / 0.99>,
//       "tier_counts":      { "improved": <int>, "no_change": <int>, "declined": <int> }
//     } | null
//   }
// }
//
// Dashboard Narrator for the Pre/Post tab (Phase 62). Translates the
// paired test, effect size, and Reliable Change Index breakdown into
// plain-language paragraph + headline + highlights.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_prepost:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$validTests = ['paired', 'wilcoxon'];
$test = (string)($snap['test'] ?? '');
if (!in_array($test, $validTests, true)) fail('bad_input', 'Unknown test type.');

$testLabel    = clean_string((string)($snap['test_label']    ?? ''), 60);
$outcomeLabel = clean_string((string)($snap['outcome_label'] ?? ''), 100);
$preValue     = clean_string((string)($snap['pre_value']     ?? ''), 40);
$postValue    = clean_string((string)($snap['post_value']    ?? ''), 40);

$normFloat = function ($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, 3);
};

$nPairs     = max(0, (int)($snap['n_pairs'] ?? 0));
$meanPre    = $normFloat($snap['mean_pre']    ?? null, -1e6, 1e6);
$meanPost   = $normFloat($snap['mean_post']   ?? null, -1e6, 1e6);
$meanChange = $normFloat($snap['mean_change'] ?? null, -1e6, 1e6);
$statistic  = $normFloat($snap['statistic']   ?? null, -1e6, 1e6);
$pValue     = $normFloat($snap['p_value']     ?? null, 0.0, 1.0);
$cohensDz   = $normFloat($snap['cohens_dz']   ?? null, -10.0, 10.0);

$validTiers = ['small', 'medium', 'large', 'unknown'];
$effectTier = (string)($snap['effect_tier'] ?? 'unknown');
if (!in_array($effectTier, $validTiers, true)) $effectTier = 'unknown';
$significant = !empty($snap['significant']);

$rci = null;
if (is_array($snap['rci'] ?? null)) {
    $r = $snap['rci'];
    $tc = is_array($r['tier_counts'] ?? null) ? $r['tier_counts'] : [];
    $rci = [
        'reliability'      => $normFloat($r['reliability']      ?? null, 0.0, 1.0),
        'sd_pre'           => $normFloat($r['sd_pre']           ?? null, 0.0, 1e6),
        'sem'              => $normFloat($r['sem']              ?? null, 0.0, 1e6),
        'se_diff'          => $normFloat($r['se_diff']          ?? null, 0.0, 1e6),
        'z_critical'       => $normFloat($r['z_critical']       ?? null, 0.0, 10.0),
        'confidence_level' => $normFloat($r['confidence_level'] ?? null, 0.0, 1.0),
        'improved'         => max(0, (int)($tc['improved']  ?? 0)),
        'no_change'        => max(0, (int)($tc['no_change'] ?? 0)),
        'declined'         => max(0, (int)($tc['declined']  ?? 0)),
    ];
}

if ($nPairs < 4) fail('insufficient_data', 'Need at least 4 matched pairs to narrate.');

$lines = [];
$lines[] = "Pre/Post setup:";
$lines[] = "  - Test: " . $testLabel . " (" . $test . ")";
$lines[] = "  - Outcome: " . $outcomeLabel;
$lines[] = "  - Wave values: pre = " . $preValue . " / post = " . $postValue;
$lines[] = "  - Matched pairs: " . $nPairs;
$lines[] = "";
$lines[] = "Group-level change:";
$lines[] = "  - Mean pre: "    . ($meanPre    === null ? 'n/a' : (string)$meanPre);
$lines[] = "  - Mean post: "   . ($meanPost   === null ? 'n/a' : (string)$meanPost);
$lines[] = "  - Mean change: " . ($meanChange === null ? 'n/a' : (string)$meanChange);
$lines[] = "";
$lines[] = "Test result:";
$lines[] = "  - Test statistic: " . ($statistic === null ? 'n/a' : (string)$statistic);
$lines[] = "  - p-value: "       . ($pValue    === null ? 'n/a' : (string)$pValue);
$lines[] = "  - Statistically significant (p < 0.05): " . ($significant ? 'yes' : 'no');
$lines[] = "";
$lines[] = "Effect size:";
if ($cohensDz !== null) $lines[] = "  - Cohen's d_z: " . $cohensDz;
$lines[] = "  - Effect tier: " . $effectTier;
$lines[] = "";

if ($rci !== null) {
    $tot = $rci['improved'] + $rci['no_change'] + $rci['declined'];
    $pct = function ($c) use ($tot) { return $tot > 0 ? round(($c / $tot) * 100) : 0; };
    $lines[] = "Reliable Change Index (Jacobson-Truax, " . round(($rci['confidence_level'] ?? 0.95) * 100) . "% confidence):";
    $lines[] = "  - Scale reliability used: " . ($rci['reliability'] === null ? 'n/a' : (string)$rci['reliability']);
    $lines[] = "  - Standard error of difference: " . ($rci['se_diff'] === null ? 'n/a' : (string)$rci['se_diff']);
    $lines[] = "  - Reliably Improved: " . $rci['improved'] . " (" . $pct($rci['improved']) . "%)";
    $lines[] = "  - No Reliable Change: " . $rci['no_change'] . " (" . $pct($rci['no_change']) . "%)";
    $lines[] = "  - Reliably Declined: " . $rci['declined'] . " (" . $pct($rci['declined']) . "%)";
} else {
    $lines[] = "Reliable Change Index: not computable (need a valid scale reliability between 0 and 1).";
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card for the Pre/Post Analysis tab of a survey app. The user is not a statistician. You explain TWO things: whether the group changed on average, and whether individual respondents changed reliably enough to count.

Tone tiers for the visual pill (combine the group-level test and the RCI breakdown):
  - "good" : Significant group change (p < 0.05) AND medium or large effect size AND the Reliably Improved tier outnumbers the Reliably Declined tier by 2:1 or more. Strong, broadly positive change.
  - "ok"   : Significant group change AND small or medium effect size AND a meaningful share (>= 25%) of respondents Reliably Improved. Real but mixed change.
  - "warn" : Non-significant group change OR mixed RCI breakdown (improved similar to declined). Some individuals changed but the picture is unclear.
  - "bad"  : Non-significant group change AND most respondents in "No Reliable Change" OR more Reliably Declined than Reliably Improved. No useful improvement; possibly deterioration.

Voice:
  - Lead with the group-level answer ("Respondents improved on average" / "Respondents got worse on average" / "No detectable change at the group level").
  - Then translate the RCI breakdown in plain language: how many improved reliably, how many stayed the same, how many got worse. Use percentages where helpful.
  - Avoid jargon: do not say "Cohen's d_z", "paired t-test", "Jacobson-Truax", "standard error of difference", "p-value". You may say "the difference is reliable" or "this change exceeds what we'd expect from measurement error alone".
  - 2-4 sentences. Plain prose.
  - When the group test is non-significant but the RCI shows a meaningful improved subset, lead with that subset ("X% of respondents showed real improvement even though the group average barely moved").

Highlights (0-3): short items surfacing specific points.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - For a clear improvement: highlight the improved-percentage. For a mixed result: highlight the declined-percentage too. For null: highlight the no-change majority.

Headline:
  - One sentence summarizing whether respondents changed.

Affected items (Phase 104):
  - When the paragraph or any highlight calls out a specific RCI tier (Reliably Improved, No Reliable Change, Reliably Declined), also list it in an affected_items array.
  - Each entry has shape { "type": "tier", "id": "improved" | "no_change" | "declined" }.
  - The id must be one of the three RCI tier keys that appear in the snapshot.
  - Empty array is fine when the narration only talks about the group-level test.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Broad improvement'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "tier", "id": "improved" | "no_change" | "declined" }
  ]
}
SYS;

$userPrompt = "Pre/Post snapshot:\n\n" . $snapshotBlock . "\n\nProduce the pre/post narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = ['good' => 'Broad improvement', 'ok' => 'Mixed improvement', 'warn' => 'Unclear change', 'bad' => 'No improvement'];
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

// Phase 104: normalize affected_items. Whitelist type='tier' and validate
// the id is one of the three RCI tier keys.
$affectedItems = [];
$validTierIds = ['improved' => true, 'no_change' => true, 'declined' => true];
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'tier') continue;
        if (!isset($validTierIds[$id])) continue;
        $affectedItems[] = ['type' => $type, 'id' => $id];
        if (count($affectedItems) >= 3) break;
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
