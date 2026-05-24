<?php
// POST /api/ai/narrate-quality.php
// Body: {
//   "snapshot": {
//     "totals": {
//       "total_responses":       <int>,
//       "likert_item_count":     <int>,
//       "open_ended_item_count": <int>,
//       "flagged_unique":        <int>,
//       "flag_density_pct":      <int 0-100>
//     },
//     "straightlining":  { "eligible": <int>, "count": <int>, "pct": <int> },
//     "duplicates":      { "eligible": <int>, "cluster_count": <int>,
//                          "in_clusters": <int>, "largest_cluster": <int>,
//                          "pct": <int> },
//     "short_opens":     { "total_answers": <int>, "short_count": <int>,
//                          "pct": <int>, "threshold_chars": <int> },
//     "missingness":     { "overall_pct": <int>,
//                          "worst": [{"prompt": <str>, "pct": <int>}] },
//     "by_channel":      [{ "channel": <str>, "n": <int>,
//                           "flagged": <int>, "flag_pct": <int> }]
//   }
// }
//
// Phase 140 Response Quality narrator. Same I/O shape as the other
// narrate-*.php endpoints (tone / tone_label / headline / paragraph /
// highlights). Translates the flag counts into a plain-language read of
// whether the dataset is trustworthy as-is, needs review, or needs
// recollection. No respondent text or identifiers are sent.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_quality:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};
$clean = function ($v, int $max = 100): string {
    return clean_string((string)$v, $max);
};

$totalsIn = is_array($snap['totals'] ?? null) ? $snap['totals'] : [];
$totals = [
    'total_responses'       => max(0, (int)($totalsIn['total_responses']       ?? 0)),
    'likert_item_count'     => max(0, (int)($totalsIn['likert_item_count']     ?? 0)),
    'open_ended_item_count' => max(0, (int)($totalsIn['open_ended_item_count'] ?? 0)),
    'flagged_unique'        => max(0, (int)($totalsIn['flagged_unique']        ?? 0)),
    'flag_density_pct'      => $clampPct($totalsIn['flag_density_pct']         ?? 0),
    'quality_score'         => $clampPct($totalsIn['quality_score']            ?? 0),
    'quality_domain_pts'    => max(0, min(15, (int)($totalsIn['quality_domain_pts'] ?? 0))),
];

if ($totals['total_responses'] === 0) {
    fail('no_responses', 'No responses yet. Collect responses before requesting a quality narration.');
}

$slIn = is_array($snap['straightlining'] ?? null) ? $snap['straightlining'] : [];
$sl = [
    'eligible' => max(0, (int)($slIn['eligible'] ?? 0)),
    'count'    => max(0, (int)($slIn['count']    ?? 0)),
    'pct'      => $clampPct($slIn['pct']         ?? 0),
];
$dvIn = is_array($snap['duplicates'] ?? null) ? $snap['duplicates'] : [];
$dv = [
    'eligible'        => max(0, (int)($dvIn['eligible']        ?? 0)),
    'cluster_count'   => max(0, (int)($dvIn['cluster_count']   ?? 0)),
    'in_clusters'     => max(0, (int)($dvIn['in_clusters']     ?? 0)),
    'largest_cluster' => max(0, (int)($dvIn['largest_cluster'] ?? 0)),
    'pct'             => $clampPct($dvIn['pct']                ?? 0),
];
$soIn = is_array($snap['short_opens'] ?? null) ? $snap['short_opens'] : [];
$so = [
    'total_answers'   => max(0, (int)($soIn['total_answers']   ?? 0)),
    'short_count'     => max(0, (int)($soIn['short_count']     ?? 0)),
    'pct'             => $clampPct($soIn['pct']                ?? 0),
    'threshold_chars' => max(1, (int)($soIn['threshold_chars'] ?? 3)),
];
$miIn = is_array($snap['missingness'] ?? null) ? $snap['missingness'] : [];
$miWorst = is_array($miIn['worst'] ?? null) ? $miIn['worst'] : [];
$mi = [
    'overall_pct' => $clampPct($miIn['overall_pct'] ?? 0),
    'worst'       => [],
];
foreach ($miWorst as $w) {
    if (!is_array($w)) continue;
    $prompt = $clean($w['prompt'] ?? '', 100);
    if ($prompt === '') continue;
    $mi['worst'][] = ['prompt' => $prompt, 'pct' => $clampPct($w['pct'] ?? 0)];
    if (count($mi['worst']) >= 3) break;
}
$chIn = is_array($snap['by_channel'] ?? null) ? $snap['by_channel'] : [];
$channels = [];
foreach ($chIn as $c) {
    if (!is_array($c)) continue;
    $name = $clean($c['channel'] ?? '', 32);
    if ($name === '') continue;
    $channels[] = [
        'channel'  => $name,
        'n'        => max(0, (int)($c['n']       ?? 0)),
        'flagged'  => max(0, (int)($c['flagged'] ?? 0)),
        'flag_pct' => $clampPct($c['flag_pct']   ?? 0),
    ];
    if (count($channels) >= 8) break;
}

