<?php
// GET /api/public/survey-dev.php?link_key={key}
// Public endpoint. Looks up a dev survey project by its link_key stored in
// deployment_settings and returns the survey data shaped for take.html.
// Returns {closed:true} with HTTP 403 when responses_open=false.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

$linkKey = isset($_GET['link_key']) ? trim((string)$_GET['link_key']) : '';
if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $linkKey)) {
    fail('bad_key', 'Invalid survey link.', 400);
}

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT project_id, settings FROM deployment_settings
     WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.link_key')) = :k
     LIMIT 1"
);
$stmt->execute([':k' => $linkKey]);
$dsRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dsRow) fail('not_found', 'No survey was found at that link.', 404);

$ds         = json_decode((string)$dsRow['settings'], true) ?: [];
$projectId  = (int)$dsRow['project_id'];

// Phase 3C gate: check responses_open.
if (empty($ds['responses_open'])) {
    fail('not_open', 'This survey is not yet open for responses.', 403, ['closed' => true]);
}

// Load project.
$pStmt = $pdo->prepare(
    'SELECT title, purpose, population, response_mode, status, settings
       FROM survey_projects WHERE id = :id LIMIT 1'
);
$pStmt->execute([':id' => $projectId]);
$project = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$project || $project['status'] !== 'published') {
    fail('not_found', 'No survey was found at that link.', 404);
}

$projSettings = ($project['settings'] !== null)
    ? (json_decode((string)$project['settings'], true) ?: [])
    : [];
$lr = is_array($projSettings['launchReadiness'] ?? null) ? $projSettings['launchReadiness'] : [];

// Load items ordered by position.
$iStmt = $pdo->prepare(
    'SELECT id, type, prompt, options, required, settings
       FROM survey_items
      WHERE project_id = :id ORDER BY position, id'
);
$iStmt->execute([':id' => $projectId]);
$rawItems = $iStmt->fetchAll(PDO::FETCH_ASSOC);

// Map dev item types to the shape take.html already understands.
function dev_map_item(array $item): array
{
    $type   = (string)$item['type'];
    $opts   = ($item['options']  !== null) ? json_decode((string)$item['options'],  true) : null;
    $iset   = ($item['settings'] !== null) ? json_decode((string)$item['settings'], true) : [];
    if (!is_array($iset)) $iset = [];
    $id     = 'i' . $item['id'];
    $prompt = (string)$item['prompt'];
    $req    = (bool)$item['required'];

    // Section heading or page break: no input.
    if (in_array($type, ['Section Text', 'Instructions', 'Page Break', 'Thank-you Message'], true)) {
        return ['id' => $id, 'type' => 'section', 'prompt' => $prompt, 'required' => false];
    }
    // Consent: dedicated checkbox type.
    if ($type === 'Consent') {
        return ['id' => $id, 'type' => 'consent', 'prompt' => $prompt, 'required' => $req];
    }
    // Likert scales.
    if (in_array($type, ['Likert Scale', 'Likert (5-pt)', 'Likert (7-pt)'], true)) {
        $pts  = ($type === 'Likert (7-pt)') ? 7 : (isset($iset['likertPoints']) ? (int)$iset['likertPoints'] : 5);
        $low  = (string)($iset['likertLow']  ?? 'Strongly Disagree');
        $high = (string)($iset['likertHigh'] ?? 'Strongly Agree');
        return ['id' => $id, 'type' => 'likert', 'prompt' => $prompt, 'required' => $req,
                'likertPoints' => $pts, 'likertLow' => $low, 'likertHigh' => $high];
    }
    // Binary choices.
    if ($type === 'Yes/No') {
        return ['id' => $id, 'type' => 'single', 'prompt' => $prompt, 'required' => $req,
                'options' => ['Yes', 'No']];
    }
    if ($type === 'True/False') {
        return ['id' => $id, 'type' => 'single', 'prompt' => $prompt, 'required' => $req,
                'options' => ['True', 'False']];
    }
    // Multiple Choice / Single Choice / Dropdown.
    if (in_array($type, ['Multiple Choice', 'Single Choice', 'Dropdown'], true)) {
        $options = is_array($opts) ? array_values($opts) : [];
        return ['id' => $id, 'type' => 'single', 'prompt' => $prompt, 'required' => $req,
                'options' => $options];
    }
    // Checkboxes → multi.
    if ($type === 'Checkboxes') {
        $options = is_array($opts) ? array_values($opts) : [];
        return ['id' => $id, 'type' => 'multi', 'prompt' => $prompt, 'required' => $req,
                'options' => $options];
    }
    // NPS 0-10.
    if ($type === 'NPS') {
        return ['id' => $id, 'type' => 'single', 'prompt' => $prompt, 'required' => $req,
                'options' => ['0','1','2','3','4','5','6','7','8','9','10']];
    }
    // Rating / star.
    if (in_array($type, ['Rating Scale', 'Rating'], true)) {
        $stars = isset($iset['ratingStars']) ? (int)$iset['ratingStars'] : 5;
        return ['id' => $id, 'type' => 'open', 'prompt' => $prompt, 'required' => $req,
                '_builderType' => 'rating', 'ratingStars' => $stars];
    }
    // Long text.
    if (in_array($type, ['Long Answer', 'Long Text'], true)) {
        return ['id' => $id, 'type' => 'open', 'prompt' => $prompt, 'required' => $req,
                '_builderType' => 'long'];
    }
    // Short text and all other open types.
    return ['id' => $id, 'type' => 'open', 'prompt' => $prompt, 'required' => $req];
}

