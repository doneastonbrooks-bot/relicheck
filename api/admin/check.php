<?php
// GET /api/admin/check.php
//
// Cheap session+admin check for the admin panel boot guard. Returns the
// admin user's name and email if authorized, or a 401/403 with a clear
// status code so admin.html can bounce the visitor to the right place.
//
// Additive endpoint. Does not modify /api/auth/me.php or any other path.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';

require_method('GET');

$user = current_user();
if (!$user) {
    json_out(['authenticated' => false, 'is_admin' => false, 'reason' => 'not_signed_in'], 401);
}

if (!is_admin_user($user)) {
    json_out([
        'authenticated' => true,
        'is_admin'      => false,
        'reason'        => 'not_admin',
        'user'          => [
            'email' => $user['email'],
        ],
    ], 403);
}

json_out([
    'authenticated' => true,
    'is_admin'      => true,
    'user'          => [
        'id'    => (int)$user['id'],
        'email' => $user['email'],
        'name'  => $user['name'],
    ],
]);
