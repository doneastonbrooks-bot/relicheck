<?php
// GET /api/dev/rssi-dataset.php?project_id={id}
// Authenticated, owner-gated. Phase 4A: RSSI Dataset Loader.
//
// Loads the project's stored public responses into a normalized, analysis-ready
// dataset object that RSSI can LATER score. This endpoint does NOT compute the
// RSSI score, Cronbach alpha, or any reliability statistic. It only joins, shapes,
// classifies, counts, and reports N-adequacy fence information.
//
// RSSI = ReliCheck Survey Strength Index (post-response evidence strength;
// reliability is one core domain, not the whole). No mock data: everything is
// read live from survey_items + survey_constructs + survey_dev_response_sessions
// + survey_dev_answers. The respondent IP hash and user agent are NEVER returned.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// Owner check: 403 if the caller does not own this project, 404 if absent.
$project = sds_require_project($pdo, (int)$user['id'], $projectId);

// ── N-adequacy fence threshold ──────────────────────────────────────────────
// Below this many analyzable responses RSSI should withhold reliability claims
// rather than force a number (the alpha-fence stance). Phase 4A only flags it;
// the scoring engine in a later phase consumes the flag.
$RSSI_MIN_N = 30;
// A construct needs at least this many scorable items before internal-consistency
// claims are meaningful. Phase 4A only flags it.
$RSSI_MIN_ITEMS_PER_CONSTRUCT = 3;

// ── Field-type classification ───────────────────────────────────────────────
// Map each builder item type to an RSSI field type. Anything not listed falls
// back to open_text so an unknown type is never silently treated as scorable.
$FIELD_TYPES = [
    // numeric scale: ordered/interval responses usable for internal consistency
    'Likert (5-pt)' => 'numeric_scale', 'Likert (7-pt)' => 'numeric_scale',
    'Likert Scale'  => 'numeric_scale', 'Rating' => 'numeric_scale',
    'Rating Scale'  => 'numeric_scale', 'NPS' => 'numeric_scale',
    'Slider'        => 'numeric_scale', 'Numeric' => 'numeric_scale',
    'Number'        => 'numeric_scale',
    // binary: two-level responses (stored as a 0/1 index for the choice ones)
    'Yes/No'    => 'binary', 'True/False' => 'binary', 'Consent' => 'binary',
    // categorical: nominal choices, not usable for internal consistency
    'Single Choice'   => 'categorical', 'Multiple Choice' => 'categorical',
    'Dropdown'        => 'categorical', 'Demographic' => 'categorical',
    'Checkboxes'      => 'categorical', 'Ranking' => 'categorical',
    'Matrix/Grid'     => 'categorical', 'Matrix' => 'categorical',
    'Open-Ended'      => 'open_text',
    // open text
    'Short Answer' => 'open_text', 'Long Answer' => 'open_text',
    'Comment Box'  => 'open_text', 'Email' => 'open_text',
    'Phone'        => 'open_text', 'Date' => 'open_text',
    // structural / non-scored
    'Section Text' => 'structural', 'Instructions' => 'structural',
    'Page Break'   => 'structural', 'Thank-you Message' => 'structural',
];
$fieldTypeOf = function (string $type) use ($FIELD_TYPES): string {
    return $FIELD_TYPES[$type] ?? 'open_text';
};

// ── Load items (carry the item -> construct mapping out of settings JSON) ────
// survey_items has no construct column; the mapping rides inside settings JSON as
// {construct: name, constructId: id}, written by develop.php DB.itemSettingsOut().
$itStmt = $pdo->prepare(
    'SELECT id, type, prompt, options, settings, position
       FROM survey_items WHERE project_id = :id ORDER BY position, id'
);
$itStmt->execute([':id' => $projectId]);

