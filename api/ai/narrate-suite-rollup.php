<?php
// POST /api/ai/narrate-suite-rollup.php
// Body: {
//   "snapshot": {
//     "suite_name":     <string>,
//     "current_label":  <string, e.g. "Q2 2026">,
//     "previous_label": <string, e.g. "Q1 2026">,
//     "survey_count":   <int>,
//     "totals": {
//       "responses_current":  <int>,
//       "responses_previous": <int>,
//       "responses_delta":    <int>
//     },
//     "avg_ssi": {
//       "current":  <float|null>,
//       "previous": <float|null>,
//       "delta":    <float|null>
//     },
//     "construct_trends": [
//       { "name": <string>, "cur_mean": <float|null>, "prev_mean": <float|null>,
//         "delta": <float|null>, "survey_count": <int> }
//     ],
//     "per_survey": [
//       { "id": <int>, "title": <string>,
//         "current":  { "n": <int>, "ssi_proxy": <int|null>, "alpha": <float|null>,
//                       "composite_mean": <float|null> },
//         "previous": { "n": <int>, "ssi_proxy": <int|null>, "alpha": <float|null>,
//                       "composite_mean": <float|null> } }
//     ]
//   }
// }
//
// Phase 135. Suite roll-up narrator. Same I/O shape as the other
// narrate-*.php endpoints (tone / tone_label / headline / paragraph /
// highlights / affected_items). Reads the cross-suite snapshot and
// produces a one-paragraph quarterly read for the HR or evaluation lead
// who opens a suite expecting "engagement trending down, exit reasons
// shifting toward compensation" style narration.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_suite_rollup:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$suiteName     = clean_string((string)($snap['suite_name']     ?? ''), 100);
$currentLabel  = clean_string((string)($snap['current_label']  ?? ''), 32);
$previousLabel = clean_string((string)($snap['previous_label'] ?? ''), 32);
$surveyCount   = max(0, (int)($snap['survey_count'] ?? 0));

if ($surveyCount < 2) fail('insufficient_data', 'Need at least 2 surveys to narrate.');

$normFloat = function ($v, float $min, float $max, int $places = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $places);
};

$totals  = is_array($snap['totals']  ?? null) ? $snap['totals']  : [];
$avgSsi  = is_array($snap['avg_ssi'] ?? null) ? $snap['avg_ssi'] : [];

$respCur   = max(0, (int)($totals['responses_current']  ?? 0));
$respPrev  = max(0, (int)($totals['responses_previous'] ?? 0));
$respDelta = (int)($totals['responses_delta'] ?? ($respCur - $respPrev));

$ssiCur   = $normFloat($avgSsi['current']  ?? null, 0.0, 100.0, 1);
$ssiPrev  = $normFloat($avgSsi['previous'] ?? null, 0.0, 100.0, 1);
$ssiDelta = $normFloat($avgSsi['delta']    ?? null, -100.0, 100.0, 1);

$constructIn = is_array($snap['construct_trends'] ?? null) ? $snap['construct_trends'] : [];
$constructs = [];
foreach ($constructIn as $c) {
    if (!is_array($c)) continue;
    $name = clean_string((string)($c['name'] ?? ''), 60);
    if ($name === '') continue;
    $constructs[] = [
        'name'         => $name,
        'cur_mean'     => $normFloat($c['cur_mean']     ?? null, 0.0, 11.0, 3),
        'prev_mean'    => $normFloat($c['prev_mean']    ?? null, 0.0, 11.0, 3),
        'delta'        => $normFloat($c['delta']        ?? null, -11.0, 11.0, 3),
        'survey_count' => max(0, (int)($c['survey_count'] ?? 0)),
    ];
    if (count($constructs) >= 6) break;
}

$perSurveyIn = is_array($snap['per_survey'] ?? null) ? $snap['per_survey'] : [];
$perSurvey = [];
foreach ($perSurveyIn as $p) {
    if (!is_array($p)) continue;
    $title = clean_string((string)($p['title'] ?? ''), 100);
    if ($title === '') continue;
    $cur = is_array($p['current']  ?? null) ? $p['current']  : [];
    $pre = is_array($p['previous'] ?? null) ? $p['previous'] : [];
    $perSurvey[] = [
        'title'       => $title,
        'cur_n'       => max(0, (int)($cur['n'] ?? 0)),
        'prev_n'      => max(0, (int)($pre['n'] ?? 0)),
        'cur_ssi'     => is_numeric($cur['ssi_proxy'] ?? null) ? (int)$cur['ssi_proxy'] : null,
        'prev_ssi'    => is_numeric($pre['ssi_proxy'] ?? null) ? (int)$pre['ssi_proxy'] : null,
        'cur_compM'   => $normFloat($cur['composite_mean'] ?? null, 0.0, 11.0, 2),
        'prev_compM'  => $normFloat($pre['composite_mean'] ?? null, 0.0, 11.0, 2),
    ];
    if (count($perSurvey) >= 12) break;
}

