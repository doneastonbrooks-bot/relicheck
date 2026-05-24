<?php
// POST /api/mm/wizard.php
// Body: { project_id, step: "data_kind"|"purpose"|"field_map"|"design_choice"|"complete", ... }

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
$step      = clean_string((string)($body['step'] ?? ''), 32);
if ($projectId <= 0 || $step === '') fail('bad_input', 'project_id and step are required.');
mm_require_project($pdo, $uid, $projectId);

$valid = [
    'data_kind'     => ['open_ended_only','survey_plus_open','survey_plus_separate_qual','quant_only_with_qual','from_scratch'],
    'purpose'       => ['explain_survey_results','find_themes','compare_groups','build_variables_from_text','strengthen_report','mixed_methods_section','evaluation_accreditation','pre_survey_exploration'],
    'design_choice' => ['A_explain_numbers','B_comments_to_themes','C_compare_themes_groups','D_variables_from_text','E_full_integrated_report'],
];

if ($step === 'data_kind' || $step === 'purpose') {
    $values = $body['values'] ?? null;
    if (!is_array($values) && isset($body['value'])) $values = [$body['value']];
    if (!is_array($values) || count($values) === 0) fail('bad_input', 'Pick at least one option for ' . $step . '.');
    $clean = [];
    foreach ($values as $v) {
        $v = clean_string((string)$v, 40);
        if (in_array($v, $valid[$step], true) && !in_array($v, $clean, true)) $clean[] = $v;
    }
    if (count($clean) === 0) fail('bad_input', 'No valid options for ' . $step . '.');
    $column = $step === 'data_kind' ? 'data_kinds' : 'purposes';
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE mm_projects SET $column = :v WHERE id = :id");
    $stmt->execute([':v' => $json, ':id' => $projectId]);
} elseif ($step === 'design_choice') {
    $value = clean_string((string)($body['value'] ?? ''), 40);
    if (!in_array($value, $valid['design_choice'], true)) fail('bad_input', 'Invalid value for design_choice.');
    $stmt = $pdo->prepare('UPDATE mm_projects SET design_choice = :v WHERE id = :id');
    $stmt->execute([':v' => $value, ':id' => $projectId]);
} elseif ($step === 'field_map') {
    $map = $body['map'] ?? null;
    $sourceId = (int)($body['source_id'] ?? 0);
    if (!is_array($map))    fail('bad_input', 'field_map needs a map object.');
    if ($sourceId <= 0)     fail('bad_input', 'field_map needs a source_id.');
    $ck = $pdo->prepare('SELECT id FROM mm_data_sources WHERE id = :i AND project_id = :p');
    $ck->execute([':i' => $sourceId, ':p' => $projectId]);
    if (!$ck->fetch()) fail('mm_source_not_found', 'Data source not found in this project.', 404);
    $clean = [
        'response_id'   => clean_string((string)($map['response_id'] ?? ''), 200),
        'question_col'  => clean_string((string)($map['question_col'] ?? ''), 200),
        'closed'   => array_values(array_filter(array_map(fn($v) => clean_string((string)$v, 200), is_array($map['closed']   ?? null) ? $map['closed']   : []))),
        'open'     => array_values(array_filter(array_map(fn($v) => clean_string((string)$v, 200), is_array($map['open']     ?? null) ? $map['open']     : []))),
        'group'    => array_values(array_filter(array_map(fn($v) => clean_string((string)$v, 200), is_array($map['group']    ?? null) ? $map['group']    : []))),
        'outcome'  => array_values(array_filter(array_map(fn($v) => clean_string((string)$v, 200), is_array($map['outcome']  ?? null) ? $map['outcome']  : []))),
        'time'     => array_values(array_filter(array_map(fn($v) => clean_string((string)$v, 200), is_array($map['time']     ?? null) ? $map['time']     : []))),
    ];
    $stmt = $pdo->prepare('UPDATE mm_data_sources SET field_map_json = :j WHERE id = :i');
    $stmt->execute([':j' => json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ':i' => $sourceId]);
} elseif ($step === 'complete') {
    $stmt = $pdo->prepare('UPDATE mm_projects SET wizard_completed_at = NOW(), status = "active" WHERE id = :id');
    $stmt->execute([':id' => $projectId]);
} else {
    fail('bad_input', 'Unknown wizard step.');
}

$row = $pdo->prepare('SELECT * FROM mm_projects WHERE id = :id');
$row->execute([':id' => $projectId]);
$project = $row->fetch(PDO::FETCH_ASSOC) ?: [];

$nextStep = 'data_kind';
if (!empty($project['data_kinds']))                 $nextStep = 'purpose';
if (!empty($project['purposes']))                   $nextStep = 'upload';
if (!empty($project['purposes']) && mm_response_count($pdo, $projectId) > 0) $nextStep = 'confirm_fields';
if (!empty($project['design_choice']))              $nextStep = 'complete';
if (!empty($project['wizard_completed_at']))        $nextStep = 'work';

json_out(['ok' => true, 'project' => mm_project_out($project), 'next_step' => $nextStep]);
