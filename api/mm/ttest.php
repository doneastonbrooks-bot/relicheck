<?php
// POST /api/mm/ttest.php
// Independent-samples Welch t-test for a project's linked dataset.
// Body: {project_id, outcome_id, grouping_id, group1, group2, test_type, confidence}

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

$projectId  = (int)($body['project_id']  ?? 0);
$outcomeIdx = (int)($body['outcome_id']  ?? -1);
$groupIdx   = (int)($body['grouping_id'] ?? -1);
$group1     = (string)($body['group1']   ?? '');
$group2     = (string)($body['group2']   ?? '');
$confidence = max(0.80, min(0.99, (float)($body['confidence'] ?? 0.95)));
if ($projectId <= 0 || $outcomeIdx < 0 || $groupIdx < 0) fail('bad_input', 'Missing required parameters.');
if ($group1 === $group2) fail('bad_input', 'group1 and group2 must differ.');
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

$outName = (string)($cm[$outcomeIdx]['name'] ?? ('col_' . $outcomeIdx));
$grpName = (string)($cm[$groupIdx]['name']   ?? ('col_' . $groupIdx));

// ── Extract values ────────────────────────────────────────────────────────
$vals1 = []; $vals2 = []; $miss1 = 0; $miss2 = 0;
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $gv = trim((string)($row[$groupIdx] ?? ''));
    $ov = trim((string)($row[$outcomeIdx] ?? ''));
    if ($gv !== $group1 && $gv !== $group2) continue;
    $isG1 = ($gv === $group1);
    if ($ov === '' || !is_numeric($ov)) { if ($isG1) $miss1++; else $miss2++; continue; }
    if ($isG1) $vals1[] = (float)$ov; else $vals2[] = (float)$ov;
}
$n1 = count($vals1); $n2 = count($vals2);
if ($n1 < 2) fail('insufficient_data', "Group \"$group1\" has $n1 valid observation(s) — need at least 2.");
if ($n2 < 2) fail('insufficient_data', "Group \"$group2\" has $n2 valid observation(s) — need at least 2.");

// ── Descriptive stats per group ───────────────────────────────────────────
function mm_tt_desc(string $grp, array $vals, int $miss): array {
    $n = count($vals); $mean = array_sum($vals) / $n;
    $var = 0.0; foreach ($vals as $x) $var += ($x - $mean) ** 2;
    $sd = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
    $se = $sd / sqrt((float)$n);
    return ['group' => $grp, 'n' => $n, 'mean' => round($mean, 4), 'sd' => round($sd, 4),
            'se' => round($se, 4), 'min' => min($vals), 'max' => max($vals), 'missing' => $miss];
}

// ── T critical value via bisection ────────────────────────────────────────
function mm_t_critical(float $df, float $alpha_upper): float {
    $target = 2.0 * $alpha_upper; // two-sided
    $lo = 0.0001; $hi = 20.0;
    for ($i = 0; $i < 60; $i++) {
        $mid = ($lo + $hi) / 2.0;
        if (stats_t_pvalue($mid, $df) > $target) $lo = $mid; else $hi = $mid;
        if (($hi - $lo) < 1e-9) break;
    }
    return ($lo + $hi) / 2.0;
}

// ── Run the test (Welch directly — stats_t_test expects (values,groups) not two arrays) ─
$d1 = mm_tt_desc($group1, $vals1, $miss1);
$d2 = mm_tt_desc($group2, $vals2, $miss2);
$va = $d1['sd'] ** 2; $vb = $d2['sd'] ** 2;
if ($va == 0.0 && $vb == 0.0) fail('stats_error', 'No variance in either group — every value is identical.');
$se = sqrt($va / $n1 + $vb / $n2);
if ($se == 0.0) fail('stats_error', 'Standard error is zero.');
$t  = ($d1['mean'] - $d2['mean']) / $se;
// Welch–Satterthwaite df
$df = (($va / $n1 + $vb / $n2) ** 2)
    / ((($va / $n1) ** 2) / max($n1 - 1, 1) + (($vb / $n2) ** 2) / max($n2 - 1, 1));
$p  = stats_t_pvalue($t, $df);
// Pooled-SD Cohen's d
$sp   = sqrt((($n1 - 1) * $va + ($n2 - 1) * $vb) / max($n1 + $n2 - 2, 1));
$dEff = $sp > 0.0 ? ($d1['mean'] - $d2['mean']) / $sp : null;

