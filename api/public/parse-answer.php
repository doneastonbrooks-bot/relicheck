<?php
// POST /api/public/parse-answer.php
// Body: { slug, question_id, user_text }
//
// Public endpoint. Used by the conversational take mode (Phase 120). The
// respondent types a natural-language reply; the AI maps it to the
// structured answer for the question (Likert int / single index / multi
// indices / open text) and returns a short confirmation message.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
// No check_origin: public take page may be embedded.

$body      = read_json_body();
$slug      = is_string($body['slug'] ?? null) ? $body['slug'] : '';
$qid       = is_string($body['question_id'] ?? null) ? $body['question_id'] : '';
$userText  = is_string($body['user_text'] ?? null) ? $body['user_text'] : '';
$userText  = trim($userText);

if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    fail('bad_slug', 'Invalid slug.', 400);
}
if ($qid === '' || strlen($qid) > 64) {
    fail('bad_qid', 'Invalid question_id.', 400);
}
if ($userText === '') {
    fail('empty', 'Type a reply first.', 400);
}
if (strlen($userText) > 2000) {
    $userText = substr($userText, 0, 2000);
}

// Cheap per-IP rate limit to keep AI cost bounded.
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
check_rate_limit('ai_parse_answer:ip:' . $ip, 240, 3600); // 240 calls/hour/IP

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, settings, questions, is_published
       FROM surveys WHERE slug = :slug LIMIT 1'
);
$stmt->execute([':slug' => $slug]);
$survey = $stmt->fetch();
if (!$survey) fail('not_found', 'No survey at that link.', 404);
if (!(int)$survey['is_published']) fail('not_published', 'This survey is not open.', 410);

$questions = json_decode((string)$survey['questions'], true) ?: [];
$settings  = json_decode((string)$survey['settings'],  true) ?: [];

// Locate the question by id.
$q = null;
foreach ($questions as $cand) {
    if (isset($cand['id']) && (string)$cand['id'] === $qid) { $q = $cand; break; }
}
if (!$q) fail('q_missing', 'Question not found on this survey.', 404);

$qType   = isset($q['type']) ? (string)$q['type'] : 'open';
$qPrompt = isset($q['prompt']) ? (string)$q['prompt'] : '(question)';

// Build the question context for the AI extraction prompt.
$points = max(2, min(11, (int)($settings['likertPoints'] ?? 5)));
if (isset($q['likertPoints']) && (int)$q['likertPoints'] >= 2) $points = (int)$q['likertPoints'];
$lkLow  = (string)($q['likertLow']  ?? $settings['likertLow']  ?? 'Strongly disagree');
$lkHigh = (string)($q['likertHigh'] ?? $settings['likertHigh'] ?? 'Strongly agree');
$options = (isset($q['options']) && is_array($q['options'])) ? array_values($q['options']) : [];

$contextLines = [];
$contextLines[] = 'QUESTION_TYPE: ' . $qType;
$contextLines[] = 'QUESTION_PROMPT: ' . $qPrompt;
if ($qType === 'likert') {
    $contextLines[] = 'LIKERT_POINTS: ' . $points;
    $contextLines[] = 'LOW_ANCHOR (' . $points . '-point scale value 1): ' . $lkLow;
    $contextLines[] = 'HIGH_ANCHOR (' . $points . '-point scale value ' . $points . '): ' . $lkHigh;
    $contextLines[] = 'OUTPUT a JSON object: {"kind":"likert","value":<int 1..' . $points . '>,"confidence":"high|medium|low","message":"<one sentence confirming what you recorded, in friendly second person>"}';
} elseif ($qType === 'single' || $qType === 'multi') {
    $optList = '';
    foreach ($options as $i => $opt) {
        $optList .= "\n  " . $i . ': ' . (string)$opt;
    }
    $contextLines[] = 'OPTIONS (zero-indexed):' . ($optList ?: ' (none provided)');
    if ($qType === 'single') {
        $contextLines[] = 'OUTPUT a JSON object: {"kind":"single","value":<int index>,"confidence":"high|medium|low","message":"<one sentence confirming>"}';
    } else {
        $contextLines[] = 'OUTPUT a JSON object: {"kind":"multi","value":[<int index>, ...],"confidence":"high|medium|low","message":"<one sentence confirming>"}';
    }
} else {
    $contextLines[] = 'OUTPUT a JSON object: {"kind":"open","value":"<the respondent\'s words, lightly cleaned>","confidence":"high","message":"<one sentence acknowledging>"}';
}

