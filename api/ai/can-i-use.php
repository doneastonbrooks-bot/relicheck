<?php
// POST /api/ai/can-i-use.php
// Body: {
//   "snapshot": {
//     "ssi": {
//       "total":         <int 0-100>,
//       "status":        "excellent"|"strong"|"usable"|"needs_strengthening"|"weak",
//       "status_label":  string,
//       "domains": {
//         "reliability":   { "score": <int>, "max": 25 },
//         "factor":        { "score": <int>, "max": 20 },
//         "item":          { "score": <int>, "max": 20 },
//         "response":      { "score": <int>, "max": 15 },
//         "openEnded":     { "score": <int>, "max": 10 },
//         "actionability": { "score": <int>, "max": 10 }
//       }
//     },
//     "reliability": {
//       "alpha":      <float|null>,
//       "split_half": <float|null>,
//       "k_items":    <int>
//     },
//     "responses": {
//       "total":           <int>,
//       "complete":        <int>,
//       "completion_pct":  <int 0-100>,
//       "partial":         <int>
//     }
//   }
// }
//
// "Can I Use These Results?" advisor (Phase 51). Stateless: the client sends
// the snapshot the Strength Index tab already has on hand. The model returns
// a structured verdict the UI renders as a card directly under the SSI ring.
//
// Output: {
//   ok, verdict, verdict_label, headline,
//   safe_for [], caution_for [], not_recommended_for [],
//   cautions [{ label, detail }],
//   model
// }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 20 verdicts per user per hour. The client caches by response_count, so
// most page views won't hit this endpoint. The cap covers manual refresh loops.
check_rate_limit('ai_can_i_use:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) {
    fail('bad_input', 'Missing snapshot payload.');
}

// ---- Normalize the snapshot. The client is trusted to compute these from
// the same SSI engine that renders the page; we still clamp aggressively so
// a malformed payload can't reach the model.

$ssiIn = $snap['ssi'] ?? [];
$relIn = $snap['reliability'] ?? [];
$resIn = $snap['responses'] ?? [];

$validStatuses = ['excellent', 'strong', 'usable', 'needs_strengthening', 'weak'];
$status = (string)($ssiIn['status'] ?? 'usable');
if (!in_array($status, $validStatuses, true)) $status = 'usable';

$ssi = [
    'total'        => max(0, min(100, (int)($ssiIn['total'] ?? 0))),
    'status'       => $status,
    'status_label' => clean_string((string)($ssiIn['status_label'] ?? ucfirst($status)), 48),
    'domains'      => [],
];

$domainKeys = ['reliability', 'factor', 'item', 'response', 'openEnded', 'actionability'];
$domainMax  = ['reliability' => 25, 'factor' => 20, 'item' => 20, 'response' => 15, 'openEnded' => 10, 'actionability' => 10];
foreach ($domainKeys as $k) {
    $d = $ssiIn['domains'][$k] ?? [];
    $max = (int)($d['max'] ?? $domainMax[$k]);
    if ($max <= 0 || $max > $domainMax[$k]) $max = $domainMax[$k];
    $score = (int)($d['score'] ?? 0);
    if ($score < 0)    $score = 0;
    if ($score > $max) $score = $max;
    $ssi['domains'][$k] = ['score' => $score, 'max' => $max];
}

$alphaRaw = $relIn['alpha'] ?? null;
$shRaw    = $relIn['split_half'] ?? null;
$reliability = [
    'alpha'      => (is_numeric($alphaRaw) && $alphaRaw > -1 && $alphaRaw <= 1) ? round((float)$alphaRaw, 3) : null,
    'split_half' => (is_numeric($shRaw)    && $shRaw    > -1 && $shRaw    <= 1) ? round((float)$shRaw,    3) : null,
    'k_items'    => max(0, (int)($relIn['k_items'] ?? 0)),
];

$totalR    = max(0, (int)($resIn['total'] ?? 0));
$completeR = max(0, min($totalR, (int)($resIn['complete'] ?? 0)));
$partialR  = max(0, $totalR - $completeR);
$pct       = max(0, min(100, (int)($resIn['completion_pct'] ?? ($totalR > 0 ? (int)round($completeR / $totalR * 100) : 0))));
$responses = [
    'total'           => $totalR,
    'complete'        => $completeR,
    'partial'         => $partialR,
    'completion_pct'  => $pct,
];

