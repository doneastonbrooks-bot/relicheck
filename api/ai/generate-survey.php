<?php
// POST /api/ai/generate-survey.php
// Body: {
//   "goal": "I want to measure employee engagement on a hybrid team",
//   "item_count": 10,         (optional, default 10, max 25)
//   "likert_points": 5,       (optional, default 5, allowed 5 or 7)
//   "audience": "employees"   (optional, free text up to 120 chars)
// }
// Returns a fully formed survey object that the front end can either
// preview or push directly into the builder. Nothing is saved server-side.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// Cap at 10 generations per user per hour to keep API costs predictable.
check_rate_limit('ai_gen:user:' . (int)$user['id'], 10, 3600);

$body = read_json_body();
$goal     = clean_string($body['goal']     ?? '', 1000);
$audience = clean_string($body['audience'] ?? '', 120);
$count    = (int)($body['item_count']    ?? 10);
$points   = (int)($body['likert_points'] ?? 5);

if ($goal === '') fail('bad_goal', 'Tell me what you want to measure.');
if ($count < 3)  $count = 3;
if ($count > 25) $count = 25;
if (!in_array($points, [5, 7], true)) $points = 5;

$system = <<<SYS
You are a senior survey methodologist. The user gives you a research goal and you draft a clean, defensible survey.

Quality bar:
- Items are short, single-barreled, and use neutral wording.
- Likert items can be answered on a single agreement scale (the user picks the scale length).
- Mix positively-worded and reverse-coded items where appropriate so respondents can't straight-line.
- Group items by underlying construct in the order you write them, but do not include the construct name inside the prompt text.
- Avoid leading language, jargon, double negatives, and culturally specific idioms.
- Do not number the items in the prompt text. Keep prompts under 25 words.

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "title": "<short title, under 80 chars>",
  "description": "<1-2 sentence intro shown to respondents, under 280 chars>",
  "settings": {
    "likertPoints": <integer, 5 or 7>,
    "likertLow":  "<short low-anchor label, under 40 chars>",
    "likertHigh": "<short high-anchor label, under 40 chars>"
  },
  "questions": [
    {
      "id": "<unique 6-12 char alphanumeric id>",
      "type": "likert" | "single" | "multi" | "open",
      "prompt": "<the question text>",
      "required": true,
      "reverse": <true|false, only meaningful for likert>,
      "options": ["..."]   // only for single/multi
    }
  ],
  "rationale": "<2-3 sentence explanation of how the items map to underlying constructs and why this design will be reliable>"
}

Rules:
- Default question type is "likert" unless the goal clearly needs open-ended or multiple choice.
- Use 1-2 "open" items at the end only if the goal benefits from qualitative feedback.
- Every "id" must be unique and use only lowercase letters and digits.
- Every "required" must be true.
- Do not include any field other than the ones listed above.
- Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt = "Goal: {$goal}\n";
if ($audience !== '') $userPrompt .= "Audience: {$audience}\n";
$userPrompt .= "Item count target: about {$count} items.\n";
$userPrompt .= "Likert scale: {$points}-point.\n";
$userPrompt .= "Generate the survey now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 3000);

$parsed = ai_extract_json($resp['text']);
if (!$parsed) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

// ---------- Sanitize the parsed survey to match the app schema ----------

$title       = clean_string((string)($parsed['title']       ?? ''), 255);
$description = clean_string((string)($parsed['description'] ?? ''), 4000);
$rationale   = clean_string((string)($parsed['rationale']   ?? ''), 1000);

$settings = [];
if (isset($parsed['settings']) && is_array($parsed['settings'])) {
    $s = $parsed['settings'];
    $sp = (int)($s['likertPoints'] ?? $points);
    if ($sp < 2 || $sp > 11) $sp = $points;
    $settings = [
        'likertPoints' => $sp,
        'likertLow'    => clean_string((string)($s['likertLow']  ?? 'Strongly disagree'), 80),
        'likertHigh'   => clean_string((string)($s['likertHigh'] ?? 'Strongly agree'),    80),
    ];
} else {
    $settings = [
        'likertPoints' => $points,
        'likertLow'    => 'Strongly disagree',
        'likertHigh'   => 'Strongly agree',
    ];
}

$questions = [];
$seenIds = [];
if (isset($parsed['questions']) && is_array($parsed['questions'])) {
    foreach ($parsed['questions'] as $q) {
        if (!is_array($q)) continue;
        $type = $q['type'] ?? 'likert';
        if (!in_array($type, ['likert','single','multi','open'], true)) continue;

        $rawId = preg_replace('/[^a-z0-9]/i', '', (string)($q['id'] ?? ''));
        if ($rawId === '') $rawId = bin2hex(random_bytes(4));
        $id = strtolower(substr($rawId, 0, 12));
        if (isset($seenIds[$id])) {
            // Make it unique
            $id = $id . substr(bin2hex(random_bytes(2)), 0, 4);
        }
        $seenIds[$id] = true;

        $entry = [
            'id'       => $id,
            'type'     => $type,
            'prompt'   => clean_string((string)($q['prompt'] ?? ''), 4000),
            'required' => true,
        ];
        if ($entry['prompt'] === '') continue;

        if ($type === 'likert') {
            $entry['reverse'] = !empty($q['reverse']);
        }
        if ($type === 'single' || $type === 'multi') {
            $opts = [];
            if (isset($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $o) {
                    $opt = clean_string((string)$o, 500);
                    if ($opt !== '') $opts[] = $opt;
                }
            }
            // Need at least 2 options for choice questions; otherwise downgrade to open.
            if (count($opts) < 2) {
                $entry['type'] = 'open';
            } else {
                $entry['options'] = $opts;
            }
        }
        $questions[] = $entry;
    }
}

if (count($questions) === 0) {
    fail('ai_empty_result', 'AI did not produce any usable questions. Try a more specific goal.', 502);
}

json_out([
    'ok' => true,
    'survey' => [
        'title'       => $title,
        'description' => $description,
        'settings'    => $settings,
        'questions'   => $questions,
    ],
    'rationale' => $rationale,
    'model'     => ai_config()['model'],
]);
