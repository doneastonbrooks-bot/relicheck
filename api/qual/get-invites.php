<?php
// GET /api/qual/get-invites.php?project_id=N
// Returns all invites for a project plus per-coder coding stats.
// Lead researcher only.
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_require_project($pdo, $uid, $projectId);

// Total segments in project
$totalSt = $pdo->prepare("SELECT COUNT(*) FROM qual_segments WHERE project_id=:p");
$totalSt->execute([':p' => $projectId]);
$totalSegments = (int)$totalSt->fetchColumn();

// Lead coder stats (project owner = $uid)
$leadSt = $pdo->prepare(
    "SELECT COUNT(DISTINCT segment_id) AS coded
     FROM qual_code_applications
     WHERE project_id=:p AND coder_id=:u AND coder_type='human' AND action_type='applied'"
);
$leadSt->execute([':p' => $projectId, ':u' => $uid]);
$leadCoded = (int)$leadSt->fetchColumn();

$leadName = $user['name'] ?? $user['email'] ?? 'Lead coder';

// Invites
$invSt = $pdo->prepare(
    "SELECT qi.id, qi.email, qi.token, qi.status, qi.accepted_by, qi.created_at, qi.accepted_at
     FROM qual_coder_invites qi
     WHERE qi.project_id=:p ORDER BY qi.created_at DESC"
);
$invSt->execute([':p' => $projectId]);
$invites = $invSt->fetchAll(PDO::FETCH_ASSOC);

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'relichecksurvey.com');

foreach ($invites as &$inv) {
    $inv['invite_url'] = $baseUrl . '/qual-coder.php?token=' . urlencode($inv['token']);
    $inv['coded']      = 0;
    $inv['coder_name'] = null;
    if ($inv['accepted_by']) {
        $acceptedUid = (int)$inv['accepted_by'];
        // Coding stats for the second coder
        $scSt = $pdo->prepare(
            "SELECT COUNT(DISTINCT segment_id) FROM qual_code_applications
             WHERE project_id=:p AND coder_id=:u AND coder_type='human' AND action_type='applied'"
        );
        $scSt->execute([':p' => $projectId, ':u' => $acceptedUid]);
        $inv['coded'] = (int)$scSt->fetchColumn();
        // Name lookup
        try {
            $uSt = $pdo->prepare("SELECT name, email FROM users WHERE id=:id LIMIT 1");
            $uSt->execute([':id' => $acceptedUid]);
            $uRow = $uSt->fetch(PDO::FETCH_ASSOC);
            if ($uRow) $inv['coder_name'] = $uRow['name'] ?: $uRow['email'];
        } catch (Throwable $_) {}
    }
}
unset($inv);

json_out([
    'ok'             => true,
    'total_segments' => $totalSegments,
    'lead'           => [
        'uid'    => $uid,
        'name'   => $leadName,
        'coded'  => $leadCoded,
    ],
    'invites'        => $invites,
]);
