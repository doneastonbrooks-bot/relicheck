<?php
// GET /api/admin/audit/list.php
//   ?search=&category=&severity=&limit=200
//
// Returns the most recent admin_audit rows matching the filters.
// Admin-only. Returns 403 to non-admins.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
$user = require_admin();

$search   = clean_string((string)($_GET['search']   ?? ''), 200);
$category = clean_string((string)($_GET['category'] ?? ''), 32);
$severity = clean_string((string)($_GET['severity'] ?? ''), 16);
$limit    = max(1, min(500, (int)($_GET['limit'] ?? 200)));

$where  = [];
$params = [];

if ($category !== '') {
    $where[] = 'category = :cat';
    $params[':cat'] = $category;
}
if ($severity !== '') {
    $where[] = 'severity = :sev';
    $params[':sev'] = $severity;
}
if ($search !== '') {
    $where[] = '(actor_email LIKE :s OR action LIKE :s OR target_label LIKE :s OR reason LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}

$pdo = db();

// Check the table exists before querying. Use a direct query (not a
// prepared placeholder) because some MySQL/PDO configs reject placeholders
// inside SHOW TABLES. Wrap in try so an unexpected failure returns JSON
// instead of crashing PHP into a generic 500 page.
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_audit'")->fetchColumn();
} catch (Throwable $e) {
    json_out([
        'ok'      => false,
        'rows'    => [],
        'count'   => 0,
        'error'   => 'table_check_failed',
        'message' => $e->getMessage(),
    ], 500);
}
if (!$tableExists) {
    json_out([
        'ok'       => true,
        'rows'     => [],
        'count'    => 0,
        'warnings' => ['admin_audit table not found. Run the Phase 20 migration in phpMyAdmin to enable live audit logs.'],
    ]);
}

$sql = 'SELECT id, ts, actor_user_id, actor_email, actor_role, action, category,
               severity, target_type, target_id, target_label,
               before_value, after_value, reason, ip
        FROM admin_audit';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY ts DESC LIMIT ' . (int)$limit;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    json_out([
        'ok'       => false,
        'rows'     => [],
        'count'    => 0,
        'error'    => 'audit_query_failed',
        'message'  => $e->getMessage(),
    ], 500);
}

// Reshape to the same field names the admin.html JS already expects so the
// renderer is unchanged.
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'         => (int)$r['id'],
        'ts'         => $r['ts'],
        'actor'      => $r['actor_email'],
        'actor_role' => $r['actor_role'],
        'action'     => $r['action'],
        'cat'        => $r['category'],
        'sev'        => $r['severity'],
        'target'     => $r['target_label']
                         ?: trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? '')),
        'before'     => $r['before_value'] ?? '-',
        'after'      => $r['after_value']  ?? '-',
        'reason'     => $r['reason']       ?? '-',
        'ip'         => $r['ip']           ?? '-',
    ];
}

json_out([
    'ok'    => true,
    'rows'  => $out,
    'count' => count($out),
]);