if ($responses['total'] === 0) {
    fail('no_responses', 'No responses yet. Collect responses before requesting a use verdict.');
}

// ---- Build the prompt. We give the model the same numbers the user sees,
// plus the threshold language we already use elsewhere in the app so the
// verdict reads as a consistent voice.

$alphaTxt = $reliability['alpha'] === null ? 'not computable (need >=2 Likert items, >=2 respondents)' : (string)$reliability['alpha'];
$shTxt    = $reliability['split_half'] === null ? 'not computable' : (string)$reliability['split_half'];

$domainLines = [];
foreach ($domainKeys as $k) {
    $d = $ssi['domains'][$k];
    $domainLines[] = sprintf('  - %s: %d/%d', $k, $d['score'], $d['max']);
}
$domainBlock = implode("\n", $domainLines);

$snapshotBlock  = "Survey Strength Index: " . $ssi['total'] . "/100 (" . $ssi['status_label'] . ")\n";
$snapshotBlock .= "Domain breakdown:\n" . $domainBlock . "\n\n";
$snapshotBlock .= "Reliability:\n";
$snapshotBlock .= "  - Cronbach's alpha: " . $alphaTxt . "\n";
$snapshotBlock .= "  - Split-half (Spearman-Brown): " . $shTxt . "\n";
$snapshotBlock .= "  - Likert items in scale: " . $reliability['k_items'] . "\n\n";
$snapshotBlock .= "Responses:\n";
$snapshotBlock .= "  - Total: " . $responses['total'] . "\n";
$snapshotBlock .= "  - Complete: " . $responses['complete'] . "\n";
$snapshotBlock .= "  - Partial: " . $responses['partial'] . "\n";
$snapshotBlock .= "  - Completion rate: " . $responses['completion_pct'] . "%";

$system = <<<SYS
You are a measurement researcher writing a one-screen "Can I use these results?" verdict for a survey owner. The owner is not a statistician. They want a practical answer: what is this dataset credible enough to support, and what should they NOT use it for?

You will receive three numbers that matter most:
  1. The Survey Strength Index (SSI), a 0 to 100 composite the platform already shows the user. Status tiers: excellent (90+), strong (80-89), usable (70-79), needs_strengthening (60-69), weak (<60).
  2. Per-domain SSI scores in six areas (Reliability 25, Factor 20, Item Quality 20, Response Quality 15, Open-Ended 10, Actionability 10).
  3. Reliability stats (Cronbach's alpha, split-half) and response counts (total, complete, partial, completion rate).

How to choose the verdict:
  - "yes" : SSI >= 80 AND alpha >= 0.80 (or null with strong domain scores) AND >= 100 complete responses AND completion >= 70%.
  - "yes_with_cautions" : SSI >= 70 AND alpha >= 0.70 AND >= 50 complete responses. Default tier when results are usable but imperfect.
  - "use_with_care" : SSI 60-69, OR alpha 0.60-0.70, OR 25-49 complete responses, OR completion 50-69%. Findings are suggestive, not conclusive.
  - "not_yet" : SSI < 60, OR alpha < 0.60 (when computable), OR < 25 complete responses, OR completion < 50%. The dataset cannot support credible decisions yet.

Use the lowest-passing tier across the criteria. If a stronger signal is dragged down by a weak one, prefer the weaker tier and explain why in the headline.

Buckets (always three lists, each 2-4 short noun phrases):
  - safe_for: uses this dataset CAN credibly support given the verdict tier.
  - caution_for: uses it CAN support, but only with caveats explicitly stated.
  - not_recommended_for: uses the dataset should NOT support given the tier.

Calibrate the buckets to the tier:
  - "yes": safe_for is broad (internal planning, program improvement, public reporting, leadership briefings). caution_for is narrow (high-stakes individual decisions, strong causal claims). not_recommended_for stays for things this kind of survey never supports (legal proof, individual personnel rulings).
  - "yes_with_cautions": safe_for is internal planning, team discussion, program improvement. caution_for is public claims, group comparisons, executive decisions. not_recommended_for is major personnel decisions, strong causal claims, accreditation evidence.
  - "use_with_care": safe_for shrinks to internal discussion and pilot signal. caution_for is most public uses. not_recommended_for grows.
  - "not_yet": safe_for is minimal (internal note that more data is needed). caution_for is small. not_recommended_for is broad.

Cautions list (2-4 entries, each with a short label and one-sentence detail) must reference the actual numbers in the snapshot. Examples of caution labels: "Sample size", "Reliability", "Completion rate", "Item Quality domain", "Reliability domain", "Response Quality domain". Do not list a caution that is not supported by the numbers.

Tone: confident, plain-language, non-technical. Do not lecture. Do not say "you should". Use direct phrasing like "These results can support..." and "Avoid using these results to...".

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "verdict": "yes" | "yes_with_cautions" | "use_with_care" | "not_yet",
  "verdict_label": "<short phrase shown in the pill, e.g. 'Yes, with minor cautions'>",
  "headline": "<one or two plain-language sentences summarizing the verdict>",
  "safe_for": ["<phrase>", "<phrase>", ...],
  "caution_for": ["<phrase>", "<phrase>", ...],
  "not_recommended_for": ["<phrase>", "<phrase>", ...],
  "cautions": [
    { "label": "<short label>", "detail": "<one-sentence explanation citing the number>" }
  ]
}
SYS;

