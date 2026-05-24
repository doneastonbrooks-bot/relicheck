<?php
// POST /api/ai/narrate-360-subject.php
// Body: {
//   "snapshot": {
//     "subject":             { "name", "title?", "department?" },
//     "panel":               { "name", "self_assessment": bool },
//     "completion":          { "total": int, "by_relationship": { rel: int } },
//     "overall_mean":        <float|null>,        // grand mean across items
//     "scale":               { "low": int, "high": int },  // e.g. 1..5
//     "top3":                [ { "text", "mean", "n" }, ... ],
//     "bottom3":             [ { "text", "mean", "n" }, ... ],
//     "self_vs_others_gaps": [
//       { "text", "self": <float>, "others": <float>, "gap": <float> },
//       ...  // pre-sorted by |gap| desc, only items with |gap| > 0.5
//     ]
//   }
// }
//
// Phase 131a. AI narrator for the 360 subject report. Same I/O shape as the
// other narrate-*.php endpoints (tone / tone_label / headline / paragraph /
// highlights) so the panels view renders through the same display chrome.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_360:user:' . (int)$user['id'], 30, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$subject = is_array($snap['subject'] ?? null) ? $snap['subject'] : [];
$panel   = is_array($snap['panel']   ?? null) ? $snap['panel']   : [];
$completion = is_array($snap['completion'] ?? null) ? $snap['completion'] : ['total' => 0, 'by_relationship' => []];
$totalRaters = max(0, (int)($completion['total'] ?? 0));
$byRel = is_array($completion['by_relationship'] ?? null) ? $completion['by_relationship'] : [];

if ($totalRaters < 1) {
    fail('insufficient_data', 'Subject report needs at least one completed rater to narrate.');
}

$normFloat = function ($v): ?float {
    if (!is_numeric($v)) return null;
    return round((float)$v, 3);
};

$subjName = clean_string((string)($subject['name'] ?? 'this subject'), 120);
$subjTitle = clean_string((string)($subject['title'] ?? ''), 120);
$subjDept  = clean_string((string)($subject['department'] ?? ''), 120);
$panelName = clean_string((string)($panel['name'] ?? ''), 160);
$selfOn    = !empty($panel['self_assessment']);

$scale = is_array($snap['scale'] ?? null) ? $snap['scale'] : ['low' => 1, 'high' => 5];
$scaleLow  = (int)($scale['low']  ?? 1);
$scaleHigh = (int)($scale['high'] ?? 5);
if ($scaleHigh <= $scaleLow) { $scaleLow = 1; $scaleHigh = 5; }

$overallMean = $normFloat($snap['overall_mean'] ?? null);

$cleanItems = function (array $raw, int $cap = 5): array {
    $out = [];
    foreach ($raw as $it) {
        if (!is_array($it)) continue;
        $text = clean_string((string)($it['text'] ?? ''), 140);
        if ($text === '') continue;
        $mean = is_numeric($it['mean'] ?? null) ? round((float)$it['mean'], 3) : null;
        $n    = max(0, (int)($it['n'] ?? 0));
        $out[] = ['text' => $text, 'mean' => $mean, 'n' => $n];
        if (count($out) >= $cap) break;
    }
    return $out;
};

$top3    = $cleanItems(is_array($snap['top3']    ?? null) ? $snap['top3']    : [], 3);
$bottom3 = $cleanItems(is_array($snap['bottom3'] ?? null) ? $snap['bottom3'] : [], 3);

$cleanGaps = function (array $raw, int $cap = 5): array {
    $out = [];
    foreach ($raw as $g) {
        if (!is_array($g)) continue;
        $text   = clean_string((string)($g['text']   ?? ''), 140);
        if ($text === '') continue;
        $self   = is_numeric($g['self']   ?? null) ? round((float)$g['self'],   3) : null;
        $others = is_numeric($g['others'] ?? null) ? round((float)$g['others'], 3) : null;
        $gap    = is_numeric($g['gap']    ?? null) ? round((float)$g['gap'],    3) : null;
        if ($self === null || $others === null || $gap === null) continue;
        $out[] = ['text' => $text, 'self' => $self, 'others' => $others, 'gap' => $gap];
        if (count($out) >= $cap) break;
    }
    return $out;
};
$gaps = $cleanGaps(is_array($snap['self_vs_others_gaps'] ?? null) ? $snap['self_vs_others_gaps'] : [], 5);

// Build the snapshot text block sent to the model.
$relLabels = ['self' => 'Self', 'manager' => 'Manager', 'peer' => 'Peers', 'direct_report' => 'Direct reports', 'external' => 'External'];
$relCountsLines = [];
foreach (['self', 'manager', 'peer', 'direct_report', 'external'] as $rel) {
    $n = (int)($byRel[$rel] ?? 0);
    if ($n === 0 && !($rel === 'self' && $selfOn)) continue;
    $relCountsLines[] = '  - ' . $relLabels[$rel] . ': ' . $n;
}

