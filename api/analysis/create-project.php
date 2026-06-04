<?php
// POST /api/analysis/create-project.php
// Creates a new analysis project row.
// Body: { title, kind? }
// Returns: { ok, project_id }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$title = trim((string)($body['title'] ?? ''));
if ($title === '') fail('bad_input', 'title is required.');

$kind = trim((string)($body['kind'] ?? 'descriptive'));
if (!in_array($kind, ['descriptive', 'inferential'], true)) $kind = 'descriptive';

// Ensure table exists (idempotent — matches schema used by existing analysis endpoints).
$pdo->exec("CREATE TABLE IF NOT EXISTS analysis_projects (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    kind        VARCHAR(32)     NOT NULL DEFAULT 'descriptive',
    title       VARCHAR(255)    NOT NULL,
    dataset_id  BIGINT UNSIGNED NULL,
    notes       TEXT            NULL,
    status      VARCHAR(16)     NOT NULL DEFAULT 'active',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ap_user (user_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $pdo->prepare(
    'INSERT INTO analysis_projects (user_id, kind, title, notes, status)
     VALUES (:u, :k, :t, NULL, :s)'
);
$stmt->execute([':u' => $uid, ':k' => $kind, ':t' => $title, ':s' => 'active']);
$projectId = (int)$pdo->lastInsertId();

json_out(['ok' => true, 'project_id' => $projectId]);
