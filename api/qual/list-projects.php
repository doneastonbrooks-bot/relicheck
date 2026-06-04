<?php
// GET /api/qual/list-projects.php
// Returns the current user's qual projects, newest first, with segment counts.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];

qual_ensure_schema($pdo);

$s = $pdo->prepare(
    "SELECT p.id, p.title, p.analysis_approach, p.data_type, p.status, p.updated_at,
            (SELECT COUNT(*) FROM qual_segments s WHERE s.project_id=p.id AND s.status='active') AS seg_count,
            (SELECT COUNT(*) FROM qual_codes   c WHERE c.project_id=p.id AND c.status <> 'retired') AS code_count
     FROM qual_projects p
     WHERE p.user_id = :uid AND p.status <> 'archived'
     ORDER BY p.updated_at DESC
     LIMIT 50"
);
$s->execute([':uid' => $uid]);
json_out(['ok' => true, 'projects' => $s->fetchAll(PDO::FETCH_ASSOC)]);
