<?php
// POST /api/panels/delete.php
// Body: { id }
// Hard-delete a 360 panel. Subjects, evaluators, and their invitation rows
// (via ON DELETE CASCADE on survey_invitations.contact_id from Phase 38)
// are cleaned up. Existing responses tagged with the panel's wave label
// stay intact on the survey for analytics history.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_panels.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

panels_require_owned($id, (int)$user['id']);

db()->prepare('DELETE FROM survey_360_panels WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true]);
