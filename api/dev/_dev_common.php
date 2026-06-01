<?php
// Shared helpers for the Survey Development System (develop.php) DB mode.
// ---------------------------------------------------------------------------
// • sds_ensure_schema()  — idempotent CREATE TABLE IF NOT EXISTS for all 11 dev
//   tables, mirroring db/schema_survey_dev_system.sql. Runs on first call per
//   request so endpoints work on a fresh DB without a manual migration step.
//   Fully additive: never alters/drops existing ReliCheck tables.
// • sds_seed_system_templates() — seeds the 6 starter templates once.
// • sds_require_project() — load a project row with an owner check.
// • sds_item_type() / sds_clean_flag() — input normalisers.
//
// Naming (locked): SDSI = design-strength (50), SIRI = readiness (100),
// RSSI = post-response (not wired here).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

function sds_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $stmts = [
        "CREATE TABLE IF NOT EXISTS survey_projects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            purpose TEXT NULL,
            population TEXT NULL,
            response_mode VARCHAR(64) NOT NULL DEFAULT '5-pt agreement',
            data_type VARCHAR(32) NOT NULL DEFAULT 'Quantitative',
            source VARCHAR(24) NOT NULL DEFAULT 'scratch',
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            settings JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_sproj_user (user_id),
            KEY idx_sproj_status (user_id, status),
            CONSTRAINT fk_sproj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS survey_sections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL DEFAULT 'Section',
            description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ssec_project (project_id, position),
            CONSTRAINT fk_ssec_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS survey_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            section_id BIGINT UNSIGNED NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(40) NOT NULL DEFAULT 'Likert (5-pt)',
            prompt TEXT NOT NULL,
            options JSON NULL,
            flag VARCHAR(16) NULL,
            required TINYINT(1) NOT NULL DEFAULT 0,
            settings JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_sitem_project (project_id, position),
            KEY idx_sitem_section (section_id),
            CONSTRAINT fk_sitem_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_sitem_section FOREIGN KEY (section_id) REFERENCES survey_sections(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS survey_constructs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL,
            definition TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_scons_project (project_id, position),
            CONSTRAINT fk_scons_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS survey_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            slug VARCHAR(64) NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT 'General',
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            items_count INT UNSIGNED NOT NULL DEFAULT 0,
            scale VARCHAR(64) NOT NULL DEFAULT '5-pt agreement',
            domains JSON NULL,
            payload JSON NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stmpl_slug (slug),
            KEY idx_stmpl_user (user_id),
            CONSTRAINT fk_stmpl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS sdsi_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            total DECIMAL(6,2) NOT NULL DEFAULT 0,
            max_points DECIMAL(6,2) NOT NULL DEFAULT 50,
            pct INT UNSIGNED NOT NULL DEFAULT 0,
            band VARCHAR(120) NULL,
            blocked TINYINT(1) NOT NULL DEFAULT 0,
            review JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sdsi_project (project_id),
            CONSTRAINT fk_sdsi_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS siri_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            total DECIMAL(6,2) NOT NULL DEFAULT 0,
            max_points DECIMAL(6,2) NOT NULL DEFAULT 100,
            pct INT UNSIGNED NOT NULL DEFAULT 0,
            band VARCHAR(120) NULL,
            blocked TINYINT(1) NOT NULL DEFAULT 0,
            review JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_siri_project (project_id),
            CONSTRAINT fk_siri_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Phase 4C: RSSI reviews — the post-data ReliCheck Survey Strength
        // Index, persisted SEPARATELY from sdsi_reviews (design) and
        // siri_reviews (pre-launch). total/pct are NULLABLE so a withheld
        // ("Insufficient data to judge") result is stored honestly instead of
        // as a fake 0. response_count + last_submitted_at fingerprint the
        // response data at run time so a reopened project can detect a stale
        // RSSI (calculated before newer responses) rather than pretend it is current.
        "CREATE TABLE IF NOT EXISTS rssi_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            total DECIMAL(6,2) NULL,
            max_points DECIMAL(6,2) NOT NULL DEFAULT 100,
            pct INT UNSIGNED NULL,
            band VARCHAR(160) NULL,
            verdict VARCHAR(160) NULL,
            withheld TINYINT(1) NOT NULL DEFAULT 0,
            response_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_submitted_at DATETIME NULL,
            review JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rssi_project (project_id),
            CONSTRAINT fk_rssi_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS deployment_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            settings JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_deploy_project (project_id),
            CONSTRAINT fk_deploy_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS response_summaries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            collected INT UNSIGNED NOT NULL DEFAULT 0,
            target INT UNSIGNED NOT NULL DEFAULT 0,
            summary JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_respsum_project (project_id),
            CONSTRAINT fk_respsum_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Phase 3D: one row per completed public submission. No respondent
        // identity is stored — only a salted IP hash (via ip_hash()) for basic
        // abuse triage and a truncated user-agent. NOT linked to users().
        "CREATE TABLE IF NOT EXISTS survey_dev_response_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            link_key VARCHAR(20) NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            KEY idx_devsess_project (project_id, submitted_at),
            KEY idx_devsess_link (link_key),
            CONSTRAINT fk_devsess_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Phase 3D: one row per answer. item_id is the survey_items.id at submit
        // time (no FK — items may later be edited/removed), and item_label is a
        // snapshot of the prompt so collected data stays interpretable.
        "CREATE TABLE IF NOT EXISTS survey_dev_answers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NULL,
            item_label VARCHAR(500) NOT NULL DEFAULT '',
            answer_value MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_devans_session (session_id),
            KEY idx_devans_project (project_id),
            KEY idx_devans_item (item_id),
            CONSTRAINT fk_devans_session FOREIGN KEY (session_id) REFERENCES survey_dev_response_sessions(id) ON DELETE CASCADE,
            CONSTRAINT fk_devans_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($stmts as $sql) {
        $pdo->exec($sql);
    }

    // Idempotent migration: add survey_items.required to tables created before
    // this column existed. Guarded by information_schema so it runs at most once.
    $hasRequired = (int)$pdo->query(
        "SELECT COUNT(*) AS c FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'survey_items'
           AND column_name = 'required'"
    )->fetch()['c'];
    if ($hasRequired === 0) {
        $pdo->exec("ALTER TABLE survey_items ADD COLUMN required TINYINT(1) NOT NULL DEFAULT 0 AFTER flag");
    }

    $done = true;
}

// Seed the 6 starter templates once (mirrors develop.php's MOCK.templates so
// the template browser is populated on a fresh DB). Idempotent via INSERT IGNORE
// on the unique slug. Each template carries a small build blueprint in payload.
function sds_seed_system_templates(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) AS c FROM survey_templates WHERE is_system = 1')->fetch()['c'];
    if ($count > 0) return;

    $likert = function (string $prompt, ?string $flag = null) {
        return ['type' => 'Likert (5-pt)', 'prompt' => $prompt, 'flag' => $flag];
    };
    $open = function (string $prompt, ?string $flag = null) {
        return ['type' => 'Open-Ended', 'prompt' => $prompt, 'flag' => $flag];
    };

    $engagementItems = [
        $likert('How satisfied are you with your role overall?'),
        $likert('My manager gives me useful feedback.'),
        $likert('I have the resources I need to do my job well.'),
        $likert('I would recommend this organization as a place to work.'),
        $likert('I feel a sense of belonging on my team.'),
        $likert('My contributions are valued.'),
        $likert('I see a clear path for growth here.'),
        $open('What is one thing we could do to improve your experience?'),
    ];

    $templates = [
        ['t-eng',  'Workforce',    'Employee Engagement Pulse',    'Validated 3-factor structure; alpha ~ .89 in field use.', 18, '5-pt agreement', ['Engagement', 'Manager Support', 'Growth'], $engagementItems],
        ['t-cust', 'Customer',     'Customer Satisfaction (CSAT)', 'Includes NPS anchor item and open-ended driver.',          12, 'Mixed',          ['Satisfaction', 'Effort', 'Loyalty'], [$likert('Overall, how satisfied are you with our service?'), $likert('It was easy to get what I needed.'), $open('What is the main reason for your score?')]],
        ['t-clim', 'Education',    'School Climate Survey (6-8)',  'K-12 reading-level anchored; bias-reviewed wording.',      24, '4-pt frequency', ['Safety', 'Belonging', 'Engagement', 'Relationships'], [$likert('I feel safe at school.'), $likert('I belong at this school.'), $likert('My teachers care about me.')]],
        ['t-pat',  'Healthcare',   'Patient Experience',           'Aligned to CAHPS-style domains.',                          20, '5-pt + open',    ['Access', 'Communication', 'Trust'], [$likert('I could get appointments when I needed them.'), $likert('My provider explained things clearly.'), $open('How could we improve your care?')]],
        ['t-360',  '360 Feedback', 'Leadership 360',               'Self + rater forms; rater-group ready.',                   32, '5-pt + behavior', ['Vision', 'Execution', 'People', 'Integrity'], [$likert('This leader sets a clear direction.'), $likert('This leader delivers on commitments.'), $likert('This leader develops people.')]],
        ['t-test', 'Assessment',   'Grade-8 Knowledge Check',      'Answer-key + distractor analysis ready.',                  25, 'Multiple choice', ['Reading', 'Math', 'Science'], [['type' => 'Multiple Choice', 'prompt' => 'Which value of x satisfies 2x + 3 = 11?', 'flag' => null]]],
    ];

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO survey_templates
            (user_id, slug, category, name, description, items_count, scale, domains, payload, is_system)
         VALUES (NULL, :slug, :cat, :name, :descr, :items, :scale, :domains, :payload, 1)'
    );
    foreach ($templates as [$slug, $cat, $name, $descr, $items, $scale, $domains, $blueprintItems]) {
        $payload = [
            'sections' => [['title' => 'Main', 'description' => null, 'position' => 0]],
            'items'    => array_values($blueprintItems),
        ];
        $ins->execute([
            ':slug'    => $slug,
            ':cat'     => $cat,
            ':name'    => $name,
            ':descr'   => $descr,
            ':items'   => $items,
            ':scale'   => $scale,
            ':domains' => json_encode($domains, JSON_UNESCAPED_UNICODE),
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }
}

// Load a project the caller owns, or fail() with the right HTTP status.
function sds_require_project(PDO $pdo, int $userId, int $projectId): array
{
    if ($projectId <= 0) fail('bad_input', 'A project id is required.');
    $stmt = $pdo->prepare('SELECT * FROM survey_projects WHERE id = :id');
    $stmt->execute([':id' => $projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)                                    fail('not_found', 'Project not found.', 404);
    if ((int)$row['user_id'] !== $userId)         fail('forbidden', 'You do not own this project.', 403);
    return $row;
}

// Normalise an item type label to the supported set (defaults to Open-Ended).
function sds_item_type($val): string
{
    $allowed = [
        // Legacy types (kept for back-compat with existing rows/templates)
        'Open-Ended', 'Single Choice', 'Likert (5-pt)', 'Likert (7-pt)',
        // One-click builder catalog
        'Multiple Choice', 'Checkboxes', 'Dropdown', 'Yes/No', 'True/False',
        'Likert Scale', 'Rating Scale', 'Matrix/Grid', 'NPS',
        'Short Answer', 'Long Answer', 'Comment Box',
        'Ranking', 'Slider',
        'Demographic', 'Email', 'Phone', 'Date', 'Numeric',
        'Section Text', 'Consent', 'Page Break', 'Thank-you Message',
        // Legacy aliases still referenced anywhere
        'Rating',
    ];
    $s = clean_string((string)$val, 40);
    return in_array($s, $allowed, true) ? $s : 'Short Answer';
}

// Normalise an item flag to null|info|warn|err.
function sds_clean_flag($val): ?string
{
    $s = clean_string((string)$val, 16);
    return in_array($s, ['info', 'warn', 'err'], true) ? $s : null;
}

// Serialise a project + its sections/items/constructs + stored reviews for the client.
function sds_project_payload(PDO $pdo, int $projectId): array
{
    $p = $pdo->prepare('SELECT * FROM survey_projects WHERE id = :id');
    $p->execute([':id' => $projectId]);
    $proj = $p->fetch(PDO::FETCH_ASSOC);
    if (!$proj) fail('not_found', 'Project not found.', 404);

    $secStmt = $pdo->prepare('SELECT id, position, title, description FROM survey_sections WHERE project_id = :id ORDER BY position, id');
    $secStmt->execute([':id' => $projectId]);
    $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC);

    $itStmt = $pdo->prepare('SELECT id, section_id, position, type, prompt, options, flag, required, settings FROM survey_items WHERE project_id = :id ORDER BY position, id');
    $itStmt->execute([':id' => $projectId]);
    $items = array_map(function ($r) {
        return [
            'id'         => (int)$r['id'],
            'section_id' => $r['section_id'] !== null ? (int)$r['section_id'] : null,
            'position'   => (int)$r['position'],
            'type'       => $r['type'],
            'prompt'     => $r['prompt'],
            'options'    => $r['options'] !== null ? json_decode((string)$r['options'], true) : null,
            'flag'       => $r['flag'],
            'required'   => (bool)$r['required'],
            'settings'   => $r['settings'] !== null ? json_decode((string)$r['settings'], true) : null,
        ];
    }, $itStmt->fetchAll(PDO::FETCH_ASSOC));

    $consStmt = $pdo->prepare('SELECT id, position, name, definition FROM survey_constructs WHERE project_id = :id ORDER BY position, id');
    $consStmt->execute([':id' => $projectId]);
    $constructs = $consStmt->fetchAll(PDO::FETCH_ASSOC);

    $readReview = function (string $table) use ($pdo, $projectId) {
        $stmt = $pdo->prepare("SELECT total, max_points, pct, band, blocked, review FROM {$table} WHERE project_id = :id");
        $stmt->execute([':id' => $projectId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'total'   => (float)$r['total'],
            'max'     => (float)$r['max_points'],
            'pct'     => (int)$r['pct'],
            'band'    => $r['band'],
            'blocked' => (bool)$r['blocked'],
            'review'  => $r['review'] !== null ? json_decode((string)$r['review'], true) : null,
        ];
    };

    return [
        'project' => [
            'id'            => (int)$proj['id'],
            'title'         => $proj['title'],
            'purpose'       => $proj['purpose'],
            'population'    => $proj['population'],
            'response_mode' => $proj['response_mode'],
            'data_type'     => $proj['data_type'],
            'source'        => $proj['source'],
            'status'        => $proj['status'],
            'settings'      => $proj['settings'] !== null ? json_decode((string)$proj['settings'], true) : null,
            'updated_at'    => $proj['updated_at'],
        ],
        'sections'   => array_map(function ($s) {
            return ['id' => (int)$s['id'], 'position' => (int)$s['position'], 'title' => $s['title'], 'description' => $s['description']];
        }, $sections),
        'items'      => $items,
        'constructs' => array_map(function ($c) {
            return ['id' => (int)$c['id'], 'position' => (int)$c['position'], 'name' => $c['name'], 'definition' => $c['definition']];
        }, $constructs),
        'sdsi'       => $readReview('sdsi_reviews'),
        'siri'       => $readReview('siri_reviews'),
        // Phase 4C: the saved RSSI review, hydrated separately from SDSI/SIRI.
        // `stale` is computed here by comparing the response-data fingerprint
        // captured when RSSI last ran against the live response count + newest
        // submission, so a reopened project never silently treats an old score
        // as current.
        'rssi'       => (function () use ($pdo, $projectId) {
            $stmt = $pdo->prepare('SELECT total, max_points, pct, band, verdict, withheld, response_count, last_submitted_at, review, updated_at FROM rssi_reviews WHERE project_id = :id');
            $stmt->execute([':id' => $projectId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $fp = $pdo->prepare('SELECT COUNT(*) AS c, MAX(submitted_at) AS last FROM survey_dev_response_sessions WHERE project_id = :id');
            $fp->execute([':id' => $projectId]);
            $cur = $fp->fetch(PDO::FETCH_ASSOC);
            $curCount = (int)($cur['c'] ?? 0);
            $curLast  = $cur['last'] ?? null;
            $stale = ($curCount !== (int)$r['response_count'])
                  || (($curLast ?? '') !== ($r['last_submitted_at'] ?? ''));
            return [
                'total'             => $r['total'] !== null ? (float)$r['total'] : null,
                'max'               => (float)$r['max_points'],
                'pct'               => $r['pct'] !== null ? (int)$r['pct'] : null,
                'band'              => $r['band'],
                'verdict'           => $r['verdict'],
                'withheld'          => (bool)$r['withheld'],
                'response_count'    => (int)$r['response_count'],
                'last_submitted_at' => $r['last_submitted_at'],
                'updated_at'        => $r['updated_at'],
                'stale'             => $stale,
                'current_count'     => $curCount,
                'review'            => $r['review'] !== null ? json_decode((string)$r['review'], true) : null,
            ];
        })(),
        'deployment' => (function () use ($pdo, $projectId) {
            $s = $pdo->prepare('SELECT settings FROM deployment_settings WHERE project_id = :id');
            $s->execute([':id' => $projectId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['settings'] === null) return null;
            return json_decode((string)$r['settings'], true);
        })(),
        // Phase 3D: count of completed public submissions, for the deploy screen.
        'responses'  => (function () use ($pdo, $projectId) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM survey_dev_response_sessions WHERE project_id = :id');
            $s->execute([':id' => $projectId]);
            return (int)$s->fetchColumn();
        })(),
    ];
}
