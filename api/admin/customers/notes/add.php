<?php
// POST /api/admin/customers/notes/add.php
// Body: { customer_id?: int, customer_email?: string, body: string }
//
// Writes a support note about a customer. Internal only - customers
// don't see these. Also writes an admin_audit row so the action shows
// up in the audit log alongside other admin operations.
//
// Requires the Phase 22 migration (admin_notes table).

declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../_session.php';
require_once __DIR__ . '/../../../_admin.php';
require_once __DIR__ . '/../../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body          = read_json_body();
$customerId    = (int)($body['customer_id'] ?? 0);
$customerEmail = strtolower(clean_string((string)($body['customer_email'] ?? ''), 255));
$noteBody      = trim((string)($body['body'] ?? ''));

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($noteBody === '')                          fail('empty_note', 'Note body cannot be empty.');
if (mb_strlen($noteBody) > 4000)               fail('too_long', 'Note must be 4000 characters or fewer.');

$pdo = db();

// Bail clearly if the migration hasn't been run, instead of crashing.
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_notes'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'Phase 22 migration not applied. The admin_notes table does not exist yet.', 500);

// Resolve the customer.
if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);
$cuid = (int)$customer['id'];

$ins = $pdo->prepare(
    'INSERT INTO admin_notes (customer_user_id, author_user_id, author_email, body)
     VALUES (:cu, :au, :ae, :b)'
);
$ins->execute([
    ':cu' => $cuid,
    ':au' => (int)$admin['id'],
    ':ae' => (string)$admin['email'],
    ':b'  => $noteBody,
]);
$noteId = (int)$pdo->lastInsertId();

// Snapshot a short preview of the note in the audit row so the audit log
// shows what was written without dumping the whole body.
$preview = mb_strlen($noteBody) > 120 ? mb_substr($noteBody, 0, 119) . '...' : $noteBody;

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Added support note',
    'customer',
    [
        'severity'     => 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => '-',
        'after'        => '"' . $preview . '"',
        'reason'       => null,
    ]
);

json_out([
    'ok'      => true,
    'note_id' => $noteId,
    'message' => 'Note saved.',
]);
