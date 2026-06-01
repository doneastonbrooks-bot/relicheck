<?php
// POST /api/public/submit-dev.php
// Public, no-login endpoint that stores one respondent submission for a Survey
// Development System project, addressed by its deployment link_key.
//
// Phase 3D: response submission + storage ONLY. It does NOT compute RSSI, does
// NOT run any analysis, and never exposes builder/owner data back to the
// respondent — a successful call returns just {ok:true}.
//
// Accepts a submission only when the deployment's responses_open flag is true;
// a closed survey is rejected with HTTP 403 {closed:true}, mirroring the gate
// in api/public/survey-dev.php.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('POST');
// Like api/public/submit.php, we deliberately do NOT call check_origin() here:
// respondents reach the survey from email/links where the Origin/Referer host
// will not match, and the endpoint is anonymous with no cookies/credentials in
// play, so there is no CSRF surface to protect.

$body    = read_json_body();
$linkKey = isset($body['link_key']) ? trim((string)$body['link_key']) : '';
$answers = (isset($body['answers']) && is_array($body['answers'])) ? $body['answers'] : null;

if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $linkKey)) {
    fail('bad_key', 'Invalid survey link.', 400);
}
if ($answers === null) {
    fail('bad_input', 'No answers were submitted.', 400);
}

$pdo = db();

// Defensive: ensure the Phase 3D tables exist even if no authenticated dev
// endpoint has run sds_ensure_schema() since they were added. Fully additive.
$pdo->exec(
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Resolve link_key → project, and read the open/closed gate.
$dsStmt = $pdo->prepare(
    "SELECT project_id, settings FROM deployment_settings
     WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.link_key')) = :k
     LIMIT 1"
);
$dsStmt->execute([':k' => $linkKey]);
$dsRow = $dsStmt->fetch(PDO::FETCH_ASSOC);
if (!$dsRow) fail('not_found', 'No survey was found at that link.', 404);

$ds        = json_decode((string)$dsRow['settings'], true) ?: [];
$projectId = (int)$dsRow['project_id'];

// Gate: only accept submissions while the survey is open.
if (empty($ds['responses_open'])) {
    fail('not_open', 'This survey is not currently accepting responses.', 403, ['closed' => true]);
}

// Confirm the project is still a published dev survey.
$pStmt = $pdo->prepare('SELECT status, settings FROM survey_projects WHERE id = :id LIMIT 1');
$pStmt->execute([':id' => $projectId]);
$proj = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$proj || $proj['status'] !== 'published') {
    fail('not_found', 'No survey was found at that link.', 404);
}

// ReliCheck Basic 25-response cap — enforced server-side (never front-end only).
// Basic projects carry settings.tier = 'basic'. Once 25 sessions are stored,
// reject further submissions with a clear cap/upgrade message.
$projSettings = json_decode((string)($proj['settings'] ?? ''), true) ?: [];
if (($projSettings['tier'] ?? '') === 'basic') {
    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM survey_dev_response_sessions WHERE project_id = :id');
    $cntStmt->execute([':id' => $projectId]);
    if ((int)$cntStmt->fetchColumn() >= 25) {
        fail('basic_limit_reached', 'This Basic survey has reached its 25-response limit. The owner can upgrade for unlimited responses.', 403, ['basic_cap' => true]);
    }
}

// Load the project's items so we can validate against them, snapshot each
// prompt, and ignore any answer keys that do not belong to this survey.
$iStmt = $pdo->prepare(
    'SELECT id, type, prompt, required FROM survey_items WHERE project_id = :id ORDER BY position, id'
);
$iStmt->execute([':id' => $projectId]);
$items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

// Item types that take no respondent input.
$NON_INPUT = ['Section Text', 'Instructions', 'Page Break', 'Thank-you Message'];

// Normalise a submitted value to the string we store, or null when blank.
$normalise = function ($v): ?string {
    if ($v === null) return null;
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_array($v)) {
        $clean = array_values(array_filter($v, fn($x) => $x !== null && $x !== ''));
        if (count($clean) === 0) return null;
        $s = json_encode($clean, JSON_UNESCAPED_UNICODE);
    } else {
        $s = trim((string)$v);
    }
    if ($s === '') return null;
    if (mb_strlen($s) > 5000) $s = mb_substr($s, 0, 5000);
    return $s;
};

// Build the rows to store and enforce required answers server-side.
$rows = [];
foreach ($items as $it) {
    if (in_array($it['type'], $NON_INPUT, true)) continue;
    $key   = 'i' . $it['id'];
    $raw   = array_key_exists($key, $answers) ? $answers[$key] : null;
    $value = $normalise($raw);

    if ((int)$it['required'] === 1 && $value === null) {
        fail('incomplete', 'Please answer all required questions before submitting.', 422);
    }
    if ($value === null) continue; // optional + unanswered → no row

    $rows[] = [
        'item_id' => (int)$it['id'],
        'label'   => mb_substr((string)$it['prompt'], 0, 500),
        'value'   => $value,
    ];
}

if (count($rows) === 0) {
    fail('empty_submission', 'No answers were submitted.', 422);
}

// Persist the session and its answers atomically.
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

try {
    $pdo->beginTransaction();

    $sessStmt = $pdo->prepare(
        'INSERT INTO survey_dev_response_sessions (project_id, link_key, submitted_at, ip_hash, user_agent)
         VALUES (:pid, :lk, NOW(), :ip, :ua)'
    );
    $sessStmt->execute([
        ':pid' => $projectId,
        ':lk'  => $linkKey,
        ':ip'  => ip_hash(),
        ':ua'  => $ua,
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    $ansStmt = $pdo->prepare(
        'INSERT INTO survey_dev_answers (session_id, project_id, item_id, item_label, answer_value)
         VALUES (:sid, :pid, :iid, :label, :val)'
    );
    foreach ($rows as $r) {
        $ansStmt->execute([
            ':sid'   => $sessionId,
            ':pid'   => $projectId,
            ':iid'   => $r['item_id'],
            ':label' => $r['label'],
            ':val'   => $r['value'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('save_failed', 'We could not save your response. Please try again.', 500);
}

json_out(['ok' => true]);
