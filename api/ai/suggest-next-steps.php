<?php
// POST /api/ai/suggest-next-steps.php
// Body: {
//   "snapshot": {
//     "totals": { surveys, datasets, total_responses, avg_completion_pct },
//     "health": { green, amber, red, unknown },
//     "activity": { last7, prev7, surveys_with_activity_7d },
//     "surveys": [
//       { id, title, status, is_published, response_count, complete_count?,
//         health_class, item_count, likert_count, has_open_ended?,
//         updated_at, last_response_at? }
//     ]
//   }
// }
//
// Returns up to 5 prioritized, actionable next steps for the user. The model
// only sees aggregate survey metadata; no respondent identifiers, no answers,
// no emails.
//
// Output: { ok, suggestions: [{ label, reason, action, survey_id?, destination? }], model }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 10 calls per hour per user. Same bucket pattern as factor naming.
check_rate_limit('ai_next_steps:user:' . (int)$user['id'], 10, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) {
    fail('bad_input', 'Missing snapshot payload.');
}

// Trim and clean the snapshot before sending to the model. We keep totals,
// health buckets, activity, and a per-survey row list capped at 30 surveys
// so the prompt stays bounded.
$cleanTotals = [
    'surveys'             => (int)($snap['totals']['surveys']             ?? 0),
    'datasets'            => (int)($snap['totals']['datasets']            ?? 0),
    'total_responses'     => (int)($snap['totals']['total_responses']     ?? 0),
    'avg_completion_pct'  => (int)($snap['totals']['avg_completion_pct']  ?? 0),
];
$cleanHealth = [
    'green'   => (int)($snap['health']['green']   ?? 0),
    'amber'   => (int)($snap['health']['amber']   ?? 0),
    'red'     => (int)($snap['health']['red']     ?? 0),
    'unknown' => (int)($snap['health']['unknown'] ?? 0),
];
$cleanActivity = [
    'last7' => (int)($snap['activity']['last7'] ?? 0),
    'prev7' => (int)($snap['activity']['prev7'] ?? 0),
    'surveys_with_activity_7d' => (int)($snap['activity']['surveys_with_activity_7d'] ?? 0),
];

$surveysIn = is_array($snap['surveys'] ?? null) ? $snap['surveys'] : [];
$surveysOut = [];
foreach ($surveysIn as $s) {
    if (!is_array($s)) continue;
    $row = [
        'id'             => (int)($s['id'] ?? 0),
        'title'          => clean_string((string)($s['title'] ?? ''), 200),
        'status'         => clean_string((string)($s['status'] ?? ''), 32),
        'is_published'   => !empty($s['is_published']),
        'response_count' => (int)($s['response_count'] ?? 0),
        'health_class'   => clean_string((string)($s['health_class'] ?? ''), 16),
        'item_count'     => (int)($s['item_count'] ?? 0),
        'likert_count'   => (int)($s['likert_count'] ?? 0),
        'has_open_ended' => !empty($s['has_open_ended']),
    ];
    if (isset($s['complete_count']))   $row['complete_count']   = (int)$s['complete_count'];
    if (!empty($s['updated_at']))      $row['updated_at']       = clean_string((string)$s['updated_at'], 32);
    if (!empty($s['last_response_at']))$row['last_response_at'] = clean_string((string)$s['last_response_at'], 32);
    if ($row['id'] <= 0 || $row['title'] === '') continue;
    $surveysOut[] = $row;
    if (count($surveysOut) >= 30) break;
}

$payload = [
    'totals'   => $cleanTotals,
    'health'   => $cleanHealth,
    'activity' => $cleanActivity,
    'surveys'  => $surveysOut,
];

$system = <<<SYS
You are a survey-quality coach inside ReliCheck. The user sees a list called "Recommended next steps" on their home page. They click a button to ask AI for sharper suggestions than the default heuristic list. You write those suggestions.

Quality bar:
- Output 3 to 5 suggestions, prioritized highest-value first.
- Each suggestion is a single concrete action, not a category or a question.
- The action label is short (under 12 words), imperative voice. Example: "Review weak items in Engagement Pulse" not "Items to consider reviewing."
- The reason is one short sentence explaining WHY this action matters now, based on something in the snapshot. Cite the specific signal (low completion, amber health, high response count, no responses yet, etc.).
- The destination tells the app where to send the user. Valid values:
    "analytics"        - open the Survey Results for a specific survey (requires survey_id)
    "builder"          - open the builder for a specific survey (requires survey_id)
    "distribution"     - open the Distribute view for a specific survey (requires survey_id)
    "surveys"          - go to the My Surveys list (no survey_id needed)
    "datasets"         - go to My Datasets (no survey_id needed)
    "new_survey"       - start a brand new survey (no survey_id needed)
    "upload_data"      - upload responses from another tool (no survey_id needed)
    "upload_survey"    - import a survey from another tool (no survey_id needed)
- survey_id is required when the action targets a specific survey. Use the id from the input. Never invent ids.

Rules:
- Do not invent surveys that are not in the snapshot.
- Do not propose actions that require data not in the snapshot (e.g., do not reference specific item IDs, respondent names, or scale alphas; that level of detail is not in the input).
- If the user has zero surveys, suggest creating one, importing data, or uploading a survey.
- If the user has surveys with amber or red health, prioritize reviewing them.
- If the user has a published survey with strong response count but low recent activity, suggest re-sharing or analyzing.
- If the user has draft surveys that have been sitting, suggest finishing one.
- Never recommend deleting data or anything destructive.

Output format: respond with a single JSON object only, no prose, no markdown fences:

{
  "suggestions": [
    { "label": "<short imperative action>",
      "reason": "<one sentence citing the signal>",
      "destination": "<one of the valid destinations>",
      "survey_id": <integer or null>
    },
    ...
  ]
}
SYS;

$userPrompt  = "Snapshot of the user's ReliCheck workspace:\n\n";
$userPrompt .= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$userPrompt .= "\n\nProduce 3 to 5 prioritized next-step suggestions.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 1200);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validDest = ['analytics','builder','distribution','surveys','datasets','new_survey','upload_data','upload_survey'];
$idsAllowed = array_map(fn($s) => (int)$s['id'], $surveysOut);
$out = [];
foreach ($parsed['suggestions'] as $s) {
    if (!is_array($s)) continue;
    $label  = clean_string((string)($s['label']  ?? ''), 160);
    $reason = clean_string((string)($s['reason'] ?? ''), 360);
    $dest   = strtolower(clean_string((string)($s['destination'] ?? ''), 32));
    if ($label === '' || !in_array($dest, $validDest, true)) continue;
    $sid = isset($s['survey_id']) ? (int)$s['survey_id'] : 0;
    $needsId = in_array($dest, ['analytics','builder','distribution'], true);
    if ($needsId) {
        if ($sid <= 0 || !in_array($sid, $idsAllowed, true)) continue;
    } else {
        $sid = 0;
    }
    $entry = [
        'label'       => $label,
        'reason'      => $reason,
        'destination' => $dest,
    ];
    if ($sid > 0) $entry['survey_id'] = $sid;
    $out[] = $entry;
    if (count($out) >= 5) break;
}

if (count($out) === 0) {
    fail('ai_empty_result', 'AI did not return any usable suggestions. Try again.', 502);
}

json_out([
    'ok'          => true,
    'suggestions' => $out,
    'model'       => ai_config()['model'],
]);
