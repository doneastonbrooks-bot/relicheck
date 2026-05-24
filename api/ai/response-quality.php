<?php
// POST /api/ai/response-quality.php
// Body: {
//   "snapshot": {
//     "totals": {
//       "total_responses":       <int>,
//       "complete_responses":    <int>,
//       "likert_item_count":     <int>,
//       "open_ended_item_count": <int>
//     },
//     "checks": {
//       "straight_lining": {
//         "eligible": <int>, "count": <int>, "pct": <int 0-100>
//       },
//       "duplicate_vectors": {
//         "eligible": <int>, "response_count": <int>,
//         "cluster_count": <int>, "largest_cluster_size": <int>, "pct": <int>
//       },
//       "short_open_ended": {
//         "total_answers": <int>, "short_count": <int>,
//         "pct": <int>, "threshold_chars": <int>
//       }
//     }
//   }
// }
//
// Response Quality Checker (Phase 52). The client computes the three flag
// counts deterministically from its in-memory responses and asks the model
// to translate the numbers into a severity tier, a plain-language headline,
// per-check findings, and a recommendation. No respondent data is sent;
// only aggregate counts.
//
// Output: {
//   ok, severity, severity_label, headline,
//   findings [{ check, severity, title, detail, count }],
//   recommendation,
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

// Same envelope as the verdict advisor. The client caches by response count
// so most page views skip the endpoint entirely.
check_rate_limit('ai_response_quality:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$totalsIn = $snap['totals'] ?? [];
$checksIn = $snap['checks'] ?? [];

$totals = [
    'total_responses'       => max(0, (int)($totalsIn['total_responses']       ?? 0)),
    'complete_responses'    => max(0, (int)($totalsIn['complete_responses']    ?? 0)),
    'likert_item_count'     => max(0, (int)($totalsIn['likert_item_count']     ?? 0)),
    'open_ended_item_count' => max(0, (int)($totalsIn['open_ended_item_count'] ?? 0)),
];

if ($totals['total_responses'] === 0) {
    fail('no_responses', 'No responses yet. Collect responses before requesting a data-quality check.');
}

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};

$slIn = $checksIn['straight_lining'] ?? [];
$dvIn = $checksIn['duplicate_vectors'] ?? [];
$soIn = $checksIn['short_open_ended'] ?? [];

$sl = [
    'eligible' => max(0, (int)($slIn['eligible'] ?? 0)),
    'count'    => max(0, (int)($slIn['count']    ?? 0)),
    'pct'      => $clampPct($slIn['pct']         ?? 0),
];
$dv = [
    'eligible'             => max(0, (int)($dvIn['eligible']             ?? 0)),
    'response_count'       => max(0, (int)($dvIn['response_count']       ?? 0)),
    'cluster_count'        => max(0, (int)($dvIn['cluster_count']        ?? 0)),
    'largest_cluster_size' => max(0, (int)($dvIn['largest_cluster_size'] ?? 0)),
    'pct'                  => $clampPct($dvIn['pct']                     ?? 0),
];
$so = [
    'total_answers'   => max(0, (int)($soIn['total_answers']   ?? 0)),
    'short_count'     => max(0, (int)($soIn['short_count']     ?? 0)),
    'pct'             => $clampPct($soIn['pct']                ?? 0),
    'threshold_chars' => max(1, (int)($soIn['threshold_chars'] ?? 3)),
];

// Build snapshot block for the prompt.
$snapshotBlock  = "Totals:\n";
$snapshotBlock .= "  - Total responses: "      . $totals['total_responses']       . "\n";
$snapshotBlock .= "  - Complete responses: "   . $totals['complete_responses']    . "\n";
$snapshotBlock .= "  - Likert items: "         . $totals['likert_item_count']     . "\n";
$snapshotBlock .= "  - Open-ended items: "     . $totals['open_ended_item_count'] . "\n\n";

$snapshotBlock .= "Straight-lining (same Likert answer across all items):\n";
if ($sl['eligible'] === 0 || $totals['likert_item_count'] < 3) {
    $snapshotBlock .= "  - Not applicable (need >=3 Likert items and complete answers).\n";
} else {
    $snapshotBlock .= "  - " . $sl['count'] . " of " . $sl['eligible'] . " eligible respondents (" . $sl['pct'] . "%)\n";
}
$snapshotBlock .= "\n";

$snapshotBlock .= "Duplicate response vectors (identical Likert answers across two or more respondents):\n";
if ($dv['eligible'] === 0 || $totals['likert_item_count'] < 3) {
    $snapshotBlock .= "  - Not applicable.\n";
} elseif ($dv['cluster_count'] === 0) {
    $snapshotBlock .= "  - None detected across " . $dv['eligible'] . " complete responses.\n";
} else {
    $snapshotBlock .= "  - " . $dv['response_count'] . " responses across " . $dv['cluster_count']
        . " duplicate clusters; largest cluster size = " . $dv['largest_cluster_size']
        . " (" . $dv['pct'] . "% of complete responses).\n";
}
$snapshotBlock .= "\n";

$snapshotBlock .= "Very short open-ended answers (under " . $so['threshold_chars'] . " characters after trimming):\n";
if ($totals['open_ended_item_count'] === 0 || $so['total_answers'] === 0) {
    $snapshotBlock .= "  - Not applicable (no open-ended items or no open-ended answers).\n";
} else {
    $snapshotBlock .= "  - " . $so['short_count'] . " of " . $so['total_answers'] . " open-ended answers ("
        . $so['pct'] . "%)\n";
}

$system = <<<SYS
You are a measurement researcher producing a "Data quality check" card for a survey owner who is not a statistician. You receive deterministic counts for three credibility checks already computed by the platform and translate them into a tier, a plain-language headline, per-check findings, and one recommendation.

