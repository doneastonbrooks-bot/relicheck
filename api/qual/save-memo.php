<?php
// POST /api/qual/save-memo.php
// Create or update an analytic memo.
// Body: { project_id, object_type?, object_id?, memo_type?, title?, body }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
qual_require_project($pdo, $uid, $projectId);

$memoBody = trim((string)($body['body'] ?? ''));
if ($memoBody === '') fail('bad_input', 'Memo body is required.');

$pdo->prepare(
    'INSERT INTO qual_memos (project_id,object_type,object_id,memo_type,title,body,author_id)
     VALUES (:p,:ot,:oi,:mt,:ti,:b,:u)'
)->execute([
    ':p'  => $projectId,
    ':ot' => $body['object_type'] ?? 'project',
    ':oi' => !empty($body['object_id']) ? (int)$body['object_id'] : null,
    ':mt' => $body['memo_type'] ?? 'analytic',
    ':ti' => trim((string)($body['title'] ?? '')) ?: null,
    ':b'  => $memoBody,
    ':u'  => $uid,
]);
$memoId = (int)$pdo->lastInsertId();
qual_audit($pdo, $projectId, $uid, 'memo_created', 'memo', $memoId, $body['title'] ?? '');

json_out(['ok' => true, 'memo_id' => $memoId]);
