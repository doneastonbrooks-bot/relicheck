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

if ($themeId > 0) {
    $pdo->prepare(
        'UPDATE qual_themes SET name=:n, interpretive_claim=:c, notes=:no WHERE id=:id AND project_id=:p LIMIT 1'
    )->execute([':n' => $name, ':c' => $claim, ':no' => $notes, ':id' => $themeId, ':p' => $projectId]);
    qual_audit($pdo, $projectId, $uid, 'theme_updated', 'theme', $themeId, $name);
    json_out(['ok' => true, 'theme_id' => $themeId]);
} else {
    $pdo->prepare(
        'INSERT INTO qual_themes (project_id, name, interpretive_claim, notes) VALUES (:p, :n, :c, :no)'
    )->execute([':p' => $projectId, ':n' => $name, ':c' => $claim, ':no' => $notes]);
    $themeId = (int)$pdo->lastInsertId();
    qual_audit($pdo, $projectId, $uid, 'theme_created', 'theme', $themeId, $name);
    json_out(['ok' => true, 'theme_id' => $themeId]);
}
