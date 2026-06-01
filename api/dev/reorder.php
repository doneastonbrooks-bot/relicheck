<?php
// POST /api/dev/reorder.php
// Body: { project_id, item_order?: [id, ...], section_order?: [id, ...] }
// Reassigns positions to match the given id order. Ids not belonging to the
// project are ignored. Either or both orderings may be supplied.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$body      = read_json_body();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
sds_require_project($pdo, (int)$user['id'], $projectId);

$itemOrder    = (isset($body['item_order'])    && is_array($body['item_order']))    ? $body['item_order']    : [];
$sectionOrder = (isset($body['section_order']) && is_array($body['section_order'])) ? $body['section_order'] : [];

$applyOrder = function (PDO $pdo, string $table, int $projectId, array $order): void {
    if (!$order) return;
    // Restrict to ids that actually belong to this project.
    $idStmt = $pdo->prepare("SELECT id FROM {$table} WHERE project_id = :pid");
    $idStmt->execute([':pid' => $projectId]);
    $valid = array_map('intval', array_column($idStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $upd = $pdo->prepare("UPDATE {$table} SET position = :pos WHERE id = :id AND project_id = :pid");
    $pos = 0;
    foreach ($order as $rawId) {
        $id = (int)$rawId;
        if (!in_array($id, $valid, true)) continue;
        $upd->execute([':pos' => $pos, ':id' => $id, ':pid' => $projectId]);
        $pos++;
    }
};

try {
    $pdo->beginTransaction();
    $applyOrder($pdo, 'survey_sections', $projectId, $sectionOrder);
    $applyOrder($pdo, 'survey_items', $projectId, $itemOrder);
    $pdo->prepare('UPDATE survey_projects SET updated_at = NOW() WHERE id = :id')->execute([':id' => $projectId]);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not reorder: ' . $e->getMessage(), 500);
}

$payload = sds_project_payload($pdo, $projectId);
json_out(['ok' => true, 'sections' => $payload['sections'], 'items' => $payload['items']]);
