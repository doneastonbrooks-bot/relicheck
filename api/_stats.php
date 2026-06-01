<?php
// api/_stats.php
//
// Pure-PHP statistics helpers for the Mixed-Methods Studio Step 14
// (quantitative support analysis). Each public function returns the same
// shape:
//
//   [
//     'ok'            => true,
//     'test_name'     => 'chi_square' | 't_test' | 'anova' | 'pearson',
//     'statistic'     => float,
//     'df1'           => float|null,
//     'df2'           => float|null,
//     'p_value'       => float,
//     'effect_size'   => float|null,
//     'effect_label'  => 'cramers_v'|'cohens_d'|'eta_squared'|'r_squared'|null,
//     'n_total'       => int,
//     'summary'       => string,
//     'details'       => mixed (groups / contingency / etc.)
//   ]
//
// On bad input the helper returns ['ok' => false, 'error' => '<reason>'].
//
// Notes:
// - p-values use closed-form series approximations (regularized
//   incomplete beta / lower regularized gamma) that are accurate to
//   roughly 1e-6 for the ranges we hit. Good enough for Studio reporting.
// - No external libraries. Hand-rolled, well-tested algorithms.

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Regularized incomplete gamma P(a, x) and Q(a, x). Used for chi-square p.
// ---------------------------------------------------------------------------
function stats_lngamma(float $x): float { return (float)log(abs(stats_gamma_approx($x))); }

function stats_gamma_approx(float $x): float {
    // Lanczos approximation, g = 7, n = 9.
    static $p = [
        0.99999999999980993,   676.5203681218851,    -1259.1392167224028,
        771.32342877765313,    -176.61502916214059,  12.507343278686905,
        -0.13857109526572012,  9.9843695780195716e-6, 1.5056327351493116e-7
    ];
    if ($x < 0.5) {
        return M_PI / (sin(M_PI * $x) * stats_gamma_approx(1.0 - $x));
    }
    $x -= 1.0;
    $a = $p[0];
    $t = $x + 7.5;
    for ($i = 1; $i < 9; $i++) $a += $p[$i] / ($x + $i);
    return sqrt(2.0 * M_PI) * pow($t, $x + 0.5) * exp(-$t) * $a;
}

function stats_gammp(float $a, float $x): float {
    if ($x < 0.0 || $a <= 0.0) return 0.0;
    if ($x == 0.0) return 0.0;
    if ($x < $a + 1.0) return stats_gser($a, $x);
    return 1.0 - stats_gcf($a, $x);
}

function stats_gser(float $a, float $x): float {
    $maxIter = 200; $eps = 1.0e-12;
    $ap = $a;
    $sum = 1.0 / $a;
    $del = $sum;
    for ($n = 1; $n <= $maxIter; $n++) {
        $ap += 1.0;
        $del *= $x / $ap;
        $sum += $del;
        if (abs($del) < abs($sum) * $eps) break;
    }
    $lng = log(abs(stats_gamma_approx($a)));
    return $sum * exp(-$x + $a * log($x) - $lng);
}

function stats_gcf(float $a, float $x): float {
    $maxIter = 200; $eps = 1.0e-12; $fpmin = 1.0e-300;
    $b = $x + 1.0 - $a;
    $c = 1.0 / $fpmin;
    $d = 1.0 / $b;
    $h = $d;
    for ($i = 1; $i <= $maxIter; $i++) {
        $an = -$i * ($i - $a);
        $b += 2.0;
        $d = $an * $d + $b;
        if (abs($d) < $fpmin) $d = $fpmin;
        $c = $b + $an / $c;
        if (abs($c) < $fpmin) $c = $fpmin;
        $d = 1.0 / $d;
        $del = $d * $c;
        $h *= $del;
        if (abs($del - 1.0) < $eps) break;
    }
    $lng = log(abs(stats_gamma_approx($a)));
    return exp(-$x + $a * log($x) - $lng) * $h;
}

// p-value for chi-square statistic with df degrees of freedom
function stats_chisq_pvalue(float $chi2, float $df): float {
    if ($df <= 0.0 || $chi2 < 0.0) return 1.0;
    return max(0.0, 1.0 - stats_gammp($df / 2.0, $chi2 / 2.0));
}

