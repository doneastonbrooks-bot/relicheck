<?php
// POST /api/qual/save-to-mm.php   Body: { project_id }
//
// Saves a Qual Studio project's CODED OUTCOME (segments + applied codes +
// themes) into the shared `datasets` table, then creates a Mixed-Methods
// project linked to it. The result lands on the unified projects page and is
// ready to open in MM Studio as the qualitative strand of a joint display —
// no CSV download / re-upload round trip.
//
// One row per segment: Respondent ID, Question, Response, Codes Applied, Themes.
// Mirrors the proven patterns in api/mm/save-to-datasets.php (datasets insert),
// api/mm/projects.php (mm_projects + rc linkage) and api/mm/link-dataset.php
// (variable_metadata seeding).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_qual_studio.php';
require_once __DIR__ . '/../_rc_projects.php';
require_once __DIR__ . '/../_dataset_helpers.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
$proj = qual_require_project($pdo, $uid, $projectId);

// ── Extract the coded outcome (one row per segment) ───────────────────────
$segSt = $pdo->prepare(
    "SELECT s.id, s.participant_id, s.question_ref,
            COALESCE(s.cleaned_text, s.raw_text) AS response_text,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '; ') AS codes_applied,
            GROUP_CONCAT(DISTINCT th.name ORDER BY th.name SEPARATOR '; ')  AS themes
     FROM qual_segments s
     LEFT JOIN qual_code_applications ca
           ON ca.segment_id=s.id AND ca.project_id=s.project_id
           AND ca.coder_type='human' AND ca.action_type='applied'
     LEFT JOIN qual_codes c ON c.id=ca.code_id
     LEFT JOIN qual_theme_quotes tq ON tq.segment_id=s.id AND tq.project_id=s.project_id
     LEFT JOIN qual_themes th ON th.id=tq.theme_id
     WHERE s.project_id=:p
     GROUP BY s.id, s.participant_id, s.question_ref, s.seg_order, response_text
     ORDER BY s.seg_order, s.id"
);
$segSt->execute([':p' => $projectId]);
$rows = $segSt->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) === 0) {
    fail('qual_no_segments', 'No coded segments to save yet. Build segments and code some responses first.');
}

// ── Build column_meta + data in the shape the datasets table expects ──────
$columnMeta = [
    ['name' => 'Respondent ID', 'type' => 'open'],
    ['name' => 'Question',      'type' => 'single'],
    ['name' => 'Response',      'type' => 'open'],
    ['name' => 'Codes Applied', 'type' => 'open'],
    ['name' => 'Themes',        'type' => 'single'],
];
$data = [];
foreach ($rows as $r) {
    $data[] = [
        (string)($r['participant_id'] ?? ''),
        (string)($r['question_ref']   ?? ''),
        (string)($r['response_text']  ?? ''),
        (string)($r['codes_applied']  ?? ''),
        (string)($r['themes']         ?? ''),
    ];
}

// Tier caps (same as the main dataset endpoints).
$current = (int)$pdo->query('SELECT COUNT(*) FROM datasets WHERE owner_id = ' . $uid)->fetchColumn();
require_under_limit($uid, 'max_datasets', $current);
require_under_limit($uid, 'max_rows_per_dataset', 0, count($data));

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($jsonData === false || strlen($jsonData) > 10 * 1024 * 1024) {
    fail('payload_too_large', 'Dataset exceeds the 10 MB size limit.', 413);
}

$baseTitle = clean_string((string)($proj['title'] ?? 'Qual project'), 200);
$dsTitle   = $baseTitle . ' — coded (Qual Studio)';
$notes     = 'Imported from Qual Studio project #' . $projectId . ' (coded segments + themes).';
$settings  = ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'];

// ── Insert the dataset + create the linked MM project (transactional) ──────
$datasetId = 0; $mmId = 0; $rcId = 0;
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO datasets
            (owner_id, title, source_filename, source_format, row_count, column_count, column_meta, settings, data)
         VALUES (:o, :t, :sfn, :sf, :rc, :cc, :cm, :st, :d)'
    )->execute([
        ':o'   => $uid,
        ':t'   => $dsTitle,
        ':sfn' => 'qual_project_' . $projectId,
        ':sf'  => 'qual',
        ':rc'  => count($data),
        ':cc'  => count($columnMeta),
        ':cm'  => json_encode($columnMeta, JSON_UNESCAPED_UNICODE),
        ':st'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ':d'   => $jsonData,
    ]);
    $datasetId = (int)$pdo->lastInsertId();

    // MM project: comments_only pathway (qualitative strand), linked to the dataset.
    $pdo->prepare(
        'INSERT INTO mm_projects (user_id, title, pathway, data_kinds, survey_id, dataset_id, notes)
         VALUES (:uid, :t, :pw, :dk, NULL, :d, :n)'
    )->execute([
        ':uid' => $uid,
        ':t'   => $baseTitle,
        ':pw'  => 'comments_only',
        ':dk'  => json_encode(['open_only'], JSON_UNESCAPED_UNICODE),
        ':d'   => $datasetId,
        ':n'   => $notes,
    ]);
    $mmId = (int)$pdo->lastInsertId();

    // RE Item 3: ecosystem project record + link (mirrors api/mm/projects.php).
    $rcId = rc_create_project($pdo, $uid, $baseTitle, $notes);
    $pdo->prepare('UPDATE mm_projects SET rc_project_id = :r WHERE id = :id')
        ->execute([':r' => $rcId, ':id' => $mmId]);
    rc_set_project_dataset($pdo, $rcId, $datasetId);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save to MM Studio: ' . $e->getMessage(), 500);
}

// Seed variable_metadata so MM's Data Map classifies the columns (non-fatal).
try { rc_seed_var_meta_from_dataset($pdo, $mmId, 'mm', $datasetId, $rcId); } catch (Throwable $e) { /* tolerate */ }

try { qual_audit($pdo, $projectId, $uid, 'saved_to_mm', 'project', $mmId, $dsTitle); } catch (Throwable $e) { /* tolerate */ }

json_out([
    'ok'            => true,
    'mm_project_id' => $mmId,
    'dataset_id'    => $datasetId,
    'rows'          => count($data),
]);
