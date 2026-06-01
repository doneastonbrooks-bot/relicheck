<?php
// POST /api/dev/ai-build.php
// Body: { name?, purpose, population?, response_mode? }
// ReliCheck Intelligence drafts a study TAILORED to the stated purpose and
// population: a title, a small construct map, and items whose response formats
// fit each question (qualitative and quantitative treated as equally valid).
// Returns { ok, study: { title, constructs:[{name,definition}], items:[{type,prompt}] } }.
//
// This is the real AI path for the "Have ReliCheck Intelligence Build My Study"
// entry. If no Anthropic key is configured the helper fails with ai_disabled
// (503) and the client falls back to its mock draft.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/../_ai.php';

require_method('POST');
check_origin();
$user = require_auth();

$body       = read_json_body();
$name       = clean_string((string)($body['name'] ?? ''), 200);
$purpose    = clean_string((string)($body['purpose'] ?? ''), 2000);
$population = clean_string((string)($body['population'] ?? ''), 600);
$mode       = clean_string((string)($body['response_mode'] ?? ''), 64);

if ($purpose === '') {
    fail('bad_input', 'A purpose or research question is needed so the study can be tailored to it.');
}

// The supported item-type vocabulary the one-click builder understands.
$types = [
    'Open-Ended', 'Short Answer', 'Long Answer', 'Comment Box',
    'Multiple Choice', 'Checkboxes', 'Dropdown', 'Yes/No', 'True/False',
    'Likert Scale', 'Rating Scale', 'NPS', 'Ranking', 'Slider',
    'Demographic', 'Date', 'Numeric',
];

$system = <<<SYS
You are ReliCheck Intelligence, an expert in survey and instrument design. You
draft a study that is tailored to the SPECIFIC purpose and population given to
you. Never return a generic, one-size-fits-all survey.

Design principles you must follow:
- Be culturally responsive and dignity-centered. Word items so respondents in
  the stated population feel respected and can answer honestly.
- Match reading level and vocabulary to the population.
- Choose the response format that genuinely fits each question. Do NOT default
  to agreement/Likert scales. Open-response and other qualitative items are
  equally valid; mix qualitative and quantitative as the purpose warrants.
- Avoid double-barreled, leading, loaded, or biased items.
- Define each construct (the thing being measured) in one plain sentence, then
  write items that clearly belong to that construct.
- Typical length is 8 to 14 items unless the purpose implies otherwise.

Use ONLY these item type labels (verbatim): {TYPES}.

Return ONLY a JSON object, no prose, in exactly this shape:
{
  "title": "short study title",
  "constructs": [ { "name": "Construct", "definition": "one plain sentence" } ],
  "items": [ { "type": "one of the allowed labels", "prompt": "the question text", "construct": "matching construct name" } ]
}
SYS;
$system = str_replace('{TYPES}', implode(', ', $types), $system);

$userMsg = "Study name: " . ($name !== '' ? $name : '(none given)') . "\n"
         . "Purpose / research question: " . $purpose . "\n"
         . "Target population: " . ($population !== '' ? $population : '(not specified)') . "\n"
         . ($mode !== '' ? "Preferred response style (only if it truly fits): " . $mode . "\n" : '')
         . "\nDraft the tailored study now as JSON.";

$res  = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 3000);
$data = ai_extract_json($res['text']);

if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
    fail('ai_bad_response', 'ReliCheck Intelligence did not return a usable study. Please try again.', 502);
}

// Normalise constructs.
$constructs = [];
if (isset($data['constructs']) && is_array($data['constructs'])) {
    foreach ($data['constructs'] as $c) {
        if (!is_array($c)) continue;
        $cn = clean_string((string)($c['name'] ?? ''), 255);
        if ($cn === '') continue;
        $constructs[] = [
            'name'       => $cn,
            'definition' => clean_string((string)($c['definition'] ?? ''), 2000),
        ];
    }
}

// Normalise items: clamp type to the supported set, require a prompt.
$items = [];
foreach ($data['items'] as $it) {
    if (!is_array($it)) continue;
    $prompt = clean_string((string)($it['prompt'] ?? ''), 4000);
    if ($prompt === '') continue;
    $items[] = [
        'type'      => sds_item_type($it['type'] ?? null),
        'prompt'    => $prompt,
        'construct' => clean_string((string)($it['construct'] ?? ''), 255),
    ];
}

if (!$items) {
    fail('ai_bad_response', 'ReliCheck Intelligence returned no usable items. Please try again.', 502);
}

$title = clean_string((string)($data['title'] ?? ''), 255);
if ($title === '') $title = ($name !== '' ? $name : 'ReliCheck Intelligence Study');

json_out([
    'ok'    => true,
    'study' => [
        'title'      => $title,
        'constructs' => $constructs,
        'items'      => $items,
    ],
]);
