<?php
// GET /api/qual/get-audit-trail.php?project_id=N[&offset=0&limit=60]
// Returns paginated qual_audit_trail rows in reverse-chronological order.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_require_project($pdo, $uid, $projectId);

$limit  = min(max((int)($_GET['limit']  ?? 60), 1), 200);
$offset = max((int)($_GET['offset'] ?? 0), 0);

// Total count
$cntSt = $pdo->prepare('SELECT COUNT(*) FROM qual_audit_trail WHERE project_id=:p');
$cntSt->execute([':p' => $projectId]);
$total = (int)$cntSt->fetchColumn();

// Paged rows
$rowSt = $pdo->prepare(
    'SELECT id, action, object_type, object_id, object_name, prev_value, new_value, memo, created_at
     FROM qual_audit_trail
     WHERE project_id=:p
     ORDER BY id DESC
     LIMIT :lim OFFSET :off'
);
$rowSt->bindValue(':p',   $projectId, PDO::PARAM_INT);
$rowSt->bindValue(':lim', $limit,     PDO::PARAM_INT);
$rowSt->bindValue(':off', $offset,    PDO::PARAM_INT);
$rowSt->execute();
$rows = $rowSt->fetchAll(PDO::FETCH_ASSOC);

// Human-readable action labels
$actionLabels = [
    'project_saved'         => 'Project settings saved',
    'dataset_linked'        => 'Dataset linked',
    'pii_scanned'           => 'PII scan run',
    'pii_masked'            => 'PII masked',
    'familiarization_memo'  => 'First impressions memo saved',
    'code_applied'          => 'Code applied',
    'code_removed'          => 'Code removed',
    'code_saved'            => 'Code saved',
    'code_created'          => 'Code created',
    'category_saved'        => 'Category saved',
    'category_created'      => 'Category created',
    'code_assigned_category'=> 'Code assigned to category',
    'theme_saved'           => 'Theme saved',
    'theme_created'         => 'Theme created',
    'theme_category_linked' => 'Category linked to theme',
    'quote_pinned'          => 'Quote pinned',
    'quote_unpinned'        => 'Quote unpinned',
    'concept_scan_run'      => 'Concept scan run',
    'codes_suggested'       => 'AI code suggestions generated',
    'member_check_added'    => 'Member check recorded',
    'memo_saved'            => 'Memo saved',
    'datamap_confirmed'     => 'Variable map confirmed',
];

foreach ($rows as &$row) {
    $action = (string)$row['action'];
    $row['action_label'] = $actionLabels[$action] ?? ucwords(str_replace('_', ' ', $action));
}
unset($row);

json_out([
    'ok'     => true,
    'total'  => $total,
    'offset' => $offset,
    'limit'  => $limit,
    'rows'   => $rows,
]);
