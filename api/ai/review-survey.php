<?php
// POST /api/ai/review-survey.php
// Body: { "survey_id": <int> }
//
// Whole-survey review run before publishing. Returns:
//   - predicted_alpha_low / predicted_alpha_high   (a defensible range)
//   - confidence                                    "low" | "medium" | "high"
//   - summary                                       1-2 sentence headline
//   - constructs                                    inferred groupings
//   - issues                                        per-item flags with optional one-tap fix
//   - suggestions                                   2-4 overall actions

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 30 reviews per user per hour. People typically don't publish that often,
// but this also handles "click review, fix things, click review again" loops.
check_rate_limit('ai_review:user:' . (int)$user['id'], 30, 3600);

$body     = read_json_body();
$surveyId = (int)($body['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_id', 'Missing or invalid survey_id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT owner_id, title, description, settings, questions FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$row = $stmt->fetch();
if (!$row)                                    fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$title       = (string)$row['title'];
$description = (string)$row['description'];
$settings    = json_decode((string)$row['settings'],  true) ?: [];
$questions   = json_decode((string)$row['questions'], true) ?: [];
if (!is_array($questions) || count($questions) === 0) {
    fail('empty_survey', 'Add at least one question before running a review.');
}

// Build a compact payload for the model.
$payload = [
    'title'       => $title,
    'description' => $description,
    'likertPoints' => (int)($settings['likertPoints'] ?? 5),
    'likertLow'    => (string)($settings['likertLow']  ?? 'Strongly disagree'),
    'likertHigh'   => (string)($settings['likertHigh'] ?? 'Strongly agree'),
    'questions'    => array_map(function ($q) {
        $item = [
            'id'      => (string)($q['id']     ?? ''),
            'type'    => (string)($q['type']   ?? ''),
            'prompt'  => (string)($q['prompt'] ?? ''),
            'reverse' => !empty($q['reverse']),
        ];
        if (isset($q['options']) && is_array($q['options'])) {
            $item['options'] = array_map(function ($o) { return (string)$o; }, $q['options']);
        }
        return $item;
    }, $questions),
];

$system = <<<SYS
You are a senior survey methodologist running a pre-publish review on a draft survey. The user gives you the survey JSON. Return an honest, useful review of how well it is likely to perform once data comes in.

What to assess:
1. Construct coverage: do the Likert items group sensibly into one or more underlying constructs? Name each construct in 2-4 words and list the question ids that belong to it.
2. Reliability risk: which Likert items are most likely to drag the construct down (out of place, weak wording, possibly off-topic)? Flag them with a one-tap rewrite if you can.
3. Reverse-coding balance: if all Likert items are positively worded, recommend reverse-coding 1-2 items. Identify which items would be best candidates and provide rewrites.
4. Type fit: is any item using the wrong type (e.g., a Likert item that is really an open-ended question, or a multi where single makes more sense)?
5. Predicted alpha range: estimate the most likely Cronbach's alpha range once 100+ responses are collected. Use the realistic range, not a single number, e.g., "0.72 to 0.84".
6. Confidence: how confident are you in this estimate? "low" if the survey is very short or the constructs are unclear, "medium" if it is well-formed but somewhat ambitious, "high" if the items are tight and clearly tap one or two constructs.

Issue types you may flag (use only these "type" values):
- weak_wording, double_barreled, leading, ambiguous, type_mismatch, redundant, off_construct, missing_reverse_code, too_long

Severity:
- "warn" for issues that will materially affect reliability or response quality.
- "info" for stylistic improvements.

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "summary": "<1-2 sentence overall read>",
  "predicted_alpha_low":  <number between 0 and 1>,
  "predicted_alpha_high": <number between 0 and 1>,
  "confidence":           "low" | "medium" | "high",
  "constructs": [
    {
      "name":        "<2-4 word construct name>",
      "question_ids": ["<id>", "<id>", "..."],
      "notes":        "<one short sentence on coverage and any gaps>"
    }
  ],
  "issues": [
    {
      "question_id": "<the id of the question being flagged>",
      "type":        "<one of the issue types above>",
      "severity":    "warn" | "info",
      "message":     "<one short sentence on the issue>",
      "fix":         "<a complete rewrite of the prompt that addresses just this issue, or null>"
    }
  ],
  "suggestions": [
    "<one short actionable suggestion at the survey level>",
    "<another suggestion>"
  ]
}

Rules:
- Cap "issues" at 6 entries; pick the highest-impact ones.
- Cap "suggestions" at 4 entries.
- "fix" must be a full replacement for the prompt, never a fragment, or null if no clean fix exists.
- predicted_alpha_low must be less than predicted_alpha_high.
- Do not invent question_ids. Only reference ids that appear in the input JSON.
- If the survey has no Likert items, set both predicted_alpha values to 0 and confidence to "low".
- Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt  = "Survey JSON:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$userPrompt .= "\n\nReturn the review now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 4000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

// Sanitize.
$validQids = [];
foreach ($questions as $q) {
    if (is_array($q) && isset($q['id'])) $validQids[(string)$q['id']] = true;
}

$summary    = clean_string((string)($parsed['summary'] ?? ''), 1000);
$alphaLow   = (float)($parsed['predicted_alpha_low']  ?? 0);
$alphaHigh  = (float)($parsed['predicted_alpha_high'] ?? 0);
if ($alphaLow  < 0) $alphaLow  = 0;
if ($alphaLow  > 1) $alphaLow  = 1;
if ($alphaHigh < 0) $alphaHigh = 0;
if ($alphaHigh > 1) $alphaHigh = 1;
if ($alphaLow > $alphaHigh) { $tmp = $alphaLow; $alphaLow = $alphaHigh; $alphaHigh = $tmp; }
$confidence = strtolower(clean_string((string)($parsed['confidence'] ?? 'medium'), 16));
if (!in_array($confidence, ['low','medium','high'], true)) $confidence = 'medium';

$constructs = [];
if (isset($parsed['constructs']) && is_array($parsed['constructs'])) {
    foreach ($parsed['constructs'] as $c) {
        if (!is_array($c)) continue;
        $name = clean_string((string)($c['name'] ?? ''), 80);
        $notes = clean_string((string)($c['notes'] ?? ''), 400);
        $qids = [];
        if (isset($c['question_ids']) && is_array($c['question_ids'])) {
            foreach ($c['question_ids'] as $qid) {
                $qid = (string)$qid;
                if (isset($validQids[$qid])) $qids[] = $qid;
            }
        }
        if ($name === '' || count($qids) === 0) continue;
        $constructs[] = ['name' => $name, 'question_ids' => $qids, 'notes' => $notes];
    }
}

$validIssueTypes = ['weak_wording','double_barreled','leading','ambiguous','type_mismatch','redundant','off_construct','missing_reverse_code','too_long'];
$issues = [];
if (isset($parsed['issues']) && is_array($parsed['issues'])) {
    foreach ($parsed['issues'] as $iss) {
        if (!is_array($iss)) continue;
        $qid  = (string)($iss['question_id'] ?? '');
        if (!isset($validQids[$qid])) continue;
        $kind = (string)($iss['type'] ?? '');
        if (!in_array($kind, $validIssueTypes, true)) continue;
        $sev  = strtolower((string)($iss['severity'] ?? 'info'));
        if (!in_array($sev, ['warn','info'], true)) $sev = 'info';
        $msg  = clean_string((string)($iss['message'] ?? ''), 400);
        if ($msg === '') continue;
        $fix  = isset($iss['fix']) && is_string($iss['fix']) ? clean_string($iss['fix'], 4000) : '';
        if ($fix === '') $fix = null;
        $issues[] = [
            'question_id' => $qid,
            'type'        => $kind,
            'severity'    => $sev,
            'message'     => $msg,
            'fix'         => $fix,
        ];
        if (count($issues) === 6) break;
    }
}

$suggestions = [];
if (isset($parsed['suggestions']) && is_array($parsed['suggestions'])) {
    foreach ($parsed['suggestions'] as $s) {
        $line = clean_string((string)$s, 400);
        if ($line !== '') $suggestions[] = $line;
        if (count($suggestions) === 4) break;
    }
}

json_out([
    'ok'                   => true,
    'summary'              => $summary,
    'predicted_alpha_low'  => round($alphaLow,  2),
    'predicted_alpha_high' => round($alphaHigh, 2),
    'confidence'           => $confidence,
    'constructs'           => $constructs,
    'issues'               => $issues,
    'suggestions'          => $suggestions,
    'model'                => ai_config()['model'],
]);
