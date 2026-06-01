<?php
// POST /api/dev/sections-save.php
// Body: { project_id, sections: [{ id?, title, description?, position? }] }
// Full upsert of a project's sections: updates rows whose id is supplied and
// owned, inserts rows with no id, deletes any existing section omitted from the
// payload (its items keep their data; section_id is set NULL by the FK).
// Positions are reassigned from array order. Returns the saved sections.

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

$incoming = (isset($body['sections']) && is_array($body['sections'])) ? $body['sections'] : [];

try {
    $pdo->beginTransaction();

    // Existing section ids for this project (for ownership + delete diff).
    $exStmt = $pdo->prepare('SELECT id FROM survey_sections WHERE project_id = :pid');
    $exStmt->execute([':pid' => $projectId]);
    $existing = array_map('intval', array_column($exStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $upd = $pdo->prepare('UPDATE survey_sections SET position = :pos, title = :title, description = :descr WHERE id = :id AND project_id = :pid');
    $ins = $pdo->prepare('INSERT INTO survey_sections (project_id, position, title, description) VALUES (:pid, :pos, :title, :descr)');

    $keptIds = [];
    $pos = 0;
    foreach ($incoming as $s) {
        if (!is_array($s)) continue;
        $title = clean_string((string)($s['title'] ?? 'Section'), 255) ?: 'Section';
        $descr = ($d = clean_string((string)($s['description'] ?? ''), 2000)) !== '' ? $d : null;
        $sid   = isset($s['id']) ? (int)$s['id'] : 0;
        if ($sid > 0 && in_array($sid, $existing, true)) {
            $upd->execute([':pos' => $pos, ':title' => $title, ':descr' => $descr, ':id' => $sid, ':pid' => $projectId]);
            $keptIds[] = $sid;
        } else {
            $ins->execute([':pid' => $projectId, ':pos' => $pos, ':title' => $title, ':descr' => $descr]);
            $keptIds[] = (int)$pdo->lastInsertId();
        }
        $pos++;
    }

    // Delete omitted sections.
    $toDelete = array_diff($existing, $keptIds);
    if ($toDelete) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $del = $pdo->prepare("DELETE FROM survey_sections WHERE project_id = ? AND id IN ($in)");
        $del->execute(array_merge([$projectId], array_values($toDelete)));
    }

    // Touch the project so updated_at reflects the edit.
    $pdo->prepare('UPDATE survey_projects SET updated_at = NOW() WHERE id = :id')->execute([':id' => $projectId]);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save sections: ' . $e->getMessage(), 500);
}

$payload = sds_project_payload($pdo, $projectId);
json_out(['ok' => true, 'sections' => $payload['sections']]);
