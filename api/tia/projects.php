<?php
// /api/tia/projects.php
// GET   list current user's TIA Studio projects
// POST  create a new TIA project { title, notes?, settings? }
//
// Auto-creates the tia_projects table on first request so the studio
// works without a manual migration step (matches the saved_blocks
// approach). The schema is also captured in db/schema_tia.sql for
// canonical reference.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// Auto-create — idempotent. See db/schema_tia.sql.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS tia_projects (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     BIGINT UNSIGNED NOT NULL,
        title       VARCHAR(255) NOT NULL,
        notes       TEXT NULL,
        settings    JSON NOT NULL,
        status      VARCHAR(16) NOT NULL DEFAULT 'active',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tia_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function tia_out(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'title'      => (string)$r['title'],
        'notes'      => (string)($r['notes'] ?? ''),
        'settings'   => json_decode((string)($r['settings'] ?? '{}'), true) ?: [],
        'status'     => (string)$r['status'],
        'created_at' => (string)$r['created_at'],
        'updated_at' => (string)$r['updated_at'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT * FROM tia_projects
          WHERE user_id = :uid AND status <> "archived"
          ORDER BY updated_at DESC LIMIT 200'
    );
    $stmt->execute([':uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out  = [];
    foreach ($rows as $r) $out[] = tia_out($r);
    json_out(['ok' => true, 'projects' => $out]);
}

// POST: create.
check_origin();
$body  = read_json_body();
$title = trim((string)($body['title'] ?? ''));
$notes = (string)($body['notes'] ?? '');
$settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

if ($title === '') fail('bad_input', 'Title is required.');
if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);
if (mb_strlen($notes) > 4000) $notes = mb_substr($notes, 0, 4000);

$ins = $pdo->prepare(
    'INSERT INTO tia_projects (user_id, title, notes, settings)
     VALUES (:uid, :title, :notes, :settings)'
);
$ins->execute([
    ':uid'      => $uid,
    ':title'    => $title,
    ':notes'    => $notes,
    ':settings' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$id = (int)$pdo->lastInsertId();

$g = $pdo->prepare('SELECT * FROM tia_projects WHERE id = :id');
$g->execute([':id' => $id]);
$row = $g->fetch(PDO::FETCH_ASSOC);
json_out(['ok' => true, 'project' => tia_out($row)]);
