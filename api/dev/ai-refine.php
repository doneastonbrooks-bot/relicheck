<?php
// POST /api/dev/ai-refine.php
// Body: { action: "rewrite"|"clarity"|"review", prompt, type?, purpose?,
//         population?, flags?: [ "rule finding", ... ] }
// Per-question ReliCheck Intelligence help. `rewrite` returns a clearer version;
// `clarity` returns short notes; `review` is the deep, context-aware, stem-first
// item verdict that PAIRS with the deterministic Build Check engine — it is told
// what the rules found (flags) and adds the cultural/contextual judgment a word
// list cannot make. It never switches the response format.
// Returns rewrite/clarity: { ok, rewrite, notes:[ "..." ] }
//         review:          { ok, verdict, notes:[ {dimension,note} ], rewrite }

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/../_ai.php';

require_method('POST');
check_origin();
$user = require_auth();
release_session_lock(); // AI call below — don't hold the session lock.

$body       = read_json_body();
$action     = clean_string((string)($body['action'] ?? 'rewrite'), 32);
$prompt     = clean_string((string)($body['prompt'] ?? ''), 4000);
$type       = clean_string((string)($body['type'] ?? ''), 64);
$purpose    = clean_string((string)($body['purpose'] ?? ''), 2000);
$population = clean_string((string)($body['population'] ?? ''), 600);

// Deterministic rule findings (from the Build Check engine) so the AI can
// confirm/refine them instead of contradicting them.
$flags = [];
if (isset($body['flags']) && is_array($body['flags'])) {
    foreach (array_slice($body['flags'], 0, 12) as $f) {
        $f = clean_string((string)$f, 200);
        if ($f !== '') $flags[] = $f;
    }
}

if ($prompt === '') {
    fail('bad_input', 'Write the question first, then ask ReliCheck Intelligence for help.');
}
if (!in_array($action, ['rewrite', 'clarity', 'review'], true)) {
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
} elseif ($action === 'review') {
    $flagLine = $flags
        ? "An automated rule checker already flagged this item: " . implode('; ', $flags)
          . ". Confirm the real ones in plain language, silently drop any false alarm, and ADD what only reading the item in context reveals."
        : "Read the item fresh and report only the genuine weaknesses.";
    $task = <<<TASK
Review ONE survey item with a STEM-FIRST, FUNCTION-FIRST lens. The response scale
is SECONDARY: judge the STEM (the measurement prompt) on its own. A weak stem
stays weak regardless of multiple choice, dropdown, Likert, numeric, rating, or
demographic format. Do NOT treat the format as evidence that the item is valid.

Evaluate in this order and report ONLY what is weak (say nothing about what is
already fine):
1. answerability — is it actually an answerable item, not a heading, label,
   topic, construct word, verb fragment, placeholder, or metadata field?
2. construct — does the stem name the specific thing being measured, or only
   gesture at a broad concept ("leadership", "safety", "support")?
3. clarity — can a respondent tell what information, judgment, behaviour, belief,
   experience, or perception to report?
4. cultural — is the language culturally and contextually FAIR for the stated
   population? Flag assumed insider or institutional knowledge, dominant-language
   norms, an assumed hierarchy, an assumed education level, or a cultural
   experience some respondents will not share. THIS is where you add the most
   value beyond an automated checker — be specific to the population.

$flagLine

Valid items include statements (Likert), questions, instructional prompts, and
demographic prompts; do NOT demand a question mark. If the item is solid, return
verdict "solid" with an empty notes list.

Return ONLY a JSON object in exactly this shape:
{
  "verdict": "solid" | "revise",
  "notes": [ { "dimension": "answerability|construct|clarity|cultural|scale", "note": "one specific, plain sentence naming the problem" } ],
  "rewrite": "a stronger version of the item that fixes the issues, matched to the population (empty string if the item is already solid)"
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
help someone with ONE survey item they are writing.

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
         . ($type !== '' ? "Response format (secondary): " . $type . "\n" : '')
         . "The item:\n" . $prompt
         . "\n\nRespond as JSON.";

$res  = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 1500);
$data = ai_extract_json($res['text']);

if (!is_array($data)) {
    fail('ai_bad_response', 'ReliCheck Intelligence did not return usable feedback. Please try again.', 502);
}

if ($action === 'review') {
    $verdict = clean_string((string)($data['verdict'] ?? 'revise'), 16);
    if ($verdict !== 'solid') $verdict = 'revise';
    $rewrite = clean_string((string)($data['rewrite'] ?? ''), 4000);

    $allowedDims = ['answerability', 'construct', 'clarity', 'cultural', 'scale'];
    $notes = [];
    if (isset($data['notes']) && is_array($data['notes'])) {
        foreach (array_slice($data['notes'], 0, 8) as $n) {
            if (is_array($n)) {
                $dim  = clean_string((string)($n['dimension'] ?? ''), 24);
                $note = clean_string((string)($n['note'] ?? ''), 600);
            } else {
                $dim = ''; $note = clean_string((string)$n, 600);
            }
            if ($note === '') continue;
            if (!in_array($dim, $allowedDims, true)) $dim = 'clarity';
            $notes[] = ['dimension' => $dim, 'note' => $note];
        }
    }
    json_out([
        'ok'      => true,
        'verdict' => $verdict,
        'notes'   => $notes,
        'rewrite' => $rewrite,
    ]);
}

$rewrite = clean_string((string)($data['rewrite'] ?? ''), 4000);

$notes = [];
if (isset($data['notes']) && is_array($data['notes'])) {
    foreach (array_slice($data['notes'], 0, 6) as $n) {
        $n = clean_string((string)(is_array($n) ? ($n['note'] ?? '') : $n), 600);
        if ($n !== '') $notes[] = $n;
    }
}

json_out([
    'ok'      => true,
    'rewrite' => $action === 'rewrite' ? $rewrite : '',
    'notes'   => $notes,
]);
