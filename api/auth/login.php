<?php
// POST /api/auth/login
// Body: { email, password }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();

$body = read_json_body();
$email    = strtolower(clean_string($body['email'] ?? '', 255));
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if (!valid_email($email) || $password === '') {
    fail('invalid_credentials', 'Email or password is incorrect.', 401);
}

// 10 attempts per email per 15 min, plus a per-IP cap of 30/15 min so a
// single bad actor can't sweep many emails from one address.
check_rate_limit('login:email:' . $email,  10, 900);
check_rate_limit(ip_bucket_key('login'),   30, 900);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, email, name, password_hash FROM users WHERE email = :email');
$stmt->execute([':email' => $email]);
$row = $stmt->fetch();

// Constant-ish failure path to limit timing leaks
if (!$row || !password_verify($password, $row['password_hash'])) {
    fail('invalid_credentials', 'Email or password is incorrect.', 401);
}

// Lock check (Phase 21) and pause check (Phase 24). Both columns are
// optional; if the migration hasn't been run, the try/catch silently
// treats the account as unlocked/active, so sign-in keeps working.
try {
    $lstmt = $pdo->prepare('SELECT locked_at, paused_at FROM users WHERE id = :id');
    $lstmt->execute([':id' => $row['id']]);
    $lr = $lstmt->fetch();
    if ($lr && !empty($lr['locked_at'])) {
        fail('account_locked', 'This account has been locked. Please contact support@relichecksurvey.com.', 423);
    }
    if ($lr && !empty($lr['paused_at'])) {
        fail('account_paused', 'Your account is currently paused. Reach out to support@relichecksurvey.com to reactivate.', 423);
    }
} catch (Throwable $e) {
    // Column missing or query failed; treat as not-locked/not-paused. The
    // lock and pause features are opt-in and should never break sign-in.
}

// Re-hash if PHP's default cost has changed
if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $up = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
    $up->execute([':h' => $newHash, ':id' => $row['id']]);
}

// New-device / new-location detection. Compares the current IP hash to the
// last_login_ip_hash column (added in schema_phase34). If it differs and the
// account has logged in before, fire auth.new_device_or_location. Wrapped in
// try/catch so a missing column or mailer hiccup never blocks the login.
$current_ip_hash = ip_hash();
$is_new_device   = false;
try {
    $devstmt = $pdo->prepare('SELECT last_login_at, last_login_ip_hash FROM users WHERE id = :id');
    $devstmt->execute([':id' => $row['id']]);
    $dev = $devstmt->fetch();
    if ($dev && !empty($dev['last_login_at'])) {
        $prior_ip = (string)($dev['last_login_ip_hash'] ?? '');
        if ($current_ip_hash !== null && $prior_ip !== '' && $prior_ip !== $current_ip_hash) {
            $is_new_device = true;
        }
    }
} catch (Throwable $e) {
    // Column doesn't exist yet; skip the check.
}

// 2FA enforcement (Phase 167). If the user has TOTP enabled, halt here and
// issue a challenge token. The client sends back { challenge_token, code }
// to complete the login. Wrapped in try/catch so a missing column or
// missing challenges table never breaks sign-in.
$needs_2fa = false;
try {
    $two = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = :id LIMIT 1');
    $two->execute([':id' => $row['id']]);
    $tworow = $two->fetch();
    $needs_2fa = $tworow && (int)$tworow['totp_enabled'] === 1;
} catch (Throwable $e) {
    // Column missing pre-Phase 149. Treat as not enabled.
    $needs_2fa = false;
}

if ($needs_2fa) {
    try {
        $token     = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $expires   = date('Y-m-d H:i:s', time() + 300);
        $pdo->prepare(
            'INSERT INTO auth_2fa_challenges (user_id, token_hash, expires_at, ip_hash)
             VALUES (:u, :h, :x, :ip)'
        )->execute([
            ':u'  => (int)$row['id'],
            ':h'  => $tokenHash,
            ':x'  => $expires,
            ':ip' => $current_ip_hash,
        ]);
        json_out([
            'ok'              => false,
            'need_2fa'        => true,
            'challenge_token' => $token,
            'expires_in'      => 300,
        ], 200);
    } catch (Throwable $e) {
        // Table missing means Phase 167 wasn't run. Log and fall through to
        // the legacy single-step login so admins aren't locked out of the
        // server, but record the gap so the operator sees it.
        error_log('[relicheck] 2FA challenge table missing; falling through: ' . $e->getMessage());
    }
}

$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
    ->execute([':id' => $row['id']]);

// Update last_login_ip_hash if the column exists. Ignore failure.
try {
    $pdo->prepare('UPDATE users SET last_login_ip_hash = :ip WHERE id = :id')
        ->execute([':ip' => $current_ip_hash, ':id' => $row['id']]);
} catch (Throwable $e) {}

if ($is_new_device) {
    try {
        if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
            require_once __DIR__ . '/../_email_dispatcher.php';
            relicheck_email_dispatch('auth.new_device_or_location', [
                'user_id'    => (int)$row['id'],
                'account_id' => (int)$row['id'],
                'idempotency_entity_id' => 'newdevice:' . (int)$row['id'] . ':' . substr((string)$current_ip_hash, 0, 8),
                'payload'    => [
                    'first_name'           => trim(explode(' ', (string)$row['name'])[0] ?: 'there'),
                    'email'                => (string)$row['email'],
                    'device'               => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'), 0, 120),
                    'approximate_location' => 'Unknown',
                    'login_at'             => date('Y-m-d H:i:s'),
                ],
            ]);
        }
    } catch (Throwable $e) {
        error_log('[relicheck] auth.new_device_or_location dispatch failed: ' . $e->getMessage());
    }
}

login_user((int)$row['id']);

// Phase 171: signal whether this user has redeemed a closed-beta cohort code,
// so login.html can route them straight to MM Studio rather than the legacy app.
// Wrapped in try/catch because the is_beta_cohort column is optional (older
// installs may not have run schema_phase171.sql yet).
$isBetaCohort = false;
try {
    $bc = $pdo->prepare(
        'SELECT 1
           FROM promo_redemptions pr
           JOIN promo_codes pc ON pc.id = pr.code_id
          WHERE pr.user_id = :u
            AND pc.is_beta_cohort = 1
            AND pc.is_active = 1
          LIMIT 1'
    );
    $bc->execute([':u' => (int)$row['id']]);
    $isBetaCohort = (bool)$bc->fetchColumn();
} catch (Throwable $e) {
    // Column or table missing: treat as non-beta.
    $isBetaCohort = false;
}

json_out([
    'ok'              => true,
    'user'            => ['id' => (int)$row['id'], 'email' => $row['email'], 'name' => $row['name']],
    'is_beta_cohort'  => $isBetaCohort,
]);
