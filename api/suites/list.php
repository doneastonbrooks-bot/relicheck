<?php
// GET /api/suites/list.php
// Returns the caller's suites with survey counts. Lazy-seeds the seven
// system suites on first access.

declare(strict_types=1);

// Phase 134a diagnostic. Surfaces the real PHP error in the JSON body
// instead of letting IONOS swallow it into a generic 500. Remove once
// the cause is identified and patched.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('GET');
$user = require_auth();
$userId = (int)$user['id'];

suites_ensure_system_for_user($userId);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT s.id, s.suite_key, s.name, s.description, s.color, s.icon,
            s.is_system, s.display_order, s.status, s.created_at, s.updated_at,
            (SELECT COUNT(*) FROM suite_surveys ss WHERE ss.suite_id = s.id) AS survey_count,
            (SELECT COUNT(*) FROM suite_tests   st WHERE st.suite_id = s.id) AS test_count
       FROM suites s
      WHERE s.user_id = :uid AND s.status = "active"
      ORDER BY s.display_order ASC, s.created_at ASC'
);
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll();

// Also include template counts (real + coming-soon) per system suite.
$tpls = [];
foreach (suites_system_definitions() as $def) {
    $tpls[$def['suite_key']] = $def['templates'];
}

$out = [];
foreach ($rows as $r) {
    $sk = (string)$r['suite_key'];
    $tplList = $tpls[$sk] ?? [];
    $tplAvailable = 0;
    foreach ($tplList as $t) { if (!empty($t['available'])) $tplAvailable++; }
    $entityType = suites_entity_type_for_key($sk);
    $itemCount = $entityType === 'test' ? (int)$r['test_count'] : (int)$r['survey_count'];
    $out[] = [
        'id'              => (int)$r['id'],
        'suite_key'       => $sk,
        'name'            => (string)$r['name'],
        'description'     => $r['description'] !== null ? (string)$r['description'] : '',
        'color'           => (string)$r['color'],
        'icon'            => (string)$r['icon'],
        'is_system'       => (int)$r['is_system'] === 1,
        'entity_type'     => $entityType,
        'display_order'   => (int)$r['display_order'],
        'survey_count'    => (int)$r['survey_count'],
        'test_count'      => (int)$r['test_count'],
        'item_count'      => $itemCount,
        'template_count'  => count($tplList),
        'template_available_count' => $tplAvailable,
        'created_at'      => (string)$r['created_at'],
        'updated_at'      => (string)$r['updated_at'],
    ];
}

json_out(['ok' => true, 'suites' => $out]);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'error'   => 'phase134a_diag',
        'message' => $e->getMessage(),
        'class'   => get_class($e),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
}