// CI for mean difference
$alpha  = 1.0 - $confidence;
$tCrit  = mm_t_critical($df, $alpha / 2.0);
$diff   = $d1['mean'] - $d2['mean'];
$ciLo   = round($diff - $tCrit * $se, 4);
$ciHi   = round($diff + $tCrit * $se, 4);
$pStr   = stats_format_p($p);
$sig    = $p < 0.05;

// Cohen's d interpretation
$dAbs = abs($dEff ?? 0.0);
if ($dAbs < 0.2)      { $dInterp = 'Negligible'; $dMeaning = 'The groups are essentially the same in practical terms.'; }
elseif ($dAbs < 0.5)  { $dInterp = 'Small';      $dMeaning = 'A small difference that may not be noticeable in practice.'; }
elseif ($dAbs < 0.8)  { $dInterp = 'Medium';     $dMeaning = 'A moderate difference that is likely noticeable.'; }
else                   { $dInterp = 'Large';      $dMeaning = 'A large, practically meaningful difference between the groups.'; }

$dir  = $diff > 0 ? "$group1 scored higher" : ($diff < 0 ? "$group1 scored lower" : 'No difference');
$sigW = $sig ? 'statistically significant' : 'not statistically significant';
$plain = sprintf('%s (M=%.2f) %s than %s (M=%.2f) on %s. The difference of %.2f was %s (Welch t=%.2f, df=%.1f, p=%s).',
    $group1, $d1['mean'], $diff >= 0 ? 'scored higher' : 'scored lower', $group2, $d2['mean'],
    $outName, abs($diff), $sigW, $t, $df, $pStr);
$researcher = sprintf('%s: M=%.2f, SD=%.2f (n=%d); %s: M=%.2f, SD=%.2f (n=%d). Welch t(%.1f)=%.2f, p=%s, d=%.2f, %d%% CI [%.2f, %.2f].',
    $group1, $d1['mean'], $d1['sd'], $n1, $group2, $d2['mean'], $d2['sd'], $n2,
    $df, $t, $pStr, (int)round($confidence * 100), $ciLo, $ciHi, $dEff ?? 0.0);

json_out([
    'ok'               => true,
    'outcome'          => ['name' => $outName],
    'grouping'         => ['name' => $grpName, 'group1' => $group1, 'group2' => $group2],
    'descriptives'     => [$d1, $d2],
    'difference'       => ['mean1' => $d1['mean'], 'mean2' => $d2['mean'],
                           'diff' => round($diff, 4), 'direction' => $dir, 'pattern' => $sig ? 'Statistically significant' : 'Not significant'],
    'result'           => ['test_used' => 'Welch t-test', 't' => round($t, 4), 'df' => round($df, 2),
                           'p' => $p, 'p_str' => $pStr, 'ci_lo' => $ciLo, 'ci_hi' => $ciHi, 'significant' => $sig],
    'effect'           => ['type' => "Cohen's d", 'value' => $dEff !== null ? round($dEff, 3) : null,
                           'interpretation' => $dInterp, 'meaning' => $dMeaning],
    'reporting'        => ['plain' => $plain, 'researcher' => $researcher,
                           'next' => 'Stage this difference in the Identify Results to Explain step for qualitative follow-up.',
                           'caution' => 'Statistical significance depends on sample size. Always report effect size alongside p-value.'],
    'follow_up_question' => "What experiences help explain why $group1 scored differently from $group2 on $outName?",
    'readiness'        => [
        ['check' => 'Group 1 sample size', 'result' => "n = $n1", 'status' => $n1 >= 20 ? 'Pass' : 'Review',
         'guidance' => $n1 >= 20 ? 'Adequate.' : 'Small group reduces power — interpret with caution.'],
        ['check' => 'Group 2 sample size', 'result' => "n = $n2", 'status' => $n2 >= 20 ? 'Pass' : 'Review',
         'guidance' => $n2 >= 20 ? 'Adequate.' : 'Small group reduces power — interpret with caution.'],
        ['check' => 'Equal variances',     'result' => 'Welch correction applied', 'status' => 'Pass',
         'guidance' => 'Welch t-test does not assume equal variances.'],
        ['check' => 'Independence',        'result' => 'Must verify by design', 'status' => 'Pass',
         'guidance' => 'Ensure observations are independent (not paired, clustered, or repeated).'],
    ],
]);
