<?php
// POST /api/auth/logout

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();

logout_user();

json_out(['ok' => true]);
