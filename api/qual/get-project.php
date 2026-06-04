<?php
// GET /api/qual/get-project.php?project_id=N
// Returns project row + document/segment/code counts.

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

$project = qual_require_project($pdo, $uid, $projectId);

// Counts
$counts = $pdo->prepare(
    'SELECT
       (SELECT COUNT(*) FROM qual_documents WHERE project_id=:p AND status="active")        AS doc_count,
       (SELECT COUNT(*) FROM qual_segments  WHERE project_id=:p AND status="active")        AS seg_count,
       (SELECT COUNT(*) FROM qual_codes     WHERE project_id=:p AND status <> "retired")    AS code_count,
       (SELECT COUNT(*) FROM qual_code_applications WHERE project_id=:p)                    AS application_count,
       (SELECT SUM(word_count) FROM qual_segments WHERE project_id=:p AND status="active")  AS total_words,
       (SELECT AVG(word_count) FROM qual_segments WHERE project_id=:p AND status="active")  AS avg_words
    FROM dual'
);
$counts->execute([':p' => $projectId]);
$stats = $counts->fetch(PDO::FETCH_ASSOC);

// Documents
$docs = $pdo->prepare(
    'SELECT id,title,source_type,created_at FROM qual_documents WHERE project_id=:p AND status="active" ORDER BY id'
);
$docs->execute([':p' => $projectId]);

json_out([
    'ok'      => true,
    'project' => $project,
    'stats'   => [
        'doc_count'         => (int)($stats['doc_count']         ?? 0),
        'seg_count'         => (int)($stats['seg_count']         ?? 0),
        'code_count'        => (int)($stats['code_count']        ?? 0),
        'application_count' => (int)($stats['application_count'] ?? 0),
        'total_words'       => (int)($stats['total_words']       ?? 0),
        'avg_words'         => round((float)($stats['avg_words'] ?? 0), 1),
    ],
    'documents' => $docs->fetchAll(PDO::FETCH_ASSOC),
]);
