<?php
// GET  /api/account/prefs.php             -> returns { prefs: {...} }
// POST /api/account/prefs.php { patch:{} } -> shallow-merges patch into the
//                                            user's prefs JSON, returns the
//                                            new merged blob.
//
// Pre-migration safe: if users.prefs is missing, GET returns {} and POST
// reports migration_pending instead of crashing.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('SELECT prefs FROM users WHERE id = :id');
        $stmt->execute([':id' => (int)$user['id']]);
        $row = $stmt->fetch();
        $prefs = ($row && $row['prefs']) ? (json_decode((string)$row['prefs'], true) ?: []) : [];
        json_out(['prefs' => $prefs]);
    } catch (Throwable $e) {
        json_out(['prefs' => [], 'note' => 'prefs_unavailable']);
    }
}

// POST = merge.
check_origin();
$body = read_json_body();
$patch = is_array($body['patch'] ?? null) ? $body['patch'] : [];
if (!$patch) {
    fail('bad_patch', 'patch must be a non-empty object.', 400);
}

try {
    $stmt = $pdo->prepare('SELECT prefs FROM users WHERE id = :id');
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    $cur = ($row && $row['prefs']) ? (json_decode((string)$row['prefs'], true) ?: []) : [];

    // Shallow merge: top-level keys in $patch overwrite top-level keys in $cur.
    $merged = array_merge($cur, $patch);

    $upd = $pdo->prepare('UPDATE users SET prefs = :p WHERE id = :id');
    $upd->execute([
        ':p'  => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => (int)$user['id'],
    ]);

    json_out(['prefs' => $merged]);
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 37b migration has not been applied yet.', 503);
}
