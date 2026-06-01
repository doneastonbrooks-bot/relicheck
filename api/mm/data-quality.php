<?php
// POST /api/mm/data-quality.php
//   Body: { project_id }
//
// Pre-analysis data-quality gate. Loads the project's linked raw dataset (same
// lookup as ttest.php / descriptives.php) and runs seven deterministic checks
// — the same set the Overview lens computes client-side (apps/overview/overview.js)
// — but server-side, so the MM Studio v4 "Data Quality" step shows real findings
// instead of static placeholders.
//
// Column roles come from the saved column_meta `type` when present
// (likert/numeric/criterion/open/identifier/single/multi/demographic); when a
// column is untagged ('ignore' or missing) the role is inferred from the data so
// the checks still work on raw uploads that were never tagged.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
check_rate_limit('mm_quality:user:' . $uid, 240, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'project_id is required.');
mm_require_project($pdo, $uid, $projectId);

// Linked raw dataset (same lookup as ttest.php / descriptives.php).
$dq = $pdo->prepare('SELECT dataset_id FROM mm_projects WHERE id = :p AND user_id = :u');
$dq->execute([':p' => $projectId, ':u' => $uid]);
$datasetId = (int)($dq->fetchColumn() ?: 0);
if ($datasetId <= 0) fail('mm_no_dataset', 'No dataset is linked to this project. Upload data first.', 404);

$drq = $pdo->prepare('SELECT column_meta, data FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
$drq->execute([':d' => $datasetId, ':u' => $uid]);
$drow = $drq->fetch(PDO::FETCH_ASSOC);
if (!$drow) fail('mm_no_dataset', 'Dataset not found for this project.', 404);

$cm   = json_decode((string)$drow['column_meta'], true) ?: [];
$data = json_decode((string)$drow['data'], true) ?: [];
$nRows = count($data);
$nCols = count($cm);
if ($nRows === 0 || $nCols === 0) fail('mm_empty_dataset', 'The linked dataset has no rows or columns.', 404);

// ---- small helpers -------------------------------------------------------
$isMissing = static function ($v): bool {
    return $v === null || trim((string)$v) === '';
};
$num = static function ($v) {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !is_numeric($s)) return null;
    return (float)$s;
};
// Linear-interpolation quantile (matches the Overview lens behaviour).
$quantile = static function (array $sorted, float $q) {
    $n = count($sorted);
    if ($n === 0) return null;
    if ($n === 1) return $sorted[0];
    $pos = ($n - 1) * $q;
    $lo  = (int)floor($pos);
    $hi  = (int)ceil($pos);
    if ($lo === $hi) return $sorted[$lo];
    $frac = $pos - $lo;
    return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac;
};

// ---- classify each column into a role ------------------------------------
// roles: 'numeric' | 'likert' | 'open' | 'id' | 'categorical' | 'ignore'
$cols = [];
for ($j = 0; $j < $nCols; $j++) {
    $name = trim((string)($cm[$j]['name'] ?? ('Column ' . ($j + 1))));
    $type = (string)($cm[$j]['type'] ?? '');

    // Pull this column's values once.
    $vals = [];
    foreach ($data as $row) {
        $vals[] = (is_array($row) && array_key_exists($j, $row)) ? $row[$j] : null;
    }

    // Profile the column.
    $nonEmpty = 0; $numericCnt = 0; $intInScale = 0; $lenSum = 0;
    $distinct = [];
    foreach ($vals as $v) {
        if ($isMissing($v)) continue;
        $nonEmpty++;
        $s = trim((string)$v);
        $lenSum += mb_strlen($s);
        $distinct[$s] = true;
        $f = $num($v);
        if ($f !== null) {
            $numericCnt++;
            if ($f == (int)$f && $f >= 1 && $f <= 11) $intInScale++;
        }
    }
    $distinctN = count($distinct);
    $numFrac   = $nonEmpty ? $numericCnt / $nonEmpty : 0.0;
    $uniqFrac  = $nonEmpty ? $distinctN / $nonEmpty : 0.0;
    $avgLen    = $nonEmpty ? $lenSum / $nonEmpty : 0.0;

    // Map the saved type to a role; fall back to inference when untagged.
    $role = null;
    switch ($type) {
        case 'likert':                          $role = 'likert';      break;
        case 'numeric': case 'criterion':       $role = 'numeric';     break;
        case 'open':                            $role = 'open';        break;
        case 'identifier':                      $role = 'id';          break;
        case 'single': case 'multi': case 'demographic': $role = 'categorical'; break;
        // 'ignore' / '' / unknown -> infer below
    }
    if ($role === null) {
        if ($nonEmpty >= 8 && $uniqFrac > 0.9) {
            $role = 'id';
        } elseif ($numFrac >= 0.8 && $distinctN >= 3) {
            // numeric; treat tight integer 1..11 scales as Likert
            $role = ($intInScale === $numericCnt && $distinctN <= 11) ? 'likert' : 'numeric';
        } elseif ($numFrac < 0.5 && $nonEmpty > 0 && ($avgLen >= 20 || $uniqFrac > 0.7)) {
            $role = 'open';
        } elseif ($distinctN >= 2 && $distinctN <= 30) {
            $role = 'categorical';
        } else {
            $role = 'ignore';
        }
    }

    $cols[] = ['idx' => $j, 'name' => $name, 'role' => $role, 'values' => $vals,
               'nonEmpty' => $nonEmpty, 'distinctN' => $distinctN];
}

