<?php
// GET  /api/mm/framing.php?project_id=N
//   Returns the framing row + the project's wizard_step + a list of valid
//   slugs for each multi-select so the front-end never has to hardcode them.
//
// PATCH /api/mm/framing.php
//   { project_id, data_kinds?: [], intent_purposes?: [], chosen_design?: string,
//     advance_wizard_step?: int }
//
// Any field omitted is left unchanged. wizard_step is advanced to whichever
// value the client passes (so the front-end controls progression). Slugs
// are validated against the canonical lists below; unknown slugs are dropped
// silently rather than failing the whole call.
//
// Phase 170. Backward-compat: if the framing row doesn't exist (project
// pre-dates the migration), the GET returns a synthetic 'pending' row and
// the first PATCH inserts the real row. Existing projects backfilled to
// 'skipped_legacy' stay that way; the front-end uses framing_status to
// decide whether to show the wizard.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('GET', 'PATCH');
$user = require_auth();
$uid  = (int)$user['id'];
$pdo  = db();

// ---- Canonical slug lists (kept here so the server is the source of truth) --
const DATA_KIND_SLUGS = [
    'open_only',
    'survey_plus_open',
    'survey_plus_interviews',
    'quant_plus_interpretation',
    'build_from_scratch',
];
const INTENT_SLUGS = [
    'explain_survey',
    'find_themes',
    'compare_groups',
    'build_variables',
    'strengthen_report',
    'findings_section',
    'eval_evidence',
    'explore_patterns',
];
const DESIGN_SLUGS = ['design_a','design_b','design_c','design_d','design_e'];

// Phase 178: the back end is split into four post-wizard stops. Stage values
// are stored on mm_project_framing.backend_stage and govern which top-line
// stop the user can move to. needs_design is the gate that forces every
// project (including legacy backfilled rows) through Choose Design once.
const BACKEND_STAGES = ['needs_design','analyze','integrate','defend'];
const BACKEND_STAGE_ORDER = [
    'needs_design' => 0,
    'analyze'      => 1,
    'integrate'    => 2,
    'defend'       => 3,
];

function clean_slug_list(mixed $raw, array $allowed): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $s = is_string($v) ? trim($v) : '';
        if ($s !== '' && in_array($s, $allowed, true)) $out[] = $s;
    }
    return array_values(array_unique($out));
}

function load_framing(PDO $pdo, int $pid, int $uid): ?array
{
    // Project guard: the framing row is meaningless if the user doesn't own
    // the project. The framing table has no user_id column, so the
    // ownership check goes through mm_projects.
    $own = $pdo->prepare('SELECT id, title, description, wizard_step FROM mm_projects WHERE id = :p AND user_id = :u LIMIT 1');
    $own->execute([':p' => $pid, ':u' => $uid]);
    $proj = $own->fetch();
    if (!$proj) return null;

    // Phase 178: detect whether the new backend_stage column exists before
    // selecting it. Older installs that haven't run schema_phase178.sql yet
    // should keep working; the synthesized stage falls back to a needs_design
    // gate when chosen_design is empty.
    $hasStage = (int)$pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'mm_project_framing'
            AND COLUMN_NAME = 'backend_stage'"
    )->fetchColumn() > 0;

    $select = 'project_id, data_kinds, intent_purposes, chosen_design,
                       framing_status, created_at, updated_at';
    if ($hasStage) {
        $select .= ', backend_stage, chosen_design_locked_at';
    }
    $stmt = $pdo->prepare('SELECT ' . $select . ' FROM mm_project_framing WHERE project_id = :p LIMIT 1');
    $stmt->execute([':p' => $pid]);
    $row = $stmt->fetch();

    $dataKinds = $row && $row['data_kinds'] ? (json_decode((string)$row['data_kinds'], true) ?: []) : [];
    $intents   = $row && $row['intent_purposes'] ? (json_decode((string)$row['intent_purposes'], true) ?: []) : [];

    $chosenDesign = $row['chosen_design'] ?? null;
    if ($hasStage) {
        $backendStage = $row['backend_stage'] ?? 'needs_design';
        if (!in_array($backendStage, BACKEND_STAGES, true)) $backendStage = 'needs_design';
        $lockedAt = $row['chosen_design_locked_at'] ?? null;
    } else {
        $backendStage = ($chosenDesign !== null && $chosenDesign !== '') ? 'analyze' : 'needs_design';
        $lockedAt = null;
    }

    return [
        'project' => [
            'id'          => (int)$proj['id'],
            'title'       => (string)$proj['title'],
            'description' => $proj['description'],
            'wizard_step' => (int)$proj['wizard_step'],
        ],
        'framing' => [
            'data_kinds'              => is_array($dataKinds) ? $dataKinds : [],
            'intent_purposes'         => is_array($intents) ? $intents : [],
            'chosen_design'           => $chosenDesign,
            'framing_status'          => $row['framing_status'] ?? 'pending',
            'backend_stage'           => $backendStage,
            'chosen_design_locked_at' => $lockedAt,
            'created_at'              => $row['created_at'] ?? null,
            'updated_at'              => $row['updated_at'] ?? null,
        ],
        'options' => [
            'data_kinds'     => DATA_KIND_SLUGS,
            'intents'        => INTENT_SLUGS,
            'designs'        => DESIGN_SLUGS,
            'backend_stages' => BACKEND_STAGES,
        ],
    ];
}

// ===================== GET =================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $pid = (int)($_GET['project_id'] ?? 0);
    if ($pid <= 0) fail('bad_project_id', 'Missing project_id.', 400);
    $payload = load_framing($pdo, $pid, $uid);
    if ($payload === null) fail('not_found', 'Project not found.', 404);
    json_out(['ok' => true] + $payload);
}

