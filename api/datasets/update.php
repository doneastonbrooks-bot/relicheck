<?php
// PATCH /api/datasets/update.php
// Body: { id, title?, column_meta?, settings? }
// Owner-only. Updates only the fields present in the body. The data column
// is not editable here; users would re-upload to replace the data.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('PATCH', 'POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid dataset id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT owner_id FROM datasets WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Dataset not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

$fields = [];
$params = [':id' => $id];

if (array_key_exists('title', $body)) {
    $title = clean_string($body['title'], 255);
    if ($title === '') $title = 'Untitled dataset';
    $fields[] = 'title = :title';
    $params[':title'] = $title;
}

if (array_key_exists('column_meta', $body)) {
    if (!is_array($body['column_meta'])) fail('bad_column_meta', 'column_meta must be an array.');
    $cleanCols = [];
    foreach ($body['column_meta'] as $c) {
        if (!is_array($c)) continue;
        $type = $c['type'] ?? 'ignore';
        // Extended for RSSI roles (see api/datasets/create.php header).
        if (!in_array($type, ['likert','single','multi','open','ignore','numeric','criterion','demographic','identifier'], true)) $type = 'ignore';
        $entry = [
            'name'    => clean_string((string)($c['name'] ?? ''), 200),
            'type'    => $type,
            'reverse' => !empty($c['reverse']),
        ];
        if (in_array($type, ['single','multi'], true) && isset($c['options']) && is_array($c['options'])) {
            $entry['options'] = array_values(array_map(fn($o) => clean_string((string)$o, 200), $c['options']));
        }
        if (!empty($c['construct'])) {
            $entry['construct'] = clean_string((string)$c['construct'], 200);
        }
        $cleanCols[] = $entry;
    }
    $fields[] = 'column_meta = :cm';
    $fields[] = 'column_count = :cc';
    $params[':cm'] = json_encode($cleanCols, JSON_UNESCAPED_UNICODE);
    $params[':cc'] = count($cleanCols);
}

if (array_key_exists('settings', $body)) {
    if (!is_array($body['settings'])) fail('bad_settings', 'settings must be an object.');
    $s = $body['settings'];
    $kPoints = (int)($s['likertPoints'] ?? 5);
    if ($kPoints < 2 || $kPoints > 11) fail('bad_likert_points', 'likertPoints must be between 2 and 11.');
    $cleanSettings = [
        'likertPoints' => $kPoints,
        'likertLow'    => clean_string((string)($s['likertLow']  ?? 'Strongly disagree'), 80),
        'likertHigh'   => clean_string((string)($s['likertHigh'] ?? 'Strongly agree'),    80),
    ];
    if (array_key_exists('reverse_coded_confirmed', $s)) {
        $cleanSettings['reverse_coded_confirmed'] = !empty($s['reverse_coded_confirmed']);
    }
    $fields[] = 'settings = :st';
    $params[':st'] = json_encode($cleanSettings, JSON_UNESCAPED_UNICODE);
}

if (!$fields) fail('nothing_to_update', 'No updatable fields were sent.');

$pdo->prepare('UPDATE datasets SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);

json_out(['ok' => true]);
