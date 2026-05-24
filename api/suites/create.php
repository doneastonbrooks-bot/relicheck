<?php
// POST /api/suites/create.php
// Body: { name, description?, color?, icon? }
// Creates a custom (non-system) suite owned by the caller.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('POST');
check_origin();
$user = require_auth();
$userId = (int)$user['id'];

$body = read_json_body();
$name = trim((string)($body['name'] ?? ''));
$desc = trim((string)($body['description'] ?? ''));
$color = trim((string)($body['color'] ?? '#1F3A8A'));
$icon  = trim((string)($body['icon']  ?? 'box'));

if ($name === '') fail('bad_input', 'Suite name is required.', 400);
if (mb_strlen($name) > 160) $name = mb_substr($name, 0, 160);
if (mb_strlen($desc) > 500) $desc = mb_substr($desc, 0, 500);
if (mb_strlen($color) > 20)  $color = mb_substr($color, 0, 20);
if (mb_strlen($icon)  > 20)  $icon  = mb_substr($icon, 0, 20);

// Slugify name into a custom suite_key. Ensure uniqueness against this user
// by appending a numeric suffix when needed.
$slug = strtolower($name);
$slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
$slug = trim($slug, '_');
if ($slug === '') $slug = 'custom';
$slug = 'c_' . mb_substr($slug, 0, 32);

$pdo = db();
$candidate = $slug;
$n = 0;
while (true) {
    $check = $pdo->prepare('SELECT id FROM suites WHERE user_id = :uid AND suite_key = :k LIMIT 1');
    $check->execute([':uid' => $userId, ':k' => $candidate]);
    if (!$check->fetch()) break;
    $n++;
    if ($n > 50) fail('conflict', 'Could not generate a unique suite key.', 500);
    $candidate = $slug . '_' . $n;
}

$ins = $pdo->prepare(
    'INSERT INTO suites (user_id, suite_key, name, description, color, icon, is_system, display_order)
     VALUES (:uid, :k, :nm, :dsc, :col, :ic, 0, 1000)'
);
$ins->execute([
    ':uid' => $userId,
    ':k'   => $candidate,
    ':nm'  => $name,
    ':dsc' => $desc !== '' ? $desc : null,
    ':col' => $color !== '' ? $color : '#1F3A8A',
    ':ic'  => $icon  !== '' ? $icon  : 'box',
]);

json_out(['ok' => true, 'suite_id' => (int)$pdo->lastInsertId(), 'suite_key' => $candidate]);
