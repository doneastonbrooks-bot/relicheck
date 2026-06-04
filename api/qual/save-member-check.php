<?php
// POST /api/qual/save-member-check.php
// Body: { project_id, check: { finding, who, method, date, outcome, notes } }
// Each call appends one member-check entry stored as a qual_memo row.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
check_origin();
$user      = require_auth();
release_session_lock();
$pdo       = db();
$uid       = (int)$user['id'];

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'project_id required.');

qual_require_project($pdo, $uid, $projectId);

$raw = $body['check'] ?? null;
if (!is_array($raw)) fail('bad_input', 'check object is required.');

$finding = clean_string((string)($raw['finding'] ?? ''), 500);
if ($finding === '') fail('bad_input', 'finding is required.');

$allowedOutcomes = ['Confirmed', 'Revised', 'Mixed'];
$outcome = in_array($raw['outcome'] ?? '', $allowedOutcomes, true)
    ? (string)$raw['outcome'] : 'Confirmed';
$who    = clean_string((string)($raw['who']    ?? ''), 200);
$method = clean_string((string)($raw['method'] ?? ''), 200);
$date   = clean_string((string)($raw['date']   ?? ''), 20) ?: date('Y-m-d');
$notes  = clean_string((string)($raw['notes']  ?? ''), 1000);

$bodyJson = json_encode([
    'finding' => $finding,
    'who'     => $who,
    'method'  => $method,
    'date'    => $date,
    'outcome' => $outcome,
    'notes'   => $notes,
], JSON_UNESCAPED_UNICODE);

$st = $pdo->prepare(
    'INSERT INTO qual_memos (project_id, object_type, object_id, memo_type, title, body, author_id)
     VALUES (:p, :ot, NULL, :mt, :ti, :b, :a)'
);
$st->execute([
    ':p'  => $projectId,
    ':ot' => 'project',
    ':mt' => 'member_check',
    ':ti' => 'Member check: ' . mb_substr($finding, 0, 80),
    ':b'  => $bodyJson,
    ':a'  => $uid,
]);
$memoId = (int)$pdo->lastInsertId();
$ts     = date('Y-m-d H:i:s');

qual_audit($pdo, $projectId, $uid, 'member_check_added', 'memo', $memoId, $finding);

json_out(['ok' => true, 'memo_id' => $memoId, 'created_at' => $ts]);
