<?php
// POST /api/dev/project-create.php
// Body: { title, purpose?, population?, response_mode?, data_type?, source?,
//         sections?: [{title, description?}], items?: [{type, prompt, flag?, options?}] }
// Creates a survey project (+ optional initial sections/items) and returns the
// full project payload. SDSI/SIRI scoring stays stubbed — no reviews written here.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/../_rc_projects.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);
rc_ensure_project_schema($pdo);

$body = read_json_body();

$title      = clean_string((string)($body['title'] ?? ''), 255);
if ($title === '') $title = 'Untitled survey';
$purpose    = clean_string((string)($body['purpose'] ?? ''), 4000);
$population = clean_string((string)($body['population'] ?? ''), 2000);
$mode       = clean_string((string)($body['response_mode'] ?? '5-pt agreement'), 64) ?: '5-pt agreement';
$dataType   = clean_string((string)($body['data_type'] ?? 'Quantitative'), 32) ?: 'Quantitative';

$source = clean_string((string)($body['source'] ?? 'scratch'), 24);
if (!in_array($source, ['ai-build', 'ai-assist', 'scratch', 'existing', 'template'], true)) {
    $source = 'scratch';
}

// ReliCheck Basic marks its projects with settings.tier = 'basic' so the
// 25-response cap and Basic-only limits can key off the one authoritative
// survey_projects identity. Any other value leaves settings null (full tier).
$tier = clean_string((string)($body['tier'] ?? ''), 16);
$settingsJson = ($tier === 'basic') ? json_encode(['tier' => 'basic'], JSON_UNESCAPED_UNICODE) : null;

$sectionsIn   = (isset($body['sections'])   && is_array($body['sections']))   ? $body['sections']   : [];
$itemsIn      = (isset($body['items'])      && is_array($body['items']))      ? $body['items']      : [];
$constructsIn = (isset($body['constructs']) && is_array($body['constructs'])) ? $body['constructs'] : [];

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO survey_projects (user_id, title, purpose, population, response_mode, data_type, source, status, settings)
         VALUES (:uid, :title, :purpose, :pop, :mode, :dt, :src, :status, :settings)'
    )->execute([
        ':uid'      => (int)$user['id'],
        ':title'    => $title,
        ':purpose'  => $purpose !== '' ? $purpose : null,
        ':pop'      => $population !== '' ? $population : null,
        ':mode'     => $mode,
        ':dt'       => $dataType,
        ':src'      => $source,
        ':status'   => 'draft',
        ':settings' => $settingsJson,
    ]);
    $projectId = (int)$pdo->lastInsertId();

    // RE Item 3: create ecosystem project record and link.
    $rcProjectId = rc_create_project($pdo, (int)$user['id'], $title, $purpose ?: null);
    $pdo->prepare('UPDATE survey_projects SET rc_project_id = :r WHERE id = :id')
        ->execute([':r' => $rcProjectId, ':id' => $projectId]);

    // Optional sections; remember the first section id to attach loose items.
    $firstSectionId = null;
    if ($sectionsIn) {
        $insSec = $pdo->prepare(
            'INSERT INTO survey_sections (project_id, position, title, description)
             VALUES (:pid, :pos, :title, :descr)'
        );
        $pos = 0;
        foreach ($sectionsIn as $s) {
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
    }

    if ($itemsIn) {
        $insItem = $pdo->prepare(
            'INSERT INTO survey_items (project_id, section_id, position, type, prompt, options, flag, required, settings)
             VALUES (:pid, :sid, :pos, :type, :prompt, :opts, :flag, :req, :settings)'
        );
        $pos = 0;
        foreach ($itemsIn as $it) {
            if (!is_array($it)) continue;
            $prompt = clean_string((string)($it['prompt'] ?? $it['t'] ?? ''), 4000);
            if ($prompt === '') continue;
            $opts     = (isset($it['options'])  && is_array($it['options']))  ? json_encode($it['options'],  JSON_UNESCAPED_UNICODE) : null;
            $settings = (isset($it['settings']) && is_array($it['settings'])) ? json_encode($it['settings'], JSON_UNESCAPED_UNICODE) : null;
            $insItem->execute([
                ':pid'      => $projectId,
                ':sid'      => $firstSectionId,
                ':pos'      => $pos,
                ':type'     => sds_item_type($it['type'] ?? null),
                ':prompt'   => $prompt,
                ':opts'     => $opts,
                ':flag'     => sds_clean_flag($it['flag'] ?? null),
                ':req'      => !empty($it['required']) ? 1 : 0,
                ':settings' => $settings,
            ]);
            $pos++;
        }
    }

    // Optional construct definitions, name-deduped so the same list never
    // produces duplicate rows.
    if ($constructsIn) {
        $insCons = $pdo->prepare(
            'INSERT INTO survey_constructs (project_id, position, name, definition)
             VALUES (:pid, :pos, :name, :def)'
        );
        $seen = [];
        $pos = 0;
        foreach ($constructsIn as $c) {
            if (!is_array($c)) continue;
            $name = clean_string((string)($c['name'] ?? ''), 255);
            if ($name === '') continue;
            $key = mb_strtolower($name);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $def = clean_string((string)($c['definition'] ?? ''), 4000);
            $insCons->execute([
                ':pid'  => $projectId,
                ':pos'  => $pos,
                ':name' => $name,
                ':def'  => $def !== '' ? $def : null,
            ]);
            $pos++;
        }
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not create project: ' . $e->getMessage(), 500);
}

json_out(array_merge(['ok' => true, 'created' => true], sds_project_payload($pdo, $projectId)), 201);
