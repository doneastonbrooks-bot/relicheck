<?php
// POST /api/tests/delete.php
// Body: { "id": <int> }
// Soft-deletes by setting archived_at; hard-deletes test_responses on cascade if needed.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id < 1) fail('bad_input', 'Missing test id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT user_id FROM tests WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Test not found.', 404);
if ((int)$row['user_id'] !== (int)$user['id']) fail('forbidden', 'You do not have access to this test.', 403);

// Hard delete (test_responses cascade via FK).
$del = $pdo->prepare('DELETE FROM tests WHERE id = :id');
$del->execute([':id' => $id]);

json_out(['ok' => true]);
