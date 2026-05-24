<?php
// GET   /api/mm/clusters.php?project_id=N
//   Lists this project's clusters with member themes.
//
// POST  /api/mm/clusters.php
//   Body shapes:
//     { project_id, action: "generate", mode: "auto"|"by_number"|"by_category_type",
//       target_count?: int, category_labels?: [string,...] }
//     { project_id, action: "rename", cluster_id, name }
//     { project_id, action: "delete", cluster_id }
//
// Persists to mm_clusters and mm_cluster_members. Replaces previous clusters
// on a fresh generate run.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
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

    $cstmt = $pdo->prepare(
        'SELECT id, name, description, mode, position, created_at
         FROM mm_clusters WHERE project_id = :p ORDER BY position ASC, id ASC'
    );
    $cstmt->execute([':p' => $projectId]);
    $clusters = $cstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $mstmt = $pdo->prepare(
        'SELECT cm.cluster_id, cm.theme_id, c.name, c.confidence,
                (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS coded_count
         FROM mm_cluster_members cm
         INNER JOIN mm_theme_categories c ON c.id = cm.theme_id
         WHERE c.project_id = :p
         ORDER BY cm.cluster_id ASC, c.position ASC, c.id ASC'
    );
    $mstmt->execute([':p' => $projectId]);
    $byCluster = [];
    foreach ($mstmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $cid = (int)$m['cluster_id'];
        if (!isset($byCluster[$cid])) $byCluster[$cid] = [];
        $byCluster[$cid][] = [
            'theme_id'    => (int)$m['theme_id'],
            'name'        => (string)$m['name'],
            'confidence'  => (string)$m['confidence'],
            'coded_count' => (int)$m['coded_count'],
        ];
    }

    $out = [];
    foreach ($clusters as $cl) {
        $out[] = [
            'id'          => (int)$cl['id'],
            'name'        => (string)$cl['name'],
            'description' => (string)($cl['description'] ?? ''),
            'mode'        => (string)$cl['mode'],
            'position'    => (int)$cl['position'],
            'themes'      => $byCluster[(int)$cl['id']] ?? [],
        ];
    }
    json_out(['ok' => true, 'clusters' => $out]);
}

check_rate_limit('mm_clusters:user:' . $uid, 30, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 32);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if ($action === 'rename') {
    $clusterId = (int)($body['cluster_id'] ?? 0);
    $name      = clean_string((string)($body['name'] ?? ''), 200);
    if ($clusterId <= 0 || $name === '') fail('bad_input', 'rename needs cluster_id and name.');
    $ck = $pdo->prepare('SELECT id FROM mm_clusters WHERE id = :i AND project_id = :p');
    $ck->execute([':i' => $clusterId, ':p' => $projectId]);
    if (!$ck->fetch()) fail('mm_cluster_not_found', 'Cluster not found.', 404);
    $pdo->prepare('UPDATE mm_clusters SET name = :n WHERE id = :i')->execute([':n' => $name, ':i' => $clusterId]);
    json_out(['ok' => true]);
}

if ($action === 'delete') {
    $clusterId = (int)($body['cluster_id'] ?? 0);
    if ($clusterId <= 0) fail('bad_input', 'delete needs cluster_id.');
    $ck = $pdo->prepare('SELECT id FROM mm_clusters WHERE id = :i AND project_id = :p');
    $ck->execute([':i' => $clusterId, ':p' => $projectId]);
    if (!$ck->fetch()) fail('mm_cluster_not_found', 'Cluster not found.', 404);
    $pdo->prepare('DELETE FROM mm_clusters WHERE id = :i')->execute([':i' => $clusterId]);
    json_out(['ok' => true]);
}

if ($action !== 'generate') fail('bad_input', 'Unknown action.');

$mode = clean_string((string)($body['mode'] ?? 'auto'), 32);
if (!in_array($mode, ['auto','by_number','by_category_type'], true)) {
    fail('bad_input', 'mode must be auto, by_number, or by_category_type.');
}
$targetCount = (int)($body['target_count'] ?? 4);
if ($targetCount < 2)  $targetCount = 2;
if ($targetCount > 10) $targetCount = 10;

$labels = [];
if (isset($body['category_labels']) && is_array($body['category_labels'])) {
    foreach ($body['category_labels'] as $l) {
        $l = clean_string((string)$l, 200);
        if ($l !== '' && !in_array($l, $labels, true)) $labels[] = $l;
        if (count($labels) >= 10) break;
    }
}
if ($mode === 'by_category_type' && count($labels) < 2) {
    fail('bad_input', 'by_category_type needs at least 2 category_labels.');
}

