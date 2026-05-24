<?php
// POST /api/reports/regenerate.php
// Body: { id }
// Rebuilds the report's snapshot from the source survey's live analytics.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_reports_snapshot.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_input', 'Missing id.', 400);

$pdo = db();
$own = $pdo->prepare('SELECT user_id, source_survey_id FROM reports WHERE id = :id LIMIT 1');
try { $own->execute([':id' => $id]); } catch (Throwable $e) {
    fail('migration_pending', 'Phase 148 migration has not been applied yet.', 503);
}
$row = $own->fetch();
if (!$row) fail('not_found', 'Report not found.', 404);
if ((int)$row['user_id'] !== (int)$user['id']) {
    fail('forbidden', 'Not your report.', 403);
}

$surveyId = (int)$row['source_survey_id'];
$snap = reports_build_snapshot($surveyId);
$snapJson = json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$pdo->prepare(
    'UPDATE reports SET snapshot_json = :snap, last_generated_at = NOW() WHERE id = :id'
)->execute([':snap' => $snapJson, ':id' => $id]);

json_out(['ok' => true, 'snapshot' => $snap, 'last_generated_at' => date('Y-m-d H:i:s')]);
