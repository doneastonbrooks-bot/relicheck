<?php
// POST /api/dev/ai-suggest.php
// Body: { purpose?, population?, draft?, existing?: [ "prompt", ... ] }
// Live ReliCheck Intelligence assist for the "Create Survey with ReliCheck
// Intelligence" path. Suggests a few next survey items that fill gaps without
// duplicating what is already written, plus analysis questions to examine once
// data is collected. Format-neutral: qualitative and quantitative are equal.
// Returns { ok, questions:[{type,prompt}], analyses:[ "..." ] }.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/../_ai.php';

require_method('POST');
check_origin();
$user = require_auth();

$body       = read_json_body();
$purpose    = clean_string((string)($body['purpose'] ?? ''), 2000);
$population = clean_string((string)($body['population'] ?? ''), 600);
$draft      = clean_string((string)($body['draft'] ?? ''), 4000);

$existing = [];
if (isset($body['existing']) && is_array($body['existing'])) {
    foreach (array_slice($body['existing'], 0, 60) as $p) {
        $p = clean_string((string)$p, 600);
        if ($p !== '') $existing[] = $p;
    }
}

$types = [
    'Open-Ended', 'Short Answer', 'Long Answer', 'Comment Box',
    'Multiple Choice', 'Checkboxes', 'Dropdown', 'Yes/No', 'True/False',
    'Likert Scale', 'Rating Scale', 'NPS', 'Ranking', 'Slider',
    'Demographic', 'Date', 'Numeric',
];

$system = <<<SYS
You are ReliCheck Intelligence, an expert in survey and instrument design,
helping someone build a survey item by item. You give live, specific
suggestions for what to add next.

Follow these principles:
- Be culturally responsive and dignity-centered; match reading level to the
  population.
- Choose the response format that genuinely fits each suggested question. Do
  NOT default to agreement/Likert scales. Qualitative (open-response) items are
  equally valid.
- Do not duplicate questions the user has already written; fill gaps and cover
  parts of the purpose that are still missing.
- Avoid double-barreled, leading, or biased wording.

Provide two things:
1) "questions": 3 to 5 suggested next survey items (type + prompt).
2) "analyses": 3 to 5 analysis questions worth examining once responses are in.
   Include qualitative analyses (e.g., theming open responses) as well as
   quantitative ones; keep them tied to the stated purpose.

Use ONLY these item type labels (verbatim): {TYPES}.

Return ONLY a JSON object, no prose, in exactly this shape:
{
  "questions": [ { "type": "allowed label", "prompt": "question text" } ],
  "analyses":  [ "an analysis question", "another" ]
}
SYS;
$system = str_replace('{TYPES}', implode(', ', $types), $system);

$userMsg = "Purpose / research question: " . ($purpose !== '' ? $purpose : '(not specified)') . "\n"
         . "Target population: " . ($population !== '' ? $population : '(not specified)') . "\n"
         . ($draft !== '' ? "Question currently being drafted: " . $draft . "\n" : '')
         . "Questions written so far:\n"
         . ($existing ? "- " . implode("\n- ", $existing) : "(none yet)")
         . "\n\nSuggest next items and analysis questions as JSON.";

$res  = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 1800);
$data = ai_extract_json($res['text']);

if (!is_array($data)) {
    fail('ai_bad_response', 'ReliCheck Intelligence did not return usable suggestions. Please try again.', 502);
}

$questions = [];
if (isset($data['questions']) && is_array($data['questions'])) {
    foreach ($data['questions'] as $q) {
        if (!is_array($q)) continue;
        $prompt = clean_string((string)($q['prompt'] ?? ''), 4000);
        if ($prompt === '') continue;
        $questions[] = [
            'type'   => sds_item_type($q['type'] ?? null),
            'prompt' => $prompt,
        ];
    }
}

$analyses = [];
if (isset($data['analyses']) && is_array($data['analyses'])) {
    foreach (array_slice($data['analyses'], 0, 8) as $a) {
        $a = clean_string((string)$a, 600);
        if ($a !== '') $analyses[] = $a;
    }
}

json_out([
    'ok'        => true,
    'questions' => $questions,
    'analyses'  => $analyses,
]);
