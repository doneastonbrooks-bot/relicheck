<?php
// GET  /api/mm/projects.php             list current user's MM Studio projects
// POST /api/mm/projects.php             create a new MM Studio project
// Body for POST: { title, pathway: 'scores_plus_comments'|'comments_only',
//                  survey_id?, dataset_id?, notes? }
//
// Mixed-Methods Studio is in DRAFT mode (Phase 155). The endpoint enforces
// auth but no tier gating yet, so admin testing can run end-to-end before the
// tier flag is added.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT * FROM mm_projects
         WHERE user_id = :uid AND status <> "archived"
         ORDER BY updated_at DESC LIMIT 200'
    );
    $stmt->execute([':uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out  = [];
    foreach ($rows as $r) $out[] = mm_project_out($r);
    json_out(['ok' => true, 'projects' => $out]);
}

// POST: create. Phase 156 wizard supplies semantics via data_kind / purpose /
// design_choice on a follow-up wizard call. pathway is kept as a legacy column
// (defaults to 'comments_only') so existing analytics keep working.
$body    = read_json_body();
$title   = clean_string((string)($body['title']   ?? ''), 200);
$pathway = clean_string((string)($body['pathway'] ?? 'comments_only'), 32);
$notes   = clean_string((string)($body['notes']   ?? ''), 4000);
$surveyId  = isset($body['survey_id'])  ? clean_string((string)$body['survey_id'], 64) : '';
$datasetId = isset($body['dataset_id']) ? (int)$body['dataset_id'] : 0;
// Multi-select friendly: accept data_kinds: [...] or legacy data_kind: "..."
$dataKindsIn = $body['data_kinds'] ?? null;
if (!is_array($dataKindsIn) && isset($body['data_kind'])) $dataKindsIn = [$body['data_kind']];
$validDataKinds = ['open_ended_only','survey_plus_open','survey_plus_separate_qual','quant_only_with_qual','from_scratch'];
$dataKinds = [];
if (is_array($dataKindsIn)) {
    foreach ($dataKindsIn as $v) {
        $v = clean_string((string)$v, 40);
        if (in_array($v, $validDataKinds, true) && !in_array($v, $dataKinds, true)) $dataKinds[] = $v;
    }
}
$dataKindsJson = count($dataKinds) > 0 ? json_encode($dataKinds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

if ($title === '') {
    fail('bad_input', 'Project title is required.');
}
if (!in_array($pathway, ['scores_plus_comments', 'comments_only'], true)) {
    $pathway = 'comments_only';
}

$stmt = $pdo->prepare(
    'INSERT INTO mm_projects (user_id, title, pathway, data_kinds, survey_id, dataset_id, notes)
     VALUES (:uid, :t, :pw, :dk, :s, :d, :n)'
);
$stmt->execute([
    ':uid' => $uid,
    ':t'   => $title,
    ':pw'  => $pathway,
    ':dk'  => $dataKindsJson,
    ':s'   => $surveyId  !== '' ? $surveyId  : null,
    ':d'   => $datasetId > 0    ? $datasetId : null,
    ':n'   => $notes !== '' ? $notes : null,
]);
$id = (int)$pdo->lastInsertId();

$row = $pdo->prepare('SELECT * FROM mm_projects WHERE id = :id');
$row->execute([':id' => $id]);
$project = $row->fetch(PDO::FETCH_ASSOC);

json_out(['ok' => true, 'project' => mm_project_out($project ?: [])]);
