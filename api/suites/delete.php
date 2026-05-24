<?php
// POST /api/suites/delete.php
// Body: { id }
// Deletes (or archives) a custom suite. System suites cannot be deleted;
// they can only be archived to hide them from the user's hub. Surveys
// previously tagged with the suite stay intact; only the join rows go.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('POST');
check_origin();
$user = require_auth();
$userId = (int)$user['id'];

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

$suite = suites_require_owned($id, $userId);

if ((int)$suite['is_system'] === 1) {
    // Archive instead of delete.
    db()->prepare('UPDATE suites SET status = "archived" WHERE id = :id')
        ->execute([':id' => $id]);
    json_out(['ok' => true, 'action' => 'archived']);
}

db()->prepare('DELETE FROM suites WHERE id = :id')->execute([':id' => $id]);
json_out(['ok' => true, 'action' => 'deleted']);
