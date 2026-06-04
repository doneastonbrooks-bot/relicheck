<?php
// POST /api/mm/reingest-dataset-text.php
// Body: { project_id }
//
// Backfill path for projects that linked a dataset BEFORE Phase 178v added
// open-column materialization to /api/mm/link-dataset.php. Re-reads the
// project's linked dataset, clears mm_text_responses for the project, and
// re-materializes one row per (respondent x open column) so the Qual
// surface has text to work with.
//
// Idempotent: safe to call repeatedly. Each call replaces the prior text
// rows entirely with what the dataset currently contains.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

$project = mm_require_project($pdo, $uid, $projectId);
$datasetId = (isset($project['dataset_id']) && $project['dataset_id'] !== null) ? (int)$project['dataset_id'] : 0;
if ($datasetId <= 0) fail('no_linked_dataset', 'This project does not have a linked dataset. Link one in Step 2 (Structure Data) first.');

$own = $pdo->prepare('SELECT id, title, column_meta, data FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
$own->execute([':id' => $datasetId, ':uid' => $uid]);
$ds = $own->fetch(PDO::FETCH_ASSOC);
if (!$ds) fail('dataset_not_found', 'Linked dataset not found.', 404);

$cm = $ds['column_meta'];
if (is_string($cm)) $cm = json_decode($cm, true);
$rows = $ds['data'];
if (is_string($rows)) $rows = json_decode($rows, true);
if (!is_array($cm))   $cm   = [];
if (!is_array($rows)) $rows = [];

// Phase 178w: tighten the open-column detection. column_meta.type == 'open'
// catches BOTH free-text answers AND short categorical strings (like Race,
// Gender, Role). The Codebook should only materialize real qualitative text.
// Heuristic: a column qualifies when its non-empty values average > 20
// characters per cell AND have > 12 distinct values across the dataset. The
// short categorical columns (3-6 unique values, average < 20 chars) become
// group_value candidates, not text-to-code rows.
$openIdx    = [];
$numericIdx = -1;
$refIdx     = -1;
$categoricalIdx = []; // open-typed but short / few-distinct: candidates for group_value
foreach ($cm as $i => $c) {
    if (!is_array($c)) continue;
    $type = (string)($c['type'] ?? '');
    $name = (string)($c['name'] ?? ('col_' . $i));
    if ($type === 'open') {
        if ($refIdx === -1 && preg_match('/^(respondent_?ref|response_?id|id|ref|email)$/i', $name)) {
            $refIdx = $i;
            continue;
        }
        // Sample the column to decide: real qualitative text vs short categorical.
        $set = [];
        $lenSum = 0;
        $lenCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
            if ($v === '') continue;
            if (count($set) < 200) $set[$v] = true;
            $lenSum += mb_strlen($v);
            $lenCount++;
        }
        $avgLen   = $lenCount > 0 ? ($lenSum / $lenCount) : 0;
        $distinct = count($set);
        // A column explicitly typed 'open' is qualitative text — materialize it
        // whenever it has any non-empty value. (The old avgLen>20 && distinct>12
        // heuristic dropped short answers and small samples; type=='open' is an
        // explicit classification, not a guess, so trust it.)
        if ($lenCount > 0) {
            $openIdx[] = ['idx' => $i, 'name' => $name];
        } else {
            // Truly empty 'open' column. Track for group_value bookkeeping only.
            $categoricalIdx[] = ['idx' => $i, 'name' => $name, 'distinct' => $distinct];
        }
    } elseif ($type === 'likert' && $numericIdx === -1) {
        $numericIdx = $i;
    }
}
// Group column: first non-Likert with 2-12 distinct values, demographic-ish.
$groupIdx = -1;
foreach ($cm as $i => $c) {
    if (!is_array($c)) continue;
    $type = (string)($c['type'] ?? '');
    if ($type === 'likert') continue;
    if ($i === $refIdx) continue;
    $isOpen = ($type === 'open');
    $set = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
        if ($v !== '') $set[$v] = true;
        if (count($set) > 20) break;
    }
    $distinct = count($set);
    if ($distinct >= 2 && $distinct <= 12) {
        if (!$isOpen) { $groupIdx = $i; break; }
        if ($groupIdx === -1) $groupIdx = $i;
    }
}

// Make sure a source row exists for this dataset; reuse the most recent one.
$srcId = 0;
try {
    $find = $pdo->prepare(
        'SELECT id FROM mm_data_sources
         WHERE project_id = :p AND source_type = "dataset" AND source_ref = :ref
         ORDER BY id DESC LIMIT 1'
    );
    $find->execute([':p' => $projectId, ':ref' => (string)$datasetId]);
    $srcId = (int)($find->fetchColumn() ?: 0);
    if ($srcId === 0) {
        $ins = $pdo->prepare(
            'INSERT INTO mm_data_sources
             (project_id, source_type, source_ref, label, field_name, numeric_field, group_field, row_count)
             VALUES (:p, "dataset", :ref, :lbl, NULL, NULL, NULL, 0)'
        );
        $ins->execute([
            ':p'   => $projectId,
            ':ref' => (string)$datasetId,
            ':lbl' => 'Dataset: ' . clean_string((string)$ds['title'], 180),
        ]);
        $srcId = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    error_log('mm/reingest-dataset-text: source row resolve failed: ' . $e->getMessage());
}

// Clear prior text rows for this project; re-materialize.
try {
    $del = $pdo->prepare('DELETE FROM mm_text_responses WHERE project_id = :p');
    $del->execute([':p' => $projectId]);
} catch (Throwable $e) {
    error_log('mm/reingest-dataset-text: clear failed: ' . $e->getMessage());
}

$inserted = 0;
$openCols = array_map(function ($o) { return $o['name']; }, $openIdx);
if (!empty($openIdx) && !empty($rows)) {
    $rowI = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) { $rowI++; continue; }
        $rid = ($refIdx >= 0 && isset($row[$refIdx]) && trim((string)$row[$refIdx]) !== '')
            ? trim((string)$row[$refIdx])
            : ('R' . ($rowI + 1));
        $num = null;
        if ($numericIdx >= 0 && isset($row[$numericIdx]) && is_numeric($row[$numericIdx])) {
            $num = (float)$row[$numericIdx];
        }
        $grp = null;
        if ($groupIdx >= 0 && isset($row[$groupIdx])) {
            $g = trim((string)$row[$groupIdx]);
            if ($g !== '') $grp = $g;
        }
        foreach ($openIdx as $o) {
            $text = isset($row[$o['idx']]) ? trim((string)$row[$o['idx']]) : '';
            if ($text === '') continue;
            if (mb_strlen($text) > 8000) $text = mb_substr($text, 0, 8000);
            try {
                mm_insert_text_response($pdo, $projectId, $srcId, $rid, $grp, $num, $text, $o['name'], $o['name']);
                $inserted++;
            } catch (Throwable $e) {
                error_log('mm/reingest-dataset-text: insert failed: ' . $e->getMessage());
            }
        }
        $rowI++;
    }
    if ($srcId > 0) {
        try {
            $upd = $pdo->prepare('UPDATE mm_data_sources SET row_count = :rc WHERE id = :id');
            $upd->execute([':rc' => $inserted, ':id' => $srcId]);
        } catch (Throwable $e) {
            error_log('mm/reingest-dataset-text: row_count update failed: ' . $e->getMessage());
        }
    }
}

json_out([
    'ok'              => true,
    'dataset_id'      => $datasetId,
    'respondent_count'=> count($rows),
    'text_rows_made'  => $inserted,
    'open_columns'    => $openCols,
]);
