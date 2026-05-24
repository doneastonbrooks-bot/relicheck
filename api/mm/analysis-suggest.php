<?php
// GET /api/mm/analysis-suggest.php?project_id=N
//
// Scans the project's generated variables and proposes one (predictor,
// outcome, test) pair for every viable combination. The Studio UI shows
// these on the Analysis tab and the user clicks Run on the ones they want.
//
// A pair is suggested when:
//   - predictor.role = 'predictor' (or 'neutral')
//   - outcome.role   = 'outcome'   (or 'neutral')
//   - both variables exist in the dataset and have enough non-empty values
//   - stats_suggest_test() returns a non-null test name
//
// Pairs are NOT persisted - this is a pure suggestion endpoint. The actual
// run + result is saved by analysis-run.php.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';
require_once __DIR__ . '/../_stats.php';

require_method('GET');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// Latest dataset for this project.
$ds = $pdo->prepare('SELECT id FROM mm_structured_datasets WHERE project_id = :p ORDER BY id DESC LIMIT 1');
$ds->execute([':p' => $projectId]);
$datasetId = (int)($ds->fetchColumn() ?: 0);
if ($datasetId <= 0) fail('mm_no_dataset', 'Build the structured dataset before running analyses.', 404);

// Variables
$vstmt = $pdo->prepare(
    'SELECT id, var_name, display_label, var_type, role
     FROM mm_generated_variables WHERE project_id = :p ORDER BY id ASC'
);
$vstmt->execute([':p' => $projectId]);
$vars = $vstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($vars) < 2) fail('mm_few_vars', 'Need at least 2 variables to suggest analyses.');

// Pull non-empty cell counts and distinct value counts per variable.
$cellStmt = $pdo->prepare(
    'SELECT variable_id, COUNT(*) AS n, COUNT(DISTINCT cell_value) AS d
     FROM mm_dataset_cells
     WHERE dataset_id = :d AND cell_value IS NOT NULL AND cell_value <> ""
     GROUP BY variable_id'
);
$cellStmt->execute([':d' => $datasetId]);
$cellInfo = [];
foreach ($cellStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cellInfo[(int)$row['variable_id']] = ['n' => (int)$row['n'], 'distinct' => (int)$row['d']];
}

// Bucket variables for pairing.
$predictors = [];
$outcomes   = [];
foreach ($vars as $v) {
    $vid = (int)$v['id'];
    $info = $cellInfo[$vid] ?? ['n' => 0, 'distinct' => 0];
    if ($info['n'] < 5) continue; // skip near-empty columns
    $entry = [
        'id'        => $vid,
        'var_name'  => (string)$v['var_name'],
        'label'     => (string)($v['display_label'] ?? $v['var_name']),
        'type'      => (string)$v['var_type'],
        'role'      => (string)$v['role'],
        'n'         => $info['n'],
        'distinct'  => $info['distinct'],
    ];
    $role = (string)$v['role'];
    if ($role === 'predictor' || $role === 'neutral') $predictors[] = $entry;
    if ($role === 'outcome'   || $role === 'neutral') $outcomes[]   = $entry;
}

$suggestions = [];
$skipped     = [];
$seen        = [];
foreach ($predictors as $p) {
    foreach ($outcomes as $o) {
        if ($p['id'] === $o['id']) continue;
        $key = $p['id'] . ':' . $o['id'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $test = stats_suggest_test($p['type'], $o['type'], $p['distinct'], $o['distinct']);
        if ($test === null) continue;

        // Skip pairs where either side has no variance. The stats engine
        // would just bail with a generic message; filtering here lets the UI
        // explain WHY the pair is unavailable.
        $reason = null;
        if ($p['distinct'] < 2) $reason = 'Predictor "' . $p['var_name'] . '" has only one distinct value across responses.';
        elseif ($o['distinct'] < 2) $reason = 'Outcome "' . $o['var_name'] . '" has only one distinct value across responses.';
        elseif (min($p['n'], $o['n']) < 5) $reason = 'Fewer than 5 paired observations available.';

        $row = [
            'predictor_id'        => $p['id'],
            'predictor_name'      => $p['var_name'],
            'predictor_label'     => $p['label'],
            'predictor_type'      => $p['type'],
            'predictor_distinct'  => $p['distinct'],
            'predictor_n'         => $p['n'],
            'outcome_id'          => $o['id'],
            'outcome_name'        => $o['var_name'],
            'outcome_label'       => $o['label'],
            'outcome_type'        => $o['type'],
            'outcome_distinct'    => $o['distinct'],
            'outcome_n'           => $o['n'],
            'test'                => $test,
            'n_estimate'          => min($p['n'], $o['n']),
        ];

        if ($reason === null) {
            $suggestions[] = $row;
        } else {
            $row['skip_reason'] = $reason;
            $skipped[] = $row;
        }
    }
}

// Also surface any results we already have for this dataset so the UI can
// highlight pairs that have already been run.
$existing = [];
$rstmt = $pdo->prepare(
    'SELECT predictor_id, outcome_id, test_name, statistic, p_value, effect_size, effect_label, n_total, summary, id
     FROM mm_analysis_results WHERE dataset_id = :d ORDER BY id DESC'
);
$rstmt->execute([':d' => $datasetId]);
foreach ($rstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $existing[] = [
        'id'           => (int)$r['id'],
        'predictor_id' => (int)$r['predictor_id'],
        'outcome_id'   => (int)$r['outcome_id'],
        'test'         => (string)$r['test_name'],
        'statistic'    => $r['statistic'] !== null ? (float)$r['statistic'] : null,
        'p_value'      => $r['p_value']   !== null ? (float)$r['p_value']   : null,
        'effect_size'  => $r['effect_size'] !== null ? (float)$r['effect_size'] : null,
        'effect_label' => (string)($r['effect_label'] ?? ''),
        'n_total'      => $r['n_total'] !== null ? (int)$r['n_total'] : null,
        'summary'      => (string)($r['summary'] ?? ''),
    ];
}

json_out([
    'ok'          => true,
    'dataset_id'  => $datasetId,
    'suggestions' => $suggestions,
    'skipped'     => $skipped,
    'results'     => $existing,
]);
