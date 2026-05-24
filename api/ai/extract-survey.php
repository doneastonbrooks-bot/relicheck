<?php
// POST /api/ai/extract-survey.php
// Body: { "text": "<pasted survey text or .qsf JSON content>" }
//
// Accepts arbitrary survey content (pasted from SurveyMonkey preview,
// Qualtrics .qsf JSON, Word/PDF text, or anywhere else) and returns a
// normalized ReliCheck question schema:
//   {
//     ok: true,
//     title: string,
//     description: string,
//     questions: [
//       { type: "likert"|"single"|"multi"|"open",
//         prompt: string,
//         options?: [string, ...],     // for single/multi
//         likertPoints?: 2..11,         // for likert
//         likertLow?: string,           // for likert
//         likertHigh?: string,          // for likert
//         reverse?: boolean             // for likert (mark obvious reverse-coded items)
//       }, ...
//     ]
//   }
//
// Privacy: the model receives only the survey text the user submitted.
// No response data is sent.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// Same budget bucket as other lightweight survey-helper endpoints.
check_rate_limit('ai_extract_survey:user:' . (int)$user['id'], 12, 3600);

$body = read_json_body();
$raw  = (string)($body['text'] ?? '');
$raw  = trim($raw);

if ($raw === '') {
    fail('bad_input', 'Paste your survey text (or the contents of a Qualtrics .qsf file).');
}
if (strlen($raw) > 120000) {
    fail('too_large', 'Survey content is too long. Paste up to ~120,000 characters at a time.');
}

// If it parses as JSON (Qualtrics .qsf is JSON), we let the model know so
// it can extract the SurveyElements payload cleanly. Otherwise we just
// pass the text along.
$lookLikeJson = false;
$json = json_decode($raw, true);
if (is_array($json) && (isset($json['SurveyElements']) || isset($json['SurveyEntry']))) {
    $lookLikeJson = true;
}

$system = <<<SYS
You are a survey-design assistant. The user pastes the content of a survey (or the JSON payload of a Qualtrics .qsf file) and you convert it to a clean ReliCheck question schema.

Output format: respond with a single JSON object only, no prose, no markdown fences, no commentary:

{
  "title":       "<survey title, or '' if not stated>",
  "description": "<one-line description, or ''>",
  "questions": [
    {
      "type":         "likert" | "single" | "multi" | "open",
      "prompt":       "<the question text>",
      "options":      ["<option 1>", "<option 2>", ...],   // ONLY for type=single or type=multi
      "likertPoints": 5,                                     // ONLY for type=likert. Integer in 2..11
      "likertLow":    "Strongly disagree",                   // ONLY for type=likert. Label of the lowest anchor.
      "likertHigh":   "Strongly agree",                      // ONLY for type=likert. Label of the highest anchor.
      "reverse":      false                                  // ONLY for type=likert. True ONLY when the item is clearly reverse-worded.
    },
    ...
  ]
}

Rules:
- Detect Likert-style items by language. "Strongly disagree to Strongly agree", "Never to Always", "Not at all to Extremely", "1 to 5", "rate from 1 to 7", etc. all imply type="likert". When the scale is symmetric and runs from a negative anchor to a positive anchor, set likertLow and likertHigh to those anchors and likertPoints to the count of options. Default to 5 points if the count is not obvious.
- Detect single-answer multiple choice (e.g., demographic questions, "select one") as type="single".
- Detect multi-answer multiple choice ("select all that apply", "choose any") as type="multi".
- Detect free-response items ("describe", "explain", "in your own words", "any additional comments") as type="open".
- If a question is clearly negatively worded relative to the construct it measures, mark reverse=true. Examples: "I feel disconnected from my team." in an engagement scale. Conservative bias: when in doubt leave reverse out.
- Combine repeated Likert items that share the SAME anchors into separate question entries, but only include the full anchors on each one (keep the schema flat).
- Do NOT invent questions. Only emit items that are present in the input.
- Do NOT include demographic prefaces, instructions, page breaks, or section headers as questions.
- Trim prompts to remove trailing scale text (e.g., a prompt should be "I feel motivated by my work." not "I feel motivated by my work. (1=Strongly disagree to 5=Strongly agree)").
- Maximum 80 questions per response. If the input has more, return the first 80 with a description that notes the truncation.

Be conservative. A clean structured output that captures 80% of the survey is far more useful than a guess that fabricates the rest.
SYS;

$userPrompt  = $lookLikeJson
    ? "Below is the JSON payload of a Qualtrics .qsf survey export. Extract the questions into the ReliCheck schema described above.\n\n"
    : "Below is the text of a survey. Extract the questions into the ReliCheck schema described above.\n\n";
$userPrompt .= $raw;

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 4500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['questions']) || !is_array($parsed['questions'])) {
    fail('ai_parse_failed', 'AI could not parse the survey content. Try again, or paste fewer questions.', 502);
}

// Clean the result before handing it to the client.
$out = [
    'title'       => clean_string((string)($parsed['title']       ?? ''), 200),
    'description' => clean_string((string)($parsed['description'] ?? ''), 600),
    'questions'   => [],
];

$allowed = ['likert', 'single', 'multi', 'open'];
foreach ($parsed['questions'] as $q) {
    if (!is_array($q)) continue;
    $type   = strtolower(clean_string((string)($q['type'] ?? ''), 16));
    if (!in_array($type, $allowed, true)) continue;
    $prompt = clean_string((string)($q['prompt'] ?? ''), 600);
    if ($prompt === '') continue;

    $entry = [
        'type'   => $type,
        'prompt' => $prompt,
    ];

    if ($type === 'likert') {
        $pts = (int)($q['likertPoints'] ?? 5);
        if ($pts < 2)  $pts = 2;
        if ($pts > 11) $pts = 11;
        $entry['likertPoints'] = $pts;
        $entry['likertLow']    = clean_string((string)($q['likertLow']  ?? 'Strongly disagree'), 60);
        $entry['likertHigh']   = clean_string((string)($q['likertHigh'] ?? 'Strongly agree'),    60);
        $entry['reverse']      = !empty($q['reverse']);
    } elseif ($type === 'single' || $type === 'multi') {
        $opts = [];
        if (isset($q['options']) && is_array($q['options'])) {
            foreach ($q['options'] as $o) {
                $val = clean_string((string)$o, 200);
                if ($val !== '') $opts[] = $val;
                if (count($opts) >= 20) break;
            }
        }
        if (count($opts) < 2) continue; // need at least 2 options to be meaningful
        $entry['options'] = $opts;
    }

    $out['questions'][] = $entry;
    if (count($out['questions']) >= 80) break;
}

if (count($out['questions']) === 0) {
    fail('ai_empty_result', 'No questions were detected. Check that the pasted content actually contains survey questions.', 502);
}

json_out([
    'ok'          => true,
    'title'       => $out['title'],
    'description' => $out['description'],
    'questions'   => $out['questions'],
    'model'       => ai_config()['model'],
]);
