<?php
// GET  /api/mm/matrix.php?project_id=N    return saved evidence matrix rows
// POST /api/mm/matrix.php                  rebuild matrix from current state
// Body for POST: { project_id }
//
// Evidence Matrix joint display. Reads categories, sentiment, alignment
// results, and any numeric summary, then asks the AI to compose a small
// joint-display table.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    $stmt = $pdo->prepare(
        'SELECT * FROM mm_evidence_matrices WHERE project_id = :p ORDER BY position ASC, id ASC'
    );
    $stmt->execute([':p' => $projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'                 => (int)$r['id'],
            'measure'            => (string)$r['measure'],
            'quant_result'       => (string)($r['quant_result'] ?? ''),
            'theme_evidence'     => (string)($r['theme_evidence'] ?? ''),
            'sentiment'          => (string)($r['sentiment'] ?? ''),
            'interpretation'     => (string)($r['interpretation'] ?? ''),
            'recommended_action' => (string)($r['recommended_action'] ?? ''),
        ];
    }
    json_out(['ok' => true, 'rows' => $out]);
}

check_rate_limit('mm_matrix:user:' . $uid, 30, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// Gather inputs.
$catStmt = $pdo->prepare(
    'SELECT c.name, c.description,
            (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS n
     FROM mm_theme_categories c WHERE c.project_id = :p ORDER BY n DESC LIMIT 10'
);
$catStmt->execute([':p' => $projectId]);
$cats = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$alignStmt = $pdo->prepare(
    'SELECT quant_label, quant_value, qual_evidence, alignment, interpretation
     FROM mm_evidence_alignment_results WHERE project_id = :p LIMIT 12'
);
$alignStmt->execute([':p' => $projectId]);
$aligns = $alignStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sentStmt = $pdo->prepare(
    'SELECT sentiment, COUNT(*) AS n FROM mm_sentiment_scores WHERE project_id = :p GROUP BY sentiment'
);
$sentStmt->execute([':p' => $projectId]);
$sentDist = [];
foreach ($sentStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sentDist[(string)$s['sentiment']] = (int)$s['n'];
}

if (count($cats) === 0 && count($aligns) === 0) {
    fail('mm_no_inputs', 'Run the builder or alignment check before generating a matrix.');
}

$prompt  = "Top categories and their counts:\n";
foreach ($cats as $c) $prompt .= '- ' . $c['name'] . ' (' . (int)$c['n'] . ')' . ($c['description'] ? ': ' . $c['description'] : '') . "\n";
$prompt .= "\nAlignment findings:\n";
foreach ($aligns as $a) $prompt .= '- ' . $a['quant_label'] . ': ' . $a['quant_value'] . ' / ' . $a['qual_evidence'] . ' (' . $a['alignment'] . ")\n";
$prompt .= "\nSentiment distribution: " . json_encode($sentDist) . "\n";

$system = <<<SYS
You are a mixed-methods analyst. Compose a joint display table that combines numbers, qualitative evidence, sentiment, interpretation, and a recommended action. Aim for 3-6 rows.

Return JSON only:

{
  "rows": [
    {
      "measure":             "<short measure label>",
      "quant_result":        "<short number or pattern>",
      "theme_evidence":      "<short theme phrase>",
      "sentiment":           "<short sentiment summary>",
      "interpretation":      "<one sentence>",
      "recommended_action":  "<one sentence>"
    }
  ],
  "summary": "<one sentence overall>"
}

Do not wrap the JSON in markdown fences.
SYS;

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $prompt],
], 2500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['rows']) || !is_array($parsed['rows'])) {
    fail('ai_parse_failed', 'AI did not return a usable response. Try again.', 502);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM mm_evidence_matrices WHERE project_id = :p')->execute([':p' => $projectId]);
    $ins = $pdo->prepare(
        'INSERT INTO mm_evidence_matrices
         (project_id, measure, quant_result, theme_evidence, sentiment, interpretation, recommended_action, position)
         VALUES (:p, :m, :q, :te, :s, :i, :ra, :pos)'
    );
    $rowsOut = [];
    foreach ($parsed['rows'] as $i => $r) {
        if (!is_array($r)) continue;
        $row = [
            'measure'            => clean_string((string)($r['measure']            ?? ''), 200),
            'quant_result'       => clean_string((string)($r['quant_result']       ?? ''), 120),
            'theme_evidence'     => clean_string((string)($r['theme_evidence']     ?? ''), 600),
            'sentiment'          => clean_string((string)($r['sentiment']          ?? ''), 60),
            'interpretation'    => clean_string((string)($r['interpretation']     ?? ''), 1200),
            'recommended_action' => clean_string((string)($r['recommended_action'] ?? ''), 1200),
        ];
        if ($row['measure'] === '') continue;
        $ins->execute([
            ':p'   => $projectId,
            ':m'   => $row['measure'],
            ':q'   => $row['quant_result'],
            ':te'  => $row['theme_evidence'],
            ':s'   => $row['sentiment'],
            ':i'   => $row['interpretation'],
            ':ra'  => $row['recommended_action'],
            ':pos' => $i + 1,
        ]);
        $rowsOut[] = $row;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_matrix_failed', 'Could not save matrix: ' . $e->getMessage(), 500);
}

json_out([
    'ok'      => true,
    'rows'    => $rowsOut,
    'summary' => clean_string((string)($parsed['summary'] ?? ''), 600),
    'model'   => ai_config()['model'],
]);
