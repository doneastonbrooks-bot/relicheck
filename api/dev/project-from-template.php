<?php
// POST /api/dev/project-from-template.php
// Body: { slug? , template_id?, title? }
// Creates a NEW editable project from a template's blueprint (sections + items)
// owned by the caller. The template itself is never modified. Returns the new
// project payload.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);
sds_seed_system_templates($pdo);

$body = read_json_body();
$slug = clean_string((string)($body['slug'] ?? ''), 64);
$tid  = isset($body['template_id']) ? (int)$body['template_id'] : 0;

if ($slug !== '') {
    $stmt = $pdo->prepare('SELECT * FROM survey_templates WHERE slug = :slug AND (is_system = 1 OR user_id = :uid)');
    $stmt->execute([':slug' => $slug, ':uid' => (int)$user['id']]);
} elseif ($tid > 0) {
    $stmt = $pdo->prepare('SELECT * FROM survey_templates WHERE id = :id AND (is_system = 1 OR user_id = :uid)');
    $stmt->execute([':id' => $tid, ':uid' => (int)$user['id']]);
} else {
    fail('bad_input', 'A template slug or template_id is required.');
}
$tpl = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tpl) fail('not_found', 'Template not found.', 404);

$payload   = $tpl['payload'] !== null ? (json_decode((string)$tpl['payload'], true) ?: []) : [];
$tplSecs   = (isset($payload['sections']) && is_array($payload['sections'])) ? $payload['sections'] : [['title' => 'Main']];
$tplItems  = (isset($payload['items'])    && is_array($payload['items']))    ? $payload['items']    : [];

$title = clean_string((string)($body['title'] ?? $tpl['name']), 255);
if ($title === '') $title = $tpl['name'];

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO survey_projects (user_id, title, response_mode, source, status, settings)
         VALUES (:uid, :title, :mode, :src, :status, :settings)'
    )->execute([
        ':uid'      => (int)$user['id'],
        ':title'    => $title,
        ':mode'     => clean_string((string)$tpl['scale'], 64) ?: '5-pt agreement',
        ':src'      => 'template',
        ':status'   => 'draft',
        ':settings' => json_encode(['template_slug' => $tpl['slug']], JSON_UNESCAPED_UNICODE),
    ]);
    $projectId = (int)$pdo->lastInsertId();

    $insSec = $pdo->prepare('INSERT INTO survey_sections (project_id, position, title, description) VALUES (:pid, :pos, :title, :descr)');
    $firstSectionId = null;
    $pos = 0;
    foreach ($tplSecs as $s) {
        if (!is_array($s)) continue;
        $insSec->execute([
            ':pid'   => $projectId,
            ':pos'   => $pos,
            ':title' => clean_string((string)($s['title'] ?? 'Section'), 255) ?: 'Section',
            ':descr' => ($d = clean_string((string)($s['description'] ?? ''), 2000)) !== '' ? $d : null,
        ]);
        if ($firstSectionId === null) $firstSectionId = (int)$pdo->lastInsertId();
        $pos++;
    }

    $insItem = $pdo->prepare('INSERT INTO survey_items (project_id, section_id, position, type, prompt, options, flag) VALUES (:pid, :sid, :pos, :type, :prompt, :opts, :flag)');
    $pos = 0;
    foreach ($tplItems as $it) {
        if (!is_array($it)) continue;
        $prompt = clean_string((string)($it['prompt'] ?? $it['t'] ?? ''), 4000);
        if ($prompt === '') continue;
        $opts = (isset($it['options']) && is_array($it['options'])) ? json_encode($it['options'], JSON_UNESCAPED_UNICODE) : null;
        $insItem->execute([
            ':pid'    => $projectId,
            ':sid'    => $firstSectionId,
            ':pos'    => $pos,
            ':type'   => sds_item_type($it['type'] ?? null),
            ':prompt' => $prompt,
            ':opts'   => $opts,
            ':flag'   => sds_clean_flag($it['flag'] ?? null),
        ]);
        $pos++;
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not create project from template: ' . $e->getMessage(), 500);
}

json_out(array_merge(['ok' => true, 'created' => true, 'from_template' => $tpl['slug']], sds_project_payload($pdo, $projectId)), 201);
