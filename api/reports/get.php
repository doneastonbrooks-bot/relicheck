<?php
// GET /api/reports/get.php?id=<int>
// Returns the report row + parsed snapshot + active shares.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('GET');
check_origin();
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_input', 'Missing id.', 400);

$pdo = db();
try {
    $stmt = $pdo->prepare(
        'SELECT r.*, s.title AS source_title
           FROM reports r
           LEFT JOIN surveys s ON s.id = r.source_survey_id
          WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 148 migration has not been applied yet.', 503);
}
if (!$row) fail('not_found', 'Report not found.', 404);
if ((int)$row['user_id'] !== (int)$user['id']) {
    fail('forbidden', 'Not your report.', 403);
}

$shares = [];
try {
    $ss = $pdo->prepare(
        'SELECT id, slug, password_hash, expires_at, view_count, created_at
           FROM report_shares WHERE report_id = :rid ORDER BY created_at DESC'
    );
    $ss->execute([':rid' => $id]);
    foreach ($ss->fetchAll() as $sr) {
        $shares[] = [
            'id'           => (int)$sr['id'],
            'slug'         => (string)$sr['slug'],
            'has_password' => !empty($sr['password_hash']),
            'expires_at'   => $sr['expires_at'] ?: null,
            'view_count'   => (int)$sr['view_count'],
            'created_at'   => (string)$sr['created_at'],
        ];
    }
} catch (Throwable $_) {}

$snapshot = $row['snapshot_json'] ? (json_decode((string)$row['snapshot_json'], true) ?: null) : null;

json_out([
    'ok' => true,
    'report' => [
        'id'                => (int)$row['id'],
        'title'             => (string)$row['title'],
        'template'          => (string)$row['template'],
        'status'            => (string)$row['status'],
        'source_survey_id'  => (int)$row['source_survey_id'],
        'source_title'      => (string)($row['source_title'] ?? ''),
        'schedule_cadence'  => $row['schedule_cadence'] ?: null,
        'schedule_next_at'  => $row['schedule_next_at']  ?: null,
        'last_generated_at' => $row['last_generated_at'] ?: null,
        'created_at'        => (string)$row['created_at'],
        'updated_at'        => (string)$row['updated_at'],
        'snapshot'          => $snapshot,
        'shares'            => $shares,
    ],
]);
