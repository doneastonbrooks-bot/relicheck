<?php
// POST /api/ai/methodology.php
// Body: {
//   "question":  "<plain English methodology question from the user>",
//   "history":   [ { "role": "user"|"assistant", "content": "..." }, ... ],   // optional
//   "survey_id": <int>                                                          // optional
// }
//
// Conversational helper for survey methodology questions: scale length,
// sample size, reverse coding, validity types, missing data, scoring rules,
// and so on. When a survey_id is provided, the model gets a brief summary
// of that survey so its answers can reference the user's current items.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 80 turns per user per hour. Cheap calls, conversational use case.
check_rate_limit('ai_methodology:user:' . (int)$user['id'], 80, 3600);

$body     = read_json_body();
$question = clean_string((string)($body['question'] ?? ''), 1500);
$history  = is_array($body['history'] ?? null) ? $body['history'] : [];
$surveyId = (int)($body['survey_id'] ?? 0);

if ($question === '') fail('bad_question', 'Type a question first.');

$surveyContext = '';
if ($surveyId > 0) {
    $stmt = db()->prepare('SELECT owner_id, title, settings, questions FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $surveyId]);
    $row = $stmt->fetch();
    if ($row && (int)$row['owner_id'] === (int)$user['id']) {
        $settings  = json_decode((string)$row['settings'],  true) ?: [];
        $questions = json_decode((string)$row['questions'], true) ?: [];
        $likertCount = 0;
        $singleCount = 0;
        $multiCount  = 0;
        $openCount   = 0;
        foreach ($questions as $q) {
            $t = (string)($q['type'] ?? '');
            if ($t === 'likert')  $likertCount++;
            elseif ($t === 'single') $singleCount++;
            elseif ($t === 'multi')  $multiCount++;
            elseif ($t === 'open')   $openCount++;
        }
        $surveyContext  = "Current survey: \"" . (string)$row['title'] . "\"\n";
        $surveyContext .= "Items: " . count($questions) . " total (" .
            $likertCount . " Likert, " . $singleCount . " single-choice, " .
            $multiCount  . " multi-choice, " . $openCount . " open-ended).\n";
        $surveyContext .= "Likert scale: " . (int)($settings['likertPoints'] ?? 5) . "-point.\n";
        // Send the first 8 prompts so the model can give grounded examples.
        $samplePrompts = [];
        foreach (array_slice($questions, 0, 8) as $i => $q) {
            $samplePrompts[] = ($i + 1) . '. [' . ($q['type'] ?? '?') . '] ' . (string)($q['prompt'] ?? '');
        }
        if ($samplePrompts) {
            $surveyContext .= "Sample items:\n" . implode("\n", $samplePrompts) . "\n";
        }
    }
}

$system = <<<SYS
You are a senior survey methodologist acting as a helpful research assistant. The user is building a survey on a platform called ReliCheck and will ask methodology questions: sample size, scale design, reliability, validity, scoring, missing data, ethics, and related topics.

Tone and style:
- Plain English. Concrete examples. No academic posturing.
- 1-3 short paragraphs typical. If the question genuinely needs more, write more, but lead with the headline answer in the first sentence.
- Cite formulas where they matter (e.g., for sample size, give the actual formula and a worked example). Use simple inline math, no LaTeX.
- When the user's current survey is in scope, reference specific items by number (Q1, Q2, etc.).
- When you are uncertain or the answer depends on assumptions, name the assumptions explicitly.
- If a question is outside survey methodology (e.g., legal advice, medical advice, off-topic chit-chat), politely redirect: "I'm here to help with survey methodology questions. For X, you'd want a different resource."

Topics you can speak to confidently include:
- Cronbach's alpha, McDonald's omega, item-rest correlations, alpha-if-deleted
- Likert scale design (number of points, labeled vs. numeric anchors, neutral midpoint)
- Reverse coding and acquiescence bias
- Sample size calculations for reliability (e.g., precision of alpha via Bonett 2002), for proportions, for mean comparisons
- Construct, content, criterion, convergent, and discriminant validity
- Common method variance and ways to mitigate it
- Missing data: MCAR/MAR/MNAR, listwise vs. pairwise vs. imputation
- Scoring conventions: sum scores, mean scores, IRT-based scores (at a high level)
- Ethical considerations: informed consent, anonymity, k-anonymity, IRB basics

Output: respond in plain prose. Do NOT return JSON. Do NOT use markdown headers. You may use simple bullet points or numbered lists if it genuinely helps clarity.
SYS;

$messages = [];
if ($surveyContext !== '') {
    $messages[] = ['role' => 'user',      'content' => "Context for this conversation:\n" . $surveyContext];
    $messages[] = ['role' => 'assistant', 'content' => "Got it. Ask me anything about this survey or about survey methodology more broadly."];
}

$history = array_slice($history, -8);
foreach ($history as $h) {
    if (!is_array($h)) continue;
    $r = (string)($h['role']    ?? '');
    $c = (string)($h['content'] ?? '');
    if ($r !== 'user' && $r !== 'assistant') continue;
    if ($c === '') continue;
    $messages[] = ['role' => $r, 'content' => clean_string($c, 4000)];
}

$messages[] = ['role' => 'user', 'content' => $question];

$resp = ai_complete($system, $messages, 1500);
$answer = trim((string)$resp['text']);
if ($answer === '') {
    fail('ai_empty', 'AI returned an empty response. Try rephrasing.', 502);
}

json_out([
    'ok'     => true,
    'answer' => clean_string($answer, 6000),
    'model'  => ai_config()['model'],
]);
