<?php
// POST /api/mm/clear-tags.php
// Body: { project_id }
//
// Removes ALL theme tags (coded responses) and sentiment scores for a project,
// WITHOUT touching the themes themselves (mm_theme_categories is untouched).
// Lets a researcher reset coverage to zero and re-tag with a different method
// (keyword vs ReliCheck Intelligence) when they change their mind.
//
// Additive and non-destructive to the codebook: only the two derived tables are
// cleared, scoped to this project (and this user's codes), mirroring the wipe
// that code-existing.php / code-existing-semantic.php already do before a re-tag.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

$delCodes = $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p AND coder_id = :u');
$delCodes->execute([':p' => $projectId, ':u' => $uid]);
$clearedCodes = $delCodes->rowCount();

$delSent = $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p');
$delSent->execute([':p' => $projectId]);

json_out([
    'ok'             => true,
    'cleared_codes'  => $clearedCodes,
]);
