<?php
// POST /api/channels/delete.php
// Body: { id }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing channel id.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT c.id, sv.owner_id
       FROM survey_channels c
       JOIN surveys sv ON sv.id = c.survey_id
      WHERE c.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row || (int)$row['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Channel not found.', 404);
}

$del = $pdo->prepare('DELETE FROM survey_channels WHERE id = :id');
$del->execute([':id' => $id]);
json_out(['ok' => true]);
