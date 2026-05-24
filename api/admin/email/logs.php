<?php
// GET /api/admin/email/logs.php
// Filters: account_id, recipient_user_id, recipient_email, department,
//          event_key, status, from_date, to_date, page, page_size
//
// Customer service can view email history but never sees restricted
// variables (the renderer scrubs them on insert, so the stored bodies are
// already safe).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$pdo = db();

$where = ['1=1']; $bind = [];

$account_id = (int)($_GET['account_id'] ?? 0);
if ($account_id > 0) { $where[] = 'l.customer_account_id = :acct'; $bind[':acct'] = $account_id; }

$ruid = (int)($_GET['recipient_user_id'] ?? 0);
if ($ruid > 0) { $where[] = 'l.recipient_user_id = :ruid'; $bind[':ruid'] = $ruid; }

$remail = strtolower(clean_string((string)($_GET['recipient_email'] ?? ''), 255));
if ($remail !== '') { $where[] = 'l.recipient_email = :remail'; $bind[':remail'] = $remail; }

$dept = clean_string((string)($_GET['department'] ?? ''), 32);
if ($dept !== '') {
    $where[] = 'd.code = :dept'; $bind[':dept'] = $dept;
}

$event_key = clean_string((string)($_GET['event_key'] ?? ''), 96);
if ($event_key !== '') { $where[] = 'l.event_key = :ek'; $bind[':ek'] = $event_key; }

$status = clean_string((string)($_GET['status'] ?? ''), 32);
if ($status !== '') { $where[] = 'l.status = :status'; $bind[':status'] = $status; }

$from = clean_string((string)($_GET['from_date'] ?? ''), 32);
$to   = clean_string((string)($_GET['to_date']   ?? ''), 32);
if ($from !== '') { $where[] = 'l.created_at >= :from'; $bind[':from'] = $from; }
if ($to   !== '') { $where[] = 'l.created_at <= :to';   $bind[':to']   = $to; }

$page = max(1, (int)($_GET['page'] ?? 1));
$size = max(1, min(200, (int)($_GET['page_size'] ?? 50)));
$offset = ($page - 1) * $size;

$sql = 'SELECT l.id, l.event_key, l.template_id, l.sender_email, l.sender_display_name,
               l.recipient_user_id, l.recipient_email, l.recipient_role,
               l.customer_account_id, l.subject, l.preview,
               l.status, l.attempts, l.last_error, l.sent_at, l.delivered_at,
               l.created_at, d.code AS department_code, d.display_name AS department_name
        FROM email_logs l
        LEFT JOIN email_departments d ON d.id = l.department_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.id DESC
        LIMIT ' . (int)$size . ' OFFSET ' . (int)$offset;

$st = $pdo->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll();

// Detail view: include sanitized_body when ?id=<id>
$detail = null;
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $st2 = $pdo->prepare(
        'SELECT l.*, d.code AS department_code FROM email_logs l
         LEFT JOIN email_departments d ON d.id = l.department_id
         WHERE l.id = :id LIMIT 1'
    );
    $st2->execute([':id' => $id]);
    $detail = $st2->fetch() ?: null;
}

json_out(['ok' => true, 'rows' => $rows, 'detail' => $detail, 'page' => $page, 'page_size' => $size]);
