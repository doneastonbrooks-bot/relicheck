<?php
// POST /api/dev/project-update.php
// Body: { id, title?, purpose?, population?, response_mode?, data_type?,
//         status?, settings? }
// Updates only the fields supplied. Owner-checked. Returns the project row.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$body = read_json_body();
$id   = isset($body['id']) ? (int)$body['id'] : 0;
sds_require_project($pdo, (int)$user['id'], $id);

$sets   = [];
$params = [':id' => $id];

if (array_key_exists('title', $body)) {
    $t = clean_string((string)$body['title'], 255);
    $sets[] = 'title = :title';
    $params[':title'] = $t !== '' ? $t : 'Untitled survey';
}
if (array_key_exists('purpose', $body)) {
    $v = clean_string((string)$body['purpose'], 4000);
    $sets[] = 'purpose = :purpose';
    $params[':purpose'] = $v !== '' ? $v : null;
}
if (array_key_exists('population', $body)) {
    $v = clean_string((string)$body['population'], 2000);
    $sets[] = 'population = :population';
    $params[':population'] = $v !== '' ? $v : null;
}
if (array_key_exists('response_mode', $body)) {
    $sets[] = 'response_mode = :mode';
    $params[':mode'] = clean_string((string)$body['response_mode'], 64) ?: '5-pt agreement';
}
if (array_key_exists('data_type', $body)) {
    $sets[] = 'data_type = :dt';
    $params[':dt'] = clean_string((string)$body['data_type'], 32) ?: 'Quantitative';
}
if (array_key_exists('status', $body)) {
    $st = clean_string((string)$body['status'], 16);
    if (in_array($st, ['draft', 'active', 'archived', 'published'], true)) {
        $sets[] = 'status = :status';
        $params[':status'] = $st;
    }
}
if (array_key_exists('settings', $body)) {
    $sets[] = 'settings = :settings';
    $params[':settings'] = is_array($body['settings']) ? json_encode($body['settings'], JSON_UNESCAPED_UNICODE) : null;
}

if ($sets) {
    $pdo->prepare('UPDATE survey_projects SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
}

$payload = sds_project_payload($pdo, $id);
json_out(['ok' => true, 'project' => $payload['project']]);
