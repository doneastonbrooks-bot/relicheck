<?php
// POST /api/ai/chat-data.php
// Body: {
//   "survey_id": <int>,
//   "question":  "<plain English question from the user>",
//   "history":   [ { "role": "user"|"assistant", "content": "..." }, ... ]   // optional
// }
//
// Loads the user's responses, computes a compact analytics digest, sends both
// (plus the chat history) to Claude, and returns the assistant's reply along
// with an optional chart spec the frontend can render inline.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 60 chat turns per user per hour. Each turn is bounded in size by the digest.
check_rate_limit('ai_chat:user:' . (int)$user['id'], 60, 3600);

$body      = read_json_body();
$surveyId  = (int)($body['survey_id']  ?? 0);
$datasetId = (int)($body['dataset_id'] ?? 0);
$question  = clean_string((string)($body['question'] ?? ''), 800);
$history   = is_array($body['history'] ?? null) ? $body['history'] : [];

if ($surveyId <= 0 && $datasetId <= 0) fail('bad_id', 'Missing or invalid survey_id / dataset_id.');
if ($question === '') fail('bad_question', 'Type a question first.');

$pdo = db();

if ($surveyId > 0) {
    // ----- Survey mode -----
    $stmt = $pdo->prepare('SELECT owner_id, title, settings, questions FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $surveyId]);
    $row = $stmt->fetch();
    if (!$row)                                       fail('not_found', 'Survey not found.', 404);
    if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

    $title     = (string)$row['title'];
    $settings  = json_decode((string)$row['settings'],  true) ?: [];
    $questions = json_decode((string)$row['questions'], true) ?: [];

    $rstmt = $pdo->prepare('SELECT submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC');
    $rstmt->execute([':sid' => $surveyId]);
    $responses = [];
    while ($r = $rstmt->fetch()) {
        $a = json_decode((string)$r['answers'], true);
        if (!is_array($a)) continue;
        $responses[] = ['submitted_at' => (string)$r['submitted_at'], 'answers' => $a];
    }

    if (count($responses) === 0) {
        fail('no_responses', 'No responses yet. Share the survey link first, then come back.');
    }
    $digest = build_digest($questions, $responses, $settings);

} else {
    // ----- Dataset mode -----
    $stmt = $pdo->prepare('SELECT owner_id, title, column_meta, settings, data FROM datasets WHERE id = :id');
    $stmt->execute([':id' => $datasetId]);
    $row = $stmt->fetch();
    if (!$row)                                       fail('not_found', 'Dataset not found.', 404);
    if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

    $title      = (string)$row['title'];
    $settings   = json_decode((string)$row['settings'],    true) ?: [];
    $columnMeta = json_decode((string)$row['column_meta'], true) ?: [];
    $data       = json_decode((string)$row['data'],        true) ?: [];

    if (count($data) === 0) {
        fail('no_responses', 'This dataset has no rows.');
    }
    $digest = build_dataset_digest($columnMeta, $data, $settings);
}

// ---------------- Compose chat messages ----------------

$system = <<<SYS
You are a survey-data analyst. The user has a survey with responses already collected, and you have a JSON digest of those responses (per-item descriptives, frequencies, correlations, and reliability where applicable). The user will ask plain-English questions about the data.

Your job:
1. Answer in clear, plain English. Cite specific numbers from the digest. Keep the answer to 2-5 sentences unless the user asks for more detail.
2. Never invent numbers. Only cite what is in the digest.
3. If the question is about a comparison, distribution, or trend that benefits from a chart, return a "chart" object alongside the answer. If not, omit "chart" or set it to null.
4. If the user asks something the digest cannot answer (e.g., demographics that were not collected, comparisons against external benchmarks), say so clearly and suggest what data would be needed.
5. If the user asks a vague or ambiguous question, ask one short clarifying question instead of guessing.

Chart spec (only when useful):
- "type" must be one of: "bar", "line", "pie", or "table".
- "title": short title for the chart.
- "labels": array of category labels (x-axis for bar/line, slice labels for pie, column 1 for table).
- "values": array of numbers (one per label) for bar/line/pie. Must be the same length as "labels".
- "x_label" and "y_label": optional axis labels (bar/line only).
- For "table", instead of "values" use "rows": an array of arrays. Also provide "columns": an array of column header strings.
- Keep charts simple: 12 or fewer labels for bar/line, 6 or fewer slices for pie.

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "answer": "<the plain-English answer>",
  "chart":  null | {
    "type": "bar"|"line"|"pie"|"table",
    "title": "...",
    "labels":  ["..."],          // for bar/line/pie
    "values":  [number, ...],    // for bar/line/pie
    "x_label": "...",            // optional
    "y_label": "...",            // optional
    "columns": ["..."],          // for table only
    "rows":    [["..."]]         // for table only
  }
}

Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$digestJson = json_encode($digest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Build the message list. The digest is included as the first user turn so
// the model has it in context for follow-up questions too.
$messages = [];
$messages[] = [
    'role'    => 'user',
    'content' => "Survey: " . ($title !== '' ? $title : '(untitled)') . "\n\nDigest:\n" . $digestJson,
];
$messages[] = [
    'role'    => 'assistant',
    'content' => '{"answer":"Got it. Ask me anything about this survey\'s responses.","chart":null}',
];

// Trim history to the last 6 turns to keep tokens bounded.
$history = array_slice($history, -6);
foreach ($history as $h) {
    if (!is_array($h)) continue;
    $r = (string)($h['role']    ?? '');
    $c = (string)($h['content'] ?? '');
    if ($r !== 'user' && $r !== 'assistant') continue;
    if ($c === '') continue;
    $messages[] = ['role' => $r, 'content' => clean_string($c, 4000)];
}

$messages[] = ['role' => 'user', 'content' => $question];

$resp = ai_complete($system, $messages, 1500);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['answer'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$answer = clean_string((string)$parsed['answer'], 4000);
$chart  = sanitize_chart($parsed['chart'] ?? null);

json_out([
    'ok'     => true,
    'answer' => $answer,
    'chart'  => $chart,
    'model'  => ai_config()['model'],
]);

// ===================== helpers =====================

function build_digest(array $questions, array $responses, array $settings): array
{
    $n = count($responses);
    $defaultPoints = (int)($settings['likertPoints'] ?? 5);

    $items = [];
    $likertCols = []; // column id -> values for correlation

    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $qid = (string)($q['id']     ?? '');
        $type = (string)($q['type']  ?? '');
        $prompt = (string)($q['prompt'] ?? '');
        if ($qid === '' || $type === '') continue;

        $entry = [
            'id'     => $qid,
            'type'   => $type,
            'prompt' => $prompt,
        ];

        if ($type === 'likert') {
            $points = (int)($q['likertPoints'] ?? $defaultPoints);
            $reverse = !empty($q['reverse']);
            $vals = [];
            foreach ($responses as $r) {
                $v = $r['answers'][$qid] ?? null;
                if (!is_int($v) && !is_numeric($v)) continue;
                $v = (int)$v;
                if ($v < 1 || $v > $points) continue;
                $scored = $reverse ? ($points + 1 - $v) : $v;
                $vals[] = $scored;
            }
            $entry['n']      = count($vals);
            $entry['mean']   = round(simple_mean($vals), 3);
            $entry['sd']     = round(simple_sd($vals),   3);
            $entry['min']    = $vals ? min($vals) : null;
            $entry['max']    = $vals ? max($vals) : null;
            $entry['scale']  = $points;
            $entry['reverse'] = $reverse;
            $hist = array_fill(1, $points, 0);
            foreach ($vals as $v) $hist[$v]++;
            $entry['histogram'] = $hist;
            $likertCols[$qid] = $vals;

        } elseif ($type === 'single') {
            $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
            $counts = array_fill(0, count($opts), 0);
            foreach ($responses as $r) {
                $v = $r['answers'][$qid] ?? null;
                if (is_int($v) && $v >= 0 && $v < count($opts)) $counts[$v]++;
            }
            $entry['n'] = array_sum($counts);
            $entry['options'] = array_map('strval', $opts);
            $entry['counts']  = $counts;

        } elseif ($type === 'multi') {
            $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
            $counts = array_fill(0, count($opts), 0);
            $respondents = 0;
            foreach ($responses as $r) {
                $v = $r['answers'][$qid] ?? null;
                if (is_array($v)) {
                    $picked = false;
                    foreach ($v as $i) {
                        $i = (int)$i;
                        if ($i >= 0 && $i < count($opts)) { $counts[$i]++; $picked = true; }
                    }
                    if ($picked) $respondents++;
                }
            }
            $entry['n']           = $respondents;
            $entry['options']     = array_map('strval', $opts);
            $entry['counts']      = $counts;
            $entry['respondents'] = $respondents;

        } elseif ($type === 'open') {
            $texts = [];
            foreach ($responses as $r) {
                $v = $r['answers'][$qid] ?? null;
                if (is_string($v) && trim($v) !== '') $texts[] = trim($v);
            }
            $entry['n'] = count($texts);
            // Don't include the full text; just length stats and a few samples.
            $lens = array_map('strlen', $texts);
            $entry['avg_length'] = $lens ? (int)round(array_sum($lens) / count($lens)) : 0;
            $entry['samples']    = array_slice($texts, 0, 3);
        }

        $items[] = $entry;
    }

    // Correlation matrix among Likert items where we have >=2 items and >=3 paired observations.
    $correlations = [];
    $likertIds = array_keys($likertCols);
    if (count($likertIds) >= 2) {
        // Need same-length pairs; restrict to responses where both items have a value.
        for ($i = 0; $i < count($likertIds); $i++) {
            for ($j = $i + 1; $j < count($likertIds); $j++) {
                $a = $likertCols[$likertIds[$i]];
                $b = $likertCols[$likertIds[$j]];
                $len = min(count($a), count($b));
                if ($len < 3) continue;
                $r = pearson(array_slice($a, 0, $len), array_slice($b, 0, $len));
                if ($r === null) continue;
                $correlations[] = [
                    'a' => $likertIds[$i],
                    'b' => $likertIds[$j],
                    'r' => round($r, 3),
                ];
            }
        }
    }

    // Cronbach's alpha across Likert items (uses all paired Likert values).
    $alpha = null;
    if (count($likertIds) >= 2) {
        $matrix = [];
        $minLen = PHP_INT_MAX;
        foreach ($likertIds as $id) $minLen = min($minLen, count($likertCols[$id]));
        if ($minLen >= 2) {
            for ($r = 0; $r < $minLen; $r++) {
                $row = [];
                foreach ($likertIds as $id) $row[] = $likertCols[$id][$r];
                $matrix[] = $row;
            }
            $alpha = cronbach_alpha($matrix);
        }
    }

    return [
        'response_count'  => $n,
        'items'           => $items,
        'cronbach_alpha'  => $alpha === null ? null : round($alpha, 3),
        'likert_correlations' => $correlations,
    ];
}

function build_dataset_digest(array $columnMeta, array $data, array $settings): array
{
    $defaultPoints = (int)($settings['likertPoints'] ?? 5);
    $items = [];
    $likertCols = []; // column index -> values

    foreach ($columnMeta as $colIdx => $col) {
        if (!is_array($col)) continue;
        $type = (string)($col['type'] ?? 'ignore');
        if ($type === 'ignore') continue;

        $name    = (string)($col['name'] ?? ('col_' . $colIdx));
        $reverse = !empty($col['reverse']);
        $entry = [
            'id'     => 'col_' . $colIdx,
            'type'   => $type,
            'prompt' => $name,
        ];

        if ($type === 'likert') {
            $points = $defaultPoints;
            $vals = [];
            foreach ($data as $row) {
                if (!is_array($row)) continue;
                $cell = $row[$colIdx] ?? null;
                if (!is_numeric($cell)) continue;
                $v = (int)$cell;
                if ($v < 1 || $v > $points) continue;
                $scored = $reverse ? ($points + 1 - $v) : $v;
                $vals[] = $scored;
            }
            $entry['n']       = count($vals);
            $entry['mean']    = round(simple_mean($vals), 3);
            $entry['sd']      = round(simple_sd($vals),   3);
            $entry['min']     = $vals ? min($vals) : null;
            $entry['max']     = $vals ? max($vals) : null;
            $entry['scale']   = $points;
            $entry['reverse'] = $reverse;
            $hist = array_fill(1, $points, 0);
            foreach ($vals as $v) $hist[$v]++;
            $entry['histogram'] = $hist;
            $likertCols[$colIdx] = $vals;

        } elseif ($type === 'single' || $type === 'multi') {
            $opts = is_array($col['options'] ?? null) ? $col['options'] : [];
            $counts = array_fill(0, max(1, count($opts)), 0);
            // For datasets, the cell is typically a string label or comma-separated for multi.
            $respondents = 0;
            foreach ($data as $row) {
                if (!is_array($row)) continue;
                $cell = $row[$colIdx] ?? null;
                if ($cell === null || $cell === '') continue;
                if ($type === 'multi') {
                    $picks = is_array($cell) ? $cell : array_filter(array_map('trim', explode(';', (string)$cell)));
                    $found = false;
                    foreach ($picks as $p) {
                        $i = array_search($p, $opts, true);
                        if ($i !== false) { $counts[$i]++; $found = true; }
                    }
                    if ($found) $respondents++;
                } else {
                    $i = array_search((string)$cell, $opts, true);
                    if ($i !== false) { $counts[$i]++; $respondents++; }
                }
            }
            $entry['n']           = $respondents;
            $entry['options']     = array_map('strval', $opts);
            $entry['counts']      = $counts;
            if ($type === 'multi') $entry['respondents'] = $respondents;

        } elseif ($type === 'open') {
            $texts = [];
            foreach ($data as $row) {
                if (!is_array($row)) continue;
                $cell = $row[$colIdx] ?? null;
                if (is_string($cell) && trim($cell) !== '') $texts[] = trim($cell);
            }
            $entry['n']          = count($texts);
            $lens                = array_map('strlen', $texts);
            $entry['avg_length'] = $lens ? (int)round(array_sum($lens) / count($lens)) : 0;
            $entry['samples']    = array_slice($texts, 0, 3);
        }

        $items[] = $entry;
    }

    // Likert correlations
    $correlations = [];
    $likertIds = array_keys($likertCols);
    if (count($likertIds) >= 2) {
        for ($i = 0; $i < count($likertIds); $i++) {
            for ($j = $i + 1; $j < count($likertIds); $j++) {
                $a = $likertCols[$likertIds[$i]];
                $b = $likertCols[$likertIds[$j]];
                $len = min(count($a), count($b));
                if ($len < 3) continue;
                $r = pearson(array_slice($a, 0, $len), array_slice($b, 0, $len));
                if ($r === null) continue;
                $correlations[] = [
                    'a' => 'col_' . $likertIds[$i],
                    'b' => 'col_' . $likertIds[$j],
                    'r' => round($r, 3),
                ];
            }
        }
    }

    // Cronbach's alpha
    $alpha = null;
    if (count($likertIds) >= 2) {
        $minLen = PHP_INT_MAX;
        foreach ($likertIds as $id) $minLen = min($minLen, count($likertCols[$id]));
        if ($minLen >= 2) {
            $matrix = [];
            for ($r = 0; $r < $minLen; $r++) {
                $row = [];
                foreach ($likertIds as $id) $row[] = $likertCols[$id][$r];
                $matrix[] = $row;
            }
            $alpha = cronbach_alpha($matrix);
        }
    }

    return [
        'response_count'      => count($data),
        'items'               => $items,
        'cronbach_alpha'      => $alpha === null ? null : round($alpha, 3),
        'likert_correlations' => $correlations,
    ];
}

function simple_mean(array $xs): float
{
    if (count($xs) === 0) return 0.0;
    return array_sum($xs) / count($xs);
}

function simple_sd(array $xs): float
{
    $n = count($xs);
    if ($n < 2) return 0.0;
    $m = simple_mean($xs);
    $sum = 0.0;
    foreach ($xs as $x) $sum += ($x - $m) ** 2;
    return sqrt($sum / ($n - 1));
}

function pearson(array $a, array $b): ?float
{
    $n = count($a);
    if ($n < 3 || count($b) !== $n) return null;
    $mA = simple_mean($a);
    $mB = simple_mean($b);
    $num = 0.0; $denA = 0.0; $denB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dA = $a[$i] - $mA;
        $dB = $b[$i] - $mB;
        $num  += $dA * $dB;
        $denA += $dA * $dA;
        $denB += $dB * $dB;
    }
    if ($denA == 0 || $denB == 0) return null;
    return $num / sqrt($denA * $denB);
}

