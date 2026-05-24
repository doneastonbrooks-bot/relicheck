<?php
// GET /api/auth/google_config.php
// Returns the public Google client_id so the front-end can render the
// "Sign in with Google" button without hardcoding the ID in HTML.
// Returns enabled=false if Google is not configured.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

$cfg = relicheck_config();
$clientId = (string)($cfg['google_client_id'] ?? '');

json_out([
    'enabled'   => $clientId !== '',
    'client_id' => $clientId,
]);