// Build snapshot block for the model.
$lines = [];
$lines[] = "Response quality snapshot:";
$lines[] = "  - Response Quality Score (0-100): " . $totals['quality_score'] . " (contributes " . $totals['quality_domain_pts'] . " / 15 to the Strength Index)";
$lines[] = "  - Total responses: " . $totals['total_responses'];
$lines[] = "  - Likert items in this survey: " . $totals['likert_item_count'];
$lines[] = "  - Open-ended items in this survey: " . $totals['open_ended_item_count'];
$lines[] = "  - Respondents flagged by any check: " . $totals['flagged_unique'] . " (" . $totals['flag_density_pct'] . " percent of all responses)";
$lines[] = "";
$lines[] = "Straight-lining (>=80 percent same Likert value across answered items):";
$lines[] = "  - Eligible respondents (>=3 answered Likert items): " . $sl['eligible'];
$lines[] = "  - Flagged: " . $sl['count'] . " (" . $sl['pct'] . " percent of eligible)";
$lines[] = "";
$lines[] = "Duplicate response vectors (identical Likert answers across respondents):";
$lines[] = "  - Eligible respondents: " . $dv['eligible'];
$lines[] = "  - Clusters: " . $dv['cluster_count'];
$lines[] = "  - Respondents in clusters: " . $dv['in_clusters'] . " (" . $dv['pct'] . " percent of eligible)";
$lines[] = "  - Largest cluster size: " . $dv['largest_cluster'];
$lines[] = "";
$lines[] = "Short open-ended answers (under " . $so['threshold_chars'] . " characters):";
$lines[] = "  - Total open answers: " . $so['total_answers'];
$lines[] = "  - Short answers: " . $so['short_count'] . " (" . $so['pct'] . " percent)";
$lines[] = "";
$lines[] = "Missingness:";
$lines[] = "  - Overall missingness rate across all items: " . $mi['overall_pct'] . " percent";
if (count($mi['worst'])) {
    $lines[] = "  - Worst items (by skip rate):";
    foreach ($mi['worst'] as $w) {
        $lines[] = "      - \"" . $w['prompt'] . "\": " . $w['pct'] . " percent missing";
    }
}
if (count($channels)) {
    $lines[] = "";
    $lines[] = "By channel:";
    foreach ($channels as $c) {
        $lines[] = "  - " . $c['channel'] . ": n=" . $c['n'] . ", flag rate " . $c['flag_pct'] . " percent";
    }
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card pinned to the top of the Response Quality analytics tab. The audience is the survey owner (HR partner, researcher, evaluator) deciding whether to trust this dataset as-is, review specific respondents, or recollect. The user is not a statistician. You translate flag counts into a plain-language verdict.

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Trustworthy dataset. Response Quality Score >= 80 AND no single check above 10 percent AND missingness <= 5 percent.
  - "ok"   : Mostly trustworthy with one or two items to glance at. Score 60-79 OR flag density 5-15 percent OR one check between 10 and 20 percent. Most analyses are still fine.
  - "warn" : Notable quality concerns. Score 40-59 OR flag density 15-30 percent OR any check between 20 and 40 percent OR a clear high-flag channel.
  - "bad"  : Major concerns. Score below 40 OR flag density above 30 percent OR any check above 40 percent OR the largest duplicate cluster exceeds 10 respondents.

Voice:
  - Lead with the practical answer and the score ("Response Quality scores 78 out of 100 this run, so the dataset is in good shape", "Response Quality scores 54; a few responses are worth a quick review before you trust these numbers", "Response Quality scores 31; plan a review pass before drawing conclusions").
  - Name the dominant concern when one exists ("straight-lining at X percent", "two duplicate clusters totaling N respondents", "Y percent of open answers are under three characters", "the email channel has a flag rate of Z percent versus the web link's W percent").
  - When missingness is high (>= 15 percent overall, or a single item >= 30 percent), name the worst item by its actual prompt and suggest the author look at wording or sensitivity.
  - When a channel comparison shows a clear skew, name both channels and the percentage gap.
  - 3 to 5 sentences. Plain prose. Avoid statistical jargon.

Highlights (0 to 3): short items naming specific findings.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - One should call out the flag that dominates the picture this run.
  - One can name a high-missingness item by quoting its prompt.
  - One can name a high-flag channel by its actual label.

Headline:
  - One sentence summarizing trust level on this dataset.

Affected items:
  - Empty array for now; the tab does not yet have data-narrator-target hooks. This field stays present for forward compatibility.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Trustworthy as-is'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 3-5 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": []
}
SYS;

$userPrompt = "Response quality snapshot:\n\n" . $snapshotBlock . "\n\nProduce the response-quality narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Trustworthy as-is',
    'ok'   => 'Glance and ship',
    'warn' => 'Review before trusting',
    'bad'  => 'Needs triage',
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
