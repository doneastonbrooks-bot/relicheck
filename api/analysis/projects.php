<?php
// GET  /api/analysis/projects.php?kind=descriptive|inferential
//        list the current user's projects for that studio.
// POST /api/analysis/projects.php  { kind, title, dataset_id?, notes? }
//        create a new analysis project.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_dataset_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_analysis_studio.php';
require_once __DIR__ . '/../_rc_projects.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
analysis_ensure_schema($pdo);
rc_ensure_project_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $kind = clean_string((string)($_GET['kind'] ?? ''), 20);
    if (!analysis_valid_kind($kind)) fail('bad_kind', 'kind must be descriptive or inferential.');
    // Light list: never pull the (potentially large) dataset_payload — just a
    // boolean has_data flag derived from it / the dataset_id link.
    $stmt = $pdo->prepare(
        'SELECT id, kind, title, dataset_id, status, notes, created_at, updated_at,
                (dataset_payload IS NOT NULL OR dataset_id IS NOT NULL) AS has_data
           FROM analysis_projects
          WHERE user_id = :uid AND kind = :kind AND status <> "archived"
          ORDER BY updated_at DESC LIMIT 200'
    );
    $stmt->execute([':uid' => $uid, ':kind' => $kind]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out  = [];
    foreach ($rows as $r) $out[] = analysis_project_out($r);
    json_out(['ok' => true, 'projects' => $out]);
}

// POST: create.
$body      = read_json_body();
$kind      = clean_string((string)($body['kind'] ?? ''), 20);
$title     = clean_string((string)($body['title'] ?? ''), 200);
$notes     = clean_string((string)($body['notes'] ?? ''), 4000);
$datasetId = isset($body['dataset_id']) ? (int)$body['dataset_id'] : 0;

if (!analysis_valid_kind($kind)) fail('bad_kind', 'kind must be descriptive or inferential.');
if ($title === '') $title = ($kind === 'descriptive' ? 'Untitled descriptive analysis' : 'Untitled inferential analysis');

// If a dataset_id is supplied, it must belong to this user.
if ($datasetId > 0) {
    $own = $pdo->prepare('SELECT id FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
    $own->execute([':id' => $datasetId, ':uid' => $uid]);
    if (!$own->fetch()) $datasetId = 0;
}

$id = 0; $rcId = 0;
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO analysis_projects (user_id, kind, title, dataset_id, notes)
         VALUES (:uid, :kind, :t, :d, :n)'
    )->execute([
        ':uid'  => $uid,
        ':kind' => $kind,
        ':t'    => $title,
        ':d'    => $datasetId > 0 ? $datasetId : null,
        ':n'    => $notes !== '' ? $notes : null,
    ]);
    $id = (int)$pdo->lastInsertId();

    // RE Item 3: create ecosystem project record and link.
    $rcId = rc_create_project($pdo, $uid, $title, $notes ?: null);
    $pdo->prepare('UPDATE analysis_projects SET rc_project_id = :r WHERE id = :id')
        ->execute([':r' => $rcId, ':id' => $id]);

    if ($datasetId > 0) rc_set_project_dataset($pdo, $rcId, $datasetId);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not create project: ' . $e->getMessage(), 500);
}

$row = $pdo->prepare('SELECT * FROM analysis_projects WHERE id = :id');
$row->execute([':id' => $id]);
$project = $row->fetch(PDO::FETCH_ASSOC);

if ($datasetId > 0) {
    rc_seed_var_meta_from_dataset($pdo, $id, 'analysis', $datasetId, $rcId);
}

json_out(['ok' => true, 'project' => analysis_project_out($project ?: [])]);
