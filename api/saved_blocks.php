<?php
// /api/saved_blocks.php — durable DB-backed Save-to-Report storage.
// -------------------------------------------------------------------
// Per [[relicheck-reports-model]] the storage shape is three-layer:
//   Project (project_id) → Reports (report_id) → Blocks (saved snapshots)
// v1 uses a single 'default' report per project, but the key already
// encodes report_id so no data migration is needed when multi-report
// UI lands.
//
// Method routing:
//   GET    ?studio=&project_id=[&report_id=default]   → list blocks
//   POST   { studio, project_id, report_id?, block }  → save a block
//   DELETE ?block_uid=<client-generated id>           → delete one block
//
// `block` shape (matches what _studio_template_footer.php's
// saveCurrentView() builds from window.RELICHECK_APP_STATE):
//   {
//     id:       'b_<timestamp36>',     // client-generated unique id
//     addedAt:  ISO timestamp,
//     studio:   'survey'|'mm'|'tia'|'360',
//     project:  'Project label',
//     app:      'overview',
//     appName:  'Overview (Project Snapshot)',
//     summary:  'one-line digest',
//     payload:  { ...window.RELICHECK_APP_STATE... }
//   }
//
// The client continues to write through to localStorage so the existing
// Reporting + Interpretation engines that read from localStorage keep
// working without changes. This endpoint is the durable copy.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_session.php';

$user = require_auth();
$uid  = (int)$user['id'];
$pdo  = db();

