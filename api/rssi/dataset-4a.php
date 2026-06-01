<?php
// GET /api/rssi/dataset-4a.php?dataset_id=N
// READ-ONLY ADAPTER. Loads a standalone saved dataset (the `datasets` table used
// by rssi.php / rssi-upload.php) and emits the develop.php Phase-4A dataset shape
// consumed by RSSIEngine.score() — the locked FOUR-domain v1 engine. It does NOT
// compute any score, does NOT mutate the dataset, and changes no formula. The
// field-type / scorable / sample-size-fence rules mirror api/dev/rssi-dataset.php
// so the four-domain score matches the develop.php engine for equivalent data.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['dataset_id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid dataset id.');

$stmt = db()->prepare(
    'SELECT id, owner_id, title, column_meta, settings, data, row_count
       FROM datasets WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Dataset not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

$columnMeta = json_decode((string)$row['column_meta'], true);
$settings   = json_decode((string)$row['settings'], true);
$data       = json_decode((string)$row['data'], true);
if (!is_array($columnMeta) || !is_array($data)) {
    // Clear fallback rather than a silent failure (acceptance requirement).
    fail('no_raw_data',
        'This saved dataset is missing column metadata or raw response rows, so the four-domain score cannot be computed. Re-upload the data to score it.',
        422);
}
$settings = is_array($settings) ? $settings : [];

$RSSI_MIN_N = 30;                    // matches api/dev/rssi-dataset.php
$RSSI_MIN_ITEMS_PER_CONSTRUCT = 3;   // matches api/dev/rssi-dataset.php
$likertPoints = isset($settings['likertPoints']) ? (int)$settings['likertPoints'] : null;

// Standalone column type -> 4A fieldType (mirrors the loader's classification:
// only numeric_scale + binary are scorable for internal consistency).
$fieldTypeOf = function (string $t): string {
    switch ($t) {
        case 'likert': case 'numeric': case 'criterion': return 'numeric_scale';
        case 'binary': return 'binary';
        case 'single': case 'multi': case 'demographic':
        case 'dropdown': case 'checkboxes': case 'ranking': return 'categorical';
        case 'open': case 'free_text': case 'email':
        case 'phone': case 'date': case 'identifier': return 'open_text';
        case 'ignore': return 'structural';
        default: return 'open_text';
    }
};

// Assign stable construct ids from the (string) construct names in column_meta.
$constructIdByName = [];
$constructOrder    = [];
foreach ($columnMeta as $c) {
    $cn = isset($c['construct']) ? trim((string)$c['construct']) : '';
    if ($cn !== '' && !isset($constructIdByName[$cn])) {
        $constructIdByName[$cn] = count($constructIdByName) + 1;
        $constructOrder[] = $cn;
    }
}

$totalN   = count($data);
$sessions = [];
for ($r = 0; $r < $totalN; $r++) $sessions[] = ['id' => $r, 'submitted_at' => ''];

$items              = [];
$itemsByConstructId = [];
$unmappedItemIds    = [];
$sessionHasScorable = [];
$ncols = count($columnMeta);

for ($col = 0; $col < $ncols; $col++) {
    $c    = $columnMeta[$col];
    $name = isset($c['name']) ? (string)$c['name'] : ('Column ' . ($col + 1));
    $type = isset($c['type']) ? (string)$c['type'] : 'open';
    $opts = (isset($c['options']) && is_array($c['options'])) ? array_values($c['options']) : [];
    $fieldType = $fieldTypeOf($type);
    $scorable  = in_array($fieldType, ['numeric_scale', 'binary'], true);
    $cn  = isset($c['construct']) ? trim((string)$c['construct']) : '';
    $cid = ($cn !== '') ? $constructIdByName[$cn] : null;

    $values   = [];
    $answered = 0;
    for ($r = 0; $r < $totalN; $r++) {
        $raw = (isset($data[$r]) && array_key_exists($col, $data[$r]) && $data[$r][$col] !== null)
            ? (string)$data[$r][$col] : '';
        if ($raw === '') continue;
        $resolved = $raw;
        if ($fieldType === 'categorical' && count($opts) > 0 && is_numeric($raw)) {
            $iv = (int)$raw;
            if ($iv >= 0 && $iv < count($opts)) $resolved = (string)$opts[$iv];
        }
        $values[] = ['sessionId' => $r, 'raw' => $raw, 'resolved' => $resolved];
        $answered++;
        if ($scorable) $sessionHasScorable[$r] = true;
    }

    $scale = ($fieldType === 'numeric_scale')
        ? ['points' => $likertPoints, 'min' => null, 'max' => null] : null;

    $item = [
        'id'          => $col,
        'label'       => trim((string)preg_replace('/\s+/', ' ', $name)),
        'type'        => $type,
        'fieldType'   => $fieldType,
        'structural'  => $fieldType === 'structural',
        'scorable'    => $scorable,
        'constructId' => $cid,
        'construct'   => $cn,
        'options'     => $opts,
        'scale'       => $scale,
        'answered'    => $answered,
        'missing'     => $totalN - $answered,
        'values'      => $values,
    ];
    $items[] = $item;
    if (!$item['structural']) {
        if ($cid !== null) $itemsByConstructId[$cid][] = $col;
        else $unmappedItemIds[] = $col;
    }
}

$analyzableN = 0;
for ($r = 0; $r < $totalN; $r++) if (!empty($sessionHasScorable[$r])) $analyzableN++;

$constructs = [];
foreach ($constructOrder as $cn) {
    $cid         = $constructIdByName[$cn];
    $itemIds     = $itemsByConstructId[$cid] ?? [];
    $scorableIds = array_values(array_filter($itemIds, fn($i) => $items[$i]['scorable']));
    $enough      = count($scorableIds) >= $RSSI_MIN_ITEMS_PER_CONSTRUCT;
    $constructs[] = [
        'id'            => $cid,
        'name'          => $cn,
        'definition'    => '',
        'itemIds'       => array_values($itemIds),
        'itemCount'     => count($itemIds),
        'scorableCount' => count($scorableIds),
        'enoughItems'   => $enough,
        'note'          => $enough ? ''
            : 'Not enough scorable items for an internal-consistency claim. RSSI will report this construct as not enough evidence rather than forcing a reliability number.',
    ];
}
$hasConstructMappings = false;
foreach ($constructs as $c) { if ($c['itemCount'] > 0) { $hasConstructMappings = true; break; } }

$tooFew     = $analyzableN < $RSSI_MIN_N;
$fenceNotes = [];
if ($totalN === 0) {
    $fenceNotes[] = 'No responses in this dataset. RSSI cannot produce any reliability evidence.';
} elseif ($tooFew) {
    $fenceNotes[] = 'Only ' . $analyzableN . ' analyzable response' . ($analyzableN === 1 ? '' : 's')
        . ' (threshold is ' . $RSSI_MIN_N . '). RSSI should withhold reliability claims and report "Insufficient data to judge" rather than force a score from too little data.';
} else {
    $fenceNotes[] = $analyzableN . ' analyzable responses meet the minimum of ' . $RSSI_MIN_N
        . ' for reliability analysis. RSSI can proceed, reporting cautions where individual constructs are thin.';
}
if (!$hasConstructMappings) {
    $fenceNotes[] = 'No item-to-construct mappings in this saved dataset. RSSI falls back to whole-survey evidence; tag constructs to unlock the per-construct labs.';
}

$fieldTypeSummary = ['numeric_scale' => 0, 'categorical' => 0, 'binary' => 0, 'open_text' => 0, 'structural' => 0];
foreach ($items as $it) { $fieldTypeSummary[$it['fieldType']]++; }

json_out([
    'ok'          => true,
    'phase'       => '4A-adapter',
    'adapter'     => 'standalone-dataset',
    'projectId'   => (int)$row['id'],
    'projectName' => (string)($row['title'] ?? ''),
    'responses'   => [
        'total_n'           => $totalN,
        'analyzable_n'      => $analyzableN,
        'min_n'             => $RSSI_MIN_N,
        'too_few_responses' => $tooFew,
        'fence_notes'       => $fenceNotes,
    ],
    'counts' => [
        'sessions'          => $totalN,
        'items_total'       => count($items),
        'items_scorable'    => count(array_filter($items, fn($r) => $r['scorable'])),
        'constructs'        => count($constructs),
        'constructs_mapped' => count(array_filter($constructs, fn($c) => $c['itemCount'] > 0)),
        'items_unmapped'    => count($unmappedItemIds),
    ],
    'fieldTypeSummary' => $fieldTypeSummary,
    'sessions'         => $sessions,
    'items'            => $items,
    'constructs'       => $constructs,
    'unmappedItemIds'  => $unmappedItemIds,
]);
