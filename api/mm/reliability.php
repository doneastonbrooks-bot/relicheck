<?php
// POST /api/mm/reliability.php
// Cronbach's alpha and item statistics for a project's linked dataset.
// Body: {project_id, item_ids: [int, ...]}

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
$itemRaw   = $body['item_ids'] ?? [];
if ($projectId <= 0 || !is_array($itemRaw) || count($itemRaw) < 2)
    fail('bad_input', 'project_id and at least two item_ids are required.');
$itemIdxs = array_values(array_unique(array_map('intval', $itemRaw)));
if (count($itemIdxs) < 2) fail('bad_input', 'At least two distinct items are required.');
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

$itemNames = array_map(fn($i) => (string)($cm[$i]['name'] ?? ('col_' . $i)), $itemIdxs);
$k = count($itemIdxs);

// ── Collect complete cases for all items ──────────────────────────────────
$itemVals = array_fill(0, $k, []); // itemVals[$j] = array of numeric values
$sums = []; // row totals for complete cases
foreach ($data as $row) {
    if (!is_array($row)) continue;
    $rowVals = []; $ok = true;
    foreach ($itemIdxs as $j => $idx) {
        $v = trim((string)($row[$idx] ?? ''));
        if ($v === '' || !is_numeric($v)) { $ok = false; break; }
        $rowVals[] = (float)$v;
    }
    if (!$ok) continue;
    foreach ($rowVals as $j => $fv) $itemVals[$j][] = $fv;
    $sums[] = array_sum($rowVals);
}
$N = count($sums);
if ($N < 2) fail('insufficient_data', "Need at least 2 complete observations (found $N).");

// ── Cronbach's alpha ──────────────────────────────────────────────────────
function mm_rel_var(array $arr): float {
    $n = count($arr); if ($n < 2) return 0.0;
    $m = array_sum($arr) / $n;
    $ss = 0.0; foreach ($arr as $x) $ss += ($x - $m) ** 2;
    return $ss / ($n - 1);
}
function mm_rel_alpha(array $itemVals, array $sums): float {
    $k = count($itemVals); if ($k < 2) return 0.0;
    $varSum = 0.0; foreach ($itemVals as $vals) $varSum += mm_rel_var($vals);
    $varTotal = mm_rel_var($sums);
    if ($varTotal <= 0.0) return 0.0;
    return ($k / ($k - 1)) * (1.0 - $varSum / $varTotal);
}
function mm_rel_pearson(array $x, array $y): float {
    $n = count($x); if ($n < 3) return 0.0;
    $mx = array_sum($x) / $n; $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $mx; $dy = $y[$i] - $my;
        $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy;
    }
    if ($sxx <= 0 || $syy <= 0) return 0.0;
    return $sxy / sqrt($sxx * $syy);
}

$alpha = mm_rel_alpha($itemVals, $sums);

// ── Item statistics ───────────────────────────────────────────────────────
$items = [];
for ($j = 0; $j < $k; $j++) {
    $vals = $itemVals[$j];
    $n    = count($vals);
    $mean = array_sum($vals) / $n;
    $vari = mm_rel_var($vals);
    $sd   = sqrt($vari);

    // Corrected item-total correlation: correlate item with sum-of-rest
    $rest = array_map(fn($sum, $v) => $sum - $v, $sums, $vals);
    $itCorr = mm_rel_pearson($vals, $rest);

    // Alpha if item deleted
    $restItems = array_values(array_filter($itemVals, fn($_, $idx) => $idx !== $j, ARRAY_FILTER_USE_BOTH));
    $restSums  = array_map(fn($sum, $v) => $sum - $v, $sums, $vals);
    $alphaIfDel = count($restItems) >= 2 ? mm_rel_alpha($restItems, $restSums) : null;

    $flag = ($itCorr < 0.30) || ($alphaIfDel !== null && $alphaIfDel > $alpha + 0.01);
    $items[] = [
        'name'            => $itemNames[$j],
        'mean'            => round($mean, 4),
        'sd'              => round($sd, 4),
        'item_total_r'    => round($itCorr, 4),
        'alpha_if_deleted' => $alphaIfDel !== null ? round($alphaIfDel, 4) : null,
        'flag'            => $flag,
    ];
}

// ── Alpha band ────────────────────────────────────────────────────────────
if ($alpha >= 0.90)      { $band = 'Excellent (≥.90)'; $usable = true;  }
elseif ($alpha >= 0.80)  { $band = 'Good (.80–.89)';    $usable = true;  }
elseif ($alpha >= 0.70)  { $band = 'Acceptable (.70–.79)'; $usable = true;  }
elseif ($alpha >= 0.60)  { $band = 'Questionable (.60–.69)'; $usable = false; }
elseif ($alpha >= 0.50)  { $band = 'Poor (.50–.59)';    $usable = false; }
else                      { $band = 'Unacceptable (<.50)'; $usable = false; }

$plain = sprintf('%d items with Cronbach\'s α = %.2f (%s). The scale %s as a single measure.',
    $k, $alpha, $band, $usable ? 'has adequate internal consistency to score' : 'does not yet have adequate consistency to score');
$next = $usable
    ? 'The scale can be scored (summed or averaged) as a single composite variable.'
    : 'Review flagged items — items with low item-total correlations may not belong to this scale.';
$researcher = sprintf('Cronbach\'s α = %.3f (k = %d, N = %d). Items: %s.',
    $alpha, $k, $N, implode(', ', $itemNames));

json_out([
    'ok'        => true,
    'items'     => $items,
    'result'    => ['alpha' => round($alpha, 4), 'band' => $band, 'k' => $k, 'n' => $N, 'usable' => $usable],
    'reporting' => ['plain' => $plain, 'researcher' => $researcher, 'next' => $next,
                    'caution' => 'Alpha depends on the number of items and inter-item correlations — more items inflate it. Alpha does not confirm a unidimensional scale; check whether the items conceptually belong together.'],
]);
