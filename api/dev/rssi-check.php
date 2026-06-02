<?php
// GET /api/dev/rssi-check.php?project_id=N
// Returns RSSI badge data for a survey_projects row the caller owns.
// Used by the analysis studio topbar stub to show a score badge when the
// loaded dataset came from a SIRI project that already has RSSI results.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
$pid = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($pid <= 0) fail('bad_input', 'project_id is required.');

// Confirm the survey project belongs to this user.
$owns = $pdo->prepare('SELECT id FROM survey_projects WHERE id = :id AND user_id = :uid');
$owns->execute([':id' => $pid, ':uid' => (int)$user['id']]);
if (!$owns->fetch()) fail('not_found', 'Project not found.', 404);

// Look up any saved RSSI review.
$stmt = $pdo->prepare(
    'SELECT total, pct, band, withheld FROM rssi_reviews WHERE project_id = :id'
);
$stmt->execute([':id' => $pid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['ok' => true, 'has_rssi' => false]);
    exit;
}

$withheld = (bool)(int)$row['withheld'];
$pct      = $row['pct'] !== null ? (int)$row['pct'] : null;
$total    = $row['total'] !== null ? (float)$row['total'] : null;

$tier = 'withheld';
if (!$withheld && $pct !== null) {
    $tier = $pct >= 85 ? 'confident' : 'developing';
}

json_out([
    'ok'       => true,
    'has_rssi' => true,
    'score'    => $total,
    'pct'      => $pct,
    'band'     => (string)($row['band'] ?? ''),
    'withheld' => $withheld,
    'tier'     => $tier,
    'link'     => '/rssi-app.php?project_id=' . $pid,
]);
