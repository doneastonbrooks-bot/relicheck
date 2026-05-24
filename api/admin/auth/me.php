<?php
// GET /api/admin/auth/me.php
//
// Reports the current admin identity. Two possible sources:
//   1. Admin session (Phase 27 staff_users + admin_sessions)
//   2. Allowlist via customer session (the owner backdoor)
//
// Returns 401 with authenticated:false / is_admin:false if neither
// path applies, so the gate splash on /admin.html knows to send the
// visitor to /admin-login.html.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');

$admin = current_admin();
if (!$admin) {
    json_out([
        'authenticated' => false,
        'is_admin'      => false,
        'reason'        => 'not_signed_in',
    ], 401);
}

json_out([
    'authenticated' => true,
    'is_admin'      => true,
    'user'          => [
        'id'    => $admin['id'],
        'email' => $admin['email'],
        'name'  => $admin['name'],
        'role'  => $admin['role'],
    ],
    'source'        => $admin['source'], // 'staff' | 'allowlist'
]);
