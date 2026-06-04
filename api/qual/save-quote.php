<?php
// POST /api/qual/save-quote.php
// Pin or unpin a segment as an exemplar quote for a theme.
// Body: { project_id, theme_id, segment_id, action: 'pin'|'unpin', note? }
// Returns: { ok }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$themeId   = (int)($body['theme_id']   ?? 0);
$segmentId = (int)($body['segment_id'] ?? 0);
$action    = (string)($body['action']  ?? 'pin');
if ($projectId <= 0 || $themeId <= 0 || $segmentId <= 0) {
    fail('bad_input', 'project_id, theme_id, and segment_id required.');
}
qual_require_project($pdo, $uid, $projectId);

// Ensure table exists (may be missing on long-running PHP-FPM workers from before V3)
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS qual_theme_quotes (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id  BIGINT UNSIGNED NOT NULL,
            theme_id    BIGINT UNSIGNED NOT NULL,
            segment_id  BIGINT UNSIGNED NOT NULL,
            note        TEXT NULL,
            added_by    BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_qtq_theme_seg (theme_id, segment_id),
            KEY idx_qtq_proj  (project_id),
            KEY idx_qtq_theme (theme_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) { /* already exists or creation failed */ }

// Verify theme and segment belong to project
$t = $pdo->prepare('SELECT id, name FROM qual_themes   WHERE id=:id AND project_id=:p LIMIT 1');
$t->execute([':id' => $themeId, ':p' => $projectId]);
$theme = $t->fetch(PDO::FETCH_ASSOC);
if (!$theme) fail('not_found', 'Theme not found.', 404);

$s = $pdo->prepare('SELECT id FROM qual_segments WHERE id=:id AND project_id=:p LIMIT 1');
$s->execute([':id' => $segmentId, ':p' => $projectId]);
if (!$s->fetch()) fail('not_found', 'Segment not found.', 404);

$note = trim((string)($body['note'] ?? '')) ?: null;

if ($action === 'unpin') {
    $pdo->prepare(
        'DELETE FROM qual_theme_quotes WHERE theme_id=:t AND segment_id=:s AND project_id=:p'
    )->execute([':t' => $themeId, ':s' => $segmentId, ':p' => $projectId]);
    qual_audit($pdo, $projectId, $uid, 'quote_unpinned', 'theme', $themeId, (string)$theme['name']);
} else {
    $pdo->prepare(
        'INSERT INTO qual_theme_quotes (project_id, theme_id, segment_id, note, added_by)
         VALUES (:p, :t, :s, :n, :u)
         ON DUPLICATE KEY UPDATE note = VALUES(note)'
    )->execute([':p' => $projectId, ':t' => $themeId, ':s' => $segmentId, ':n' => $note, ':u' => $uid]);
    qual_audit($pdo, $projectId, $uid, 'quote_pinned', 'theme', $themeId, (string)$theme['name']);
}

json_out(['ok' => true]);
