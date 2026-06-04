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
require_once __DIR__ . '/_type_taxonomy.php';

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

// ── Field-type classification — two-tier resolution ─────────────────────────
// Tier 1 (preferred): if the project has been through the Data Map step and
//   variable_metadata rows exist, derive fieldType from the canonical
//   analysis_type stored there. Also reads construct_id, reverse_scored, and
//   include_in_analysis directly from the metadata row.
// Tier 2 (legacy fallback): projects with no saved metadata use the display-type
//   map below. Nothing breaks for existing projects; new projects get richer data.

// Legacy display-type → RSSI fieldType (unchanged from Phase 4A — fallback only).
$FIELD_TYPES = [
    'Likert (5-pt)' => 'numeric_scale', 'Likert (7-pt)' => 'numeric_scale',
    'Likert Scale'  => 'numeric_scale', 'Rating' => 'numeric_scale',
    'Rating Scale'  => 'numeric_scale', 'NPS' => 'numeric_scale',
    'Slider'        => 'numeric_scale', 'Numeric' => 'numeric_scale',
    'Number'        => 'numeric_scale',
    'Yes/No'    => 'binary', 'True/False' => 'binary', 'Consent' => 'binary',
    'Single Choice'   => 'categorical', 'Multiple Choice' => 'categorical',
    'Dropdown'        => 'categorical', 'Demographic' => 'categorical',
    'Checkboxes'      => 'categorical', 'Ranking' => 'categorical',
    'Matrix/Grid'     => 'categorical', 'Matrix' => 'categorical',
    'Open-Ended'      => 'open_text',
    'Short Answer' => 'open_text', 'Long Answer' => 'open_text',
    'Comment Box'  => 'open_text', 'Email' => 'open_text',
    'Phone'        => 'open_text', 'Date' => 'open_text',
    'Section Text' => 'structural', 'Instructions' => 'structural',
    'Page Break'   => 'structural', 'Thank-you Message' => 'structural',
];
$fieldTypeOf = function (string $type) use ($FIELD_TYPES): string {
    return $FIELD_TYPES[$type] ?? 'open_text';
};

// Canonical resolution: analysis_type → RSSI fieldType.
// likert_item is only numeric_scale (scorable) when construct-assigned;
// without a construct it is treated as categorical so it can't accidentally
// inflate a reliability score.
function rssi_field_type_from_analysis(string $analysisType, ?int $constructId): string
{
    switch ($analysisType) {
        case 'likert_item':
            return $constructId !== null ? 'numeric_scale' : 'categorical';
        case 'scale_score':
        case 'computed_score':
        case 'demographic_numeric':
            return 'numeric_scale';
        case 'binary':
            return 'binary';
        case 'demographic_nominal':
        case 'demographic_ordinal':
            return 'categorical';
        case 'open_ended':
        case 'narrative':
        case 'qualitative_code':
        case 'theme':
            return 'open_text';
        default:  // identifier, structural, metadata, date_time, file_reference
            return 'structural';
    }
}

// ── Uploaded-dataset path ────────────────────────────────────────────────────
// When the project has a linked uploaded dataset, build the normalized dataset
// from the datasets table (column_meta + data) instead of from internal
// response sessions. The output shape is identical so the RSSI engine,
// dataset screen, and rssi-run.php all work unchanged.
$linkedDatasetId = isset($project['dataset_id']) && $project['dataset_id'] !== null
    ? (int)$project['dataset_id'] : null;

