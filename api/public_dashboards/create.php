<?php
// POST /api/public_dashboards/create.php
// Body: { survey_id, password?: string, expires_at?: "YYYY-MM-DD" }
// Creates a new shareable read-only dashboard link.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

$pdo = db();
$own = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id LIMIT 1');
$own->execute([':id' => $sid]);
$srow = $own->fetch();
if (!$srow) fail('not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You can only create links on your own surveys.', 403);
}

$pwd  = trim((string)($body['password'] ?? ''));
$pwh  = null;
if ($pwd !== '') {
    if (mb_strlen($pwd) > 200) fail('bad_password', 'Password too long.', 400);
    $pwh = password_hash($pwd, PASSWORD_DEFAULT);
}

$exp = null;
$expRaw = trim((string)($body['expires_at'] ?? ''));
if ($expRaw !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expRaw)) {
        fail('bad_expires', 'Expires must be YYYY-MM-DD.', 400);
    }
    $exp = $expRaw . ' 23:59:59';
}

// 24-char unguessable slug (about 142 bits of entropy from random_bytes(18)).
$slug = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
if (mb_strlen($slug) > 24) $slug = mb_substr($slug, 0, 24);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO public_dashboard_links
            (survey_id, slug, password_hash, expires_at, created_by)
         VALUES (:sid, :slug, :ph, :exp, :uid)'
    );
    $stmt->execute([
        ':sid'  => $sid,
        ':slug' => $slug,
        ':ph'   => $pwh,
        ':exp'  => $exp,
        ':uid'  => (int)$user['id'],
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 42 migration has not been applied yet.', 503);
}

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');

json_out([
    'link' => [
        'id'           => $id,
        'slug'         => $slug,
        'url'          => $siteUrl . '/dashboard.html?s=' . urlencode($slug),
        'has_password' => $pwh !== null,
        'expires_at'   => $exp,
        'view_count'   => 0,
    ],
], 201);
