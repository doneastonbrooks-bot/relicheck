<?php
// GET /api/admin/email/audit.php
// Filters: actor_user_id, action, target_type, from_date, to_date

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$where = ['1=1']; $bind = [];
$actor = (int)($_GET['actor_user_id'] ?? 0);
if ($actor > 0) { $where[] = 'actor_user_id = :a'; $bind[':a'] = $actor; }
$action = clean_string((string)($_GET['action'] ?? ''), 64);
if ($action !== '') { $where[] = 'action = :ac'; $bind[':ac'] = $action; }
$tt = clean_string((string)($_GET['target_type'] ?? ''), 64);
if ($tt !== '') { $where[] = 'target_type = :tt'; $bind[':tt'] = $tt; }
$from = clean_string((string)($_GET['from_date'] ?? ''), 32);
$to   = clean_string((string)($_GET['to_date']   ?? ''), 32);
if ($from !== '') { $where[] = 'created_at >= :f'; $bind[':f'] = $from; }
if ($to   !== '') { $where[] = 'created_at <= :to'; $bind[':to'] = $to; }

$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

$sql = 'SELECT id, actor_user_id, action, target_type, target_id, before_json, after_json, created_at
        FROM email_audit_logs
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id DESC
        LIMIT ' . (int)$limit;
$st = db()->prepare($sql);
$st->execute($bind);
json_out(['ok' => true, 'rows' => $st->fetchAll()]);
