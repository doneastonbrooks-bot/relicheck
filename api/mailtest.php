<?php
// Temporary diagnostic that captures EVERY error path, including PHP fatals.
// Delete this file after debugging.

declare(strict_types=1);

// Force errors into the response so we can see them.
ini_set('display_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$result = [];

// Catch any fatal that happens before normal flow finishes.
register_shutdown_function(function () use (&$result) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo json_encode([
            'result'  => 'fatal',
            'message' => $err['message'],
            'file'    => basename($err['file'] ?? ''),
            'line'    => $err['line'] ?? 0,
            'partial' => $result,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
});

try {
    $result['step'] = 'load_helpers';
    require_once __DIR__ . '/_helpers.php';
    require_once __DIR__ . '/_session.php';
    require_once __DIR__ . '/_mailer.php';

    $result['step'] = 'check_method';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        echo json_encode(['result' => 'error', 'message' => 'Open this URL in a browser GET only.']);
        exit;
    }

    $result['step'] = 'check_auth';
    $user = current_user();
    if (!$user) {
        echo json_encode([
            'result'  => 'not_authenticated',
            'message' => 'Sign into app.html in the same browser, then reload this URL.',
        ]);
        exit;
    }
    $result['signed_in_as'] = $user['email'];

    $result['step'] = 'read_config';
    $cfg = relicheck_config();
    $result['site_url']       = $cfg['site_url']       ?? '(missing)';
    $result['smtp_host']      = $cfg['smtp_host']      ?? '(missing)';
    $result['smtp_port']      = $cfg['smtp_port']      ?? '(missing)';
    $result['smtp_user']      = $cfg['smtp_user']      ?? '(missing)';
    $result['smtp_pass_set']  = !empty($cfg['smtp_pass']);
    $result['mail_from']      = $cfg['mail_from']      ?? '(missing)';
    $result['mail_from_name'] = $cfg['mail_from_name'] ?? '(missing)';
    $result['recipient']      = $user['email'];

    if (!$result['smtp_pass_set'] || $result['smtp_host'] === '(missing)' || $result['smtp_user'] === '(missing)') {
        $result['result'] = 'config_incomplete';
        $result['message'] = 'Add smtp_host, smtp_user, smtp_pass to _config.php and re-upload.';
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result['step'] = 'send_mail';
    send_mail(
        $user['email'],
        'ReliCheck SMTP test',
        "This is a test from /api/mailtest.php.\nIf you see this, password resets will work too.",
        '<p>This is a test from <code>/api/mailtest.php</code>.</p><p>If you see this, password resets will work too.</p>'
    );

    $result['result'] = 'sent';
    $result['note']   = 'Check the inbox (and spam) of ' . $user['email'] . '.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $result['result']  = 'error';
    $result['message'] = $e->getMessage();
    $result['file']    = basename($e->getFile());
    $result['line']    = $e->getLine();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
