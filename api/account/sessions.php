<?php
// /api/account/sessions.php  -  list and revoke active user sessions.
//
// Note: this endpoint READS from user_sessions but does NOT itself write
// rows. New session rows are inserted at sign-in time (api/auth/login.php
// touchpoint, to be added in the follow-up that wires session tracking
// into _session.php). For now the list will be empty until the sign-in
// path is patched; revoke still works for any rows that do exist.
//
// GET                  -> list of active sessions for the current user
// POST  action=revoke  -> body { id }. Sets revoked_at on one row.
// POST  action=revoke-all-others -> revoke every session except the
//                         current one (best-effort detection by matching
//                         the current PHP session_id() hash).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function _current_session_hash(): string
{
    $sid = session_id();
    return $sid !== '' ? hash('sha256', $sid) : '';
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, session_token, ip_hash, user_agent, created_at, last_seen_at
               FROM user_sessions
              WHERE user_id = :u AND revoked_at IS NULL
              ORDER BY last_seen_at DESC'
        );
        $stmt->execute([':u' => (int)$user['id']]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        fail('migration_pending', 'Phase 149 migration has not been applied yet.', 503);
    }
    $curHash = _current_session_hash();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'is_current'   => $curHash !== '' && hash_equals($curHash, (string)$r['session_token']),
            'created_at'   => (string)$r['created_at'],
            'last_seen_at' => (string)$r['last_seen_at'],
            'user_agent'   => (string)($r['user_agent'] ?? ''),
        ];
    }
    json_out(['ok' => true, 'sessions' => $out]);
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'revoke') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'Missing id.', 400);
    try {
        $upd = $pdo->prepare(
            'UPDATE user_sessions
                SET revoked_at = NOW()
              WHERE id = :id AND user_id = :u AND revoked_at IS NULL'
        );
        $upd->execute([':id' => $id, ':u' => (int)$user['id']]);
    } catch (Throwable $e) {
        fail('migration_pending', 'Phase 149 migration has not been applied yet.', 503);
    }
    if ($upd->rowCount() === 0) fail('not_found', 'No active session with that id.', 404);
    json_out(['ok' => true]);
}

if ($action === 'revoke-all-others') {
    $curHash = _current_session_hash();
    try {
        $sql = 'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :u AND revoked_at IS NULL';
        $params = [':u' => (int)$user['id']];
        if ($curHash !== '') {
            $sql .= ' AND session_token <> :tok';
            $params[':tok'] = $curHash;
        }
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    } catch (Throwable $e) {
        fail('migration_pending', 'Phase 149 migration has not been applied yet.', 503);
    }
    json_out(['ok' => true, 'revoked' => $upd->rowCount()]);
}

fail('bad_action', 'Unknown action.');
