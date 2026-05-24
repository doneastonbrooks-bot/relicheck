<?php
// POST /api/admin/feedback/update.php
//   { id: int, status?: 'new'|'read'|'acted'|'wontfix', admin_note?: string }

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('POST');
check_origin();
require_admin();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing feedback id.', 400);

$status = isset($body['status']) ? (string)$body['status'] : null;
if ($status !== null && !in_array($status, ['new','read','acted','wontfix'], true)) {
    fail('bad_status', 'status must be one of new, read, acted, wontfix.', 400);
}

$note = isset($body['admin_note']) ? trim((string)$body['admin_note']) : null;
if ($note !== null && strlen($note) > 1000) $note = substr($note, 0, 1000);

$pdo = db();

try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'mm_feedback'")->fetchColumn();
} catch (Throwable $e) { $has = false; }
if (!$has) fail('feedback_not_ready', 'mm_feedback table missing. Apply schema_phase169.sql.', 503);

$sets = [];
$args = [':id' => $id];
if ($status !== null) { $sets[] = 'status = :s';     $args[':s']  = $status; }
if ($note   !== null) { $sets[] = 'admin_note = :n'; $args[':n']  = $note;   }
if (!$sets) fail('nothing_to_update', 'Provide status or admin_note.', 400);

$stmt = $pdo->prepare('UPDATE mm_feedback SET ' . implode(', ', $sets) . ' WHERE id = :id');
$stmt->execute($args);

json_out(['ok' => true, 'updated' => $stmt->rowCount()]);
