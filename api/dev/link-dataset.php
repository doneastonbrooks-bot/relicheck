<?php
// POST /api/dev/link-dataset.php
// Body: { project_id, dataset_id }
//
// Links an uploaded dataset to a survey development project. Mirrors
// api/mm/link-dataset.php for the survey side. Writes
// survey_projects.dataset_id so rssi-dataset.php reads from the uploaded
// data instead of the project's collected response sessions.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/../_rc_projects.php';
require_once __DIR__ . '/../_dataset_helpers.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
sds_ensure_schema($pdo);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$datasetId = (int)($body['dataset_id'] ?? 0);
if ($projectId <= 0 || $datasetId <= 0) fail('bad_input', 'project_id and dataset_id are required.');

sds_require_project($pdo, $uid, $projectId);

$own = $pdo->prepare(
    'SELECT id, title, row_count, column_count, column_meta, data FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1'
);
$own->execute([':id' => $datasetId, ':uid' => $uid]);
$ds = $own->fetch(PDO::FETCH_ASSOC);
if (!$ds) fail('dataset_not_found', 'Dataset not found or you do not own it.', 404);

$pdo->prepare('UPDATE survey_projects SET dataset_id = :did WHERE id = :pid AND user_id = :uid')
    ->execute([':did' => $datasetId, ':pid' => $projectId, ':uid' => $uid]);

rc_seed_var_meta_from_dataset($pdo, $projectId, 'survey', $datasetId, null);

$rcId = rc_project_id_for_studio($pdo, 'survey_projects', $projectId);
if ($rcId !== null) rc_set_project_dataset($pdo, $rcId, $datasetId);

// ── Materialize the uploaded survey's QUESTIONS into the builder ─────────────
// "Bring In an Existing Survey" means the file's columns ARE the survey items.
// Each data column becomes a survey_item (header -> prompt, data shape -> type)
// so the project opens in the builder populated, ready for SDSI/SIRI review.
// Response rows stay in the linked dataset for RSSI at the end of the pipeline.
// Guarded to run only when the project has NO items yet, so re-linking a dataset
// (e.g. "Change data") never clobbers a survey the user has been editing.
$itemsCreated = 0;
$existing = $pdo->prepare('SELECT COUNT(*) FROM survey_items WHERE project_id = :pid');
$existing->execute([':pid' => $projectId]);
if ((int)$existing->fetchColumn() === 0) {
    $cm = $ds['column_meta'];
    if (is_string($cm)) $cm = json_decode($cm, true);
    $dataRows = $ds['data'];
    if (is_string($dataRows)) $dataRows = json_decode($dataRows, true);
    if (!is_array($dataRows)) $dataRows = [];
    // Derive the real, distinct answer categories for a column straight from the
    // response data, so choice questions come in with their actual options (e.g.
    // "1-3 years", "Manager") instead of empty/placeholder options. Capped: beyond
    // ~15 distinct values it is free text, not a clean categorical, so we leave it.
    $distinctFor = function (int $ci) use ($dataRows): array {
        $seen = []; $out = [];
        foreach ($dataRows as $row) {
            if (!is_array($row)) continue;
            $v = isset($row[$ci]) ? trim((string)$row[$ci]) : '';
            if ($v === '' || isset($seen[$v])) continue;
            $seen[$v] = true; $out[] = $v;
            if (count($out) > 15) return [];  // too many distinct values -> not categorical
        }
        return $out;
    };
    if (is_array($cm) && $cm) {
        // analysis_type (preferred) / legacy dataset type -> survey item type.
        // Returns null for columns that are NOT survey questions (id, timestamp,
        // structural metadata) so they are skipped rather than shown as items.
        $itemTypeFor = function (?string $at, ?string $legacy): ?string {
            switch ($at) {
                case 'likert_item':          return 'Likert (5-pt)';
                case 'binary':               return 'Yes/No';
                case 'demographic_numeric':
                case 'scale_score':
                case 'computed_score':       return 'Numeric';
                case 'demographic_nominal':
                case 'demographic_ordinal':  return 'Single Choice';
                case 'open_ended':
                case 'narrative':            return 'Open-Ended';
                case 'identifier':
                case 'date_time':
                case 'metadata':
                case 'structural':
                case 'file_reference':
                case 'qualitative_code':
                case 'theme':                return null;  // not a survey question
            }
            switch ($legacy) {
                case 'likert':                 return 'Likert (5-pt)';
                case 'binary':                 return 'Yes/No';
                case 'numeric': case 'criterion': return 'Numeric';
                case 'single': case 'demographic': return 'Single Choice';
                case 'multi':                  return 'Multiple Choice';
                case 'open':                   return 'Open-Ended';
                case 'identifier': case 'ignore': return null;
            }
            return 'Short Answer';  // unknown but present -> keep as a question
        };
        $ins = $pdo->prepare(
            'INSERT INTO survey_items (project_id, section_id, position, type, prompt, options, flag, required, settings)
             VALUES (:pid, NULL, :pos, :type, :prompt, :opts, NULL, 0, NULL)'
        );
        $pos = 0;
        foreach ($cm as $ci => $col) {
            if (!is_array($col)) continue;
            $prompt = trim((string)($col['name'] ?? ''));
            if ($prompt === '') continue;
            $at     = isset($col['analysis_type']) && $col['analysis_type'] !== '' ? (string)$col['analysis_type'] : null;
            $legacy = isset($col['type']) ? strtolower(trim((string)$col['type'])) : null;
            $type   = $itemTypeFor($at, $legacy);
            if ($type === null) continue;  // skip id / timestamp / structural columns
            // Choice questions need real answer options. Prefer any stored in
            // column_meta; otherwise derive the actual distinct categories from the
            // response data (the column index $ci aligns column_meta with data rows).
            $opts = null;
            if (in_array($type, ['Single Choice', 'Multiple Choice'], true)) {
                if (isset($col['options']) && is_array($col['options']) && count($col['options']) >= 2) {
                    $opts = json_encode(array_values($col['options']), JSON_UNESCAPED_UNICODE);
                } else {
                    $derived = $distinctFor((int)$ci);
                    if (count($derived) >= 2) $opts = json_encode($derived, JSON_UNESCAPED_UNICODE);
                }
            }
            $ins->execute([
                ':pid'    => $projectId,
                ':pos'    => $pos,
                ':type'   => $type,
                ':prompt' => mb_substr($prompt, 0, 4000),
                ':opts'   => $opts,
            ]);
            $pos++;
            $itemsCreated++;
        }
    }
}

json_out([
    'ok'            => true,
    'dataset_id'    => $datasetId,
    'row_count'     => (int)$ds['row_count'],
    'column_count'  => (int)$ds['column_count'],
    'title'         => (string)$ds['title'],
    'items_created' => $itemsCreated,
]);
