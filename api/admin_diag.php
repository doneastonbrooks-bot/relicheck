<?php
// Temporary diagnostic for the admin gate (promotional codes admin panel).
// Visit this at /api/admin_diag.php while signed in. It reports:
//   1. Whether _config.php is being read at all and what mtime PHP sees.
//   2. The admin_emails list as PHP currently sees it.
//   3. The session uid and the email on that user record.
//   4. Whether is_admin_user() returns true (apples-to-apples with require_admin()).
// Delete this file after the issue is resolved.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = [];

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/_admin.php';

// 1. Config file presence and freshness.
$cfgPath = __DIR__ . '/_config.php';
$out['config_file_exists'] = is_file($cfgPath);
$out['config_file_mtime']  = $out['config_file_exists'] ? date('c', filemtime($cfgPath)) : null;
$out['config_file_size']   = $out['config_file_exists'] ? filesize($cfgPath) : null;

// 2. admin_emails as actually loaded.
$cfg = relicheck_config();
$adminList = $cfg['admin_emails'] ?? null;
$out['admin_emails_present_in_config'] = array_key_exists('admin_emails', $cfg);
$out['admin_emails_is_array']          = is_array($adminList);
$out['admin_emails_count']             = is_array($adminList) ? count($adminList) : null;
$out['admin_emails_lowercased']        = is_array($adminList)
    ? array_map(static fn($e) => strtolower((string)$e), $adminList)
    : null;

// 3. Session / user record.
$uid = current_user_id();
$out['session_uid'] = $uid;
if ($uid !== null) {
    $u = current_user();
    $out['user_record_email']            = $u['email'] ?? null;
    $out['user_record_email_lowercased'] = strtolower((string)($u['email'] ?? ''));
    // 4. Apples-to-apples admin check.
    $out['is_admin_user_result'] = $u ? is_admin_user($u) : false;
    // Show the exact comparison so a typo or stray whitespace is obvious.
    if ($u && is_array($adminList)) {
        $userEmail = strtolower((string)($u['email'] ?? ''));
        $matches = [];
        foreach ($adminList as $a) {
            $matches[] = [
                'config_value'        => (string)$a,
                'config_lowercased'   => strtolower((string)$a),
                'equals_user_email'   => strtolower((string)$a) === $userEmail,
                'has_leading_space'   => $a !== ltrim((string)$a),
                'has_trailing_space'  => $a !== rtrim((string)$a),
            ];
        }
        $out['email_comparison'] = $matches;
    }
} else {
    $out['note'] = 'Not signed in. Open /app.html, sign in, then visit this URL again.';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
