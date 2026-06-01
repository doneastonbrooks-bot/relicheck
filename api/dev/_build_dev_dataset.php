<?php
// Shared transform: a SIRI / survey-dev project (survey_items +
// survey_dev_answers + survey_dev_response_sessions) → the standard analysis
// dataset shape { source, variables:[{name, types, values}], rowCount, config }.
//
// To guarantee the engines receive EXACTLY the shape they already expect (and
// to avoid a second, drift-prone implementation), this does NOT re-derive the
// variable shape itself. It adapts survey-dev rows into the same `questions` +
// `responses` arrays the legacy builder consumes, then calls the tested
// relicheck_survey_build_dataset() from api/surveys/_build_dataset.php.
//
// No side effects at top level — only the function definition. Reliability is
// NOT computed here; this is data shaping only.

require_once __DIR__ . '/../surveys/_build_dataset.php';

if (!function_exists('relicheck_surveydev_build_dataset')):

// Map a survey-dev item type to the legacy builder's logical type +
// _builderType. Anything unknown falls back to plain open text so it is never
// silently mis-scored. Structural items return null (no variable).
function relicheck_surveydev_logical_type(string $t): ?array
{
    // [logical_type, _builderType]
    static $map = [
        'Likert (5-pt)' => ['likert', ''], 'Likert (7-pt)' => ['likert', ''], 'Likert Scale' => ['likert', ''],
        'NPS' => ['likert', ''], // 0–10 index doubles as the numeric score
        'Rating' => ['open', 'rating'], 'Rating Scale' => ['open', 'rating'],
        'Numeric' => ['open', 'rating'], 'Number' => ['open', 'rating'],
        'Slider' => ['open', 'slider'],
        'Single Choice' => ['single', ''], 'Dropdown' => ['single', ''], 'Demographic' => ['single', ''],
        'Yes/No' => ['single', ''], 'True/False' => ['single', ''], 'Consent' => ['single', ''],
        'Multiple Choice' => ['multi', ''], 'Checkboxes' => ['multi', ''],
        'Ranking' => ['open', 'ranking'],
        'Matrix/Grid' => ['open', 'matrix'], 'Matrix' => ['open', 'matrix'],
        'Open-Ended' => ['open', ''], 'Short Answer' => ['open', ''], 'Long Answer' => ['open', ''],
        'Comment Box' => ['open', ''], 'Email' => ['open', ''], 'Phone' => ['open', ''], 'Date' => ['open', ''],
        // structural / non-scored → no variable
        'Section Text' => null, 'Instructions' => null, 'Page Break' => null, 'Thank-you Message' => null,
    ];
    return array_key_exists($t, $map) ? $map[$t] : ['open', ''];
}

function relicheck_surveydev_build_dataset(PDO $pdo, int $projectId, string $title): array
{
    // ---- items → legacy `questions` ----
    $itStmt = $pdo->prepare('SELECT id, type, prompt, options, settings FROM survey_items WHERE project_id = :id ORDER BY position ASC, id ASC');
    $itStmt->execute([':id' => $projectId]);

    $questions = [];
    foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $logical = relicheck_surveydev_logical_type((string)$it['type']);
        if ($logical === null) continue; // structural

        $opts = ($it['options'] !== null) ? json_decode((string)$it['options'], true) : null;
        $opts = is_array($opts) ? array_values($opts) : [];
        $settings = ($it['settings'] !== null) ? json_decode((string)$it['settings'], true) : null;
        $settings = is_array($settings) ? $settings : [];

        [$type, $builderType] = $logical;

        // Choice / binary types need options to resolve indexes → labels. With
        // no options, treat as open text so we never emit empty categoricals.
        if (($type === 'single' || $type === 'multi') && count($opts) === 0) {
            $type = 'open'; $builderType = '';
        }

        $q = [
            'id'           => (string)$it['id'],
            'type'         => $type,
            '_builderType' => $builderType,
            'prompt'       => (string)$it['prompt'],
            'options'      => $opts,
        ];
        // Construct mapping rides in item.settings as {construct, constructId}.
        if (isset($settings['construct']) && is_string($settings['construct']) && trim($settings['construct']) !== '') {
            $q['construct'] = trim($settings['construct']);
        }
        if (array_key_exists('reverse', $settings))      $q['reverse']      = !empty($settings['reverse']);
        if (isset($settings['likertPoints']) && is_numeric($settings['likertPoints'])) $q['likertPoints'] = (int)$settings['likertPoints'];
        // Matrix rows / ranking items reuse the options list as their labels.
        if ($builderType === 'matrix')  $q['matrixRows']   = $opts;
        if ($builderType === 'ranking') $q['rankingItems'] = $opts;

        $questions[] = $q;
    }

    // ---- sessions + answers → legacy `responses` ----
    $sStmt = $pdo->prepare('SELECT id, submitted_at FROM survey_dev_response_sessions WHERE project_id = :id ORDER BY submitted_at ASC, id ASC');
    $sStmt->execute([':id' => $projectId]);
    $sessions = $sStmt->fetchAll(PDO::FETCH_ASSOC);

    $aStmt = $pdo->prepare('SELECT session_id, item_id, answer_value FROM survey_dev_answers WHERE project_id = :id ORDER BY id');
    $aStmt->execute([':id' => $projectId]);
    $bySession = [];
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $sid = (int)$a['session_id'];
        if ($a['item_id'] === null) continue;
        $bySession[$sid][(string)$a['item_id']] = $a['answer_value']; // raw; legacy builder resolves indexes
    }

    $responses = [];
    foreach ($sessions as $s) {
        $sid = (int)$s['id'];
        $responses[] = [
            'answers'      => $bySession[$sid] ?? [],
            'submitted_at' => (string)$s['submitted_at'],
        ];
    }

    // Delegate to the single tested transform so the shape + type tokens are
    // identical to the legacy path. survey-dev carries construct on each item
    // (set above), so no settings.scales fallback is needed; pass empty config.
    return relicheck_survey_build_dataset($title, $questions, $responses, []);
}

endif;
