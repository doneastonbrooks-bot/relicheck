<?php
// POST /api/reports/share.php
// Body modes:
//   { action: "create", report_id, password?, expires_at? "YYYY-MM-DD" }
//   { action: "delete", share_id }
// Mirrors the Phase 42 public_dashboards/create.php pattern.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('POST');
check_origin();
$user = require_auth();

$body   = read_json_body();
$action = clean_string((string)($body['action'] ?? 'create'), 16);

$pdo = db();

if ($action === 'delete') {
    $sid = (int)($body['share_id'] ?? 0);
    if ($sid <= 0) fail('bad_input', 'Missing share_id.', 400);
    $own = $pdo->prepare(
        'SELECT rs.id, r.user_id
           FROM report_shares rs JOIN reports r ON r.id = rs.report_id
          WHERE rs.id = :id LIMIT 1'
    );
    try { $own->execute([':id' => $sid]); } catch (Throwable $e) {
        fail('migration_pending', 'Phase 148 migration has not been applied yet.', 503);
    }
    $row = $own->fetch();
    if (!$row) fail('not_found', 'Share not found.', 404);
    if ((int)$row['user_id'] !== (int)$user['id']) {
        fail('forbidden', 'Not your share.', 403);
    }
    $pdo->prepare('DELETE FROM report_shares WHERE id = :id')->execute([':id' => $sid]);
    json_out(['ok' => true]);
}

// ---- create
$rid = (int)($body['report_id'] ?? 0);
if ($rid <= 0) fail('bad_input', 'Missing report_id.', 400);

$own = $pdo->prepare('SELECT user_id FROM reports WHERE id = :id LIMIT 1');
try { $own->execute([':id' => $rid]); } catch (Throwable $e) {
    fail('migration_pending', 'Phase 148 migration has not been applied yet.', 503);
}
$row = $own->fetch();
if (!$row) fail('not_found', 'Report not found.', 404);
if ((int)$row['user_id'] !== (int)$user['id']) {
    fail('forbidden', 'Not your report.', 403);
}

$pwd = trim((string)($body['password'] ?? ''));
$pwh = null;
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

$slug = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
if (mb_strlen($slug) > 24) $slug = mb_substr($slug, 0, 24);

$stmt = $pdo->prepare(
    'INSERT INTO report_shares
        (report_id, slug, password_hash, expires_at, created_by)
     VALUES (:rid, :slug, :ph, :exp, :uid)'
);
$stmt->execute([
    ':rid'  => $rid,
    ':slug' => $slug,
    ':ph'   => $pwh,
    ':exp'  => $exp,
    ':uid'  => (int)$user['id'],
]);
$sid = (int)$pdo->lastInsertId();

// Flip status to shared.
$pdo->prepare("UPDATE reports SET status = 'shared' WHERE id = :id")
    ->execute([':id' => $rid]);

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');

json_out([
    'ok' => true,
    'share' => [
        'id'           => $sid,
        'slug'         => $slug,
        'url'          => $siteUrl . '/public-report.html?s=' . urlencode($slug),
        'has_password' => $pwh !== null,
        'expires_at'   => $exp,
        'view_count'   => 0,
    ],
], 201);
