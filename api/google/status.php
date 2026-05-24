<?php
// GET /api/google/status.php
// Returns whether the current user has a stored Google OAuth connection,
// and which account they connected. Used by the UI to show "Connect Google"
// vs. "Connected as alice@example.com".

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_google.php';

require_method('GET');
$user = require_auth();

$cfg = relicheck_config();
$serverEnabled = ((string)($cfg['google_client_id'] ?? '') !== '')
              && ((string)($cfg['google_client_secret'] ?? '') !== '');

if (!$serverEnabled) {
    json_out(['enabled' => false, 'connected' => false]);
}

$row = google_load_tokens_for_user((int)$user['id']);
if (!$row) {
    json_out(['enabled' => true, 'connected' => false]);
}

json_out([
    'enabled'      => true,
    'connected'    => true,
    'google_email' => $row['google_email'],
    'connected_at' => $row['connected_at'],
    'scopes'       => $row['scopes'],
]);
