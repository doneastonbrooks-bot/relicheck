<?php
// POST /api/qual/save-project.php
// Updates project setup fields. Body: { project_id, title?, research_question?,
//   purpose?, data_type?, analysis_approach?, researcher_stance_memo?, notes? }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
qual_require_project($pdo, $uid, $projectId);

$fields = ['title','research_question','purpose','data_type','analysis_approach',
           'researcher_stance_memo','notes',
           'cl_analysis_purpose','cl_population_context','cl_analyst_positionality','cl_potential_misuse'];
$sets = []; $params = [':id' => $projectId];
foreach ($fields as $f) {
    if (array_key_exists($f, $body)) {
        $sets[] = "`{$f}` = :{$f}";
        $params[":{$f}"] = trim((string)$body[$f]) ?: null;
    }
}
if (!$sets) fail('bad_input', 'No fields to update.');

$pdo->prepare('UPDATE qual_projects SET ' . implode(',', $sets) . ' WHERE id = :id')
    ->execute($params);

qual_audit($pdo, $projectId, $uid, 'project_updated', 'project', $projectId);
json_out(['ok' => true]);
