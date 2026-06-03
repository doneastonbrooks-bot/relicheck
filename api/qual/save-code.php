<?php
// POST /api/qual/save-code.php
// Create or update a qual_code.
// Body: { project_id, id?(update), name, definition?, include_when?,
//         exclude_when?, example_quote?, status? }
// Returns: { ok, code_id }

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

$name = trim((string)($body['name'] ?? ''));
if ($name === '') fail('bad_input', 'name is required.');

$codeId = (int)($body['id'] ?? 0);

if ($codeId > 0) {
    // Update
    $existing = $pdo->prepare('SELECT id,name FROM qual_codes WHERE id=:id AND project_id=:p LIMIT 1');
    $existing->execute([':id' => $codeId, ':p' => $projectId]);
    if (!$existing->fetch()) fail('not_found', 'Code not found.', 404);

    $pdo->prepare(
        'UPDATE qual_codes SET name=:n, definition=:d, include_when=:i, exclude_when=:e,
         example_quote=:q, status=:s WHERE id=:id AND project_id=:p'
    )->execute([
        ':n'  => $name,
        ':d'  => trim((string)($body['definition']   ?? '')) ?: null,
        ':i'  => trim((string)($body['include_when'] ?? '')) ?: null,
        ':e'  => trim((string)($body['exclude_when'] ?? '')) ?: null,
        ':q'  => trim((string)($body['example_quote']?? '')) ?: null,
        ':s'  => in_array($body['status'] ?? '', ['draft','reviewed','approved','retired'], true)
                 ? $body['status'] : 'draft',
        ':id' => $codeId,
        ':p'  => $projectId,
    ]);
    qual_audit($pdo, $projectId, $uid, 'code_updated', 'code', $codeId, $name);
    json_out(['ok' => true, 'code_id' => $codeId]);
} else {
    // Create
    $pdo->prepare(
        'INSERT INTO qual_codes (project_id,name,definition,include_when,exclude_when,example_quote,status,created_by_type)
         VALUES (:p,:n,:d,:i,:e,:q,:s,"human")'
    )->execute([
        ':p' => $projectId,
        ':n' => $name,
        ':d' => trim((string)($body['definition']   ?? '')) ?: null,
        ':i' => trim((string)($body['include_when'] ?? '')) ?: null,
        ':e' => trim((string)($body['exclude_when'] ?? '')) ?: null,
        ':q' => trim((string)($body['example_quote']?? '')) ?: null,
        ':s' => 'draft',
    ]);
    $codeId = (int)$pdo->lastInsertId();
    qual_audit($pdo, $projectId, $uid, 'code_created', 'code', $codeId, $name);
    json_out(['ok' => true, 'code_id' => $codeId]);
}
