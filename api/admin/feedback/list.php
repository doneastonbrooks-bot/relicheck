<?php
// GET /api/admin/feedback/list.php
//   ?status=new|read|acted|wontfix  (optional)
//   ?limit=200  (1..500)
//
// Returns MM Studio beta feedback rows for the admin readout. Joins on
// users for email + name. Returns a guard message if the Phase 169 table
// hasn't been created yet, instead of HTML 500.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$pdo = db();

try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'mm_feedback'")->fetchColumn();
} catch (Throwable $e) { $has = false; }
if (!$has) {
    json_out([
        'ok'       => true,
        'items'    => [],
        'warning'  => 'mm_feedback table is not present. Apply schema_phase169.sql.',
    ]);
}

$status = clean_string((string)($_GET['status'] ?? ''), 16);
$limit  = max(1, min(500, (int)($_GET['limit'] ?? 200)));

$where = [];
$args  = [];
if (in_array($status, ['new','read','acted','wontfix'], true)) {
    $where[] = 'f.status = :s';
    $args[':s'] = $status;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT f.id, f.user_id, f.project_id, f.rating, f.comment, f.page_kind,
            f.user_agent, f.viewport, f.status, f.admin_note,
            f.created_at, f.updated_at,
            u.email AS user_email, u.name AS user_name,
            p.title AS project_title
       FROM mm_feedback f
  LEFT JOIN users u ON u.id = f.user_id
  LEFT JOIN mm_projects p ON p.id = f.project_id
            $whereSql
   ORDER BY f.created_at DESC
      LIMIT $limit"
);
$stmt->execute($args);
$rows = $stmt->fetchAll() ?: [];

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'            => (int)$r['id'],
        'user_id'       => (int)$r['user_id'],
        'user_email'    => $r['user_email'],
        'user_name'     => $r['user_name'],
        'project_id'    => $r['project_id'] !== null ? (int)$r['project_id'] : null,
        'project_title' => $r['project_title'],
        'rating'        => $r['rating'] !== null ? (int)$r['rating'] : null,
        'comment'       => $r['comment'],
        'page_kind'     => $r['page_kind'],
        'user_agent'    => $r['user_agent'],
        'viewport'      => $r['viewport'],
        'status'        => (string)$r['status'],
        'admin_note'    => $r['admin_note'],
        'created_at'    => $r['created_at'],
        'updated_at'    => $r['updated_at'],
    ];
}

// Counts by status for the admin sidebar.
$countsRow = $pdo->query(
    "SELECT
        SUM(status = 'new')     AS c_new,
        SUM(status = 'read')    AS c_read,
        SUM(status = 'acted')   AS c_acted,
        SUM(status = 'wontfix') AS c_wontfix,
        COUNT(*)                AS c_total
       FROM mm_feedback"
)->fetch() ?: [];

json_out([
    'ok'     => true,
    'items'  => $items,
    'counts' => [
        'new'     => (int)($countsRow['c_new']     ?? 0),
        'read'    => (int)($countsRow['c_read']    ?? 0),
        'acted'   => (int)($countsRow['c_acted']   ?? 0),
        'wontfix' => (int)($countsRow['c_wontfix'] ?? 0),
        'total'   => (int)($countsRow['c_total']   ?? 0),
    ],
]);
