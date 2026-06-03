<?php
// POST /api/qual/remove-code.php
// Remove a code application from a segment for the current coder.
// Body: { project_id, segment_id, code_id }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$segmentId = (int)($body['segment_id'] ?? 0);
$codeId    = (int)($body['code_id']    ?? 0);
if ($projectId <= 0 || $segmentId <= 0 || $codeId <= 0) fail('bad_input', 'project_id, segment_id, code_id required.');

qual_require_project($pdo, $uid, $projectId);

$pdo->prepare(
    'DELETE FROM qual_code_applications WHERE segment_id=:s AND code_id=:c AND coder_id=:u AND project_id=:p'
)->execute([':s' => $segmentId, ':c' => $codeId, ':u' => $uid, ':p' => $projectId]);

json_out(['ok' => true]);
