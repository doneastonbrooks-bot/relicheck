<?php
// Survey Studio · My Surveys (project table).
// Platform Shell only — no studio template, no sidebar/rail.
// Table view with status badges and contextual actions.
// Deploy action PATCHes /api/surveys/update.php with is_published:true.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-survey-projects.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// Deep-link: ?project_id=N → bounce straight to the right destination
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id, is_published FROM surveys WHERE id = :id AND owner_id = :uid AND archived_at IS NULL');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $row = false; }
  if ($row) {
    if (!$row['is_published']) {
      header('Location: /survey-builder.php?id=' . $projectId);
    } else {
      header('Location: /project-snapshot.php?studio=survey&project_id=' . $projectId);
    }
    exit;
  }
}

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = 'My Surveys — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = '';
$shell_body_attrs    = 'data-current-studio="survey" data-studio-landing="survey"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root {
    --accent:      #e85d3a;
    --accent-soft: #fdeee9;
  }

  /* ── Page hero ── */
  .projects-hero {
    padding: 44px 0 28px;
    border-bottom: 1px solid rgba(15,23,42,0.07);
    margin-bottom: 28px;
  }
  .projects-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; color: #5f6368; text-decoration: none;
    margin-bottom: 16px;
    transition: color 0.12s;
  }
  .projects-back:hover { color: #15171a; }
  .projects-hero h1 {
    font-family: Inter, -apple-system, sans-serif;
    font-size: 32px; font-weight: 700; letter-spacing: -0.025em;
    color: #15171a; margin: 0 0 8px;
  }
  .projects-hero p {
    font-size: 15px; color: #5f6368; margin: 0; line-height: 1.55;
  }

  /* ── Toolbar ── */
  .projects-bar {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 18px; flex-wrap: wrap;
  }
  .filter-pill {
    padding: 6px 14px; border-radius: 999px;
    border: 1.5px solid rgba(15,23,42,0.10);
    background: #fff; color: #5f6368;
    font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all 0.12s;
  }
  .filter-pill:hover  { border-color: var(--accent); color: var(--accent); }
  .filter-pill.active { background: var(--accent); border-color: var(--accent); color: #fff; }
  .projects-bar-spacer { flex: 1; }
  .projects-count { font-size: 13px; color: #8a8f98; font-weight: 500; }
  .btn-new-survey {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; border-radius: 10px;
    background: var(--accent); color: #fff;
    font-family: inherit; font-size: 13px; font-weight: 700;
    text-decoration: none; border: none; cursor: pointer;
    transition: opacity 0.12s;
  }
  .btn-new-survey:hover { opacity: 0.88; }

  /* ── Table shell ── */
  .sv-table-wrap {
    background: #fff;
    border: 1px solid rgba(15,23,42,0.08);
    border-radius: 18px;
    box-shadow: 0 2px 8px rgba(15,23,42,0.07);
    overflow: hidden;
  }
  .sv-table {
    width: 100%; border-collapse: collapse;
    font-family: Inter, -apple-system, sans-serif;
    font-size: 13.5px;
  }
  .sv-table thead th {
    padding: 11px 16px;
    background: #f8f9fa;
    border-bottom: 1px solid rgba(15,23,42,0.08);
    font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
    text-transform: uppercase; color: #8a8f98;
    text-align: left; white-space: nowrap;
  }
  .sv-table thead th:last-child { text-align: right; }
  .sv-table tbody tr {
    border-bottom: 1px solid rgba(15,23,42,0.05);
    transition: background 0.1s;
  }
  .sv-table tbody tr:last-child { border-bottom: none; }
  .sv-table tbody tr:hover { background: #fafbfc; }
  .sv-table tbody tr[data-hidden="1"] { display: none; }
  .sv-table td {
    padding: 14px 16px;
    vertical-align: middle;
  }

  /* Title cell */
  .sv-td-title {
    font-weight: 600; color: #15171a; font-size: 14px;
    max-width: 320px;
  }
  .sv-td-title a {
    color: inherit; text-decoration: none;
  }
  .sv-td-title a:hover { color: var(--accent); }
  .sv-td-slug {
    font-size: 11.5px; color: #8a8f98; margin-top: 3px;
    font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace;
  }

  /* Status badge */
  .sv-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; letter-spacing: 0.04em;
    text-transform: uppercase; white-space: nowrap;
  }
  .sv-badge .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
  .sv-badge--draft    { background: #f0f1f3; color: #8a8f98; }
  .sv-badge--draft .dot    { background: #c0c5cf; }
  .sv-badge--live     { background: #e6efff; color: #2563eb; }
  .sv-badge--live .dot     { background: #2563eb; }
  .sv-badge--collecting { background: #e3f5ee; color: #0e8a6f; }
  .sv-badge--collecting .dot { background: #0e8a6f; animation: blink 1.4s infinite; }
  .sv-badge--archived { background: #f0f1f3; color: #8a8f98; }
  .sv-badge--archived .dot { background: #c0c5cf; }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.35} }

  /* Stat cells */
  .sv-td-num { color: #5f6368; font-variant-numeric: tabular-nums; }
  .sv-td-date { color: #8a8f98; font-size: 12.5px; white-space: nowrap; }

  /* Actions cell */
  .sv-td-actions {
    text-align: right; white-space: nowrap;
  }
  .sv-td-actions .action-group {
    display: inline-flex; align-items: center; gap: 6px;
  }
  .sv-action-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 13px; border-radius: 8px;
    font-family: inherit; font-size: 12px; font-weight: 700;
    cursor: pointer; border: none; text-decoration: none;
    transition: opacity 0.12s, background 0.12s;
    white-space: nowrap;
  }
  .sv-action-btn--ghost   { background: #f0f1f3; color: #5f6368; }
  .sv-action-btn--ghost:hover { background: #e4e6ea; }
  .sv-action-btn--primary { background: var(--accent); color: #fff; }
  .sv-action-btn--primary:hover { opacity: 0.88; }
  .sv-action-btn--blue    { background: #2563eb; color: #fff; }
  .sv-action-btn--blue:hover { opacity: 0.88; }
  .sv-action-btn--green   { background: #0e8a6f; color: #fff; }
  .sv-action-btn--green:hover { opacity: 0.88; }
  .sv-action-btn--icon    {
    padding: 6px 9px; font-size: 15px; line-height: 1;
    color: #5f6368; background: transparent;
  }
  .sv-action-btn--icon:hover { background: #f0f1f3; color: #15171a; }

  /* More menu (dropdown) */
  .sv-more-wrap { position: relative; display: inline-block; }
  .sv-more-menu {
    display: none; position: absolute; right: 0; top: calc(100% + 4px);
    background: #fff; border: 1px solid rgba(15,23,42,0.10);
    border-radius: 12px; box-shadow: 0 8px 24px rgba(15,23,42,0.14);
    min-width: 170px; z-index: 200; overflow: hidden;
  }
  .sv-more-menu.open { display: block; }
  .sv-more-item {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; font-size: 13px; font-weight: 500; color: #15171a;
    cursor: pointer; border: none; background: none; width: 100%; text-align: left;
    font-family: inherit; text-decoration: none;
    transition: background 0.1s;
  }
  .sv-more-item:hover { background: #f5f6f8; }
  .sv-more-item svg { opacity: 0.55; flex-shrink: 0; }
  .sv-more-item--danger { color: #c2492f; }
  .sv-more-item--danger:hover { background: #fff5f3; }
  .sv-more-divider { border: none; border-top: 1px solid rgba(15,23,42,0.07); margin: 3px 0; }

  /* Empty / loading / error states */
  .sv-table-state {
    padding: 40px 24px; text-align: center;
    color: #8a8f98; font-size: 14px;
  }
  .sv-table-state.is-error { color: #c2492f; }

  /* ── Deploy modal ── */
  .deploy-backdrop {
    display: none; position: fixed; inset: 0;
    background: rgba(15,23,42,0.45); backdrop-filter: blur(4px);
    z-index: 1000; align-items: center; justify-content: center;
  }
  .deploy-backdrop.open { display: flex; }
  .deploy-modal {
    background: #fff; border-radius: 20px;
    box-shadow: 0 20px 60px rgba(15,23,42,0.22);
    padding: 36px; max-width: 440px; width: 90%;
  }
  .deploy-modal h2 {
    font-family: Inter, -apple-system, sans-serif;
    font-size: 20px; font-weight: 700; letter-spacing: -0.02em;
    color: #15171a; margin: 0 0 10px;
  }
  .deploy-modal p {
    font-size: 14px; color: #5f6368; line-height: 1.6; margin: 0 0 20px;
  }
  .deploy-modal .survey-name-chip {
    display: inline-block; background: var(--accent-soft); color: var(--accent);
    border-radius: 8px; padding: 4px 12px; font-size: 13px; font-weight: 600;
    margin-bottom: 18px;
  }
  .deploy-modal .share-url-row {
    display: flex; align-items: center; gap: 8px;
    background: #f5f6f8; border-radius: 10px; padding: 10px 14px;
    margin-bottom: 24px;
  }
  .deploy-modal .share-url-row span {
    flex: 1; font-size: 12.5px; color: #5f6368;
    font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .deploy-modal .share-url-row button {
    flex-shrink: 0; padding: 4px 10px; font-size: 11.5px; font-weight: 700;
    border: none; border-radius: 6px; background: #e4e6ea; color: #5f6368;
    cursor: pointer; font-family: inherit; transition: background 0.12s;
  }
  .deploy-modal .share-url-row button:hover { background: #d4d7dd; }
  .deploy-modal .modal-actions {
    display: flex; gap: 10px; justify-content: flex-end;
  }
  .deploy-modal .btn-cancel {
    padding: 10px 20px; border-radius: 10px; border: none;
    background: #f0f1f3; color: #5f6368;
    font-family: inherit; font-size: 14px; font-weight: 600;
    cursor: pointer; transition: background 0.12s;
  }
  .deploy-modal .btn-cancel:hover { background: #e4e6ea; }
  .deploy-modal .btn-deploy {
    padding: 10px 24px; border-radius: 10px; border: none;
    background: var(--accent); color: #fff;
    font-family: inherit; font-size: 14px; font-weight: 700;
    cursor: pointer; transition: opacity 0.12s;
    display: flex; align-items: center; gap: 8px;
  }
  .deploy-modal .btn-deploy:hover { opacity: 0.88; }
  .deploy-modal .btn-deploy:disabled { opacity: 0.5; cursor: default; }

  /* ── Copy toast ── */
  .copy-toast {
    display: none; position: fixed; bottom: 28px; left: 50%;
    transform: translateX(-50%);
    background: #15171a; color: #fff;
    padding: 10px 20px; border-radius: 999px;
    font-size: 13px; font-weight: 600;
    z-index: 2000; pointer-events: none;
    animation: fadeUp 0.2s ease;
  }
  @keyframes fadeUp { from { opacity: 0; transform: translateX(-50%) translateY(8px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
</style>

<!-- ── Hero ── -->
<div class="projects-hero">
  <a class="projects-back" href="/studio-survey.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Survey Studio
  </a>
  <h1>My Surveys</h1>
  <p>All your surveys in one place. Deploy drafts to start collecting, or jump into analysis on live surveys.</p>
</div>

<!-- ── Toolbar ── -->
<div class="projects-bar">
  <button class="filter-pill active" data-filter="all">All</button>
  <button class="filter-pill" data-filter="draft">Draft</button>
  <button class="filter-pill" data-filter="live">Live</button>
  <button class="filter-pill" data-filter="collecting">Collecting</button>
  <button class="filter-pill" data-filter="archived">Archived</button>
  <span class="projects-bar-spacer"></span>
  <span class="projects-count" id="surveyCount"></span>
  <a class="btn-new-survey" href="/survey-builder.php">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New survey
  </a>
</div>

<!-- ── Table ── -->
<div class="sv-table-wrap">
  <table class="sv-table" id="svTable">
    <thead>
      <tr>
        <th>Survey</th>
        <th>Status</th>
        <th>Questions</th>
        <th>Responses</th>
        <th>Last updated</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody id="svTableBody">
      <tr><td colspan="6" class="sv-table-state">Loading your surveys…</td></tr>
    </tbody>
  </table>
</div>

<!-- ── Deploy modal ── -->
<div class="deploy-backdrop" id="deployBackdrop">
  <div class="deploy-modal">
    <h2>Deploy survey</h2>
    <div class="survey-name-chip" id="deployName"></div>
    <p>Deploying makes your survey live and shareable. Respondents can submit answers at the link below. You can unpublish it later from this page.</p>
    <div class="share-url-row">
      <span id="deployUrl"></span>
      <button type="button" onclick="copyDeployUrl()">Copy</button>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" type="button" onclick="closeDeployModal()">Cancel</button>
      <button class="btn-deploy" type="button" id="deployConfirmBtn" onclick="confirmDeploy()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
        Deploy now
      </button>
    </div>
  </div>
</div>

<!-- ── Copy toast ── -->
<div class="copy-toast" id="copyToast">Link copied!</div>

<script>
(function () {
  /* ─── State ─────────────────────────────────────────────── */
  var allSurveys  = [];
  var activeFilter = 'all';
  var pendingDeploy = null; // { id, slug }

  var tbody    = document.getElementById('svTableBody');
  var countEl  = document.getElementById('surveyCount');

  /* ─── Helpers ────────────────────────────────────────────── */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
    });
  }

  function statusOf(s) {
    if (s.archived_at)                  return 'archived';
    if (!s.is_published)                return 'draft';
    if ((s.response_count || 0) > 0)   return 'collecting';
    return 'live';
  }

  function badgeHtml(status) {
    var labels = { draft:'Draft', live:'Live', collecting:'Collecting', archived:'Archived' };
    return '<span class="sv-badge sv-badge--' + status + '"><span class="dot"></span>' + labels[status] + '</span>';
  }

  function shareUrl(slug) {
    return location.origin + '/s/' + slug;
  }

  function ico(path) {
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
  }

  /* ─── Row actions ─────────────────────────────────────────── */
  function actionsHtml(s, status) {
    var id   = s.id;
    var slug = esc(s.slug || '');
    var title = esc(s.title || 'Untitled survey');

    if (status === 'draft') {
      return '<div class="action-group">' +
        '<a class="sv-action-btn sv-action-btn--ghost" href="/survey-builder.php?id=' + id + '">' +
          ico('<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'+
              '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>') +
          ' Edit' +
        '</a>' +
        '<a class="sv-action-btn sv-action-btn--primary" href="/survey-deploy.php?id=' + id + '">' +
          ico('<path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/>') +
          ' Deploy' +
        '</a>' +
        moreMenu(s, status) +
      '</div>';
    }

    if (status === 'live') {
      return '<div class="action-group">' +
        '<a class="sv-action-btn sv-action-btn--blue" href="/project-snapshot.php?studio=survey&project_id=' + id + '">' +
          ico('<path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>') +
          ' Analyse' +
        '</a>' +
        '<button class="sv-action-btn sv-action-btn--ghost" onclick="copyLink(\'' + slug + '\')" title="Copy survey link">' +
          ico('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'+
              '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>') +
          ' Copy link' +
        '</button>' +
        moreMenu(s, status) +
      '</div>';
    }

    if (status === 'collecting') {
      return '<div class="action-group">' +
        '<a class="sv-action-btn sv-action-btn--green" href="/project-snapshot.php?studio=survey&project_id=' + id + '">' +
          ico('<path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>') +
          ' Analyse' +
        '</a>' +
        '<button class="sv-action-btn sv-action-btn--ghost" onclick="copyLink(\'' + slug + '\')" title="Copy survey link">' +
          ico('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'+
              '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>') +
          ' Copy link' +
        '</button>' +
        moreMenu(s, status) +
      '</div>';
    }

    // archived
    return '<div class="action-group">' +
      '<a class="sv-action-btn sv-action-btn--ghost" href="/project-snapshot.php?studio=survey&project_id=' + id + '">' +
        ico('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>'+
            '<circle cx="12" cy="12" r="3"/>') +
        ' View' +
      '</a>' +
      moreMenu(s, status) +
    '</div>';
  }

  function moreMenu(s, status) {
    var id    = s.id;
    var slug  = esc(s.slug || '');
    var title = esc(s.title || 'Untitled survey');
    var items = '';

    if (status === 'draft') {
      items +=
        '<a class="sv-more-item" href="/survey-builder.php?id=' + id + '">' +
          ico('<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'+
              '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>') +
          ' Edit in builder' +
        '</a>';
    }

    if (status === 'live' || status === 'collecting') {
      items +=
        '<a class="sv-more-item" href="' + shareUrl(s.slug || '') + '" target="_blank" rel="noopener">' +
          ico('<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'+
              '<polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>') +
          ' Open survey link' +
        '</a>' +
        '<button class="sv-more-item" onclick="unpublish(' + id + ')">' +
          ico('<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>'+
              '<line x1="1" y1="1" x2="23" y2="23"/>') +
          ' Unpublish' +
        '</button>' +
        '<hr class="sv-more-divider">';
    }

    if (status !== 'archived') {
      items +=
        '<button class="sv-more-item" onclick="archiveSurvey(' + id + ',\'' + title + '\')">' +
          ico('<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/>'+
              '<line x1="10" y1="12" x2="14" y2="12"/>') +
          ' Archive' +
        '</button>';
    }

    items +=
      '<button class="sv-more-item sv-more-item--danger" onclick="deleteSurvey(' + id + ',\'' + title + '\')">' +
        ico('<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>') +
        ' Delete' +
      '</button>';

    return '<div class="sv-more-wrap">' +
      '<button class="sv-action-btn sv-action-btn--icon" onclick="toggleMore(this)" title="More options">···</button>' +
      '<div class="sv-more-menu">' + items + '</div>' +
    '</div>';
  }

  /* ─── Render ─────────────────────────────────────────────── */
  function render(filter) {
    var list = allSurveys.filter(function (s) {
      if (filter === 'all') return true;
      return statusOf(s) === filter;
    });
    countEl.textContent = list.length + ' survey' + (list.length !== 1 ? 's' : '');

    if (!list.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="sv-table-state">' +
        (filter === 'all'
          ? 'No surveys yet. <a href="/survey-builder.php" style="color:var(--accent);font-weight:700;">Create your first one →</a>'
          : 'No ' + esc(filter) + ' surveys.') +
      '</td></tr>';
      return;
    }

    tbody.innerHTML = list.map(function (s) {
      var status  = statusOf(s);
      var updated = s.updated_at ? s.updated_at.slice(0, 10) : '—';
      var titleHref = status === 'draft'
        ? '/survey-builder.php?id=' + s.id
        : '/project-snapshot.php?studio=survey&project_id=' + s.id;

      return '<tr data-id="' + s.id + '" data-status="' + status + '">' +
        '<td class="sv-td-title">' +
          '<a href="' + titleHref + '">' + esc(s.title || 'Untitled survey') + '</a>' +
          (s.slug ? '<div class="sv-td-slug">/s/' + esc(s.slug) + '</div>' : '') +
        '</td>' +
        '<td>' + badgeHtml(status) + '</td>' +
        '<td class="sv-td-num">' + esc(String(s.item_count || 0)) + '</td>' +
        '<td class="sv-td-num">' + esc(String(s.response_count || 0)) + '</td>' +
        '<td class="sv-td-date">' + esc(updated) + '</td>' +
        '<td class="sv-td-actions">' + actionsHtml(s, status) + '</td>' +
      '</tr>';
    }).join('');
  }

  /* ─── Filter pills ───────────────────────────────────────── */
  document.querySelectorAll('.filter-pill').forEach(function (btn) {
    btn.addEventListener('click', function () {
      activeFilter = btn.dataset.filter;
      document.querySelectorAll('.filter-pill').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      render(activeFilter);
    });
  });

  /* ─── Close more menus on outside click ──────────────────── */
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.sv-more-wrap')) {
      document.querySelectorAll('.sv-more-menu.open').forEach(function (m) { m.classList.remove('open'); });
    }
  });

  /* ─── Fetch surveys ──────────────────────────────────────── */
  fetch('/api/surveys/list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (data) {
      if (!data || !Array.isArray(data.surveys)) throw new Error('Bad response');
      allSurveys = data.surveys;
      render(activeFilter);
    })
    .catch(function (err) {
      tbody.innerHTML = '<tr><td colspan="6" class="sv-table-state is-error">Could not load your surveys: ' + esc(String(err.message || err)) + '</td></tr>';
    });

  /* ─── More menu toggle ───────────────────────────────────── */
  window.toggleMore = function (btn) {
    var menu = btn.nextElementSibling;
    var wasOpen = menu.classList.contains('open');
    document.querySelectorAll('.sv-more-menu.open').forEach(function (m) { m.classList.remove('open'); });
    if (!wasOpen) menu.classList.add('open');
  };

  /* ─── Copy survey link ───────────────────────────────────── */
  window.copyLink = function (slug) {
    var url = shareUrl(slug);
    navigator.clipboard.writeText(url).then(function () { showToast('Link copied!'); }).catch(function () {
      prompt('Copy this link:', url);
    });
  };

  /* ─── Copy in deploy modal ───────────────────────────────── */
  window.copyDeployUrl = function () {
    var url = document.getElementById('deployUrl').textContent;
    navigator.clipboard.writeText(url).then(function () { showToast('Link copied!'); }).catch(function () { prompt('Copy:', url); });
  };

  /* ─── Toast ──────────────────────────────────────────────── */
  function showToast(msg) {
    var t = document.getElementById('copyToast');
    t.textContent = msg; t.style.display = 'block';
    setTimeout(function () { t.style.display = 'none'; }, 2000);
  }

  /* ─── Deploy modal ───────────────────────────────────────── */
  window.openDeployModal = function (id, slug, title) {
    pendingDeploy = { id: id, slug: slug };
    document.getElementById('deployName').textContent = title;
    document.getElementById('deployUrl').textContent  = shareUrl(slug);
    var btn = document.getElementById('deployConfirmBtn');
    btn.disabled = false; btn.innerHTML =
      ico('<path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/>') + ' Deploy now';
    document.getElementById('deployBackdrop').classList.add('open');
  };

  window.closeDeployModal = function () {
    document.getElementById('deployBackdrop').classList.remove('open');
    pendingDeploy = null;
  };

  document.getElementById('deployBackdrop').addEventListener('click', function (e) {
    if (e.target === this) closeDeployModal();
  });

  window.confirmDeploy = function () {
    if (!pendingDeploy) return;
    var btn = document.getElementById('deployConfirmBtn');
    btn.disabled = true; btn.textContent = 'Deploying…';

    fetch('/api/surveys/update.php', {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ id: pendingDeploy.id, is_published: true }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.survey) throw new Error('Deploy failed');
        // Update local state
        var idx = allSurveys.findIndex(function (s) { return s.id === pendingDeploy.id; });
        if (idx >= 0) allSurveys[idx].is_published = true;
        closeDeployModal();
        render(activeFilter);
        showToast('Survey is live!');
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = 'Retry deploy';
        alert('Deploy failed: ' + (err.message || err));
      });
  };

  /* ─── Unpublish ──────────────────────────────────────────── */
  window.unpublish = function (id) {
    if (!confirm('Unpublish this survey? The link will stop accepting responses.')) return;
    fetch('/api/surveys/update.php', {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ id: id, is_published: false }),
    })
      .then(function (r) { return r.json(); })
      .then(function () {
        var idx = allSurveys.findIndex(function (s) { return s.id === id; });
        if (idx >= 0) allSurveys[idx].is_published = false;
        render(activeFilter); showToast('Survey unpublished.');
      })
      .catch(function (err) { alert('Could not unpublish: ' + err.message); });
  };

  /* ─── Archive ────────────────────────────────────────────── */
  window.archiveSurvey = function (id, title) {
    if (!confirm('Archive "' + title + '"? You can still view its data.')) return;
    fetch('/api/surveys/archive.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ id: id }),
    })
      .then(function (r) { return r.json(); })
      .then(function () {
        var idx = allSurveys.findIndex(function (s) { return s.id === id; });
        if (idx >= 0) allSurveys[idx].archived_at = new Date().toISOString();
        render(activeFilter); showToast('Survey archived.');
      })
      .catch(function (err) { alert('Could not archive: ' + err.message); });
  };

  /* ─── Delete ─────────────────────────────────────────────── */
  window.deleteSurvey = function (id, title) {
    if (!confirm('Permanently delete "' + title + '"? This cannot be undone.')) return;
    fetch('/api/surveys/delete.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ id: id }),
    })
      .then(function (r) { return r.json(); })
      .then(function () {
        allSurveys = allSurveys.filter(function (s) { return s.id !== id; });
        render(activeFilter); showToast('Survey deleted.');
      })
      .catch(function (err) { alert('Could not delete: ' + err.message); });
  };

  /* ─── Share URL helper ───────────────────────────────────── */
  function shareUrl(slug) {
    return location.origin + '/s/' + slug;
  }

  function ico(path) {
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
  }

})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
