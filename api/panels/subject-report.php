<?php
// GET /api/panels/subject-report.php?panel_id=<id>&subject_id=<id>
//
// Aggregates every response submitted on the panel's survey whose channel
// matches the subject's 360 wave label. Returns:
//
//   - completion counts (overall, by relationship)
//   - per-question mean + sd across all raters
//   - per-question mean by relationship bucket
//   - self-vs-others gap when self_assessment is enabled and a self response
//     exists
//   - top 3 highest and bottom 3 lowest items on the overall mean
//
// Numeric aggregation is applied only to Likert / scale / numeric question
// types whose answers are numbers; open-ended text is returned as-is in a
// separate bag so the caller can themed-summarize it elsewhere.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_panels.php';

require_method('GET');
$user = require_auth();

$panelId   = (int)($_GET['panel_id']   ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
if ($panelId   <= 0) fail('bad_id', 'Missing panel_id.', 400);
if ($subjectId <= 0) fail('bad_id', 'Missing subject_id.', 400);

$panel = panels_require_owned($panelId, (int)$user['id']);

$pdo = db();

// Subject must belong to this panel.
$sStmt = $pdo->prepare(
    'SELECT id, name, email, title, department
       FROM survey_360_subjects
      WHERE id = :sid AND panel_id = :pid LIMIT 1'
);
$sStmt->execute([':sid' => $subjectId, ':pid' => $panelId]);
$subject = $sStmt->fetch();
if (!$subject) fail('not_found', 'Subject not found on this panel.', 404);

// Pull the survey questions to know which to aggregate as numeric.
$qStmt = $pdo->prepare('SELECT questions FROM surveys WHERE id = :id LIMIT 1');
$qStmt->execute([':id' => (int)$panel['survey_id']]);
$qRow = $qStmt->fetch();
$questions = [];
if ($qRow && !empty($qRow['questions'])) {
    $decoded = json_decode((string)$qRow['questions'], true);
    if (is_array($decoded)) $questions = $decoded;
}

// Detect Phase 16 arm_id column on responses just to be defensive on SELECT *.
// We don't use it, we just need a clean column list.
$rStmt = $pdo->prepare(
    'SELECT id, submitted_at, answers, channel
       FROM responses
      WHERE survey_id = :sid
        AND channel LIKE :pat
      ORDER BY submitted_at ASC'
);
$rStmt->execute([
    ':sid' => (int)$panel['survey_id'],
    ':pat' => '360-S' . $subjectId . '-R%',
]);
$rows = $rStmt->fetchAll();

// Build numeric question lookup.
$numericQs = []; // qid => { id, text, type }
$openQs    = []; // qid => { id, text, type }
foreach ($questions as $q) {
    $qid  = (string)($q['id']   ?? '');
    $type = (string)($q['type'] ?? '');
    $text = (string)($q['text'] ?? ($q['prompt'] ?? ''));
    if ($qid === '') continue;
    // Likert/scale/numeric all carry numeric answers per ReliCheck convention.
    if (in_array($type, ['likert','scale','numeric','rating','nps'], true)) {
        $numericQs[$qid] = ['id' => $qid, 'text' => $text, 'type' => $type];
    } elseif (in_array($type, ['text','longtext','open','open_ended'], true)) {
        $openQs[$qid] = ['id' => $qid, 'text' => $text, 'type' => $type];
    }
}

// Accumulators.
$relBuckets = ['self' => [], 'manager' => [], 'peer' => [], 'direct_report' => [], 'external' => []];
$byQuestion = []; // qid => { values: [], byRel: { rel => [] } }
$openByQuestion = []; // qid => [ {rel, text, submitted_at} ]
$completedTotal = 0;
$completedByRel = ['self' => 0, 'manager' => 0, 'peer' => 0, 'direct_report' => 0, 'external' => 0];

foreach ($rows as $r) {
    $parsed = panels_parse_channel((string)$r['channel']);
    if (!$parsed) continue;
    if ((int)$parsed['subject_id'] !== $subjectId) continue;
    $rel = $parsed['relationship'];
    $completedTotal++;
    if (isset($completedByRel[$rel])) $completedByRel[$rel]++;

    $answers = json_decode((string)$r['answers'], true);
    if (!is_array($answers)) continue;

    foreach ($numericQs as $qid => $_) {
        if (!array_key_exists($qid, $answers)) continue;
        $val = $answers[$qid];
        if (!is_numeric($val)) continue;
        if (!isset($byQuestion[$qid])) {
            $byQuestion[$qid] = ['values' => [], 'byRel' => []];
        }
        $byQuestion[$qid]['values'][] = (float)$val;
        if (!isset($byQuestion[$qid]['byRel'][$rel])) $byQuestion[$qid]['byRel'][$rel] = [];
        $byQuestion[$qid]['byRel'][$rel][] = (float)$val;
    }

    foreach ($openQs as $qid => $_) {
        if (!array_key_exists($qid, $answers)) continue;
        $txt = trim((string)$answers[$qid]);
        if ($txt === '') continue;
        if (!isset($openByQuestion[$qid])) $openByQuestion[$qid] = [];
        $openByQuestion[$qid][] = [
            'rel'          => $rel,
            'text'         => $txt,
            'submitted_at' => (string)$r['submitted_at'],
        ];
    }
}

// Compute means / sds.
function _p129_mean(array $vals): ?float {
    $n = count($vals); if ($n === 0) return null;
    return array_sum($vals) / $n;
}
function _p129_sd(array $vals): ?float {
    $n = count($vals); if ($n < 2) return null;
    $m = array_sum($vals) / $n;
    $sq = 0.0; foreach ($vals as $v) $sq += ($v - $m) * ($v - $m);
    return sqrt($sq / ($n - 1));
}

$selfAnswers = []; // qid => self mean for gap calc
$qSummaries = [];
foreach ($numericQs as $qid => $meta) {
    $bag = $byQuestion[$qid] ?? null;
    if (!$bag) continue;
    $values = $bag['values'];
    $relMeans = [];
    foreach ($bag['byRel'] as $rel => $vs) {
        $relMeans[$rel] = [
            'n'    => count($vs),
            'mean' => _p129_mean($vs),
            'sd'   => _p129_sd($vs),
        ];
        if ($rel === 'self') {
            $selfAnswers[$qid] = _p129_mean($vs);
        }
    }
    $mean = _p129_mean($values);
    $sd   = _p129_sd($values);
    $othersValues = [];
    foreach ($bag['byRel'] as $rel => $vs) {
        if ($rel === 'self') continue;
        foreach ($vs as $v) $othersValues[] = $v;
    }
    $othersMean = _p129_mean($othersValues);
    $gap = null;
    if (isset($selfAnswers[$qid]) && $othersMean !== null && $selfAnswers[$qid] !== null) {
        $gap = $selfAnswers[$qid] - $othersMean;
    }
    $qSummaries[] = [
        'id'         => $qid,
        'text'       => $meta['text'],
        'type'       => $meta['type'],
        'n'          => count($values),
        'mean'       => $mean,
        'sd'         => $sd,
        'self_mean'  => $selfAnswers[$qid] ?? null,
        'others_mean'=> $othersMean,
        'self_minus_others' => $gap,
        'by_relationship'   => $relMeans,
    ];
}

// Sort once for top/bottom lists. Stable on mean asc.
$sortable = array_values(array_filter($qSummaries, function ($s) { return $s['mean'] !== null && $s['n'] > 0; }));
usort($sortable, function ($a, $b) {
    if ($a['mean'] === $b['mean']) return 0;
    return ($a['mean'] < $b['mean']) ? -1 : 1;
});
$bottom3 = array_slice($sortable, 0, 3);
$top3    = array_slice(array_reverse($sortable), 0, 3);

// Open-ended bag, grouped by question.
$openOut = [];
foreach ($openByQuestion as $qid => $list) {
    $openOut[] = [
        'id'       => $qid,
        'text'     => $openQs[$qid]['text'] ?? '',
        'count'    => count($list),
        'comments' => $list,
    ];
}

json_out([
    'ok' => true,
    'panel' => [
        'id'                   => (int)$panel['id'],
        'name'                 => (string)$panel['name'],
        'survey_id'            => (int)$panel['survey_id'],
        'survey_title'         => (string)$panel['survey_title'],
        'self_assessment'      => (int)$panel['self_assessment'] === 1,
        'confidentiality_mode' => (string)$panel['confidentiality_mode'],
    ],
    'subject' => [
        'id'         => (int)$subject['id'],
        'name'       => (string)$subject['name'],
        'email'      => $subject['email']      !== null ? (string)$subject['email']      : '',
        'title'      => $subject['title']      !== null ? (string)$subject['title']      : '',
        'department' => $subject['department'] !== null ? (string)$subject['department'] : '',
    ],
    'completion' => [
        'total'           => $completedTotal,
        'by_relationship' => $completedByRel,
    ],
    'questions'   => $qSummaries,
    'top3'        => $top3,
    'bottom3'     => $bottom3,
    'open_ended'  => $openOut,
]);
