<?php
// POST /api/public/report.php
// Body: { slug: string, password?: string }
//
// Public read-only feed for the public-report.html page. Mirrors the
// Phase 42 dashboard_data.php pattern. Returns ok=false / needs_password=true
// when a password is required, ok=false / reason=expired when past the
// expires_at window.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
// No check_origin: this is intended to be openly callable.

$body = read_json_body();
$slug = trim((string)($body['slug'] ?? ''));
$pwd  =      (string)($body['password'] ?? '');

if ($slug === '' || mb_strlen($slug) > 24) {
    json_out(['ok' => false, 'reason' => 'bad_slug']);
}

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'SELECT rs.id AS share_id, rs.report_id, rs.password_hash, rs.expires_at,
                r.title, r.template, r.snapshot_json, r.last_generated_at
           FROM report_shares rs
           JOIN reports r ON r.id = rs.report_id
          WHERE rs.slug = :s LIMIT 1'
    );
    $stmt->execute([':s' => $slug]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    json_out(['ok' => false, 'reason' => 'unavailable']);
}

if (!$row) {
    json_out(['ok' => false, 'reason' => 'not_found']);
}

// Expiry check.
if (!empty($row['expires_at'])) {
    $exp = $pdo->prepare('SELECT (NOW() > :e) AS expired');
    $exp->execute([':e' => $row['expires_at']]);
    if ((int)$exp->fetch()['expired'] === 1) {
        json_out(['ok' => false, 'reason' => 'expired']);
    }
}

// Password check. Rate-limit attempts per (IP, share) so brute-force is bounded.
if ($row['password_hash']) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($pwd !== '') {
        check_rate_limit('rpt_pwd:ip:' . $ip . ':share:' . (int)$row['share_id'], 20, 3600);
    }
    if ($pwd === '' || !password_verify($pwd, (string)$row['password_hash'])) {
        json_out(['ok' => false, 'needs_password' => true]);
    }
}

// Best-effort view-count bump.
try {
    $pdo->prepare('UPDATE report_shares SET view_count = view_count + 1 WHERE id = :id')
        ->execute([':id' => (int)$row['share_id']]);
} catch (Throwable $_) {}

$snapshot = $row['snapshot_json']
    ? (json_decode((string)$row['snapshot_json'], true) ?: null)
    : null;

json_out([
    'ok' => true,
    'report' => [
        'title'             => (string)$row['title'],
        'template'          => (string)$row['template'],
        'last_generated_at' => $row['last_generated_at'] ?: null,
        'snapshot'          => $snapshot,
    ],
]);
