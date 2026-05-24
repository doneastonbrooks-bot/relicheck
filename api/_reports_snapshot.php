<?php
// Phase 148: shared report-snapshot builder.
// Used by /api/reports/create.php and /api/reports/regenerate.php (and the
// cron worker) to assemble the JSON blob a report stores. The snapshot is
// intentionally lightweight: counts, headline reliability/validity numbers,
// and per-item Likert means. Heavy work (factor structure, IRT) stays in
// the browser; the report shows the headline figures.
//
// The function returns an associative array; callers json_encode it into
// reports.snapshot_json.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

function reports_build_snapshot(int $surveyId): array
{
    $pdo = db();

    // ---- Survey row.
    $stmt = $pdo->prepare(
        'SELECT id, title, description, questions, settings, is_published
           FROM surveys WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $surveyId]);
    $survey = $stmt->fetch();
    if (!$survey) {
        return ['ok' => false, 'reason' => 'survey_not_found'];
    }
    $questions = json_decode((string)$survey['questions'], true) ?: [];
    $settings  = json_decode((string)$survey['settings'],  true) ?: [];

    // ---- Item taxonomy.
    $likertQs = [];
    $openQs   = [];
    $choiceQs = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $t = (string)($q['type'] ?? '');
        if ($t === 'section') continue;
        if ($t === 'likert')               $likertQs[] = $q;
        elseif ($t === 'open')             $openQs[]   = $q;
        elseif ($t === 'single' || $t === 'multi') $choiceQs[] = $q;
    }
    $likertPoints = 5;
    if (count($likertQs) > 0 && isset($likertQs[0]['likertPoints']) && is_numeric($likertQs[0]['likertPoints'])) {
        $likertPoints = max(2, min(11, (int)$likertQs[0]['likertPoints']));
    }

    // ---- Responses (final, not partial). The is_partial column was added
    // in Phase 41 and may not exist on older deployments; check first.
    $hasPartial = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM responses LIKE 'is_partial'");
        $hasPartial = (bool)$col->fetch();
    } catch (Throwable $_) { $hasPartial = false; }

    $sql = $hasPartial
        ? 'SELECT id, answers, is_partial, submitted_at FROM responses WHERE survey_id = :s'
        : 'SELECT id, answers, submitted_at FROM responses WHERE survey_id = :s';
    $rs = $pdo->prepare($sql);
    $rs->execute([':s' => $surveyId]);
    $rowsRaw = $rs->fetchAll();
    $totalRows = count($rowsRaw);
    $finals = [];
    foreach ($rowsRaw as $r) {
        if ($hasPartial && (int)($r['is_partial'] ?? 0) === 1) continue;
        $finals[] = $r;
    }
    $finalCount = count($finals);

    // ---- Per-respondent Likert vectors (reverse-scored when q.reverse).
    $matrix = []; // rows = respondents, cols = Likert items (in question order)
    foreach ($finals as $r) {
        $ans = json_decode((string)($r['answers'] ?? '{}'), true) ?: [];
        $row = [];
        $ok  = true;
        foreach ($likertQs as $q) {
            $v = $ans[$q['id']] ?? null;
            if (!is_numeric($v)) { $ok = false; break; }
            $vn = (int)$v;
            if ($vn < 1 || $vn > $likertPoints) { $ok = false; break; }
            if (!empty($q['reverse'])) $vn = ($likertPoints + 1) - $vn;
            $row[] = $vn;
        }
        if ($ok && count($row) === count($likertQs)) $matrix[] = $row;
    }
    $n = count($matrix);
    $k = count($likertQs);

    // ---- Cronbach's alpha.
    $alpha = null;
    if ($n >= 3 && $k >= 2) {
        $itemVars = [];
        $totals   = array_fill(0, $n, 0);
        for ($j = 0; $j < $k; $j++) {
            $col = [];
            for ($i = 0; $i < $n; $i++) {
                $col[] = $matrix[$i][$j];
                $totals[$i] += $matrix[$i][$j];
            }
            $mean = array_sum($col) / $n;
            $ss = 0.0;
            foreach ($col as $v) $ss += ($v - $mean) * ($v - $mean);
            $itemVars[] = $ss / max(1, $n - 1);
        }
        $totalMean = array_sum($totals) / $n;
        $ssT = 0.0;
        foreach ($totals as $v) $ssT += ($v - $totalMean) * ($v - $totalMean);
        $totalVar = $ssT / max(1, $n - 1);
        if ($totalVar > 0) {
            $alpha = ($k / max(1, $k - 1)) * (1 - (array_sum($itemVars) / $totalVar));
            $alpha = round(max(-1.0, min(1.0, $alpha)), 3);
        }
    }

    // ---- Per-item means + SDs.
    $items = [];
    for ($j = 0; $j < $k; $j++) {
        $col = [];
        for ($i = 0; $i < $n; $i++) $col[] = $matrix[$i][$j];
        $m  = $n > 0 ? array_sum($col) / $n : null;
        $sd = null;
        if ($n > 1 && $m !== null) {
            $ss = 0.0;
            foreach ($col as $v) $ss += ($v - $m) * ($v - $m);
            $sd = sqrt($ss / ($n - 1));
        }
        $items[] = [
            'id'      => (string)($likertQs[$j]['id'] ?? ''),
            'prompt'  => mb_substr((string)($likertQs[$j]['prompt'] ?? ''), 0, 200),
            'mean'    => $m  !== null ? round($m, 2)  : null,
            'sd'      => $sd !== null ? round($sd, 2) : null,
            'reverse' => !empty($likertQs[$j]['reverse']),
        ];
    }
    $avgItemMean = null;
    if (count($items) > 0) {
        $sum = 0.0; $c = 0;
        foreach ($items as $it) {
            if (is_numeric($it['mean'])) { $sum += $it['mean']; $c++; }
        }
        if ($c > 0) $avgItemMean = round($sum / $c, 2);
    }

    // ---- Completion stats.
    $partialCount = $totalRows - $finalCount;
    $completionPct = $totalRows > 0 ? (int)round(($finalCount / $totalRows) * 100) : 0;

    // ---- Open-ended engagement.
    $openCount = 0; $openWords = 0;
    if (count($openQs) > 0) {
        foreach ($finals as $r) {
            $ans = json_decode((string)($r['answers'] ?? '{}'), true) ?: [];
            foreach ($openQs as $q) {
                $v = $ans[$q['id']] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $openCount++;
                    $openWords += count(preg_split('/\s+/', trim($v)) ?: []);
                }
            }
        }
    }

    // ---- SSI (lightweight: reliability + response + open-end only).
    $ssi = null;
    $ssiLabel = '';
    if ($n >= 3 && $k >= 2 && $alpha !== null) {
        // Reliability: 25 pts mapped to alpha tiers.
        $relPts = $alpha >= 0.90 ? 25 : ($alpha >= 0.80 ? 22 : ($alpha >= 0.70 ? 18 : ($alpha >= 0.60 ? 14 : 8)));
        // Response: 15 pts mapped to n.
        $resPts = $n >= 200 ? 15 : ($n >= 100 ? 13 : ($n >= 50 ? 11 : ($n >= 20 ? 8 : ($n >= 10 ? 5 : 2))));
        // Completion: 15 pts mapped to completion %.
        $compPts = $completionPct >= 95 ? 15 : ($completionPct >= 85 ? 13 : ($completionPct >= 70 ? 10 : ($completionPct >= 50 ? 7 : 3)));
        // Open-ended: 10 pts if any qualitative engagement.
        $openPts = count($openQs) === 0 ? 8 : ($openCount === 0 ? 2 : ($openCount >= 20 ? 10 : 6));
        // Item quality: 20 pts, ding for items with very low SD (likely straight-lining).
        $itemPts = 20;
        foreach ($items as $it) {
            if (is_numeric($it['sd']) && $it['sd'] < 0.30) $itemPts = max(8, $itemPts - 3);
        }
        // Factor placeholder: 15 pts. Without server-side factor analysis we
        // give a moderate baseline; the in-app SSI is the canonical score.
        $facPts = 12;
        $ssi = (int)round($relPts + $resPts + $compPts + $openPts + $itemPts + $facPts);
        $ssi = max(0, min(100, $ssi));
        $ssiLabel = $ssi >= 90 ? 'excellent'
                  : ($ssi >= 80 ? 'strong'
                  : ($ssi >= 70 ? 'usable'
                  : ($ssi >= 60 ? 'needs_strengthening' : 'weak')));
    }

    return [
        'ok'             => true,
        'generated_at'   => date('c'),
        'survey' => [
            'id'              => (int)$survey['id'],
            'title'           => (string)$survey['title'],
            'description'     => (string)($survey['description'] ?? ''),
            'purpose'         => (string)($settings['purpose'] ?? ''),
            'is_published'    => (int)$survey['is_published'],
        ],
        'counts' => [
            'total_items'   => count($questions),
            'likert_items'  => $k,
            'open_items'    => count($openQs),
            'choice_items'  => count($choiceQs),
            'total_rows'    => $totalRows,
            'final_rows'    => $finalCount,
            'partial_rows'  => $partialCount,
            'completion_pct'=> $completionPct,
        ],
        'reliability' => [
            'n'             => $n,
            'k'             => $k,
            'alpha'         => $alpha,
            'avg_item_mean' => $avgItemMean,
        ],
        'items' => $items,
        'open_ended' => [
            'answered'   => $openCount,
            'total_words'=> $openWords,
            'avg_words'  => $openCount > 0 ? (int)round($openWords / $openCount) : 0,
        ],
        'ssi' => [
            'score'  => $ssi,
            'status' => $ssiLabel,
        ],
    ];
}
