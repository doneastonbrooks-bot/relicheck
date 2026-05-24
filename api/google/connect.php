<?php
// GET /api/google/connect.php
// Starts the OAuth flow. Redirects the user's browser to Google's consent page
// with the scopes ReliCheck needs for Sheets export and Drive uploads.
//
// On success Google will redirect back to /api/google/callback.php?code=...
// which exchanges the code for tokens and stores them.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_google.php';

require_method('GET');
$user = require_auth();

$app = google_oauth_app();

// Random state used by the callback to detect CSRF / mismatched flows.
start_session_secure();
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'              => $app['client_id'],
    'redirect_uri'           => $app['redirect_uri'],
    'response_type'          => 'code',
    'scope'                  => implode(' ', GOOGLE_SCOPES_FOR_API),
    'access_type'            => 'offline',          // returns a refresh_token
    'prompt'                 => 'consent',          // forces re-consent so refresh_token is always returned
    'include_granted_scopes' => 'true',
    'state'                  => $state,
    // Hint Google with the user's email so they pick the right account quickly.
    'login_hint'             => $user['email'],
];

$url = GOOGLE_OAUTH_AUTH_URL . '?' . http_build_query($params, '', '&');

header('Location: ' . $url, true, 302);
exit;
