<?php
// POST /api/mm/analysis-run.php
//   Body: { project_id, predictor_id, outcome_id, test }
//   test in: chi_square | t_test | anova | pearson
//
// Pulls the two variables' cell values from the latest dataset,
// runs the requested test, persists the row to mm_analysis_results,
// and returns the result.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';
require_once __DIR__ . '/../_stats.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

check_rate_limit('mm_analysis:user:' . $uid, 120, 3600);

$body        = read_json_body();
$projectId   = (int)($body['project_id']   ?? 0);
$predictorId = (int)($body['predictor_id'] ?? 0);
$outcomeId   = (int)($body['outcome_id']   ?? 0);
$test        = clean_string((string)($body['test'] ?? ''), 24);

if ($projectId <= 0 || $predictorId <= 0 || $outcomeId <= 0 || $test === '') {
    fail('bad_input', 'project_id, predictor_id, outcome_id, and test are required.');
}
if (!in_array($test, ['chi_square', 't_test', 'anova', 'pearson'], true)) {
    fail('bad_input', 'test must be chi_square, t_test, anova, or pearson.');
}
if ($predictorId === $outcomeId) fail('bad_input', 'predictor and outcome must differ.');
mm_require_project($pdo, $uid, $projectId);

// Latest dataset for this project.
$ds = $pdo->prepare('SELECT id FROM mm_structured_datasets WHERE project_id = :p ORDER BY id DESC LIMIT 1');
$ds->execute([':p' => $projectId]);
$datasetId = (int)($ds->fetchColumn() ?: 0);
if ($datasetId <= 0) fail('mm_no_dataset', 'Build the structured dataset first.', 404);

// Confirm both variables belong to this project.
$vstmt = $pdo->prepare(
    'SELECT id, var_name, display_label, var_type FROM mm_generated_variables
     WHERE project_id = :p AND id IN (:a, :b)'
);
$vstmt->execute([':p' => $projectId, ':a' => $predictorId, ':b' => $outcomeId]);
$vrows = $vstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($vrows) !== 2) fail('mm_var_scope_invalid', 'One or both variables are not in this project.', 404);
$vMap = [];
foreach ($vrows as $vr) $vMap[(int)$vr['id']] = $vr;
$pred = $vMap[$predictorId]; $out = $vMap[$outcomeId];

// Pull cells keyed by response_id so we can align the two variables.
$cellStmt = $pdo->prepare(
    'SELECT response_id, variable_id, cell_value
     FROM mm_dataset_cells
     WHERE dataset_id = :d AND variable_id IN (:a, :b)'
);
$cellStmt->execute([':d' => $datasetId, ':a' => $predictorId, ':b' => $outcomeId]);
$byResp = [];
foreach ($cellStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rid = (int)$row['response_id'];
    $byResp[$rid][(int)$row['variable_id']] = (string)$row['cell_value'];
}

$xs = []; $ys = [];
foreach ($byResp as $rid => $pair) {
    if (!array_key_exists($predictorId, $pair) || !array_key_exists($outcomeId, $pair)) continue;
    $xv = $pair[$predictorId]; $yv = $pair[$outcomeId];
    if ($xv === '' || $yv === '') continue;
    $xs[] = $xv; $ys[] = $yv;
}
if (count($xs) < 5) fail('mm_few_pairs', 'Need at least 5 paired non-empty observations (have ' . count($xs) . ').');

// For t-test and ANOVA, pick which side is numeric vs categorical based on
// the variable types so we don't depend on Predictor/Outcome ordering.
$numericTypes = ['numeric', 'ordinal', 'frequency', 'intensity', 'sentiment_num'];
$predIsNumeric = in_array(strtolower((string)$pred['var_type']), $numericTypes, true);

switch ($test) {
    case 'chi_square':
        $res = stats_chi_square($xs, $ys);
        break;
    case 't_test':
        $res = $predIsNumeric ? stats_t_test($xs, $ys) : stats_t_test($ys, $xs);
        break;
    case 'anova':
        $res = $predIsNumeric ? stats_anova($xs, $ys) : stats_anova($ys, $xs);
        break;
    case 'pearson':
        $res = stats_pearson($xs, $ys);
        break;
    default:
        fail('bad_input', 'Unknown test.');
}

if (empty($res['ok'])) {
    fail('mm_analysis_failed', $res['error'] ?? 'Test failed.', 400);
}

// Save to mm_analysis_results. Replace any prior row for the exact same triple
// so the user can re-run without piling up duplicates.
$pdo->prepare(
    'DELETE FROM mm_analysis_results
     WHERE dataset_id = :d AND predictor_id = :p AND outcome_id = :o AND test_name = :t'
)->execute([':d' => $datasetId, ':p' => $predictorId, ':o' => $outcomeId, ':t' => $test]);

$ins = $pdo->prepare(
    'INSERT INTO mm_analysis_results
       (project_id, dataset_id, predictor_id, outcome_id, test_name,
        statistic, df1, df2, p_value, effect_size, effect_label, n_total, summary, details_json)
     VALUES
       (:p, :d, :pr, :ou, :tn, :st, :d1, :d2, :pv, :es, :el, :n, :sm, :dj)'
);
$ins->execute([
    ':p'  => $projectId,
    ':d'  => $datasetId,
    ':pr' => $predictorId,
    ':ou' => $outcomeId,
    ':tn' => $test,
    ':st' => $res['statistic']   ?? null,
    ':d1' => $res['df1']         ?? null,
    ':d2' => $res['df2']         ?? null,
    ':pv' => $res['p_value']     ?? null,
    ':es' => $res['effect_size'] ?? null,
    ':el' => $res['effect_label']?? null,
    ':n'  => $res['n_total']     ?? null,
    ':sm' => isset($res['summary']) ? substr((string)$res['summary'], 0, 600) : null,
    ':dj' => isset($res['details']) ? json_encode($res['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
]);
$resultId = (int)$pdo->lastInsertId();

json_out([
    'ok' => true,
    'result' => [
        'id'             => $resultId,
        'dataset_id'     => $datasetId,
        'predictor_id'   => $predictorId,
        'predictor_name' => (string)($pred['var_name'] ?? ''),
        'outcome_id'     => $outcomeId,
        'outcome_name'   => (string)($out['var_name']  ?? ''),
        'test'           => $test,
        'statistic'      => $res['statistic']   ?? null,
        'df1'            => $res['df1']         ?? null,
        'df2'            => $res['df2']         ?? null,
        'p_value'        => $res['p_value']     ?? null,
        'effect_size'    => $res['effect_size'] ?? null,
        'effect_label'   => $res['effect_label']?? null,
        'n_total'        => $res['n_total']     ?? null,
        'summary'        => $res['summary']     ?? '',
    ],
]);
