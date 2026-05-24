<?php
// GET /api/panels/get.php?id=<panel_id>
// Full panel detail: panel metadata, all subjects, all evaluators grouped by
// subject. Used by the panel detail view in app.html.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_panels.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

$panel = panels_require_owned($id, (int)$user['id']);

$pdo = db();

$sStmt = $pdo->prepare(
    'SELECT id, name, email, title, department, external_ref, created_at
       FROM survey_360_subjects
      WHERE panel_id = :pid
      ORDER BY id ASC'
);
$sStmt->execute([':pid' => $id]);
$subjects = $sStmt->fetchAll();

$eStmt = $pdo->prepare(
    'SELECT id, panel_id, subject_id, evaluator_email, evaluator_name,
            relationship, invitation_id, status, created_at, updated_at
       FROM survey_360_evaluators
      WHERE panel_id = :pid
      ORDER BY subject_id ASC, id ASC'
);
$eStmt->execute([':pid' => $id]);
$evaluators = $eStmt->fetchAll();

$bySubject = [];
foreach ($evaluators as $ev) {
    $sub = (int)$ev['subject_id'];
    if (!isset($bySubject[$sub])) $bySubject[$sub] = [];
    $bySubject[$sub][] = [
        'id'              => (int)$ev['id'],
        'evaluator_email' => (string)$ev['evaluator_email'],
        'evaluator_name'  => $ev['evaluator_name'] !== null ? (string)$ev['evaluator_name'] : '',
        'relationship'    => (string)$ev['relationship'],
        'invitation_id'   => $ev['invitation_id'] !== null ? (int)$ev['invitation_id'] : null,
        'status'          => (string)$ev['status'],
        'created_at'      => (string)$ev['created_at'],
    ];
}

$subjectsOut = [];
foreach ($subjects as $s) {
    $sid = (int)$s['id'];
    $list = $bySubject[$sid] ?? [];
    $completed = 0;
    foreach ($list as $row) if ($row['status'] === 'completed') $completed++;
    $subjectsOut[] = [
        'id'              => $sid,
        'name'            => (string)$s['name'],
        'email'           => $s['email'] !== null ? (string)$s['email'] : '',
        'title'           => $s['title'] !== null ? (string)$s['title'] : '',
        'department'      => $s['department'] !== null ? (string)$s['department'] : '',
        'external_ref'    => $s['external_ref'] !== null ? (string)$s['external_ref'] : '',
        'created_at'      => (string)$s['created_at'],
        'evaluators'      => $list,
        'evaluator_count' => count($list),
        'completed_count' => $completed,
    ];
}

json_out([
    'ok'    => true,
    'panel' => [
        'id'                   => (int)$panel['id'],
        'survey_id'            => (int)$panel['survey_id'],
        'survey_title'         => (string)($panel['survey_title'] ?? ''),
        'name'                 => (string)$panel['name'],
        'status'               => (string)$panel['status'],
        'self_assessment'      => (int)$panel['self_assessment'] === 1,
        'confidentiality_mode' => (string)$panel['confidentiality_mode'],
        'launched_at'          => $panel['launched_at'] !== null ? (string)$panel['launched_at'] : null,
        'closed_at'            => $panel['closed_at']   !== null ? (string)$panel['closed_at']   : null,
        'created_at'           => (string)$panel['created_at'],
        'updated_at'           => (string)$panel['updated_at'],
    ],
    'subjects' => $subjectsOut,
]);
