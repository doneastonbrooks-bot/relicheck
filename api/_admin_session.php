<?php
// Admin-side session management. Independent from the customer-facing
// PHP $_SESSION (which uses a different cookie name and lifecycle).
//
// Cookie name: relicheck_admin
// Storage:     admin_sessions table (Phase 27)
//
// Sessions are validated on every request: the cookie carries a random
// 64-hex token, the server looks it up in admin_sessions, and the
// staff_users row is loaded if the token is valid AND the staff status
// is 'active' AND the expires_at is in the future.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

const ADMIN_COOKIE_NAME = 'relicheck_admin';
const ADMIN_SESSION_DAYS = 14;

function admin_cookie_params(): array
{
    $cfg = relicheck_config();
    return [
        'lifetime' => ADMIN_SESSION_DAYS * 86400,
        'path'     => '/',
        'secure'   => (bool)($cfg['session_secure']   ?? true),
        'httponly' => true,
        'samesite' => (string)($cfg['session_samesite'] ?? 'Lax'),
    ];
}

function admin_login_session(int $staffId, string $status = 'active', ?string $pendingSecret = null): string
{
    $token   = bin2hex(random_bytes(32));

    // Compute expires_at using MySQL's clock so it stays consistent with
    // NOW() comparisons in pending_admin_session() / current_admin_staff().
    // Mixing PHP's clock (often a different timezone on shared hosts like
    // IONOS) and MySQL's clock leads to "expired before created" rows.
    //
    // Pending sessions get a 60-minute window - enough time to deal with
    // authenticator-app setup, password manager friction, scanning issues,
    // etc. Active sessions get the full 14-day life.
    $minutes = ($status === 'active') ? (ADMIN_SESSION_DAYS * 24 * 60) : 60;

    // Insert with status if the column exists; fall back to the original
    // schema if Phase 28 hasn't run yet.
    try {
        db()->prepare(
            'INSERT INTO admin_sessions (token, staff_id, expires_at, ip, user_agent, status, pending_secret)
             VALUES (:t, :s, DATE_ADD(NOW(), INTERVAL ' . (int)$minutes . ' MINUTE), :ip, :ua, :st, :ps)'
        )->execute([
            ':t'  => $token,
            ':s'  => $staffId,
            ':ip' => substr((string)($_SERVER['REMOTE_ADDR']     ?? ''), 0, 64),
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':st' => $status,
            ':ps' => $pendingSecret,
        ]);
    } catch (Throwable $e) {
        // Pre-Phase-28 schema. Status/pending_secret columns missing.
        // Force status='active' so we don't end up with a stuck pending row.
        db()->prepare(
            'INSERT INTO admin_sessions (token, staff_id, expires_at, ip, user_agent)
             VALUES (:t, :s, DATE_ADD(NOW(), INTERVAL ' . (int)$minutes . ' MINUTE), :ip, :ua)'
        )->execute([
            ':t'  => $token,
            ':s'  => $staffId,
            ':ip' => substr((string)($_SERVER['REMOTE_ADDR']     ?? ''), 0, 64),
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    $params = admin_cookie_params();
    setcookie(ADMIN_COOKIE_NAME, $token, [
        'expires'  => time() + $params['lifetime'],
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);

    return $token;
}

function admin_logout_session(): void
{
    $token = (string)($_COOKIE[ADMIN_COOKIE_NAME] ?? '');
    if ($token !== '') {
        try {
            db()->prepare('DELETE FROM admin_sessions WHERE token = :t')
                ->execute([':t' => $token]);
        } catch (Throwable $e) { /* best-effort */ }
    }
    $params = admin_cookie_params();
    setcookie(ADMIN_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
}

/**
 * Returns the staff_users row for the current admin session, or null.
 * Joins on staff_users.status='active' so a suspended/removed account
 * cannot keep using a still-valid token.
 */
function current_admin_staff(): ?array
{
    $token = (string)($_COOKIE[ADMIN_COOKIE_NAME] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    try {
        // Try the Phase-28 schema (admin_sessions.status). If status is
        // missing (pre-migration), fall back to the unconditioned query.
        $stmt = db()->prepare(
            "SELECT s.id, s.email, s.name, s.role, s.status, sess.expires_at
               FROM admin_sessions sess
               JOIN staff_users s ON s.id = sess.staff_id
              WHERE sess.token = :t
                AND sess.expires_at > NOW()
                AND s.status = 'active'
                AND (sess.status = 'active' OR sess.status IS NULL)
              LIMIT 1"
        );
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if (!$row) {
            // Try the pre-Phase-28 query in case the sess.status column does not exist.
            $stmt2 = db()->prepare(
                "SELECT s.id, s.email, s.name, s.role, s.status, sess.expires_at
                   FROM admin_sessions sess
                   JOIN staff_users s ON s.id = sess.staff_id
                  WHERE sess.token = :t
                    AND sess.expires_at > NOW()
                    AND s.status = 'active'
                  LIMIT 1"
            );
            $stmt2->execute([':t' => $token]);
            $row = $stmt2->fetch();
            if (!$row) return null;
        }

        db()->prepare('UPDATE admin_sessions SET last_seen_at = NOW() WHERE token = :t')
            ->execute([':t' => $token]);

        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Returns the pending admin_sessions row for the current cookie, or null.
 * Used by 2FA verify/setup endpoints - they need to know who's trying to
 * complete authentication without granting any admin access yet.
 */
function pending_admin_session(): ?array
{
    $token = (string)($_COOKIE[ADMIN_COOKIE_NAME] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    try {
        $stmt = db()->prepare(
            "SELECT sess.token, sess.staff_id, sess.status, sess.pending_secret, sess.expires_at,
                    s.email, s.name, s.role, s.totp_secret
               FROM admin_sessions sess
               JOIN staff_users s ON s.id = sess.staff_id
              WHERE sess.token = :t
                AND sess.expires_at > NOW()
                AND sess.status IN ('pending_2fa', 'pending_setup')
              LIMIT 1"
        );
        $stmt->execute([':t' => $token]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Promote a pending session to active. Called after successful 2FA verify.
 */
function activate_admin_session(string $token): void
{
    // Use MySQL's clock for the new expiry to keep timezone consistent
    // with NOW() comparisons elsewhere.
    $minutes = ADMIN_SESSION_DAYS * 24 * 60;
    db()->prepare(
        "UPDATE admin_sessions
            SET status='active',
                pending_secret=NULL,
                expires_at=DATE_ADD(NOW(), INTERVAL " . (int)$minutes . " MINUTE)
          WHERE token=:t"
    )->execute([':t' => $token]);
}
