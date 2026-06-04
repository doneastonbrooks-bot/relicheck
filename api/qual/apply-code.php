<?php
// POST /api/qual/apply-code.php
// Apply (or confirm) a code to a segment for the current coder.
// Body: { project_id, segment_id, code_id, memo?, selected_text? }
// Returns: { ok }

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
$segmentId = (int)($body['segment_id'] ?? 0);
$codeId    = (int)($body['code_id']    ?? 0);
if ($projectId <= 0 || $segmentId <= 0 || $codeId <= 0) fail('bad_input', 'project_id, segment_id, code_id required.');

qual_check_access($pdo, $uid, $projectId);

// Verify segment + code belong to this project
$seg = $pdo->prepare('SELECT id FROM qual_segments WHERE id=:id AND project_id=:p LIMIT 1');
$seg->execute([':id' => $segmentId, ':p' => $projectId]);
if (!$seg->fetch()) fail('not_found', 'Segment not found.', 404);

$code = $pdo->prepare('SELECT id,name FROM qual_codes WHERE id=:id AND project_id=:p LIMIT 1');
$code->execute([':id' => $codeId, ':p' => $projectId]);
$codeRow = $code->fetch(PDO::FETCH_ASSOC);
if (!$codeRow) fail('not_found', 'Code not found.', 404);

$memo         = trim((string)($body['memo']          ?? '')) ?: null;
$selectedText = trim((string)($body['selected_text'] ?? '')) ?: null;

$pdo->prepare(
    'INSERT INTO qual_code_applications
     (project_id,segment_id,code_id,coder_id,coder_type,selected_text,action_type,memo)
     VALUES (:p,:s,:c,:u,"human",:st,"applied",:m)
     ON DUPLICATE KEY UPDATE selected_text=VALUES(selected_text), memo=VALUES(memo), action_type="applied"'
)->execute([
    ':p'  => $projectId,
    ':s'  => $segmentId,
    ':c'  => $codeId,
    ':u'  => $uid,
    ':st' => $selectedText,
    ':m'  => $memo,
]);

json_out(['ok' => true]);