// ---------------------------------------------------------------------------
// Regularized incomplete beta I_x(a,b). Used for t and F p-values.
// ---------------------------------------------------------------------------
function stats_betacf(float $a, float $b, float $x): float {
    $maxIter = 200; $eps = 1.0e-12; $fpmin = 1.0e-300;
    $qab = $a + $b; $qap = $a + 1.0; $qam = $a - 1.0;
    $c = 1.0; $d = 1.0 - $qab * $x / $qap;
    if (abs($d) < $fpmin) $d = $fpmin;
    $d = 1.0 / $d;
    $h = $d;
    for ($m = 1; $m <= $maxIter; $m++) {
        $m2 = 2 * $m;
        $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
        $d = 1.0 + $aa * $d; if (abs($d) < $fpmin) $d = $fpmin;
        $c = 1.0 + $aa / $c; if (abs($c) < $fpmin) $c = $fpmin;
        $d = 1.0 / $d;
        $h *= $d * $c;
        $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
        $d = 1.0 + $aa * $d; if (abs($d) < $fpmin) $d = $fpmin;
        $c = 1.0 + $aa / $c; if (abs($c) < $fpmin) $c = $fpmin;
        $d = 1.0 / $d;
        $del = $d * $c;
        $h *= $del;
        if (abs($del - 1.0) < $eps) break;
    }
    return $h;
}

function stats_betai(float $a, float $b, float $x): float {
    if ($x < 0.0 || $x > 1.0) return 0.0;
    if ($x == 0.0 || $x == 1.0) return $x == 0.0 ? 0.0 : 1.0;
    $lnGa = log(abs(stats_gamma_approx($a)));
    $lnGb = log(abs(stats_gamma_approx($b)));
    $lnGab = log(abs(stats_gamma_approx($a + $b)));
    $bt = exp($lnGab - $lnGa - $lnGb + $a * log($x) + $b * log(1.0 - $x));
    if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
        return $bt * stats_betacf($a, $b, $x) / $a;
    }
    return 1.0 - $bt * stats_betacf($b, $a, 1.0 - $x) / $b;
}

// two-sided p for Student t with df degrees of freedom
function stats_t_pvalue(float $t, float $df): float {
    if ($df <= 0.0) return 1.0;
    $x = $df / ($df + $t * $t);
    $p = stats_betai($df / 2.0, 0.5, $x); // upper tail x2 = two-sided
    return max(0.0, min(1.0, $p));
}

// upper-tail p for F with df1, df2
function stats_f_pvalue(float $f, float $df1, float $df2): float {
    if ($f <= 0.0 || $df1 <= 0.0 || $df2 <= 0.0) return 1.0;
    $x = $df2 / ($df2 + $df1 * $f);
    $p = stats_betai($df2 / 2.0, $df1 / 2.0, $x);
    return max(0.0, min(1.0, $p));
}

