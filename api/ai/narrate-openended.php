<?php
// POST /api/ai/narrate-openended.php
// Body: {
//   "snapshot": {
//     "totals": {
//       "total_respondents":  <int>,
//       "open_respondents":   <int>,
//       "engagement_pct":     <int 0-100>,
//       "total_open_answers": <int>,
//       "avg_chars_overall":  <int>
//     },
//     "open_questions": [
//       { "idx": <int>, "prompt": <string>,
//         "response_count": <int>, "avg_chars": <int>, "short_count": <int>,
//         "short_pct": <int 0-100> }
//     ]
//   }
// }
//
// Dashboard Narrator for the Open-Ended Analysis tab (Phase 54). Structural
// narration only, describes engagement and answer-length patterns. Theme
// and sentiment extraction continue to live in the existing dashboard
// flow; this narrator does NOT touch respondent text.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_openended:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$totals = $snap['totals'] ?? [];
$qsIn   = is_array($snap['open_questions'] ?? null) ? $snap['open_questions'] : [];

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};

$totalResp   = max(0, (int)($totals['total_respondents']  ?? 0));
$openResp    = max(0, (int)($totals['open_respondents']   ?? 0));
$engagePct   = $clampPct($totals['engagement_pct']        ?? 0);
$totalAns    = max(0, (int)($totals['total_open_answers'] ?? 0));
$avgChars    = max(0, (int)($totals['avg_chars_overall']  ?? 0));

if ($totalResp === 0) fail('no_responses', 'Collect at least one response before requesting an open-ended narration.');
if (count($qsIn) === 0 && $totalAns === 0) fail('no_open_items', 'No open-ended items or answers in this survey.');

$questions = [];
foreach ($qsIn as $q) {
    if (!is_array($q)) continue;
    $idx = (int)($q['idx'] ?? count($questions));
    $prompt = clean_string((string)($q['prompt'] ?? ''), 100);
    if ($prompt === '') $prompt = 'Open-ended question ' . ($idx + 1);
    $questions[] = [
        'idx'             => $idx,
        'prompt'          => $prompt,
        'response_count'  => max(0, (int)($q['response_count']  ?? 0)),
        'avg_chars'       => max(0, (int)($q['avg_chars']       ?? 0)),
        'short_count'     => max(0, (int)($q['short_count']     ?? 0)),
        'short_pct'       => $clampPct($q['short_pct']          ?? 0),
    ];
    if (count($questions) >= 12) break;
}

$lines = [];
$lines[] = "Totals: " . $totalResp . " respondents; " . $openResp . " answered any open-ended question ("
        . $engagePct . "%); " . $totalAns . " total open-ended answers; avg "
        . $avgChars . " characters across all open answers.";
$lines[] = "";
$lines[] = "Per-question (idx. \"prompt\", responses / avg chars / short answers / short %):";
foreach ($questions as $q) {
    $lines[] = sprintf(
        '  %d. "%s", n=%d, avg %d chars, %d short (%d%%)',
        $q['idx'] + 1, $q['prompt'], $q['response_count'], $q['avg_chars'], $q['short_count'], $q['short_pct']
    );
}
$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card for the Open-Ended Analysis tab of a survey app. The user is not a statistician. You explain the QUALITATIVE ENGAGEMENT picture: how many respondents answered, how detailed their answers were, and which prompts drew the most or least depth.

You do NOT see respondent text. Themes and sentiment are handled by a separate flow inside the same dashboard; do not invent theme content. Stick to engagement and answer-length signals.

Tone tiers for the visual pill:
  - "good" : Engagement >= 70% AND average length >= 30 chars AND short-pct < 10% on most prompts.
  - "ok"   : Engagement 50-69% OR average length 20-29 chars OR some prompts with elevated short-pct.
  - "warn" : Engagement 30-49% OR average length 10-19 chars OR short-pct >= 20% on most prompts.
  - "bad"  : Engagement < 30% OR average length < 10 chars OR short-pct >= 40% across the board.

Voice:
  - Lead with the engagement picture in plain language.
  - Mention the most-answered and least-answered prompts by quoting their text.
  - If short-pct is meaningful, call it out as a credibility concern.
  - Avoid statistical jargon. Use "respondents engaged with", "answers were brief", "many respondents skipped this question".
  - 2-4 sentences. Plain prose.

Highlights (0-3): short items surfacing specific engagement notes.
  - Each has: label (3-6 words, e.g. "Highest engagement", "Lowest engagement", "Many short answers"), detail (one short sentence citing numbers).

Headline:
  - One sentence summarizing the qualitative engagement picture.

Affected items (Phase 104):
  - When the paragraph or any highlight names specific open-ended questions by prompt text or item number, also list them in an affected_items array.
  - Each entry has shape { "type": "question", "id": "<0-based idx as string>" }.
  - The id must match the idx field in the per-question snapshot block.
  - Empty array is fine when the narration does not call out specific prompts.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Strong engagement'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence>" }
  ],
  "affected_items": [
    { "type": "question", "id": "<0-based idx of an open-ended question called out above>" }
  ]
}
SYS;

$userPrompt = "Open-ended snapshot:\n\n" . $snapshotBlock . "\n\nProduce the open-ended narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = ['good' => 'Strong engagement', 'ok' => 'Moderate engagement', 'warn' => 'Limited engagement', 'bad' => 'Low engagement'];
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

// Phase 104: normalize affected_items. Whitelist type='question' and validate
// the id matches an idx in this snapshot. Drop anything else.
$affectedItems = [];
$validIds = [];
foreach ($questions as $q) { $validIds[(string)$q['idx']] = true; }
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'question') continue;
        if (!isset($validIds[$id])) continue;
        $affectedItems[] = ['type' => $type, 'id' => $id];
        if (count($affectedItems) >= 12) break;
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