if ($linkedDatasetId !== null) {
    $dsStmt = $pdo->prepare(
        'SELECT title, column_meta, data, row_count FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1'
    );
    $dsStmt->execute([':id' => $linkedDatasetId, ':uid' => (int)$user['id']]);
    $dsRow = $dsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dsRow) fail('dataset_not_found', 'Linked dataset not found or you do not own it.', 404);

    $cm       = $dsRow['column_meta'];
    if (is_string($cm))   $cm       = json_decode($cm, true);
    $dataRows = $dsRow['data'];
    if (is_string($dataRows)) $dataRows = json_decode($dataRows, true);
    if (!is_array($cm))       $cm       = [];
    if (!is_array($dataRows)) $dataRows = [];

    // Legacy dataset type → RSSI fieldType (for datasets without analysis_type).
    $LEGACY_DS_TYPE = [
        'likert'      => 'numeric_scale',
        'numeric'     => 'numeric_scale',
        'criterion'   => 'numeric_scale',
        'demographic' => 'categorical',
        'single'      => 'categorical',
        'multi'       => 'categorical',
        'binary'      => 'binary',
        'open'        => 'open_text',
        'identifier'  => 'structural',
        'ignore'      => 'structural',
    ];

    // Assign synthetic integer construct ids to construct name strings so the
    // output shape matches the internal-responses path (which uses DB ids).
    $constructNameToId = [];
    foreach ($cm as $col) {
        $cname = trim((string)($col['construct'] ?? ''));
        if ($cname !== '' && !isset($constructNameToId[$cname])) {
            $constructNameToId[$cname] = count($constructNameToId) + 1;
        }
    }

    // Build items from column_meta.
    $items = [];
    foreach ($cm as $ci => $col) {
        $analysisType = isset($col['analysis_type']) && $col['analysis_type'] !== ''
            ? (string)$col['analysis_type'] : null;
        $cname        = trim((string)($col['construct'] ?? ''));
        $constructId  = ($cname !== '' && isset($constructNameToId[$cname]))
            ? $constructNameToId[$cname] : null;
        if ($analysisType !== null) {
            $fieldType = rssi_field_type_from_analysis($analysisType, $constructId);
        } else {
            $legacyType = strtolower(trim((string)($col['type'] ?? '')));
            $fieldType  = $LEGACY_DS_TYPE[$legacyType] ?? 'open_text';
        }
        $items[$ci] = [
            'id'                => $ci,
            'label'             => (string)($col['name'] ?? ('Column ' . ($ci + 1))),
            'type'              => (string)($col['type'] ?? 'open'),
            'analysisType'      => $analysisType,
            'fieldType'         => $fieldType,
            'structural'        => $fieldType === 'structural',
            'scorable'          => in_array($fieldType, ['numeric_scale', 'binary'], true),
            'reverseScored'     => !empty($col['reverse']),
            'includeInAnalysis' => true,
            'constructId'       => $constructId,
            'construct'         => $cname,
            'options'           => is_array($col['options'] ?? null) ? $col['options'] : [],
            'scale'             => null,
            'answered'          => 0,
            'missing'           => 0,
            'values'            => [],
        ];
    }

    // Build sessions and attach values from data rows.
    $sessions    = [];
    $totalN      = 0;
    $analyzableN = 0;
    foreach ($dataRows as $ri => $row) {
        if (!is_array($row)) continue;
        $sessions[] = ['id' => $ri, 'submitted_at' => ''];
        $totalN++;
        $rowHasScorable = false;
        foreach ($items as $ci => &$item) {
            $val = isset($row[$ci]) ? trim((string)$row[$ci]) : '';
            if ($val === '') {
                $item['missing']++;
            } else {
                $item['answered']++;
                $item['values'][] = ['sessionId' => $ri, 'raw' => $val, 'resolved' => $val];
                if ($item['scorable']) $rowHasScorable = true;
            }
        }
        unset($item);
        if ($rowHasScorable) $analyzableN++;
    }

    // Build constructs from synthetic name map.
    $constructById = [];
    foreach ($constructNameToId as $cname => $cid) {
        $constructById[$cid] = [
            'id'           => $cid,
            'name'         => $cname,
            'definition'   => '',
            'itemIds'      => [],
            'itemCount'    => 0,
            'scorableCount'=> 0,
            'enoughItems'  => false,
            'note'         => '',
        ];
    }
    foreach ($items as $ci => $item) {
        if ($item['structural'] || $item['constructId'] === null) continue;
        $cid = $item['constructId'];
        $constructById[$cid]['itemIds'][]   = $ci;
        $constructById[$cid]['itemCount']++;
        if ($item['scorable']) $constructById[$cid]['scorableCount']++;
    }
    $constructs = array_values(array_map(function ($con) use ($RSSI_MIN_ITEMS_PER_CONSTRUCT) {
        $enough = $con['scorableCount'] >= $RSSI_MIN_ITEMS_PER_CONSTRUCT;
        return array_merge($con, [
            'enoughItems' => $enough,
            'note' => $enough ? '' :
                'Not enough scorable items for an internal-consistency claim. RSSI will report this construct as not enough evidence rather than forcing a reliability number.',
        ]);
    }, $constructById));

    // Shared output: fence, counts, field-type summary.
    $tooFew     = $analyzableN < $RSSI_MIN_N;
    $fenceNotes = [];
    if ($totalN === 0) {
        $fenceNotes[] = 'No rows in the uploaded dataset. Check the file and re-upload.';
    } elseif ($tooFew) {
        $fenceNotes[] = 'Only ' . $analyzableN . ' analyzable row' . ($analyzableN === 1 ? '' : 's')
            . ' (threshold is ' . $RSSI_MIN_N . '). RSSI should withhold reliability claims rather than force a score from too little data.';
    } else {
        $fenceNotes[] = $analyzableN . ' analyzable rows meet the minimum of ' . $RSSI_MIN_N . ' for reliability analysis.';
    }
    $hasConstructMappings = !empty($constructs) && array_sum(array_column($constructs, 'itemCount')) > 0;
    if (!$hasConstructMappings) {
        $fenceNotes[] = 'No column-to-construct mappings found. RSSI will fall back to whole-survey evidence where possible.';
    }

    $fieldTypeSummary = ['numeric_scale' => 0, 'categorical' => 0, 'binary' => 0, 'open_text' => 0, 'structural' => 0];
    foreach ($items as $it) { $fieldTypeSummary[$it['fieldType']]++; }

    $inputItems      = array_values(array_filter($items, fn($r) => !$r['structural']));
    $unmappedItemIds = [];
    foreach ($items as $ci => $it) {
        if (!$it['structural'] && $it['constructId'] === null) $unmappedItemIds[] = $ci;
    }

    json_out([
        'ok'              => true,
        'phase'           => '4A',
        'note'            => 'RSSI Dataset Loader (uploaded dataset). Normalized analysis-ready dataset only.',
        'projectId'       => $projectId,
        'linkedDatasetId' => $linkedDatasetId,
        'datasetTitle'    => (string)$dsRow['title'],
        'hasVarMeta'      => false,
        'projectName'     => (string)($project['title'] ?? ''),
        'responses' => [
            'total_n'           => $totalN,
            'analyzable_n'      => $analyzableN,
            'min_n'             => $RSSI_MIN_N,
            'too_few_responses' => $tooFew,
            'fence_notes'       => $fenceNotes,
        ],
        'counts' => [
            'sessions'          => $totalN,
            'items_total'       => count($items),
            'items_input'       => count($inputItems),
            'items_scorable'    => count(array_filter($items, fn($r) => $r['scorable'])),
            'constructs'        => count($constructs),
            'constructs_mapped' => count(array_filter($constructs, fn($c) => $c['itemCount'] > 0)),
            'items_unmapped'    => count($unmappedItemIds),
        ],
        'fieldTypeSummary' => $fieldTypeSummary,
        'sessions'         => $sessions,
        'items'            => array_values($items),
        'constructs'       => $constructs,
        'unmappedItemIds'  => $unmappedItemIds,
    ]);
}