// Build snapshot block.
$lines = [];
$lines[] = "Suite roll-up:";
$lines[] = "  - Suite: " . $suiteName;
$lines[] = "  - Current quarter: " . $currentLabel;
$lines[] = "  - Previous quarter: " . $previousLabel;
$lines[] = "  - Surveys in suite: " . $surveyCount;
$lines[] = "";
$lines[] = "Totals:";
$lines[] = "  - Responses " . $currentLabel . ": " . $respCur;
$lines[] = "  - Responses " . $previousLabel . ": " . $respPrev;
$lines[] = "  - Response delta (current minus previous): " . $respDelta;
$lines[] = "";
$lines[] = "Average Strength Index (proxy, 0-100):";
$lines[] = "  - " . $currentLabel . ": "  . ($ssiCur  === null ? 'n/a' : (string)$ssiCur);
$lines[] = "  - " . $previousLabel . ": " . ($ssiPrev === null ? 'n/a' : (string)$ssiPrev);
$lines[] = "  - Delta: " . ($ssiDelta === null ? 'n/a' : (string)$ssiDelta);
$lines[] = "";
$lines[] = "Shared-construct trends (constructs present in 2+ surveys this quarter):";
if (!count($constructs)) {
    $lines[] = "  - none";
} else {
    foreach ($constructs as $c) {
        $cm = $c['cur_mean']  === null ? 'n/a' : (string)$c['cur_mean'];
        $pm = $c['prev_mean'] === null ? 'n/a' : (string)$c['prev_mean'];
        $dl = $c['delta']     === null ? 'n/a' : (string)$c['delta'];
        $lines[] = sprintf(
            "  - %s (in %d survey%s): mean %s = %s, %s = %s, delta = %s",
            $c['name'], $c['survey_count'], $c['survey_count'] === 1 ? '' : 's',
            $currentLabel,  $cm,
            $previousLabel, $pm,
            $dl
        );
    }
}
$lines[] = "";
$lines[] = "Per-survey:";
foreach ($perSurvey as $p) {
    $cs = $p['cur_ssi']  === null ? 'n/a' : (string)$p['cur_ssi'];
    $ps = $p['prev_ssi'] === null ? 'n/a' : (string)$p['prev_ssi'];
    $cc = $p['cur_compM']  === null ? 'n/a' : (string)$p['cur_compM'];
    $pc = $p['prev_compM'] === null ? 'n/a' : (string)$p['prev_compM'];
    $lines[] = sprintf(
        "  - \"%s\": n %s = %d (prev %d), SSI %s = %s (prev %s), composite Likert mean %s = %s (prev %s)",
        $p['title'],
        $currentLabel, $p['cur_n'], $p['prev_n'],
        $currentLabel, $cs, $ps,
        $currentLabel, $cc, $pc
    );
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card pinned to the top of a survey-suite roll-up dashboard. The audience is the HR partner, program evaluator, or research lead who opens the suite expecting a quarterly read across every survey in it. The user is not a statistician. You translate response counts, average Strength Index, and shared-construct Likert-mean trends into a plain-language quarterly read.

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Response volume is up or stable AND average Strength Index is stable or up. At least one shared construct trending up or stable.
  - "ok"   : Mixed picture. Volume or Strength Index moved modestly in either direction. Constructs mixed.
  - "warn" : Notable drop in average Strength Index, OR a shared construct shows a clear negative direction (delta of -0.20 or worse on a 5-point scale, equivalent on larger scales), OR response volume dropped by 30 percent or more.
  - "bad"  : Multiple negative signals at once. Strength Index dropped meaningfully (-3 points or more) AND a shared construct moved against the suite's purpose.

Voice:
  - Lead with the practical answer. "Engagement is steady this quarter" / "Response volume is up but Strength Index slipped" / "Engagement is trending down" / "Cannot read this quarter yet, both windows are thin".
  - Name the suite by its actual name once.
  - Name at most two shared constructs by their actual label, and translate the direction (up, down, flat) plus the size (small, meaningful, large) in plain words. Avoid the phrase "delta".
  - Translate Strength Index moves into plain language. A -1 point move is "essentially flat". -1 to -3 is "slipping". Beyond -3 is "down meaningfully". Mirror direction for positive moves.
  - 3 to 5 sentences. Plain prose.
  - When responses_previous is 0, do not say things "dropped"; say the suite "ran cold last quarter" or "this is the first quarter with usable volume".
  - When responses_current is below 30 in total across the suite, lead with "this read is preliminary" and downgrade the tone toward "warn" unless every metric is strong.

Highlights (0 to 3): short items naming specific points.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - One should call out the strongest moving construct when constructs are present.
  - One should call out the response volume change.
  - One can call out the strongest-performing or weakest-performing survey by name when survey_count is at least three.

Headline:
  - One sentence summarizing whether the suite is improving, holding, or slipping this quarter.

Affected items:
  - When the paragraph or any highlight names a specific construct, include it in affected_items.
  - When the paragraph or any highlight names a specific survey by title, include it in affected_items.
  - Each entry has shape { "type": "construct"|"survey", "id": "<exact name or title from snapshot>" }.
  - Empty array is fine when the narration speaks only at the suite level.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Steady this quarter'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 3-5 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": [
    { "type": "construct", "id": "<construct name>" }
  ]
}
SYS;

$userPrompt = "Suite roll-up snapshot:\n\n" . $snapshotBlock . "\n\nProduce the suite roll-up narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Steady or improving',
    'ok'   => 'Mixed quarter',
    'warn' => 'Watch this quarter',
    'bad'  => 'Slipping this quarter',
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

// Normalize affected_items. Whitelist construct names that appear in the snapshot
// and survey titles that appear in per_survey. Drop everything else.
$validConstructs = [];
foreach ($constructs as $c) { $validConstructs[$c['name']] = true; }
$validSurveys = [];
foreach ($perSurvey as $p) { $validSurveys[$p['title']] = true; }

$affectedItems = [];
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 100);
        if ($type === 'construct' && isset($validConstructs[$id])) {
            $affectedItems[] = ['type' => 'construct', 'id' => $id];
        } elseif ($type === 'survey' && isset($validSurveys[$id])) {
            $affectedItems[] = ['type' => 'survey', 'id' => $id];
        }
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