// Variable groupings used by the checks.
$allVars     = array_values(array_filter($cols, fn($c) => $c['role'] !== 'ignore'));
$numericVars = array_values(array_filter($cols, fn($c) => $c['role'] === 'numeric' || $c['role'] === 'likert'));
$likertVars  = array_values(array_filter($cols, fn($c) => $c['role'] === 'likert'));
$openVars    = array_values(array_filter($cols, fn($c) => $c['role'] === 'open'));
$idVar       = null;
foreach ($cols as $c) { if ($c['role'] === 'id') { $idVar = $c; break; } }

$checks = [];
$mkCheck = static function (string $name, string $finding, string $tone, string $detail) {
    return ['name' => $name, 'finding' => $finding, 'tone' => $tone, 'detail' => $detail];
};
$plural = static fn(int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');

// 1. Duplicate full rows -----------------------------------------------------
$seenFull = []; $dupFull = 0; $dupFullEx = [];
for ($i = 0; $i < $nRows; $i++) {
    $parts = [];
    foreach ($allVars as $v) { $parts[] = (string)($v['values'][$i] ?? ''); }
    $key = implode("\x1f", $parts);
    if (isset($seenFull[$key])) { $dupFull++; if (count($dupFullEx) < 3) $dupFullEx[] = $i; }
    else $seenFull[$key] = $i;
}
$checks[] = $mkCheck('Duplicate full rows',
    $dupFull === 0 ? 'None' : $plural($dupFull, 'row') . ' identical across all columns',
    $dupFull === 0 ? 'ok' : 'alert',
    $dupFull ? 'Example row indices: ' . implode(', ', array_map(fn($i) => '#' . ($i + 1), $dupFullEx)) : 'No identical rows.');

// 2. Duplicate IDs -----------------------------------------------------------
if ($idVar) {
    $seenId = []; $dupId = 0; $dupIdEx = [];
    for ($i = 0; $i < $nRows; $i++) {
        $k = trim((string)($idVar['values'][$i] ?? ''));
        if ($k === '') continue;
        if (isset($seenId[$k])) { $dupId++; if (count($dupIdEx) < 3) $dupIdEx[] = $k; }
        else $seenId[$k] = true;
    }
    $checks[] = $mkCheck('Duplicate IDs',
        $dupId === 0 ? 'None (' . $idVar['name'] . ')' : $plural($dupId, 'duplicate id') . ' in ' . $idVar['name'],
        $dupId === 0 ? 'ok' : 'alert',
        $dupId ? 'Example ids: ' . implode(', ', $dupIdEx) : 'All ids unique.');
} else {
    $checks[] = $mkCheck('Duplicate IDs', 'No ID column', 'muted',
        'Tag a column as an identifier to enable this check.');
}

// 3. Straight-lining on Likert items ----------------------------------------
if (count($likertVars) >= 3) {
    $straight = 0;
    for ($i = 0; $i < $nRows; $i++) {
        $first = null; $ok = true; $any = false;
        foreach ($likertVars as $v) {
            $f = $num($v['values'][$i] ?? null);
            if ($f === null) { $ok = false; break; }
            if ($first === null) { $first = $f; $any = true; }
            elseif ($f !== $first) { $ok = false; break; }
        }
        if ($ok && $any) $straight++;
    }
    $rate = $nRows ? $straight / $nRows : 0;
    $checks[] = $mkCheck('Straight-lining on Likert items',
        $straight === 0 ? 'None' : $plural($straight, 'respondent') . ' answered every Likert item identically',
        $straight === 0 ? 'ok' : ($rate > 0.05 ? 'alert' : 'warn'),
        $nRows ? 'Straight-lining rate: ' . round($rate * 100) . '%.' : '');
} else {
    $checks[] = $mkCheck('Straight-lining on Likert items', 'Not assessed (need 3 or more Likert items)', 'muted', '');
}

// 4. Numeric outliers (Tukey IQR fences) ------------------------------------
$outlierCount = 0; $outlierBreak = [];
foreach ($numericVars as $v) {
    $nums = [];
    foreach ($v['values'] as $val) { $f = $num($val); if ($f !== null) $nums[] = $f; }
    if (count($nums) < 4) continue;
    sort($nums);
    $q1 = $quantile($nums, 0.25); $q3 = $quantile($nums, 0.75);
    $iqr = $q3 - $q1; $lo = $q1 - 1.5 * $iqr; $hi = $q3 + 1.5 * $iqr;
    $out = 0;
    foreach ($nums as $x) { if ($x < $lo || $x > $hi) $out++; }
    if ($out > 0) { $outlierCount += $out; $outlierBreak[] = $v['name'] . ' (' . $out . ')'; }
}
$checks[] = $mkCheck('Numeric outliers (Tukey IQR)',
    $outlierCount === 0 ? 'None' : $plural($outlierCount, 'value') . ' outside 1.5x IQR fences',
    ($outlierCount === 0 || $outlierCount <= $nRows * 0.1) ? 'ok' : 'warn',
    $outlierBreak ? 'By variable: ' . implode(', ', $outlierBreak) : 'No outliers.');

// 5. Invalid numeric values --------------------------------------------------
$invalid = 0; $invalidBreak = [];
foreach ($numericVars as $v) {
    $inv = 0;
    foreach ($v['values'] as $val) {
        if ($isMissing($val)) continue;
        if ($num($val) === null) $inv++;
    }
    if ($inv) { $invalid += $inv; $invalidBreak[] = $v['name'] . ' (' . $inv . ')'; }
}
$checks[] = $mkCheck('Invalid numeric values',
    $invalid === 0 ? 'None' : $plural($invalid, 'non-numeric value') . ' in numeric/Likert columns',
    $invalid === 0 ? 'ok' : 'alert',
    $invalidBreak ? 'By variable: ' . implode(', ', $invalidBreak) : 'All numeric values parse.');

// 6. Low-effort open-ends ----------------------------------------------------
if (!$openVars) {
    $checks[] = $mkCheck('Low-effort open-ends', 'No open-ended fields', 'muted', '');
} else {
    $lowOpen = 0; $totalOpen = 0;
    foreach ($openVars as $v) {
        foreach ($v['values'] as $val) {
            if ($isMissing($val)) continue;
            $totalOpen++;
            if (mb_strlen(trim((string)$val)) < 5) $lowOpen++;
        }
    }
    $checks[] = $mkCheck('Low-effort open-ends',
        $lowOpen === 0 ? 'None' : $plural($lowOpen, 'answer') . ' under 5 characters',
        ($lowOpen === 0 || $lowOpen <= max($totalOpen, 1) * 0.2) ? 'ok' : 'warn',
        'Of ' . $totalOpen . ' total open-ended answers.');
}

// 7. High item-level missingness --------------------------------------------
$highMiss = [];
foreach ($allVars as $v) {
    $miss = 0;
    foreach ($v['values'] as $val) { if ($isMissing($val)) $miss++; }
    $rate = $nRows ? $miss / $nRows : 0;
    if ($rate > 0.20) $highMiss[] = $v['name'] . ' (' . round($rate * 100) . '%)';
}
$checks[] = $mkCheck('High item-level missingness',
    !$highMiss ? 'No variable above 20% missing' : $plural(count($highMiss), 'variable') . ' above 20% missing',
    !$highMiss ? 'ok' : 'warn',
    $highMiss ? 'Affected: ' . implode(', ', $highMiss) : '');

// ---- score + band ---------------------------------------------------------
$alerts = 0; $warns = 0;
foreach ($checks as $c) { if ($c['tone'] === 'alert') $alerts++; elseif ($c['tone'] === 'warn') $warns++; }
$score = max(0, 100 - 15 * $alerts - 5 * $warns);
if ($score >= 95)      { $band = 'Clean';              $scoreTone = 'ok'; }
elseif ($score >= 85)  { $band = 'Mostly clean';       $scoreTone = 'ok'; }
elseif ($score >= 70)  { $band = 'Needs attention';    $scoreTone = 'warn'; }
else                   { $band = 'Significant issues'; $scoreTone = 'alert'; }

$interp =
    $score >= 95 ? 'The dataset is clean. Proceed to analysis with confidence.' :
    ($score >= 85 ? 'Mostly clean. Address the warnings before publishing strong claims.' :
    ($score >= 70 ? 'Several issues need attention. Resolve the alerts first; inspect the warnings before drawing conclusions.' :
                    'Significant data-quality issues. Resolve the alerts before any analysis is defensible. Current results may be driven by duplicates, invalid values, or straight-lining.'));

json_out([
    'ok'        => true,
    'rows'      => $nRows,
    'columns'   => count($allVars),
    'checks'    => $checks,
    'alerts'    => $alerts,
    'warns'     => $warns,
    'clean'     => count($checks) - $alerts - $warns,
    'score'     => $score,
    'band'      => $band,
    'scoreTone' => $scoreTone,
    'interp'    => $interp,
]);
