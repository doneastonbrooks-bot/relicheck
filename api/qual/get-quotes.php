<?php
// GET /api/qual/get-quotes.php?project_id=N&theme_id=M
// Returns theme detail, segments linked through the theme's categories,
// and which segments are pinned as exemplar quotes.
//
// "Linked" = segment has a code applied whose parent_category_id is in a
// category connected to this theme via qual_theme_categories.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
$themeId   = (int)($_GET['theme_id']   ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_require_project($pdo, $uid, $projectId);

// All themes for the selector
$tSt = $pdo->prepare(
    "SELECT id, name, interpretive_claim
     FROM qual_themes WHERE project_id=:p ORDER BY position ASC, id ASC"
);
$tSt->execute([':p' => $projectId]);
$allThemes = $tSt->fetchAll(PDO::FETCH_ASSOC);

if ($themeId <= 0) {
    json_out(['ok' => true, 'themes' => $allThemes, 'theme' => null, 'segments' => [], 'pinned' => []]);
}

// Verify theme belongs to project
$themeSt = $pdo->prepare(
    "SELECT id, name, interpretive_claim, notes FROM qual_themes WHERE id=:id AND project_id=:p LIMIT 1"
);
$themeSt->execute([':id' => $themeId, ':p' => $projectId]);
$theme = $themeSt->fetch(PDO::FETCH_ASSOC);
if (!$theme) fail('not_found', 'Theme not found.', 404);

// Segments linked to this theme through code → category → theme chain
$segSt = $pdo->prepare(
    "SELECT DISTINCT s.id, s.raw_text, s.cleaned_text, s.participant_id,
            s.question_ref, s.metadata_json, s.word_count, s.seg_order
     FROM qual_segments s
     JOIN qual_code_applications a  ON a.segment_id = s.id  AND a.project_id = :p
     JOIN qual_codes c              ON c.id = a.code_id     AND c.project_id = :p
     JOIN qual_theme_categories tc  ON tc.category_id = c.parent_category_id
     WHERE s.project_id = :p AND s.status = 'active' AND tc.theme_id = :t
     ORDER BY s.seg_order ASC
     LIMIT 500"
);
$segSt->execute([':p' => $projectId, ':t' => $themeId]);
$segments = $segSt->fetchAll(PDO::FETCH_ASSOC);

// For each segment, collect the codes (with category) that link it to this theme
$codesBySeg = [];
if ($segments) {
    $segIds = implode(',', array_map('intval', array_column($segments, 'id')));
    $codeSt = $pdo->query(
        "SELECT a.segment_id, c.id AS code_id, c.name AS code_name,
                cat.id AS cat_id, cat.name AS cat_name
         FROM qual_code_applications a
         JOIN qual_codes c           ON c.id = a.code_id
         JOIN qual_categories cat    ON cat.id = c.parent_category_id
         JOIN qual_theme_categories tc ON tc.category_id = cat.id AND tc.theme_id = {$themeId}
         WHERE a.project_id = {$projectId} AND a.segment_id IN ({$segIds})"
    );
    foreach ($codeSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $codesBySeg[(int)$row['segment_id']][] = $row;
    }
}

foreach ($segments as &$seg) {
    $seg['theme_codes'] = $codesBySeg[(int)$seg['id']] ?? [];
    if ($seg['metadata_json'] && is_string($seg['metadata_json'])) {
        $seg['metadata_json'] = json_decode($seg['metadata_json'], true);
    }
}
unset($seg);

// Pinned exemplar quotes for this theme
$pinSt = $pdo->prepare(
    "SELECT segment_id, note, created_at FROM qual_theme_quotes
     WHERE theme_id=:t AND project_id=:p ORDER BY created_at ASC"
);
$pinSt->execute([':t' => $themeId, ':p' => $projectId]);
$pinned = $pinSt->fetchAll(PDO::FETCH_ASSOC);
$pinnedIds = array_flip(array_column($pinned, 'segment_id'));

json_out([
    'ok'       => true,
    'themes'   => $allThemes,
    'theme'    => $theme,
    'segments' => $segments,
    'pinned'   => $pinned,
    'pinned_ids' => array_keys($pinnedIds),
]);