// ===================== PATCH ===============================================
check_origin();
check_rate_limit('mm_framing:user:' . $uid, 120, 3600);

$body = read_json_body();
$pid  = (int)($body['project_id'] ?? 0);
if ($pid <= 0) fail('bad_project_id', 'Missing project_id.', 400);

// Ownership guard
$own = $pdo->prepare('SELECT id, wizard_step FROM mm_projects WHERE id = :p AND user_id = :u LIMIT 1');
$own->execute([':p' => $pid, ':u' => $uid]);
$proj = $own->fetch();
if (!$proj) fail('not_found', 'Project not found.', 404);

// Phase 178 column-exists guard: PATCH paths that touch backend_stage or
// chosen_design_locked_at need to know whether the migration has run. If it
// has not, those updates are silently dropped so the rest of the request
// still goes through.
$hasStage = (int)$pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'mm_project_framing'
        AND COLUMN_NAME = 'backend_stage'"
)->fetchColumn() > 0;

// Read the current framing row up front so we can enforce the chosen_design
// gate on stage advances and stamp chosen_design_locked_at the first time a
// design is recorded.
$cur = $pdo->prepare('SELECT chosen_design' . ($hasStage ? ', backend_stage, chosen_design_locked_at' : '') . ' FROM mm_project_framing WHERE project_id = :p LIMIT 1');
$cur->execute([':p' => $pid]);
$curRow = $cur->fetch() ?: [];
$curDesign = $curRow['chosen_design'] ?? null;
$curStage  = $hasStage ? ($curRow['backend_stage'] ?? 'needs_design') : 'needs_design';

$updates = [];
$args    = [':p' => $pid];

if (array_key_exists('data_kinds', $body)) {
    $cleaned = clean_slug_list($body['data_kinds'], DATA_KIND_SLUGS);
    $updates[] = 'data_kinds = :dk';
    $args[':dk'] = json_encode($cleaned, JSON_UNESCAPED_SLASHES);
}
if (array_key_exists('intent_purposes', $body)) {
    $cleaned = clean_slug_list($body['intent_purposes'], INTENT_SLUGS);
    $updates[] = 'intent_purposes = :ip';
    $args[':ip'] = json_encode($cleaned, JSON_UNESCAPED_SLASHES);
}
// Track the new chosen_design value across both the chosen_design and
// backend_stage update paths so the gate is enforced against the latest
// value, not the row's prior state.
$newDesign = $curDesign;
if (array_key_exists('chosen_design', $body)) {
    $d = (string)$body['chosen_design'];
    if ($d !== '' && !in_array($d, DESIGN_SLUGS, true)) {
        fail('bad_design', 'chosen_design must be one of: ' . implode(', ', DESIGN_SLUGS), 400);
    }
    $updates[] = 'chosen_design = :cd';
    $args[':cd'] = $d !== '' ? $d : null;
    $newDesign = $d !== '' ? $d : null;
    // Phase 178: stamp the lock the first time a design is recorded.
    if ($hasStage && $newDesign !== null && ($curRow['chosen_design_locked_at'] ?? null) === null) {
        $updates[] = 'chosen_design_locked_at = NOW()';
    }
}

// Phase 178: backend_stage transitions.
//   * needs_design is the only stage allowed when chosen_design is empty.
//   * analyze, integrate, defend require a chosen_design.
//   * Skipping ahead (eg. needs_design -> defend) is allowed; the client
//     decides which stop the user is on. The single hard rule is that you
//     cannot leave needs_design without a design.
if (array_key_exists('backend_stage', $body) && $hasStage) {
    $stage = (string)$body['backend_stage'];
    if (!in_array($stage, BACKEND_STAGES, true)) {
        fail('bad_stage', 'backend_stage must be one of: ' . implode(', ', BACKEND_STAGES), 400);
    }
    if ($stage !== 'needs_design' && ($newDesign === null || $newDesign === '')) {
        fail('design_required', 'Pick a mixed-methods design before leaving the Choose Design step.', 409);
    }
    $updates[] = 'backend_stage = :bs';
    $args[':bs'] = $stage;
}

if (array_key_exists('framing_status', $body)) {
    $s = (string)$body['framing_status'];
    if (!in_array($s, ['pending','in_progress','complete','skipped_legacy'], true)) {
        fail('bad_status', 'framing_status must be pending, in_progress, complete, or skipped_legacy.', 400);
    }
    $updates[] = 'framing_status = :fs';
    $args[':fs'] = $s;
}

// Always upsert the framing row even if no field was sent (lets the client
// create the row on first wizard load).
if (empty($updates)) {
    $updates[] = 'framing_status = COALESCE(framing_status, \'pending\')';
}

// Upsert the framing row.
$pdo->prepare(
    'INSERT INTO mm_project_framing (project_id, framing_status)
     VALUES (:p, \'pending\')
     ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
)->execute($args);

// Optionally advance the wizard_step on mm_projects.
if (array_key_exists('advance_wizard_step', $body)) {
    $newStep = (int)$body['advance_wizard_step'];
    if ($newStep < 1)  $newStep = 1;
    if ($newStep > 99) $newStep = 99;
    // Only advance forward; don't let a stale client reset progress.
    if ($newStep > (int)$proj['wizard_step']) {
        $pdo->prepare('UPDATE mm_projects SET wizard_step = :s WHERE id = :p')
            ->execute([':s' => $newStep, ':p' => $pid]);
    }
}

$payload = load_framing($pdo, $pid, $uid);
json_out(['ok' => true] + ($payload ?? []));
