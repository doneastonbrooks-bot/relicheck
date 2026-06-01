<?php
// GET  /api/mm/report.php?project_id=N
//   Returns all stored sections for this project, plus the static section
//   definitions in their canonical order.
//
// POST /api/mm/report.php
//   { project_id, action: "generate_all" }
//       Builds every section. Templated sections always refresh from data.
//       AI sections skip refresh when source = 'user' (the researcher edited it).
//   { project_id, action: "generate_section", section_key }
//       Rebuilds one section. Always overwrites whatever was there.
//   { project_id, action: "save_section", section_key, body_text }
//       Saves user-edited prose for one section.
//   { project_id, action: "reset_section", section_key }
//       Drops user edits and reverts to the templated/AI version on next generate.
//
// Section keys:
//   exec_summary       AI
//   methods            template
//   results_qual       template
//   results_quant      template
//   integration        AI
//   recommendations    AI
//   strength_appendix  template

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function rep_table_exists(PDO $pdo, string $name): bool {
    try {
        $s = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return $s && $s->fetch() !== false;
    } catch (Throwable $e) { return false; }
}

function rep_column_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $s = $pdo->prepare("SHOW COLUMNS FROM " . $table . " LIKE :c");
        $s->execute([':c' => $col]);
        return $s->fetch() !== false;
    } catch (Throwable $e) { return false; }
}

$REPORT_SECTIONS = [
    ['key' => 'exec_summary',      'title' => 'Executive summary',     'source' => 'ai'],
    ['key' => 'methods',           'title' => 'Methods',               'source' => 'template'],
    ['key' => 'results_qual',      'title' => 'Results — Qualitative', 'source' => 'template'],
    ['key' => 'results_quant',    'title' => 'Results — Quantitative','source' => 'template'],
    // Findings: collects per-area "Save to report" entries. 'ai' source so
    // generate_all preserves it once the user has saved into it (it is never
    // auto-built: rep_build_section has no 'findings' case → returns empty).
    ['key' => 'findings',          'title' => 'Findings',              'source' => 'ai'],
    ['key' => 'integration',       'title' => 'Integration',           'source' => 'ai'],
    ['key' => 'recommendations',   'title' => 'Recommendations',       'source' => 'ai'],
    ['key' => 'strength_appendix', 'title' => 'Strength Check appendix','source' => 'template'],
];

if (!rep_table_exists($pdo, 'mm_report_sections')) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
        mm_require_project($pdo, $uid, $projectId);
        json_out([
            'ok' => true,
            'has_table' => false,
            'sections' => $REPORT_SECTIONS,
            'rows' => [],
        ]);
    }
    fail('mm_no_report_table', 'Phase 162 schema not yet installed. Run schema_phase162.sql first.', 500);
}

// ------------------------------------------------------------
// GET
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);
    $stmt = $pdo->prepare(
        'SELECT section_key, title, body_text, body_html, source, generated_at, updated_at
         FROM mm_report_sections WHERE project_id = :p'
    );
    $stmt->execute([':p' => $projectId]);
    $byKey = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byKey[(string)$r['section_key']] = $r;

    $rows = [];
    foreach ($REPORT_SECTIONS as $sec) {
        $r = $byKey[$sec['key']] ?? null;
        $rows[] = [
            'section_key' => $sec['key'],
            'title'       => $sec['title'],
            'source_type' => $sec['source'],
            'body_text'   => (string)($r['body_text'] ?? ''),
            'body_html'   => (string)($r['body_html'] ?? ''),
            'source'      => (string)($r['source'] ?? ''),
            'generated_at'=> (string)($r['generated_at'] ?? ''),
            'updated_at'  => (string)($r['updated_at']   ?? ''),
        ];
    }
    json_out(['ok' => true, 'has_table' => true, 'sections' => $REPORT_SECTIONS, 'rows' => $rows]);
}

