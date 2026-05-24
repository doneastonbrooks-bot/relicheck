<?php
// GET   /api/mm/coded-responses.php?project_id=N&category_id=M[&limit=200]
// PATCH /api/mm/coded-responses.php   { project_id, coded_id, intensity?, relevance?, quote_worthy?, sentiment?, confidence? }
// POST  /api/mm/coded-responses.php   { project_id, action: "approve_all"|"mark_final"|"unmark_final", category_id }
//
// Defensive: works whether or not Phase 156 columns (intensity, relevance,
// quote_worthy, is_user_edited on mm_coded_responses; is_final, definition
// on mm_theme_categories) exist on the server's schema. Missing columns
// fall back to safe defaults.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'PATCH', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// One-time schema probe: which optional columns are present?
function mm_col_exists(PDO $pdo, string $tbl, string $col): bool
{
    static $cache = [];
    $key = $tbl . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $r = $pdo->query("SHOW COLUMNS FROM " . $tbl . " LIKE '" . str_replace("'", "''", $col) . "'");
        $cache[$key] = $r && $r->fetch() !== false;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

$hasIntensity   = mm_col_exists($pdo, 'mm_coded_responses', 'intensity');
$hasRelevance   = mm_col_exists($pdo, 'mm_coded_responses', 'relevance');
$hasQuoteWorthy = mm_col_exists($pdo, 'mm_coded_responses', 'quote_worthy');
$hasUserEdited  = mm_col_exists($pdo, 'mm_coded_responses', 'is_user_edited');
$hasIsFinal     = mm_col_exists($pdo, 'mm_theme_categories', 'is_final');
$hasDefinition  = mm_col_exists($pdo, 'mm_theme_categories', 'definition');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function mm_owns_category_strict(PDO $pdo, int $projectId, int $catId): array
{
    $s = $pdo->prepare('SELECT * FROM mm_theme_categories WHERE id = :i AND project_id = :p');
    $s->execute([':i' => $catId, ':p' => $projectId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('mm_category_not_found', 'Category not found in this project.', 404);
    return $r;
}

if ($method === 'GET') {
    $projectId  = (int)($_GET['project_id'] ?? 0);
    $categoryId = (int)($_GET['category_id'] ?? 0);
    $limit      = (int)($_GET['limit'] ?? 200);
    if ($projectId <= 0 || $categoryId <= 0) fail('bad_input', 'project_id and category_id are required.');
    if ($limit < 1)    $limit = 1;
    if ($limit > 1000) $limit = 1000;
    mm_require_project($pdo, $uid, $projectId);
    $cat = mm_owns_category_strict($pdo, $projectId, $categoryId);

    // Build SELECT dynamically so missing columns don't crash the query.
    $crCols = ['cr.id AS coded_id', 'cr.response_id', 'cr.confidence'];
    if ($hasIntensity)   $crCols[] = 'cr.intensity';
    if ($hasRelevance)   $crCols[] = 'cr.relevance';
    if ($hasQuoteWorthy) $crCols[] = 'cr.quote_worthy';
    if ($hasUserEdited)  $crCols[] = 'cr.is_user_edited';
    $orderBy = $hasUserEdited ? 'cr.is_user_edited ASC, cr.id ASC' : 'cr.id ASC';

    // PDO without emulated prepares requires every placeholder to be unique,
    // even when they bind to the same value. Use :p1 and :p2 for the two
    // mm_coded_responses / mm_sentiment_scores project_id binds.
    $sql = 'SELECT ' . implode(', ', $crCols) . ',
                   tr.respondent_ref, tr.text, tr.group_value, tr.numeric_value,
                   ss.sentiment
            FROM mm_coded_responses cr
            INNER JOIN mm_text_responses tr ON tr.id = cr.response_id
            LEFT JOIN mm_sentiment_scores ss ON ss.response_id = cr.response_id AND ss.project_id = :p1
            WHERE cr.project_id = :p2 AND cr.category_id = :c
            ORDER BY ' . $orderBy . '
            LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':p1' => $projectId, ':p2' => $projectId, ':c' => $categoryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        fail('mm_query_failed', 'Could not load coded responses: ' . $e->getMessage(), 500);
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'coded_id'       => (int)$r['coded_id'],
            'response_id'    => (int)$r['response_id'],
            'respondent_ref' => $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : '',
            'text'           => (string)$r['text'],
            'group_value'    => $r['group_value']   !== null ? (string)$r['group_value']   : '',
            'numeric_value'  => $r['numeric_value'] !== null ? (float)$r['numeric_value']  : null,
            'confidence'     => (string)($r['confidence'] ?? 'moderate'),
            'intensity'      => isset($r['intensity'])  && $r['intensity']  !== null ? (string)$r['intensity']  : 'moderate',
            'relevance'      => isset($r['relevance'])  && $r['relevance']  !== null ? (string)$r['relevance']  : 'usable',
            'quote_worthy'   => isset($r['quote_worthy']) ? ((int)$r['quote_worthy'] === 1) : false,
            'is_user_edited' => isset($r['is_user_edited']) ? ((int)$r['is_user_edited'] === 1) : false,
            'sentiment'      => $r['sentiment'] !== null ? (string)$r['sentiment'] : 'neutral',
        ];
    }
    json_out([
        'ok'        => true,
        'category'  => [
            'id'         => (int)$cat['id'],
            'name'       => (string)$cat['name'],
            'is_final'   => $hasIsFinal && isset($cat['is_final']) ? ((int)$cat['is_final'] === 1) : false,
            'definition' => $hasDefinition && isset($cat['definition']) && $cat['definition'] !== null
                            ? (string)$cat['definition']
                            : (string)($cat['description'] ?? ''),
        ],
        'total'        => count($out),
        'capabilities' => [
            'intensity'   => $hasIntensity,
            'relevance'   => $hasRelevance,
            'quote_worthy'=> $hasQuoteWorthy,
            'user_edited' => $hasUserEdited,
            'is_final'    => $hasIsFinal,
            'definition'  => $hasDefinition,
        ],
        'responses' => $out,
    ]);
}

if ($method === 'PATCH') {
    $body      = read_json_body();
    $projectId = (int)($body['project_id'] ?? 0);
    $codedId   = (int)($body['coded_id'] ?? 0);
    if ($projectId <= 0 || $codedId <= 0) fail('bad_input', 'project_id and coded_id are required.');
    mm_require_project($pdo, $uid, $projectId);

    $own = $pdo->prepare('SELECT cr.id, cr.category_id, cr.response_id FROM mm_coded_responses cr WHERE cr.id = :i AND cr.project_id = :p');
    $own->execute([':i' => $codedId, ':p' => $projectId]);
    $crow = $own->fetch(PDO::FETCH_ASSOC);
    if (!$crow) fail('mm_coded_not_found', 'Coded row not found in this project.', 404);

    $fields = [];
    $params = [':id' => $codedId];

    if (array_key_exists('intensity', $body)) {
        if (!$hasIntensity) fail('mm_schema_missing', 'intensity column not available; run the schema migration.', 500);
        $v = strtolower(clean_string((string)$body['intensity'], 16));
        if (!in_array($v, ['low','moderate','high'], true)) fail('bad_input', 'intensity must be low, moderate, or high.');
        $fields[] = 'intensity = :int'; $params[':int'] = $v;
    }
    if (array_key_exists('relevance', $body)) {
        if (!$hasRelevance) fail('mm_schema_missing', 'relevance column not available; run the schema migration.', 500);
        $v = strtolower(clean_string((string)$body['relevance'], 16));
        if (!in_array($v, ['usable','unclear','off_topic'], true)) fail('bad_input', 'relevance must be usable, unclear, or off_topic.');
        $fields[] = 'relevance = :rel'; $params[':rel'] = $v;
    }
    if (array_key_exists('quote_worthy', $body)) {
        if (!$hasQuoteWorthy) fail('mm_schema_missing', 'quote_worthy column not available; run the schema migration.', 500);
        $fields[] = 'quote_worthy = :qw'; $params[':qw'] = !empty($body['quote_worthy']) ? 1 : 0;
    }
    if (array_key_exists('confidence', $body)) {
        $v = strtolower(clean_string((string)$body['confidence'], 16));
        if (!in_array($v, ['high','moderate','low'], true)) fail('bad_input', 'confidence must be high, moderate, or low.');
        $fields[] = 'confidence = :cf'; $params[':cf'] = $v;
    }

    $sentimentChange = null;
    if (array_key_exists('sentiment', $body)) {
        $v = strtolower(clean_string((string)$body['sentiment'], 16));
        if (!in_array($v, ['positive','neutral','negative','mixed'], true)) fail('bad_input', 'sentiment must be positive, neutral, negative, or mixed.');
        $sentimentChange = $v;
    }

    if (count($fields) === 0 && $sentimentChange === null) json_out(['ok' => true, 'changed' => 0]);

    $pdo->beginTransaction();
    try {
        if (count($fields) > 0) {
            if ($hasUserEdited) $fields[] = 'is_user_edited = 1';
            $sql = 'UPDATE mm_coded_responses SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        if ($sentimentChange !== null) {
            $up = $pdo->prepare(
                'INSERT INTO mm_sentiment_scores (project_id, response_id, sentiment, confidence)
                 VALUES (:p, :r, :s, "high")
                 ON DUPLICATE KEY UPDATE sentiment = VALUES(sentiment), confidence = "high"'
            );
            $up->execute([':p' => $projectId, ':r' => (int)$crow['response_id'], ':s' => $sentimentChange]);
        }
        $pdo->prepare('UPDATE mm_theme_categories SET source_mode = "user" WHERE id = :i AND source_mode <> "user"')
            ->execute([':i' => (int)$crow['category_id']]);
        $pdo->commit();
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('mm_coded_patch_failed', 'Could not save edit: ' . $e->getMessage(), 500);
    }
}

$body       = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$action     = clean_string((string)($body['action'] ?? ''), 32);
$categoryId = (int)($body['category_id'] ?? 0);
if ($projectId <= 0 || $categoryId <= 0) fail('bad_input', 'project_id and category_id are required.');
mm_require_project($pdo, $uid, $projectId);
mm_owns_category_strict($pdo, $projectId, $categoryId);

if ($action === 'approve_all') {
    if (!$hasUserEdited) fail('mm_schema_missing', 'is_user_edited column not available; run the schema migration.', 500);
    $stmt = $pdo->prepare('UPDATE mm_coded_responses SET is_user_edited = 1 WHERE project_id = :p AND category_id = :c');
    $stmt->execute([':p' => $projectId, ':c' => $categoryId]);
    $pdo->prepare('UPDATE mm_theme_categories SET source_mode = "user" WHERE id = :i')->execute([':i' => $categoryId]);
    json_out(['ok' => true, 'approved' => $stmt->rowCount()]);
}

if ($action === 'mark_final') {
    if (!$hasIsFinal) fail('mm_schema_missing', 'is_final column not available; run the schema migration.', 500);
    $stmt = $pdo->prepare('UPDATE mm_theme_categories SET is_final = 1, source_mode = "user" WHERE id = :i');
    $stmt->execute([':i' => $categoryId]);
    json_out(['ok' => true]);
}

if ($action === 'unmark_final') {
    if (!$hasIsFinal) fail('mm_schema_missing', 'is_final column not available; run the schema migration.', 500);
    $stmt = $pdo->prepare('UPDATE mm_theme_categories SET is_final = 0 WHERE id = :i');
    $stmt->execute([':i' => $categoryId]);
    json_out(['ok' => true]);
}

fail('bad_input', 'Unknown action.');
