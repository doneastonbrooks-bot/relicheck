<?php
// GET /api/reports/list.php
// Returns the current user's reports, newest first.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('GET');
check_origin();
$user = require_auth();

$pdo = db();
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.title, r.template, r.status, r.source_survey_id,
                r.schedule_cadence, r.schedule_next_at, r.last_generated_at,
                r.created_at, r.updated_at,
                s.title AS source_title,
                (SELECT COUNT(*) FROM report_shares rs WHERE rs.report_id = r.id) AS share_count
           FROM reports r
           LEFT JOIN surveys s ON s.id = r.source_survey_id
          WHERE r.user_id = :uid
          ORDER BY r.updated_at DESC
          LIMIT 200'
    );
    $stmt->execute([':uid' => (int)$user['id']]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 148 migration has not been applied yet.', 503);
}

$out = array_map(static function ($r) {
    return [
        'id'                => (int)$r['id'],
        'title'             => (string)$r['title'],
        'template'          => (string)$r['template'],
        'status'            => (string)$r['status'],
        'source_survey_id'  => (int)$r['source_survey_id'],
        'source_title'      => (string)($r['source_title'] ?? ''),
        'schedule_cadence'  => $r['schedule_cadence'] ?: null,
        'schedule_next_at'  => $r['schedule_next_at']  ?: null,
        'last_generated_at' => $r['last_generated_at'] ?: null,
        'created_at'        => (string)$r['created_at'],
        'updated_at'        => (string)$r['updated_at'],
        'share_count'       => (int)$r['share_count'],
    ];
}, $rows);

json_out(['ok' => true, 'reports' => $out]);
