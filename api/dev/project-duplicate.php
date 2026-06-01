<?php
// POST /api/dev/project-duplicate.php
// Body: { id }
// Deep-copies a project the caller owns: project row + sections + items +
// constructs (NOT reviews, deployment, or responses). The copy starts as a
// draft titled "<original> (copy)". Returns the new project payload.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$body = read_json_body();
$srcId = isset($body['id']) ? (int)$body['id'] : 0;
$src   = sds_require_project($pdo, (int)$user['id'], $srcId);

try {
    $pdo->beginTransaction();

    $newTitle = clean_string($src['title'] . ' (copy)', 255);
    $pdo->prepare(
        'INSERT INTO survey_projects (user_id, title, purpose, population, response_mode, data_type, source, status, settings)
         VALUES (:uid, :title, :purpose, :pop, :mode, :dt, :src, :status, :settings)'
    )->execute([
        ':uid'      => (int)$user['id'],
        ':title'    => $newTitle,
        ':purpose'  => $src['purpose'],
        ':pop'      => $src['population'],
        ':mode'     => $src['response_mode'],
        ':dt'       => $src['data_type'],
        ':src'      => $src['source'],
        ':status'   => 'draft',
        ':settings' => $src['settings'],
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Copy sections, remembering old→new id mapping so items keep their grouping.
    $secStmt = $pdo->prepare('SELECT id, position, title, description FROM survey_sections WHERE project_id = :pid ORDER BY position, id');
    $secStmt->execute([':pid' => $srcId]);
    $secMap = [];
    $insSec = $pdo->prepare('INSERT INTO survey_sections (project_id, position, title, description) VALUES (:pid, :pos, :title, :descr)');
    foreach ($secStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $insSec->execute([':pid' => $newId, ':pos' => (int)$s['position'], ':title' => $s['title'], ':descr' => $s['description']]);
        $secMap[(int)$s['id']] = (int)$pdo->lastInsertId();
    }

    // Copy items, remapping section_id.
    $itStmt = $pdo->prepare('SELECT section_id, position, type, prompt, options, flag, settings FROM survey_items WHERE project_id = :pid ORDER BY position, id');
    $itStmt->execute([':pid' => $srcId]);
    $insItem = $pdo->prepare('INSERT INTO survey_items (project_id, section_id, position, type, prompt, options, flag, settings) VALUES (:pid, :sid, :pos, :type, :prompt, :opts, :flag, :settings)');
    foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $oldSid = $it['section_id'] !== null ? (int)$it['section_id'] : 0;
        $newSid = $oldSid > 0 && isset($secMap[$oldSid]) ? $secMap[$oldSid] : null;
        $insItem->execute([
            ':pid'    => $newId,
            ':sid'    => $newSid,
            ':pos'    => (int)$it['position'],
            ':type'   => $it['type'],
            ':prompt' => $it['prompt'],
            ':opts'   => $it['options'],
            ':flag'   => $it['flag'],
            ':settings' => $it['settings'],
        ]);
    }

    // Copy constructs.
    $consStmt = $pdo->prepare('SELECT position, name, definition FROM survey_constructs WHERE project_id = :pid ORDER BY position, id');
    $consStmt->execute([':pid' => $srcId]);
    $insCons = $pdo->prepare('INSERT INTO survey_constructs (project_id, position, name, definition) VALUES (:pid, :pos, :name, :def)');
    foreach ($consStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $insCons->execute([':pid' => $newId, ':pos' => (int)$c['position'], ':name' => $c['name'], ':def' => $c['definition']]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not duplicate project: ' . $e->getMessage(), 500);
}

json_out(array_merge(['ok' => true, 'created' => true], sds_project_payload($pdo, $newId)), 201);
