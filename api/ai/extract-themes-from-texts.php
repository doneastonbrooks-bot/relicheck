<?php
// POST /api/ai/extract-themes-from-texts.php
// Body: { "texts": ["...", "..."], "prompt": "<optional question text>" }
//
// Stateless theme extraction. Accepts a raw array of open-text answers and
// returns 3-8 themes with counts and example quotes. Used by dataset analytics
// where the texts live in the client (datasets.data column) rather than the
// responses table, so the existing extract-themes.php (which looks rows up by
// survey_id+question_id) cannot serve them.
//
// Additive: parallels extract-themes.php without modifying it. The two share
// the same prompt and output shape so the client can render both with one
// renderer (renderThemesHtml).
//
// The model never sees response IDs, timestamps, or any other PII. Only the
// raw text answers (truncated to keep token use predictable).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// Same per-user budget as the survey-side endpoint so a user can't bypass the
// cap by switching between dataset and survey calls.
check_rate_limit('ai_themes:user:' . (int)$user['id'], 15, 3600);

$body   = read_json_body();
$prompt = clean_string((string)($body['prompt'] ?? ''), 600);
$rawTexts = $body['texts'] ?? null;

if (!is_array($rawTexts)) {
    fail('bad_input', 'Missing or invalid "texts" array.');
}

// Coerce to strings, trim, drop empties, cap each at 800 chars to keep total
// payload predictable. Same cap as the survey-side endpoint.
$texts = [];
foreach ($rawTexts as $t) {
    if (!is_string($t)) continue;
    $val = trim($t);
    if ($val === '') continue;
    if (strlen($val) > 800) $val = substr($val, 0, 800) . '...';
    $texts[] = $val;
}

if (count($texts) < 3) {
    fail('insufficient_data', 'Need at least 3 open-ended responses before themes are meaningful.');
}

// Cap total responses sent to keep token costs bounded. Sample evenly so the
// themes reflect the full distribution (same approach as extract-themes.php).
$maxResponses = 250;
if (count($texts) > $maxResponses) {
    $step = (int)floor(count($texts) / $maxResponses);
    if ($step < 1) $step = 1;
    $sampled = [];
    for ($i = 0; $i < count($texts); $i += $step) {
        $sampled[] = $texts[$i];
        if (count($sampled) >= $maxResponses) break;
    }
    $texts = $sampled;
}

$lines = [];
foreach ($texts as $i => $t) {
    $lines[] = ($i + 1) . '. ' . $t;
}
$responseBlock = implode("\n", $lines);
$totalCount    = count($texts);

$system = <<<SYS
You are a qualitative researcher. The user gives you a question and a numbered list of open-ended responses. Group the responses into 3-8 distinct themes.

Quality bar:
- A theme is a recurring idea, not a paraphrase of one response. If only one or two responses fit, do not promote it to a theme; instead group them under a broader catch-all.
- Theme names are short noun phrases, 2-5 words, sentence case. Examples: "Workload imbalance", "Lack of recognition", "Praise for the new tooling".
- Counts must equal the number of distinct response numbers assigned to that theme. Each response goes to exactly one theme.
- The sum of all theme counts must equal the total number of responses provided.
- Order themes from most to least common.
- For each theme, include 1-3 short example quotes (each under 30 words) drawn verbatim from the responses, plus a one-sentence summary of what unifies the theme.
- Do not invent responses. Only use the text the user provided.
- Sentiment is one of: positive, neutral, negative, mixed.

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "themes": [
    {
      "name":      "<short theme name>",
      "count":     <integer>,
      "sentiment": "positive" | "neutral" | "negative" | "mixed",
      "summary":   "<one sentence on what unifies this theme>",
      "examples":  ["<short verbatim quote>", "<short verbatim quote>"]
    }
  ],
  "total_responses": <integer>,
  "notes": "<optional one-sentence overall observation, or empty string>"
}

Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt  = "Question: " . ($prompt !== '' ? $prompt : '(no prompt provided)') . "\n";
$userPrompt .= "Total responses: " . $totalCount . "\n\n";
$userPrompt .= "Responses:\n" . $responseBlock . "\n\n";
$userPrompt .= "Cluster these into themes now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 3500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['themes']) || !is_array($parsed['themes'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$themes = [];
foreach ($parsed['themes'] as $t) {
    if (!is_array($t)) continue;
    $name      = clean_string((string)($t['name']      ?? ''), 120);
    $summary   = clean_string((string)($t['summary']   ?? ''), 600);
    $count     = (int)($t['count']     ?? 0);
    $sentiment = strtolower(clean_string((string)($t['sentiment'] ?? 'neutral'), 16));
    if (!in_array($sentiment, ['positive','neutral','negative','mixed'], true)) $sentiment = 'neutral';
    if ($name === '' || $count <= 0) continue;
    $examples = [];
    if (isset($t['examples']) && is_array($t['examples'])) {
        foreach ($t['examples'] as $ex) {
            $exClean = clean_string((string)$ex, 600);
            if ($exClean !== '') $examples[] = $exClean;
            if (count($examples) === 3) break;
        }
    }
    $themes[] = [
        'name'      => $name,
        'count'     => $count,
        'sentiment' => $sentiment,
        'summary'   => $summary,
        'examples'  => $examples,
    ];
    if (count($themes) === 8) break;
}

if (count($themes) === 0) {
    fail('ai_empty_result', 'AI did not return any themes. Try again.', 502);
}

$notes = clean_string((string)($parsed['notes'] ?? ''), 600);

json_out([
    'ok'              => true,
    'total_responses' => $totalCount,
    'themes'          => $themes,
    'notes'           => $notes,
    'model'           => ai_config()['model'],
]);