$system = "You map a survey respondent's free-text reply to the structured answer expected by the question.\n"
        . "Rules:\n"
        . "- Read the question context. Read the respondent's reply. Return JSON only, no prose around it.\n"
        . "- For Likert: pick the single integer 1..N that best matches the reply's sentiment, anchored by the low/high labels.\n"
        . "- For single-choice: pick the integer index of the closest option. If the reply does not match any option, return confidence \"low\" and your best guess.\n"
        . "- For multi-choice: return an array of integer indices the reply selects. If the reply mentions everything, return all indices.\n"
        . "- For open: return the respondent's reply, lightly cleaned (capitalize first letter, remove leading filler like \"um\", trim whitespace). Do not invent content.\n"
        . "- The message field is ONE friendly second-person sentence confirming what you recorded. No emojis. No questions; just the confirmation.\n"
        . "- If the reply is ambiguous, low-confidence, or off-topic, still pick the best mapping and set confidence accordingly.\n"
        . "- Never include explanations outside the JSON.";

$user = "QUESTION CONTEXT:\n" . implode("\n", $contextLines)
      . "\n\nRESPONDENT REPLY:\n" . $userText
      . "\n\nReturn ONLY the JSON object.";

try {
    $res = ai_complete($system, [['role' => 'user', 'content' => $user]], 400);
} catch (Throwable $e) {
    fail('ai_failed', 'Could not parse the answer.', 502);
}

$text = isset($res['text']) ? (string)$res['text'] : '';
// Strip code fences if the model wraps the JSON.
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', (string)$text);
$text = trim((string)$text);

$parsed = json_decode($text, true);
if (!is_array($parsed) || !isset($parsed['kind']) || !array_key_exists('value', $parsed)) {
    // Soft-fail with the question type echoed back so the client can decide.
    json_out([
        'ok'      => false,
        'reason'  => 'unparseable_ai_reply',
        'message' => 'I could not match that to the question; try a clearer reply.',
    ]);
}

// Validate the value matches the question shape.
$kind = (string)$parsed['kind'];
$value = $parsed['value'];
$confidence = isset($parsed['confidence']) ? (string)$parsed['confidence'] : 'medium';
$message = isset($parsed['message']) ? (string)$parsed['message'] : '';

if ($qType === 'likert') {
    $v = is_numeric($value) ? (int)$value : 0;
    if ($v < 1 || $v > $points) {
        json_out([
            'ok' => false, 'reason' => 'out_of_range',
            'message' => 'Pick a number between 1 (' . $lkLow . ') and ' . $points . ' (' . $lkHigh . ').',
        ]);
    }
    $value = $v;
} elseif ($qType === 'single') {
    $v = is_numeric($value) ? (int)$value : -1;
    if ($v < 0 || $v >= count($options)) {
        json_out([
            'ok' => false, 'reason' => 'out_of_range',
            'message' => 'I could not match that to one of the options. Try naming an option.',
        ]);
    }
    $value = $v;
} elseif ($qType === 'multi') {
    if (!is_array($value)) {
        json_out(['ok' => false, 'reason' => 'bad_shape', 'message' => 'I expected one or more options. Try listing them.']);
    }
    $clean = [];
    foreach ($value as $iv) {
        $vi = is_numeric($iv) ? (int)$iv : -1;
        if ($vi >= 0 && $vi < count($options) && !in_array($vi, $clean, true)) $clean[] = $vi;
    }
    $value = $clean;
} else {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') $value = trim($userText);
    if (strlen($value) > 2000) $value = substr($value, 0, 2000);
}

if ($message === '') {
    $message = 'Got it.';
}
if (strlen($message) > 320) $message = substr($message, 0, 320);

json_out([
    'ok'         => true,
    'kind'       => $kind,
    'value'      => $value,
    'confidence' => in_array($confidence, ['high','medium','low'], true) ? $confidence : 'medium',
    'message'    => $message,
]);