$items    = [];   // ordered, normalized item records
$itemMeta = [];   // id => [type, options[], fieldType] for answer resolution
foreach ($itStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
    $id       = (int)$it['id'];
    $type     = (string)$it['type'];
    $opts     = ($it['options'] !== null) ? json_decode((string)$it['options'], true) : null;
    $opts     = is_array($opts) ? array_values($opts) : [];
    $settings = ($it['settings'] !== null) ? json_decode((string)$it['settings'], true) : null;
    $settings = is_array($settings) ? $settings : [];
    $fieldType = $fieldTypeOf($type);

    // Scale metadata preserved for the scorer (points/min/max where defined).
    $scale = null;
    if ($fieldType === 'numeric_scale') {
        $scale = [
            'points' => isset($settings['points']) ? (int)$settings['points'] : null,
            'min'    => isset($settings['min']) ? $settings['min'] : null,
            'max'    => isset($settings['max']) ? $settings['max'] : null,
        ];
    }

    $constructId   = isset($settings['constructId']) && $settings['constructId'] !== '' ? (int)$settings['constructId'] : null;
    $constructName = isset($settings['construct']) ? (string)$settings['construct'] : '';

    $items[$id] = [
        'id'          => $id,
        'label'       => trim(preg_replace('/\s+/', ' ', (string)$it['prompt'])),
        'type'        => $type,
        'fieldType'   => $fieldType,
        'structural'  => $fieldType === 'structural',
        // scorable for internal-consistency = ordered numeric or binary input
        'scorable'    => in_array($fieldType, ['numeric_scale', 'binary'], true),
        'constructId' => $constructId,
        'construct'   => $constructName,
        'options'     => $opts,
        'scale'       => $scale,
        'answered'    => 0,
        'missing'     => 0,
        'values'      => [],   // {sessionId, raw, resolved}, filled below
    ];
    $itemMeta[$id] = ['type' => $type, 'options' => $opts, 'fieldType' => $fieldType];
}

// ── Resolve a stored answer value to display text ───────────────────────────
// Mirrors api/dev/project-responses.php: choice answers are stored as option
// INDEXES and resolved back to the option text; everything else is shown as-is.
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

// ── Load constructs (definitions) ───────────────────────────────────────────
$consStmt = $pdo->prepare(
    'SELECT id, position, name, definition FROM survey_constructs
      WHERE project_id = :id ORDER BY position, id'
);
$consStmt->execute([':id' => $projectId]);
$constructRows = $consStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Load sessions (no ip_hash, no user_agent) ───────────────────────────────
$sStmt = $pdo->prepare(
    'SELECT id, submitted_at FROM survey_dev_response_sessions
      WHERE project_id = :id ORDER BY submitted_at ASC, id ASC'
);
$sStmt->execute([':id' => $projectId]);
$sessionRows = $sStmt->fetchAll(PDO::FETCH_ASSOC);
$sessions = array_map(function ($s) {
    return ['id' => (int)$s['id'], 'submitted_at' => (string)$s['submitted_at']];
}, $sessionRows);
$sessionIds = array_map(fn($s) => $s['id'], $sessions);
$totalN = count($sessions);

// ── Load answers and attach them to their items ─────────────────────────────
$aStmt = $pdo->prepare(
    'SELECT session_id, item_id, answer_value FROM survey_dev_answers
      WHERE project_id = :id ORDER BY id'
);
$aStmt->execute([':id' => $projectId]);

// Track, per session, whether it answered at least one scorable item (defines
// analyzable_n) and which items each session answered (for missingness).
$answeredItemsBySession = [];   // sid => set(item_id)
$sessionHasScorable = [];       // sid => bool
foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $sid    = (int)$a['session_id'];
    $itemId = $a['item_id'] !== null ? (int)$a['item_id'] : null;
    if ($itemId === null || !isset($items[$itemId])) continue;
    $raw = $a['answer_value'] !== null ? (string)$a['answer_value'] : '';
    if ($raw === '') continue;   // blank value is not an answer

    $items[$itemId]['values'][] = [
        'sessionId' => $sid,
        'raw'       => $raw,
        'resolved'  => $displayValue($itemId, $raw),
    ];
    $items[$itemId]['answered']++;
    $answeredItemsBySession[$sid][$itemId] = true;
    if ($items[$itemId]['scorable']) $sessionHasScorable[$sid] = true;
}

// Missing per item = sessions that did not answer this item.
foreach ($items as $id => &$rec) {
    $rec['missing'] = $totalN - $rec['answered'];
}
unset($rec);

$analyzableN = 0;
foreach ($sessionIds as $sid) {
    if (!empty($sessionHasScorable[$sid])) $analyzableN++;
}

