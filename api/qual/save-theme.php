<?php
// POST /api/qual/save-theme.php
// Create or update a qual_theme.
// Body: { project_id, id?(update), name, interpretive_claim, notes? }
// Returns: { ok, theme_id }

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

$name    = trim((string)($body['name']              ?? ''));
$claim   = trim((string)($body['interpretive_claim']?? ''));
$notes   = trim((string)($body['notes']             ?? '')) ?: null;
if ($name  === '') fail('bad_input', 'name is required.');
if ($claim === '') fail('bad_input', 'interpretive_claim is required.');

$themeId = (int)($body['id'] ?? 0);

$clCtx  = trim((string)($body['cl_context']           ?? '')) ?: null;
$clGrp  = trim((string)($body['cl_group_variation']   ?? '')) ?: null;
$clPat  = trim((string)($body['cl_pattern_type']      ?? '')) ?: null;
$clCtr  = trim((string)($body['cl_counter_story']     ?? '')) ?: null;
$clSf   = trim((string)($body['cl_structural_framing']?? '')) ?: null;
$clAct  = trim((string)($body['cl_action_caution']    ?? '')) ?: null;

if ($themeId > 0) {
    $pdo->prepare(
        'UPDATE qual_themes SET name=:n, interpretive_claim=:c, notes=:no,
         cl_context=:cl_ctx, cl_group_variation=:cl_grp, cl_pattern_type=:cl_pat,
         cl_counter_story=:cl_ctr, cl_structural_framing=:cl_sf, cl_action_caution=:cl_act
         WHERE id=:id AND project_id=:p LIMIT 1'
    )->execute([
        ':n' => $name, ':c' => $claim, ':no' => $notes,
        ':cl_ctx' => $clCtx, ':cl_grp' => $clGrp, ':cl_pat' => $clPat,
        ':cl_ctr' => $clCtr, ':cl_sf'  => $clSf,  ':cl_act' => $clAct,
        ':id' => $themeId, ':p' => $projectId,
    ]);
    qual_audit($pdo, $projectId, $uid, 'theme_updated', 'theme', $themeId, $name);
    json_out(['ok' => true, 'theme_id' => $themeId]);
} else {
    $pdo->prepare(
        'INSERT INTO qual_themes
         (project_id, name, interpretive_claim, notes,
          cl_context, cl_group_variation, cl_pattern_type,
          cl_counter_story, cl_structural_framing, cl_action_caution)
         VALUES (:p, :n, :c, :no, :cl_ctx, :cl_grp, :cl_pat, :cl_ctr, :cl_sf, :cl_act)'
    )->execute([
        ':p' => $projectId, ':n' => $name, ':c' => $claim, ':no' => $notes,
        ':cl_ctx' => $clCtx, ':cl_grp' => $clGrp, ':cl_pat' => $clPat,
        ':cl_ctr' => $clCtr, ':cl_sf'  => $clSf,  ':cl_act' => $clAct,
    ]);
    $themeId = (int)$pdo->lastInsertId();
    qual_audit($pdo, $projectId, $uid, 'theme_created', 'theme', $themeId, $name);
    json_out(['ok' => true, 'theme_id' => $themeId]);
}
