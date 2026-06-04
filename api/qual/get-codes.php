<?php
// GET /api/qual/get-codes.php?project_id=N
// Returns all non-retired codes for the project with application counts.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
release_session_lock();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_check_access($pdo, $uid, $projectId);

$s = $pdo->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM qual_code_applications a WHERE a.code_id=c.id) AS application_count,
            (SELECT COUNT(DISTINCT a.segment_id) FROM qual_code_applications a WHERE a.code_id=c.id) AS segment_count
     FROM qual_codes c
     WHERE c.project_id = :p AND c.status <> 'retired'
     ORDER BY c.position ASC, c.id ASC"
);
$s->execute([':p' => $projectId]);
json_out(['ok' => true, 'codes' => $s->fetchAll(PDO::FETCH_ASSOC)]);
