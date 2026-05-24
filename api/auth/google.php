<?php
// POST /api/auth/google.php
// Body: { credential }   <- the JWT credential returned by Google Identity Services
//
// Verifies the credential against Google's tokeninfo endpoint, then either
//   (a) signs in an existing linked Google identity,
//   (b) attaches Google to an existing same-email account, or
//   (c) creates a brand-new account with no password.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();

// Google sign-ins are usually a single click, but cap at 30/IP/15 min.
check_rate_limit(ip_bucket_key('google'), 30, 900);

$cfg = relicheck_config();
$clientId = (string)($cfg['google_client_id'] ?? '');
if ($clientId === '') {
    fail('google_disabled', 'Google Sign-In is not configured on this server.', 503);
}

$body = read_json_body();
$credential = is_string($body['credential'] ?? null) ? trim($body['credential']) : '';
if ($credential === '' || substr_count($credential, '.') !== 2) {
    fail('bad_credential', 'Missing or malformed Google credential.', 400);
}

// Verify with Google's tokeninfo endpoint. This validates the signature and
// expiry without requiring a JWT library on our end.
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
$ctx = stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true],
    'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
]);
$raw = @file_get_contents($verifyUrl, false, $ctx);
if ($raw === false) fail('verify_failed', 'Could not reach Google to verify the credential.', 502);

$claims = json_decode($raw, true);
if (!is_array($claims)) fail('verify_failed', 'Unexpected response from Google.', 502);
if (isset($claims['error_description']) || isset($claims['error'])) {
    fail('bad_credential', 'Google rejected the credential: ' . (string)($claims['error_description'] ?? $claims['error']), 401);
}

// Audience must match our Client ID.
$aud = (string)($claims['aud'] ?? '');
if ($aud === '' || !hash_equals($clientId, $aud)) {
    fail('bad_audience', 'This credential was not issued for this site.', 401);
}

// Issuer check.
$iss = (string)($claims['iss'] ?? '');
if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
    fail('bad_issuer', 'Unexpected token issuer.', 401);
}

// Expiry check (defense in depth; tokeninfo already enforces this).
$exp = (int)($claims['exp'] ?? 0);
if ($exp < time()) {
    fail('expired_credential', 'Sign-in attempt expired. Please try again.', 401);
}

$sub           = (string)($claims['sub'] ?? '');
$email         = strtolower((string)($claims['email'] ?? ''));
$emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
$name          = (string)($claims['name'] ?? ($claims['given_name'] ?? ''));
if ($sub === '' || !valid_email($email)) {
    fail('bad_credential', 'Google did not return a usable identity.', 401);
}
if (!$emailVerified) {
    fail('unverified_email', 'Your Google email is not verified. Please verify it with Google first.', 403);
}

$pdo = db();
$pdo->beginTransaction();
try {
    // 1. Existing linked Google identity?
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.name
           FROM oauth_identities oi
           JOIN users u ON u.id = oi.user_id
          WHERE oi.provider = \'google\' AND oi.sub = :sub LIMIT 1'
    );
    $stmt->execute([':sub' => $sub]);
    $linked = $stmt->fetch();

    if ($linked) {
        $userId = (int)$linked['id'];
        $userOut = ['id' => $userId, 'email' => $linked['email'], 'name' => $linked['name']];
        $pdo->prepare('UPDATE oauth_identities SET last_used_at = NOW() WHERE provider = \'google\' AND sub = :sub')
            ->execute([':sub' => $sub]);
    } else {
        // 2. Existing user with the same email? Link Google to that account.
        $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Same-email auto-link is a known takeover vector: anyone who
            // controls a Google account that matches a victim's ReliCheck
            // email could otherwise walk in via "Sign in with Google."
            // Allow auto-link only when the existing user is already a
            // Google-created or Google-linked account. For password-based
            // accounts, require the user to sign in with password first
            // and link Google from Account settings.
            $idStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM oauth_identities WHERE user_id = :u AND provider = \'google\'');
            $idStmt->execute([':u' => (int)$existing['id']]);
            $hasGoogle = (int)($idStmt->fetch()['c'] ?? 0) > 0;
            if (!$hasGoogle) {
                $pdo->rollBack();
                fail('email_conflict',
                     'An account with this email already exists. Sign in with your password first, then link Google from Account settings.',
                     409);
            }
            $userId = (int)$existing['id'];
            $userOut = ['id' => $userId, 'email' => $existing['email'], 'name' => $existing['name']];
        } else {
            // 3. Brand new user. Create one with a random unguessable password
            //    (the user never types a password; password reset still works
            //    if they want to add a password later).
            $randomPass = bin2hex(random_bytes(24));
            $hash = password_hash($randomPass, PASSWORD_DEFAULT);
            $finalName = clean_string($name !== '' ? $name : explode('@', $email)[0], 120);
            if ($finalName === '') $finalName = 'New user';
            $pdo->prepare('INSERT INTO users (email, password_hash, name) VALUES (:e, :h, :n)')
                ->execute([':e' => $email, ':h' => $hash, ':n' => $finalName]);
            $userId = (int)$pdo->lastInsertId();
            $userOut = ['id' => $userId, 'email' => $email, 'name' => $finalName];
        }

        // Link Google to this user.
        $pdo->prepare(
            'INSERT INTO oauth_identities (provider, sub, user_id, email, last_used_at)
             VALUES (\'google\', :sub, :uid, :email, NOW())'
        )->execute([':sub' => $sub, ':uid' => $userId, ':email' => $email]);
    }

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $userId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

login_user($userId);

// Phase 171: same beta-cohort signal as login.php so Google sign-in can
// route the user to MM Studio when they have redeemed a closed-beta code.
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
    $bc->execute([':u' => (int)$userId]);
    $isBetaCohort = (bool)$bc->fetchColumn();
} catch (Throwable $e) {
    $isBetaCohort = false;
}

json_out(['ok' => true, 'user' => $userOut, 'is_beta_cohort' => $isBetaCohort]);
