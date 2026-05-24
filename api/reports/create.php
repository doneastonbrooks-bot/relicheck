<?php
// POST /api/reports/create.php
// Body: { source_survey_id, template?, title? }
// Creates a new report, builds the initial snapshot, returns the row.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_reports_snapshot.php';

require_method('POST');
check_origin();
$user = require_auth();

$body     = read_json_body();
$surveyId = (int)($body['source_survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_input', 'Missing source_survey_id.', 400);

$template = clean_string((string)($body['template'] ?? 'executive'), 40);
$validTemplates = ['executive', 'methods', 'findings'];
if (!in_array($template, $validTemplates, true)) $template = 'executive';

$titleIn = clean_string((string)($body['title'] ?? ''), 240);

$pdo = db();

// Verify ownership of the source survey. ReliCheck's surveys table uses
// owner_id (see db/schema.sql Phase 1); some older code paths still query
// user_id, so try both for resilience.
try {
    $own = $pdo->prepare('SELECT id, owner_id, title FROM surveys WHERE id = :id LIMIT 1');
    $own->execute([':id' => $surveyId]);
    $srow = $own->fetch();
} catch (Throwable $e) {
    fail('server_error', 'Survey lookup failed: ' . $e->getMessage(), 500);
}
if (!$srow) fail('not_found', 'Survey not found.', 404);
$surveyOwnerId = (int)$srow['owner_id'];
if ($surveyOwnerId !== (int)$user['id']) {
    fail('forbidden', 'You can only build reports on your own surveys.', 403);
}

$title = $titleIn !== ''
    ? $titleIn
    : (((string)$srow['title']) . ' . ' . ucfirst($template) . ' report');

// Snapshot builder is the riskiest step. Surface the actual error to the
// caller instead of letting it 500.
try {
    $snapshot = reports_build_snapshot($surveyId);
} catch (Throwable $e) {
    fail('snapshot_failed', 'Snapshot builder error: ' . $e->getMessage(), 500);
}
$snapJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($snapJson === false) {
    fail('snapshot_failed', 'Snapshot encode error: ' . json_last_error_msg(), 500);
}

// INSERT. If this fails it's almost always either a missing table (Phase 148
// migration not run) or a column / constraint mismatch.
try {
    $stmt = $pdo->prepare(
        'INSERT INTO reports
            (user_id, source_survey_id, title, template, status, snapshot_json, last_generated_at)
         VALUES (:uid, :sid, :title, :tpl, :st, :snap, NOW())'
    );
    $stmt->execute([
        ':uid'   => (int)$user['id'],
        ':sid'   => $surveyId,
        ':title' => $title,
        ':tpl'   => $template,
        ':st'    => 'draft',
        ':snap'  => $snapJson,
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    $msg = $e->getMessage();
    // Best-guess code based on the MySQL error.
    if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Base table') !== false) {
        fail('migration_pending', 'reports table missing. Run db/schema_phase148.sql in phpMyAdmin.', 503);
    }
    fail('insert_failed', 'Insert error: ' . $msg, 500);
}

json_out([
    'ok' => true,
    'report' => [
        'id'                => $id,
        'title'             => $title,
        'template'          => $template,
        'status'            => 'draft',
        'source_survey_id'  => $surveyId,
        'source_title'      => (string)$srow['title'],
        'last_generated_at' => date('Y-m-d H:i:s'),
        'snapshot'          => $snapshot,
    ],
], 201);
