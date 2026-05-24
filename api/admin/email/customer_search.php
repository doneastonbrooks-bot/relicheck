<?php
// GET /api/admin/email/customer_search.php?q=<query>&limit=20
//
// Lookup-as-you-type for the Compose tab's single-recipient picker.
// Searches users.email and users.name, returns id / email / name only.
// Admin-gated. Refuses queries shorter than 2 characters to avoid full
// table scans on accidental keystrokes.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    json_out(['ok' => true, 'rows' => [], 'note' => 'Type at least 2 characters.']);
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

$sql = "SELECT id, email, name, last_login_at, locked_at
        FROM users
        WHERE (email LIKE :like OR name LIKE :like)
        ORDER BY (email = :exact) DESC, id DESC
        LIMIT $limit";

$st = db()->prepare($sql);
$st->execute([':like' => $like, ':exact' => $q]);

$rows = [];
foreach ($st->fetchAll() as $r) {
    $rows[] = [
        'id'             => (int)$r['id'],
        'email'          => (string)$r['email'],
        'name'           => (string)$r['name'],
        'last_login_at'  => $r['last_login_at'],
        'locked'         => !empty($r['locked_at']),
    ];
}

json_out(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
