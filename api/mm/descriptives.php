<?php
// POST /api/mm/descriptives.php
// Returns summary statistics for a project's linked dataset.
// Optional params in body:
//   xtab_row + xtab_col  (int) → crosstab matrix
//   means_num + means_group (int) → means of numeric by categorical group

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// ── Load dataset ──────────────────────────────────────────────────────────
$dq = $pdo->prepare('SELECT dataset_id FROM mm_projects WHERE id = :p AND user_id = :u');
$dq->execute([':p' => $projectId, ':u' => $uid]);
$datasetId = (int)($dq->fetchColumn() ?: 0);
if ($datasetId <= 0) fail('no_dataset', 'No dataset is linked to this project yet.', 404);
$drq = $pdo->prepare('SELECT column_meta, data FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
$drq->execute([':d' => $datasetId, ':u' => $uid]);
$drow = $drq->fetch(PDO::FETCH_ASSOC);
if (!$drow) fail('no_dataset', 'Dataset not found.', 404);
$cm   = json_decode((string)$drow['column_meta'], true) ?: [];
$data = json_decode((string)$drow['data'], true) ?: [];
if (!$cm || !$data) fail('empty_dataset', 'Dataset is empty.');

$nRows = count($data);

// ── Classify columns ─────────────────────────────────────────────────────
$numericCols     = [];
$categoricalCols = [];

foreach ($cm as $i => $col) {
    if (!is_array($col)) continue;
    $name      = (string)($col['name'] ?? ('col_' . $i));
    $savedType = (string)($col['type'] ?? '');
    $isSavedCat = in_array($savedType, ['single', 'multi'], true);

    $nonEmpty = 0; $numCount = 0; $distinct = [];
    foreach ($data as $row) {
        if (!is_array($row) || !array_key_exists($i, $row)) continue;
        $v = trim((string)$row[$i]); if ($v === '') continue;
        $nonEmpty++;
        if (is_numeric($v)) $numCount++;
        $distinct[$v] = ($distinct[$v] ?? 0) + 1;
    }
    if ($nonEmpty < 2) continue;
    $nDistinct = count($distinct);
    $isNumeric = $numCount >= 0.8 * $nonEmpty;
    $isId      = ($nonEmpty > 20 && $nDistinct > 0.9 * $nonEmpty);
    if ($isId && !$isSavedCat) continue;
    $avgValLen = $nDistinct ? array_sum(array_map('strlen', array_keys($distinct))) / $nDistinct : 0;
    $isVerbatim = ($avgValLen > 40);

    if ($isNumeric && !$isSavedCat && $nDistinct >= 3) {
        // Compute numeric stats
        $vals = []; $missing = 0;
        foreach ($data as $row) {
            if (!is_array($row) || !array_key_exists($i, $row)) continue;
            $v = trim((string)$row[$i]);
            if ($v === '' || !is_numeric($v)) { $missing++; continue; }
            $vals[] = (float)$v;
        }
        $n = count($vals);
        if ($n < 2) continue;
        $mean = array_sum($vals) / $n;
        $var = 0.0; foreach ($vals as $x) $var += ($x - $mean) ** 2;
        $sd = sqrt($var / ($n - 1));
        $min = min($vals); $max = max($vals);
        $numericCols[] = ['id' => $i, 'name' => $name, 'n' => $n, 'mean' => round($mean, 4),
                          'sd' => round($sd, 4), 'min' => $min, 'max' => $max, 'missing' => $missing];
    } elseif (($isSavedCat || !$isNumeric) && !$isVerbatim && $nDistinct >= 2 && $nDistinct <= 30) {
        arsort($distinct);
        $total = array_sum($distinct); $missing = $nRows - $nonEmpty;
        $cats = []; $cumValid = 0;
        foreach ($distinct as $val => $cnt) {
            $pct      = $nRows   > 0 ? round(100.0 * $cnt / $nRows, 2)  : 0.0;
            $validPct = $nonEmpty > 0 ? round(100.0 * $cnt / $nonEmpty, 2) : 0.0;
            $cats[] = ['value' => (string)$val, 'count' => $cnt, 'pct' => $pct, 'valid_pct' => $validPct];
            if (count($cats) >= 30) break;
        }
        $categoricalCols[] = ['id' => $i, 'name' => $name, 'missing' => $missing, 'categories' => $cats];
    }
}

// ── Optional: cross-tabulation ────────────────────────────────────────────
if (isset($body['xtab_row']) && isset($body['xtab_col'])) {
    $ri = (int)$body['xtab_row']; $ci = (int)$body['xtab_col'];
    if ($ri === $ci) fail('bad_input', 'Row and column variables must differ.');
    $rName = (string)($cm[$ri]['name'] ?? ('col_' . $ri));
    $cName = (string)($cm[$ci]['name'] ?? ('col_' . $ci));

    $rowDist = []; $colDist = []; $cell = [];
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $rv = trim((string)($row[$ri] ?? '')); $cv = trim((string)($row[$ci] ?? ''));
        if ($rv === '' || $cv === '') continue;
        $rowDist[$rv] = ($rowDist[$rv] ?? 0) + 1;
        $colDist[$cv] = ($colDist[$cv] ?? 0) + 1;
        $cell[$rv][$cv] = ($cell[$rv][$cv] ?? 0) + 1;
    }
    arsort($rowDist); arsort($colDist);
    $colLabels = array_keys($colDist);
    $matrix = []; $colTotals = array_fill(0, count($colLabels), 0);
    $grand = array_sum($rowDist);
    foreach ($rowDist as $rVal => $rTotal) {
        $cells = [];
        foreach ($colLabels as $j => $cVal) {
            $cnt = $cell[$rVal][$cVal] ?? 0;
            $colTotals[$j] += $cnt;
            $cells[] = ['count' => $cnt, 'row_pct' => $rTotal > 0 ? round(100.0 * $cnt / $rTotal, 2) : 0.0];
        }
        $matrix[] = ['label' => (string)$rVal, 'total' => (int)$rTotal, 'cells' => $cells];
    }
    json_out(['ok' => true, 'crosstab' => [
        'row_var' => $rName, 'col_var' => $cName,
        'col_labels' => $colLabels, 'matrix' => $matrix,
        'col_totals' => $colTotals, 'grand' => $grand,
    ]]);
}