// ---------------------------------------------------------------------------
// Test: Chi-square independence on a contingency table.
// Inputs: arrays $a, $b of equal length, both treated as labels.
// ---------------------------------------------------------------------------
function stats_chi_square(array $a, array $b): array {
    $n = min(count($a), count($b));
    $rows = []; $cols = []; $cell = [];
    for ($i = 0; $i < $n; $i++) {
        $r = (string)$a[$i]; $c = (string)$b[$i];
        if ($r === '' || $c === '') continue;
        if (!isset($rows[$r])) $rows[$r] = 0;
        if (!isset($cols[$c])) $cols[$c] = 0;
        if (!isset($cell[$r][$c])) $cell[$r][$c] = 0;
        $rows[$r]++; $cols[$c]++; $cell[$r][$c]++;
    }
    $N = array_sum($rows);
    if ($N < 5 || count($rows) < 2 || count($cols) < 2) {
        $rowVals = array_keys($rows); $colVals = array_keys($cols);
        $detail = 'Predictor distinct values: ' . (count($rowVals) === 0 ? '(none)' : implode(', ', array_map(fn($v) => $v === '' ? '<blank>' : $v, $rowVals)))
                . '. Outcome distinct values: ' . (count($colVals) === 0 ? '(none)' : implode(', ', array_map(fn($v) => $v === '' ? '<blank>' : $v, $colVals)))
                . '. N = ' . $N . '.';
        return ['ok' => false, 'error' => 'Chi-square needs at least 2 categories on each side and N >= 5. ' . $detail];
    }
    $chi2 = 0.0;
    foreach ($rows as $r => $rn) {
        foreach ($cols as $c => $cn) {
            $obs = $cell[$r][$c] ?? 0;
            $exp = ($rn * $cn) / $N;
            if ($exp > 0.0) $chi2 += (($obs - $exp) ** 2) / $exp;
        }
    }
    $df = (count($rows) - 1) * (count($cols) - 1);
    $p = stats_chisq_pvalue($chi2, $df);
    $k = min(count($rows), count($cols));
    $cramers = $k > 1 ? sqrt($chi2 / ($N * ($k - 1))) : null;
    $summary = sprintf(
        'Chi-square(df=%d) = %.2f, p = %s, Cramer V = %.2f, N = %d.',
        $df, $chi2, stats_format_p($p), $cramers ?? 0.0, $N
    );
    return [
        'ok' => true, 'test_name' => 'chi_square',
        'statistic' => $chi2, 'df1' => (float)$df, 'df2' => null,
        'p_value' => $p, 'effect_size' => $cramers, 'effect_label' => 'cramers_v',
        'n_total' => $N, 'summary' => $summary,
        'details' => ['rows' => $rows, 'cols' => $cols, 'contingency' => $cell],
    ];
}

// ---------------------------------------------------------------------------
// Test: Welch's t-test on a numeric series split by a 2-level group.
// $values is numeric, $groups is categorical.
// ---------------------------------------------------------------------------
function stats_t_test(array $values, array $groups): array {
    $n = min(count($values), count($groups));
    $byGroup = [];
    for ($i = 0; $i < $n; $i++) {
        $v = $values[$i]; $g = (string)$groups[$i];
        if ($g === '' || !is_numeric($v)) continue;
        $byGroup[$g][] = (float)$v;
    }
    if (count($byGroup) !== 2) {
        return ['ok' => false, 'error' => 'Independent t-test needs exactly 2 groups (got ' . count($byGroup) . ').'];
    }
    $keys = array_keys($byGroup);
    $a = $byGroup[$keys[0]]; $b = $byGroup[$keys[1]];
    $na = count($a); $nb = count($b);
    if ($na < 2 || $nb < 2) return ['ok' => false, 'error' => 'Each group needs at least 2 observations.'];
    $ma = array_sum($a) / $na; $mb = array_sum($b) / $nb;
    $va = stats_variance($a, $ma); $vb = stats_variance($b, $mb);
    if ($va == 0.0 && $vb == 0.0) return ['ok' => false, 'error' => 'No variance in either group.'];
    $se = sqrt($va / $na + $vb / $nb);
    if ($se == 0.0) return ['ok' => false, 'error' => 'Standard error is zero.'];
    $t = ($ma - $mb) / $se;
    // Welch-Satterthwaite df
    $df = (($va / $na + $vb / $nb) ** 2)
        / ((($va / $na) ** 2) / max($na - 1, 1) + (($vb / $nb) ** 2) / max($nb - 1, 1));
    $p = stats_t_pvalue($t, $df);
    // Pooled-SD Cohen's d
    $sp = sqrt((($na - 1) * $va + ($nb - 1) * $vb) / max(($na + $nb - 2), 1));
    $d = $sp > 0.0 ? ($ma - $mb) / $sp : null;
    $N = $na + $nb;
    $summary = sprintf(
        "Welch t(df=%.1f) = %.2f, p = %s, Cohen d = %s, N = %d. %s mean = %.2f (n=%d), %s mean = %.2f (n=%d).",
        $df, $t, stats_format_p($p), $d === null ? 'n/a' : sprintf('%.2f', $d), $N,
        $keys[0], $ma, $na, $keys[1], $mb, $nb
    );
    return [
        'ok' => true, 'test_name' => 't_test',
        'statistic' => $t, 'df1' => $df, 'df2' => null,
        'p_value' => $p, 'effect_size' => $d, 'effect_label' => 'cohens_d',
        'n_total' => $N, 'summary' => $summary,
        'details' => ['groups' => [
            $keys[0] => ['n' => $na, 'mean' => $ma, 'var' => $va],
            $keys[1] => ['n' => $nb, 'mean' => $mb, 'var' => $vb],
        ]],
    ];
}