$userPrompt  = "Survey snapshot:\n\n" . $snapshotBlock . "\n\n";
$userPrompt .= "Produce the verdict JSON now.";

// ---- Phase 178t: rule-based verdict ALWAYS computes first. This is the
// hybrid pattern from Phase 2 applied here. The verdict tier and the
// buckets come from a deterministic rule using the snapshot numbers. AI
// polishes the headline and refines the bucket wording IF available.
// When AI is overloaded / errors / returns garbage, we return the rule-
// based verdict with ai_polished=false. Card never breaks.

$ruleVerdict = can_i_use_rule_based($ssi, $reliability, $responses);

// Try AI polish. Wrap in try/catch so any AI failure falls through to the
// rule-based response. We do NOT call fail() on AI errors anymore.
$parsed = null;
$aiPolished = false;
$aiError = null;
try {
    $resp = ai_complete($system, [
        ['role' => 'user', 'content' => $userPrompt],
    ], 900);
    $parsed = ai_extract_json($resp['text']);
} catch (Throwable $e) {
    $aiError = $e->getMessage();
    // Swallow; we fall back below.
}

if (!$parsed || !isset($parsed['verdict'])) {
    // Either AI failed, AI returned unparseable text, or AI was missing the
    // verdict key. Use the rule-based output. The user gets a complete card.
    json_out(array_merge([
        'ok'           => true,
        'ai_polished'  => false,
        'ai_error'     => $aiError,
        'model'        => ai_config()['model'],
    ], $ruleVerdict));
}

// ---- Clean and clamp the model output. The VERDICT TIER comes from the
// rule, not the model -- this guarantees the user always sees a verdict
// consistent with the numbers even when AI is wrong about the tier. The
// model is permitted to refine the headline and bucket wording only.

$validVerdicts = ['yes', 'yes_with_cautions', 'use_with_care', 'not_yet'];
$verdict = $ruleVerdict['verdict']; // ALWAYS the rule's tier.

$defaultLabels = [
    'yes'                => 'Yes',
    'yes_with_cautions'  => 'Yes, with cautions',
    'use_with_care'      => 'Use with care',
    'not_yet'            => 'Not yet',
];
$verdictLabel = clean_string((string)($parsed['verdict_label'] ?? $defaultLabels[$verdict]), 48);
if ($verdictLabel === '') $verdictLabel = $defaultLabels[$verdict];

$headline = clean_string((string)($parsed['headline'] ?? ''), 360);
if ($headline === '') $headline = $ruleVerdict['headline'];
$aiPolished = true;

$cleanList = function ($arr, int $maxItems, int $maxLen): array {
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $v) {
        if (!is_string($v)) continue;
        $c = clean_string($v, $maxLen);
        $c = rtrim($c, ". \t\n\r");
        if ($c === '') continue;
        $out[] = $c;
        if (count($out) >= $maxItems) break;
    }
    return $out;
};

$safeFor    = $cleanList($parsed['safe_for'] ?? [], 5, 80);
$cautionFor = $cleanList($parsed['caution_for'] ?? [], 5, 80);
$notRec     = $cleanList($parsed['not_recommended_for'] ?? [], 5, 80);

