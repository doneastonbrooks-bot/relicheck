<?php
// POST /api/account/change_password.php
// Body: { current_password, new_password }
// Verifies current password, then sets the new one.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 5 password-change attempts per user per 15 min.
check_rate_limit('changepw:user:' . (int)$user['id'], 5, 900);

$body    = read_json_body();
$current = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';
$new     = is_string($body['new_password']     ?? null) ? $body['new_password']     : '';

if ($current === '') fail('missing_current', 'Please enter your current password.');
if (!valid_password($new)) fail('bad_password', 'New password must be at least 8 characters and include a number.');
if ($new === $current) fail('same_password', 'Pick a new password different from the current one.');

$pdo = db();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($current, (string)$row['password_hash'])) {
    fail('wrong_password', 'Your current password is incorrect.', 401);
}

$hash = password_hash($new, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
    ->execute([':h' => $hash, ':id' => $user['id']]);

// Confirm-the-change notification. Wrapped so a mailer hiccup never blocks
// the password change itself.
try {
    if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
        require_once __DIR__ . '/../_email_dispatcher.php';
        $now = date('Y-m-d H:i:s');
        relicheck_email_dispatch('password.changed', [
            'user_id'    => (int)$user['id'],
            'account_id' => (int)$user['id'],
            'idempotency_entity_id' => 'pwchange:' . (int)$user['id'] . ':' . $now,
            'payload'    => [
                'first_name' => trim(explode(' ', (string)($user['name'] ?? ''))[0] ?: 'there'),
                'email'      => (string)($user['email'] ?? ''),
                'changed_at' => $now,
                'ip_address' => substr((string)(ip_hash() ?? 'unknown'), 0, 16),
            ],
        ]);
    }
} catch (Throwable $e) {
    error_log('[relicheck] password.changed dispatch failed: ' . $e->getMessage());
}

json_out(['ok' => true]);
