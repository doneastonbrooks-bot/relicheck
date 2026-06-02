<?php
// GET /api/rc/project-bundle.php — RE Infrastructure Item 4.
//
// Returns the structured bundle that the SIRI→RSSI→Studios chain (Item 5)
// passes between tools. One endpoint, three layers:
//
//   dataset          — { id, title, row_count, column_count, columns[], data[][] }
//   variable_metadata — typed schema rows from the variable_metadata table
//   scores           — { sdsi, siri, rssi } each null if not yet computed
//
// Input (one of, in priority order):
//   ?rc_project_id=N        — preferred; looks up via the ecosystem parent
//   ?survey_project_id=N    — fallback for SIRI projects that predate rc_projects
//   ?dataset_id=N           — minimal; dataset + variable_metadata only (no scores)

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_rc_projects.php';
require_once __DIR__ . '/../dev/_dev_common.php';

require_method('GET');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

sds_ensure_schema($pdo);
rc_ensure_project_schema($pdo);

// ── Resolve the entry point ──────────────────────────────────────────────────

$rcProjectId     = isset($_GET['rc_project_id'])     ? (int)$_GET['rc_project_id']     : 0;
$surveyProjectId = isset($_GET['survey_project_id']) ? (int)$_GET['survey_project_id'] : 0;
$datasetIdDirect = isset($_GET['dataset_id'])        ? (int)$_GET['dataset_id']        : 0;

if (!$rcProjectId && !$surveyProjectId && !$datasetIdDirect) {
    fail('bad_input', 'One of rc_project_id, survey_project_id, or dataset_id is required.');
}

$rcRow       = null;   // rc_projects row
$surveyRow   = null;   // survey_projects row
$datasetId   = 0;
$title       = '';

// ── Path 1: rc_project_id ────────────────────────────────────────────────────
if ($rcProjectId > 0) {
    $s = $pdo->prepare(
        'SELECT id, user_id, title, description, dataset_id
           FROM rc_projects
          WHERE id = :id AND user_id = :uid AND status <> "archived"
          LIMIT 1'
    );
    $s->execute([':id' => $rcProjectId, ':uid' => $uid]);
    $rcRow = $s->fetch(PDO::FETCH_ASSOC);
    if (!$rcRow) fail('not_found', 'Project not found.', 404);

    $datasetId = (int)($rcRow['dataset_id'] ?? 0);
    $title     = (string)$rcRow['title'];

    // Find any linked survey_projects row for score lookups.
    $s2 = $pdo->prepare(
        'SELECT id, dataset_id FROM survey_projects
          WHERE rc_project_id = :rcid AND user_id = :uid
          LIMIT 1'
    );
    $s2->execute([':rcid' => $rcProjectId, ':uid' => $uid]);
    $surveyRow = $s2->fetch(PDO::FETCH_ASSOC) ?: null;

    // If rc_projects.dataset_id is not yet set, fall back to survey_projects.dataset_id.
    if (!$datasetId && $surveyRow && !empty($surveyRow['dataset_id'])) {
        $datasetId = (int)$surveyRow['dataset_id'];
    }
}

// ── Path 2: survey_project_id ────────────────────────────────────────────────
if (!$rcProjectId && $surveyProjectId > 0) {
    $s = $pdo->prepare(
        'SELECT id, rc_project_id, dataset_id, title
           FROM survey_projects
          WHERE id = :id AND user_id = :uid
          LIMIT 1'
    );
    $s->execute([':id' => $surveyProjectId, ':uid' => $uid]);
    $surveyRow = $s->fetch(PDO::FETCH_ASSOC);
    if (!$surveyRow) fail('not_found', 'Survey project not found.', 404);

    $datasetId   = (int)($surveyRow['dataset_id'] ?? 0);
    $title       = (string)($surveyRow['title'] ?? '');
    $rcProjectId = (int)($surveyRow['rc_project_id'] ?? 0);

    // Try to load the rc_projects row so the response includes the canonical id.
    if ($rcProjectId > 0) {
        $s2 = $pdo->prepare(
            'SELECT id, title, dataset_id FROM rc_projects
              WHERE id = :id AND user_id = :uid
              LIMIT 1'
        );
        $s2->execute([':id' => $rcProjectId, ':uid' => $uid]);
        $rcRow = $s2->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rcRow && !$datasetId && !empty($rcRow['dataset_id'])) {
            $datasetId = (int)$rcRow['dataset_id'];
        }
    }
}

// ── Path 3: dataset_id only ──────────────────────────────────────────────────
if (!$rcProjectId && !$surveyProjectId && $datasetIdDirect > 0) {
    $datasetId = $datasetIdDirect;
}

// ── Layer 1: Dataset ─────────────────────────────────────────────────────────