// Pull all themes in this project.
$tstmt = $pdo->prepare(
    'SELECT id, name, COALESCE(definition, description, "") AS description
     FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$tstmt->execute([':p' => $projectId]);
$themes = $tstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($themes) < 2) fail('mm_no_themes', 'Need at least 2 themes before clustering.');

$themeLines = [];
foreach ($themes as $i => $t) {
    $themeLines[] = ($i + 1) . '. ' . (string)$t['name']
        . ((string)$t['description'] !== '' ? ' -- ' . (string)$t['description'] : '');
}
$themesBlock = implode("\n", $themeLines);

if ($mode === 'auto') {
    $modeLine = "Mode: AUTO. Discover the right number of broader clusters yourself (typically 3 to 7). Pick clear group names. Every theme must be assigned to exactly one cluster.";
} elseif ($mode === 'by_number') {
    $modeLine = "Mode: BY_NUMBER. Produce exactly " . $targetCount . " clusters. Every theme must be assigned to exactly one cluster.";
} else {
    $modeLine = "Mode: BY_CATEGORY_TYPE. Use these cluster names and these only: " . implode(', ', $labels) . ". Every theme is assigned to exactly one cluster. If a theme does not fit any cluster cleanly, pick the closest one.";
}

$system = <<<SYS
You are a mixed-methods qualitative coder. The user gives you a numbered list of themes already produced by an earlier analysis. Group them into broader CLUSTERS that describe the higher-level categories these themes share.

Rules:
- Cluster names are short noun phrases, 2-5 words, sentence case.
- Every theme number must be assigned to exactly one cluster.
- Provide a one-sentence description of each cluster.
- Do not invent new themes. Use only the numbers you were given.

Output a single JSON object only, no prose, no markdown fences:

{
  "clusters": [
    {
      "name":         "<short>",
      "description":  "<one sentence>",
      "theme_numbers": [<int>, <int>, ...]
    }
  ]
}
SYS;

$prompt  = $modeLine . "\n\n";
$prompt .= "Themes (numbered):\n" . $themesBlock . "\n\n";
$prompt .= "Produce the clusters now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $prompt]], 3000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['clusters']) || !is_array($parsed['clusters'])) {
    fail('ai_parse_failed', 'AI did not return a usable cluster set. Try again.', 502);
}

$themeIdByNumber = [];
foreach ($themes as $i => $t) $themeIdByNumber[$i + 1] = (int)$t['id'];

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM mm_clusters WHERE project_id = :p')->execute([':p' => $projectId]);

    $insertCl = $pdo->prepare(
        'INSERT INTO mm_clusters (project_id, name, description, mode, position)
         VALUES (:p, :n, :d, :m, :pos)'
    );
    $insertMem = $pdo->prepare(
        'INSERT IGNORE INTO mm_cluster_members (cluster_id, theme_id) VALUES (:c, :t)'
    );

    $clustersOut = [];
    $position = 0;
    foreach ($parsed['clusters'] as $cl) {
        if (!is_array($cl)) continue;
        $name = clean_string((string)($cl['name'] ?? ''), 200);
        if ($name === '') continue;
        $desc = clean_string((string)($cl['description'] ?? ''), 600);
        $insertCl->execute([':p' => $projectId, ':n' => $name, ':d' => $desc, ':m' => $mode, ':pos' => $position++]);
        $clusterId = (int)$pdo->lastInsertId();

        $memberIds = [];
        if (isset($cl['theme_numbers']) && is_array($cl['theme_numbers'])) {
            foreach ($cl['theme_numbers'] as $tn) {
                $tn = (int)$tn;
                if (!isset($themeIdByNumber[$tn])) continue;
                $themeId = $themeIdByNumber[$tn];
                if (in_array($themeId, $memberIds, true)) continue;
                $insertMem->execute([':c' => $clusterId, ':t' => $themeId]);
                $memberIds[] = $themeId;
            }
        }

        $clustersOut[] = [
            'id'          => $clusterId,
            'name'        => $name,
            'description' => $desc,
            'mode'        => $mode,
            'theme_count' => count($memberIds),
            'theme_ids'   => $memberIds,
        ];
    }

    if (count($clustersOut) === 0) {
        $pdo->rollBack();
        fail('ai_empty_result', 'AI returned no usable clusters. Try again.', 502);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_cluster_save_failed', 'Could not save clusters: ' . $e->getMessage(), 500);
}

json_out([
    'ok'       => true,
    'mode'     => $mode,
    'count'    => count($clustersOut),
    'clusters' => $clustersOut,
    'model'    => ai_config()['model'],
]);
