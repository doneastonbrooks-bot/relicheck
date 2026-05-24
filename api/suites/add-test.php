<?php
// POST /api/suites/add-test.php
// Body: { suite_id, test_id }
// Attaches a test to a suite. Idempotent via the join's PK.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('POST');
check_origin();
$user = require_auth();
$userId = (int)$user['id'];

$body = read_json_body();
$suiteId = (int)($body['suite_id'] ?? 0);
$testId  = (int)($body['test_id']  ?? 0);
if ($suiteId <= 0 || $testId <= 0) fail('bad_id', 'Missing suite_id or test_id.', 400);

suites_require_owned($suiteId, $userId);

// Confirm test ownership.
$pdo = db();
$tStmt = $pdo->prepare('SELECT user_id FROM tests WHERE id = :id LIMIT 1');
$tStmt->execute([':id' => $testId]);
$tRow = $tStmt->fetch();
if (!$tRow) fail('not_found', 'Test not found.', 404);
if ((int)$tRow['user_id'] !== $userId) fail('forbidden', 'You can only add your own tests.', 403);

$pdo->prepare(
    'INSERT IGNORE INTO suite_tests (suite_id, test_id) VALUES (:s, :t)'
)->execute([':s' => $suiteId, ':t' => $testId]);

json_out(['ok' => true]);
