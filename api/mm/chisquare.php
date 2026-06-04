<?php
// POST /api/mm/chisquare.php
// Chi-square test of independence for a project's linked dataset.
// Body: {project_id, row_id, col_id}

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';
require_once __DIR__ . '/../_stats.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$rowIdx    = (int)($body['row_id']     ?? -1);
$colIdx    = (int)($body['col_id']     ?? -1);
if ($projectId <= 0 || $rowIdx < 0 || $colIdx < 0) fail('bad_input', 'Missing required parameters.');
if ($rowIdx === $colIdx) fail('bad_input', 'Row and column variables must differ.');
mm_require_project($pdo, $uid, $projectId);

// ── Load dataset ──────────────────────────────────────────────────────────
$dq = $pdo->prepare('SELECT dataset_id FROM mm_projects WHERE id = :p AND user_id = :u');
$dq->execute([':p' => $projectId, ':u' => $uid]);
$datasetId = (int)($dq->fetchColumn() ?: 0);
if ($datasetId <= 0) fail('no_dataset', 'No dataset linked.', 404);
$drq = $pdo->prepare('SELECT column_meta, data FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
$drq->execute([':d' => $datasetId, ':u' => $uid]);
$drow = $drq->fetch(PDO::FETCH_ASSOC);
if (!$drow) fail('no_dataset', 'Dataset not found.', 404);
$cm   = json_decode((string)$drow['column_meta'], true) ?: [];
$data = json_decode((string)$drow['data'], true) ?: [];
if (!$cm || !$data) fail('empty_dataset', 'Dataset is empty.');

$rowName = (string)($cm[$rowIdx]['name'] ?? ('col_' . $rowIdx));
$colName = (string)($cm[$colIdx]['name'] ?? ('col_' . $colIdx));

// ── Build value arrays ────────────────────────────────────────────────────
$rowVals = []; $colVals = [];
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $rv = trim((string)($row[$rowIdx] ?? ''));
    $cv = trim((string)($row[$colIdx] ?? ''));
    if ($rv === '' || $cv === '') continue;
    $rowVals[] = $rv; $colVals[] = $cv;
}

$r = stats_chi_square($rowVals, $colVals);
if (!($r['ok'] ?? false)) fail('stats_error', $r['error'] ?? 'Could not run chi-square test.');

$chi2   = (float)$r['statistic'];
$df     = (int)$r['df1'];
$p      = (float)$r['p_value'];
$cramV  = $r['effect_size'];
$pStr   = stats_format_p($p);
$sig    = $p < 0.05;
$N      = (int)$r['n_total'];

// ── Build contingency matrix ──────────────────────────────────────────────
$rowsMap = (array)($r['details']['rows'] ?? []);
$colsMap = (array)($r['details']['cols'] ?? []);
$cellMap = (array)($r['details']['contingency'] ?? []);

arsort($rowsMap); arsort($colsMap);
$colLabels  = array_keys($colsMap);
$colTotals  = array_fill(0, count($colLabels), 0);
$matrix     = [];

foreach ($rowsMap as $rVal => $rTotal) {
    $cells = [];
    foreach ($colLabels as $j => $cVal) {
        $cnt = (int)($cellMap[$rVal][$cVal] ?? 0);
        $colTotals[$j] += $cnt;
        $cells[] = ['count' => $cnt, 'row_pct' => $rTotal > 0 ? round(100.0 * $cnt / $rTotal, 2) : 0.0];
    }
    $matrix[] = ['label' => (string)$rVal, 'total' => (int)$rTotal, 'cells' => $cells];
}

// Cramer's V interpretation (Cohen 1988 thresholds adjusted for table size)
$k = min(count($rowsMap), count($colsMap));
$cv = $cramV ?? 0.0;
$thresholds = [1 => [0.10, 0.30, 0.50], 2 => [0.07, 0.21, 0.35], 3 => [0.06, 0.17, 0.29]];
$thr = $thresholds[min($k - 1, 3)] ?? $thresholds[3];
if ($cv >= $thr[2])      { $vInterp = 'Large';      $vMeaning = 'A strong association between the two variables.'; }
elseif ($cv >= $thr[1])  { $vInterp = 'Medium';     $vMeaning = 'A moderate association between the two variables.'; }
elseif ($cv >= $thr[0])  { $vInterp = 'Small';      $vMeaning = 'A small but potentially meaningful association.'; }
else                      { $vInterp = 'Negligible'; $vMeaning = 'Little to no association between the two variables.'; }

$plain = sprintf('%s and %s %s significantly related (χ²(%d)=%.2f, p=%s, Cramer V=%.2f, N=%d).',
    $rowName, $colName, $sig ? 'were' : 'were not', $df, $chi2, $pStr, $cv, $N);
$researcher = sprintf('Chi-square test of independence: χ²(%d, N=%d)=%.2f, p=%s, Cramer V=%.3f.',
    $df, $N, $chi2, $pStr, $cv);

json_out([
    'ok'    => true,
    'row'   => ['name' => $rowName],
    'col'   => ['name' => $colName],
    'table' => ['row_var' => $rowName, 'col_var' => $colName,
                'col_labels' => $colLabels, 'matrix' => $matrix,
                'col_totals' => $colTotals, 'grand' => $N],
    'result' => ['test_used' => 'Chi-square', 'chi2' => round($chi2, 4), 'df' => $df,
                 'p' => $p, 'p_str' => $pStr, 'n_total' => $N, 'cramers_v' => $cramV !== null ? round($cramV, 4) : null,
                 'significant' => $sig],
    'effect' => ['type' => "Cramer's V", 'value' => $cramV !== null ? round($cramV, 4) : null,
                 'interpretation' => $vInterp, 'meaning' => $vMeaning],
    'reporting' => ['plain' => $plain, 'researcher' => $researcher,
                    'next' => 'Stage significant associations in the Identify Results to Explain step for qualitative follow-up.',
                    'caution' => 'Chi-square is sensitive to cell counts — check that no expected cell is below 5. A significant result does not explain the direction or cause of the relationship.'],
    'follow_up_question' => "What experiences or processes connect $rowName and $colName?",
]);
