<?php
// GET /api/dev/project-responses.php?project_id={id}
// Authenticated. Returns the stored public responses for a project the caller
// owns, read from the Phase 3D tables (survey_dev_response_sessions +
// survey_dev_answers).
//
// Phase 3E: response viewing ONLY. No RSSI, no analysis, no scoring. The
// respondent IP hash is never returned; the user-agent is returned only when
// ?debug=1 is passed (for the owner's own troubleshooting), never by default.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$debug     = isset($_GET['debug']) && $_GET['debug'] === '1';

// Owner check: fails 403 if the caller does not own this project, 404 if absent.
sds_require_project($pdo, (int)$user['id'], $projectId);

// Load the project's items so choice answers (stored as option INDEXES by
// take.html) can be resolved back to the option text. Map: item_id → {type, options[]}.
$itStmt = $pdo->prepare('SELECT id, type, options FROM survey_items WHERE project_id = :id');
$itStmt->execute([':id' => $projectId]);
$itemMeta = [];
foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
    $opts = ($it['options'] !== null) ? json_decode((string)$it['options'], true) : null;
    $itemMeta[(int)$it['id']] = [
        'type'    => (string)$it['type'],
        'options' => is_array($opts) ? array_values($opts) : [],
    ];
}

// Resolve a stored answer value to human-readable display text.
// Single/Multiple-choice store a 0-based index (or JSON array of indexes);
// look the index up in the item's option list. Everything else is shown as-is.
$displayValue = function (?int $itemId, string $raw) use ($itemMeta): string {
    if ($itemId === null || !isset($itemMeta[$itemId])) return $raw;
    $meta = $itemMeta[$itemId];
    $opts = $meta['options'];
    if (count($opts) === 0) return $raw;
    $choiceTypes = ['Multiple Choice', 'Single Choice', 'Dropdown', 'Checkboxes', 'Yes/No', 'True/False', 'NPS'];
    if (!in_array($meta['type'], $choiceTypes, true)) return $raw;

    $resolve = function ($v) use ($opts) {
        if (!is_numeric($v)) return (string)$v;     // already text → leave it
        $i = (int)$v;
        return ($i >= 0 && $i < count($opts)) ? (string)$opts[$i] : (string)$v;
    };

    // Multi-select answers arrive as a JSON array of indexes.
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return implode(', ', array_map($resolve, $decoded));
    }
    return $resolve($raw);
};

// Sessions, newest first.
$sStmt = $pdo->prepare(
    'SELECT id, link_key, submitted_at, user_agent
       FROM survey_dev_response_sessions
      WHERE project_id = :id
      ORDER BY submitted_at DESC, id DESC'
);
$sStmt->execute([':id' => $projectId]);
$sessionRows = $sStmt->fetchAll(PDO::FETCH_ASSOC);

// All answers for the project in one query, grouped by session in PHP.
$aStmt = $pdo->prepare(
    'SELECT session_id, item_id, item_label, answer_value
       FROM survey_dev_answers
      WHERE project_id = :id
      ORDER BY id'
);
$aStmt->execute([':id' => $projectId]);

$answersBySession = [];
foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $sid    = (int)$a['session_id'];
    $itemId = $a['item_id'] !== null ? (int)$a['item_id'] : null;
    $raw    = $a['answer_value'] !== null ? (string)$a['answer_value'] : '';
    $answersBySession[$sid][] = [
        'item_id' => $itemId,
        'label'   => (string)$a['item_label'],
        'value'   => $displayValue($itemId, $raw),
    ];
}

$sessions = array_map(function ($s) use ($answersBySession, $debug) {
    $sid = (int)$s['id'];
    $out = [
        'id'           => $sid,
        'submitted_at' => $s['submitted_at'],
        'answers'      => $answersBySession[$sid] ?? [],
    ];
    // user_agent only on explicit debug request; ip_hash NEVER exposed.
    if ($debug) {
        $out['user_agent'] = $s['user_agent'] !== null ? (string)$s['user_agent'] : '';
    }
    return $out;
}, $sessionRows);

json_out([
    'ok'        => true,
    'count'     => count($sessions),
    'sessions'  => $sessions,
]);
