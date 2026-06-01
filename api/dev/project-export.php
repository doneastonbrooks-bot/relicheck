<?php
// GET /api/dev/project-export.php?project_id={id}[&format=csv]
// Authenticated, owner-gated. Streams a CSV of the project's stored public
// responses (one row per response session) for the owner to download.
//
// Phase 3F: export ONLY. No RSSI, no analysis, no scoring. The CSV NEVER
// includes the respondent IP hash or user agent, and carries no internal
// owner/project metadata beyond the project id needed to scope the file name.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// Owner check: 403 if the caller does not own this project, 404 if absent.
$project = sds_require_project($pdo, (int)$user['id'], $projectId);

// Column set = the project's input items in display order. Using the live item
// list (not just items that happen to have answers) keeps every response row
// aligned to the same columns. Section/instruction/page-break items take no
// input, so they are excluded.
$NON_INPUT = ['Section Text', 'Instructions', 'Page Break', 'Thank-you Message'];

$itStmt = $pdo->prepare(
    'SELECT id, type, prompt, options FROM survey_items
      WHERE project_id = :id ORDER BY position, id'
);
$itStmt->execute([':id' => $projectId]);

$columns = [];          // ordered list of item_id for the answer columns
$itemMeta = [];         // item_id => [type, options[], label]
foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
    if (in_array($it['type'], $NON_INPUT, true)) continue;
    $id   = (int)$it['id'];
    $opts = ($it['options'] !== null) ? json_decode((string)$it['options'], true) : null;
    $itemMeta[$id] = [
        'type'    => (string)$it['type'],
        'options' => is_array($opts) ? array_values($opts) : [],
        'label'   => trim((string)$it['prompt']),
    ];
    $columns[] = $id;
}

// Resolve a stored answer value to readable text (choice indexes -> option text).
// Mirrors api/dev/project-responses.php so the CSV matches the on-screen viewer.
$displayValue = function (?int $itemId, string $raw) use ($itemMeta): string {
    if ($itemId === null || !isset($itemMeta[$itemId])) return $raw;
    $opts = $itemMeta[$itemId]['options'];
    if (count($opts) === 0) return $raw;
    $choiceTypes = ['Multiple Choice', 'Single Choice', 'Dropdown', 'Checkboxes', 'Yes/No', 'True/False', 'NPS'];
    if (!in_array($itemMeta[$itemId]['type'], $choiceTypes, true)) return $raw;

    $resolve = function ($v) use ($opts) {
        if (!is_numeric($v)) return (string)$v;
        $i = (int)$v;
        return ($i >= 0 && $i < count($opts)) ? (string)$opts[$i] : (string)$v;
    };
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return implode(', ', array_map($resolve, $decoded));
    }
    return $resolve($raw);
};

// Sessions oldest-first so row numbers read naturally in the export.
$sStmt = $pdo->prepare(
    'SELECT id, submitted_at FROM survey_dev_response_sessions
      WHERE project_id = :id ORDER BY submitted_at ASC, id ASC'
);
$sStmt->execute([':id' => $projectId]);
$sessionRows = $sStmt->fetchAll(PDO::FETCH_ASSOC);

// Answers grouped by session, keyed by item_id for column alignment.
$aStmt = $pdo->prepare(
    'SELECT session_id, item_id, answer_value FROM survey_dev_answers
      WHERE project_id = :id ORDER BY id'
);
$aStmt->execute([':id' => $projectId]);
$bySession = [];
foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $sid    = (int)$a['session_id'];
    $itemId = $a['item_id'] !== null ? (int)$a['item_id'] : null;
    if ($itemId === null) continue;
    $raw    = $a['answer_value'] !== null ? (string)$a['answer_value'] : '';
    $bySession[$sid][$itemId] = $displayValue($itemId, $raw);
}

// De-duplicate header labels so two identically worded questions stay distinct
// columns (append " (2)", " (3)", ...). Falls back to a generic label if blank.
$usedLabels = [];
$headerLabels = [];
foreach ($columns as $id) {
    $base = $itemMeta[$id]['label'];
    if ($base === '') $base = 'Question ' . $id;
    // Flatten newlines/tabs so the header stays a single clean cell.
    $base = trim(preg_replace('/\s+/', ' ', $base));
    $label = $base;
    $n = 2;
    while (isset($usedLabels[$label])) { $label = $base . ' (' . $n . ')'; $n++; }
    $usedLabels[$label] = true;
    $headerLabels[$id] = $label;
}

// ── Stream the CSV ──────────────────────────────────────────────────────────
$fileName = 'responses-project-' . $projectId . '.csv';
if (!headers_sent()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store');
}

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel reads accented characters correctly.
fwrite($out, "\xEF\xBB\xBF");

// Header row: a Response number, the submission time, then one column per item.
$header = ['Response', 'Submitted at'];
foreach ($columns as $id) $header[] = $headerLabels[$id];
fputcsv($out, $header);

$rowNum = 0;
foreach ($sessionRows as $s) {
    $rowNum++;
    $sid = (int)$s['id'];
    $row = [$rowNum, (string)$s['submitted_at']];
    foreach ($columns as $id) {
        $row[] = array_key_exists($id, $bySession[$sid] ?? []) ? $bySession[$sid][$id] : '';
    }
    fputcsv($out, $row);
}

fclose($out);
exit;
