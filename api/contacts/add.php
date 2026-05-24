<?php
// POST /api/contacts/add.php
// Body: { survey_id, email, name?, external_ref? }
// Adds one contact to a survey's distribution list.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

invitations_require_survey_owned_by($sid, (int)$user['id']);

$email = invitations_clean_email((string)($body['email'] ?? ''));
if (!$email) fail('bad_email', 'Email is required and must be valid.', 400);

$name = trim((string)($body['name'] ?? ''));
if ($name === '') $name = null;
elseif (mb_strlen($name) > 120) fail('bad_name', 'Name too long (max 120).', 400);

$ext  = trim((string)($body['external_ref'] ?? ''));
if ($ext === '') $ext = null;
elseif (mb_strlen($ext) > 120) fail('bad_external_ref', 'external_ref too long (max 120).', 400);

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO survey_contacts (survey_id, email, name, external_ref, added_by)
         VALUES (:sid, :em, :nm, :ext, :uid)'
    );
    $stmt->execute([
        ':sid' => $sid,
        ':em'  => $email,
        ':nm'  => $name,
        ':ext' => $ext,
        ':uid' => (int)$user['id'],
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    // Most likely cause: duplicate email on the (survey_id, email) unique key.
    if (strpos((string)$e->getMessage(), '1062') !== false || strpos((string)$e->getMessage(), 'Duplicate') !== false) {
        fail('duplicate_email', 'That email is already on this survey\'s contact list.', 409);
    }
    fail('db_error', 'Could not add contact: ' . $e->getMessage(), 500);
}

json_out([
    'contact' => [
        'id'           => $id,
        'email'        => $email,
        'name'         => $name,
        'external_ref' => $ext,
        'status'       => 'active',
    ],
], 201);
