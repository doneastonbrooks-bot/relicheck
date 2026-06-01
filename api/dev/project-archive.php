<?php
// POST /api/dev/project-archive.php
// Body: { id, archived?: bool }  — archived defaults to true.
// Soft state change only: sets status to 'archived' (or back to 'draft').
// Never deletes data. Owner-checked.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$body = read_json_body();
$id   = isset($body['id']) ? (int)$body['id'] : 0;
sds_require_project($pdo, (int)$user['id'], $id);

$archived = array_key_exists('archived', $body) ? (bool)$body['archived'] : true;
$status   = $archived ? 'archived' : 'draft';

$pdo->prepare('UPDATE survey_projects SET status = :status WHERE id = :id')
    ->execute([':status' => $status, ':id' => $id]);

json_out(['ok' => true, 'id' => $id, 'status' => $status]);
