<?php
// GET  /api/account/profile.php   - returns the current user's profile
// PATCH /api/account/profile.php  - updates name and/or email
// Body for PATCH: { name?, email? }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET', 'PATCH', 'POST'); // POST allowed for HTML form fallbacks
$user = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$pdo = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, email, name, created_at, last_login_at
           FROM users WHERE id = :id'
    );
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();
    json_out([
        'user' => [
            'id'            => (int)$row['id'],
            'email'         => $row['email'],
            'name'          => $row['name'],
            'created_at'    => $row['created_at'],
            'last_login_at' => $row['last_login_at'],
        ],
    ]);
}

check_origin();
$body = read_json_body();

$updates = [];
$params = [':id' => $user['id']];

if (array_key_exists('name', $body)) {
    $name = clean_string($body['name'], 120);
    if ($name === '') fail('bad_name', 'Please enter your name.');
    $updates[] = 'name = :name';
    $params[':name'] = $name;
}
if (array_key_exists('email', $body)) {
    $email = strtolower(clean_string($body['email'], 255));
    if (!valid_email($email)) fail('bad_email', 'Please enter a valid email address.');

    // Check for collision with another account
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $check->execute([':email' => $email, ':id' => $user['id']]);
    if ($check->fetch()) fail('email_taken', 'That email is already in use.', 409);

    $updates[] = 'email = :email';
    $params[':email'] = $email;
}

if (!$updates) fail('nothing_to_update', 'Send a name or email to update.');

$pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id')->execute($params);

$stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();

json_out([
    'ok'   => true,
    'user' => [
        'id'    => (int)$row['id'],
        'email' => $row['email'],
        'name'  => $row['name'],
    ],
]);
