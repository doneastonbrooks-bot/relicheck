<?php
// POST /api/ai/extract-themes.php
// Body: { "survey_id": <int>, "question_id": "<string>" }
//
// Loads all open-ended responses for the given question, sends them to the
// model, and returns 3-8 themes with counts and example quotes.
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

// 15 theme runs per user per hour. Each call can be larger than other AI
// features, so the cap is a bit tighter.
check_rate_limit('ai_themes:user:' . (int)$user['id'], 15, 3600);

$body       = read_json_body();
$surveyId   = (int)($body['survey_id'] ?? 0);
$questionId = clean_string((string)($body['question_id'] ?? ''), 32);

if ($surveyId <= 0)     fail('bad_id', 'Missing or invalid survey_id.');
if ($questionId === '') fail('bad_id', 'Missing or invalid question_id.');

$pdo = db();

// Owner check + load the question definition so we can confirm it is open-ended.
$stmt = $pdo->prepare('SELECT id, owner_id, title, questions FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$survey = $stmt->fetch();
if (!$survey)                                  fail('not_found', 'Survey not found.', 404);
if ((int)$survey['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$questions = json_decode((string)$survey['questions'], true);
if (!is_array($questions)) $questions = [];

$question = null;
foreach ($questions as $q) {
    if (is_array($q) && (string)($q['id'] ?? '') === $questionId) { $question = $q; break; }
}
if (!$question) fail('not_found', 'Question not found in this survey.', 404);
if (($question['type'] ?? '') !== 'open') fail('bad_type', 'Theme extraction only works on open-ended questions.');

$prompt = (string)($question['prompt'] ?? '');

// Pull all responses and collect non-empty text answers for this question.
$rstmt = $pdo->prepare('SELECT answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC');
$rstmt->execute([':sid' => $surveyId]);

$texts = [];
while ($r = $rstmt->fetch()) {
    $a = json_decode((string)$r['answers'], true);
    if (!is_array($a)) continue;
    $val = $a[$questionId] ?? null;
    if (!is_string($val)) continue;
    $val = trim($val);
    if ($val === '') continue;
    // Cap each answer at 800 chars to keep total size predictable.
    if (strlen($val) > 800) $val = substr($val, 0, 800) . '...';
    $texts[] = $val;
}

if (count($texts) < 3) {
    fail('insufficient_data', 'Need at least 3 open-ended responses before themes are meaningful.');
}

// Cap the number of responses sent to keep token costs bounded.
$maxResponses = 250;
if (count($texts) > $maxResponses) {
    // Sample evenly across the dataset so themes reflect the full distribution.
    $step = (int)floor(count($texts) / $maxResponses);
    $sampled = [];
    for ($i = 0; $i < count($texts); $i += $step) {
        $sampled[] = $texts[$i];
        if (count($sampled) >= $maxResponses) break;
    }
    $texts = $sampled;
}

// Build the numbered list of responses for the model.
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
    'question_id'     => $questionId,
    'total_responses' => $totalCount,
    'themes'          => $themes,
    'notes'           => $notes,
    'model'           => ai_config()['model'],
]);