// ------------------------------------------------------------
// POST
// ------------------------------------------------------------
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? 'generate_all'), 32);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if ($action === 'save_section') {
    $key  = clean_string((string)($body['section_key'] ?? ''), 64);
    $text = (string)($body['body_text'] ?? '');
    $html = (string)($body['body_html'] ?? '');
    if (!rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    if (strlen($text) > 60000) $text = substr($text, 0, 60000);
    if (strlen($html) > 120000) $html = substr($html, 0, 120000);
    $cleanHtml = $html !== '' ? rep_sanitize_html($html) : '';
    if ($text === '' && $cleanHtml !== '') {
        $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", $cleanHtml)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    rep_upsert_section($pdo, $projectId, $key, rep_title_for($key, $REPORT_SECTIONS), $text, $cleanHtml, 'user', false);
    // Phase B: log a version. Table-guarded so older installs still work.
    rep_log_version($pdo, $projectId, $key, $text, $cleanHtml, 'user');
    json_out(['ok' => true, 'section_key' => $key, 'source' => 'user', 'body_text' => $text, 'body_html' => $cleanHtml]);
}

if ($action === 'list_versions') {
    $key = clean_string((string)($body['section_key'] ?? ''), 64);
    if (!rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    if (!rep_table_exists($pdo, 'mm_report_section_versions')) {
        json_out(['ok' => true, 'versions' => [], 'has_table' => false]);
    }
    $stmt = $pdo->prepare(
        'SELECT id, body_text, body_html, source, created_at
         FROM mm_report_section_versions
         WHERE project_id = :p AND section_key = :k
         ORDER BY id DESC LIMIT 10'
    );
    $stmt->execute([':p' => $projectId, ':k' => $key]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $preview = trim((string)($r['body_text'] ?? ''));
        if ($preview === '' && !empty($r['body_html'])) {
            $preview = trim(html_entity_decode(strip_tags((string)$r['body_html']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        if (mb_strlen($preview) > 220) $preview = mb_substr($preview, 0, 220) . '...';
        $out[] = [
            'id'         => (int)$r['id'],
            'created_at' => (string)$r['created_at'],
            'source'     => (string)($r['source'] ?? 'user'),
            'preview'    => $preview,
            'body_text'  => (string)($r['body_text'] ?? ''),
            'body_html'  => (string)($r['body_html'] ?? ''),
        ];
    }
    json_out(['ok' => true, 'versions' => $out, 'has_table' => true]);
}

if ($action === 'restore_version') {
    $key = clean_string((string)($body['section_key'] ?? ''), 64);
    $vid = (int)($body['version_id'] ?? 0);
    if (!rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    if ($vid <= 0) fail('bad_input', 'version_id is required.');
    if (!rep_table_exists($pdo, 'mm_report_section_versions')) {
        fail('mm_no_version_table', 'Version history not available. Run schema_phase163.sql.', 500);
    }
    $stmt = $pdo->prepare(
        'SELECT body_text, body_html, source
         FROM mm_report_section_versions
         WHERE id = :i AND project_id = :p AND section_key = :k'
    );
    $stmt->execute([':i' => $vid, ':p' => $projectId, ':k' => $key]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('mm_version_not_found', 'Version not found.', 404);
    $text = (string)($r['body_text'] ?? '');
    $html = (string)($r['body_html'] ?? '');
    rep_upsert_section($pdo, $projectId, $key, rep_title_for($key, $REPORT_SECTIONS), $text, $html, 'user', false);
    // The restore itself is a save - log it as a version too so you can
    // un-restore if you change your mind.
    rep_log_version($pdo, $projectId, $key, $text, $html, 'user');
    json_out(['ok' => true, 'section_key' => $key, 'source' => 'user', 'body_text' => $text, 'body_html' => $html]);
}

// ----------------------------------------------------------------
// Notes / to-do actions (Phase 164)
// ----------------------------------------------------------------
if ($action === 'list_notes') {
    $key = clean_string((string)($body['section_key'] ?? ''), 64);
    if ($key !== '' && !rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    if (!rep_table_exists($pdo, 'mm_report_section_notes')) {
        json_out(['ok' => true, 'notes' => [], 'unresolved_by_section' => new stdClass(), 'has_table' => false]);
    }
    $rows = [];
    if ($key === '') {
        $stmt = $pdo->prepare(
            'SELECT id, section_key, body_text, is_todo, is_resolved, created_at, updated_at
             FROM mm_report_section_notes WHERE project_id = :p ORDER BY is_resolved ASC, id DESC'
        );
        $stmt->execute([':p' => $projectId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, section_key, body_text, is_todo, is_resolved, created_at, updated_at
             FROM mm_report_section_notes WHERE project_id = :p AND section_key = :k ORDER BY is_resolved ASC, id DESC'
        );
        $stmt->execute([':p' => $projectId, ':k' => $key]);
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'section_key' => (string)$r['section_key'],
            'body_text'   => (string)$r['body_text'],
            'is_todo'     => (int)$r['is_todo'] === 1,
            'is_resolved' => (int)$r['is_resolved'] === 1,
            'created_at'  => (string)$r['created_at'],
            'updated_at'  => (string)$r['updated_at'],
        ];
    }
    $counts = $pdo->prepare(
        'SELECT section_key, COUNT(*) AS n
         FROM mm_report_section_notes
         WHERE project_id = :p AND is_resolved = 0
         GROUP BY section_key'
    );
    $counts->execute([':p' => $projectId]);
    $byKey = [];
    foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $c) $byKey[(string)$c['section_key']] = (int)$c['n'];
    json_out(['ok' => true, 'notes' => $rows, 'unresolved_by_section' => $byKey, 'has_table' => true]);
}

if ($action === 'add_note') {
    if (!rep_table_exists($pdo, 'mm_report_section_notes')) fail('mm_no_notes_table', 'Notes not available. Run schema_phase164.sql.', 500);
    $key  = clean_string((string)($body['section_key'] ?? ''), 64);
    $text = clean_string((string)($body['body_text'] ?? ''), 800);
    $todo = !empty($body['is_todo']) ? 1 : 0;
    if (!rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    if (trim($text) === '') fail('bad_input', 'Note text is required.');
    $ins = $pdo->prepare(
        'INSERT INTO mm_report_section_notes (project_id, section_key, body_text, is_todo, is_resolved)
         VALUES (:p, :k, :t, :td, 0)'
    );
    $ins->execute([':p' => $projectId, ':k' => $key, ':t' => $text, ':td' => $todo]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'section_key' => $key, 'body_text' => $text, 'is_todo' => $todo === 1, 'is_resolved' => false]);
}

if ($action === 'update_note') {
    if (!rep_table_exists($pdo, 'mm_report_section_notes')) fail('mm_no_notes_table', 'Notes not available.', 500);
    $id = (int)($body['note_id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'note_id is required.');
    $ck = $pdo->prepare('SELECT id, section_key FROM mm_report_section_notes WHERE id = :i AND project_id = :p');
    $ck->execute([':i' => $id, ':p' => $projectId]);
    $note = $ck->fetch(PDO::FETCH_ASSOC);
    if (!$note) fail('mm_note_not_found', 'Note not found.', 404);

    $fields = []; $params = [':id' => $id];
    if (array_key_exists('body_text', $body)) {
        $text = clean_string((string)$body['body_text'], 800);
        if (trim($text) === '') fail('bad_input', 'Note text cannot be empty.');
        $fields[] = 'body_text = :bt'; $params[':bt'] = $text;
    }
    if (array_key_exists('is_todo', $body)) {
        $fields[] = 'is_todo = :td'; $params[':td'] = !empty($body['is_todo']) ? 1 : 0;
    }
    if (array_key_exists('is_resolved', $body)) {
        $fields[] = 'is_resolved = :rv'; $params[':rv'] = !empty($body['is_resolved']) ? 1 : 0;
    }
    if (count($fields) === 0) fail('bad_input', 'Nothing to update.');
    $sql = 'UPDATE mm_report_section_notes SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    $out = $pdo->prepare('SELECT id, section_key, body_text, is_todo, is_resolved, created_at, updated_at FROM mm_report_section_notes WHERE id = :id');
    $out->execute([':id' => $id]);
    $r = $out->fetch(PDO::FETCH_ASSOC) ?: [];
    json_out(['ok' => true, 'note' => [
        'id'          => (int)($r['id'] ?? 0),
        'section_key' => (string)($r['section_key'] ?? ''),
        'body_text'   => (string)($r['body_text'] ?? ''),
        'is_todo'     => (int)($r['is_todo'] ?? 0) === 1,
        'is_resolved' => (int)($r['is_resolved'] ?? 0) === 1,
        'created_at'  => (string)($r['created_at'] ?? ''),
        'updated_at'  => (string)($r['updated_at'] ?? ''),
    ]]);
}

if ($action === 'delete_note') {
    if (!rep_table_exists($pdo, 'mm_report_section_notes')) fail('mm_no_notes_table', 'Notes not available.', 500);
    $id = (int)($body['note_id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'note_id is required.');
    $pdo->prepare('DELETE FROM mm_report_section_notes WHERE id = :i AND project_id = :p')
        ->execute([':i' => $id, ':p' => $projectId]);
    json_out(['ok' => true]);
}

if ($action === 'reset_section') {
    $key = clean_string((string)($body['section_key'] ?? ''), 64);
    if (!rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    $pdo->prepare('DELETE FROM mm_report_sections WHERE project_id = :p AND section_key = :k')
        ->execute([':p' => $projectId, ':k' => $key]);
    json_out(['ok' => true, 'section_key' => $key]);
}

// Generate-style actions can be expensive (AI calls). Throttle.
check_rate_limit('mm_report:user:' . $uid, 60, 3600);

if ($action === 'generate_section') {
    $key = clean_string((string)($body['section_key'] ?? ''), 64);
    if (!rep_valid_section_key($key, $REPORT_SECTIONS)) fail('bad_input', 'Unknown section_key.');
    $sec = rep_section_def($key, $REPORT_SECTIONS);
    $result = rep_build_section($pdo, $projectId, $sec);
    rep_upsert_section($pdo, $projectId, $key, $sec['title'], $result['body_text'], $result['body_html'], $sec['source'], true);
    json_out(['ok' => true, 'section_key' => $key, 'source' => $sec['source'], 'body_text' => $result['body_text'], 'body_html' => $result['body_html']]);
}

// Default: generate_all
$generated = []; $skipped = [];
foreach ($REPORT_SECTIONS as $sec) {
    // For AI sections, skip if user has edited the saved text.
    if ($sec['source'] === 'ai') {
        $stmt = $pdo->prepare('SELECT source FROM mm_report_sections WHERE project_id = :p AND section_key = :k');
        $stmt->execute([':p' => $projectId, ':k' => $sec['key']]);
        $existing = (string)($stmt->fetchColumn() ?: '');
        if ($existing === 'user') { $skipped[] = ['section_key' => $sec['key'], 'reason' => 'user_edited']; continue; }
    }
    $result = rep_build_section($pdo, $projectId, $sec);
    rep_upsert_section($pdo, $projectId, $sec['key'], $sec['title'], $result['body_text'], $result['body_html'], $sec['source'], true);
    $generated[] = $sec['key'];
}

// Return the full rendered set so the UI can re-render in one round-trip.
$stmt = $pdo->prepare(
    'SELECT section_key, title, body_text, body_html, source, generated_at, updated_at
     FROM mm_report_sections WHERE project_id = :p'
);
$stmt->execute([':p' => $projectId]);
$byKey = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byKey[(string)$r['section_key']] = $r;

$rows = [];
foreach ($REPORT_SECTIONS as $sec) {
    $r = $byKey[$sec['key']] ?? null;
    $rows[] = [
        'section_key' => $sec['key'],
        'title'       => $sec['title'],
        'source_type' => $sec['source'],
        'body_text'   => (string)($r['body_text'] ?? ''),
        'body_html'   => (string)($r['body_html'] ?? ''),
        'source'      => (string)($r['source'] ?? ''),
        'generated_at'=> (string)($r['generated_at'] ?? ''),
        'updated_at'  => (string)($r['updated_at']   ?? ''),
    ];
}

json_out([
    'ok' => true,
    'generated' => $generated,
    'skipped'   => $skipped,
    'sections'  => $REPORT_SECTIONS,
    'rows'      => $rows,
]);

// ================================================================
// Section builders
// ================================================================
function rep_valid_section_key(string $key, array $defs): bool {
    foreach ($defs as $d) if ($d['key'] === $key) return true;
    return false;
}
function rep_title_for(string $key, array $defs): string {
    foreach ($defs as $d) if ($d['key'] === $key) return $d['title'];
    return $key;
}
function rep_section_def(string $key, array $defs): array {
    foreach ($defs as $d) if ($d['key'] === $key) return $d;
    return ['key' => $key, 'title' => $key, 'source' => 'template'];
}

function rep_upsert_section(PDO $pdo, int $projectId, string $key, string $title, string $bodyText, string $bodyHtml, string $source, bool $stampGenerated): void {
    $sql = 'INSERT INTO mm_report_sections (project_id, section_key, title, body_text, body_html, source, generated_at)
            VALUES (:p, :k, :t, :bt, :bh, :s, ' . ($stampGenerated ? 'NOW()' : 'NULL') . ')
            ON DUPLICATE KEY UPDATE
              title    = VALUES(title),
              body_text = VALUES(body_text),
              body_html = VALUES(body_html),
              source    = VALUES(source)';
    if ($stampGenerated) $sql .= ', generated_at = NOW()';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':p' => $projectId, ':k' => $key, ':t' => $title,
        ':bt' => $bodyText !== '' ? $bodyText : null,
        ':bh' => $bodyHtml !== '' ? $bodyHtml : null,
        ':s' => $source,
    ]);
}

// Pull a compact picture of the project: counts, themes with stats,
// analysis results, integration paragraphs, strength check rows. Each
// optional read is guarded so missing prior steps return empty.
function rep_project_snapshot(PDO $pdo, int $projectId): array {
    $snap = [
        'project_id'       => $projectId,
        'project_title'    => '',
        'total_responses'  => 0,
        'total_coded_rows' => 0,
        'themes'           => [],
        'analyses'         => [],
        'integrations'     => [],
        'strength_checks'  => [],
        'questions'        => [],
    ];

    try {
        $s = $pdo->prepare('SELECT title FROM mm_projects WHERE id = :p');
        $s->execute([':p' => $projectId]);
        $snap['project_title'] = (string)($s->fetchColumn() ?: ('Project ' . $projectId));
    } catch (Throwable $e) {}

    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM mm_text_responses WHERE project_id = :p');
        $s->execute([':p' => $projectId]);
        $snap['total_responses'] = (int)$s->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p');
        $s->execute([':p' => $projectId]);
        $snap['total_coded_rows'] = (int)$s->fetchColumn();
    } catch (Throwable $e) {}

    // Per-question list if Phase 157 columns are present.
    if (rep_column_exists($pdo, 'mm_text_responses', 'question_text_raw')) {
        try {
            $s = $pdo->prepare(
                'SELECT COALESCE(question_text_raw, question_id_raw) AS q, COUNT(*) AS n
                 FROM mm_text_responses WHERE project_id = :p AND COALESCE(question_text_raw, question_id_raw) IS NOT NULL
                 GROUP BY COALESCE(question_text_raw, question_id_raw) ORDER BY n DESC LIMIT 20'
            );
            $s->execute([':p' => $projectId]);
            $snap['questions'] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    // Themes with frequency + sentiment + quote + integration paragraph.
    $hasIntensity = rep_column_exists($pdo, 'mm_coded_responses', 'intensity');
    try {
        $tstmt = $pdo->prepare(
            'SELECT id, name, COALESCE(definition, description, "") AS description
             FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
        );
        $tstmt->execute([':p' => $projectId]);
        $themes = $tstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasJoint  = rep_table_exists($pdo, 'mm_joint_display_rows');
        $hasInteg  = rep_table_exists($pdo, 'mm_integration_rows');
        foreach ($themes as $t) {
            $tid = (int)$t['id'];
            $entry = ['id' => $tid, 'name' => (string)$t['name'], 'description' => (string)$t['description'], 'n' => 0, 'percent' => 0.0, 'mean_intensity' => null, 'sentiment' => null, 'quote' => '', 'integration' => '', 'integration_source' => ''];

            $s = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p AND category_id = :c');
            $s->execute([':p' => $projectId, ':c' => $tid]);
            $entry['n'] = (int)$s->fetchColumn();
            $entry['percent'] = $snap['total_responses'] > 0 ? round(100.0 * $entry['n'] / $snap['total_responses'], 1) : 0.0;

            if ($hasIntensity) {
                $s = $pdo->prepare('SELECT intensity FROM mm_coded_responses WHERE project_id = :p AND category_id = :c');
                $s->execute([':p' => $projectId, ':c' => $tid]);
                $vals = [];
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $v) {
                    $score = ['low' => 1, 'moderate' => 2, 'high' => 3][(string)$v] ?? 0;
                    if ($score > 0) $vals[] = $score;
                }
                if (count($vals) > 0) $entry['mean_intensity'] = round(array_sum($vals) / count($vals), 2);
            }

            try {
                $s = $pdo->prepare(
                    'SELECT ss.sentiment, COUNT(*) AS n
                     FROM mm_coded_responses cr
                     INNER JOIN mm_sentiment_scores ss ON ss.response_id = cr.response_id AND ss.project_id = cr.project_id
                     WHERE cr.project_id = :p AND cr.category_id = :c GROUP BY ss.sentiment'
                );
                $s->execute([':p' => $projectId, ':c' => $tid]);
                $sent = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $k = strtolower((string)$row['sentiment']);
                    if (isset($sent[$k])) $sent[$k] = (int)$row['n'];
                }
                $tot = array_sum($sent);
                $entry['sentiment'] = ['counts' => $sent, 'total' => $tot];
            } catch (Throwable $e) {}

            if ($hasJoint) {
                try {
                    $s = $pdo->prepare('SELECT quote_text FROM mm_joint_display_rows WHERE project_id = :p AND theme_id = :c');
                    $s->execute([':p' => $projectId, ':c' => $tid]);
                    $entry['quote'] = (string)($s->fetchColumn() ?: '');
                } catch (Throwable $e) {}
            }
            if ($hasInteg) {
                try {
                    $s = $pdo->prepare('SELECT paragraph_text, source FROM mm_integration_rows WHERE project_id = :p AND theme_id = :c');
                    $s->execute([':p' => $projectId, ':c' => $tid]);
                    $row = $s->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $entry['integration'] = (string)($row['paragraph_text'] ?? '');
                        $entry['integration_source'] = (string)($row['source'] ?? '');
                    }
                } catch (Throwable $e) {}
            }
            $snap['themes'][] = $entry;
        }
    } catch (Throwable $e) {}

    // Analyses.
    if (rep_table_exists($pdo, 'mm_analysis_results')) {
        try {
            $s = $pdo->prepare(
                'SELECT ar.test_name, ar.statistic, ar.df1, ar.df2, ar.p_value, ar.effect_size, ar.effect_label, ar.n_total, ar.summary,
                        vp.var_name AS predictor, vo.var_name AS outcome
                 FROM mm_analysis_results ar
                 LEFT JOIN mm_generated_variables vp ON vp.id = ar.predictor_id
                 LEFT JOIN mm_generated_variables vo ON vo.id = ar.outcome_id
                 WHERE ar.project_id = :p
                 ORDER BY (ar.p_value IS NULL), ar.p_value ASC'
            );
            $s->execute([':p' => $projectId]);
            $snap['analyses'] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    // Strength checks.
    if (rep_table_exists($pdo, 'mm_strength_checks')) {
        try {
            $s = $pdo->prepare(
                'SELECT check_key, status, severity, title, message, fix_hint, ran_at
                 FROM mm_strength_checks WHERE project_id = :p ORDER BY ran_at DESC'
            );
            $s->execute([':p' => $projectId]);
            $snap['strength_checks'] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    return $snap;
}

function rep_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rep_test_label(string $t): string {
    return ['chi_square' => 'Chi-square', 't_test' => "Welch's t-test", 'anova' => 'One-way ANOVA', 'pearson' => 'Pearson r'][$t] ?? $t;
}

function rep_fmt_p(?float $p): string {
    if ($p === null) return '-';
    if ($p < 0.0001) return '<.0001';
    if ($p < 0.001)  return '<.001';
    return sprintf('%.3f', $p);
}

// Build one section: returns ['body_text' => ..., 'body_html' => ...].
function rep_build_section(PDO $pdo, int $projectId, array $sec): array {
    $snap = rep_project_snapshot($pdo, $projectId);

    switch ($sec['key']) {
        case 'methods':
            return rep_section_methods($snap);
        case 'results_qual':
            return rep_section_results_qual($snap);
        case 'results_quant':
            return rep_section_results_quant($snap);
        case 'strength_appendix':
            return rep_section_strength($snap);
        case 'exec_summary':
            return rep_section_exec_summary($snap);
        case 'integration':
            return rep_section_integration($snap);
        case 'recommendations':
            return rep_section_recommendations($snap);
    }
    return ['body_text' => '', 'body_html' => ''];
}

// ----- TEMPLATED SECTIONS -----
function rep_section_methods(array $s): array {
    $themesN = count($s['themes']);
    $hasIntensity = false;
    foreach ($s['themes'] as $t) if ($t['mean_intensity'] !== null) { $hasIntensity = true; break; }
    $qN = count($s['questions']);
    $lines = [];
    $lines[] = 'This project analyzed ' . $s['total_responses'] . ' open-ended responses' . ($qN > 0 ? (' across ' . $qN . ' question(s)') : '') . '.';
    if ($themesN > 0) {
        $lines[] = 'Studio generated ' . $themesN . ' themes from the response corpus and applied them to every response using deterministic keyword matching against each theme name and definition.';
    }
    if ($hasIntensity) {
        $lines[] = 'For each theme-response match, Studio recorded a low / moderate / high intensity score and per-response sentiment (positive, neutral, mixed, or negative) from a built-in lexicon.';
    } else {
        $lines[] = 'For each theme-response match, Studio recorded per-response sentiment (positive, neutral, mixed, or negative) from a built-in lexicon.';
    }
    if (count($s['analyses']) > 0) {
        $lines[] = 'Quantitative tests were drawn from variable role assignments on the Dataset tab. ' . count($s['analyses']) . ' analysis result(s) were computed (chi-square, Welch t-test, one-way ANOVA, or Pearson r) and saved with the project.';
    }
    $body = implode(' ', $lines);
    return ['body_text' => $body, 'body_html' => '<p>' . rep_esc($body) . '</p>'];
}

function rep_section_results_qual(array $s): array {
    if (count($s['themes']) === 0) {
        $t = 'No themes have been generated for this project yet.';
        return ['body_text' => $t, 'body_html' => '<p>' . rep_esc($t) . '</p>'];
    }
    $textParts = [];
    $htmlParts = [];
    foreach ($s['themes'] as $t) {
        if ((int)$t['n'] === 0) continue;
        $sent = $t['sentiment'];
        $tot = $sent ? (int)$sent['total'] : 0;
        $pctOf = function ($k) use ($sent, $tot) {
            if (!$sent || $tot === 0) return 0.0;
            return round(100.0 * (int)($sent['counts'][$k] ?? 0) / $tot, 0);
        };
        $line = $t['name'] . ' appeared in ' . $t['n'] . ' of ' . $s['total_responses'] . ' responses (' . $t['percent'] . '%).';
        if ($t['description']) $line .= ' Definition: ' . $t['description'];
        if ($t['mean_intensity'] !== null) $line .= ' Mean intensity: ' . $t['mean_intensity'] . '.';
        if ($sent && $tot > 0) {
            $line .= ' Sentiment among matching responses: positive ' . $pctOf('positive') . '%, neutral ' . $pctOf('neutral') . '%, mixed ' . $pctOf('mixed') . '%, negative ' . $pctOf('negative') . '%.';
        }
        if ($t['quote']) $line .= ' Representative quote: "' . $t['quote'] . '"';
        $textParts[] = $line;

        $htmlParts[] = '<div style="margin-bottom:14px;">' .
            '<strong>' . rep_esc($t['name']) . '</strong> &middot; ' . $t['n'] . ' / ' . $s['total_responses'] . ' (' . $t['percent'] . '%)' .
            ($t['description'] ? '<div style="color:#5a6470;font-size:12px;margin-top:2px;">' . rep_esc($t['description']) . '</div>' : '') .
            ($t['mean_intensity'] !== null ? '<div style="font-size:12px;color:#5a6470;">Mean intensity: ' . $t['mean_intensity'] . '</div>' : '') .
            ($sent && $tot > 0 ? '<div style="font-size:12px;color:#5a6470;">Sentiment: pos ' . $pctOf('positive') . '% &middot; neu ' . $pctOf('neutral') . '% &middot; mix ' . $pctOf('mixed') . '% &middot; neg ' . $pctOf('negative') . '%</div>' : '') .
            ($t['quote'] ? '<div style="margin-top:6px;font-style:italic;">"' . rep_esc($t['quote']) . '"</div>' : '') .
        '</div>';
    }
    return ['body_text' => implode("\n\n", $textParts), 'body_html' => implode('', $htmlParts)];
}

function rep_section_results_quant(array $s): array {
    if (count($s['analyses']) === 0) {
        $t = 'No quantitative tests have been run for this project yet.';
        return ['body_text' => $t, 'body_html' => '<p>' . rep_esc($t) . '</p>'];
    }
    $rowsHtml = '';
    $textParts = [];
    foreach ($s['analyses'] as $a) {
        $test = rep_test_label((string)$a['test_name']);
        $p    = $a['p_value']     !== null ? (float)$a['p_value']     : null;
        $stat = $a['statistic']   !== null ? (float)$a['statistic']   : null;
        $eff  = $a['effect_size'] !== null ? (float)$a['effect_size'] : null;
        $eL   = (string)($a['effect_label'] ?? '');
        $nT   = $a['n_total']     !== null ? (int)$a['n_total']     : null;
        $pred = (string)($a['predictor'] ?? ''); $out = (string)($a['outcome'] ?? '');
        $sig = ($p !== null && $p < 0.05) ? ' *' : '';
        $row = $pred . ' &rarr; ' . $out . ' (' . $test . '): ' .
            ($stat !== null ? sprintf('%.2f', $stat) : '-') .
            ($p !== null ? (', p = ' . rep_fmt_p($p)) : '') .
            ($eff !== null ? (', ' . str_replace('_', ' ', $eL) . ' = ' . sprintf('%.2f', $eff)) : '') .
            ($nT !== null ? (', N = ' . $nT) : '');
        $textParts[] = $row . $sig;
        $rowsHtml .= '<tr>' .
            '<td>' . rep_esc($pred) . ' &rarr; ' . rep_esc($out) . '</td>' .
            '<td>' . rep_esc($test) . '</td>' .
            '<td>' . ($stat !== null ? sprintf('%.2f', $stat) : '-') . '</td>' .
            '<td>' . rep_fmt_p($p) . ($sig ? ' <strong>*</strong>' : '') . '</td>' .
            '<td>' . ($eff !== null ? sprintf('%.2f', $eff) : '-') . ($eL ? ' <span style="color:#5a6470;font-size:11px;">' . rep_esc(str_replace('_', ' ', $eL)) . '</span>' : '') . '</td>' .
            '<td>' . ($nT ?? '-') . '</td>' .
        '</tr>';
    }
    $html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">' .
        '<thead><tr style="background:#f7f8fa;"><th style="text-align:left;padding:6px 8px;">Pair</th><th style="text-align:left;padding:6px 8px;">Test</th><th style="text-align:left;padding:6px 8px;">Statistic</th><th style="text-align:left;padding:6px 8px;">p-value</th><th style="text-align:left;padding:6px 8px;">Effect</th><th style="text-align:left;padding:6px 8px;">N</th></tr></thead>' .
        '<tbody>' . $rowsHtml . '</tbody></table>' .
        '<p style="font-size:12px;color:#5a6470;margin-top:6px;">* p &lt; .05.</p>';
    return ['body_text' => implode("\n", $textParts), 'body_html' => $html];
}

function rep_section_strength(array $s): array {
    if (count($s['strength_checks']) === 0) {
        $t = 'Strength Check has not been run yet for this project.';
        return ['body_text' => $t, 'body_html' => '<p>' . rep_esc($t) . '</p>'];
    }
    $textParts = [];
    $rowsHtml = '';
    foreach ($s['strength_checks'] as $c) {
        $status = strtoupper((string)$c['status']);
        $textParts[] = '[' . $status . '] ' . (string)$c['title'] . ' - ' . (string)($c['message'] ?? '');
        $rowsHtml .= '<tr>' .
            '<td style="padding:6px 8px;"><strong>' . rep_esc((string)$c['title']) . '</strong></td>' .
            '<td style="padding:6px 8px;">' . rep_esc($status) . '</td>' .
            '<td style="padding:6px 8px;">' . rep_esc((string)($c['message'] ?? '')) . '</td>' .
        '</tr>';
    }
    $html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">' .
        '<thead><tr style="background:#f7f8fa;"><th style="text-align:left;padding:6px 8px;">Check</th><th style="text-align:left;padding:6px 8px;">Status</th><th style="text-align:left;padding:6px 8px;">Detail</th></tr></thead>' .
        '<tbody>' . $rowsHtml . '</tbody></table>';
    return ['body_text' => implode("\n", $textParts), 'body_html' => $html];
}

// ----- AI SECTIONS -----
function rep_section_exec_summary(array $s): array {
    $topThemes = array_slice($s['themes'], 0, 6);
    $themeBlock = '';
    foreach ($topThemes as $t) {
        if ((int)$t['n'] === 0) continue;
        $themeBlock .= '- ' . $t['name'] . ' (' . $t['n'] . '/' . $s['total_responses'] . ' = ' . $t['percent'] . '%)' . "\n";
    }
    $sigAnalysis = '';
    foreach ($s['analyses'] as $a) {
        $p = $a['p_value'] !== null ? (float)$a['p_value'] : null;
        if ($p !== null && $p < 0.05) {
            $sigAnalysis .= '- ' . (string)($a['predictor'] ?? '') . ' -> ' . (string)($a['outcome'] ?? '') . ' (' . rep_test_label((string)$a['test_name']) . '), p = ' . rep_fmt_p($p) . "\n";
            if (substr_count($sigAnalysis, "\n") >= 4) break;
        }
    }

    $system = <<<SYS
You are a mixed-methods research analyst writing a 200-280 word Executive Summary for a project report.

Rules:
- Plain academic prose. No headers, no bullets, no markdown.
- 4 short paragraphs.
- Paragraph 1: the research scope (number of responses, what was studied).
- Paragraph 2: top qualitative themes (use names provided).
- Paragraph 3: any significant quantitative findings, with effect sizes if given.
- Paragraph 4: 2-3 sentence implication.
- Do not invent numbers, themes, or quotes.
SYS;

    $user = 'PROJECT: ' . $s['project_title'] . "\n"
          . 'RESPONSES: ' . $s['total_responses'] . "\n"
          . 'THEMES: ' . count($s['themes']) . "\n"
          . 'TOP THEMES BY FREQUENCY:' . "\n" . $themeBlock
          . 'SIGNIFICANT QUANT FINDINGS:' . "\n" . ($sigAnalysis !== '' ? $sigAnalysis : '(none)' . "\n");

    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => $user]], 700);
        $text = trim((string)($resp['text'] ?? ''));
        if ($text === '') $text = 'Executive summary not available.';
        return ['body_text' => $text, 'body_html' => '<p>' . str_replace("\n\n", '</p><p>', rep_esc($text)) . '</p>'];
    } catch (Throwable $e) {
        $fallback = 'Executive summary could not be generated by AI. Edit this section to write one by hand.';
        return ['body_text' => $fallback, 'body_html' => '<p>' . rep_esc($fallback) . '</p>'];
    }
}

function rep_section_integration(array $s): array {
    $paragraphs = [];
    foreach ($s['themes'] as $t) {
        if ($t['integration']) $paragraphs[] = $t['integration'];
    }
    if (count($paragraphs) === 0) {
        $t = 'No integration paragraphs have been written yet. Use the Integration tab to generate them.';
        return ['body_text' => $t, 'body_html' => '<p>' . rep_esc($t) . '</p>'];
    }
    $joined = implode("\n\n", $paragraphs);

    $system = <<<SYS
You are a mixed-methods research analyst. You will be given a set of theme-by-theme integration paragraphs. Weave them into a single, coherent 4-6 paragraph integration narrative that flows for a reader.

Rules:
- Plain academic prose. No headers, no bullets, no markdown.
- Keep every concrete number and effect-size phrase intact. Do not invent new numbers.
- Group thematically where natural; you may rearrange the order.
- End with a one-sentence synthesis of what the integration tells us.
SYS;

    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => 'THEME-LEVEL PARAGRAPHS:' . "\n\n" . $joined]], 1200);
        $text = trim((string)($resp['text'] ?? ''));
        if ($text === '') $text = $joined;
        return ['body_text' => $text, 'body_html' => '<p>' . str_replace("\n\n", '</p><p>', rep_esc($text)) . '</p>'];
    } catch (Throwable $e) {
        return ['body_text' => $joined, 'body_html' => '<p>' . str_replace("\n\n", '</p><p>', rep_esc($joined)) . '</p>'];
    }
}

function rep_section_recommendations(array $s): array {
    $topThemes = array_slice($s['themes'], 0, 6);
    $themeBlock = '';
    foreach ($topThemes as $t) {
        if ((int)$t['n'] === 0) continue;
        $themeBlock .= '- ' . $t['name'] . ' (' . $t['percent'] . '% of responses)' . "\n";
    }
    $sigAnalysis = '';
    foreach ($s['analyses'] as $a) {
        $p = $a['p_value'] !== null ? (float)$a['p_value'] : null;
        if ($p !== null && $p < 0.05) {
            $sigAnalysis .= '- ' . (string)($a['predictor'] ?? '') . ' -> ' . (string)($a['outcome'] ?? '') . ', p = ' . rep_fmt_p($p) . "\n";
            if (substr_count($sigAnalysis, "\n") >= 6) break;
        }
    }

    $system = <<<SYS
You are a mixed-methods analyst writing 3 to 5 actionable recommendations for an organization based on a project's findings.

Rules:
- Plain prose. No markdown. Output a numbered list, "1. ... 2. ... 3. ..." each on its own line.
- Each recommendation is one sentence (no more than 28 words).
- Lead each with an action verb.
- Tie each recommendation to a specific theme or significant finding by name.
- Avoid vague advice. Be specific to the data.
SYS;

    $user = 'TOP THEMES:' . "\n" . $themeBlock . "\n"
          . 'SIGNIFICANT FINDINGS:' . "\n" . ($sigAnalysis !== '' ? $sigAnalysis : '(none)' . "\n");

    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => $user]], 500);
        $text = trim((string)($resp['text'] ?? ''));
        if ($text === '') $text = 'Recommendations not available.';
        // Convert numbered list to HTML <ol>.
        $lines = preg_split('/\r?\n+/', $text);
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
            if ($line !== '') $items[] = $line;
        }
        $html = '<ol style="margin:0;padding-left:22px;">' . implode('', array_map(function ($it) { return '<li style="margin:6px 0;">' . rep_esc($it) . '</li>'; }, $items)) . '</ol>';
        return ['body_text' => $text, 'body_html' => $html];
    } catch (Throwable $e) {
        $fallback = 'Recommendations could not be generated by AI. Edit this section to write them by hand.';
        return ['body_text' => $fallback, 'body_html' => '<p>' . rep_esc($fallback) . '</p>'];
    }
}

// ================================================================
// Append a row to mm_report_section_versions, then prune so only the
// 10 most recent versions per (project, section) remain. Table-guarded
// so installs that have not run Phase 163 silently no-op.
// ================================================================
function rep_log_version(PDO $pdo, int $projectId, string $key, string $text, string $html, string $source): void {
    try {
        $s = $pdo->query("SHOW TABLES LIKE 'mm_report_section_versions'");
        if (!$s || $s->fetch() === false) return;
    } catch (Throwable $e) { return; }
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mm_report_section_versions
               (project_id, section_key, body_text, body_html, source)
             VALUES (:p, :k, :tx, :hx, :sr)'
        );
        $ins->execute([
            ':p'  => $projectId,
            ':k'  => $key,
            ':tx' => $text !== '' ? $text : null,
            ':hx' => $html !== '' ? $html : null,
            ':sr' => $source !== '' ? $source : 'user',
        ]);
        // Keep only the newest 10. DELETE any older rows.
        $keep = $pdo->prepare(
            'SELECT id FROM mm_report_section_versions
             WHERE project_id = :p AND section_key = :k
             ORDER BY id DESC LIMIT 10'
        );
        $keep->execute([':p' => $projectId, ':k' => $key]);
        $keepIds = array_map('intval', $keep->fetchAll(PDO::FETCH_COLUMN));
        if (count($keepIds) > 0) {
            $place = implode(',', array_fill(0, count($keepIds), '?'));
            $args = array_merge([$projectId, $key], $keepIds);
            $del = $pdo->prepare(
                "DELETE FROM mm_report_section_versions
                 WHERE project_id = ? AND section_key = ? AND id NOT IN ($place)"
            );
            $del->execute($args);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// ================================================================
// HTML sanitizer for user-saved section content. Keeps only a small
// whitelist of structural tags. Strips every attribute. No scripts,
// no inline styles, no event handlers, no foreign tags.
// ================================================================
function rep_sanitize_html(string $html): string {
    // Allow only these tags. Everything else (including their content for
    // <script>, <style>) is stripped or escaped.
    $allowedTags = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote'];

    // Drop script/style/iframe blocks entirely (tag and content).
    $html = preg_replace('#<(script|style|iframe|object|embed|svg|link)[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|svg|link)[^>]*/?>#i', '', $html);

    // Strip every attribute on every tag we keep, drop every tag we don't.
    $out = preg_replace_callback('#<\s*(/?)\s*([a-zA-Z0-9]+)\b[^>]*>#', function ($m) use ($allowedTags) {
        $slash = $m[1];
        $tag = strtolower($m[2]);
        if (!in_array($tag, $allowedTags, true)) return '';
        return '<' . $slash . $tag . '>';
    }, $html);

    // Remove any HTML comments.
    $out = preg_replace('/<!--.*?-->/s', '', (string)$out);

    // Collapse leftover empty paragraphs.
    $out = preg_replace('#<p>\s*</p>#i', '', (string)$out);

    return trim((string)$out);
}
