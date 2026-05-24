<?php
// Phase 89: Key Drivers digest snapshot.
//
// Computes a lightweight server-side Key Drivers snapshot for the digest
// email. The full Key Drivers tab in app.html uses Johnson's Relative
// Weights and standardized regression betas (heavy math, runs in browser).
// The email is a teaser, so we only need bivariate Pearson correlations
// between each candidate driver (Likert construct subscale or composite)
// and the outcome.
//
// Returns:
//   {
//     ok: bool,
//     outcome_label: string,
//     top_drivers_html: string,   // pre-rendered <ul>...</ul>
//     top_drivers_text: string,   // plain-text version with bullets
//     n: int                      // complete-case count
//   }
//
// Returns ok=false with a 'reason' field when there is not enough data
// (no Likert items, fewer than 10 complete-case responses, or no
// constructs / composite to rank). The caller silently skips the email
// in that case.

declare(strict_types=1);

function keydrivers_snapshot(array $survey, array $responses): array
{
    $questions = is_array($survey['questions'] ?? null) ? $survey['questions'] : [];
    $likertQs  = [];
    foreach ($questions as $q) {
        if (is_array($q) && ($q['type'] ?? '') === 'likert') $likertQs[] = $q;
    }
    if (count($likertQs) < 2) return ['ok' => false, 'reason' => 'not_enough_likert_items'];
    if (count($responses)  < 10) return ['ok' => false, 'reason' => 'not_enough_responses'];

    // Build the per-respondent matrix of Likert answers, applying reverse-scoring.
    $likertPoints = (int)($survey['likertPoints'] ?? 5);
    $matrix = [];
    foreach ($responses as $r) {
        $answers = $r['answers'] ?? null;
        if (is_string($answers)) {
            $answers = json_decode($answers, true);
        }
        if (!is_array($answers)) continue;
        $row = [];
        $ok = true;
        foreach ($likertQs as $q) {
            $qid = (string)($q['id'] ?? '');
            $v   = $answers[$qid] ?? null;
            if (!is_numeric($v)) { $ok = false; break; }
            $val = (float)$v;
            if (!empty($q['reverse'])) {
                $val = ($likertPoints + 1) - $val;
            }
            $row[] = $val;
        }
        if ($ok) $matrix[] = $row;
    }
    $n = count($matrix);
    if ($n < 10) return ['ok' => false, 'reason' => 'not_enough_complete_cases'];

    // Composite Likert mean per respondent is the default outcome for the
    // digest. The dashboard lets the user pick a different outcome, but the
    // email teases the most common one.
    $kAll = count($likertQs);
    $composite = [];
    for ($i = 0; $i < $n; $i++) {
        $s = 0.0;
        for ($j = 0; $j < $kAll; $j++) $s += $matrix[$i][$j];
        $composite[$i] = $s / $kAll;
    }

    // Build construct subscales. A construct subscale is the per-respondent
    // mean of the items tagged with that construct. Drivers are everything
    // except the composite itself (since correlating composite with composite
    // would always be 1.0).
    $byConstruct = [];
    foreach ($likertQs as $j => $q) {
        $c = isset($q['construct']) && is_string($q['construct']) ? trim($q['construct']) : '';
        if ($c === '') continue;
        if (!isset($byConstruct[$c])) $byConstruct[$c] = [];
        $byConstruct[$c][] = $j;
    }

    $drivers = [];
    foreach ($byConstruct as $cName => $colIdxs) {
        $vec = [];
        for ($i = 0; $i < $n; $i++) {
            $s = 0.0;
            foreach ($colIdxs as $j) $s += $matrix[$i][$j];
            $vec[$i] = $s / count($colIdxs);
        }
        $drivers[] = [
            'label' => $cName,
            'vec'   => $vec,
        ];
    }

    // If there are no constructs, fall back to per-item drivers. Use the
    // item prompt as the label.
    if (!count($drivers)) {
        for ($j = 0; $j < $kAll; $j++) {
            $vec = [];
            for ($i = 0; $i < $n; $i++) $vec[$i] = $matrix[$i][$j];
            $label = (string)($likertQs[$j]['prompt'] ?? ('Item ' . ($j + 1)));
            if (mb_strlen($label) > 60) $label = mb_substr($label, 0, 60) . '...';
            $drivers[] = ['label' => $label, 'vec' => $vec];
        }
    }
    if (!count($drivers)) return ['ok' => false, 'reason' => 'no_drivers'];

    // Pearson r per driver vs the composite outcome.
    $ranked = [];
    foreach ($drivers as $d) {
        $r = keydrivers_pearson($d['vec'], $composite);
        if ($r === null) continue;
        $ranked[] = ['label' => $d['label'], 'r' => $r];
    }
    if (!count($ranked)) return ['ok' => false, 'reason' => 'all_zero_variance'];

    usort($ranked, function ($a, $b) {
        return abs($b['r']) <=> abs($a['r']);
    });
    $top = array_slice($ranked, 0, 3);

    // Pre-render the HTML and plain-text driver list for the template.
    $html = '<ul style="margin:8px 0 16px;padding-left:20px;line-height:1.7;">';
    $text = '';
    $rank = 1;
    foreach ($top as $row) {
        $rStr = number_format($row['r'], 2);
        $sign = $row['r'] >= 0 ? 'positive' : 'negative';
        $label_h = htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8');
        $html .= '<li><strong>' . $label_h . '</strong> (r = ' . $rStr . ', ' . $sign . ')</li>';
        $text .= '  ' . $rank . '. ' . $row['label'] . '  (r = ' . $rStr . ', ' . $sign . ")\n";
        $rank++;
    }
    $html .= '</ul>';

    return [
        'ok'               => true,
        'outcome_label'    => 'Composite Likert mean',
        'top_drivers_html' => $html,
        'top_drivers_text' => rtrim($text, "\n"),
        'n'                => $n,
    ];
}

function keydrivers_pearson(array $xs, array $ys): ?float
{
    $n = min(count($xs), count($ys));
    if ($n < 2) return null;
    $mx = 0.0; $my = 0.0;
    for ($i = 0; $i < $n; $i++) { $mx += $xs[$i]; $my += $ys[$i]; }
    $mx /= $n; $my /= $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $xs[$i] - $mx;
        $dy = $ys[$i] - $my;
        $sxy += $dx * $dy;
        $sxx += $dx * $dx;
        $syy += $dy * $dy;
    }
    if ($sxx <= 0 || $syy <= 0) return null;
    return $sxy / sqrt($sxx * $syy);
}