// ── Optional: means by group ──────────────────────────────────────────────
if (isset($body['means_num']) && isset($body['means_group'])) {
    $ni = (int)$body['means_num']; $gi = (int)$body['means_group'];
    $allVals = []; $byGroup = [];
    foreach ($data as $row) {
        if (!is_array($row)) continue;
        $nv = trim((string)($row[$ni] ?? '')); $gv = trim((string)($row[$gi] ?? ''));
        if ($nv === '' || !is_numeric($nv) || $gv === '') continue;
        $allVals[] = (float)$nv;
        $byGroup[$gv][] = (float)$nv;
    }
    $overall = count($allVals) > 0 ? array_sum($allVals) / count($allVals) : 0.0;
    $groups = [];
    foreach ($byGroup as $gVal => $vals) {
        $n = count($vals); if ($n < 1) continue;
        $mean = array_sum($vals) / $n;
        $var = 0.0; foreach ($vals as $x) $var += ($x - $mean) ** 2;
        $sd = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
        $groups[] = ['group' => (string)$gVal, 'n' => $n,
                     'mean' => round($mean, 4), 'sd' => round($sd, 4),
                     'delta' => round($mean - $overall, 4)];
    }
    usort($groups, fn($a, $b) => $b['mean'] <=> $a['mean']);
    json_out(['ok' => true, 'means_by' => ['groups' => $groups]]);
}

// ── Base response ─────────────────────────────────────────────────────────
json_out(['ok' => true, 'rows' => $nRows, 'numeric' => $numericCols, 'categorical' => $categoricalCols]);
