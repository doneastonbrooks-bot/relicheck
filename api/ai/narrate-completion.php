<?php
// POST /api/ai/narrate-completion.php
// Body: {
//   "snapshot": {
//     "totals": {
//       "total_responses":    <int>,
//       "item_count":         <int>,
//       "complete_responses": <int>,
//       "complete_pct":       <int 0-100>,
//       "overall_pct":        <int 0-100>,
//       "completion_score":   <int 0-100>
//     },
//     "drop_off":     <null|{ idx:<int>, prompt:<str>, type:<str>, count:<int>, pct:<int> }>,
//     "worst_items":  [{ prompt:<str>, pct:<int>, idx:<int> }],
//     "funnel_end":   <null|int>,    // percent still answering at the last item
//     "by_group":     <null|{ group_var:<str>, rows:[{ label:<str>, n:<int>, missing_pct:<int> }] }>
//   }
// }
//
// Phase 141 Completion & Missing Data narrator. Same I/O shape as the
// other narrate-*.php endpoints (tone / tone_label / headline / paragraph
// / highlights / affected_items). Translates the completion-snapshot
// numbers into a plain-language read: are people finishing, where do
// they stop, do specific items lose them, are groups dropping unevenly.
// No respondent data is sent; only aggregates.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_completion:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};

$totalsIn = is_array($snap['totals'] ?? null) ? $snap['totals'] : [];
$totals = [
    'total_responses'    => max(0, (int)($totalsIn['total_responses']    ?? 0)),
    'item_count'         => max(0, (int)($totalsIn['item_count']         ?? 0)),
    'complete_responses' => max(0, (int)($totalsIn['complete_responses'] ?? 0)),
    'complete_pct'       => $clampPct($totalsIn['complete_pct']          ?? 0),
    'overall_pct'        => $clampPct($totalsIn['overall_pct']           ?? 0),
    'completion_score'   => $clampPct($totalsIn['completion_score']      ?? 0),
];

if ($totals['total_responses'] === 0) {
    fail('no_responses', 'No responses yet. Collect responses before requesting a completion narration.');
}

$dropIn = is_array($snap['drop_off'] ?? null) ? $snap['drop_off'] : null;
$dropOff = null;
if ($dropIn !== null) {
    $dropOff = [
        'idx'    => max(0, (int)($dropIn['idx']    ?? 0)),
        'prompt' => clean_string((string)($dropIn['prompt'] ?? ''), 100),
        'type'   => clean_string((string)($dropIn['type']   ?? ''), 16),
        'count'  => max(0, (int)($dropIn['count']  ?? 0)),
        'pct'    => $clampPct($dropIn['pct']       ?? 0),
    ];
}

$worstIn = is_array($snap['worst_items'] ?? null) ? $snap['worst_items'] : [];
$worst = [];
foreach ($worstIn as $w) {
    if (!is_array($w)) continue;
    $prompt = clean_string((string)($w['prompt'] ?? ''), 100);
    if ($prompt === '') continue;
    $worst[] = [
        'prompt' => $prompt,
        'pct'    => $clampPct($w['pct'] ?? 0),
        'idx'    => max(0, (int)($w['idx'] ?? 0)),
    ];
    if (count($worst) >= 5) break;
}

$funnelEnd = (isset($snap['funnel_end']) && $snap['funnel_end'] !== null)
    ? $clampPct($snap['funnel_end']) : null;

$bgIn = is_array($snap['by_group'] ?? null) ? $snap['by_group'] : null;
$byGroup = null;
if ($bgIn !== null) {
    $rowsIn = is_array($bgIn['rows'] ?? null) ? $bgIn['rows'] : [];
    $rows = [];
    foreach ($rowsIn as $r) {
        if (!is_array($r)) continue;
        $label = clean_string((string)($r['label'] ?? ''), 60);
        if ($label === '') continue;
        $rows[] = [
            'label'       => $label,
            'n'           => max(0, (int)($r['n'] ?? 0)),
            'missing_pct' => $clampPct($r['missing_pct'] ?? 0),
        ];
        if (count($rows) >= 6) break;
    }
    if (count($rows)) {
        $byGroup = [
            'group_var' => clean_string((string)($bgIn['group_var'] ?? 'group'), 80),
            'rows'      => $rows,
        ];
    }
}