$cautions = [];
if (is_array($parsed['cautions'] ?? null)) {
    foreach ($parsed['cautions'] as $c) {
        if (!is_array($c)) continue;
        $label  = clean_string((string)($c['label']  ?? ''), 40);
        $detail = clean_string((string)($c['detail'] ?? ''), 240);
        if ($label === '' || $detail === '') continue;
        $cautions[] = ['label' => $label, 'detail' => $detail];
        if (count($cautions) >= 5) break;
    }
}

// Bucket-list fallbacks: if the AI returned empty buckets for any of the
// three lists, backfill from the rule-based tier templates so the card
// always has something to render.
if (empty($safeFor))    $safeFor    = $ruleVerdict['safe_for'];
if (empty($cautionFor)) $cautionFor = $ruleVerdict['caution_for'];
if (empty($notRec))     $notRec     = $ruleVerdict['not_recommended_for'];
if (empty($cautions))   $cautions   = $ruleVerdict['cautions'];

json_out([
    'ok'                  => true,
    'ai_polished'         => $aiPolished,
    'verdict'             => $verdict,
    'verdict_label'       => $verdictLabel,
    'headline'            => $headline,
    'safe_for'            => $safeFor,
    'caution_for'         => $cautionFor,
    'not_recommended_for' => $notRec,
    'cautions'            => $cautions,
    'model'               => ai_config()['model'],
]);


// ============================================================
// Rule-based verdict
// ============================================================

/**
 * Deterministic verdict tier from the snapshot numbers. Mirrors the tier
 * rules in the system prompt so the rule output and the AI output never
 * disagree on tier. Returns verdict, verdict_label, headline, the three
 * bucket lists, and the cautions list -- everything the UI needs.
 */
