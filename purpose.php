<?php
// Purpose & Research Question — Overview lens.
// Renders the template-level two-card workspace (rc-workspace-2col).
// Per [[relicheck-template-only-layout]]: no inline <style> or <script>.
// All layout chrome lives in studio-template.css; data is passed via vars.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

$mount_app            = 'overview';
$mount_lens           = 'project_snapshot';
$mount_section        = 'overview';
$mount_item           = 'purpose';
$mount_breadcrumb     = ['Overview', 'Purpose & Research Question'];
$mount_title          = 'Purpose & Research Question';
$mount_intro          = "The why behind the study. A clear purpose and a sharp research question shape every analysis downstream. Edit here any time.";
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';

// ---------- Build workspace card content from project record ----------
// Pull title + description from the studio's project table. The mount
// partial validates ownership and exposes $mount_project, but we need
// the description column too (which the partial doesn't fetch).
start_session_secure();
$uid = current_user_id();
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$studio_slug = $_GET['studio'] ?? 'survey';

$project_title = '';
$project_desc  = '';
if ($uid && $projectId > 0) {
  $pdo = db();
  try {
    if ($studio_slug === 'survey') {
      $stmt = $pdo->prepare('SELECT title, description AS notes FROM surveys WHERE id = :id AND owner_id = :uid');
    } elseif ($studio_slug === 'mm') {
      $stmt = $pdo->prepare('SELECT title, notes FROM mm_projects WHERE id = :id AND user_id = :uid');
    } elseif ($studio_slug === '360') {
      $stmt = $pdo->prepare('SELECT name AS title, "" AS notes FROM survey_360_panels WHERE id = :id AND user_id = :uid');
    } else { // tia
      $stmt = $pdo->prepare('SELECT title, notes FROM tia_projects WHERE id = :id AND user_id = :uid');
    }
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $project_title = (string)($row['title'] ?? '');
      $project_desc  = (string)($row['notes'] ?? '');
    }
  } catch (Throwable $e) { /* table may not exist (tia) — leave empty */ }
}

$edit_href = '/' . htmlspecialchars($studio_slug) . '-wizard.php?step=1&project_id=' . (int)$projectId;

if ($project_title !== '' || $project_desc !== '') {
  $mount_workspace = [
    'main' =>
      '<h3>Study definition</h3>'
      . '<div class="lbl">Title</div>'
      . '<div class="val' . ($project_title !== '' ? '' : ' is-empty') . '">'
        . htmlspecialchars($project_title !== '' ? $project_title : 'No title set')
      . '</div>'
      . '<div class="lbl">Description</div>'
      . '<div class="val' . ($project_desc !== '' ? '' : ' is-empty') . '">'
        . htmlspecialchars($project_desc !== '' ? $project_desc : 'No description yet. Open the wizard to add one.')
      . '</div>'
      . '<a class="rc-workspace-edit-link" href="' . $edit_href . '">Edit in wizard →</a>',
    'side' =>
      '<h3>A strong research question</h3>'
      . '<ul>'
        . '<li><em>Specific</em> — names the population, the construct, and the comparison.</li>'
        . '<li><em>Answerable</em> — the data you have (or are collecting) can actually answer it.</li>'
        . '<li><em>Actionable</em> — the answer informs a real decision.</li>'
        . '<li><em>Falsifiable</em> — there is a result that would change your mind.</li>'
      . '</ul>',
  ];
} else {
  $mount_workspace_empty =
    '<h3>No purpose set yet</h3>'
    . '<p>Open the wizard to add a study title, description, and research question. They drive every downstream analysis.</p>'
    . '<a class="rc-workspace-edit-link" href="' . $edit_href . '">Open the wizard →</a>';
}

include __DIR__ . '/_studio_mount.php';
