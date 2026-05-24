<?php
// POST /api/admin/customers/notes/delete.php
// Body: { id: int, reason: string }
//
// Hard-deletes a support note. Reason is required because the note body
// is gone after this; the audit row is the only surviving record. The
// audit row captures the deleted note's body in the before field.

declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../_session.php';
require_once __DIR__ . '/../../../_admin.php';
require_once __DIR__ . '/../../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body   = read_json_body();
$id     = (int)($body['id'] ?? 0);
$reason = clean_string((string)($body['reason'] ?? ''), 500);

if ($id <= 0) fail('bad_id', 'Missing or invalid note id.');
if ($reason === '') fail('reason_required', 'A reason is required to delete a note.');

$pdo = db();
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_notes'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'Phase 22 migration not applied.', 500);

$stmt = $pdo->prepare(
    'SELECT id, customer_user_id, author_email, body FROM admin_notes WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$note = $stmt->fetch();
if (!$note) fail('not_found', 'Note not found.', 404);

$pdo->prepare('DELETE FROM admin_notes WHERE id = :id')->execute([':id' => $id]);

$preview = mb_strlen((string)$note['body']) > 120
    ? mb_substr((string)$note['body'], 0, 119) . '...'
    : (string)$note['body'];

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Deleted support note',
    'customer',
    [
        'severity'     => 'warn',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . (int)$note['customer_user_id'],
        'target_label' => 'note #' . $id . ' (by ' . $note['author_email'] . ')',
        'before'       => '"' . $preview . '"',
        'after'        => 'Deleted',
        'reason'       => $reason,
    ]
);

json_out(['ok' => true, 'message' => 'Note deleted.']);
