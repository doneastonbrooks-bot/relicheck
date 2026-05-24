<?php
// POST /api/mm/feedback.php
//   { rating?: 1..5, comment?: string, project_id?: int, page_kind?: string, viewport?: string }
//
// Writes a row to mm_feedback. Rate-limited so a single signed-in user
// can't spam the table (20/hr/user). Comment + admin_note are TEXT;
// everything else is bounded.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$uid  = (int)$user['id'];

check_rate_limit('mm_feedback:user:' . $uid, 20, 3600);

$body = read_json_body();

$rating = null;
if (isset($body['rating']) && is_numeric($body['rating'])) {
    $r = (int)$body['rating'];
    if ($r >= 1 && $r <= 5) $rating = $r;
}
$comment   = trim((string)($body['comment']   ?? ''));
$projectId = isset($body['project_id']) && is_numeric($body['project_id']) ? (int)$body['project_id'] : null;
$pageKind  = substr((string)($body['page_kind'] ?? ''), 0, 40);
$viewport  = substr((string)($body['viewport']  ?? ''), 0, 40);

if ($rating === null && $comment === '') {
    fail('empty_feedback', 'Add a rating or a comment so we know what to look at.', 400);
}
if (strlen($comment) > 5000) {
    $comment = substr($comment, 0, 5000);
}

$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$pdo = db();

// Table guard: if Phase 169 migration hasn't been applied yet, return a
// helpful 503 instead of an HTML 500. Same pattern as the rest of the
// MM endpoints.
try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'mm_feedback'")->fetchColumn();
} catch (Throwable $e) { $has = false; }
if (!$has) {
    fail('feedback_not_ready', 'Feedback storage is not configured on this server yet. Apply db/schema_phase169.sql.', 503);
}

// If project_id is provided, confirm it belongs to this user before
// storing it. If it doesn't, just drop the project_id (don't fail the
// whole submit) so users still get their comment recorded.
if ($projectId !== null) {
    try {
        $owns = $pdo->prepare('SELECT 1 FROM mm_projects WHERE id = :i AND owner_id = :u LIMIT 1');
        $owns->execute([':i' => $projectId, ':u' => $uid]);
        if (!$owns->fetchColumn()) $projectId = null;
    } catch (Throwable $e) {
        $projectId = null;
    }
}

$pdo->prepare(
    'INSERT INTO mm_feedback (user_id, project_id, rating, comment, page_kind, user_agent, viewport)
     VALUES (:u, :p, :r, :c, :pk, :ua, :vp)'
)->execute([
    ':u'  => $uid,
    ':p'  => $projectId,
    ':r'  => $rating,
    ':c'  => $comment !== '' ? $comment : null,
    ':pk' => $pageKind !== '' ? $pageKind : null,
    ':ua' => $ua !== '' ? $ua : null,
    ':vp' => $viewport !== '' ? $viewport : null,
]);

json_out(['ok' => true]);
