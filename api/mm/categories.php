<?php
// GET  /api/mm/categories.php?project_id=N   list this project's categories
// POST /api/mm/categories.php                actions: rename / merge / split / delete / add

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
    mm_require_project_or_coder($pdo, $uid, $projectId);
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.description, c.definition, c.confidence, c.source_mode,
                c.tone, c.is_final, c.question_id,
                (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS coded_count
         FROM mm_theme_categories c
         WHERE c.project_id = :p
         ORDER BY c.position ASC, c.id ASC'
    );
    $stmt->execute([':p' => $projectId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'description'  => (string)($r['description'] ?? ''),
            'definition'   => (string)($r['definition'] ?? ''),
            'confidence'   => (string)($r['confidence'] ?? 'moderate'),
            'source_mode'  => (string)($r['source_mode'] ?? 'auto'),
            'tone'         => $r['tone'] !== null ? (string)$r['tone'] : '',
            'is_final'     => (int)($r['is_final'] ?? 0) === 1,
            'question_id'  => $r['question_id'] !== null ? (int)$r['question_id'] : null,
            'coded_count'  => (int)$r['coded_count'],
        ];
    }
    json_out(['ok' => true, 'categories' => $out]);
}

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 16);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

function mm_owns_category(PDO $pdo, int $projectId, int $catId): array
{
    $s = $pdo->prepare('SELECT * FROM mm_theme_categories WHERE id = :i AND project_id = :p');
    $s->execute([':i' => $catId, ':p' => $projectId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('mm_category_not_found', 'Category not found in this project.', 404);
    return $r;
}

if ($action === 'rename') {
    $catId = (int)($body['category_id'] ?? 0);
    $name  = clean_string((string)($body['name'] ?? ''), 200);
    if ($catId <= 0 || $name === '') fail('bad_input', 'rename needs category_id and name.');
    mm_owns_category($pdo, $projectId, $catId);
    $stmt = $pdo->prepare('UPDATE mm_theme_categories SET name = :n, source_mode = "user" WHERE id = :i');
    $stmt->execute([':n' => $name, ':i' => $catId]);
    json_out(['ok' => true]);
}

if ($action === 'add') {
    $name = clean_string((string)($body['name'] ?? ''), 200);
    $desc = clean_string((string)($body['description'] ?? ''), 600);
    if ($name === '') fail('bad_input', 'add needs name.');
    $maxPos = (int)$pdo->query("SELECT IFNULL(MAX(position), 0) FROM mm_theme_categories WHERE project_id = " . $projectId)->fetchColumn();
    $stmt = $pdo->prepare(
        'INSERT INTO mm_theme_categories (project_id, name, description, source_mode, confidence, position)
         VALUES (:p, :n, :d, "user", "moderate", :pos)'
    );
    $stmt->execute([':p' => $projectId, ':n' => $name, ':d' => $desc, ':pos' => $maxPos + 1]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'delete') {
    $catId = (int)($body['category_id'] ?? 0);
    if ($catId <= 0) fail('bad_input', 'delete needs category_id.');
    mm_owns_category($pdo, $projectId, $catId);
    $pdo->prepare('DELETE FROM mm_theme_categories WHERE id = :i')->execute([':i' => $catId]);
    json_out(['ok' => true]);
}

if ($action === 'merge') {
    $sourceIds = $body['source_ids'] ?? [];
    $newName   = clean_string((string)($body['target_name'] ?? ''), 200);
    // Optional: preserve a description on the merged theme (otherwise blank).
    $newDesc   = clean_string((string)($body['target_description'] ?? ''), 600);
    if (!is_array($sourceIds) || count($sourceIds) < 2 || $newName === '') {
        fail('bad_input', 'merge needs source_ids (>= 2) and target_name.');
    }
    $cleanIds = [];
    foreach ($sourceIds as $sid) {
        $sid = (int)$sid;
        if ($sid <= 0) continue;
        mm_owns_category($pdo, $projectId, $sid);
        $cleanIds[] = $sid;
    }
    if (count($cleanIds) < 2) fail('bad_input', 'Need at least 2 valid source_ids.');

    $pdo->beginTransaction();
    try {
        $maxPos = (int)$pdo->query("SELECT IFNULL(MAX(position), 0) FROM mm_theme_categories WHERE project_id = " . $projectId)->fetchColumn();
        $ins = $pdo->prepare(
            'INSERT INTO mm_theme_categories (project_id, name, description, source_mode, confidence, position)
             VALUES (:p, :n, :d, "user", "moderate", :pos)'
        );
        $ins->execute([':p' => $projectId, ':n' => $newName, ':d' => $newDesc, ':pos' => $maxPos + 1]);
        $newId = (int)$pdo->lastInsertId();

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $upd = $pdo->prepare("UPDATE IGNORE mm_coded_responses SET category_id = ? WHERE category_id IN (" . $placeholders . ")");
        $upd->execute(array_merge([$newId], $cleanIds));

        $del = $pdo->prepare("DELETE FROM mm_theme_categories WHERE id IN (" . $placeholders . ")");
        $del->execute($cleanIds);

        $pdo->commit();
        json_out(['ok' => true, 'new_category_id' => $newId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('mm_merge_failed', 'Could not merge: ' . $e->getMessage(), 500);
    }
}

if ($action === 'split') {
    $catId = (int)($body['category_id'] ?? 0);
    $names = $body['new_names'] ?? [];
    if ($catId <= 0 || !is_array($names) || count($names) < 2) {
        fail('bad_input', 'split needs category_id and at least 2 new_names.');
    }
    mm_owns_category($pdo, $projectId, $catId);

    $clean = [];
    foreach ($names as $nm) {
        $nm = clean_string((string)$nm, 200);
        if ($nm !== '') $clean[] = $nm;
    }
    if (count($clean) < 2) fail('bad_input', 'Need at least 2 non-empty names.');

    $pdo->beginTransaction();
    try {
        $maxPos = (int)$pdo->query("SELECT IFNULL(MAX(position), 0) FROM mm_theme_categories WHERE project_id = " . $projectId)->fetchColumn();
        $ins = $pdo->prepare(
            'INSERT INTO mm_theme_categories (project_id, name, description, source_mode, confidence, position)
             VALUES (:p, :n, "", "user", "moderate", :pos)'
        );
        $newIds = [];
        foreach ($clean as $i => $nm) {
            $ins->execute([':p' => $projectId, ':n' => $nm, ':pos' => $maxPos + 1 + $i]);
            $newIds[] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM mm_theme_categories WHERE id = :i')->execute([':i' => $catId]);
        $pdo->commit();
        json_out(['ok' => true, 'new_ids' => $newIds]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('mm_split_failed', 'Could not split: ' . $e->getMessage(), 500);
    }
}

fail('bad_input', 'Unknown action.');
