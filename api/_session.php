<?php
// Session helpers - wraps PHP's built-in $_SESSION with secure cookie defaults.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function start_session_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $cfg = relicheck_config();
    $lifetime = (int)($cfg['session_lifetime_days'] ?? 30) * 86400;
    session_name((string)($cfg['session_name'] ?? 'relicheck_sid'));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => (bool)($cfg['session_secure'] ?? true),
        'httponly' => true,
        'samesite' => (string)($cfg['session_samesite'] ?? 'Lax'),
    ]);
    // Tighten ini settings before starting the session
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function login_user(int $userId): void
{
    start_session_secure();
    // Regenerate to prevent session fixation
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    $_SESSION['login_at'] = time();
}

function logout_user(): void
{
    start_session_secure();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }
    session_destroy();
}

function current_user_id(): ?int
{
    start_session_secure();
    $uid = $_SESSION['uid'] ?? null;
    return is_int($uid) ? $uid : null;
}

function current_user(): ?array
{
    $uid = current_user_id();
    if ($uid === null) return null;
    $stmt = db()->prepare('SELECT id, email, name, created_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $uid]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function require_auth(): array
{
    $u = current_user();
    if (!$u) {
        require_once __DIR__ . '/_helpers.php';
        fail('not_authenticated', 'Sign in to continue.', 401);
    }
    return $u;
}
