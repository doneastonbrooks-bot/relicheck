<?php
// GET /api/suites/rollup.php?id=<suite_id>
//
// Phase 135. Cross-suite roll-up dashboard payload. Aggregates response
// counts, average SSI proxy, and shared-construct Likert-mean trends
// across all surveys attached to one suite. Two time windows: the current
// calendar quarter (in UTC) and the previous calendar quarter. The card
// in the suite detail view renders an empty state until the suite has
// two or more surveys; this endpoint mirrors that gate by returning
// reason='insufficient_surveys' when fewer than two surveys are attached.
//
// The SSI proxy is a server-side approximation of the full Stats.surveyStrength
// composite. Alpha drives the Reliability domain (25 pts); sample size drives
// the Response Quality domain (15 pts); the remaining four domains are
// neutral defaults that correlate well with the full score for most surveys.
// The dashboard labels this proxy "Average Strength Index" so users see a
// directionally honest 0-100 number, not a synthetic mismatch.
//
// Test-typed suites (Phase 134a) return reason='entity_test' because the
// roll-up math is keyed on survey responses, not test takes.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('GET');
$user = require_auth();
$userId = (int)$user['id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

$suite = suites_require_owned($id, $userId);
$entityType = suites_entity_type_for_key((string)$suite['suite_key']);

// Compute current and previous calendar-quarter bounds as UTC date strings.
// We pass these as inline string literals into the SQL to avoid the PHP /
// MySQL timezone mismatch on IONOS (see project memory).
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cy = (int)$now->format('Y');
$cm = (int)$now->format('n');
$cq = (int)floor(($cm - 1) / 3) + 1;
$quarterStartMonth = [1 => 1, 2 => 4, 3 => 7, 4 => 10];
$currentStart = sprintf('%04d-%02d-01 00:00:00', $cy, $quarterStartMonth[$cq]);
$nextStart    = $cq === 4
    ? sprintf('%04d-01-01 00:00:00', $cy + 1)
    : sprintf('%04d-%02d-01 00:00:00', $cy, $quarterStartMonth[$cq + 1]);
if ($cq === 1) { $pq = 4; $py = $cy - 1; } else { $pq = $cq - 1; $py = $cy; }
$previousStart = sprintf('%04d-%02d-01 00:00:00', $py, $quarterStartMonth[$pq]);
$previousEnd   = $currentStart;

$currentLabel  = 'Q' . $cq . ' ' . $cy;
$previousLabel = 'Q' . $pq . ' ' . $py;

$period = [
    'current_label'   => $currentLabel,
    'current_start'   => $currentStart,
    'current_end'     => $nextStart,
    'previous_label'  => $previousLabel,
    'previous_start'  => $previousStart,
    'previous_end'    => $previousEnd,
];

if ($entityType !== 'survey') {
    json_out([
        'ok'           => true,
        'reason'       => 'entity_test',
        'suite_id'     => (int)$suite['id'],
        'suite_name'   => (string)$suite['name'],
        'period'       => $period,
        'survey_count' => 0,
    ]);
}

$pdo = db();

$listStmt = $pdo->prepare(
    'SELECT s.id, s.title, s.questions, s.settings
       FROM suite_surveys ss
       JOIN surveys s ON s.id = ss.survey_id
      WHERE ss.suite_id = :sid AND s.owner_id = :uid
      ORDER BY ss.added_at ASC'
);
$listStmt->execute([':sid' => $id, ':uid' => $userId]);
$attached = $listStmt->fetchAll();

if (count($attached) < 2) {
    json_out([
        'ok'           => true,
        'reason'       => 'insufficient_surveys',
        'suite_id'     => (int)$suite['id'],
        'suite_name'   => (string)$suite['name'],
        'period'       => $period,
        'survey_count' => count($attached),
    ]);
}

// One survey, one window. Returns { n, alpha, ssi_proxy, composite_mean, constructs }.
function suites_rollup_window(PDO $pdo, int $surveyId, array $questions, int $likertPoints, string $startUtc, string $endUtc): array
{
    $stmt = $pdo->prepare(
        'SELECT answers FROM responses
          WHERE survey_id = :sid AND submitted_at >= :a AND submitted_at < :b'
    );
    $stmt->execute([':sid' => $surveyId, ':a' => $startUtc, ':b' => $endUtc]);
    $rows = $stmt->fetchAll();
    $nRaw = count($rows);

    $likertQs = [];
    foreach ($questions as $q) {
        if (is_array($q) && ($q['type'] ?? '') === 'likert') $likertQs[] = $q;
    }
    $k = count($likertQs);

    $empty = [
        'n'              => $nRaw,
        'alpha'          => null,
        'ssi_proxy'      => null,
        'composite_mean' => null,
        'constructs'     => [],
    ];
    if ($nRaw === 0 || $k < 2) return $empty;

    $matrix = [];
    foreach ($rows as $row) {
        $answers = $row['answers'];
        if (is_string($answers)) $answers = json_decode($answers, true);
        if (!is_array($answers)) continue;
        $vec = [];
        $ok = true;
        foreach ($likertQs as $q) {
            $qid = (string)($q['id'] ?? '');
            $v   = $answers[$qid] ?? null;
            if (!is_numeric($v)) { $ok = false; break; }
            $val = (float)$v;
            if (!empty($q['reverse'])) {
                $val = ($likertPoints + 1) - $val;
            }
            $vec[] = $val;
        }
        if ($ok) $matrix[] = $vec;
    }
    $nc = count($matrix);
    if ($nc < 3) return $empty;

    // Row-sum scale variance for Cronbach alpha.
    $rowSums = array_fill(0, $nc, 0.0);
    for ($i = 0; $i < $nc; $i++) {
        $s = 0.0;
        for ($j = 0; $j < $k; $j++) $s += $matrix[$i][$j];
        $rowSums[$i] = $s;
    }
    $muSum = array_sum($rowSums) / $nc;
    $varTotal = 0.0;
    foreach ($rowSums as $rs) $varTotal += ($rs - $muSum) * ($rs - $muSum);
    $varTotal /= max(1, $nc - 1);

    $sumItemVar = 0.0;
    for ($j = 0; $j < $k; $j++) {
        $colMean = 0.0;
        for ($i = 0; $i < $nc; $i++) $colMean += $matrix[$i][$j];
        $colMean /= $nc;
        $v = 0.0;
        for ($i = 0; $i < $nc; $i++) {
            $d = $matrix[$i][$j] - $colMean;
            $v += $d * $d;
        }
        $v /= max(1, $nc - 1);
        $sumItemVar += $v;
    }

    $alpha = null;
    if ($varTotal > 0) {
        $alpha = ($k / ($k - 1)) * (1 - $sumItemVar / $varTotal);
        if ($alpha > 1) $alpha = 1.0;
        if ($alpha < 0) $alpha = 0.0;
    }

    // Composite Likert mean across respondents.
    $compMean = $muSum / $k;

    // SSI proxy: anchor on Reliability and Response Quality domains, hold
    // the other four at conservative neutrals. Calibrated to land within a
    // few points of the full client-side Stats.surveyStrength for surveys
    // we have spot-checked.
    $ssi = null;
    if ($alpha !== null) {
        if ($alpha >= 0.90) $rel = 24;
        elseif ($alpha >= 0.80) $rel = 21;
        elseif ($alpha >= 0.70) $rel = 17;
        elseif ($alpha >= 0.60) $rel = 12;
        else $rel = 6;
        if ($nRaw >= 100) $resp = 15;
        elseif ($nRaw >= 50) $resp = 13;
        elseif ($nRaw >= 30) $resp = 11;
        elseif ($nRaw >= 10) $resp = 8;
        else $resp = 5;
        $factor = 14; // 70 percent of the 20-pt Factor Structure domain
        $items  = 14; // 70 percent of the 20-pt Item Quality domain
        $oe     = 7;  // neutral when open-ended cannot be evaluated server-side
        $action = 7;  // neutral actionability
        $ssi = $rel + $resp + $factor + $items + $oe + $action;
        if ($ssi > 100) $ssi = 100;
        if ($ssi < 0)   $ssi = 0;
    }

    // Construct subscales.
    $byConstruct = [];
    foreach ($likertQs as $j => $q) {
        $c = isset($q['construct']) && is_string($q['construct']) ? trim($q['construct']) : '';
        if ($c === '') continue;
        if (!isset($byConstruct[$c])) $byConstruct[$c] = [];
        $byConstruct[$c][] = $j;
    }
    $constructs = [];
    foreach ($byConstruct as $cName => $colIdxs) {
        $sum = 0.0;
        for ($i = 0; $i < $nc; $i++) {
            $s = 0.0;
            foreach ($colIdxs as $j) $s += $matrix[$i][$j];
            $sum += $s / count($colIdxs);
        }
        $constructs[] = [
            'name'  => $cName,
            'mean'  => round($sum / $nc, 3),
            'items' => count($colIdxs),
            'n'     => $nc,
        ];
    }

    return [
        'n'              => $nRaw,
        'alpha'          => $alpha === null ? null : round($alpha, 3),
        'ssi_proxy'      => $ssi,
        'composite_mean' => round($compMean, 3),
        'constructs'     => $constructs,
    ];
}

$perSurvey = [];
foreach ($attached as $a) {
    $questions = json_decode((string)$a['questions'], true);
    if (!is_array($questions)) $questions = [];
    $settings  = json_decode((string)$a['settings'], true);
    if (!is_array($settings)) $settings = [];
    $likertPoints = (int)($settings['likertPoints'] ?? 5);
    if ($likertPoints < 2 || $likertPoints > 11) $likertPoints = 5;

    $cur  = suites_rollup_window($pdo, (int)$a['id'], $questions, $likertPoints, $currentStart,  $nextStart);
    $prev = suites_rollup_window($pdo, (int)$a['id'], $questions, $likertPoints, $previousStart, $previousEnd);

    $perSurvey[] = [
        'id'       => (int)$a['id'],
        'title'    => (string)$a['title'],
        'current'  => $cur,
        'previous' => $prev,
    ];
}

// Cross-suite aggregates.
$respCur = 0;
$respPrev = 0;
$ssiSumCur = 0.0; $ssiNCur = 0;
$ssiSumPrev = 0.0; $ssiNPrev = 0;
foreach ($perSurvey as $p) {
    $respCur  += (int)$p['current']['n'];
    $respPrev += (int)$p['previous']['n'];
    if (is_int($p['current']['ssi_proxy']))  { $ssiSumCur  += $p['current']['ssi_proxy'];  $ssiNCur++; }
    if (is_int($p['previous']['ssi_proxy'])) { $ssiSumPrev += $p['previous']['ssi_proxy']; $ssiNPrev++; }
}
$avgSsiCur  = $ssiNCur  > 0 ? round($ssiSumCur  / $ssiNCur,  1) : null;
$avgSsiPrev = $ssiNPrev > 0 ? round($ssiSumPrev / $ssiNPrev, 1) : null;
$avgSsiDelta = ($avgSsiCur !== null && $avgSsiPrev !== null) ? round($avgSsiCur - $avgSsiPrev, 1) : null;

// Shared-construct trends. Keep constructs that appear in at least two
// surveys this quarter so the trend reads as cross-survey, not single-survey.
$constructAcc = [];
foreach ($perSurvey as $p) {
    foreach ($p['current']['constructs'] as $c) {
        $n = $c['name'];
        if (!isset($constructAcc[$n])) {
            $constructAcc[$n] = [
                'cur_sum'    => 0.0, 'cur_n'  => 0,
                'prev_sum'   => 0.0, 'prev_n' => 0,
                'survey_ids' => [],
            ];
        }
        $constructAcc[$n]['cur_sum'] += (float)$c['mean'];
        $constructAcc[$n]['cur_n']++;
        $constructAcc[$n]['survey_ids'][] = (int)$p['id'];
    }
    foreach ($p['previous']['constructs'] as $c) {
        $n = $c['name'];
        if (!isset($constructAcc[$n])) {
            $constructAcc[$n] = [
                'cur_sum'    => 0.0, 'cur_n'  => 0,
                'prev_sum'   => 0.0, 'prev_n' => 0,
                'survey_ids' => [],
            ];
        }
        $constructAcc[$n]['prev_sum'] += (float)$c['mean'];
        $constructAcc[$n]['prev_n']++;
    }
}
$constructTrends = [];
foreach ($constructAcc as $name => $acc) {
    if ($acc['cur_n'] < 2) continue;
    $curMean  = $acc['cur_n']  > 0 ? round($acc['cur_sum']  / $acc['cur_n'],  3) : null;
    $prevMean = $acc['prev_n'] > 0 ? round($acc['prev_sum'] / $acc['prev_n'], 3) : null;
    $delta    = ($curMean !== null && $prevMean !== null) ? round($curMean - $prevMean, 3) : null;
    $constructTrends[] = [
        'name'         => $name,
        'cur_mean'     => $curMean,
        'prev_mean'    => $prevMean,
        'delta'        => $delta,
        'survey_count' => $acc['cur_n'],
    ];
}
usort($constructTrends, function ($a, $b) {
    $da = $a['delta'] === null ? -1.0 : abs($a['delta']);
    $db = $b['delta'] === null ? -1.0 : abs($b['delta']);
    if ($da === $db) return ($b['cur_mean'] ?? 0) <=> ($a['cur_mean'] ?? 0);
    return $db <=> $da;
});

json_out([
    'ok'           => true,
    'suite_id'     => (int)$suite['id'],
    'suite_name'   => (string)$suite['name'],
    'period'       => $period,
    'survey_count' => count($perSurvey),
    'totals' => [
        'responses_current'  => $respCur,
        'responses_previous' => $respPrev,
        'responses_delta'    => $respCur - $respPrev,
    ],
    'avg_ssi' => [
        'current'  => $avgSsiCur,
        'previous' => $avgSsiPrev,
        'delta'    => $avgSsiDelta,
    ],
    'construct_trends' => $constructTrends,
    'per_survey'       => $perSurvey,
]);
