<?php
// GET /api/admin/audit/export.php
//   ?search=&category=&severity=&limit=10000
//
// Streams audit log rows as a downloadable CSV. Respects the same filters
// as list.php so the user can preview-then-export with consistent results.
// Caps at 10,000 rows by default so a single download stays bounded;
// pass an explicit ?limit= to widen.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('GET');
$admin = require_admin();

$search   = clean_string((string)($_GET['search']   ?? ''), 200);
$category = clean_string((string)($_GET['category'] ?? ''), 32);
$severity = clean_string((string)($_GET['severity'] ?? ''), 16);
$limit    = max(1, min(100000, (int)($_GET['limit'] ?? 10000)));

$pdo = db();

// Bail clearly if the table isn't there yet.
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_audit'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'admin_audit table not found. Run the Phase 20 migration first.', 500);

// Build the same filter shape as list.php.
$where  = [];
$params = [];
if ($category !== '') { $where[] = 'category = :cat'; $params[':cat'] = $category; }
if ($severity !== '') { $where[] = 'severity = :sev'; $params[':sev'] = $severity; }
if ($search   !== '') {
    $where[] = '(actor_email LIKE :s OR action LIKE :s OR target_label LIKE :s OR reason LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}

$sql = 'SELECT ts, actor_email, actor_role, action, category, severity,
               target_type, target_id, target_label,
               before_value, after_value, reason, ip, user_agent
          FROM admin_audit';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY ts DESC LIMIT ' . (int)$limit;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (Throwable $e) {
    fail('audit_query_failed', $e->getMessage(), 500);
}

// Build a download filename that encodes the filters so the user can tell
// exports apart later.
$filenameParts = ['relicheck-audit'];
if ($category !== '') $filenameParts[] = $category;
if ($severity !== '') $filenameParts[] = $severity;
$filenameParts[] = date('Ymd-His');
$filename = implode('_', $filenameParts) . '.csv';

// Headers. text/csv with attachment Content-Disposition triggers a download
// instead of inline rendering.
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('Pragma: no-cache');

// Excel-friendly: a UTF-8 BOM so non-ASCII (smart quotes, em dashes, etc.)
// renders correctly when opened in Excel.
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row.
fputcsv($out, [
    'Timestamp (server local)',
    'Actor email',
    'Actor role',
    'Action',
    'Category',
    'Severity',
    'Target type',
    'Target id',
    'Target label',
    'Before',
    'After',
    'Reason',
    'IP',
    'User agent',
]);

$count = 0;
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        (string)$r['ts'],
        (string)$r['actor_email'],
        (string)$r['actor_role'],
        (string)$r['action'],
        (string)$r['category'],
        (string)$r['severity'],
        (string)($r['target_type']  ?? ''),
        (string)($r['target_id']    ?? ''),
        (string)($r['target_label'] ?? ''),
        (string)($r['before_value'] ?? ''),
        (string)($r['after_value']  ?? ''),
        (string)($r['reason']       ?? ''),
        (string)($r['ip']           ?? ''),
        (string)($r['user_agent']   ?? ''),
    ]);
    $count++;
}

fclose($out);

// Audit the export itself so we have a record of who pulled the log.
admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'owner'],
    'Exported audit log',
    'system',
    [
        'severity'     => 'info',
        'target_type'  => 'audit',
        'target_id'    => null,
        'target_label' => $filename,
        'before'       => '-',
        'after'        => $count . ' rows * filters: '
            . ($category !== '' ? 'category=' . $category . ' ' : '')
            . ($severity !== '' ? 'severity=' . $severity . ' ' : '')
            . ($search   !== '' ? 'search="' . $search . '" '   : '')
            . 'limit=' . $limit,
        'reason'       => null,
    ]
);