// ------- Schema: auto-create on first request -------
// We don't gate on a migration file; the table is created lazily so
// deploying this endpoint to a fresh DB Just Works. Idempotent.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS saved_blocks (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        block_uid   VARCHAR(64) NOT NULL,
        user_id     BIGINT UNSIGNED NOT NULL,
        studio      VARCHAR(16) NOT NULL,
        project_id  BIGINT UNSIGNED NOT NULL,
        report_id   VARCHAR(64) NOT NULL DEFAULT 'default',
        app_key     VARCHAR(64) NOT NULL,
        app_name    VARCHAR(255) NOT NULL,
        lens        VARCHAR(64) NULL,
        summary     TEXT NULL,
        payload     LONGTEXT NOT NULL,
        added_at    DATETIME NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_block_uid_user (block_uid, user_id),
        KEY idx_user_proj_report (user_id, studio, project_id, report_id, added_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$valid_studios = ['survey', 'mm', 'tia', '360', 'strength-survey', 'descriptive', 'inferential'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $studio    = (string)($_GET['studio']    ?? '');
    $projectId = (int)   ($_GET['project_id'] ?? 0);
    $reportId  = (string)($_GET['report_id'] ?? 'default');
    if (!in_array($studio, $valid_studios, true)) fail('bad_studio', 'studio must be one of: ' . implode(',', $valid_studios));
    if ($projectId <= 0) fail('bad_project_id', 'project_id is required.');

    $stmt = $pdo->prepare(
        'SELECT block_uid, studio, project_id, report_id, app_key, app_name,
                lens, summary, payload, added_at
           FROM saved_blocks
          WHERE user_id = :uid AND studio = :studio AND project_id = :pid AND report_id = :rid
          ORDER BY added_at ASC, id ASC'
    );
    $stmt->execute([':uid' => $uid, ':studio' => $studio, ':pid' => $projectId, ':rid' => $reportId]);

    $blocks = [];
    while ($r = $stmt->fetch()) {
        $blocks[] = [
            'id'       => $r['block_uid'],
            'addedAt'  => $r['added_at'],
            'studio'   => $r['studio'],
            'projectId'=> (int)$r['project_id'],
            'reportId' => $r['report_id'],
            'app'      => $r['app_key'],
            'appName'  => $r['app_name'],
            'lens'     => $r['lens'],
            'summary'  => $r['summary'],
            'payload'  => json_decode((string)$r['payload'], true),
        ];
    }
    json_out(['ok' => true, 'blocks' => $blocks, 'count' => count($blocks)]);
}

if ($method === 'POST') {
    check_origin();
    $body = read_json_body();

    $studio    = (string)($body['studio']     ?? '');
    $projectId = (int)   ($body['project_id'] ?? 0);
    $reportId  = (string)($body['report_id']  ?? 'default');
    $block     = $body['block'] ?? null;

    if (!in_array($studio, $valid_studios, true)) fail('bad_studio', 'studio must be one of: ' . implode(',', $valid_studios));
    if ($projectId <= 0) fail('bad_project_id', 'project_id is required.');
    if (!is_array($block)) fail('bad_block', 'block must be an object.');

    $blockUid = (string)($block['id']      ?? '');
    $addedAt  = (string)($block['addedAt'] ?? '');
    $appKey   = (string)($block['app']     ?? 'unknown');
    $appName  = (string)($block['appName'] ?? 'Analysis');
    $lens     = isset($block['payload']['lens']) ? (string)$block['payload']['lens'] : null;
    $summary  = (string)($block['summary']  ?? '');
    $payload  = $block['payload'] ?? [];

    if ($blockUid === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $blockUid)) {
        fail('bad_block_id', 'block.id must be 1-64 chars, alphanumeric + _ -');
    }
    if ($addedAt === '') $addedAt = gmdate('Y-m-d H:i:s');
    else {
        // Accept ISO-8601 and normalize to MySQL DATETIME (UTC).
        $ts = strtotime($addedAt);
        $addedAt = $ts ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
    }
    if (strlen($appKey)  > 64)  $appKey  = substr($appKey, 0, 64);
    if (strlen($appName) > 255) $appName = substr($appName, 0, 255);
    if ($lens !== null && strlen($lens) > 64) $lens = substr($lens, 0, 64);

    // Verify project ownership against the studio's project table.
    $owns = false;
    try {
        if ($studio === 'survey' || $studio === 'strength-survey') {
            $os = $pdo->prepare('SELECT 1 FROM surveys WHERE id = :id AND owner_id = :uid');
        } elseif ($studio === 'mm') {
            $os = $pdo->prepare('SELECT 1 FROM mm_projects WHERE id = :id AND user_id = :uid');
        } elseif ($studio === '360') {
            $os = $pdo->prepare('SELECT 1 FROM survey_360_panels WHERE id = :id AND user_id = :uid');
        } else { // tia
            $os = $pdo->prepare('SELECT 1 FROM tia_projects WHERE id = :id AND user_id = :uid');
        }
        $os->execute([':id' => $projectId, ':uid' => $uid]);
        $owns = (bool)$os->fetchColumn();
    } catch (Throwable $e) {
        // Some studio tables (e.g. tia_projects) may not exist yet.
        // Treat that as ownership-unknown and refuse, rather than over-trust.
        $owns = false;
    }
    if (!$owns) fail('forbidden', 'You do not own this project.', 403);

    // Upsert by (block_uid, user_id) so duplicate clicks don't multiply rows.
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) fail('bad_payload', 'block.payload could not be JSON-encoded.');
    if (strlen($payloadJson) > 1500000) fail('payload_too_large', 'block.payload exceeds 1.5MB.');

    $sql =
      'INSERT INTO saved_blocks
         (block_uid, user_id, studio, project_id, report_id, app_key, app_name, lens, summary, payload, added_at)
       VALUES
         (:uid_block, :uid, :studio, :pid, :rid, :app_key, :app_name, :lens, :summary, :payload, :added_at)
       ON DUPLICATE KEY UPDATE
         studio    = VALUES(studio),
         project_id= VALUES(project_id),
         report_id = VALUES(report_id),
         app_key   = VALUES(app_key),
         app_name  = VALUES(app_name),
         lens      = VALUES(lens),
         summary   = VALUES(summary),
         payload   = VALUES(payload),
         added_at  = VALUES(added_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid_block'=> $blockUid,
        ':uid'      => $uid,
        ':studio'   => $studio,
        ':pid'      => $projectId,
        ':rid'      => $reportId,
        ':app_key'  => $appKey,
        ':app_name' => $appName,
        ':lens'     => $lens,
        ':summary'  => $summary,
        ':payload'  => $payloadJson,
        ':added_at' => $addedAt,
    ]);

    // Return the freshly-stored row count for this report so the UI can
    // show "Saved (N blocks)" with a server-trusted number.
    $c = $pdo->prepare(
        'SELECT COUNT(*) FROM saved_blocks
          WHERE user_id = :uid AND studio = :studio AND project_id = :pid AND report_id = :rid'
    );
    $c->execute([':uid' => $uid, ':studio' => $studio, ':pid' => $projectId, ':rid' => $reportId]);
    json_out(['ok' => true, 'block_uid' => $blockUid, 'count' => (int)$c->fetchColumn()]);
}

if ($method === 'DELETE') {
    check_origin();
    $blockUid = (string)($_GET['block_uid'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $blockUid)) fail('bad_block_id', 'block_uid is required.');
    $stmt = $pdo->prepare('DELETE FROM saved_blocks WHERE block_uid = :b AND user_id = :uid');
    $stmt->execute([':b' => $blockUid, ':uid' => $uid]);
    json_out(['ok' => true, 'deleted' => (int)$stmt->rowCount()]);
}

require_method('GET', 'POST', 'DELETE');
