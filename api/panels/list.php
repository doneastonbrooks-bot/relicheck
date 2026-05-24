<?php
// GET /api/panels/list.php
// Returns the calling user's 360 panels with subject/evaluator counts and a
// completion progress number so the My Panels view can render at-a-glance
// cards.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT p.id, p.survey_id, p.name, p.status, p.self_assessment,
            p.confidentiality_mode, p.launched_at, p.created_at, p.updated_at,
            s.title AS survey_title,
            (SELECT COUNT(*) FROM survey_360_subjects   sj WHERE sj.panel_id = p.id) AS subjects,
            (SELECT COUNT(*) FROM survey_360_evaluators ev WHERE ev.panel_id = p.id) AS evaluators,
            (SELECT COUNT(*) FROM survey_360_evaluators ev WHERE ev.panel_id = p.id AND ev.status = "completed") AS completed
       FROM survey_360_panels p
       JOIN surveys s ON s.id = p.survey_id
      WHERE p.user_id = :uid
      ORDER BY p.created_at DESC
      LIMIT 200'
);
$stmt->execute([':uid' => (int)$user['id']]);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $r) {
    $ev = (int)$r['evaluators'];
    $cm = (int)$r['completed'];
    $pct = $ev > 0 ? (int)round(($cm / $ev) * 100) : 0;
    $out[] = [
        'id'                   => (int)$r['id'],
        'survey_id'            => (int)$r['survey_id'],
        'survey_title'         => (string)$r['survey_title'],
        'name'                 => (string)$r['name'],
        'status'               => (string)$r['status'],
        'self_assessment'      => (int)$r['self_assessment'] === 1,
        'confidentiality_mode' => (string)$r['confidentiality_mode'],
        'launched_at'          => $r['launched_at'] !== null ? (string)$r['launched_at'] : null,
        'created_at'           => (string)$r['created_at'],
        'updated_at'           => (string)$r['updated_at'],
        'counts'               => [
            'subjects'           => (int)$r['subjects'],
            'evaluators'         => $ev,
            'completed'          => $cm,
            'completion_percent' => $pct,
        ],
    ];
}

json_out(['ok' => true, 'panels' => $out]);