$datasetOut = null;
if ($datasetId > 0) {
    $ds = $pdo->prepare(
        'SELECT id, title, row_count, column_count, column_meta, data
           FROM datasets
          WHERE id = :id AND owner_id = :uid
          LIMIT 1'
    );
    $ds->execute([':id' => $datasetId, ':uid' => $uid]);
    $drow = $ds->fetch(PDO::FETCH_ASSOC);

    if ($drow) {
        $cols = json_decode((string)($drow['column_meta'] ?? '[]'), true) ?: [];
        $data = json_decode((string)($drow['data']        ?? '[]'), true) ?: [];

        if (!$title) $title = (string)$drow['title'];

        $datasetOut = [
            'id'           => (int)$drow['id'],
            'title'        => (string)$drow['title'],
            'row_count'    => (int)$drow['row_count'],
            'column_count' => (int)$drow['column_count'],
            'columns'      => $cols,   // column_meta array as stored
            'data'         => $data,   // 2-D array [row][col_index]
        ];
    }
}

// ── Layer 2: Variable metadata ────────────────────────────────────────────────

$varMeta = [];

if ($rcProjectId > 0) {
    // Prefer rc_project_id — the unified cross-studio view.
    $vm = $pdo->prepare(
        'SELECT variable_name, display_label, source, analysis_type,
                measurement_level, role, construct_id, allowed_values,
                reverse_scored, include_in_analysis, position, dataset_id
           FROM variable_metadata
          WHERE rc_project_id = :rcid
          ORDER BY position, id'
    );
    $vm->execute([':rcid' => $rcProjectId]);
    $varMeta = $vm->fetchAll(PDO::FETCH_ASSOC);
}

if (!$varMeta && $surveyRow) {
    // Fallback: survey project scope (legacy rows without rc_project_id).
    $vm = $pdo->prepare(
        'SELECT variable_name, display_label, source, analysis_type,
                measurement_level, role, construct_id, allowed_values,
                reverse_scored, include_in_analysis, position, dataset_id
           FROM variable_metadata
          WHERE project_id = :pid AND project_type = "survey"
          ORDER BY position, id'
    );
    $vm->execute([':pid' => (int)$surveyRow['id']]);
    $varMeta = $vm->fetchAll(PDO::FETCH_ASSOC);
}

if (!$varMeta && $datasetId > 0) {
    // Minimal fallback: variable_metadata keyed directly by dataset_id.
    $vm = $pdo->prepare(
        'SELECT variable_name, display_label, source, analysis_type,
                measurement_level, role, construct_id, allowed_values,
                reverse_scored, include_in_analysis, position, dataset_id
           FROM variable_metadata
          WHERE dataset_id = :did
          ORDER BY position, id'
    );
    $vm->execute([':did' => $datasetId]);
    $varMeta = $vm->fetchAll(PDO::FETCH_ASSOC);
}

// Normalise booleans so JSON consumers don't have to cast "0"/"1".
$varMeta = array_map(function (array $v): array {
    $v['reverse_scored']      = (bool)(int)$v['reverse_scored'];
    $v['include_in_analysis'] = (bool)(int)$v['include_in_analysis'];
    $v['construct_id']        = $v['construct_id'] !== null ? (int)$v['construct_id'] : null;
    $v['position']            = (int)$v['position'];
    if (isset($v['allowed_values']) && is_string($v['allowed_values'])) {
        $v['allowed_values'] = json_decode($v['allowed_values'], true);
    }
    return $v;
}, $varMeta);

// ── Layer 3: Scores ───────────────────────────────────────────────────────────
// Scores live on survey_projects rows (sdsi_reviews, siri_reviews, rssi_reviews
// all FK to survey_projects.id). We need the survey_project_id to join.

$scores = ['sdsi' => null, 'siri' => null, 'rssi' => null];

$sid = $surveyRow ? (int)$surveyRow['id'] : $surveyProjectId;

if ($sid > 0) {
    $readScore = function (string $table) use ($pdo, $sid): ?array {
        try {
            $s = $pdo->prepare(
                "SELECT total, max_points, pct, band, blocked, withheld, review, updated_at
                   FROM `{$table}`
                  WHERE project_id = :pid
                  LIMIT 1"
            );
            $s->execute([':pid' => $sid]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            // Decode JSON review blob but do not include the full detail in the
            // bundle by default — callers that need it can join separately.
            // We include pct, band, blocked/withheld, and updated_at only.
            $out = [
                'total'      => $row['total'] !== null ? (float)$row['total'] : null,
                'max_points' => (float)$row['max_points'],
                'pct'        => $row['pct'] !== null ? (int)$row['pct'] : null,
                'band'       => $row['band'],
                'updated_at' => $row['updated_at'],
            ];
            if (array_key_exists('blocked',  $row)) $out['blocked']  = (bool)(int)$row['blocked'];
            if (array_key_exists('withheld', $row)) $out['withheld'] = (bool)(int)$row['withheld'];
            return $out;
        } catch (Throwable $e) {
            return null;
        }
    };

    $scores['sdsi'] = $readScore('sdsi_reviews');
    $scores['siri'] = $readScore('siri_reviews');
    $scores['rssi'] = $readScore('rssi_reviews');
}

// ── Response ──────────────────────────────────────────────────────────────────

json_out([
    'ok'               => true,
    'rc_project_id'    => $rcProjectId ?: null,
    'survey_project_id'=> $sid ?: null,
    'dataset_id'       => $datasetId ?: null,
    'title'            => $title,
    'dataset'          => $datasetOut,
    'variable_metadata'=> $varMeta,
    'scores'           => $scores,
]);
