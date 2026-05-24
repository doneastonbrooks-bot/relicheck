<?php
// POST /api/admin/auth/logout.php
// Clears the admin session cookie and removes the row from admin_sessions.
// Does NOT touch the customer session - signing out of admin leaves the
// user's customer session (if any) intact.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_admin_session.php';

require_method('POST');
check_origin();

admin_logout_session();

json_out(['ok' => true, 'message' => 'Signed out of admin.']);
