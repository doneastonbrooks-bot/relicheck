<?php
// POST /api/contacts/delete.php
// Body: { id }
// Deletes a single contact. The cascading FK on survey_invitations.contact_id
// will remove the invitation history for that contact too.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing contact id.', 400);

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT c.id, c.survey_id FROM survey_contacts c WHERE c.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Contact not found.', 404);

invitations_require_survey_owned_by((int)$row['survey_id'], (int)$user['id']);

$pdo->prepare('DELETE FROM survey_contacts WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true]);
