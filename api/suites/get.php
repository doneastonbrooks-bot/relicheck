<?php
// GET /api/suites/get.php?id=<suite_id>
// Returns one suite plus its templates list (curated for system suites)
// and the surveys currently attached to it.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('GET');
$user = require_auth();
$userId = (int)$user['id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

$suite = suites_require_owned($id, $userId);

$entityType = suites_entity_type_for_key((string)$suite['suite_key']);

$pdo = db();

$surveys = [];
$tests   = [];

if ($entityType === 'test') {
    $tStmt = $pdo->prepare(
        'SELECT t.id, t.title, t.description, t.num_items, t.created_at, t.updated_at, st.added_at
           FROM suite_tests st
           JOIN tests t ON t.id = st.test_id
          WHERE st.suite_id = :sid AND t.user_id = :uid AND t.archived_at IS NULL
          ORDER BY st.added_at DESC'
    );
    $tStmt->execute([':sid' => $id, ':uid' => $userId]);
    foreach ($tStmt->fetchAll() as $r) {
        $tests[] = [
            'id'          => (int)$r['id'],
            'title'       => (string)$r['title'],
            'description' => $r['description'] !== null ? (string)$r['description'] : '',
            'num_items'   => (int)$r['num_items'],
            'created_at'  => (string)$r['created_at'],
            'updated_at'  => (string)$r['updated_at'],
            'added_at'    => (string)$r['added_at'],
        ];
    }
} else {
    $rStmt = $pdo->prepare(
        'SELECT s.id, s.title, s.description, s.is_published, s.slug,
                s.created_at, s.updated_at, ss.added_at
           FROM suite_surveys ss
           JOIN surveys s ON s.id = ss.survey_id
          WHERE ss.suite_id = :sid AND s.owner_id = :uid
          ORDER BY ss.added_at DESC'
    );
    $rStmt->execute([':sid' => $id, ':uid' => $userId]);
    foreach ($rStmt->fetchAll() as $r) {
        $surveys[] = [
            'id'           => (int)$r['id'],
            'title'        => (string)$r['title'],
            'description'  => $r['description'] !== null ? (string)$r['description'] : '',
            'is_published' => (int)$r['is_published'] === 1,
            'slug'         => (string)$r['slug'],
            'created_at'   => (string)$r['created_at'],
            'updated_at'   => (string)$r['updated_at'],
            'added_at'     => (string)$r['added_at'],
        ];
    }
}

// Templates list: pull canonical curated list when system suite.
$templates = (int)$suite['is_system'] === 1
    ? suites_templates_for_key((string)$suite['suite_key'])
    : [];

json_out([
    'ok'    => true,
    'suite' => [
        'id'            => (int)$suite['id'],
        'suite_key'     => (string)$suite['suite_key'],
        'name'          => (string)$suite['name'],
        'description'   => $suite['description'] !== null ? (string)$suite['description'] : '',
        'color'         => (string)$suite['color'],
        'icon'          => (string)$suite['icon'],
        'is_system'     => (int)$suite['is_system'] === 1,
        'entity_type'   => $entityType,
        'display_order' => (int)$suite['display_order'],
        'created_at'    => (string)$suite['created_at'],
        'updated_at'    => (string)$suite['updated_at'],
    ],
    'templates' => $templates,
    'surveys'   => $surveys,
    'tests'     => $tests,
]);