function can_i_use_rule_based(array $ssi, array $reliability, array $responses): array
{
    $total      = (int)$responses['total'];
    $complete   = (int)$responses['complete'];
    $pct        = (int)$responses['completion_pct'];
    $alpha      = $reliability['alpha']; // may be null
    $kItems     = (int)$reliability['k_items'];
    $ssiTotal   = (int)$ssi['total'];

    // Tier logic. We take the weakest tier across the criteria.
    $tier = 'yes';
    $reasons = [];

    $alphaKnown = is_numeric($alpha);

    // SSI tier from total.
    if      ($ssiTotal < 60)  { $tier = 'not_yet';        $reasons[] = ['SSI', 'Survey Strength Index is ' . $ssiTotal . '/100, below the credibility floor of 60.']; }
    elseif  ($ssiTotal < 70)  { $tier = worstTier($tier, 'use_with_care'); $reasons[] = ['SSI', 'Survey Strength Index is ' . $ssiTotal . '/100, in the use-with-care range.']; }
    elseif  ($ssiTotal < 80)  { $tier = worstTier($tier, 'yes_with_cautions'); $reasons[] = ['SSI', 'Survey Strength Index is ' . $ssiTotal . '/100, usable but imperfect.']; }

    // Reliability tier from alpha.
    if ($alphaKnown) {
        $a = (float)$alpha;
        if      ($a < 0.60) { $tier = worstTier($tier, 'not_yet');           $reasons[] = ['Reliability', sprintf("Cronbach's alpha is %.2f, below the 0.60 minimum.", $a)]; }
        elseif  ($a < 0.70) { $tier = worstTier($tier, 'use_with_care');     $reasons[] = ['Reliability', sprintf("Cronbach's alpha is %.2f, in the use-with-care range (0.60-0.70).", $a)]; }
        elseif  ($a < 0.80) { $tier = worstTier($tier, 'yes_with_cautions'); $reasons[] = ['Reliability', sprintf("Cronbach's alpha is %.2f, acceptable but below the 0.80 strong threshold.", $a)]; }
    } elseif ($kItems >= 2) {
        // Alpha couldn't compute even though there were items. Worth flagging.
        $reasons[] = ['Reliability', 'Reliability could not be computed; check for missing data or near-zero variance on items.'];
    }

    // Sample size tier.
    if      ($complete < 25)  { $tier = worstTier($tier, 'not_yet');           $reasons[] = ['Sample size', 'Only ' . $complete . ' complete responses (below the 25 minimum for defensible claims).']; }
    elseif  ($complete < 50)  { $tier = worstTier($tier, 'use_with_care');     $reasons[] = ['Sample size', 'Only ' . $complete . ' complete responses (in the use-with-care 25-49 band).']; }
    elseif  ($complete < 100) { $tier = worstTier($tier, 'yes_with_cautions'); $reasons[] = ['Sample size', $complete . ' complete responses (usable; under 100 keeps confidence intervals wide).']; }

    // Completion rate tier.
    if      ($pct < 50)  { $tier = worstTier($tier, 'not_yet');           $reasons[] = ['Completion rate', 'Completion rate is ' . $pct . '% (below the 50% floor; severe non-response bias risk).']; }
    elseif  ($pct < 70)  { $tier = worstTier($tier, 'use_with_care');     $reasons[] = ['Completion rate', 'Completion rate is ' . $pct . '% (use-with-care band; non-response bias possible).']; }

    // Tier templates for buckets and headline.
    $tierLabels = [
        'yes'                => 'Yes',
        'yes_with_cautions'  => 'Yes, with cautions',
        'use_with_care'      => 'Use with care',
        'not_yet'            => 'Not yet',
    ];
    $tierHeadlines = [
        'yes'                => 'These results are reliable and complete enough to support credible decisions across most uses.',
        'yes_with_cautions'  => 'These results are usable for most internal decisions, with a few cautions documented below.',
        'use_with_care'      => 'These results carry enough signal for internal exploration, but their credibility is limited; treat findings as suggestive.',
        'not_yet'            => 'These results do not yet meet the credibility thresholds for defensible decisions. Strengthen the dataset before drawing conclusions.',
    ];
    $tierBuckets = [
        'yes' => [
            'safe_for'    => ['Internal planning and program improvement', 'Leadership briefings and executive decisions', 'Public reporting and stakeholder communication', 'Team discussion and strategy refinement'],
            'caution_for' => ['High-stakes individual or personnel decisions', 'Strong causal claims without further evidence'],
            'not_recommended_for' => ['Legal proof or compliance evidence', 'Individual personnel rulings'],
        ],
        'yes_with_cautions' => [
            'safe_for'    => ['Internal planning and team discussion', 'Program improvement decisions', 'Pilot signal and hypothesis generation'],
            'caution_for' => ['Public claims and external reporting', 'Group comparisons across small subgroups', 'Executive decisions with significant resource impact'],
            'not_recommended_for' => ['High-stakes personnel decisions', 'Strong causal claims', 'Accreditation or compliance evidence'],
        ],
        'use_with_care' => [
            'safe_for'    => ['Internal discussion and pilot signal', 'Preliminary direction-setting (with explicit caveats)'],
            'caution_for' => ['Any public-facing report or claim', 'Group comparisons of any kind', 'Resource-allocation decisions'],
            'not_recommended_for' => ['Personnel or hiring decisions', 'Accreditation evidence', 'Public benchmarking', 'Causal claims of any kind'],
        ],
        'not_yet' => [
            'safe_for'    => ['Internal note that more data is needed before any claim can be defended'],
            'caution_for' => ['Even internal hypothesis generation should be revisited once the dataset strengthens'],
            'not_recommended_for' => ['Any public or external use', 'Any decision with personnel or resource implications', 'Any group comparison', 'Any causal claim'],
        ],
    ];

    // Cautions list: one entry per failed criterion, capped at 5.
    $cautions = [];
    foreach ($reasons as $r) {
        $cautions[] = ['label' => $r[0], 'detail' => $r[1]];
        if (count($cautions) >= 5) break;
    }

    return [
        'verdict'             => $tier,
        'verdict_label'       => $tierLabels[$tier],
        'headline'            => $tierHeadlines[$tier],
        'safe_for'            => $tierBuckets[$tier]['safe_for'],
        'caution_for'         => $tierBuckets[$tier]['caution_for'],
        'not_recommended_for' => $tierBuckets[$tier]['not_recommended_for'],
        'cautions'            => $cautions,
    ];
}

/**
 * Returns whichever of two tiers is more cautious. Tier order from
 * strongest to weakest: yes > yes_with_cautions > use_with_care > not_yet.
 */
function worstTier(string $a, string $b): string
{
    static $rank = ['yes' => 4, 'yes_with_cautions' => 3, 'use_with_care' => 2, 'not_yet' => 1];
    $ra = $rank[$a] ?? 3;
    $rb = $rank[$b] ?? 3;
    return $ra <= $rb ? $a : $b;
}