You will receive three flag types:
  1. STRAIGHT-LINING: respondents who picked the same Likert answer across every Likert item. Common when respondents are not engaged or when bots submit. Only flagged when there are at least 3 Likert items and at least one complete response.
  2. DUPLICATE RESPONSE VECTORS: two or more respondents whose Likert answer patterns match exactly. Common with bot activity, copy-paste, or shared accounts. A small number of duplicates with few items is normal; many duplicates is suspicious.
  3. SHORT OPEN-ENDED ANSWERS: written responses under a small character threshold (typically 3 chars). Common signals: "ok", "n/a", "no", or single letters. High rates indicate respondents skipped engagement on qualitative prompts.

Severity tiers (use the worst tier across the three checks):
  - "clean"    : all checks are 0 OR all percentages <= 2%. No meaningful credibility issues.
  - "minor"    : at most one check between 3% and 9%, others near zero. Worth noting but does not change usage decisions.
  - "moderate" : any check between 10% and 24%, OR two checks each between 3% and 9%. Review before treating subgroup splits or marginal effects as final.
  - "severe"   : any check at 25% or above, OR duplicate clusters with >=4 identical respondents, OR straight-lining above 15%. Recommend reviewing the distribution channel and considering whether to exclude flagged responses.

Calibration notes:
  - If a check is "Not applicable", omit it from findings entirely (do not invent a finding).
  - If a check shows 0, still emit a brief positive finding ("No straight-lining detected. Respondents varied their answers across items.").
  - Short open-ended thresholds: 5-9% is common; 10%+ deserves a finding; 25%+ is severe.
  - Duplicate vectors: with very few items (3-5), low-rate duplication can be coincidence. Note this in the detail if relevant.

Findings format:
  - One entry per APPLICABLE check (skip "Not applicable" entries).
  - Each entry has: check (one of "straight_lining" | "duplicate_vectors" | "short_open_ended"), severity (same tier vocabulary), title (5-9 words), detail (one to two sentences citing the actual numbers from the snapshot), count (the integer count from the snapshot).
  - Detail must reference the actual numbers from the snapshot. Do not invent percentages.

Recommendation:
  - One sentence. Practical, not preachy.
  - If "clean": confirm the dataset looks healthy and can be analyzed as-is.
  - If "minor": suggest noting these in the methodology section but proceeding.
  - If "moderate": suggest reviewing flagged responses and recollecting if subgroup analysis is planned.
  - If "severe": suggest reviewing the distribution channel and considering exclusion of flagged respondents before relying on the numbers.

Headline:
  - One or two plain-language sentences summarizing the picture. Lead with the worst signal.
  - Confident voice. Avoid hedging language ("might", "could possibly").

Tone: confident, plain-language, non-technical. Do not lecture. Use direct phrasing.

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "severity": "clean" | "minor" | "moderate" | "severe",
  "severity_label": "<short phrase, e.g., 'Minor issues'>",
  "headline": "<one or two plain-language sentences>",
  "findings": [
    {
      "check": "straight_lining" | "duplicate_vectors" | "short_open_ended",
      "severity": "clean" | "minor" | "moderate" | "severe",
      "title": "<5-9 word title>",
      "detail": "<one or two sentences with the actual numbers>",
      "count": <integer>
    }
  ],
  "recommendation": "<one sentence>"
}
SYS;

$userPrompt  = "Data quality snapshot:\n\n" . $snapshotBlock . "\n\n";
$userPrompt .= "Produce the data quality JSON now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 900);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['severity'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validSeverities = ['clean', 'minor', 'moderate', 'severe'];
$validChecks     = ['straight_lining', 'duplicate_vectors', 'short_open_ended'];
$defaultLabels   = [
    'clean'    => 'Clean',
    'minor'    => 'Minor issues',
    'moderate' => 'Moderate issues',
    'severe'   => 'Severe issues',
];

$severity = (string)($parsed['severity'] ?? 'clean');
if (!in_array($severity, $validSeverities, true)) $severity = 'clean';

$severityLabel = clean_string((string)($parsed['severity_label'] ?? $defaultLabels[$severity]), 48);
if ($severityLabel === '') $severityLabel = $defaultLabels[$severity];

$headline = clean_string((string)($parsed['headline'] ?? ''), 360);
if ($headline === '') $headline = 'See the findings below for details on data quality.';

$recommendation = clean_string((string)($parsed['recommendation'] ?? ''), 360);
if ($recommendation === '') $recommendation = 'Review the findings and decide whether further action is needed before publishing results.';

$findings = [];
if (is_array($parsed['findings'] ?? null)) {
    foreach ($parsed['findings'] as $f) {
        if (!is_array($f)) continue;
        $check = (string)($f['check'] ?? '');
        if (!in_array($check, $validChecks, true)) continue;
        $sev = (string)($f['severity'] ?? $severity);
        if (!in_array($sev, $validSeverities, true)) $sev = $severity;
        $title  = clean_string((string)($f['title']  ?? ''), 80);
        $detail = clean_string((string)($f['detail'] ?? ''), 280);
        if ($title === '' || $detail === '') continue;
        $findings[] = [
            'check'    => $check,
            'severity' => $sev,
            'title'    => $title,
            'detail'   => $detail,
            'count'    => max(0, (int)($f['count'] ?? 0)),
        ];
        if (count($findings) >= 6) break;
    }
}

json_out([
    'ok'             => true,
    'severity'       => $severity,
    'severity_label' => $severityLabel,
    'headline'       => $headline,
    'findings'       => $findings,
    'recommendation' => $recommendation,
    'model'          => ai_config()['model'],
]);
