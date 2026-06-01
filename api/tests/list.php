<?php
// GET /api/tests/list.php
// Returns the current user's tests with summary metadata. No responses.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_tia_schema.php';

require_method('GET');
$user = require_auth();

$pdo = db();
// Phase 181: ensure the metadata columns exist before SELECT references them.
tia_ensure_tests_schema($pdo);
$stmt = $pdo->prepare(
    'SELECT t.id, t.title, t.description, t.num_items, t.pass_threshold,
            t.assessment_purpose, t.decision_type, t.intended_cognitive_demand,
            t.includes_open_ended, t.includes_rubric, t.includes_group_analysis, t.status,
            t.created_at, t.updated_at,
            (SELECT COUNT(*)  FROM test_responses tr WHERE tr.test_id = t.id) AS response_count,
            (SELECT AVG(tr.percent_correct) FROM test_responses tr WHERE tr.test_id = t.id) AS avg_percent
       FROM tests t
      WHERE t.user_id = :uid AND t.archived_at IS NULL
      ORDER BY t.updated_at DESC'
);
$stmt->execute([':uid' => $user['id']]);

$rows = [];
while ($r = $stmt->fetch()) {
    $rows[] = [
        'id'                        => (int)$r['id'],
        'title'                     => $r['title'],
        'description'               => $r['description'],
        'num_items'                 => (int)$r['num_items'],
        'pass_threshold'            => $r['pass_threshold'] !== null ? (float)$r['pass_threshold'] : 70.0,
        // Phase 181: TIA Studio metadata for landing badges and quick filters.
        'assessment_purpose'        => $r['assessment_purpose'] !== null ? (string)$r['assessment_purpose'] : null,
        'decision_type'             => $r['decision_type'] !== null ? (string)$r['decision_type'] : null,
        'intended_cognitive_demand' => $r['intended_cognitive_demand'] !== null ? (string)$r['intended_cognitive_demand'] : null,
        'includes_open_ended'       => (int)$r['includes_open_ended'] === 1,
        'includes_rubric'           => (int)$r['includes_rubric'] === 1,
        'includes_group_analysis'   => (int)$r['includes_group_analysis'] === 1,
        'status'                    => $r['status'] !== null ? (string)$r['status'] : 'setup',
        'response_count'            => (int)$r['response_count'],
        'avg_percent'               => $r['avg_percent'] !== null ? round((float)$r['avg_percent'], 1) : null,
        'created_at'                => $r['created_at'],
        'updated_at'                => $r['updated_at'],
    ];
}

json_out(['tests' => $rows]);
