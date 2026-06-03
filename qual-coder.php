<?php
// qual-coder.php — second coder's dedicated coding workspace
// Accessed via /qual-coder.php?token=ABC
// Validates the invite, then shows a focused coding interface.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_qual_studio.php';

start_session_secure();
$uid = current_user_id();

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    die('<p>Invalid link. No token provided.</p>');
}

// Redirect to login if not authenticated (return here after)
if (!$uid) {
    $returnUrl = '/qual-coder.php?token=' . urlencode($token);
    header('Location: /login.html?return=' . urlencode($returnUrl));
    exit;
}

$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();
qual_ensure_schema($pdo);

// Validate token
$s = $pdo->prepare(
    "SELECT qi.*, qp.title AS project_title, qp.user_id AS owner_id
     FROM qual_coder_invites qi
     JOIN qual_projects qp ON qp.id = qi.project_id
     WHERE qi.token = :t LIMIT 1"
);
$s->execute([':t' => $token]);
$inv = $s->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
    http_response_code(404);
    die('<p>This invite link is invalid or has expired. Ask the project lead to send a new one.</p>');
}
if ($inv['status'] === 'revoked') {
    http_response_code(403);
    die('<p>This invite has been revoked. Contact the project lead.</p>');
}
if ((int)$inv['owner_id'] === $uid) {
    http_response_code(403);
    die('<p>You cannot use this link to code your own project. Open the project from the <a href="/qual-studio.php">Qualitative Studio</a> instead.</p>');
}

$projectId    = (int)$inv['project_id'];
$projectTitle = $inv['project_title'];

// Accept invite if pending (or already accepted by this user — idempotent)
if ($inv['status'] === 'pending') {
    $pdo->prepare(
        "UPDATE qual_coder_invites SET status='accepted', accepted_by=:u, accepted_at=NOW() WHERE token=:t AND status='pending'"
    )->execute([':u' => $uid, ':t' => $token]);
    qual_audit($pdo, $projectId, $uid, 'coder_accepted_invite', 'invite', (int)$inv['id']);
} elseif ($inv['status'] === 'accepted' && (int)$inv['accepted_by'] !== $uid) {
    http_response_code(403);
    die('<p>This invite has already been claimed by someone else.</p>');
}

// Load project for display
$proj = $pdo->prepare("SELECT * FROM qual_projects WHERE id=:id LIMIT 1");
$proj->execute([':id' => $projectId]);
$projRow = $proj->fetch(PDO::FETCH_ASSOC);
if (!$projRow) { http_response_code(404); die('<p>Project not found.</p>'); }

$coderName = $user['name'] ?? $user['email'] ?? 'Coder';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $coderName) ?: 'C', 0, 2));

