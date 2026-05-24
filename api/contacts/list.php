<?php
// GET /api/contacts/list.php?survey_id=N
// Returns the contacts for a survey. The owner sees who they invited
// (necessary for adding/removing the list), but PER-CONTACT response status
// is intentionally NOT returned. The owner can never link an email address
// to a specific completion. Aggregate response counts live in
// /api/invitations/list.php.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('GET');
$user = require_auth();

$sid = (int)($_GET['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

invitations_require_survey_owned_by($sid, (int)$user['id']);

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'SELECT id, email, name, external_ref, status, created_at
           FROM survey_contacts
          WHERE survey_id = :sid
          ORDER BY created_at DESC'
    );
    $stmt->execute([':sid' => $sid]);
    $rows = [];
    while ($r = $stmt->fetch()) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'email'        => (string)$r['email'],
            'name'         => $r['name'] !== null ? (string)$r['name'] : null,
            'external_ref' => $r['external_ref'] !== null ? (string)$r['external_ref'] : null,
            'status'       => (string)$r['status'],
            'created_at'   => $r['created_at'],
        ];
    }
    json_out(['contacts' => $rows]);
} catch (Throwable $e) {
    json_out(['contacts' => [], 'note' => 'phase38_pending']);
}