// ── Group items by construct (construct-first) ──────────────────────────────
$itemsByConstructId = [];
$unmappedItemIds = [];
foreach ($items as $id => $rec) {
    if ($rec['structural']) continue;   // structural items never join a construct
    if ($rec['constructId'] !== null) {
        $itemsByConstructId[$rec['constructId']][] = $id;
    } else {
        $unmappedItemIds[] = $id;
    }
}

$constructs = array_map(function ($c) use ($itemsByConstructId, $items, $RSSI_MIN_ITEMS_PER_CONSTRUCT) {
    $cid     = (int)$c['id'];
    $itemIds = $itemsByConstructId[$cid] ?? [];
    $scorableIds = array_values(array_filter($itemIds, fn($i) => $items[$i]['scorable']));
    $enoughItems = count($scorableIds) >= $RSSI_MIN_ITEMS_PER_CONSTRUCT;
    $note = $enoughItems
        ? ''
        : 'Not enough scorable items for an internal-consistency claim. RSSI will report this construct as not enough evidence rather than forcing a reliability number.';
    return [
        'id'             => $cid,
        'name'           => (string)$c['name'],
        'definition'     => (string)($c['definition'] ?? ''),
        'itemIds'        => array_values($itemIds),
        'itemCount'      => count($itemIds),
        'scorableCount'  => count($scorableIds),
        'enoughItems'    => $enoughItems,
        'note'           => $note,
    ];
}, $constructRows);

$hasConstructMappings = false;
foreach ($constructs as $c) { if ($c['itemCount'] > 0) { $hasConstructMappings = true; break; } }

// ── N-adequacy fence ────────────────────────────────────────────────────────
$tooFew = $analyzableN < $RSSI_MIN_N;
$fenceNotes = [];
if ($totalN === 0) {
    $fenceNotes[] = 'No responses collected yet. RSSI cannot produce any reliability evidence until responses are stored.';
} elseif ($tooFew) {
    $fenceNotes[] = 'Only ' . $analyzableN . ' analyzable response' . ($analyzableN === 1 ? '' : 's')
        . ' (threshold is ' . $RSSI_MIN_N . '). RSSI should withhold reliability claims and report "Insufficient data to judge" rather than force a score from too little data.';
} else {
    $fenceNotes[] = $analyzableN . ' analyzable responses meet the minimum of ' . $RSSI_MIN_N . ' for reliability analysis. RSSI can proceed, reporting cautions where individual constructs are thin.';
}
if (!$hasConstructMappings) {
    $fenceNotes[] = 'No item-to-construct mappings found. RSSI will fall back to whole-survey evidence where possible instead of reporting by construct.';
}

// ── Field-type summary ──────────────────────────────────────────────────────
$fieldTypeSummary = ['numeric_scale' => 0, 'categorical' => 0, 'binary' => 0, 'open_text' => 0, 'structural' => 0];
foreach ($items as $rec) { $fieldTypeSummary[$rec['fieldType']]++; }

$inputItems = array_values(array_filter($items, fn($r) => !$r['structural']));

// ── Emit the normalized dataset (no mock; live data only) ───────────────────
json_out([
    'ok'        => true,
    'phase'     => '4A',
    'note'      => 'RSSI Dataset Loader. Normalized analysis-ready dataset only. No RSSI score, no Cronbach alpha, no reliability statistic is computed here.',
    'projectId' => $projectId,
    'projectName' => (string)($project['title'] ?? ''),
    'responses' => [
        'total_n'           => $totalN,
        'analyzable_n'      => $analyzableN,
        'min_n'             => $RSSI_MIN_N,
        'too_few_responses' => $tooFew,
        'fence_notes'       => $fenceNotes,
    ],
    'counts' => [
        'sessions'       => $totalN,
        'items_total'    => count($items),
        'items_input'    => count($inputItems),
        'items_scorable' => count(array_filter($items, fn($r) => $r['scorable'])),
        'constructs'     => count($constructs),
        'constructs_mapped' => count(array_filter($constructs, fn($c) => $c['itemCount'] > 0)),
        'items_unmapped' => count($unmappedItemIds),
    ],
    'fieldTypeSummary' => $fieldTypeSummary,
    'sessions'   => $sessions,
    'items'      => array_values($items),
    'constructs' => $constructs,
    'unmappedItemIds' => $unmappedItemIds,
]);
