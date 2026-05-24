<?php
// GET /api/admin/email/failures.php
// Returns recent rows from email_delivery_failures joined with email_logs.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

$rows = db()->query(
    'SELECT f.id, f.email_log_id, f.attempt_number, f.error_code, f.error_message,
            f.failed_at, l.event_key, l.recipient_email, l.subject, l.status
     FROM email_delivery_failures f
     JOIN email_logs l ON l.id = f.email_log_id
     ORDER BY f.id DESC
     LIMIT ' . (int)$limit
)->fetchAll();

json_out(['ok' => true, 'rows' => $rows]);
