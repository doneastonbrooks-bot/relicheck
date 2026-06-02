<?php
// POST /api/analysis/infer.php
// Runs a statistical test on data sent from the Inferential Studio.
// Returns MM-Studio-compatible response shapes (validated computations).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_stats.php';

require_method('POST');
check_origin();
require_auth();

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || !isset($body['tool'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing tool parameter.']);
    exit;
}

$tool = (string)($body['tool'] ?? '');

// ============================================================
// Shared interpretation helpers
// ============================================================
function eff_label_d(float $d): string {
    $a = abs($d);
    if ($a >= 0.8) return 'large';
    if ($a >= 0.5) return 'medium';
    if ($a >= 0.2) return 'small';
    return 'negligible';
}
function eff_label_eta(float $e): string {
    if ($e >= 0.14) return 'large';
    if ($e >= 0.06) return 'medium';
    if ($e >= 0.01) return 'small';
    return 'negligible';
}
function eff_label_v(float $v): string {
    if ($v >= 0.5) return 'large';
    if ($v >= 0.3) return 'medium';
    if ($v >= 0.1) return 'small';
    return 'negligible';
}
function eff_meaning_d(string $lbl): string {
    return match($lbl) {
        'large'  => 'The groups differ substantially — the effect would be noticeable in practice.',
        'medium' => 'The groups differ noticeably — the effect is practically meaningful.',
        'small'  => 'The groups differ, but the effect is small and may be difficult to detect in practice.',
        default  => 'The groups differ very little — the effect has minimal practical importance.',
    };
}
function eff_meaning_eta(string $lbl): string {
    return match($lbl) {
        'large'  => 'Group membership explains a large proportion of variance in the outcome.',
        'medium' => 'Group membership explains a moderate proportion of variance in the outcome.',
        'small'  => 'Group membership explains a small proportion of variance in the outcome.',
        default  => 'Group membership explains very little variance in the outcome.',
    };
}
function eff_meaning_v(string $lbl): string {
    return match($lbl) {
        'large'  => 'There is a strong association between the two variables.',
        'medium' => 'There is a moderate association between the two variables.',
        'small'  => 'There is a weak association between the two variables.',
        default  => 'The association between the two variables is negligible.',
    };
}
function t_crit(float $df): float {
    // Approximate t critical for alpha=0.025 (95% two-tailed CI)
    static $lk = [
        1=>12.706,2=>4.303,3=>3.182,4=>2.776,5=>2.571,6=>2.447,7=>2.365,
        8=>2.306,9=>2.262,10=>2.228,12=>2.179,15=>2.131,20=>2.086,
        25=>2.060,30=>2.042,40=>2.021,60=>2.000,120=>1.980,
    ];
    if ($df >= 120) return 1.960;
    $dfr = (int)round($df);
    if (isset($lk[$dfr])) return $lk[$dfr];
    $keys = array_keys($lk); sort($keys);
    $lo = 1; $hi = 120;
    foreach ($keys as $k) {
        if ($k <= $dfr) $lo = $k;
        if ($k >= $dfr) { $hi = $k; break; }
    }
    if ($lo === $hi) return $lk[$lo];
    $frac = ($dfr - $lo) / ($hi - $lo);
    return $lk[$lo] + $frac * ($lk[$hi] - $lk[$lo]);
}
function arr_stats(array $vals): array {
    $n = count($vals);
    if ($n === 0) return ['n'=>0,'mean'=>null,'sd'=>null,'se'=>null,'min'=>null,'max'=>null];
    $m = array_sum($vals) / $n;
    $v = 0.0;
    foreach ($vals as $x) $v += ($x-$m)*($x-$m);
    $sd = $n > 1 ? sqrt($v/($n-1)) : 0.0;
    return ['n'=>$n,'mean'=>round($m,3),'sd'=>round($sd,3),'se'=>round($n>0?$sd/sqrt($n):0,3),
            'min'=>round(min($vals),3),'max'=>round(max($vals),3)];
}

// ============================================================
// T-TEST
// ============================================================
if ($tool === 't_test') {
    $values   = array_map('floatval', (array)($body['values'] ?? []));
    $groups   = array_map('strval',  (array)($body['groups'] ?? []));
    $group1   = (string)($body['group1'] ?? '');
    $group2   = (string)($body['group2'] ?? '');
    $outName  = (string)($body['outcome_name'] ?? 'Outcome');
    $grpName  = (string)($body['group_name']   ?? 'Group');
    $conf     = (float)($body['confidence']    ?? 0.95);

    // Filter to the two chosen groups
    if ($group1 !== '' && $group2 !== '') {
        $fv = []; $fg = [];
        $n = min(count($values), count($groups));
        for ($i = 0; $i < $n; $i++) {
            if ($groups[$i] === $group1 || $groups[$i] === $group2) {
                $fv[] = $values[$i]; $fg[] = $groups[$i];
            }
        }
        $values = $fv; $groups = $fg;
    }

    $res = stats_t_test($values, $groups);
    if (!$res['ok']) { echo json_encode($res); exit; }

    $t = $res['statistic']; $df = $res['df1']; $p = $res['p_value']; $pFmt = stats_format_p($p);
    $det = $res['details']['groups']; $keys = array_keys($det);
    $g1k = $keys[0]; $g2k = $keys[1];
    $g1  = $det[$g1k]; $g2  = $det[$g2k];

    // Split raw values per group for full descriptives
    $g1vals = []; $g2vals = [];
    $tot = min(count($values), count($groups));
    for ($i = 0; $i < $tot; $i++) {
        if ($groups[$i] === $g1k) $g1vals[] = $values[$i];
        elseif ($groups[$i] === $g2k) $g2vals[] = $values[$i];
    }
    $s1 = arr_stats($g1vals); $s2 = arr_stats($g2vals);

    // Confidence interval
    $alpha = 1.0 - $conf;
    $tc = t_crit($df);  // uses 0.025 table; adjust alpha factor for other confidences
    if (abs($conf - 0.90) < 0.001) {
        static $lk90 = [1=>6.314,2=>2.920,3=>2.353,4=>2.132,5=>2.015,8=>1.860,10=>1.812,15=>1.753,20=>1.725,30=>1.697,60=>1.671,120=>1.658];
        $dfr = (int)round($df);
        $keys90 = array_keys($lk90); sort($keys90);
        $lo90 = 1; $hi90 = 120;
        foreach ($keys90 as $k) {
            if ($k <= $dfr) $lo90 = $k;
            if ($k >= $dfr) { $hi90 = $k; break; }
        }
        $tc = $lo90===$hi90 ? $lk90[$lo90] : $lk90[$lo90] + (($dfr-$lo90)/($hi90-$lo90))*($lk90[$hi90]-$lk90[$lo90]);
    } elseif (abs($conf - 0.99) < 0.001) {
        static $lk99 = [1=>63.657,2=>9.925,3=>5.841,4=>4.604,5=>4.032,8=>3.355,10=>3.169,15=>2.947,20=>2.845,30=>2.750,60=>2.660,120=>2.617];
        $dfr = (int)round($df);
        $keys99 = array_keys($lk99); sort($keys99);
        $lo99 = 1; $hi99 = 120;
        foreach ($keys99 as $k) {
            if ($k <= $dfr) $lo99 = $k;
            if ($k >= $dfr) { $hi99 = $k; break; }
        }
        $tc = $lo99===$hi99 ? $lk99[$lo99] : $lk99[$lo99] + (($dfr-$lo99)/($hi99-$lo99))*($lk99[$hi99]-$lk99[$lo99]);
    }
    $se_diff = sqrt($g1['var'] / $g1['n'] + $g2['var'] / $g2['n']);
    $diff    = $g1['mean'] - $g2['mean'];
    $ci_lo   = round($diff - $tc * $se_diff, 3);
    $ci_hi   = round($diff + $tc * $se_diff, 3);
    $pct     = (int)round($conf * 100);

    $d     = $res['effect_size'] ?? 0.0;
    $dlbl  = eff_label_d($d);
    $dir   = $diff > 0 ? "$g1k scored higher" : "$g2k scored higher";
    $plain = sprintf('%s (M=%.2f, SD=%.2f) %s %s (M=%.2f, SD=%.2f) on %s. The difference was %s (t(%.1f)=%.2f, p=%s, d=%.2f).',
        $g1k, $g1['mean'], sqrt($g1['var']), ($diff>0?'scored higher than':'scored lower than'),
        $g2k, $g2['mean'], sqrt($g2['var']), $outName, ($p<0.05?'statistically significant':'not statistically significant'),
        $df, $t, $pFmt, $d);
    $researcher = sprintf('An independent-samples t-test (Welch) indicated a %s difference between %s (M=%.2f, SD=%.2f) and %s (M=%.2f, SD=%.2f), t(%.1f)=%.2f, p=%s, d=%.2f, %d%% CI [%.2f, %.2f].',
        ($p<0.05?'significant':'non-significant'),
        $g1k, $g1['mean'], sqrt($g1['var']), $g2k, $g2['mean'], sqrt($g2['var']),
        $df, $t, $pFmt, $d, $pct, $ci_lo, $ci_hi);

    echo json_encode([
        'ok' => true,
        'outcome'  => ['name' => $outName],
        'grouping' => ['name' => $grpName, 'group1' => $g1k, 'group2' => $g2k],
        'descriptives' => [
            array_merge(['group'=>$g1k], $s1),
            array_merge(['group'=>$g2k], $s2),
        ],
        'difference' => ['mean1'=>round($g1['mean'],3),'mean2'=>round($g2['mean'],3),
                         'diff'=>round($diff,3),'direction'=>$dir,'pattern'=>($p<0.05?"Significant — $dir":'No significant difference')],
        'result' => ['test_used'=>"Welch's t-test",'t'=>round($t,3),'df'=>round($df,1),
                     'p'=>$p,'p_str'=>$pFmt,'diff'=>round($diff,3),
                     'ci_lo'=>$ci_lo,'ci_hi'=>$ci_hi,'conf_level'=>$conf,'significant'=>$p<0.05],
        'effect' => ['type'=>"Cohen's d",'value'=>round($d,3),'interpretation'=>$dlbl,'meaning'=>eff_meaning_d($dlbl)],
        'reporting' => ['plain'=>$plain,'researcher'=>$researcher,
                        'next'=>'Explore qualitatively why the groups differ on this outcome.',
                        'caution'=>"Welch's t-test assumes approximately normal distributions in each group, especially with small N."],
    ]);
    exit;
}

// ============================================================
// ANOVA
// ============================================================
if ($tool === 'anova') {
    $values  = array_map('floatval', (array)($body['values'] ?? []));
    $groups  = array_map('strval',  (array)($body['groups'] ?? []));
    $outName = (string)($body['outcome_name'] ?? 'Outcome');
    $grpName = (string)($body['group_name']   ?? 'Group');

    $res = stats_anova($values, $groups);
    if (!$res['ok']) { echo json_encode($res); exit; }

    $F  = $res['statistic']; $df1 = $res['df1']; $df2 = $res['df2'];
    $p  = $res['p_value']; $pFmt = stats_format_p($p);
    $e2 = $res['effect_size'] ?? 0.0;
    $det = $res['details']['groups']; $grandM = $res['details']['grand_mean'];

    // Per-group descriptives
    $descriptives = [];
    $byGroup = [];
    $n = min(count($values), count($groups));
    for ($i = 0; $i < $n; $i++) $byGroup[$groups[$i]][] = $values[$i];
    foreach (array_keys($det) as $g) {
        $s = arr_stats($byGroup[$g] ?? []);
        $descriptives[] = array_merge(['group' => $g], $s);
    }

    $elbl = eff_label_eta($e2);
    $plain = sprintf('A one-way ANOVA %s a significant difference in %s across groups, F(%d,%d)=%.2f, p=%s, η²=%.3f.',
        ($p<0.05?'found':'did not find'), $outName, (int)$df1, (int)$df2, $F, $pFmt, $e2);
    $researcher = sprintf('A one-way ANOVA revealed a %s effect of %s on %s, F(%d,%d)=%.2f, p=%s, η²=%.3f.',
        ($p<0.05?'significant':'non-significant'), $grpName, $outName, (int)$df1, (int)$df2, $F, $pFmt, $e2);

    echo json_encode([
        'ok' => true,
        'outcome'      => ['name' => $outName],
        'grouping'     => ['name' => $grpName, 'grand_mean' => round($grandM, 3)],
        'descriptives' => $descriptives,
        'result' => ['test_used'=>'One-way ANOVA','F'=>round($F,3),'df1'=>(int)$df1,'df2'=>(int)$df2,
                     'p'=>$p,'p_str'=>$pFmt,'eta_sq'=>round($e2,3),'significant'=>$p<0.05],
        'effect' => ['type'=>'η² (eta-squared)','value'=>round($e2,3),'interpretation'=>$elbl,'meaning'=>eff_meaning_eta($elbl)],
        'reporting' => ['plain'=>$plain,'researcher'=>$researcher,
                        'next'=>'Use a post-hoc test (e.g., Tukey HSD) to identify which specific groups differ.',
                        'caution'=>'ANOVA tests the omnibus null. A significant F only tells you at least one group differs — not which one.'],
    ]);
    exit;
}

// ============================================================
// CHI-SQUARE
// ============================================================
if ($tool === 'chi_square') {
    $rowVals = array_map('strval', (array)($body['values'] ?? []));
    $colVals = array_map('strval', (array)($body['groups'] ?? []));
    $rowName = (string)($body['row_name'] ?? 'Variable A');
    $colName = (string)($body['col_name'] ?? 'Variable B');

    $res = stats_chi_square($rowVals, $colVals);
    if (!$res['ok']) { echo json_encode($res); exit; }

    $chi2 = $res['statistic']; $df = $res['df1']; $p = $res['p_value'];
    $pFmt = stats_format_p($p); $V = $res['effect_size'] ?? 0.0; $N = $res['n_total'];
    $det = $res['details'];

    // Build contingency table in MM format
    $rowKeys = array_keys($det['rows']); $colKeys = array_keys($det['cols']); $grand = $N;
    $matrix = [];
    foreach ($rowKeys as $rk) {
        $cells = []; $rtot = $det['rows'][$rk];
        foreach ($colKeys as $ck) {
            $cnt = $det['contingency'][$rk][$ck] ?? 0;
            $cells[] = ['count' => $cnt, 'row_pct' => $rtot > 0 ? round(100*$cnt/$rtot, 1) : 0.0];
        }
        $matrix[] = ['label' => $rk, 'cells' => $cells, 'total' => $rtot];
    }
    $colTotals = array_map(fn($c) => $det['cols'][$c], $colKeys);

    $vlbl  = eff_label_v($V);
    $plain = sprintf('A chi-square test of independence %s a %s association between %s and %s, χ²(%d, N=%d)=%.2f, p=%s, V=%.3f.',
        ($p<0.05?'found':'did not find'), ($p<0.05?'significant':'significant'), $rowName, $colName, (int)$df, $N, $chi2, $pFmt, $V);
    $researcher = sprintf('A chi-square test of independence indicated %s between %s and %s, χ²(%d, N=%d)=%.2f, p=%s, Cramér\'s V=%.3f.',
        ($p<0.05?'a significant association':'no significant association'), $rowName, $colName, (int)$df, $N, $chi2, $pFmt, $V);

    echo json_encode([
        'ok' => true,
        'row' => ['name' => $rowName], 'col' => ['name' => $colName],
        'table' => ['row_var'=>$rowName,'col_var'=>$colName,'row_labels'=>$rowKeys,
                    'col_labels'=>$colKeys,'matrix'=>$matrix,'col_totals'=>$colTotals,'grand'=>$grand],
        'result' => ['test_used'=>'Pearson chi-square','chi2'=>round($chi2,3),'df'=>(int)$df,
                     'n_total'=>$N,'p'=>$p,'p_str'=>$pFmt,'cramers_v'=>round($V,3),'significant'=>$p<0.05],
        'effect' => ['type'=>"Cramér's V",'value'=>round($V,3),'interpretation'=>$vlbl,'meaning'=>eff_meaning_v($vlbl)],
        'reporting' => ['plain'=>$plain,'researcher'=>$researcher,
                        'next'=>'Examine the cells with the largest deviations from expected counts to locate the source of the relationship.',
                        'caution'=>'Chi-square is unreliable when expected cell counts fall below 5. Check that your sample size is adequate for the number of categories.'],
    ]);
    exit;
}

// ============================================================
// CORRELATION
// ============================================================
if ($tool === 'correlation') {
    $xVals  = array_map('floatval', (array)($body['x'] ?? []));
    $yVals  = array_map('floatval', (array)($body['y'] ?? []));
    $xName  = (string)($body['x_name'] ?? 'Variable X');
    $yName  = (string)($body['y_name'] ?? 'Variable Y');

    $res = stats_pearson($xVals, $yVals);
    if (!$res['ok']) { echo json_encode($res); exit; }

    $r  = $res['statistic']; $r2 = $res['effect_size'] ?? ($r*$r);
    $df = $res['df1']; $p = $res['p_value']; $pFmt = stats_format_p($p); $N = $res['n_total'];
    $sx = arr_stats($xVals); $sy = arr_stats($yVals);

    $dir = $r > 0 ? 'positive' : ($r < 0 ? 'negative' : 'none');
    $rabs = abs($r);
    $strength = $rabs >= 0.7 ? 'strong' : ($rabs >= 0.4 ? 'moderate' : ($rabs >= 0.2 ? 'weak' : 'negligible'));
    $rlbl = $rabs >= 0.25 ? 'large' : ($rabs >= 0.09 ? 'medium' : ($rabs >= 0.01 ? 'small' : 'negligible'));
    $rmean = "r² = " . round($r2,3) . " — " . round($r2*100,1) . "% of variance in one variable is explained by the other.";

    $plain = sprintf('There was a %s %s correlation between %s and %s, r(%.0f)=%.3f, p=%s.',
        $strength, ($dir==='positive'?'positive':($dir==='negative'?'negative':'zero')), $xName, $yName, $df, $r, $pFmt);
    $researcher = sprintf('A Pearson correlation indicated a %s %s correlation between %s and %s, r(%.0f)=%.3f, p=%s, r²=%.3f.',
        ($p<0.05?'significant':'non-significant'), $strength, $xName, $yName, $df, $r, $pFmt, $r2);

    echo json_encode([
        'ok' => true,
        'x' => ['name' => $xName], 'y' => ['name' => $yName],
        'descriptives' => [
            array_merge(['name' => $xName], $sx),
            array_merge(['name' => $yName], $sy),
        ],
        'result' => ['test_used'=>'Pearson r','r'=>round($r,3),'r2'=>round($r2,3),
                     'df'=>(int)$df,'p'=>$p,'p_str'=>$pFmt,'direction'=>$dir,'significant'=>$p<0.05],
        'effect' => ['type'=>'r² (r-squared)','value'=>round($r2,3),'interpretation'=>$rlbl,'meaning'=>$rmean],
        'reporting' => ['plain'=>$plain,'researcher'=>$researcher,
                        'next'=>'Explore qualitatively what might explain why these variables move together.',
                        'caution'=>'Pearson r measures linear association only. Non-linear relationships and outliers can distort the result. Correlation is not causation.'],
    ]);
    exit;
}

// ============================================================
// REGRESSION (OLS via normal equations, supports multiple predictors)
// ============================================================
if ($tool === 'regression') {
    // y: outcome values, x_list: array of predictor arrays, y_name, x_names
    $yRaw   = array_map('floatval', (array)($body['y'] ?? []));
    $xList  = (array)($body['x_list'] ?? []);
    $yName  = (string)($body['y_name'] ?? 'Outcome');
    $xNames = (array)($body['x_names'] ?? []);
    // Also support simple single-predictor (backwards compat)
    if (empty($xList) && !empty($body['x'])) {
        $xList  = [$body['x']];
        $xNames = [$xNames[0] ?? 'Predictor'];
    }
    $p = count($xList);
    if ($p === 0) { echo json_encode(['ok'=>false,'error'=>'No predictors supplied.']); exit; }

    // Build design matrix X (N × p+1): col 0 = 1 (intercept)
    $N = count($yRaw);
    foreach ($xList as $xv) $N = min($N, count($xv));
    if ($N < $p + 2) { echo json_encode(['ok'=>false,'error'=>'Not enough observations for the number of predictors.']); exit; }

    // Collect complete cases
    $Y = []; $X = [];
    for ($i = 0; $i < $N; $i++) {
        $row = [1.0];
        $ok = true;
        foreach ($xList as $xv) { $v = floatval($xv[$i] ?? null); if (!is_finite($v)){$ok=false;break;} $row[] = $v; }
        if ($ok && is_finite($yRaw[$i])) { $Y[] = $yRaw[$i]; $X[] = $row; }
    }
    $n = count($Y); $k = $p + 1; // k = number of params incl. intercept
    if ($n < $k + 1) { echo json_encode(['ok'=>false,'error'=>'Not enough complete cases.']); exit; }

    // Normal equations: b = (X'X)^-1 X'y
    // Compute X'X (k×k) and X'y (k×1)
    $XtX = array_fill(0, $k, array_fill(0, $k, 0.0));
    $Xty = array_fill(0, $k, 0.0);
    for ($r = 0; $r < $n; $r++) {
        for ($i = 0; $i < $k; $i++) {
            $Xty[$i] += $X[$r][$i] * $Y[$r];
            for ($j = 0; $j < $k; $j++) $XtX[$i][$j] += $X[$r][$i] * $X[$r][$j];
        }
    }
    // Gauss-Jordan inversion of XtX
    $inv = $XtX;
    $I   = array_fill(0, $k, array_fill(0, $k, 0.0));
    for ($i = 0; $i < $k; $i++) $I[$i][$i] = 1.0;
    for ($col = 0; $col < $k; $col++) {
        $pivot = $inv[$col][$col];
        if (abs($pivot) < 1e-12) { echo json_encode(['ok'=>false,'error'=>'Matrix is singular — predictors may be collinear.']); exit; }
        for ($j = 0; $j < $k; $j++) { $inv[$col][$j] /= $pivot; $I[$col][$j] /= $pivot; }
        for ($r2 = 0; $r2 < $k; $r2++) {
            if ($r2 === $col) continue;
            $f = $inv[$r2][$col];
            for ($j = 0; $j < $k; $j++) { $inv[$r2][$j] -= $f * $inv[$col][$j]; $I[$r2][$j] -= $f * $I[$col][$j]; }
        }
    }
    // b = I * Xty
    $b = array_fill(0, $k, 0.0);
    for ($i = 0; $i < $k; $i++) for ($j = 0; $j < $k; $j++) $b[$i] += $I[$i][$j] * $Xty[$j];

    // Residuals, RSS, TSS
    $yBar = array_sum($Y) / $n;
    $rss = 0.0; $tss = 0.0;
    for ($r = 0; $r < $n; $r++) {
        $yhat = 0.0;
        for ($j = 0; $j < $k; $j++) $yhat += $b[$j] * $X[$r][$j];
        $rss += ($Y[$r] - $yhat)**2;
        $tss += ($Y[$r] - $yBar)**2;
    }
    $dfRes  = $n - $k;
    $dfReg  = $k - 1;
    $mse    = $dfRes > 0 ? $rss / $dfRes : 0.0;
    $r2     = $tss > 0.0 ? 1.0 - $rss / $tss : 0.0;
    $adjR2  = 1.0 - (1.0 - $r2) * ($n - 1) / max($dfRes, 1);
    $msr    = $dfReg > 0 ? ($tss - $rss) / $dfReg : 0.0;
    $F      = $mse > 0.0 ? $msr / $mse : 0.0;
    $pF     = stats_f_pvalue($F, (float)$dfReg, (float)$dfRes);
    $pFmt   = stats_format_p($pF);

    // Coefficients with SE, t, p
    $coefs = [];
    for ($i = 0; $i < $k; $i++) {
        $se = $mse > 0.0 && $I[$i][$i] > 0.0 ? sqrt($mse * $I[$i][$i]) : 0.0;
        $tv = $se > 0.0 ? $b[$i] / $se : 0.0;
        $pc = stats_t_pvalue($tv, (float)$dfRes);
        $coefs[] = [
            'term'         => $i === 0 ? 'Intercept' : ($xNames[$i-1] ?? "X$i"),
            'b'            => round($b[$i], 4),
            'se'           => round($se, 4),
            't'            => round($tv, 3),
            'p'            => $pc,
            'p_str'        => stats_format_p($pc),
            'sig'          => $pc < 0.05,
            'is_intercept' => $i === 0,
        ];
    }

    $rLbl = $r2 >= 0.25 ? 'large' : ($r2 >= 0.09 ? 'medium' : ($r2 >= 0.01 ? 'small' : 'negligible'));
    $predList = implode(', ', array_map(fn($n,$i)=>$n??("X".($i+1)), $xNames, range(0,$p-1)));
    $plain = sprintf('A linear regression predicting %s from %s %s (R²=%.3f, F(%d,%d)=%.2f, p=%s).',
        $yName, $predList, ($pF<0.05?'was statistically significant':'was not statistically significant'),
        $r2, $dfReg, $dfRes, $F, $pFmt);
    $researcher = sprintf('A linear regression model predicting %s from %s was %s, F(%d,%d)=%.2f, p=%s, R²=%.3f, adj. R²=%.3f.',
        $yName, $predList, ($pF<0.05?'statistically significant':'not statistically significant'),
        $dfReg, $dfRes, $F, $pFmt, $r2, $adjR2);

    echo json_encode([
        'ok'           => true,
        'outcome'      => ['name' => $yName],
        'predictors'   => array_map(fn($nm,$i) => ['name'=>$nm??("X".($i+1))], $xNames, range(0,$p-1)),
        'coefficients' => $coefs,
        'result' => ['test_used'=>'OLS linear regression','r2'=>round($r2,3),'adj_r2'=>round($adjR2,3),
                     'F'=>round($F,3),'df1'=>$dfReg,'df2'=>$dfRes,'n_total'=>$n,
                     'p'=>$pF,'p_str'=>$pFmt,'significant'=>$pF<0.05],
        'effect' => ['type'=>'R² (R-squared)','value'=>round($r2,3),'interpretation'=>$rLbl,
                     'meaning'=>"R² = ".round($r2,3)." — the predictors explain ".round($r2*100,1)."% of variance in $yName."],
        'reporting' => ['plain'=>$plain,'researcher'=>$researcher,
                        'next'=>'Examine residuals and check linearity before reporting this model.',
                        'caution'=>'OLS regression assumes linearity, independence of errors, and approximately equal variance of residuals. Results may be affected by outliers or collinearity between predictors.'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown tool: ' . $tool]);
