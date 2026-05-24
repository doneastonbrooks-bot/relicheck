<?php
// POST /api/mm/link-survey.php
// Body: { project_id, survey_id, open_qid, numeric_qid?, group_qid?, numeric_options? }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body       = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$surveyId   = (int)($body['survey_id']  ?? 0);
$openQid    = clean_string((string)($body['open_qid']    ?? ''), 64);
$numericQid = clean_string((string)($body['numeric_qid'] ?? ''), 64);
$groupQid   = clean_string((string)($body['group_qid']   ?? ''), 64);
$numericMap = $body['numeric_options'] ?? null;

if ($projectId <= 0 || $surveyId <= 0 || $openQid === '') {
    fail('bad_input', 'project_id, survey_id, and open_qid are required.');
}
mm_require_project($pdo, $uid, $projectId);

$own = $pdo->prepare('SELECT owner_id, title FROM surveys WHERE id = :id');
$own->execute([':id' => $surveyId]);
$srow = $own->fetch(PDO::FETCH_ASSOC);
if (!$srow)                          fail('survey_not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== $uid) fail('forbidden', 'You do not own that survey.', 403);

$rstmt = $pdo->prepare('SELECT id, answers FROM responses WHERE survey_id = :sid ORDER BY id ASC');
$rstmt->execute([':sid' => $surveyId]);
$rows = $rstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($rows) === 0) fail('mm_no_responses', 'That survey has no responses yet.');

$pickText = function ($answers) use ($openQid) {
    if (!is_array($answers) || !isset($answers[$openQid])) return '';
    $v = $answers[$openQid];
    if (is_array($v)) $v = implode(' / ', array_map('strval', $v));
    return trim((string)$v);
};
$pickNumeric = function ($answers) use ($numericQid, $numericMap) {
    if ($numericQid === '' || !is_array($answers) || !isset($answers[$numericQid])) return null;
    $v = $answers[$numericQid];
    if (is_array($v)) $v = reset($v);
    if (is_numeric($v)) return (float)$v;
    if (is_array($numericMap) && array_key_exists((string)$v, $numericMap)) {
        $mapped = $numericMap[(string)$v];
        if (is_numeric($mapped)) return (float)$mapped;
    }
    return null;
};
$pickGroup = function ($answers) use ($groupQid) {
    if ($groupQid === '' || !is_array($answers) || !isset($answers[$groupQid])) return null;
    $v = $answers[$groupQid];
    if (is_array($v)) $v = implode(' / ', array_map('strval', $v));
    $v = trim((string)$v);
    return $v !== '' ? $v : null;
};

$pdo->beginTransaction();
try {
    $srcStmt = $pdo->prepare(
        'INSERT INTO mm_data_sources
         (project_id, source_type, source_ref, label, field_name, numeric_field, group_field, row_count)
         VALUES (:p, "survey", :ref, :lbl, :fn, :nf, :gf, 0)'
    );
    $srcStmt->execute([
        ':p' => $projectId, ':ref' => (string)$surveyId,
        ':lbl' => 'Survey: ' . clean_string((string)$srow['title'], 180),
        ':fn' => $openQid, ':nf' => $numericQid !== '' ? $numericQid : null,
        ':gf' => $groupQid !== '' ? $groupQid : null,
    ]);
    $sourceId = (int)$pdo->lastInsertId();

    // A response is worth inserting if it has ANY of: open text, numeric value,
    // group value. Previously we required open text, which silently dropped
    // every respondent who skipped the open question -- producing a project
    // with zero rows even when the source survey had hundreds of responses.
    $inserted = 0;
    $withText = 0;
    $withNumeric = 0;
    $withGroup = 0;
    foreach ($rows as $r) {
        $answers = json_decode((string)$r['answers'], true);
        $text = $pickText($answers);
        $num  = $pickNumeric($answers);
        $grp  = $pickGroup($answers);
        // Keep the row if any of the three mapped fields produced a value.
        if ($text === '' && $num === null && ($grp === null || $grp === '')) continue;
        if (strlen($text) > 8000) $text = substr($text, 0, 8000);
        $ref = 'R' . (int)$r['id'];
        mm_insert_text_response($pdo, $projectId, $sourceId, $ref, $grp, $num, $text);
        $inserted++;
        if ($text !== '')   $withText++;
        if ($num !== null)  $withNumeric++;
        if ($grp !== null && $grp !== '') $withGroup++;
    }

    $pdo->prepare('UPDATE mm_data_sources SET row_count = :rc WHERE id = :id')->execute([':rc' => $inserted, ':id' => $sourceId]);

    // Write the survey link back to the project row so downstream Studio
    // panels (Reliability, Validity, etc.) that read p.survey_id can resolve
    // the linked source. Previously link-survey.php only wrote
    // mm_data_sources and left mm_projects.survey_id null.
    // Clear any stale dataset_id too so downstream panels do not preferentially
    // read from an older linked dataset.
    $pdo->prepare('UPDATE mm_projects SET survey_id = :sid, dataset_id = NULL WHERE id = :pid AND user_id = :uid')
        ->execute([':sid' => (string)$surveyId, ':pid' => $projectId, ':uid' => $uid]);

    if ($inserted === 0) {
        $pdo->rollBack();
        fail('mm_no_mapped_answers',
             'This survey has ' . count($rows) . ' response' . (count($rows) === 1 ? '' : 's') .
             ' but none of them have values for the question(s) you mapped. Pick a different open-ended, numeric, or group question, or check that the survey actually collected answers to the mapped field(s).');
    }
    $pdo->commit();
    json_out([
        'ok'          => true,
        'source_id'   => $sourceId,
        'inserted'    => $inserted,
        'with_text'   => $withText,
        'with_numeric'=> $withNumeric,
        'with_group'  => $withGroup,
        'survey_id'   => $surveyId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_link_failed', 'Could not link survey: ' . $e->getMessage(), 500);
}
