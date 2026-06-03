<?php
// GET /api/qual/get-segments.php?project_id=N[&document_id=N][&limit=200][&offset=0][&uncoded=1]
// Returns segments with this user's applied codes.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_check_access($pdo, $uid, $projectId);

$docId  = (int)($_GET['document_id'] ?? 0);
$limit  = max(1, min(500, (int)($_GET['limit']  ?? 200)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$uncodedOnly = !empty($_GET['uncoded']);

// Fetch this user's codes keyed by segment_id
$codeStmt = $pdo->prepare(
    'SELECT a.segment_id, a.code_id, c.name AS code_name
     FROM qual_code_applications a
     JOIN qual_codes c ON c.id = a.code_id
     WHERE a.project_id = :p AND a.coder_id = :u'
);
$codeStmt->execute([':p' => $projectId, ':u' => $uid]);
$codesBySegment = [];
foreach ($codeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int)$r['segment_id'];
    $codesBySegment[$sid][] = ['id' => (int)$r['code_id'], 'name' => $r['code_name']];
}

// Build query
$where = ['s.project_id = :p', 's.status = "active"'];
$params = [':p' => $projectId];
if ($docId > 0) { $where[] = 's.document_id = :d'; $params[':d'] = $docId; }
if ($uncodedOnly) {
    $where[] = 's.id NOT IN (SELECT segment_id FROM qual_code_applications WHERE project_id=:p2 AND coder_id=:u)';
    $params[':p2'] = $projectId;
    $params[':u']  = $uid;
}

$sql = 'SELECT s.id,s.document_id,s.participant_id,s.question_ref,s.raw_text,
               s.word_count,s.seg_order,s.metadata_json
        FROM qual_segments s
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.document_id ASC, s.seg_order ASC
        LIMIT ' . $limit . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$segments = [];
$codedCount = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid   = (int)$r['id'];
    $codes = $codesBySegment[$sid] ?? [];
    if ($r['metadata_json'] && is_string($r['metadata_json'])) {
        $r['metadata_json'] = json_decode($r['metadata_json'], true);
    }
    if (count($codes) > 0) $codedCount++;
    $segments[] = array_merge($r, [
        'id'         => $sid,
        'codes'      => $codes,
        'code_count' => count($codes),
    ]);
}

// Total count for pagination
$countSql = 'SELECT COUNT(*) FROM qual_segments s WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

json_out([
    'ok'          => true,
    'segments'    => $segments,
    'coded_count' => $codedCount,
    'total'       => $total,
    'limit'       => $limit,
    'offset'      => $offset,
]);
