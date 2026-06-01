<?php
// POST /api/dev/constructs-save.php
// Body: { project_id, constructs: [{ id?, name, definition?, position? }] }
// Full upsert of a project's construct definitions: updates supplied+owned ids,
// reuses an existing row when a new (id-less) construct matches an existing name
// (so repeated saves never duplicate), inserts genuinely new ones, and deletes
// any omitted. Positions are reassigned from array order. Returns the saved
// constructs (with ids) so the client can realign item->construct mappings.

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

$incoming = (isset($body['constructs']) && is_array($body['constructs'])) ? $body['constructs'] : [];

try {
    $pdo->beginTransaction();

    // Existing rows for this project, indexed by id and by lower-cased name.
    $exStmt = $pdo->prepare('SELECT id, name FROM survey_constructs WHERE project_id = :pid');
    $exStmt->execute([':pid' => $projectId]);
    $existingRows = $exStmt->fetchAll(PDO::FETCH_ASSOC);
    $existingIds  = array_map('intval', array_column($existingRows, 'id'));
    $idByName     = [];
    foreach ($existingRows as $row) {
        $idByName[mb_strtolower(trim((string)$row['name']))] = (int)$row['id'];
    }

    $upd = $pdo->prepare('UPDATE survey_constructs SET position = :pos, name = :name, definition = :def WHERE id = :id AND project_id = :pid');
    $ins = $pdo->prepare('INSERT INTO survey_constructs (project_id, position, name, definition) VALUES (:pid, :pos, :name, :def)');

    $keptIds = [];
    $pos = 0;
    foreach ($incoming as $c) {
        if (!is_array($c)) continue;
        $name = clean_string((string)($c['name'] ?? ''), 255);
        if ($name === '') continue; // never store nameless constructs
        $def  = clean_string((string)($c['definition'] ?? ''), 4000);
        $defVal = $def !== '' ? $def : null;

        $cid = isset($c['id']) ? (int)$c['id'] : 0;
        if ($cid <= 0 || !in_array($cid, $existingIds, true)) {
            // No usable id: reuse a same-named row if one exists, else insert.
            $cid = $idByName[mb_strtolower($name)] ?? 0;
        }

        if ($cid > 0 && in_array($cid, $existingIds, true) && !in_array($cid, $keptIds, true)) {
            $upd->execute([':pos' => $pos, ':name' => $name, ':def' => $defVal, ':id' => $cid, ':pid' => $projectId]);
            $keptIds[] = $cid;
        } else {
            $ins->execute([':pid' => $projectId, ':pos' => $pos, ':name' => $name, ':def' => $defVal]);
            $keptIds[] = (int)$pdo->lastInsertId();
        }
        $pos++;
    }

    $toDelete = array_diff($existingIds, $keptIds);
    if ($toDelete) {
        $in  = implode(',', array_fill(0, count($toDelete), '?'));
        $del = $pdo->prepare("DELETE FROM survey_constructs WHERE project_id = ? AND id IN ($in)");
        $del->execute(array_merge([$projectId], array_values($toDelete)));
    }

    $pdo->prepare('UPDATE survey_projects SET updated_at = NOW() WHERE id = :id')->execute([':id' => $projectId]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save constructs: ' . $e->getMessage(), 500);
}

$payload = sds_project_payload($pdo, $projectId);
json_out(['ok' => true, 'constructs' => $payload['constructs']]);
