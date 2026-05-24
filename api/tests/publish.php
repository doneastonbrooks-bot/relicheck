<?php
// POST /api/tests/publish.php
// Body: { id: <int>, publish: <bool> }
// Owner-authed. Toggles is_published. Generates a slug on first publish.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
$publish = !empty($body['publish']);
if ($id < 1) fail('bad_input', 'Missing test id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id, slug, is_published FROM tests WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$test = $stmt->fetch();
if (!$test) fail('not_found', 'Test not found.', 404);
if ((int)$test['user_id'] !== (int)$user['id']) fail('forbidden', 'You do not have access to this test.', 403);

$slug = (string)($test['slug'] ?? '');
if ($publish && $slug === '') {
    // Generate an unguessable 24-char slug (case-insensitive alphabetic + digits).
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = '';
        for ($i = 0; $i < 24; $i++) $candidate .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        // Make sure the candidate is not already in use.
        $check = $pdo->prepare('SELECT 1 FROM tests WHERE slug = :slug LIMIT 1');
        $check->execute([':slug' => $candidate]);
        if (!$check->fetch()) { $slug = $candidate; break; }
    }
    if ($slug === '') fail('server_error', 'Could not generate a unique slug. Try again.', 500);
}

$pdo->prepare('UPDATE tests SET slug = :slug, is_published = :pub WHERE id = :id')->execute([
    ':slug' => $slug !== '' ? $slug : null,
    ':pub'  => $publish ? 1 : 0,
    ':id'   => $id,
]);

json_out([
    'ok'           => true,
    'id'           => $id,
    'slug'         => $slug,
    'is_published' => $publish,
]);
