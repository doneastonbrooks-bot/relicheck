<?php
// GET /api/tests/list.php
// Returns the current user's tests with summary metadata. No responses.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$stmt = db()->prepare(
    'SELECT t.id, t.title, t.description, t.num_items, t.pass_threshold,
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
        'id'              => (int)$r['id'],
        'title'           => $r['title'],
        'description'     => $r['description'],
        'num_items'       => (int)$r['num_items'],
        'pass_threshold'  => $r['pass_threshold'] !== null ? (float)$r['pass_threshold'] : 70.0,
        'response_count'  => (int)$r['response_count'],
        'avg_percent'     => $r['avg_percent'] !== null ? round((float)$r['avg_percent'], 1) : null,
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'],
    ];
}

json_out(['tests' => $rows]);
