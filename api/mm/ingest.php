<?php
// POST /api/mm/ingest.php
// Body: { project_id, source_label, field_name?, numeric_field?, group_field?,
//   rows: [ { text, numeric_value?, group_value?, respondent_ref? } ] }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body         = read_json_body();
$projectId    = (int)($body['project_id'] ?? 0);
$sourceLabel  = clean_string((string)($body['source_label']  ?? 'Paste import'), 200);
$fieldName    = clean_string((string)($body['field_name']    ?? ''), 200);
$numericField = clean_string((string)($body['numeric_field'] ?? ''), 200);
$groupField   = clean_string((string)($body['group_field']   ?? ''), 200);
$rows         = $body['rows'] ?? null;

if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
if (!is_array($rows) || count($rows) === 0) fail('bad_input', 'Provide a non-empty rows array.');

mm_require_project($pdo, $uid, $projectId);

if (count($rows) > 5000) $rows = array_slice($rows, 0, 5000);

$pdo->beginTransaction();
try {
    $sourceStmt = $pdo->prepare(
        'INSERT INTO mm_data_sources
         (project_id, source_type, source_ref, label, field_name, numeric_field, group_field, row_count)
         VALUES (:p, "paste", NULL, :l, :f, :nf, :gf, 0)'
    );
    $sourceStmt->execute([
        ':p'  => $projectId,
        ':l'  => $sourceLabel !== '' ? $sourceLabel : 'Paste import',
        ':f'  => $fieldName    !== '' ? $fieldName    : null,
        ':nf' => $numericField !== '' ? $numericField : null,
        ':gf' => $groupField   !== '' ? $groupField   : null,
    ]);
    $sourceId = (int)$pdo->lastInsertId();

    // A row is worth inserting if it has ANY of: text, numeric value, group
    // value. Previously we required text, which silently dropped every row in
    // a closed-ended-only upload. The Studio's quantitative panels (Reliability,
    // Compare, etc.) operate on numeric and group columns directly, so empty
    // text should not block ingest.
    $inserted = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $text = clean_string((string)($r['text'] ?? ''), 8000);
        $resp = isset($r['respondent_ref']) ? clean_string((string)$r['respondent_ref'], 120) : '';
        $grp  = isset($r['group_value'])    ? clean_string((string)$r['group_value'],    200) : '';
        $qid  = isset($r['question_id_raw'])    ? clean_string((string)$r['question_id_raw'],   120)  : '';
        $qtxt = isset($r['question_text_raw'])  ? clean_string((string)$r['question_text_raw'], 2000) : '';
        $num  = null;
        if (isset($r['numeric_value']) && $r['numeric_value'] !== '' && is_numeric($r['numeric_value'])) {
            $num = (float)$r['numeric_value'];
        }
        // Skip only when ALL fields are empty (a truly blank row).
        if ($text === '' && $num === null && $grp === '') continue;
        mm_insert_text_response(
            $pdo, $projectId, $sourceId,
            $resp !== '' ? $resp : null,
            $grp  !== '' ? $grp  : null,
            $num, $text,
            $qid  !== '' ? $qid  : null,
            $qtxt !== '' ? $qtxt : null
        );
        $inserted++;
    }

    $upd = $pdo->prepare('UPDATE mm_data_sources SET row_count = :rc WHERE id = :id');
    $upd->execute([':rc' => $inserted, ':id' => $sourceId]);

    $pdo->commit();
    json_out(['ok' => true, 'source_id' => $sourceId, 'inserted' => $inserted]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_ingest_failed', 'Could not import rows: ' . $e->getMessage(), 500);
}
