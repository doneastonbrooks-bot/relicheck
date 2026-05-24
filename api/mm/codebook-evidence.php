<?php
// /api/mm/codebook-evidence.php
//
// GET ?project_id=N&category_id=K
//   Optional filters:
//     &sentiment=positive|neutral|negative|mixed
//     &confidence=high|moderate|low
//     &min_intensity=0..100        (skipped if there is no intensity column)
//     &group=value                  (matches text_responses.group_value)
//     &outcome_min=number           (matches text_responses.numeric_value)
//     &outcome_max=number
//
// Returns the actual responses coded to the selected theme so the codebook
// workspace's evidence drawer can render them with respondent_ref, original
// text, sentiment, intensity / confidence proxies, and linked closed-ended
// values when those columns are present.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$projectId  = (int)($_GET['project_id']  ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);
if ($projectId <= 0 || $categoryId <= 0) {
    fail('bad_input', 'Missing project_id or category_id.', 400);
}
mm_require_project($pdo, $uid, $projectId);

// Optional filters.
$fSent = isset($_GET['sentiment'])  ? (string)$_GET['sentiment']  : '';
$fConf = isset($_GET['confidence']) ? (string)$_GET['confidence'] : '';
$fGrp  = isset($_GET['group'])      ? trim((string)$_GET['group']) : '';
$fOMin = $_GET['outcome_min'] ?? null;
$fOMax = $_GET['outcome_max'] ?? null;

// Detect optional columns once so the query stays portable.
function _cb_has_col(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $s->execute([':t' => $table, ':c' => $col]);
    return ((int)$s->fetchColumn()) > 0;
}
$hasNumeric = _cb_has_col($pdo, 'mm_text_responses', 'numeric_value');
$hasGroup   = _cb_has_col($pdo, 'mm_text_responses', 'group_value');

// Confirm the category belongs to the project.
$cat = $pdo->prepare('SELECT id, name FROM mm_theme_categories WHERE id = :i AND project_id = :p LIMIT 1');
$cat->execute([':i' => $categoryId, ':p' => $projectId]);
$catRow = $cat->fetch(PDO::FETCH_ASSOC);
if (!$catRow) fail('not_found', 'Category not found in this project.', 404);

// Build the SELECT.
$select = [
    'r.id',
    'r.respondent_ref',
    'r.text',
    'cr.confidence AS coding_confidence',
    'cr.is_user_edited',
    '(SELECT sentiment  FROM mm_sentiment_scores s WHERE s.response_id = r.id LIMIT 1) AS sentiment',
    '(SELECT confidence FROM mm_sentiment_scores s WHERE s.response_id = r.id LIMIT 1) AS sentiment_confidence',
];
if ($hasNumeric) $select[] = 'r.numeric_value';
if ($hasGroup)   $select[] = 'r.group_value';

$where = ['cr.project_id = :p', 'cr.category_id = :c'];
$args  = [':p' => $projectId, ':c' => $categoryId];

if ($fConf !== '' && in_array($fConf, ['high','moderate','low'], true)) {
    $where[] = 'cr.confidence = :cf';
    $args[':cf'] = $fConf;
}
if ($fSent !== '' && in_array($fSent, ['positive','neutral','negative','mixed'], true)) {
    $where[] = 'EXISTS (SELECT 1 FROM mm_sentiment_scores s WHERE s.response_id = r.id AND s.sentiment = :sf)';
    $args[':sf'] = $fSent;
}
if ($hasGroup && $fGrp !== '') {
    $where[] = 'r.group_value = :gv';
    $args[':gv'] = $fGrp;
}
if ($hasNumeric && $fOMin !== null && $fOMin !== '') {
    $where[] = 'r.numeric_value >= :omin';
    $args[':omin'] = (float)$fOMin;
}
if ($hasNumeric && $fOMax !== null && $fOMax !== '') {
    $where[] = 'r.numeric_value <= :omax';
    $args[':omax'] = (float)$fOMax;
}

$sql = 'SELECT ' . implode(', ', $select) .
       ' FROM mm_coded_responses cr' .
       ' JOIN mm_text_responses  r ON r.id = cr.response_id' .
       ' WHERE ' . implode(' AND ', $where) .
       ' ORDER BY cr.confidence DESC, r.id ASC' .
       ' LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);

$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        'response_id'           => (int)$r['id'],
        'respondent_ref'        => (string)($r['respondent_ref'] ?? ('R' . $r['id'])),
        'text'                  => (string)$r['text'],
        'sentiment'             => (string)($r['sentiment'] ?? ''),
        'sentiment_confidence'  => (string)($r['sentiment_confidence'] ?? ''),
        'coding_confidence'     => (string)($r['coding_confidence'] ?? 'moderate'),
        'is_user_edited'        => (int)($r['is_user_edited'] ?? 0) === 1,
        'numeric_value'         => $hasNumeric && $r['numeric_value'] !== null ? (float)$r['numeric_value'] : null,
        'group_value'           => $hasGroup ? (string)($r['group_value'] ?? '') : '',
    ];
}

// Available groups for the filter dropdown.
$groups = [];
if ($hasGroup) {
    $g = $pdo->prepare(
        'SELECT DISTINCT r.group_value
           FROM mm_coded_responses cr
           JOIN mm_text_responses r ON r.id = cr.response_id
          WHERE cr.project_id = :p AND cr.category_id = :c AND r.group_value IS NOT NULL AND r.group_value <> \'\''
    );
    $g->execute([':p' => $projectId, ':c' => $categoryId]);
    $groups = array_values(array_filter($g->fetchAll(PDO::FETCH_COLUMN)));
}

json_out([
    'ok'           => true,
    'category_id'  => $categoryId,
    'category'     => (string)$catRow['name'],
    'rows'         => $rows,
    'available'    => [
        'sentiments'  => ['positive','neutral','negative','mixed'],
        'confidences' => ['high','moderate','low'],
        'groups'      => $groups,
        'has_numeric' => $hasNumeric,
        'has_group'   => $hasGroup,
    ],
]);
