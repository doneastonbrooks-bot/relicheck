<?php
// GET /api/qual/scan-pii.php?project_id=N
// Scans all active segments for common PII patterns using regex.
// Returns: { ok, total_segments, flag_count, flagged: [{segment_id, original, patterns:[{type,match}]}] }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
qual_require_project($pdo, $uid, $projectId);

$stmt = $pdo->prepare(
    "SELECT id, raw_text FROM qual_segments
     WHERE project_id = :p AND status = 'active'
     ORDER BY id ASC"
);
$stmt->execute([':p' => $projectId]);
$segments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$patterns = [
    'email'  => '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
    'phone'  => '/\b(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}\b/',
    'ssn'    => '/\b\d{3}-\d{2}-\d{4}\b/',
    'name_intro' => '/\b(?:my name is|i am|i\'m)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i',
];

$flagged = [];
foreach ($segments as $seg) {
    $found = [];
    foreach ($patterns as $type => $rx) {
        if (preg_match_all($rx, (string)$seg['raw_text'], $matches)) {
            foreach ($matches[0] as $match) {
                $found[] = ['type' => $type, 'match' => $match];
            }
        }
    }
    if (count($found) > 0) {
        $flagged[] = [
            'segment_id' => (int)$seg['id'],
            'original'   => $seg['raw_text'],
            'patterns'   => $found,
        ];
    }
}

json_out([
    'ok'              => true,
    'total_segments'  => count($segments),
    'flag_count'      => count($flagged),
    'flagged'         => $flagged,
]);
