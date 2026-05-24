<?php
// POST /api/google/disconnect.php
// Revokes the stored token at Google and removes the local row.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_google.php';

require_method('POST');
check_origin();
$user = require_auth();

google_disconnect_user((int)$user['id']);

json_out(['ok' => true]);
