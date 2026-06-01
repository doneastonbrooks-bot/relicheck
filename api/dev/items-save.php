<?php
// POST /api/dev/items-save.php
// Body: { project_id, items: [{ id?, section_id?, type, prompt, options?, flag?, required?, settings? }] }
// Full upsert of a project's items: updates supplied+owned ids, inserts new
// rows, deletes omitted ones. Positions are reassigned from array order so the
// saved order is authoritative. Returns the saved items (with ids).

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

$incoming = (isset($body['items']) && is_array($body['items'])) ? $body['items'] : [];

try {
    $pdo->beginTransaction();

    // Valid section ids for this project (to keep section_id referential).
    $secStmt = $pdo->prepare('SELECT id FROM survey_sections WHERE project_id = :pid');
    $secStmt->execute([':pid' => $projectId]);
    $validSections = array_map('intval', array_column($secStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $exStmt = $pdo->prepare('SELECT id FROM survey_items WHERE project_id = :pid');
    $exStmt->execute([':pid' => $projectId]);
    $existing = array_map('intval', array_column($exStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $upd = $pdo->prepare('UPDATE survey_items SET section_id = :sid, position = :pos, type = :type, prompt = :prompt, options = :opts, flag = :flag, required = :req, settings = :settings WHERE id = :id AND project_id = :pid');
    $ins = $pdo->prepare('INSERT INTO survey_items (project_id, section_id, position, type, prompt, options, flag, required, settings) VALUES (:pid, :sid, :pos, :type, :prompt, :opts, :flag, :req, :settings)');

    $keptIds = [];
    $pos = 0;
    foreach ($incoming as $it) {
        if (!is_array($it)) continue;
        $prompt = clean_string((string)($it['prompt'] ?? $it['t'] ?? ''), 4000);
        if ($prompt === '') continue;

        $sectionId = isset($it['section_id']) ? (int)$it['section_id'] : 0;
        $sectionId = ($sectionId > 0 && in_array($sectionId, $validSections, true)) ? $sectionId : null;

        $type     = sds_item_type($it['type'] ?? null);
        $flag     = sds_clean_flag($it['flag'] ?? null);
        $required = !empty($it['required']) ? 1 : 0;
        $opts     = (isset($it['options'])  && is_array($it['options']))  ? json_encode($it['options'],  JSON_UNESCAPED_UNICODE) : null;
        $settings = (isset($it['settings']) && is_array($it['settings'])) ? json_encode($it['settings'], JSON_UNESCAPED_UNICODE) : null;

        $iid = isset($it['id']) ? (int)$it['id'] : 0;
        if ($iid > 0 && in_array($iid, $existing, true)) {
            $upd->execute([':sid' => $sectionId, ':pos' => $pos, ':type' => $type, ':prompt' => $prompt, ':opts' => $opts, ':flag' => $flag, ':req' => $required, ':settings' => $settings, ':id' => $iid, ':pid' => $projectId]);
            $keptIds[] = $iid;
        } else {
            $ins->execute([':pid' => $projectId, ':sid' => $sectionId, ':pos' => $pos, ':type' => $type, ':prompt' => $prompt, ':opts' => $opts, ':flag' => $flag, ':req' => $required, ':settings' => $settings]);
            $keptIds[] = (int)$pdo->lastInsertId();
        }
        $pos++;
    }

    $toDelete = array_diff($existing, $keptIds);
    if ($toDelete) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $del = $pdo->prepare("DELETE FROM survey_items WHERE project_id = ? AND id IN ($in)");
        $del->execute(array_merge([$projectId], array_values($toDelete)));
    }

    $pdo->prepare('UPDATE survey_projects SET updated_at = NOW() WHERE id = :id')->execute([':id' => $projectId]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save items: ' . $e->getMessage(), 500);
}

$payload = sds_project_payload($pdo, $projectId);
json_out(['ok' => true, 'items' => $payload['items']]);
