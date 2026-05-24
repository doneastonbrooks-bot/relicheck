<?php
// GET /api/mm/activity.php?limit=20
// Unified recent-activity feed for the Mixed-Methods Studio landing. Returns
// the most recent events across the user's MM projects (project created,
// data ingested, builder run, report generated), newest first.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1)  $limit = 1;
if ($limit > 50) $limit = 50;

$events = [];

// Project creations.
$stmt = $pdo->prepare(
    'SELECT id, title, created_at FROM mm_projects
     WHERE user_id = :uid ORDER BY created_at DESC LIMIT 30'
);
$stmt->execute([':uid' => $uid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $events[] = [
        'kind'       => 'project_created',
        'project_id' => (int)$r['id'],
        'title'      => (string)$r['title'],
        'detail'     => 'Project created',
        'at'         => (string)$r['created_at'],
    ];
}

// Data ingests.
$stmt = $pdo->prepare(
    'SELECT ds.project_id, ds.label, ds.row_count, ds.source_type, ds.created_at, p.title
     FROM mm_data_sources ds
     INNER JOIN mm_projects p ON p.id = ds.project_id AND p.user_id = :uid
     ORDER BY ds.created_at DESC LIMIT 30'
);
$stmt->execute([':uid' => $uid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $events[] = [
        'kind'       => 'data_ingested',
        'project_id' => (int)$r['project_id'],
        'title'      => (string)$r['title'],
        'detail'     => 'Added ' . (int)$r['row_count'] . ' responses from ' . (string)($r['label'] ?? $r['source_type']),
        'at'         => (string)$r['created_at'],
    ];
}

// Builder runs are detected from theme_categories created_at (the build
// inserts categories in one batch). Group by project + minute so a single
// builder pass collapses to one event.
$stmt = $pdo->prepare(
    'SELECT c.project_id, p.title,
            DATE_FORMAT(MAX(c.created_at), "%Y-%m-%d %H:%i:00") AS at,
            COUNT(*) AS n
     FROM mm_theme_categories c
     INNER JOIN mm_projects p ON p.id = c.project_id AND p.user_id = :uid
     GROUP BY c.project_id, DATE_FORMAT(c.created_at, "%Y-%m-%d %H:%i")
     ORDER BY at DESC LIMIT 30'
);
$stmt->execute([':uid' => $uid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $events[] = [
        'kind'       => 'builder_run',
        'project_id' => (int)$r['project_id'],
        'title'      => (string)$r['title'],
        'detail'     => 'Builder produced ' . (int)$r['n'] . ' categories',
        'at'         => (string)$r['at'],
    ];
}

// Report generations.
$stmt = $pdo->prepare(
    'SELECT r.project_id, r.title AS report_title, r.created_at, p.title
     FROM mm_reports r
     INNER JOIN mm_projects p ON p.id = r.project_id AND p.user_id = :uid
     ORDER BY r.created_at DESC LIMIT 30'
);
$stmt->execute([':uid' => $uid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $events[] = [
        'kind'       => 'report_generated',
        'project_id' => (int)$r['project_id'],
        'title'      => (string)$r['title'],
        'detail'     => 'Report generated: ' . (string)$r['report_title'],
        'at'         => (string)$r['created_at'],
    ];
}

// Sort by at desc, slice.
usort($events, function ($a, $b) { return strcmp($b['at'], $a['at']); });
$events = array_slice($events, 0, $limit);

json_out(['ok' => true, 'events' => $events]);
