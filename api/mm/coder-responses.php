<?php
// GET /api/mm/coder-responses.php?project_id=N[&limit=2000]
//
// Powers the manual per-response coding workspace. Returns, in ONE fetch:
//   - every text response for the project (id, text, respondent_ref,
//     group_value, numeric_value)
//   - the CURRENT coder's assigned theme codes per response (+ a code count)
//   - the project's theme list (picker options: id, name)
//
// Read-only. All edits go through the existing coder-set-coding.php (set/clear,
// scoped to coder_id = current user), so this never writes. Accepts owner OR an
// accepted second coder (mm_require_project_or_coder), so it also backs the
// dual-coder workflow.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project_or_coder($pdo, $uid, $projectId);

$limit = (int)($_GET['limit'] ?? 2000);
if ($limit < 1) $limit = 1;
if ($limit > 5000) $limit = 5000;

// Themes (picker options).
$catStmt = $pdo->prepare(
    'SELECT id, name FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$catStmt->execute([':p' => $projectId]);
$themes = [];
foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $themes[] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
}

// This coder's codes, keyed by response.
$codeStmt = $pdo->prepare(
    'SELECT response_id, category_id FROM mm_coded_responses
     WHERE project_id = :p AND coder_id = :u'
);
$codeStmt->execute([':p' => $projectId, ':u' => $uid]);
$codesByResp = [];
foreach ($codeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid = (int)$r['response_id'];
    $codesByResp[$rid][] = (int)$r['category_id'];
}

// Responses.
$respStmt = $pdo->prepare(
    'SELECT id, respondent_ref, group_value, numeric_value, text
     FROM mm_text_responses WHERE project_id = :p ORDER BY id ASC LIMIT ' . $limit
);
$respStmt->execute([':p' => $projectId]);
$responses = [];
$codedCount = 0;
foreach ($respStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rid   = (int)$r['id'];
    $codes = $codesByResp[$rid] ?? [];
    if (count($codes) > 0) $codedCount++;
    $responses[] = [
        'id'             => $rid,
        'respondent_ref' => $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : '',
        'group_value'    => $r['group_value']    !== null ? (string)$r['group_value']    : '',
        'numeric_value'  => $r['numeric_value']  !== null ? (string)$r['numeric_value']  : '',
        'text'           => (string)$r['text'],
        'codes'          => $codes,
        'code_count'     => count($codes),
    ];
}

json_out([
    'ok'           => true,
    'themes'       => $themes,
    'responses'    => $responses,
    'total'        => count($responses),
    'coded'        => $codedCount,
    'uncoded'      => count($responses) - $codedCount,
]);