$questions = array_map('dev_map_item', $rawItems);

// Skip patterns / display logic: attach each item's showIf rule (authored in the
// builder, stored in settings) so take.html can show/hide the question as the
// respondent answers. The trigger id is normalised to the public 'i'<id> form.
foreach ($rawItems as $k => $ri) {
    $iset = ($ri['settings'] !== null) ? json_decode((string)$ri['settings'], true) : [];
    if (is_array($iset) && isset($iset['showIf']) && is_array($iset['showIf'])) {
        $si     = $iset['showIf'];
        $trigId = isset($si['questionId']) ? (int)$si['questionId'] : 0;
        $op     = (string)($si['op'] ?? 'equals');
        if ($trigId > 0 && in_array($op, ['equals', 'not_equals'], true)) {
            $questions[$k]['showIf'] = [
                'questionId' => 'i' . $trigId,
                'op'         => $op,
                'value'      => $si['value'] ?? null,
            ];
        }
    }
}

// Response mode → default Likert anchors.
$mode = (string)$project['response_mode'];
$defaultPts  = (strpos($mode, '7-pt') !== false) ? 7 : 5;
$defaultLow  = 'Strongly Disagree';
$defaultHigh = 'Strongly Agree';
if (strpos($mode, 'freq') !== false) { $defaultLow = 'Never'; $defaultHigh = 'Always'; }
elseif (strpos($mode, 'satisf') !== false) { $defaultLow = 'Very Dissatisfied'; $defaultHigh = 'Very Satisfied'; }

// Build a cover page from launch readiness (consent + instructions).
$consentStatement = trim((string)(($lr['consent']      ?? [])['statement'] ?? ''));
$instructionsText = trim((string)(($lr['instructions'] ?? [])['text']      ?? ''));
$introEnabled     = $lr['introEnabled'] ?? null; // null = legacy (before the toggle existed)
$coverPage = null;
// Show cover page if: explicitly enabled, OR legacy project that already had content (introEnabled was null).
$showCover = ($introEnabled === true) || ($introEnabled === null && ($consentStatement !== '' || $instructionsText !== ''));
if ($showCover && ($consentStatement !== '' || $instructionsText !== '')) {
    $body = '';
    if ($instructionsText !== '') $body = $instructionsText;
    if ($consentStatement !== '') {
        if ($body !== '') $body .= "\n\n";
        $body .= "Data privacy and consent:\n" . $consentStatement;
    }
    $hasConsent = ($consentStatement !== '');
    $coverPage = [
        'enabled'      => true,
        'body'         => $body,
        'consentMode'  => $hasConsent ? 'required' : 'none',
        'consentLabel' => 'I have read the above and agree to participate',
    ];
}

$hideBrand = !empty($projSettings['hideBrand']);

$settings = [
    'likertPoints' => $defaultPts,
    'likertLow'    => $defaultLow,
    'likertHigh'   => $defaultHigh,
];
if ($coverPage !== null) $settings['coverPage'] = $coverPage;
if ($hideBrand) $settings['hideBrand'] = true;

json_out([
    'ok'     => true,
    'survey' => [
        'title'        => (string)$project['title'],
        'description'  => (string)($project['purpose'] ?? ''),
        'settings'     => $settings,
        'questions'    => $questions,
        '_isDevSurvey' => true,
    ],
]);
