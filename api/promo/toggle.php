<?php
// POST /api/promo/toggle.php   (admin only)
// Body: { "id": <int>, "is_active": <bool> }
// Disables or re-enables a code without deleting it.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';

require_method('POST');
check_origin();
require_admin();

$body  = read_json_body();
$id    = (int)($body['id'] ?? 0);
$state = !empty($body['is_active']) ? 1 : 0;
if ($id <= 0) fail('bad_id', 'Missing or invalid id.');

$pdo = db();
$stmt = $pdo->prepare('UPDATE promo_codes SET is_active = :s WHERE id = :id');
$stmt->execute([':s' => $state, ':id' => $id]);

json_out(['ok' => true, 'id' => $id, 'is_active' => (bool)$state]);
