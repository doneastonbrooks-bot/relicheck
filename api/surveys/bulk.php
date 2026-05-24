<?php
// POST /api/surveys/bulk.php
// Body: { ids: int[], action: 'archive'|'unarchive'|'delete'|'duplicate'|'move_folder',
//         folder_id?: int|null }
// Applies a single action to multiple surveys owned by the caller. Returns a
// per-id outcome map so the client can report partial successes accurately.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/templates.php';

require_method('POST');
check_origin();
$user = require_auth();

$body   = read_json_body();
$ids    = is_array($body['ids'] ?? null) ? $body['ids'] : [];
$action = (string)($body['action'] ?? '');

$validActions = ['archive', 'unarchive', 'delete', 'duplicate', 'move_folder'];
if (!in_array($action, $validActions, true)) {
    fail('bad_action', 'Unsupported bulk action.', 400);
}

// Coerce ids to a clean positive-integer list.
$cleanIds = [];
foreach ($ids as $v) {
    $n = (int)$v;
    if ($n > 0) $cleanIds[$n] = true;
}
$cleanIds = array_keys($cleanIds);
if (!$cleanIds) {
    fail('no_ids', 'Pick at least one survey.', 400);
}
if (count($cleanIds) > 500) {
    fail('too_many', 'Bulk action limited to 500 surveys at a time.', 400);
}

$folderId = null;
if ($action === 'move_folder') {
    if (array_key_exists('folder_id', $body) && $body['folder_id'] !== null && $body['folder_id'] !== '') {
        $folderId = (int)$body['folder_id'];
        if ($folderId <= 0) {
            fail('bad_folder', 'Folder id must be a positive integer or null.', 400);
        }
    }
}

$pdo = db();

// Confirm ownership for every id up front. Surveys not owned by the caller
// are recorded as 'forbidden' in the result map and skipped silently.
$placeholders = implode(',', array_map('intval', $cleanIds));
$rows = $pdo->query(
    'SELECT id, owner_id FROM surveys WHERE id IN (' . $placeholders . ')'
)->fetchAll();

$ownedBy = [];
foreach ($rows as $r) {
    $ownedBy[(int)$r['id']] = (int)$r['owner_id'];
}

if ($action === 'move_folder' && $folderId !== null) {
    $f = $pdo->prepare('SELECT id, owner_id FROM folders WHERE id = :id');
    $f->execute([':id' => $folderId]);
    $fr = $f->fetch();
    if (!$fr) fail('folder_not_found', 'Folder not found.', 404);
    if ((int)$fr['owner_id'] !== (int)$user['id']) {
        fail('forbidden', 'You can only move surveys into your own folders.', 403);
    }
}

$results = [];
$ok      = 0;
$fail    = 0;

foreach ($cleanIds as $sid) {
    if (!isset($ownedBy[$sid])) {
        $results[$sid] = 'not_found';
        $fail++;
        continue;
    }
    if ($ownedBy[$sid] !== (int)$user['id']) {
        $results[$sid] = 'forbidden';
        $fail++;
        continue;
    }

    try {
        switch ($action) {
            case 'archive':
                $pdo->prepare('UPDATE surveys SET archived_at = NOW() WHERE id = :id')
                    ->execute([':id' => $sid]);
                $results[$sid] = 'archived';
                $ok++;
                break;

            case 'unarchive':
                $pdo->prepare('UPDATE surveys SET archived_at = NULL WHERE id = :id')
                    ->execute([':id' => $sid]);
                $results[$sid] = 'unarchived';
                $ok++;
                break;

            case 'move_folder':
                $pdo->prepare('UPDATE surveys SET folder_id = :f WHERE id = :id')->execute([
                    ':f'  => $folderId,
                    ':id' => $sid,
                ]);
                $results[$sid] = 'moved';
                $ok++;
                break;

            case 'delete':
                // Hard delete. Cascading FKs (responses, etc.) handle the rest.
                $pdo->prepare('DELETE FROM surveys WHERE id = :id')
                    ->execute([':id' => $sid]);
                $results[$sid] = 'deleted';
                $ok++;
                break;

            case 'duplicate':
                // Per-tier survey count cap: re-check on every dupe so 50 selected
                // surveys can't blast past a 5-survey Free plan.
                $cs = $pdo->prepare('SELECT COUNT(*) AS c FROM surveys WHERE owner_id = :u AND archived_at IS NULL');
                $cs->execute([':u' => (int)$user['id']]);
                $cur = (int)($cs->fetch()['c'] ?? 0);
                require_under_limit((int)$user['id'], 'max_surveys', $cur, 1);

                $src = $pdo->prepare('SELECT title, description, settings, questions FROM surveys WHERE id = :id');
                $src->execute([':id' => $sid]);
                $srow = $src->fetch();
                if (!$srow) { $results[$sid] = 'not_found'; $fail++; break; }
                $slug = unique_survey_slug($pdo);
                $title = trim('Copy of ' . (string)$srow['title']);
                $ins = $pdo->prepare(
                    'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
                     VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
                );
                $ins->execute([
                    ':uid'       => (int)$user['id'],
                    ':slug'      => $slug,
                    ':title'     => clean_string($title, 255) ?: 'Untitled survey',
                    ':desc'      => clean_string((string)$srow['description'], 4000),
                    ':settings'  => (string)$srow['settings'],
                    ':questions' => (string)$srow['questions'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                $results[$sid] = ['duplicated' => $newId];
                $ok++;
                break;
        }
    } catch (Throwable $e) {
        $results[$sid] = 'error';
        $fail++;
    }
}

json_out([
    'ok'      => true,
    'action'  => $action,
    'count'   => count($cleanIds),
    'success' => $ok,
    'failed'  => $fail,
    'results' => $results,
]);
