<?php
// GET /api/google/callback.php?code=...&state=...
// Receives Google's redirect after consent. Exchanges the auth code for tokens,
// stores them in google_oauth_tokens, then redirects the user back into the app
// with a small status query param so the UI can show a toast.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_google.php';

require_method('GET');
$user = require_auth();

start_session_secure();

$cfg = relicheck_config();
$site = rtrim((string)($cfg['site_url'] ?? ''), '/');
$returnUrl = $site . '/app.html';

function bounce_with(string $returnUrl, string $statusKey, string $statusValue): void
{
    $sep = (strpos($returnUrl, '?') === false) ? '?' : '&';
    header('Location: ' . $returnUrl . $sep . 'google=' . urlencode($statusValue), true, 302);
    exit;
}

// Google may report an error (e.g., user denied consent).
if (isset($_GET['error'])) {
    bounce_with($returnUrl, 'google', 'denied');
}

$code  = isset($_GET['code'])  ? (string)$_GET['code']  : '';
$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$expectedState = (string)($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    bounce_with($returnUrl, 'google', 'state_mismatch');
}

try {
    google_exchange_code_and_store((int)$user['id'], $code);
} catch (Throwable $e) {
    bounce_with($returnUrl, 'google', 'error');
}

bounce_with($returnUrl, 'google', 'connected');
