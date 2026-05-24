<?php
// POST /api/ai/translate-survey.php
// Body: {
//   "survey_id": <int>,
//   "language":  "Spanish" | "French" | "German" | ... (any language name)
// }
// Creates a new (unpublished) survey row whose title, description, scale
// anchors, question prompts, and choice options have all been translated by
// the AI. The original survey is left alone. Returns the new survey row.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 10 translations per user per hour. Each call is cheap but creates a new
// survey row, so we cap to keep the database tidy.
check_rate_limit('ai_translate:user:' . (int)$user['id'], 10, 3600);

$body     = read_json_body();
$surveyId = (int)($body['survey_id'] ?? 0);
$language = clean_string((string)($body['language'] ?? ''), 80);

if ($surveyId <= 0)  fail('bad_id', 'Missing or invalid survey_id.');
if ($language === '') fail('bad_language', 'Pick a target language.');

$pdo = db();

$stmt = $pdo->prepare('SELECT owner_id, title, description, settings, questions FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

// Tier check: survey count limit
$current = (int)$pdo->query('SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . (int)$user['id'])->fetch()['c'];
require_under_limit((int)$user['id'], 'max_surveys', $current);

$origTitle    = (string)$row['title'];
$origDesc     = (string)$row['description'];
$origSettings = json_decode((string)$row['settings'],  true) ?: default_survey_settings();
$origQuestions = json_decode((string)$row['questions'], true) ?: [];

// Build a compact, machine-friendly translation payload. We only send the
// strings that need translating; ids, types, and other structural fields
// stay untouched on our side.
$payload = [
    'language'    => $language,
    'title'       => $origTitle,
    'description' => $origDesc,
    'likertLow'   => (string)($origSettings['likertLow']  ?? ''),
    'likertHigh'  => (string)($origSettings['likertHigh'] ?? ''),
    'thankYou'    => (string)($origSettings['thankYou']   ?? ''),
    'questions'   => array_map(function ($q) {
        $item = [
            'id'      => (string)($q['id']     ?? ''),
            'type'    => (string)($q['type']   ?? ''),
            'prompt'  => (string)($q['prompt'] ?? ''),
        ];
        if (isset($q['likertLow']))  $item['likertLow']  = (string)$q['likertLow'];
        if (isset($q['likertHigh'])) $item['likertHigh'] = (string)$q['likertHigh'];
        if (isset($q['options']) && is_array($q['options'])) {
            $item['options'] = array_map(function ($o) { return (string)$o; }, $q['options']);
        }
        return $item;
    }, $origQuestions),
];

$system = <<<SYS
You are a professional survey translator. The user gives you a JSON object representing a survey in some source language and a target language to translate it into.

Translate every human-readable string into the target language while preserving:
- The exact JSON shape and key names.
- All ids unchanged (do not translate ids).
- The "type" field unchanged (likert, single, multi, open).
- The order and number of questions and options.
- The intent and tone of each item. Do not summarize, expand, or editorialize.
- Survey-research conventions in the target language for Likert scale anchors (use the standard equivalents of "Strongly disagree" / "Strongly agree" rather than literal word-for-word translations).
- Idioms and culturally specific phrasing should be adapted, not translated literally, when a literal translation would feel awkward to a native speaker.

If a field is empty in the source, return it empty in the output.

Output format: respond with a single JSON object only, no prose around it, in exactly the same shape the user sent. Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt  = "Target language: " . $language . "\n";
$userPrompt .= "Source survey JSON:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$userPrompt .= "\n\nReturn the same object with every human-readable string translated into " . $language . ".";

$resp   = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 4000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

// Map the translated payload back onto the survey schema.
$newTitle = clean_string((string)($parsed['title'] ?? $origTitle), 255);
if ($newTitle === '') $newTitle = $origTitle;
$newDesc = clean_string((string)($parsed['description'] ?? $origDesc), 4000);

$newSettings = $origSettings;
if (isset($parsed['likertLow']))  $newSettings['likertLow']  = clean_string((string)$parsed['likertLow'],  80);
if (isset($parsed['likertHigh'])) $newSettings['likertHigh'] = clean_string((string)$parsed['likertHigh'], 80);
if (isset($parsed['thankYou']))   $newSettings['thankYou']   = clean_string((string)$parsed['thankYou'],   1000);

// Index the translated questions by id so we can match them up safely.
$translatedById = [];
if (isset($parsed['questions']) && is_array($parsed['questions'])) {
    foreach ($parsed['questions'] as $tq) {
        if (!is_array($tq)) continue;
        $tid = (string)($tq['id'] ?? '');
        if ($tid !== '') $translatedById[$tid] = $tq;
    }
}

$newQuestions = [];
foreach ($origQuestions as $q) {
    if (!is_array($q)) continue;
    $copy = $q;
    $qid = (string)($q['id'] ?? '');
    $tq  = $translatedById[$qid] ?? null;
    if ($tq) {
        if (isset($tq['prompt']))     $copy['prompt']     = clean_string((string)$tq['prompt'],     4000);
        if (isset($tq['likertLow']))  $copy['likertLow']  = clean_string((string)$tq['likertLow'],  80);
        if (isset($tq['likertHigh'])) $copy['likertHigh'] = clean_string((string)$tq['likertHigh'], 80);
        if (isset($tq['options']) && is_array($tq['options']) && isset($q['options']) && is_array($q['options'])) {
            // Translate each option positionally; if the count differs, fall
            // back to the original to avoid losing structure.
            if (count($tq['options']) === count($q['options'])) {
                $copy['options'] = array_map(function ($o) { return clean_string((string)$o, 500); }, $tq['options']);
            }
        }
    }
    $newQuestions[] = $copy;
}

// Tier check: question count
require_under_limit((int)$user['id'], 'max_questions_per_survey', 0, count($newQuestions));

$slug = unique_survey_slug($pdo);
$titleWithLang = $newTitle . ' (' . $language . ')';

$stmt = $pdo->prepare(
    'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
     VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
);
$stmt->execute([
    ':uid'       => $user['id'],
    ':slug'      => $slug,
    ':title'     => clean_string($titleWithLang, 255) ?: 'Untitled survey',
    ':desc'      => $newDesc,
    ':settings'  => json_encode($newSettings,  JSON_UNESCAPED_UNICODE),
    ':questions' => json_encode($newQuestions, JSON_UNESCAPED_UNICODE),
]);
$newId = (int)$pdo->lastInsertId();

json_out([
    'ok' => true,
    'survey' => [
        'id'             => $newId,
        'slug'           => $slug,
        'title'          => $titleWithLang,
        'description'    => $newDesc,
        'is_published'   => false,
        'settings'       => $newSettings,
        'questions'      => $newQuestions,
        'item_count'     => count($newQuestions),
        'likert_count'   => count(array_filter($newQuestions, function ($q) { return ($q['type'] ?? '') === 'likert'; })),
        'response_count' => 0,
    ],
    'language' => $language,
    'model'    => ai_config()['model'],
], 201);