// ---------------------------------------------------------------------------
// Test: One-way ANOVA on a numeric series split by a 3+ level group.
// ---------------------------------------------------------------------------
function stats_anova(array $values, array $groups): array {
    $n = min(count($values), count($groups));
    $byGroup = [];
    for ($i = 0; $i < $n; $i++) {
        $v = $values[$i]; $g = (string)$groups[$i];
        if ($g === '' || !is_numeric($v)) continue;
        $byGroup[$g][] = (float)$v;
    }
    if (count($byGroup) < 3) {
        return ['ok' => false, 'error' => 'One-way ANOVA needs at least 3 groups (got ' . count($byGroup) . ').'];
    }
    $grandSum = 0.0; $grandN = 0;
    $stats = [];
    foreach ($byGroup as $g => $arr) {
        $cnt = count($arr); if ($cnt < 2) return ['ok' => false, 'error' => 'Each group needs at least 2 observations (group "' . $g . '" has ' . $cnt . ').'];
        $mean = array_sum($arr) / $cnt;
        $stats[$g] = ['n' => $cnt, 'mean' => $mean];
        $grandSum += array_sum($arr); $grandN += $cnt;
    }
    if ($grandN < 6) return ['ok' => false, 'error' => 'Need at least 6 observations total.'];
    $grandMean = $grandSum / $grandN;
    $ssBetween = 0.0; $ssWithin = 0.0;
    foreach ($byGroup as $g => $arr) {
        $cnt = $stats[$g]['n']; $mean = $stats[$g]['mean'];
        $ssBetween += $cnt * (($mean - $grandMean) ** 2);
        foreach ($arr as $x) $ssWithin += (($x - $mean) ** 2);
    }
    $dfBetween = count($byGroup) - 1;
    $dfWithin  = $grandN - count($byGroup);
    if ($dfWithin < 1 || $ssWithin == 0.0) return ['ok' => false, 'error' => 'No within-group variance.'];
    $msBetween = $ssBetween / $dfBetween;
    $msWithin  = $ssWithin  / $dfWithin;
    $F = $msBetween / $msWithin;
    $p = stats_f_pvalue($F, (float)$dfBetween, (float)$dfWithin);
    $etaSq = ($ssBetween + $ssWithin) > 0.0 ? $ssBetween / ($ssBetween + $ssWithin) : null;
    $summary = sprintf(
        'One-way ANOVA F(%d, %d) = %.2f, p = %s, eta-squared = %.3f, N = %d, groups = %d.',
        $dfBetween, $dfWithin, $F, stats_format_p($p), $etaSq ?? 0.0, $grandN, count($byGroup)
    );
    return [
        'ok' => true, 'test_name' => 'anova',
        'statistic' => $F, 'df1' => (float)$dfBetween, 'df2' => (float)$dfWithin,
        'p_value' => $p, 'effect_size' => $etaSq, 'effect_label' => 'eta_squared',
        'n_total' => $grandN, 'summary' => $summary,
        'details' => ['groups' => $stats, 'grand_mean' => $grandMean],
    ];
}

