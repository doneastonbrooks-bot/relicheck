<?php
// POST /api/public_dashboards/delete.php
// Body: { id }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT pdl.id, s.owner_id
       FROM public_dashboard_links pdl
       JOIN surveys s ON s.id = pdl.survey_id
      WHERE pdl.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Link not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You can only delete your own links.', 403);
}

$pdo->prepare('DELETE FROM public_dashboard_links WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true]);
