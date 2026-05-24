<?php
// POST /api/admin/customers/notes/update.php
// Body: { id: int, body: string, reason?: string }
//
// Edits the body of an existing support note. Audit row records the
// change with a short before/after preview so the edit history is visible
// in the audit log.

declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../_session.php';
require_once __DIR__ . '/../../../_admin.php';
require_once __DIR__ . '/../../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
$text = trim((string)($body['body'] ?? ''));

if ($id <= 0) fail('bad_id', 'Missing or invalid note id.');
if ($text === '') fail('empty_note', 'Note body cannot be empty.');
if (mb_strlen($text) > 4000) fail('too_long', 'Note must be 4000 characters or fewer.');

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

$before = (string)$note['body'];
if ($before === $text) fail('no_change', 'Note body is unchanged.');

$pdo->prepare('UPDATE admin_notes SET body = :b WHERE id = :id')
    ->execute([':b' => $text, ':id' => $id]);

$beforePrev = mb_strlen($before) > 80 ? mb_substr($before, 0, 79) . '...' : $before;
$afterPrev  = mb_strlen($text) > 80   ? mb_substr($text, 0, 79) . '...'   : $text;

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Edited support note',
    'customer',
    [
        'severity'     => 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . (int)$note['customer_user_id'],
        'target_label' => 'note #' . $id,
        'before'       => '"' . $beforePrev . '"',
        'after'        => '"' . $afterPrev . '"',
        'reason'       => clean_string((string)($body['reason'] ?? ''), 500) ?: null,
    ]
);

json_out(['ok' => true, 'message' => 'Note updated.']);