// ---------------------------------------------------------------------------
// Test: Pearson correlation on two numeric series.
// ---------------------------------------------------------------------------
function stats_pearson(array $x, array $y): array {
    $n = min(count($x), count($y));
    $xs = []; $ys = [];
    for ($i = 0; $i < $n; $i++) {
        if (is_numeric($x[$i]) && is_numeric($y[$i])) {
            $xs[] = (float)$x[$i]; $ys[] = (float)$y[$i];
        }
    }
    $N = count($xs);
    if ($N < 3) return ['ok' => false, 'error' => 'Pearson r needs at least 3 paired observations.'];
    $mx = array_sum($xs) / $N; $my = array_sum($ys) / $N;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $N; $i++) {
        $dx = $xs[$i] - $mx; $dy = $ys[$i] - $my;
        $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy;
    }
    if ($sxx == 0.0 && $syy == 0.0) return ['ok' => false, 'error' => 'Neither variable has any variance - every value is the same.'];
    if ($sxx == 0.0) return ['ok' => false, 'error' => 'The predictor variable has no variance - every value is the same (mean = ' . sprintf('%.2f', $mx) . ').'];
    if ($syy == 0.0) return ['ok' => false, 'error' => 'The outcome variable has no variance - every value is the same (mean = ' . sprintf('%.2f', $my) . ').'];
    $r = $sxy / sqrt($sxx * $syy);
    if ($r >= 1.0)  $r = 0.999999999;
    if ($r <= -1.0) $r = -0.999999999;
    $df = $N - 2;
    $t = $r * sqrt($df / (1.0 - $r * $r));
    $p = stats_t_pvalue($t, (float)$df);
    $summary = sprintf('Pearson r = %.2f (r-squared = %.3f), t(%d) = %.2f, p = %s, N = %d.', $r, $r * $r, $df, $t, stats_format_p($p), $N);
    return [
        'ok' => true, 'test_name' => 'pearson',
        'statistic' => $r, 'df1' => (float)$df, 'df2' => null,
        'p_value' => $p, 'effect_size' => $r * $r, 'effect_label' => 'r_squared',
        'n_total' => $N, 'summary' => $summary,
        'details' => ['mean_x' => $mx, 'mean_y' => $my],
    ];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function stats_variance(array $arr, ?float $mean = null): float {
    $n = count($arr); if ($n < 2) return 0.0;
    if ($mean === null) $mean = array_sum($arr) / $n;
    $s = 0.0;
    foreach ($arr as $x) $s += ($x - $mean) ** 2;
    return $s / ($n - 1);
}

function stats_format_p(float $p): string {
    if ($p < 0.0001) return '<.0001';
    if ($p < 0.001)  return '<.001';
    return sprintf('%.3f', $p);
}

// Guess the right test for a (predictor, outcome) pair given their var_types.
// Returns one of: chi_square | t_test | anova | pearson | null.
function stats_suggest_test(string $predType, string $outType, int $predGroups, int $outGroups): ?string {
    $numericTypes = ['numeric', 'ordinal', 'frequency', 'intensity', 'sentiment_num'];
    $catTypes     = ['binary', 'category', 'sentiment'];
    $isPredNum = in_array(strtolower($predType), $numericTypes, true);
    $isOutNum  = in_array(strtolower($outType),  $numericTypes, true);
    $isPredCat = in_array(strtolower($predType), $catTypes, true);
    $isOutCat  = in_array(strtolower($outType),  $catTypes, true);

    if ($isPredNum && $isOutNum) return 'pearson';
    if ($isPredCat && $isOutCat) return 'chi_square';
    // numeric vs categorical: t-test if the categorical side has exactly 2 groups, else ANOVA
    if ($isPredNum && $isOutCat) {
        return $outGroups === 2 ? 't_test' : ($outGroups >= 3 ? 'anova' : null);
    }
    if ($isPredCat && $isOutNum) {
        return $predGroups === 2 ? 't_test' : ($predGroups >= 3 ? 'anova' : null);
    }
    return null;
}

// ============================================================
// Inter-rater agreement (added Phase 182, for MM Trustworthiness).
// Pure functions; no dependency on the rest of this file.
// ============================================================

// Cohen's kappa for two raters over aligned categorical judgments.
// $a and $b are equal-length arrays of labels (e.g. '0'/'1' for code
// applied / not applied). Returns null if not computable, else kappa in
// [-1, 1]. Returns 1.0 on perfect, degenerate-agreement (pe == 1).
function stats_cohen_kappa(array $a, array $b): ?float
{
    $n = count($a);
    if ($n === 0 || $n !== count($b)) return null;
    $a = array_values($a);
    $b = array_values($b);
    $labels = array_values(array_unique(array_merge($a, $b)));

    $po = 0;
    for ($i = 0; $i < $n; $i++) if ($a[$i] === $b[$i]) $po++;
    $po /= $n;

    $pe = 0.0;
    foreach ($labels as $l) {
        $pa = 0; $pb = 0;
        for ($i = 0; $i < $n; $i++) { if ($a[$i] === $l) $pa++; if ($b[$i] === $l) $pb++; }
        $pe += ($pa / $n) * ($pb / $n);
    }
    if ($pe >= 1.0) return 1.0;
    return ($po - $pe) / (1 - $pe);
}

