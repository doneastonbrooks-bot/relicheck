<?php
// GET  /api/mm/variable-roles.php?project_id=N
//   Lists every generated variable for the project with its current role.
//
// POST /api/mm/variable-roles.php
//   Body: { project_id, variable_id, role: "predictor" | "outcome" | "neutral" }
//   Sets one variable's role. Role drives Step 14 analysis suggestions.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    $stmt = $pdo->prepare(
        'SELECT id, var_name, display_label, var_type, role, source_category_id, notes
         FROM mm_generated_variables
         WHERE project_id = :p
         ORDER BY id ASC'
    );
    $stmt->execute([':p' => $projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'var_name'     => (string)$r['var_name'],
            'label'        => (string)($r['display_label'] ?? $r['var_name']),
            'type'         => (string)$r['var_type'],
            'role'         => (string)$r['role'],
            'category_id'  => $r['source_category_id'] !== null ? (int)$r['source_category_id'] : null,
            'notes'        => (string)($r['notes'] ?? ''),
        ];
    }
    json_out(['ok' => true, 'variables' => $out]);
}

// POST: edit a single variable. Accepts any combination of role / var_name /
// display_label / notes in the body. At least one of them must be present.
$body       = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$variableId = (int)($body['variable_id'] ?? 0);
if ($projectId <= 0 || $variableId <= 0) fail('bad_input', 'project_id and variable_id are required.');
mm_require_project($pdo, $uid, $projectId);

$ck = $pdo->prepare('SELECT id FROM mm_generated_variables WHERE id = :i AND project_id = :p');
$ck->execute([':i' => $variableId, ':p' => $projectId]);
if (!$ck->fetch()) fail('mm_variable_not_found', 'Variable not found in this project.', 404);

$fields = [];
$params = [':id' => $variableId];

if (array_key_exists('role', $body)) {
    $role = clean_string((string)$body['role'], 16);
    if (!in_array($role, ['predictor', 'outcome', 'neutral'], true)) {
        fail('bad_input', 'role must be predictor, outcome, or neutral.');
    }
    $fields[] = 'role = :r'; $params[':r'] = $role;
}
if (array_key_exists('var_name', $body)) {
    $vn = clean_string((string)$body['var_name'], 120);
    if ($vn === '') fail('bad_input', 'var_name cannot be empty.');
    // Normalize to a valid identifier: lowercase, underscores, no spaces.
    $vn = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $vn));
    $vn = trim($vn, '_');
    if ($vn === '') $vn = 'var';
    if (strlen($vn) > 60) $vn = substr($vn, 0, 60);
    // Ensure uniqueness inside this project.
    $u = $pdo->prepare('SELECT id FROM mm_generated_variables WHERE project_id = :p AND var_name = :n AND id <> :i');
    $u->execute([':p' => $projectId, ':n' => $vn, ':i' => $variableId]);
    if ($u->fetch()) fail('mm_var_name_taken', 'Another variable already uses that name.', 409);
    $fields[] = 'var_name = :vn'; $params[':vn'] = $vn;
}
if (array_key_exists('display_label', $body)) {
    $dl = clean_string((string)$body['display_label'], 200);
    $fields[] = 'display_label = :dl'; $params[':dl'] = $dl !== '' ? $dl : null;
}
if (array_key_exists('notes', $body)) {
    $n = clean_string((string)$body['notes'], 600);
    $fields[] = 'notes = :nt'; $params[':nt'] = $n !== '' ? $n : null;
}

if (count($fields) === 0) fail('bad_input', 'Provide at least one of role, var_name, display_label, or notes.');

$sql = 'UPDATE mm_generated_variables SET ' . implode(', ', $fields) . ' WHERE id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Return the fresh row so the front end can re-render without another GET.
$out = $pdo->prepare(
    'SELECT id, var_name, display_label, var_type, role, source_category_id, notes
     FROM mm_generated_variables WHERE id = :i'
);
$out->execute([':i' => $variableId]);
$row = $out->fetch(PDO::FETCH_ASSOC) ?: [];
json_out(['ok' => true, 'variable' => [
    'id'           => (int)($row['id'] ?? 0),
    'var_name'     => (string)($row['var_name'] ?? ''),
    'label'        => (string)($row['display_label'] ?? $row['var_name'] ?? ''),
    'type'         => (string)($row['var_type'] ?? ''),
    'role'         => (string)($row['role'] ?? 'neutral'),
    'category_id'  => isset($row['source_category_id']) && $row['source_category_id'] !== null ? (int)$row['source_category_id'] : null,
    'notes'        => (string)($row['notes'] ?? ''),
]]);
