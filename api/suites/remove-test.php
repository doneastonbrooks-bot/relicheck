<?php
// POST /api/suites/remove-test.php
// Body: { suite_id, test_id }
// Detaches a test from a suite. The test itself stays.

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

db()->prepare('DELETE FROM suite_tests WHERE suite_id = :s AND test_id = :t')
    ->execute([':s' => $suiteId, ':t' => $testId]);

json_out(['ok' => true]);
