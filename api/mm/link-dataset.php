<?php
// POST /api/mm/link-dataset.php
// Body: { project_id, dataset_id }
//
// Links an existing ReliCheck dataset (the kind that powers the main app's
// Analyze surface) to an MM Studio project. No row-copying. Writes
// mm_projects.dataset_id so the Studio's Quant Analysis tab can hand the
// dataset to the same analytics layer the main app uses.
//
// This is the "use the dataset as-is" path. Use it when a researcher has
// already uploaded a dataset into the main app (with multi-Likert columns,
// demographics, etc.) and wants MM Studio to analyze it without flattening
// it into mm_text_responses long-format.
//
// Phase 178j.

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
$datasetId = (int)($body['dataset_id'] ?? 0);
if ($projectId <= 0 || $datasetId <= 0) fail('bad_input', 'project_id and dataset_id are required.');

mm_require_project($pdo, $uid, $projectId);

// Ownership check on the dataset. Pull column_meta and data too because we
// materialize the open columns into mm_text_responses for the Qual side.
$own = $pdo->prepare('SELECT id, title, row_count, column_count, column_meta, data FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
$own->execute([':id' => $datasetId, ':uid' => $uid]);
$ds = $own->fetch(PDO::FETCH_ASSOC);
if (!$ds) fail('dataset_not_found', 'Dataset not found or you do not own it.', 404);

// Write the link back to the project. Also clear any stale survey_id so the
// downstream Studio panels do not preferentially read from an older linked
// survey (a pre-178j project could have both ids set if the user linked a
// survey first and then uploaded a dataset later).
$pdo->prepare('UPDATE mm_projects SET dataset_id = :did, survey_id = NULL WHERE id = :pid AND user_id = :uid')
    ->execute([':did' => $datasetId, ':pid' => $projectId, ':uid' => $uid]);

// Record a row in mm_data_sources so the wizard knows what was attached.
$sourceId = 0;
try {
    $src = $pdo->prepare(
        'INSERT INTO mm_data_sources
         (project_id, source_type, source_ref, label, field_name, numeric_field, group_field, row_count)
         VALUES (:p, "dataset", :ref, :lbl, NULL, NULL, NULL, :rc)'
    );
    $src->execute([
        ':p'   => $projectId,
        ':ref' => (string)$datasetId,
        ':lbl' => 'Dataset: ' . clean_string((string)$ds['title'], 180),
        ':rc'  => (int)$ds['row_count'],
    ]);
    $sourceId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('mm/link-dataset: mm_data_sources insert failed for project ' . $projectId . ': ' . $e->getMessage());
}

// Phase 178v: materialize open-typed columns into mm_text_responses so the
// Qual Analysis surface (Codebook, sentiment, quote selection) has long-
// format text rows to read. Idempotent: any prior rows for this project
// are removed first so re-linking the same dataset does not duplicate.
$insertedText = 0;
$openCols = [];
try {
    $cm = $ds['column_meta'];
    if (is_string($cm)) $cm = json_decode($cm, true);
    $rows = $ds['data'];
    if (is_string($rows)) $rows = json_decode($rows, true);
    if (!is_array($cm))   $cm   = [];
    if (!is_array($rows)) $rows = [];

    // Phase 178w: tighten open-column detection. column_meta.type == 'open'
    // catches BOTH free-text answers AND short categorical strings (Race,
    // Gender, Role). The Codebook should only materialize real qualitative
    // text. Heuristic: avg cell length > 20 AND distinct values > 12 means
    // free text. Otherwise it's a short categorical -- candidate for group.
    $openIdx     = [];
    $numericIdx  = -1;
    $groupIdx    = -1;
    $refIdx      = -1;
    foreach ($cm as $i => $c) {
        if (!is_array($c)) continue;
        $type = (string)($c['type'] ?? '');
        $name = (string)($c['name'] ?? ('col_' . $i));
        if ($type === 'open') {
            if ($refIdx === -1 && preg_match('/^(respondent_?ref|response_?id|id|ref|email)$/i', $name)) {
                $refIdx = $i;
                continue;
            }
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
            $avgLen = $lenCount > 0 ? ($lenSum / $lenCount) : 0;
            if ($avgLen > 20 && count($set) > 12) {
                $openIdx[] = ['idx' => $i, 'name' => $name];
            }
            // else: short categorical; the group-detection pass below will
            // consider it for group_value.
        } elseif ($type === 'likert' && $numericIdx === -1) {
            $numericIdx = $i;
        }
    }
    // Distinct-count pass for group detection (only on non-Likert columns).
    if ($groupIdx === -1) {
        $candidate = -1;
        foreach ($cm as $i => $c) {
            if (!is_array($c)) continue;
            $type = (string)($c['type'] ?? '');
            if ($type === 'likert') continue;
            if ($i === $refIdx) continue;
            $isOpen = ($type === 'open');
            // Skip columns we already identified as the response_id ref.
            $set = [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $v = isset($row[$i]) ? trim((string)$row[$i]) : '';
                if ($v !== '') $set[$v] = true;
                if (count($set) > 20) break;
            }
            $distinct = count($set);
            if ($distinct >= 2 && $distinct <= 12) {
                // Prefer single/multi typed columns over generic "open" demographics.
                if (!$isOpen || $candidate === -1) $candidate = $i;
                if (!$isOpen) break;
            }
        }
        $groupIdx = $candidate;
    }

    $openCols = array_map(function ($o) { return $o['name']; }, $openIdx);

    if (!empty($openIdx) && !empty($rows)) {
        // Clear any prior materialized text rows for this project. We replace
        // the project's mm_text_responses contents entirely on re-link so
        // the row set stays consistent with the linked dataset.
        try {
            $del = $pdo->prepare('DELETE FROM mm_text_responses WHERE project_id = :p');
            $del->execute([':p' => $projectId]);
        } catch (Throwable $e) {
            error_log('mm/link-dataset: prior text clear failed: ' . $e->getMessage());
        }

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
            // One mm_text_responses row per (respondent x open column). The
            // question_id_raw / question_text_raw fields carry the source
            // column name so the Codebook can render "themes per question."
            foreach ($openIdx as $o) {
                $text = isset($row[$o['idx']]) ? trim((string)$row[$o['idx']]) : '';
                if ($text === '') continue;
                if (mb_strlen($text) > 8000) $text = mb_substr($text, 0, 8000);
                try {
                    mm_insert_text_response(
                        $pdo, $projectId, $sourceId,
                        $rid, $grp, $num, $text,
                        $o['name'], $o['name']
                    );
                    $insertedText++;
                } catch (Throwable $e) {
                    error_log('mm/link-dataset: text insert failed: ' . $e->getMessage());
                }
            }
            $rowI++;
        }

        // Update the source row's row_count to reflect what we actually
        // materialized so the wizard step 2 summary reads honest numbers.
        if ($sourceId > 0 && $insertedText > 0) {
            try {
                $upd = $pdo->prepare('UPDATE mm_data_sources SET row_count = :rc WHERE id = :id');
                $upd->execute([':rc' => $insertedText, ':id' => $sourceId]);
            } catch (Throwable $e) {
                error_log('mm/link-dataset: source row_count update failed: ' . $e->getMessage());
            }
        }
    }
} catch (Throwable $e) {
    error_log('mm/link-dataset: text materialization failed: ' . $e->getMessage());
}

json_out([
    'ok'             => true,
    'dataset_id'     => $datasetId,
    'row_count'      => (int)$ds['row_count'],
    'column_count'   => (int)$ds['column_count'],
    'title'          => (string)$ds['title'],
    'text_rows_made' => $insertedText,
    'open_columns'   => $openCols,
    'source_id'      => $sourceId,
]);
