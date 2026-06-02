<?php
// POST /api/qual/create-project.php
// Creates a qual_project + rc_projects parent row.
// Body: { title, research_question?, purpose?, data_type?, analysis_approach?,
//         researcher_stance_memo?, notes? }
// Returns: { ok, project_id, rc_project_id }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_rc_projects.php';
require_once __DIR__ . '/_qual_studio.php';   // wait — path is relative to this file

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = json_body();

$title = trim((string)($body['title'] ?? ''));
if ($title === '') fail('bad_input', 'title is required.');

qual_ensure_schema($pdo);
rc_ensure_project_schema($pdo);

$pdo->beginTransaction();
try {
    // rc_projects parent
    $rcId = rc_create_project($pdo, $uid, $title, (string)($body['purpose'] ?? ''));

    // qual_projects row
    $s = $pdo->prepare(
        'INSERT INTO qual_projects
         (rc_project_id,user_id,title,research_question,purpose,data_type,
          analysis_approach,researcher_stance_memo,notes)
         VALUES (:rc,:u,:t,:rq,:pu,:dt,:aa,:rs,:n)'
    );
    $s->execute([
        ':rc' => $rcId,
        ':u'  => $uid,
        ':t'  => $title,
        ':rq' => trim((string)($body['research_question'] ?? '')) ?: null,
        ':pu' => trim((string)($body['purpose'] ?? '')) ?: null,
        ':dt' => $body['data_type'] ?? 'open_ended_survey',
        ':aa' => $body['analysis_approach'] ?? 'thematic',
        ':rs' => trim((string)($body['researcher_stance_memo'] ?? '')) ?: null,
        ':n'  => trim((string)($body['notes'] ?? '')) ?: null,
    ]);
    $projectId = (int)$pdo->lastInsertId();

    qual_audit($pdo, $projectId, $uid, 'project_created', 'project', $projectId, $title);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('qual/create-project: ' . $e->getMessage());
    fail('db_error', 'Could not create project.');
}

json_out(['ok' => true, 'project_id' => $projectId, 'rc_project_id' => $rcId]);
