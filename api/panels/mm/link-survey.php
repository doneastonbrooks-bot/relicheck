<?php
// POST /api/mm/link-survey.php
// Body: {
//   project_id,
//   survey_id,
//   open_qid,                  // question id whose answer holds the open-ended text
//   numeric_qid?,              // optional question id whose answer is a numeric score
//   group_qid?,                // optional question id whose answer is a group label
//   numeric_options?           // optional map: answer-index -> numeric value
// }
//
// Pulls every response from the chosen survey, projects each one into the
// (text, numeric_value, group_value) shape the Studio expects, and inserts
// them via the same path as the paste-box / file-upload flow. The survey
// itself is unchanged.

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
$surveyId     = (int)($body['survey_id']  ?? 0);
$openQid      = clean_string((string)($body['open_qid']    ?? ''), 64);
$numericQid   = clean_string((string)($body['numeric_qid'] ?? ''), 64);
$groupQid     = clean_string((string)($body['group_qid']   ?? ''), 64);
$numericMap   = $body['numeric_options'] ?? null;

if ($projectId <= 0 || $surveyId <= 0 || $openQid === '') {
    fail('bad_input', 'project_id, survey_id, and open_qid are required.');
}
mm_require_project($pdo, $uid, $projectId);

// Verify the user owns the survey before touching its responses.
$own = $pdo->prepare('SELECT owner_id, title FROM surveys WHERE id = :id');
$own->execute([':id' => $surveyId]);
$srow = $own->fetch(PDO::FETCH_ASSOC);
if (!$srow)                                  fail('survey_not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== $uid)         fail('forbidden', 'You do not own that survey.', 403);

// Fetch responses.
$rstmt = $pdo->prepare('SELECT id, answers FROM responses WHERE survey_id = :sid ORDER BY id ASC');
$rstmt->execute([':sid' => $surveyId]);
$rows = $rstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($rows) === 0) fail('mm_no_responses', 'That survey has no responses yet.');

// Helper: pull a value from the answers JSON. Handles both flat string answers
// and option-index answers. For numeric, accept either a raw number or use the
// numeric_options map if provided.
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
        ':p'   => $projectId,
        ':ref' => (string)$surveyId,
        ':lbl' => 'Survey: ' . clean_string((string)$srow['title'], 180),
        ':fn'  => $openQid,
        ':nf'  => $numericQid !== '' ? $numericQid : null,
        ':gf'  => $groupQid   !== '' ? $groupQid   : null,
    ]);
    $sourceId = (int)$pdo->lastInsertId();

    $inserted = 0;
    foreach ($rows as $r) {
        $answers = json_decode((string)$r['answers'], true);
        $text = $pickText($answers);
        if ($text === '') continue;
        if (strlen($text) > 8000) $text = substr($text, 0, 8000);

        $num = $pickNumeric($answers);
        $grp = $pickGroup($answers);
        $ref = 'R' . (int)$r['id'];

        mm_insert_text_response($pdo, $projectId, $sourceId, $ref, $grp, $num, $text);
        $inserted++;
    }

    $pdo->prepare('UPDATE mm_data_sources SET row_count = :rc WHERE id = :id')
        ->execute([':rc' => $inserted, ':id' => $sourceId]);

    if ($inserted === 0) {
        $pdo->rollBack();
        fail('mm_no_open_answers', 'Found responses but none had text for the chosen question.');
    }

    $pdo->commit();
    json_out([
        'ok'        => true,
        'source_id' => $sourceId,
        'inserted'  => $inserted,
        'survey_id' => $surveyId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_link_failed', 'Could not link survey: ' . $e->getMessage(), 500);
}
