<?php
// POST /api/datasets/delete.php
// Body: { id }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST', 'DELETE');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid dataset id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT owner_id FROM datasets WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Dataset not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

$pdo->prepare('DELETE FROM datasets WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true, 'deleted_id' => $id]);
