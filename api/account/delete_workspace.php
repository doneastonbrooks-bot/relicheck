<?php
// /api/account/delete_workspace.php  -  user-side workspace deletion.
//
// Two-step flow:
//   POST  action=schedule  body { password, confirm_text }
//        Verifies password AND that confirm_text === "DELETE".
//        Sets users.deletion_scheduled_at = NOW() + 30 days (the column
//        already exists from Phase 33). Emails a confirmation via the
//        existing Phase 31 mailer if available; otherwise silent. The
//        admin cron that purges accounts (Phase 33+) will then actually
//        hard-delete the data after the 30-day grace window.
//
//   POST  action=cancel
//        Clears deletion_scheduled_at. Available at any point during the
//        30-day grace window.
//
//   GET
//        Returns current schedule state.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT deletion_scheduled_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    json_out([
        'ok' => true,
        'scheduled_at' => $row && $row['deletion_scheduled_at'] ? (string)$row['deletion_scheduled_at'] : null,
    ]);
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'schedule') {
    $password = (string)($body['password'] ?? '');
    $confirm  = trim((string)($body['confirm_text'] ?? ''));
    if ($password === '') fail('bad_password', 'Confirm your password.', 400);
    if (strtoupper($confirm) !== 'DELETE') {
        fail('bad_confirm', 'Type DELETE in the confirmation box to proceed.', 400);
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        fail('bad_password', 'Password did not match.', 401);
    }
    // Schedule the hard delete 30 days from now (computed in MySQL to avoid
    // PHP / MySQL timezone drift, per the project rule).
    $pdo->prepare(
        'UPDATE users SET deletion_scheduled_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id'
    )->execute([':id' => (int)$user['id']]);

    // Best-effort confirmation email. Uses Phase 31 helper if present; never
    // blocks the response.
    try {
        $mailerHelper = __DIR__ . '/../_mailer.php';
        if (is_file($mailerHelper)) {
            require_once $mailerHelper;
            if (function_exists('send_mail')) {
                $when = (new DateTimeImmutable('+30 days'))->format('F j, Y');
                $body_html = '<p>Hi ' . htmlspecialchars(explode(' ', (string)($user['name'] ?? ''))[0] ?: 'there') . ',</p>'
                    . '<p>Your ReliCheck workspace is now scheduled for deletion on <strong>' . htmlspecialchars($when) . '</strong>.</p>'
                    . '<p>If this was not you, sign in and cancel from Account > Danger zone to keep your data.</p>';
                send_mail([
                    'to'      => (string)$user['email'],
                    'subject' => 'Your ReliCheck workspace is scheduled for deletion',
                    'html'    => $body_html,
                ]);
            }
        }
    } catch (Throwable $_) {}

    $when = $pdo->prepare('SELECT deletion_scheduled_at FROM users WHERE id = :id');
    $when->execute([':id' => (int)$user['id']]);
    json_out([
        'ok' => true,
        'scheduled_at' => (string)$when->fetchColumn(),
        'note' => 'You have 30 days to cancel by signing in and clicking Cancel deletion.',
    ]);
}

if ($action === 'cancel') {
    $pdo->prepare('UPDATE users SET deletion_scheduled_at = NULL WHERE id = :id')
        ->execute([':id' => (int)$user['id']]);
    json_out(['ok' => true]);
}

fail('bad_action', 'Unknown action.');
