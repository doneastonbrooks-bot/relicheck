<?php
// Qualitative Analysis Studio — workspace
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_qual_studio.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/qual-studio-workspace.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();
qual_ensure_schema($pdo);

$projectId    = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectTitle = '';
$projectRow   = null;

if ($projectId > 0) {
    try {
        $s = $pdo->prepare(
            "SELECT * FROM qual_projects WHERE id=:id AND user_id=:u AND status<>'archived' LIMIT 1"
        );
        $s->execute([':id' => $projectId, ':u' => $uid]);
        $projectRow = $s->fetch(PDO::FETCH_ASSOC);
        if ($projectRow) $projectTitle = $projectRow['title'];
        else $projectId = 0;
    } catch (Throwable $e) { $projectId = 0; }
}

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$_qs_css_v = is_file(__DIR__ . '/apps/qual/qual-studio.css') ? filemtime(__DIR__ . '/apps/qual/qual-studio.css') : time();
$_qs_js_v  = is_file(__DIR__ . '/apps/qual/qual-studio.js')  ? filemtime(__DIR__ . '/apps/qual/qual-studio.js')  : time();
$_au_js_v  = is_file(__DIR__ . '/apps/studio/dataset-upload.js') ? filemtime(__DIR__ . '/apps/studio/dataset-upload.js') : time();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= $projectTitle ? htmlspecialchars($projectTitle) . ' — ' : '' ?>Qualitative Analysis Studio — ReliCheck</title>
  <link rel="icon" href="/logo-brand.svg">
  <link rel="stylesheet" href="/apps/qual/qual-studio.css?v=<?= $_qs_css_v ?>">
</head>
<body>

<!-- Top bar -->
<header class="qs-topbar">
  <a class="qs-brand" href="/qual-studio.php">
    <img src="/logo-brand.svg" alt="ReliCheck" class="qs-brand-logo">
    <span class="qs-brand-sep"></span>
    <span class="qs-brand-name">Qualitative Analysis Studio</span>
  </a>
  <div class="qs-topbar-center" id="projectContext">
    <?php if ($projectTitle): ?>
      <span class="qs-proj-ctx"><?= htmlspecialchars($projectTitle) ?></span>
    <?php endif; ?>
  </div>
  <div class="qs-topbar-right">
    <a href="/qual-studio.php" class="qs-back-link">All projects</a>
    <span class="qs-avatar" title="<?= htmlspecialchars($user_full) ?>"><?= htmlspecialchars($initials) ?></span>
  </div>
</header>

<!-- 3-panel body -->
<div class="qs-body">

  <!-- Left rail: module navigation -->
  <nav class="qs-rail" id="moduleRail">
    <div class="qs-rail-h">Workflow</div>
    <?php
    $modules = [
      ['id'=>'setup',      'label'=>'Project Setup',    'icon'=>'gear',   'phase'=>1],
      ['id'=>'import',     'label'=>'Data Import',      'icon'=>'upload', 'phase'=>1],
      ['id'=>'familiarize','label'=>'Familiarization',  'icon'=>'eye',    'phase'=>1],
      ['id'=>'coding',     'label'=>'Coding Workspace', 'icon'=>'tag',    'phase'=>1],
      ['id'=>'codebook',   'label'=>'Codebook Builder', 'icon'=>'book',   'phase'=>1],
      ['id'=>'categories', 'label'=>'Category Builder', 'icon'=>'layers', 'phase'=>2, 'soon'=>true],
      ['id'=>'themes',     'label'=>'Theme Builder',    'icon'=>'bulb',   'phase'=>2, 'soon'=>true],
      ['id'=>'quotes',     'label'=>'Quote Finder',     'icon'=>'quote',  'phase'=>2, 'soon'=>true],
      ['id'=>'trust',      'label'=>'Trustworthiness',  'icon'=>'shield', 'phase'=>2, 'soon'=>true],
      ['id'=>'audit',      'label'=>'Audit Trail',      'icon'=>'clock',  'phase'=>2, 'soon'=>true],
      ['id'=>'report',     'label'=>'Report Builder',   'icon'=>'doc',    'phase'=>2, 'soon'=>true],
    ];
    $icons = [
      'gear'   => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
      'upload' => '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>',
      'eye'    => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
      'tag'    => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
      'book'   => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
      'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
      'bulb'   => '<line x1="9" y1="18" x2="15" y2="18"/><line x1="10" y1="22" x2="14" y2="22"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
      'quote'  => '<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/>',
      'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
      'clock'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
      'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
    ];
    foreach ($modules as $m):
      $soon = !empty($m['soon']);
    ?>
    <button class="qs-step<?= $soon ? ' soon' : '' ?>"
            data-module="<?= $m['id'] ?>"
            <?= $soon ? 'disabled title="Coming in a future phase"' : '' ?>
            onclick="App.go('<?= $m['id'] ?>')">
      <svg class="qs-step-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <?= $icons[$m['icon']] ?>
      </svg>
      <span class="qs-step-label"><?= $m['label'] ?></span>
      <?php if ($soon): ?><span class="qs-soon-chip">Soon</span><?php endif; ?>
    </button>
    <?php endforeach; ?>
  </nav>

  <!-- Center: active module workspace -->
  <main class="qs-main" id="qsMain">
    <div class="qs-loading" id="qsLoading">
      <div class="qs-spinner"></div>
      <span>Loading...</span>
    </div>
    <div id="qsContent" style="display:none;"></div>
  </main>

  <!-- Right: guidance panel -->
  <aside class="qs-guide" id="qsGuide">
    <div class="qs-guide-inner" id="qsGuideInner">
      <div class="qs-guide-h">ReliCheck Intelligence</div>
      <div class="qs-guide-body" id="qsGuideBody">
        <p>Select a module from the left rail to begin.</p>
      </div>
    </div>
  </aside>

</div><!-- .qs-body -->

<!-- Boot data for JS -->
<script>
const BOOT = {
  projectId: <?= $projectId ?>,
  project:   <?= $projectRow ? json_encode($projectRow) : 'null' ?>,
  uid:       <?= $uid ?>,
  user:      <?= json_encode(['full' => $user_full, 'initials' => $initials]) ?>,
};
</script>
<script src="/apps/studio/dataset-upload.js?v=<?= $_au_js_v ?>"></script>
<script src="/apps/qual/qual-studio.js?v=<?= $_qs_js_v ?>"></script>

</body>
</html>
