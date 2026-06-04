<?php
// POST /api/mm/regression.php
// Multiple OLS regression for a project's linked dataset.
// Body: {project_id, outcome_id, predictor_ids: [int, ...]}

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

$projectId    = (int)($body['project_id']  ?? 0);
$outcomeIdx   = (int)($body['outcome_id']  ?? -1);
$predRaw      = $body['predictor_ids']     ?? [];
if ($projectId <= 0 || $outcomeIdx < 0 || !is_array($predRaw) || count($predRaw) === 0)
    fail('bad_input', 'Missing required parameters.');
$predIdxs = array_values(array_unique(array_map('intval', $predRaw)));
$predIdxs = array_filter($predIdxs, fn($i) => $i >= 0 && $i !== $outcomeIdx);
$predIdxs = array_values($predIdxs);
if (count($predIdxs) === 0) fail('bad_input', 'At least one predictor (other than the outcome) is required.');
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

$outName  = (string)($cm[$outcomeIdx]['name'] ?? ('col_' . $outcomeIdx));
$predNames = array_map(fn($i) => (string)($cm[$i]['name'] ?? ('col_' . $i)), $predIdxs);
$kPred = count($predIdxs);

// ── Collect complete cases ────────────────────────────────────────────────
$yArr = []; $xMat = []; // x rows are [1, pred0, pred1, ...]
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $yv = trim((string)($row[$outcomeIdx] ?? ''));
    if ($yv === '' || !is_numeric($yv)) continue;
    $xRow = [1.0]; // intercept
    $ok = true;
    foreach ($predIdxs as $pi) {
        $xv = trim((string)($row[$pi] ?? ''));
        if ($xv === '' || !is_numeric($xv)) { $ok = false; break; }
        $xRow[] = (float)$xv;
    }
    if (!$ok) continue;
    $yArr[] = (float)$yv; $xMat[] = $xRow;
}
$N = count($yArr);
if ($N < $kPred + 2) fail('insufficient_data', "Need at least " . ($kPred + 2) . " complete observations for this model (found $N).");

// ── Normal equations: β = (X'X)⁻¹ X'y ───────────────────────────────────
$k = $kPred + 1; // number of params including intercept

function mm_reg_matmul(array $A, array $B): array {
    $rA = count($A); $cA = count($A[0]); $cB = count($B[0]);
    $C = array_fill(0, $rA, array_fill(0, $cB, 0.0));
    for ($i = 0; $i < $rA; $i++)
        for ($l = 0; $l < $cA; $l++) if ($A[$i][$l] != 0)
            for ($j = 0; $j < $cB; $j++) $C[$i][$j] += $A[$i][$l] * $B[$l][$j];
    return $C;
}

function mm_reg_transpose(array $A): array {
    $rA = count($A); $cA = count($A[0]);
    $T = array_fill(0, $cA, array_fill(0, $rA, 0.0));
    for ($i = 0; $i < $rA; $i++) for ($j = 0; $j < $cA; $j++) $T[$j][$i] = $A[$i][$j];
    return $T;
}

// Gaussian elimination with partial pivoting; returns null if singular
function mm_reg_solve(array $A, array $b): ?array {
    $n = count($A);
    // Augment [A|b]
    for ($i = 0; $i < $n; $i++) $A[$i][] = $b[$i];
    for ($col = 0; $col < $n; $col++) {
        // Find pivot
        $maxRow = $col; $maxVal = abs($A[$col][$col]);
        for ($row = $col + 1; $row < $n; $row++) {
            if (abs($A[$row][$col]) > $maxVal) { $maxVal = abs($A[$row][$col]); $maxRow = $row; }
        }
        if ($maxVal < 1e-12) return null; // singular
        [$A[$col], $A[$maxRow]] = [$A[$maxRow], $A[$col]];
        $piv = $A[$col][$col];
        for ($j = $col; $j <= $n; $j++) $A[$col][$j] /= $piv;
        for ($row = 0; $row < $n; $row++) {
            if ($row === $col) continue;
            $f = $A[$row][$col];
            for ($j = $col; $j <= $n; $j++) $A[$row][$j] -= $f * $A[$col][$j];
        }
    }
    return array_column($A, $n);
}

// Build X'X and X'y
$Xt = mm_reg_transpose($xMat);
$XtX = mm_reg_matmul($Xt, $xMat);
$Xty_m = mm_reg_matmul($Xt, array_map(fn($y) => [$y], $yArr));
$Xty = array_column($Xty_m, 0);

$beta = mm_reg_solve($XtX, $Xty);
if ($beta === null) fail('stats_error', 'Design matrix is singular — predictors may be perfectly collinear.');

