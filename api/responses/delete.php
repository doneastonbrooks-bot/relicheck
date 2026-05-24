<?php
// POST /api/responses/delete.php
// Body: { id, survey_id }
// Owner-only. Removes a single response after verifying the user owns the survey.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST', 'DELETE');
check_origin();
$user = require_auth();

$body = read_json_body();
$id        = (int)($body['id']        ?? 0);
$surveyId  = (int)($body['survey_id'] ?? 0);
if ($id <= 0 || $surveyId <= 0) fail('bad_id', 'Missing or invalid id / survey_id.');

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT r.id, s.owner_id
       FROM responses r
       JOIN surveys s ON s.id = r.survey_id
      WHERE r.id = :rid AND r.survey_id = :sid LIMIT 1'
);
$stmt->execute([':rid' => $id, ':sid' => $surveyId]);
$row = $stmt->fetch();

if (!$row) fail('not_found', 'Response not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$pdo->prepare('DELETE FROM responses WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true, 'deleted_id' => $id]);