// ── Internal-responses path (no linked dataset) ──────────────────────────────

// Load variable_metadata for this project (survey type, item-linked rows only).
$vmStmt = $pdo->prepare(
    'SELECT survey_item_id, analysis_type, construct_id, reverse_scored, include_in_analysis
       FROM variable_metadata
      WHERE project_id = :pid AND project_type = :ptype AND survey_item_id IS NOT NULL'
);
$vmStmt->execute([':pid' => $projectId, ':ptype' => 'survey']);
$vmByItemId = [];
foreach ($vmStmt->fetchAll(PDO::FETCH_ASSOC) as $vmr) {
    $vmByItemId[(int)$vmr['survey_item_id']] = $vmr;
}
$hasVarMeta = count($vmByItemId) > 0;

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
    // Tier-1: use variable_metadata when the project has been through the Data Map.
    // Tier-2: fall back to the legacy display-type map.
    $reverseScored      = false;
    $includeInAnalysis  = true;
    $analysisType       = null;
    if ($hasVarMeta && isset($vmByItemId[$id])) {
        $vm             = $vmByItemId[$id];
        $vmConstructId  = $vm['construct_id'] !== null ? (int)$vm['construct_id'] : null;
        $analysisType   = (string)($vm['analysis_type'] ?? 'open_ended');
        $fieldType      = rssi_field_type_from_analysis($analysisType, $vmConstructId);
        $reverseScored  = (bool)$vm['reverse_scored'];
        $includeInAnalysis = (bool)$vm['include_in_analysis'];
        // Prefer variable_metadata construct; fall back to settings JSON.
        $constructId    = $vmConstructId ?? (isset($settings['constructId']) && $settings['constructId'] !== '' ? (int)$settings['constructId'] : null);
    } else {
        $fieldType   = $fieldTypeOf($type);
        $constructId = isset($settings['constructId']) && $settings['constructId'] !== '' ? (int)$settings['constructId'] : null;
    }
    $constructName = isset($settings['construct']) ? (string)$settings['construct'] : '';

    // Scale metadata preserved for the scorer (points/min/max where defined).
    $scale = null;
    if ($fieldType === 'numeric_scale') {
        $scale = [
            'points' => isset($settings['points']) ? (int)$settings['points'] : null,
            'min'    => isset($settings['min']) ? $settings['min'] : null,
            'max'    => isset($settings['max']) ? $settings['max'] : null,
        ];
    }

    $items[$id] = [
        'id'               => $id,
        'label'            => trim(preg_replace('/\s+/', ' ', (string)$it['prompt'])),
        'type'             => $type,
        'analysisType'     => $analysisType,   // canonical RE type (null when legacy fallback)
        'fieldType'        => $fieldType,
        'structural'       => $fieldType === 'structural',
        // scorable = ordered numeric or binary, AND not excluded by the Data Map
        'scorable'         => in_array($fieldType, ['numeric_scale', 'binary'], true) && $includeInAnalysis,
        'reverseScored'    => $reverseScored,
        'includeInAnalysis'=> $includeInAnalysis,
        'constructId'      => $constructId,
        'construct'        => $constructName,
        'options'          => $opts,
        'scale'            => $scale,
        'answered'         => 0,
        'missing'          => 0,
        'values'           => [],   // {sessionId, raw, resolved}, filled below
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
    'ok'              => true,
    'phase'           => '4A',
    'note'            => 'RSSI Dataset Loader. Normalized analysis-ready dataset only. No RSSI score, no Cronbach alpha, no reliability statistic is computed here.',
    'projectId'       => $projectId,
    'linkedDatasetId' => null,
    'datasetTitle'    => null,
    'hasVarMeta'      => $hasVarMeta,   // true = canonical RE types used; false = legacy display-type fallback
    'projectName'     => (string)($project['title'] ?? ''),
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