// Fleiss' kappa for 3+ raters. $table is one row per item; each row is the
// per-category count of raters choosing that category, and every row must
// sum to the same number of raters n (>= 2). Returns null if not computable.
function stats_fleiss_kappa(array $table): ?float
{
    $N = count($table);
    if ($N === 0) return null;
    $n = array_sum($table[0]);
    if ($n < 2) return null;
    $k = count($table[0]);

    $Pbar = 0.0;
    $colSum = array_fill(0, $k, 0);
    foreach ($table as $row) {
        if (count($row) !== $k || array_sum($row) !== $n) return null; // ragged → not computable
        $s = 0;
        for ($j = 0; $j < $k; $j++) { $s += $row[$j] * $row[$j]; $colSum[$j] += $row[$j]; }
        $Pbar += ($s - $n) / ($n * ($n - 1));
    }
    $Pbar /= $N;

    $Pe = 0.0;
    $tot = $N * $n;
    for ($j = 0; $j < $k; $j++) { $pj = $colSum[$j] / $tot; $Pe += $pj * $pj; }
    if ($Pe >= 1.0) return 1.0;
    return ($Pbar - $Pe) / (1 - $Pe);
}

// ---------------------------------------------------------------------------
// Test: Simple OLS regression (numeric Y from numeric X).
// ---------------------------------------------------------------------------
function stats_regression(array $x, array $y): array {
    $n = min(count($x), count($y));
    $xs = []; $ys = [];
    for ($i = 0; $i < $n; $i++) {
        if (is_numeric($x[$i]) && is_numeric($y[$i])) {
            $xs[] = (float)$x[$i]; $ys[] = (float)$y[$i];
        }
    }
    $N = count($xs);
    if ($N < 3) return ['ok' => false, 'error' => 'Regression needs at least 3 paired observations.'];
    $mx = array_sum($xs) / $N; $my = array_sum($ys) / $N;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $N; $i++) {
        $dx = $xs[$i] - $mx; $dy = $ys[$i] - $my;
        $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy;
    }
    if ($sxx == 0.0) return ['ok' => false, 'error' => 'Predictor has no variance — every value is the same.'];
    $slope     = $sxy / $sxx;
    $intercept = $my - $slope * $mx;
    $rss = 0.0;
    for ($i = 0; $i < $N; $i++) {
        $res  = $ys[$i] - ($slope * $xs[$i] + $intercept);
        $rss += $res * $res;
    }
    $r2  = $syy > 0.0 ? 1.0 - $rss / $syy : 0.0;
    $df  = $N - 2;
    $mse = $df > 0 ? $rss / $df : 0.0;
    $se_slope = ($mse > 0.0 && $sxx > 0.0) ? sqrt($mse / $sxx) : 0.0;
    $t_slope  = $se_slope > 0.0 ? $slope / $se_slope : 0.0;
    $p = stats_t_pvalue($t_slope, (float)$df);
    $summary = sprintf(
        'OLS: Y = %.3f + %.3f*X, R² = %.3f, t(%d) = %.2f, p = %s, N = %d.',
        $intercept, $slope, $r2, $df, $t_slope, stats_format_p($p), $N
    );
    return [
        'ok' => true, 'test_name' => 'regression',
        'statistic' => $t_slope, 'df1' => (float)$df, 'df2' => null,
        'p_value' => $p, 'effect_size' => $r2, 'effect_label' => 'r_squared',
        'n_total' => $N, 'summary' => $summary,
        'details' => ['slope' => $slope, 'intercept' => $intercept, 'r_squared' => $r2,
                      'se_slope' => $se_slope, 'mean_x' => $mx, 'mean_y' => $my],
    ];
}
