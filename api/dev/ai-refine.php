<?php
// POST /api/dev/ai-refine.php
// Body: { action: "rewrite"|"clarity", prompt, type?, purpose?, population? }
// Per-question ReliCheck Intelligence help for the "Create Survey with ReliCheck
// Intelligence" path. The user writes ONE question; this either rewrites it more
// clearly (action=rewrite) or returns clarity/bias notes (action=clarity). It
// never changes what is being asked or its response format.
// Returns { ok, rewrite:"" , notes:[ "..." ] }.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/../_ai.php';

require_method('POST');
check_origin();
$user = require_auth();

$body       = read_json_body();
$action     = clean_string((string)($body['action'] ?? 'rewrite'), 32);
$prompt     = clean_string((string)($body['prompt'] ?? ''), 4000);
$type       = clean_string((string)($body['type'] ?? ''), 64);
$purpose    = clean_string((string)($body['purpose'] ?? ''), 2000);
$population = clean_string((string)($body['population'] ?? ''), 600);

if ($prompt === '') {
    fail('bad_input', 'Write the question first, then ask ReliCheck Intelligence for help.');
}
if ($action !== 'rewrite' && $action !== 'clarity') {
    $action = 'rewrite';
}

if ($action === 'rewrite') {
    $task = <<<TASK
Rewrite the question so it is clearer and easier to answer. Keep the SAME thing
being asked and the SAME response format; do not turn it into a different
question or switch it to an agreement/Likert scale. Fix double-barreled, leading,
loaded, or biased wording, and match the reading level to the population. If the
question is already strong, you may return it nearly unchanged.

Return ONLY a JSON object in exactly this shape:
{
  "rewrite": "the improved question text",
  "notes": [ "one short note on what you changed and why" ]
}
TASK;
} else {
    $task = <<<TASK
Do NOT rewrite the question. Review it and return short, plain-language notes on
its clarity: whether it asks one thing (not double-barreled), whether wording is
leading/loaded/biased, whether the reading level fits the population, and whether
a respondent would interpret it the way intended. If it is clear, say so briefly.

Return ONLY a JSON object in exactly this shape:
{
  "rewrite": "",
  "notes": [ "a short clarity note", "another" ]
}
TASK;
}

$system = <<<SYS
You are ReliCheck Intelligence, an expert in survey and instrument design. You
help someone with ONE survey question they are writing.

Principles:
- Be culturally responsive and dignity-centered; respondents in the stated
  population should feel respected and able to answer honestly.
- Treat qualitative (open-response) and quantitative formats as equally valid.
  Never push an agreement/Likert scale.
- Be concise and concrete. No preamble.

{TASK}
SYS;
$system = str_replace('{TASK}', $task, $system);

$userMsg = "Target population: " . ($population !== '' ? $population : '(not specified)') . "\n"
         . "Study purpose: " . ($purpose !== '' ? $purpose : '(not specified)') . "\n"
         . ($type !== '' ? "Response format: " . $type . "\n" : '')
         . "The question being written:\n" . $prompt
         . "\n\nRespond as JSON.";

$res  = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 1200);
$data = ai_extract_json($res['text']);

if (!is_array($data)) {
    fail('ai_bad_response', 'ReliCheck Intelligence did not return usable feedback. Please try again.', 502);
}

$rewrite = clean_string((string)($data['rewrite'] ?? ''), 4000);

$notes = [];
if (isset($data['notes']) && is_array($data['notes'])) {
    foreach (array_slice($data['notes'], 0, 6) as $n) {
        $n = clean_string((string)$n, 600);
        if ($n !== '') $notes[] = $n;
    }
}

json_out([
    'ok'      => true,
    'rewrite' => $action === 'rewrite' ? $rewrite : '',
    'notes'   => $notes,
]);