$topLines = [];
foreach ($top3 as $it) {
    $topLines[] = sprintf('  %.2f - "%s" (n=%d)', (float)$it['mean'], $it['text'], $it['n']);
}
$botLines = [];
foreach ($bottom3 as $it) {
    $botLines[] = sprintf('  %.2f - "%s" (n=%d)', (float)$it['mean'], $it['text'], $it['n']);
}
$gapLines = [];
foreach ($gaps as $g) {
    $sign = $g['gap'] > 0 ? 'self higher' : 'self lower';
    $gapLines[] = sprintf(
        '  gap %+0.2f (%s): self=%.2f, others=%.2f - "%s"',
        $g['gap'], $sign, $g['self'], $g['others'], $g['text']
    );
}

$subjLine = $subjName;
if ($subjTitle !== '' || $subjDept !== '') {
    $subjLine .= ' (' . trim($subjTitle . ($subjTitle !== '' && $subjDept !== '' ? ', ' : '') . $subjDept) . ')';
}

$snapshotBlock  = "Subject: " . $subjLine . "\n";
$snapshotBlock .= "Panel: " . ($panelName !== '' ? $panelName : '(unnamed)') . "\n";
$snapshotBlock .= "Self-assessment enabled: " . ($selfOn ? 'yes' : 'no') . "\n";
$snapshotBlock .= "Likert scale: " . $scaleLow . " to " . $scaleHigh . "\n";
$snapshotBlock .= "Overall mean across all rated items: " . ($overallMean !== null ? number_format($overallMean, 2) : 'not computable') . "\n";
$snapshotBlock .= "Total raters completed: " . $totalRaters . "\n";
if (!empty($relCountsLines)) {
    $snapshotBlock .= "Raters by relationship:\n" . implode("\n", $relCountsLines) . "\n";
}
$snapshotBlock .= "\nHighest-rated items:\n" . (empty($topLines) ? '  (none reported)' : implode("\n", $topLines)) . "\n";
$snapshotBlock .= "\nLowest-rated items:\n" . (empty($botLines) ? '  (none reported)' : implode("\n", $botLines)) . "\n";
$snapshotBlock .= "\nSelf-vs-others gaps (|gap| > 0.5), sorted by magnitude:\n" . (empty($gapLines) ? '  (none meaningful)' : implode("\n", $gapLines)) . "\n";

$system = <<<SYS
You are an experienced HR practitioner producing a short, plain-language narrator card that sits at the top of a 360 subject report inside a survey analytics app. The reader is an HR partner or the subject's manager. They want a fast, useful read on what the data says about this one person.

The reader can already see the underlying table (item means by relationship), so do not restate every number. Surface what matters.

Tone of the card (visual pill):
  - "good" : overall picture is strong; ratings are high; gaps are small.
  - "ok"   : solid, mixed picture; some development areas worth flagging.
  - "warn" : meaningful gaps or low items; specific feedback is needed.
  - "bad"  : multiple low areas, or large self-vs-others gaps in both directions.

Voice:
  - Refer to the subject by their first name when one is given. Use "this leader" or "this manager" if no name is available.
  - 2-4 sentences. Plain prose. No bullets in the paragraph.
  - Name the strongest theme by quoting the highest item's text. Name the biggest development area by quoting the lowest item's text. Use the exact text from the snapshot.
  - When the self-vs-others gaps include a meaningful blind spot (self rates higher than others by 0.75 or more on a 5-point scale, or proportionally on a longer scale), name the item and call it a blind spot in plain language. When self rates lower than others by 0.75 or more, call it self-deprecation or underestimation.
  - When self-assessment is disabled or no self rater completed, skip the self-vs-others sentence.
  - Avoid jargon. Do not say "Cronbach", "inter-rater reliability", "psychometric", percentages, or statistical symbols.
  - Frame for action: end with a sentence that nudges what the manager / HR partner should do next.

Highlights (0-3 short items, optional):
  - Each is { label: 3-6 words, detail: one short sentence quoting the relevant item }.
  - Use highlights to surface specific items that warrant a coaching conversation, a 30-day follow-up, or an explicit strength to leverage.
  - When the picture is uniformly strong, omit highlights or include one positive highlight.

Headline:
  - One sentence summarizing the picture. Plain language. Names the subject when known.

Output: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Strong with one gap'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence quoting the relevant item>" }
  ]
}
SYS;

$userPrompt = "360 subject snapshot:\n\n" . $snapshotBlock . "\n\nProduce the narrator JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Strong picture',
    'ok'   => 'Solid with notes',
    'warn' => 'Gaps to address',
    'bad'  => 'Significant gaps',
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
        $detail = clean_string((string)($h['detail'] ?? ''), 280);
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
