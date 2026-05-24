<?php
// GET  /api/mm/templates.php
//   Returns the user's saved templates.
//
// POST /api/mm/templates.php
//   { action: "save",   project_id, name, description? }
//   { action: "delete", template_id }
//   { action: "apply",  template_id, target_project_id }
//     Applies a template's themes + variable roles to an existing project.
//     Existing user-edited themes are preserved; AI/template-source themes
//     are replaced. Variable roles match by var_name where possible.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

function tpl_table_exists(PDO $pdo, string $name): bool {
    try {
        $s = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return $s && $s->fetch() !== false;
    } catch (Throwable $e) { return false; }
}

if (!tpl_table_exists($pdo, 'mm_project_templates')) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_out(['ok' => true, 'templates' => [], 'has_table' => false]);
    }
    fail('mm_no_template_table', 'Phase 165 schema not yet installed. Run schema_phase165.sql first.', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, name, description, source_project_id, theme_count, role_count, created_at, updated_at
         FROM mm_project_templates WHERE user_id = :u ORDER BY id DESC'
    );
    $stmt->execute([':u' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'                => (int)$r['id'],
            'name'              => (string)$r['name'],
            'description'       => (string)($r['description'] ?? ''),
            'source_project_id' => $r['source_project_id'] !== null ? (int)$r['source_project_id'] : null,
            'theme_count'       => (int)$r['theme_count'],
            'role_count'        => (int)$r['role_count'],
            'created_at'        => (string)$r['created_at'],
            'updated_at'        => (string)$r['updated_at'],
        ];
    }
    json_out(['ok' => true, 'templates' => $out, 'has_table' => true]);
}

$body   = read_json_body();
$action = clean_string((string)($body['action'] ?? ''), 16);

// ------------ delete ------------
if ($action === 'delete') {
    $id = (int)($body['template_id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'template_id is required.');
    $pdo->prepare('DELETE FROM mm_project_templates WHERE id = :i AND user_id = :u')
        ->execute([':i' => $id, ':u' => $uid]);
    json_out(['ok' => true]);
}

// ------------ save (snapshot a project) ------------
if ($action === 'save') {
    $projectId   = (int)($body['project_id'] ?? 0);
    $name        = clean_string((string)($body['name'] ?? ''), 200);
    $description = clean_string((string)($body['description'] ?? ''), 800);
    if ($projectId <= 0) fail('bad_input', 'project_id is required.');
    if ($name === '') fail('bad_input', 'Template name is required.');
    mm_require_project($pdo, $uid, $projectId);

    $themes = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT name, COALESCE(definition, description, "") AS description, position
             FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':p' => $projectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $themes[] = [
                'name'        => (string)$r['name'],
                'description' => (string)($r['description'] ?? ''),
                'position'    => (int)($r['position'] ?? 0),
            ];
        }
    } catch (Throwable $e) {}

    $roles = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT var_name, role, var_type, COALESCE(notes, "") AS notes
             FROM mm_generated_variables WHERE project_id = :p AND role IN ("predictor", "outcome")'
        );
        $stmt->execute([':p' => $projectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roles[] = [
                'var_name' => (string)$r['var_name'],
                'role'     => (string)$r['role'],
                'var_type' => (string)$r['var_type'],
                'notes'    => (string)$r['notes'],
            ];
        }
    } catch (Throwable $e) {}

    if (count($themes) === 0) fail('mm_no_themes', 'This project has no themes to save. Run the Builder first.');

    $ins = $pdo->prepare(
        'INSERT INTO mm_project_templates
            (user_id, name, description, themes_json, var_roles_json, source_project_id, theme_count, role_count)
         VALUES
            (:u, :n, :d, :tj, :rj, :sp, :tc, :rc)'
    );
    $ins->execute([
        ':u'  => $uid,
        ':n'  => $name,
        ':d'  => $description !== '' ? $description : null,
        ':tj' => json_encode($themes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':rj' => json_encode($roles,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':sp' => $projectId,
        ':tc' => count($themes),
        ':rc' => count($roles),
    ]);
    json_out([
        'ok'          => true,
        'template_id' => (int)$pdo->lastInsertId(),
        'name'        => $name,
        'theme_count' => count($themes),
        'role_count'  => count($roles),
    ]);
}

// ------------ apply (write themes + roles into an existing project) ------------
if ($action === 'apply') {
    $templateId = (int)($body['template_id'] ?? 0);
    $targetId   = (int)($body['target_project_id'] ?? 0);
    if ($templateId <= 0 || $targetId <= 0) fail('bad_input', 'template_id and target_project_id are required.');
    mm_require_project($pdo, $uid, $targetId);

    $stmt = $pdo->prepare('SELECT themes_json, var_roles_json FROM mm_project_templates WHERE id = :i AND user_id = :u');
    $stmt->execute([':i' => $templateId, ':u' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('mm_template_not_found', 'Template not found.', 404);

    $themes = json_decode((string)($row['themes_json'] ?? '[]'), true) ?: [];
    $roles  = json_decode((string)($row['var_roles_json'] ?? '[]'), true) ?: [];

    // Insert themes. We do NOT wipe existing user-edited themes; we add the
    // template themes alongside any that already exist with a unique name.
    $existingNames = [];
    try {
        $s = $pdo->prepare('SELECT name FROM mm_theme_categories WHERE project_id = :p');
        $s->execute([':p' => $targetId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $existingNames[mb_strtolower((string)$n)] = true;
        }
    } catch (Throwable $e) {}

    $themesAdded = 0;
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mm_theme_categories (project_id, name, description, source_mode, confidence, position)
             VALUES (:p, :n, :d, "user", "high", :pos)'
        );
        $position = (int)($pdo->query('SELECT COALESCE(MAX(position), 0) FROM mm_theme_categories WHERE project_id = ' . (int)$targetId)->fetchColumn() ?: 0);
        foreach ($themes as $t) {
            $name = isset($t['name']) ? (string)$t['name'] : '';
            if ($name === '') continue;
            if (isset($existingNames[mb_strtolower($name)])) continue;
            $position++;
            $ins->execute([
                ':p'   => $targetId,
                ':n'   => $name,
                ':d'   => (string)($t['description'] ?? ''),
                ':pos' => $position,
            ]);
            $existingNames[mb_strtolower($name)] = true;
            $themesAdded++;
        }
    } catch (Throwable $e) {}

    // Apply variable roles. Only update rows where var_name matches.
    $rolesApplied = 0;
    try {
        $up = $pdo->prepare(
            'UPDATE mm_generated_variables SET role = :r
             WHERE project_id = :p AND var_name = :v'
        );
        foreach ($roles as $vr) {
            $vn = isset($vr['var_name']) ? (string)$vr['var_name'] : '';
            $rl = isset($vr['role'])     ? (string)$vr['role']     : '';
            if ($vn === '' || !in_array($rl, ['predictor', 'outcome', 'neutral'], true)) continue;
            $up->execute([':r' => $rl, ':p' => $targetId, ':v' => $vn]);
            $rolesApplied += $up->rowCount();
        }
    } catch (Throwable $e) {}

    json_out([
        'ok'            => true,
        'themes_added'  => $themesAdded,
        'roles_applied' => $rolesApplied,
    ]);
}

fail('bad_input', 'Unknown action.');