function _cv(string $path): string {
    $full = __DIR__ . $path;
    return is_file($full) ? (string)filemtime($full) : (string)time();
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Coding: <?= htmlspecialchars($projectTitle) ?> — ReliCheck</title>
<link rel="icon" href="/logo-brand.svg">
<style>
:root{
  --ink:#15171a;--ink-2:#5f6368;--ink-3:#8a8f98;
  --bg:#f5f6f8;--panel:#fff;--line:#e6e8ec;--line-2:#eef0f3;
  --btn:#1e5c3a;--btn-hover:#174d30;
  --acc:#1e5c3a;--acc-soft:#e8f5ee;--acc-deep:#174d30;
  --green:#1f9e44;--green-soft:#e9f7ee;
  --font:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif;
  --shadow:0 1px 2px rgba(20,28,45,.04),0 4px 16px rgba(20,28,45,.05);
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{font-family:var(--font);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
/* Header */
.cdr-header{background:var(--panel);border-bottom:1px solid var(--line);padding:0 28px;display:flex;align-items:center;gap:16px;height:56px;position:sticky;top:0;z-index:10}
.cdr-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.cdr-logo img{height:32px}
.cdr-badge{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;padding:3px 9px;border-radius:999px;background:var(--acc-soft);color:var(--acc-deep)}
.cdr-project{font-size:14px;font-weight:600;color:var(--ink-2);margin-left:4px}
.cdr-user{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-3)}
.cdr-avatar{width:30px;height:30px;border-radius:50%;background:var(--acc-soft);color:var(--acc-deep);display:grid;place-items:center;font-size:11px;font-weight:800}
/* Stats bar */
.cdr-stats{background:var(--panel);border-bottom:1px solid var(--line);padding:10px 28px;display:flex;gap:28px;align-items:center;font-size:13px}
.cdr-stat{display:flex;flex-direction:column}
.cdr-stat .n{font-size:18px;font-weight:800;color:var(--acc);line-height:1}
.cdr-stat .l{font-size:11px;color:var(--ink-3);margin-top:2px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
/* Main */
.cdr-main{max-width:860px;margin:28px auto;padding:0 20px 80px}
/* Filters */
.filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.filter-btn{font-size:12.5px;font-weight:700;padding:6px 14px;border-radius:999px;border:1.5px solid var(--line);background:var(--panel);color:var(--ink-2);cursor:pointer;transition:.13s;font-family:var(--font)}
.filter-btn.active{border-color:var(--acc);background:var(--acc-soft);color:var(--acc-deep)}
.search-input{flex:1;min-width:180px;padding:7px 13px;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;outline:none;font-family:var(--font)}
.search-input:focus{border-color:var(--acc)}
/* Segments */
.seg-list{display:flex;flex-direction:column;gap:10px}
.seg-card{background:var(--panel);border:1.5px solid var(--line);border-radius:12px;padding:16px 18px;transition:border-color .15s}
.seg-card.coded{border-color:color-mix(in srgb,var(--acc) 30%,white)}
.seg-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.seg-pid{font-size:11.5px;font-weight:700;color:var(--ink-3)}
.seg-q{font-size:11.5px;color:var(--ink-3);font-style:italic}
.seg-text{font-size:14.5px;color:var(--ink);line-height:1.7;margin-bottom:12px}
.code-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;background:var(--acc-soft);color:var(--acc-deep)}
.chip-x{background:none;border:none;cursor:pointer;padding:0;color:var(--acc-deep);font-size:13px;line-height:1;opacity:.7}
.chip-x:hover{opacity:1}
.seg-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.add-code-btn{font-size:12.5px;font-weight:700;color:var(--acc);background:var(--acc-soft);border:none;padding:5px 12px;border-radius:999px;cursor:pointer}
.flag{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.flag.uncoded{background:#f3f4f6;color:#9ca3af}
/* Picker */
.picker-wrap{position:relative;display:inline-block}
.picker{position:absolute;top:calc(100% + 6px);left:0;z-index:50;background:#fff;border:1.5px solid var(--line);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.12);min-width:220px;max-height:260px;overflow-y:auto;padding:6px}
.picker-item{display:block;width:100%;text-align:left;padding:8px 12px;border:none;background:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font)}
.picker-item:hover{background:var(--acc-soft);color:var(--acc-deep)}
.picker-empty{font-size:13px;color:var(--ink-3);padding:10px 12px}
/* Notices */
.notice{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px}
.notice.info{background:var(--acc-soft);color:var(--acc-deep)}
.notice.err{background:#fef2f2;color:#c0392b}
.btn{display:inline-flex;align-items:center;gap:7px;border:none;border-radius:10px;padding:9px 16px;font-family:var(--font);font-size:13.5px;font-weight:700;cursor:pointer;background:var(--acc-soft);color:var(--acc-deep)}
.btn.primary{background:var(--btn);color:#fff}
.btn.primary:hover{background:var(--btn-hover)}
.btn:disabled{opacity:.55;cursor:default}
.placeholder{padding:40px;text-align:center;color:var(--ink-3);border:1.5px dashed var(--line);border-radius:14px}
/* Done banner */
.done-banner{background:var(--green-soft);border:1.5px solid var(--green);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:14px;margin-bottom:20px}
.done-check{width:32px;height:32px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;flex-shrink:0;font-size:16px}
</style>
</head>
<body>

<header class="cdr-header">
  <a class="cdr-logo" href="/qual-studio.php">
    <img src="/Qualitative Analysis.png" alt="Qualitative Analysis Studio">
  </a>
  <span class="cdr-badge">Second Coder</span>
  <span class="cdr-project"><?= htmlspecialchars($projectTitle) ?></span>
  <div class="cdr-user">
    <span><?= htmlspecialchars($coderName) ?></span>
    <div class="cdr-avatar"><?= htmlspecialchars($initials) ?></div>
  </div>
</header>

<div class="cdr-stats">
  <div class="cdr-stat"><span class="n" id="stat-total">—</span><span class="l">Segments</span></div>
  <div class="cdr-stat"><span class="n" id="stat-coded">—</span><span class="l">You coded</span></div>
  <div class="cdr-stat"><span class="n" id="stat-remaining">—</span><span class="l">Remaining</span></div>
</div>

<main class="cdr-main">
  <div id="done-banner" style="display:none" class="done-banner">
    <div class="done-check">&#10003;</div>
    <div>
      <div style="font-size:15px;font-weight:700;color:var(--green);margin-bottom:2px">All segments coded</div>
      <div style="font-size:13px;color:#166534">Your coding is complete. The project lead will compare your decisions with theirs.</div>
    </div>
  </div>
  <div class="filters">
    <input class="search-input" id="seg-search" placeholder="Search segments...">
    <button class="filter-btn active" id="filter-all">All</button>
    <button class="filter-btn" id="filter-uncoded">Uncoded only</button>
  </div>
  <div id="seg-counts" style="font-size:13px;color:var(--ink-3);margin-bottom:14px">Loading...</div>
  <div class="seg-list" id="seg-list"><div class="placeholder">Loading segments...</div></div>
</main>

<script>
const BOOT = {
  projectId: <?= (int)$projectId ?>,
  token:     <?= json_encode($token) ?>,
  uid:       <?= (int)$uid ?>,
};
</script>
<script src="/apps/qual/qual-coder.js?v=<?= _cv('/apps/qual/qual-coder.js') ?>"></script>
</body>
</html>
