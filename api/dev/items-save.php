<?php
// POST /api/dev/items-save.php
// Body: { project_id, items: [{ id?, section_id?, type, prompt, options?, flag?, required?, settings? }] }
// Full upsert of a project's items: updates supplied+owned ids, inserts new
// rows, deletes omitted ones. Positions are reassigned from array order so the
// saved order is authoritative. Returns the saved items (with ids).

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/_type_taxonomy.php';

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

    $keptIds      = [];
    $savedItemMeta = [];   // iid => [type, settingsArr, prompt, pos] for variable_metadata upsert
    $pos = 0;
    foreach ($incoming as $it) {
        if (!is_array($it)) continue;
        $prompt = clean_string((string)($it['prompt'] ?? $it['t'] ?? ''), 4000);
        if ($prompt === '') continue;

        $sectionId = isset($it['section_id']) ? (int)$it['section_id'] : 0;
        $sectionId = ($sectionId > 0 && in_array($sectionId, $validSections, true)) ? $sectionId : null;

        $type        = sds_item_type($it['type'] ?? null);
        $flag        = sds_clean_flag($it['flag'] ?? null);
        $required    = !empty($it['required']) ? 1 : 0;
        $opts        = (isset($it['options'])  && is_array($it['options']))  ? json_encode($it['options'],  JSON_UNESCAPED_UNICODE) : null;
        $settingsArr = (isset($it['settings']) && is_array($it['settings'])) ? $it['settings'] : [];
        $settings    = $settingsArr ? json_encode($settingsArr, JSON_UNESCAPED_UNICODE) : null;

        $iid = isset($it['id']) ? (int)$it['id'] : 0;
        if ($iid > 0 && in_array($iid, $existing, true)) {
            $upd->execute([':sid' => $sectionId, ':pos' => $pos, ':type' => $type, ':prompt' => $prompt, ':opts' => $opts, ':flag' => $flag, ':req' => $required, ':settings' => $settings, ':id' => $iid, ':pid' => $projectId]);
            $keptIds[] = $iid;
        } else {
            $ins->execute([':pid' => $projectId, ':sid' => $sectionId, ':pos' => $pos, ':type' => $type, ':prompt' => $prompt, ':opts' => $opts, ':flag' => $flag, ':req' => $required, ':settings' => $settings]);
            $iid = (int)$pdo->lastInsertId();
            $keptIds[] = $iid;
        }
        $savedItemMeta[$iid] = ['type' => $type, 'settings' => $settingsArr, 'prompt' => $prompt, 'pos' => $pos];
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

// ── Sync variable_metadata (RE infrastructure) ──────────────────────────────
// Upsert one variable_metadata row per saved item so RSSI and the Data Map
// have canonical analysis_type and construct_id at design time.
// Structural items are included but marked include_in_analysis=0.
// construct_id is written from settings.constructId when present — constructs
// are saved before items in App.saveAll(), so the FK is valid.
try {
    $vmUpsert = $pdo->prepare(
        'INSERT INTO variable_metadata
             (project_id, project_type, variable_name, display_label, source,
              survey_item_id, storage_type, analysis_type, measurement_level,
              construct_id, include_in_analysis, position)
         VALUES
             (:pid, :ptype, :vname, :dlabel, :src,
              :siid, :stype, :atype, :mlevel,
              :cid, :include, :pos)
         ON DUPLICATE KEY UPDATE
             display_label       = VALUES(display_label),
             survey_item_id      = VALUES(survey_item_id),
             storage_type        = VALUES(storage_type),
             analysis_type       = VALUES(analysis_type),
             measurement_level   = VALUES(measurement_level),
             construct_id        = VALUES(construct_id),
             include_in_analysis = VALUES(include_in_analysis),
             position            = VALUES(position),
             updated_at          = NOW()'
    );

    foreach ($savedItemMeta as $iid => $m) {
        $constructId  = isset($m['settings']['constructId']) && $m['settings']['constructId'] !== ''
                      ? (int)$m['settings']['constructId'] : null;
        $analysisType = rc_analysis_type($m['type'], $constructId);
        $isStructural = ($analysisType === 'structural');

        $storageHint  = null;
        if (in_array($analysisType, ['likert_item', 'binary', 'demographic_numeric'], true)) {
            $storageHint = 'INT';
        } elseif (in_array($analysisType, ['open_ended', 'narrative'], true)) {
            $storageHint = 'TEXT';
        } elseif (in_array($analysisType, ['demographic_nominal', 'demographic_ordinal'], true)) {
            $storageHint = 'VARCHAR';
        }

        $vmUpsert->execute([
            ':pid'     => $projectId,
            ':ptype'   => 'survey',
            ':vname'   => 'item_' . $iid,
            ':dlabel'  => mb_substr($m['prompt'], 0, 255) ?: null,
            ':src'     => 'siri_item',
            ':siid'    => $iid,
            ':stype'   => $storageHint,
            ':atype'   => $analysisType,
            ':mlevel'  => rc_measurement_level($analysisType),
            ':cid'     => $constructId,
            ':include' => $isStructural ? 0 : 1,
            ':pos'     => $m['pos'],
        ]);
    }

    // Remove variable_metadata rows for items that were deleted.
    if ($toDelete) {
        $ph = implode(',', array_fill(0, count($toDelete), '?'));
        $pdo->prepare("DELETE FROM variable_metadata
                         WHERE project_id = ? AND project_type = 'survey'
                           AND survey_item_id IN ($ph)")
            ->execute(array_merge([$projectId], array_values($toDelete)));
    }
} catch (\Throwable $e) {
    // variable_metadata sync failure is non-fatal: items are already saved.
    // Log silently; the Data Map step can re-derive metadata on next open.
    error_log('items-save: variable_metadata sync failed: ' . $e->getMessage());
}

$payload = sds_project_payload($pdo, $projectId);
json_out(['ok' => true, 'items' => $payload['items']]);