// Build snapshot block for the model.
$lines = [];
$lines[] = "Completion & Missing Data snapshot:";
$lines[] = "  - Completion Score (0-100): " . $totals['completion_score'];
$lines[] = "  - Total responses: " . $totals['total_responses'];
$lines[] = "  - Items in this survey: " . $totals['item_count'];
$lines[] = "  - Complete-case responses (zero missing): " . $totals['complete_responses'] . " (" . $totals['complete_pct'] . " percent of all responses)";
$lines[] = "  - Overall missingness rate across all cells: " . $totals['overall_pct'] . " percent";
if ($funnelEnd !== null) {
    $lines[] = "  - Percent of respondents still answering at the last item: " . $funnelEnd . " percent";
}
$lines[] = "";
if ($dropOff !== null) {
    $lines[] = "Modal drop-off point (most common first-skipped item):";
    $lines[] = "  - Item " . ($dropOff['idx'] + 1) . " (\"" . $dropOff['prompt'] . "\", type=" . $dropOff['type'] . ")";
    $lines[] = "  - " . $dropOff['count'] . " respondents stopped here (" . $dropOff['pct'] . " percent of all responses)";
} else {
    $lines[] = "Modal drop-off point: none. Every respondent answered every item.";
}
$lines[] = "";
if (count($worst)) {
    $lines[] = "Worst items by skip rate:";
    foreach ($worst as $w) {
        $lines[] = "  - \"" . $w['prompt'] . "\": " . $w['pct'] . " percent skipped";
    }
}
if ($byGroup !== null) {
    $lines[] = "";
    $lines[] = "By group (\"" . $byGroup['group_var'] . "\"):";
    foreach ($byGroup['rows'] as $r) {
        $lines[] = "  - " . $r['label'] . " (n=" . $r['n'] . "): " . $r['missing_pct'] . " percent missing on remaining items";
    }
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card pinned to the top of the Completion & Missing Data analytics tab. The audience is the survey owner (HR partner, evaluator, researcher) trying to figure out whether respondents are finishing the survey and where to fix it if they are not. The user is not a statistician. You translate completion-snapshot numbers into a plain-language verdict.

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Strong completion. Completion Score >= 80 AND overall missingness <= 5 percent AND no single item above 10 percent skip rate.
  - "ok"   : Acceptable but worth a glance. Score 60-79 OR overall missingness 5-15 percent OR one item between 10 and 25 percent skip.
  - "warn" : Notable drop-off. Score 40-59 OR overall missingness 15-30 percent OR a drop-off point at 20 percent or higher OR an item above 25 percent skip.
  - "bad"  : Significant drop-off. Score below 40 OR overall missingness above 30 percent OR drop-off above 35 percent OR any item above 50 percent skip.

Voice:
  - Lead with the practical answer and the score ("Completion scores 78 out of 100; people are finishing", "Completion scores 54; respondents are stopping at item N", "Completion scores 31; substantial drop-off needs investigation").
  - When a drop-off point is named, quote the item by its actual prompt text ("the open-ended item asking about manager support") and the percentage that stopped there.
  - When the worst items list is informative, name the highest-skip item by prompt and its percentage.
  - When the by_group data shows a gap of 10 percentage points or more between the highest and lowest group, name both groups by their actual labels.
  - 3 to 5 sentences. Plain prose. Avoid statistical jargon ("MCAR", "non-response", "imputation"). Use words like "skip rate", "drop-off", and "finished".

Highlights (0 to 3): short items naming specific findings.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - One should call out the drop-off point or the highest-skip item by quoting the prompt.
  - One can name a group with high missingness when by_group is present and shows a meaningful gap.
  - One can call out the funnel-end percentage when it is well below 100.

Headline:
  - One sentence summarizing completion health.

Affected items: empty array for now (forward compatibility).

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Finishing strongly'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 3-5 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": []
}
SYS;

$userPrompt = "Completion snapshot:\n\n" . $snapshotBlock . "\n\nProduce the completion narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Finishing strongly',
    'ok'   => 'Mostly finishing',
    'warn' => 'Drop-off to watch',
    'bad'  => 'Substantial drop-off',
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
