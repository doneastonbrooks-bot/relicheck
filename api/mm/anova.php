<?php
// POST /api/mm/anova.php
// One-way ANOVA for a project's linked dataset.
// Body: {project_id, outcome_id, grouping_id}

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
if ($projectId <= 0 || $outcomeIdx < 0 || $groupIdx < 0) fail('bad_input', 'Missing required parameters.');
if ($outcomeIdx === $groupIdx) fail('bad_input', 'Outcome and grouping must be different variables.');
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

// ── Build value arrays ────────────────────────────────────────────────────
$allVals = []; $allGroups = [];
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $gv = trim((string)($row[$groupIdx] ?? ''));
    $ov = trim((string)($row[$outcomeIdx] ?? ''));
    if ($gv === '' || $ov === '' || !is_numeric($ov)) continue;
    $allVals[]   = (float)$ov;
    $allGroups[] = $gv;
}

$r = stats_anova($allVals, $allGroups);
if (!($r['ok'] ?? false)) fail('stats_error', $r['error'] ?? 'Could not run ANOVA.');

$F      = (float)$r['statistic'];
$df1    = (int)$r['df1'];
$df2    = (int)$r['df2'];
$p      = (float)$r['p_value'];
$etaSq  = (float)($r['effect_size'] ?? 0.0);
$pStr   = stats_format_p($p);
$sig    = $p < 0.05;
$groups = (array)($r['details']['groups'] ?? []);

// ── Build descriptives table ──────────────────────────────────────────────
$byGroup = [];
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $gv = trim((string)($row[$groupIdx] ?? ''));
    $ov = trim((string)($row[$outcomeIdx] ?? ''));
    if ($gv === '' || $ov === '' || !is_numeric($ov)) continue;
    $byGroup[$gv][] = (float)$ov;
}
$missPerGroup = [];
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $gv = trim((string)($row[$groupIdx] ?? ''));
    $ov = trim((string)($row[$outcomeIdx] ?? ''));
    if ($gv === '' || $ov !== '' && !is_numeric($ov)) $missPerGroup[$gv] = ($missPerGroup[$gv] ?? 0) + 1;
}

$descriptives = []; $dropped = [];
foreach ($groups as $gVal => $gs) {
    $vals = $byGroup[$gVal] ?? [];
    $n = count($vals); if ($n < 2) { $dropped[] = $gVal; continue; }
    $mean = $gs['mean'] ?? (array_sum($vals) / $n);
    $var = 0.0; foreach ($vals as $x) $var += ($x - $mean) ** 2;
    $sd = sqrt($var / ($n - 1));
    $se = $sd / sqrt((float)$n);
    $descriptives[] = ['group' => (string)$gVal, 'n' => $n, 'mean' => round((float)$mean, 4),
                       'sd' => round($sd, 4), 'se' => round($se, 4),
                       'min' => min($vals), 'max' => max($vals),
                       'missing' => (int)($missPerGroup[$gVal] ?? 0)];
}

// Eta-squared interpretation
if ($etaSq < 0.01)      { $eInterp = 'Negligible'; $eMeaning = 'The grouping explains very little of the variance.'; }
elseif ($etaSq < 0.06)  { $eInterp = 'Small';      $eMeaning = 'A small proportion of variance explained.'; }
elseif ($etaSq < 0.14)  { $eInterp = 'Medium';     $eMeaning = 'A moderate proportion of variance explained.'; }
else                     { $eInterp = 'Large';      $eMeaning = 'A large proportion of variance explained by group membership.'; }

$nTotal = (int)($r['n_total'] ?? 0);
$plain = sprintf('A one-way ANOVA found that %s %s significantly related to %s (F(%d,%d)=%.2f, p=%s, η²=%.3f, N=%d). %s',
    $grpName, $sig ? 'was' : 'was not', $outName, $df1, $df2, $F, $pStr, $etaSq, $nTotal,
    $sig ? 'Mean scores differed meaningfully across groups.' : 'Group means did not differ beyond chance.');
$researcher = sprintf('One-way ANOVA: F(%d, %d) = %.2f, p = %s, η² = %.3f, N = %d.',
    $df1, $df2, $F, $pStr, $etaSq, $nTotal);

json_out([
    'ok'               => true,
    'outcome'          => ['name' => $outName],
    'grouping'         => ['name' => $grpName, 'dropped' => $dropped],
    'descriptives'     => $descriptives,
    'result'           => ['test_used' => 'One-way ANOVA', 'F' => round($F, 4), 'df1' => $df1, 'df2' => $df2,
                           'p' => $p, 'p_str' => $pStr, 'eta_sq' => round($etaSq, 4), 'significant' => $sig],
    'effect'           => ['type' => 'Eta-squared (η²)', 'value' => round($etaSq, 4),
                           'interpretation' => $eInterp, 'meaning' => $eMeaning],
    'reporting'        => ['plain' => $plain, 'researcher' => $researcher,
                           'next' => 'A significant ANOVA means at least one group differs — follow up with pairwise comparisons or a qualitative strand to explain which groups and why.',
                           'caution' => 'ANOVA only tells you that groups differ; it does not identify which pairs. Report effect size (η²) alongside the F statistic.'],
    'follow_up_question' => "What experiences help explain the differences in $outName across groups of $grpName?",
]);
