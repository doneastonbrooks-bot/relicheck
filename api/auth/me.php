<?php
// GET /api/auth/me - returns the current signed-in user, or 401.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');

$user = current_user();
if (!$user) {
    json_out(['authenticated' => false], 200);
}

// Pull the user's verified custom domain (if any) so the frontend can
// generate share URLs from the customer's vanity hostname instead of the
// platform's. Only surfaces a domain whose status is 'verified'.
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT custom_domain, custom_domain_status FROM users WHERE id = :id'
);
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();
$verifiedDomain = ($row && $row['custom_domain_status'] === 'verified')
    ? $row['custom_domain']
    : null;

json_out([
    'authenticated' => true,
    'user' => [
        'id'                   => (int)$user['id'],
        'email'                => $user['email'],
        'name'                 => $user['name'],
        'custom_domain'        => $verifiedDomain,
        'custom_domain_status' => $row['custom_domain_status'] ?? 'disabled',
    ],
]);
