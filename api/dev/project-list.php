<?php
// GET /api/dev/project-list.php?status=draft|active|archived (optional)
// Lists the caller's projects with item counts. Powers "Work on existing"
// and reload recovery. Archived projects are excluded unless explicitly asked.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$status = isset($_GET['status']) ? clean_string((string)$_GET['status'], 16) : '';
$where  = 'p.user_id = :uid';
$params = [':uid' => (int)$user['id']];
if (in_array($status, ['draft', 'active', 'archived'], true)) {
    $where .= ' AND p.status = :status';
    $params[':status'] = $status;
} else {
    $where .= " AND p.status <> 'archived'";
}

$stmt = $pdo->prepare(
    'SELECT p.id, p.title, p.status, p.source, p.response_mode, p.settings, p.updated_at,
            (SELECT COUNT(*) FROM survey_items i WHERE i.project_id = p.id) AS item_count,
            (SELECT COUNT(*) FROM survey_dev_response_sessions ss WHERE ss.project_id = p.id) AS response_count,
            s.total AS siri_total
       FROM survey_projects p
       LEFT JOIN siri_reviews s ON s.project_id = p.id
      WHERE ' . $where . '
   ORDER BY p.updated_at DESC, p.id DESC
      LIMIT 200'
);
$stmt->execute($params);

$projects = array_map(function ($r) {
    $st = json_decode((string)($r['settings'] ?? ''), true) ?: [];
    return [
        'id'         => (int)$r['id'],
        'title'      => $r['title'],
        'status'     => $r['status'],
        'source'     => $r['source'],
        'mode'       => $r['response_mode'],
        'items'          => (int)$r['item_count'],
        'response_count' => (int)$r['response_count'],
        'tier'           => isset($st['tier']) ? (string)$st['tier'] : null,
        'siri'           => $r['siri_total'] !== null ? (float)$r['siri_total'] : null,
        'updated_at'     => $r['updated_at'],
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

json_out(['ok' => true, 'projects' => $projects]);
