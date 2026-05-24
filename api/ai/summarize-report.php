<?php
// POST /api/ai/summarize-report.php
// Body: {
//   "analytics": {
//      "title": "...",
//      "n":  <int responses>,                 number of complete Likert responses
//      "k":  <int items>,                     number of Likert items in the scale
//      "alpha": <number>,                     Cronbach's alpha
//      "splitHalf": <number>,                 Spearman-Brown coefficient
//      "kmo": <number>,                       KMO overall
//      "items": [
//        {
//          "prompt": "...",                   the question text
//          "mean": <number>,
//          "sd": <number>,
//          "itemTotalCorr": <number>,         corrected item-total correlation
//          "alphaIfDeleted": <number>,
//          "reverse": <bool>
//        }, ...
//      ],
//      "cautions": [ "string", ... ]          optional small-sample / methodology warnings
//   }
// }
// Returns:
//   { ok: true, summary: "<2-4 sentence paragraph>", takeaways: ["...", ...] }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 20 summaries per user per hour. Cheaper than survey generation but still
// worth capping in case a UI loop calls it repeatedly.
check_rate_limit('ai_summary:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$a    = is_array($body['analytics'] ?? null) ? $body['analytics'] : null;
if (!$a) fail('bad_payload', 'Send the analytics object.');

// Sanitize and pull only the fields we need. The AI never sees raw PII.
$title  = clean_string((string)($a['title'] ?? ''), 255);
$n      = (int)($a['n']     ?? 0);
$k      = (int)($a['k']     ?? 0);
$alpha  = (float)($a['alpha']     ?? 0);
$split  = (float)($a['splitHalf'] ?? 0);
$kmo    = (float)($a['kmo']       ?? 0);
$itemsRaw = is_array($a['items'] ?? null) ? $a['items'] : [];
$cautions = is_array($a['cautions'] ?? null) ? $a['cautions'] : [];

if ($n < 2 || $k < 2) {
    fail('insufficient_data', 'Need at least 2 Likert items and 2 complete responses to summarize.');
}

$items = [];
foreach ($itemsRaw as $i => $it) {
    if (!is_array($it)) continue;
    $items[] = [
        'idx'            => $i + 1,
        'prompt'         => clean_string((string)($it['prompt'] ?? ('Item ' . ($i + 1))), 600),
        'mean'           => round((float)($it['mean'] ?? 0), 2),
        'sd'             => round((float)($it['sd']   ?? 0), 2),
        'itemTotalCorr'  => round((float)($it['itemTotalCorr']  ?? 0), 3),
        'alphaIfDeleted' => round((float)($it['alphaIfDeleted'] ?? 0), 3),
        'reverse'        => !empty($it['reverse']),
    ];
}

if (count($items) === 0) {
    fail('insufficient_data', 'No item-level statistics were provided.');
}

$cautionLines = [];
foreach ($cautions as $c) {
    $line = clean_string((string)$c, 400);
    if ($line !== '') $cautionLines[] = $line;
}

$payload = [
    'title'          => $title !== '' ? $title : 'Untitled survey',
    'n'              => $n,
    'k'              => $k,
    'alpha'          => round($alpha, 3),
    'splitHalf'      => round($split, 3),
    'kmo'            => round($kmo, 3),
    'items'          => $items,
    'cautions'       => $cautionLines,
];

$system = <<<SYS
You are a survey methodologist writing a 2-4 sentence executive summary of a reliability and validity report. The user gives you a JSON object with the survey's headline statistics, then a per-item breakdown.

Quality bar:
- Lead with the headline finding in plain English. Use the alpha band: < .60 is weak, .60-.70 questionable, .70-.80 acceptable, .80-.90 good, .90+ excellent (but flag > .95 as possibly redundant items).
- Always include the alpha value and the sample size in parentheses, e.g., "(alpha = 0.87, n = 142)".
- If any item has a corrected item-total correlation below 0.30 OR an "alpha if deleted" value meaningfully higher than the overall alpha (more than +0.02), name that item by its short label and note it is dragging the construct.
- If KMO is below 0.60 or split-half is below 0.60, note that as a separate concern.
- If any cautions are provided in the JSON, weave the most important one into the summary.
- Do not invent statistics. Only cite numbers that appear in the JSON.
- Tone: confident, plain English, no jargon beyond alpha / KMO / item-rest / split-half. Suitable for a board deck.
- Keep the whole summary under 90 words.

After the summary, return a short bulleted "takeaways" array of 2-4 actionable next steps. Each takeaway must be a single sentence under 25 words. Examples: "Drop or revise item 5; corrected r = 0.18.", "Collect at least 30 more responses before reporting publicly.", "Investigate why items 2 and 7 cluster apart from the rest of the scale."

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "summary": "<the paragraph>",
  "takeaways": ["<takeaway 1>", "<takeaway 2>", "..."]
}

Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt = "Survey title: {$payload['title']}\n";
$userPrompt .= "JSON payload:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$userPrompt .= "\n\nWrite the summary and takeaways now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 1500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || empty($parsed['summary'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$summary = clean_string((string)$parsed['summary'], 1500);
$takeaways = [];
if (isset($parsed['takeaways']) && is_array($parsed['takeaways'])) {
    foreach ($parsed['takeaways'] as $t) {
        $line = clean_string((string)$t, 400);
        if ($line !== '') $takeaways[] = $line;
        if (count($takeaways) === 4) break;
    }
}

json_out([
    'ok'        => true,
    'summary'   => $summary,
    'takeaways' => $takeaways,
    'model'     => ai_config()['model'],
]);
