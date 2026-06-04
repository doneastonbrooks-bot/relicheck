<?php
// POST /api/mm/correlation.php
// Pearson correlation for a project's linked dataset.
// Body: {project_id, x_id, y_id}

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
$xIdx      = (int)($body['x_id']       ?? -1);
$yIdx      = (int)($body['y_id']       ?? -1);
if ($projectId <= 0 || $xIdx < 0 || $yIdx < 0) fail('bad_input', 'Missing required parameters.');
if ($xIdx === $yIdx) fail('bad_input', 'Variable 1 and Variable 2 must differ.');
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

$xName = (string)($cm[$xIdx]['name'] ?? ('col_' . $xIdx));
$yName = (string)($cm[$yIdx]['name'] ?? ('col_' . $yIdx));

// ── Extract paired values ─────────────────────────────────────────────────
$xVals = []; $yVals = [];
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $xv = trim((string)($row[$xIdx] ?? ''));
    $yv = trim((string)($row[$yIdx] ?? ''));
    if ($xv === '' || $yv === '' || !is_numeric($xv) || !is_numeric($yv)) continue;
    $xVals[] = (float)$xv; $yVals[] = (float)$yv;
}

$r = stats_pearson($xVals, $yVals);
if (!($r['ok'] ?? false)) fail('stats_error', $r['error'] ?? 'Could not compute correlation.');

$rVal = (float)$r['statistic'];
$r2   = (float)($r['effect_size'] ?? $rVal * $rVal);
$df   = (int)$r['df1'];
$p    = (float)$r['p_value'];
$N    = (int)$r['n_total'];
$pStr = stats_format_p($p);
$sig  = $p < 0.05;

// Descriptives for each variable
function mm_cor_desc(string $name, array $vals): array {
    $n = count($vals); if ($n < 1) return ['name' => $name, 'n' => 0, 'mean' => null, 'sd' => null, 'min' => null, 'max' => null];
    $mean = array_sum($vals) / $n;
    $var = 0.0; foreach ($vals as $x) $var += ($x - $mean) ** 2;
    $sd = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
    return ['name' => $name, 'n' => $n, 'mean' => round($mean, 4), 'sd' => round($sd, 4),
            'min' => min($vals), 'max' => max($vals)];
}

// r and r² interpretation
$rAbs = abs($rVal);
if ($rAbs < 0.10)     { $rInterp = 'Negligible'; $rMeaning = 'Essentially no linear relationship.'; }
elseif ($rAbs < 0.30) { $rInterp = 'Small';      $rMeaning = 'A weak linear relationship.'; }
elseif ($rAbs < 0.50) { $rInterp = 'Medium';     $rMeaning = 'A moderate linear relationship.'; }
else                   { $rInterp = 'Large';      $rMeaning = 'A strong linear relationship between the two variables.'; }
$dir = $rVal > 0 ? 'positive' : ($rVal < 0 ? 'negative' : 'no');

$plain = sprintf('%s and %s had a %s %s correlation (r=%.2f, r²=%.3f, p=%s, N=%d). %s',
    $xName, $yName, $rInterp, $dir, $rVal, $r2, $pStr, $N,
    $sig ? 'This relationship was statistically significant.' : 'This relationship was not statistically significant.');
$researcher = sprintf('Pearson r(%d) = %.3f, r² = %.3f, p = %s, N = %d.',
    $df, $rVal, $r2, $pStr, $N);

json_out([
    'ok'           => true,
    'x'            => ['name' => $xName],
    'y'            => ['name' => $yName],
    'descriptives' => [mm_cor_desc($xName, $xVals), mm_cor_desc($yName, $yVals)],
    'result'       => ['test_used' => 'Pearson correlation', 'r' => round($rVal, 4), 'r2' => round($r2, 4),
                       'df' => $df, 'p' => $p, 'p_str' => $pStr, 'direction' => ucfirst($dir), 'significant' => $sig],
    'effect'       => ['type' => 'r² (variance explained)', 'value' => round($r2, 4),
                       'interpretation' => $rInterp, 'meaning' => $rMeaning],
    'reporting'    => ['plain' => $plain, 'researcher' => $researcher,
                       'next' => 'Stage significant correlations in the Identify Results to Explain step for qualitative follow-up.',
                       'caution' => 'Correlation does not imply causation. Pearson r assumes a linear relationship; inspect the data visually to rule out curvilinear patterns.'],
    'follow_up_question' => "What might explain the relationship between $xName and $yName?",
]);