function cronbach_alpha(array $matrix): ?float
{
    $n = count($matrix);
    if ($n < 2) return null;
    $k = count($matrix[0]);
    if ($k < 2) return null;
    $itemVar = 0.0;
    for ($j = 0; $j < $k; $j++) {
        $col = array_column($matrix, $j);
        $itemVar += simple_sd($col) ** 2;
    }
    $totals = array_map(function ($r) { return array_sum($r); }, $matrix);
    $totalVar = simple_sd($totals) ** 2;
    if ($totalVar == 0) return null;
    return ($k / ($k - 1)) * (1 - $itemVar / $totalVar);
}

function sanitize_chart($chart): ?array
{
    if (!is_array($chart)) return null;
    $type = (string)($chart['type'] ?? '');
    if (!in_array($type, ['bar','line','pie','table'], true)) return null;
    $out = ['type' => $type, 'title' => clean_string((string)($chart['title'] ?? ''), 200)];

    if ($type === 'table') {
        $cols = is_array($chart['columns'] ?? null) ? $chart['columns'] : [];
        $rows = is_array($chart['rows']    ?? null) ? $chart['rows']    : [];
        $out['columns'] = array_map(function ($c) { return clean_string((string)$c, 80); }, array_slice($cols, 0, 8));
        $cleanRows = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) continue;
            $cleanRows[] = array_map(function ($c) { return clean_string((string)$c, 200); }, array_slice($row, 0, 8));
        }
        $out['rows'] = $cleanRows;
        return $out;
    }

    $labels = is_array($chart['labels'] ?? null) ? $chart['labels'] : [];
    $values = is_array($chart['values'] ?? null) ? $chart['values'] : [];
    if (count($labels) === 0 || count($labels) !== count($values)) return null;
    $maxLabels = ($type === 'pie') ? 6 : 12;
    $labels = array_slice($labels, 0, $maxLabels);
    $values = array_slice($values, 0, $maxLabels);

    $out['labels'] = array_map(function ($l) { return clean_string((string)$l, 60); }, $labels);
    $out['values'] = array_map(function ($v) { return is_numeric($v) ? round((float)$v, 3) : 0; }, $values);
    if (isset($chart['x_label'])) $out['x_label'] = clean_string((string)$chart['x_label'], 60);
    if (isset($chart['y_label'])) $out['y_label'] = clean_string((string)$chart['y_label'], 60);
    return $out;
}