// ── Compute residuals and model stats ─────────────────────────────────────
$yMean = array_sum($yArr) / $N;
$rss = 0.0; $tss = 0.0;
$resids = [];
foreach ($xMat as $i => $xRow) {
    $yhat = 0.0; foreach ($xRow as $j => $xv) $yhat += $beta[$j] * $xv;
    $e = $yArr[$i] - $yhat;
    $rss += $e * $e; $tss += ($yArr[$i] - $yMean) ** 2;
    $resids[] = $e;
}
$r2    = $tss > 0 ? 1.0 - $rss / $tss : 0.0;
$df1   = $kPred;             // regression df
$df2   = $N - $k;            // residual df
$adjR2 = 1.0 - (1.0 - $r2) * ($N - 1) / max($df2, 1);
$mse   = $df2 > 0 ? $rss / $df2 : 0.0;
$msr   = $kPred > 0 ? ($r2 * $tss) / $kPred : 0.0;
$Fstat = $mse > 0 ? $msr / $mse : 0.0;
$pF    = stats_f_pvalue($Fstat, (float)$df1, (float)$df2);
$pFStr = stats_format_p($pF);
$sigModel = $pF < 0.05;

// ── Coefficient standard errors via (X'X)⁻¹ * MSE ────────────────────────
// Invert XtX to get hat matrix diagonal
function mm_reg_invert(array $A): ?array {
    $n = count($A);
    // Augment with identity
    for ($i = 0; $i < $n; $i++) {
        $eye = array_fill(0, $n, 0.0); $eye[$i] = 1.0;
        $A[$i] = array_merge($A[$i], $eye);
    }
    for ($col = 0; $col < $n; $col++) {
        $maxRow = $col; $maxVal = abs($A[$col][$col]);
        for ($row = $col + 1; $row < $n; $row++)
            if (abs($A[$row][$col]) > $maxVal) { $maxVal = abs($A[$row][$col]); $maxRow = $row; }
        if ($maxVal < 1e-12) return null;
        [$A[$col], $A[$maxRow]] = [$A[$maxRow], $A[$col]];
        $piv = $A[$col][$col];
        for ($j = 0; $j < 2 * $n; $j++) $A[$col][$j] /= $piv;
        for ($row = 0; $row < $n; $row++) {
            if ($row === $col) continue;
            $f = $A[$row][$col];
            for ($j = 0; $j < 2 * $n; $j++) $A[$row][$j] -= $f * $A[$col][$j];
        }
    }
    return array_map(fn($row) => array_slice($row, $n), $A);
}

$XtXinv = mm_reg_invert($XtX);
$coefficients = [];
for ($j = 0; $j < $k; $j++) {
    $isIntercept = ($j === 0);
    $se = $XtXinv !== null ? sqrt(max(0.0, $XtXinv[$j][$j] * $mse)) : 0.0;
    $tStat = $se > 0 ? $beta[$j] / $se : 0.0;
    $pCoef = $df2 > 0 ? stats_t_pvalue($tStat, (float)$df2) : 1.0;
    $pCoefStr = stats_format_p($pCoef);
    $coefficients[] = [
        'term'         => $isIntercept ? '(Intercept)' : $predNames[$j - 1],
        'b'            => round($beta[$j], 6),
        'se'           => round($se, 6),
        't'            => round($tStat, 4),
        'p'            => $pCoef,
        'p_str'        => $pCoefStr,
        'sig'          => $pCoef < 0.05,
        'is_intercept' => $isIntercept,
    ];
}

$nSigPreds = count(array_filter($coefficients, fn($c) => !$c['is_intercept'] && $c['sig']));
$sigPredNames = implode(', ', array_map(fn($c) => $c['term'], array_filter($coefficients, fn($c) => !$c['is_intercept'] && $c['sig'])));
$plain = sprintf('A linear regression model with %d predictor%s explained %.1f%% of the variance in %s (R²=%.3f, F(%d,%d)=%.2f, p=%s, N=%d). %s',
    $kPred, $kPred > 1 ? 's' : '', round($r2 * 100, 1), $outName,
    $r2, $df1, $df2, $Fstat, $pFStr, $N,
    $nSigPreds > 0 ? "Significant predictor(s): $sigPredNames." : 'No individual predictors were statistically significant.');
$researcher = sprintf('Multiple OLS regression: R²=%.3f, adjusted R²=%.3f, F(%d,%d)=%.2f, p=%s, N=%d.',
    $r2, $adjR2, $df1, $df2, $Fstat, $pFStr, $N);

json_out([
    'ok'           => true,
    'outcome'      => ['name' => $outName],
    'predictors'   => array_map(fn($n) => ['name' => $n], $predNames),
    'coefficients' => $coefficients,
    'result'       => ['r2' => round($r2, 4), 'adj_r2' => round($adjR2, 4),
                       'F' => round($Fstat, 4), 'df1' => $df1, 'df2' => $df2,
                       'p' => $pF, 'p_str' => $pFStr, 'n_total' => $N, 'significant' => $sigModel],
    'reporting'    => ['plain' => $plain, 'researcher' => $researcher,
                       'next' => 'Stage this model in the Identify Results to Explain step to explore the significant predictors qualitatively.',
                       'caution' => 'Regression assumes linearity and no perfect multicollinearity. With many predictors, individual coefficients can be suppressed. Report adjusted R² and inspect individual betas cautiously.'],
    'follow_up_question' => "What qualitative factors help explain how these predictors relate to $outName?",
]);
