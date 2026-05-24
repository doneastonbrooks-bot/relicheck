<?php
// GET /api/public_dashboards/list.php?survey_id=N
// Returns the share-link rows owned by the caller for one survey.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$sid = (int)($_GET['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

$pdo = db();

$own = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id LIMIT 1');
$own->execute([':id' => $sid]);
$srow = $own->fetch();
if (!$srow) fail('not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You can only manage links on your own surveys.', 403);
}

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');

$rows = [];
try {
    $stmt = $pdo->prepare(
        'SELECT id, slug,
                (password_hash IS NOT NULL) AS has_password,
                expires_at, view_count, created_at
           FROM public_dashboard_links
          WHERE survey_id = :sid
          ORDER BY created_at DESC'
    );
    $stmt->execute([':sid' => $sid]);
    while ($r = $stmt->fetch()) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'slug'         => (string)$r['slug'],
            'url'          => $siteUrl . '/dashboard.html?s=' . urlencode((string)$r['slug']),
            'has_password' => (bool)$r['has_password'],
            'expires_at'   => $r['expires_at'],
            'view_count'   => (int)$r['view_count'],
            'created_at'   => $r['created_at'],
        ];
    }
} catch (Throwable $e) {
    json_out(['links' => [], 'note' => 'phase42_pending']);
}

json_out(['links' => $rows]);
