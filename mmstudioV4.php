<?php
// ============================================================
// MM Studio · DESIGN-LED PIPELINE shell (Phase 1)
// Runs ALONGSIDE the existing studio-mm.php → project-snapshot flow.
// Nothing here touches engines or statistics. Workstations are
// placeholders in this phase; Phase 3 swaps them for the existing
// tested engines via the established ?embed=1 iframe mount.
//
//   - Auth: the standard session pattern used across the app.
//   - Project: loaded read-only via mm_require_project() (api/_mm.php).
//   - Design: A–E taxonomy from api/mm/design-recommendations.php,
//     persisted on mm_projects.design_choice via api/mm/wizard.php.
//
// Deep link: /mmstudioV4.php?project_id=N[&design=SLUG]
// ============================================================

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_mm.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/mmstudioV4.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// Design→pipeline mapping (server-authoritative). The valid slugs and the
// default design come straight from the config, so the page and the config
// can never drift apart.
$MM = require __DIR__ . '/_mm_pipelines.php';
$VALID_DESIGNS = $MM['order'];

// ---------- Load project (optional; 0 = demo walkthrough) ----------
$projectId    = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectTitle = '';
$projectDesc  = '';
$storedDesign = '';
$scores = ['siri' => null, 'rssi' => null, 'rssiBand' => '', 'rssiWithheld' => false];
if ($projectId > 0) {
  try {
    $pdo = db();
    $proj = mm_require_project($pdo, (int)$uid, $projectId); // throws if not owned
    $projectTitle = (string)($proj['title'] ?? '');
    $projectDesc  = (string)($proj['notes'] ?? '');
    $storedDesign = (string)($proj['design_choice'] ?? '');

    // SIRI / RSSI scores for the linked survey (if any), surfaced in the Overview.
    $surveyId = (int)($proj['survey_id'] ?? 0);
    if ($surveyId > 0) {
      try {
        $sr = $pdo->prepare('SELECT total FROM siri_reviews WHERE project_id = :id LIMIT 1');
        $sr->execute([':id' => $surveyId]); $srow = $sr->fetch(PDO::FETCH_ASSOC);
        if ($srow && $srow['total'] !== null) $scores['siri'] = (float)$srow['total'];
      } catch (Throwable $e) {}
      try {
        $rr = $pdo->prepare('SELECT total, band, withheld FROM rssi_reviews WHERE project_id = :id LIMIT 1');
        $rr->execute([':id' => $surveyId]); $rrow = $rr->fetch(PDO::FETCH_ASSOC);
        if ($rrow) {
          $scores['rssiWithheld'] = !empty($rrow['withheld']);
          $scores['rssi']     = (!empty($rrow['withheld']) || $rrow['total'] === null) ? null : (float)$rrow['total'];
          $scores['rssiBand'] = (string)($rrow['band'] ?? '');
        }
      } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {
    $projectId = 0; // not owned / not found → fall back to demo
  }
}

// ---------- Load the framing (the questions answered in setup) for the Overview ----------
$framing = ['data_kinds' => [], 'intent_purposes' => [], 'chosen_design' => ''];
if ($projectId > 0) {
  try {
    $fst = $pdo->prepare('SELECT data_kinds, purposes AS intent_purposes, design_choice AS chosen_design FROM mm_projects WHERE id = :p LIMIT 1');
    $fst->execute([':p' => $projectId]);
    $fr = $fst->fetch(PDO::FETCH_ASSOC);
    if ($fr) {
      $framing['data_kinds']      = json_decode((string)($fr['data_kinds'] ?? '[]'), true) ?: [];
      $framing['intent_purposes'] = json_decode((string)($fr['intent_purposes'] ?? '[]'), true) ?: [];
      $framing['chosen_design']   = (string)($fr['chosen_design'] ?? '');
    }
  } catch (Throwable $e) { /* framing optional; Overview degrades gracefully */ }
}

// ---------- Response count for the Overview "Your data" card ----------
$respCount = 0;
if ($projectId > 0) { try { $respCount = mm_response_count(db(), $projectId); } catch (Throwable $e) {} }

// ---------- Dataset variables for the t-test / quantitative setup pickers ----------
// The RAW uploaded data lives in the main `datasets` table (column_meta + data),
// linked via mm_projects.dataset_id. (mm_generated_variables is the QUAL-derived
// theme dataset and does NOT contain the raw survey columns.) Column types in
// column_meta are unreliable, so numeric vs categorical is detected from values.
$ttVars = ['datasetReady' => false, 'outcomes' => [], 'groupings' => []];
$rawInfo = ['linked' => false, 'rows' => 0, 'cols' => 0, 'dataset_id' => 0];
if ($projectId > 0) {
  try {
    $pv = db();
    // Resolve the raw datasets.id: mm_projects.dataset_id first, then fall back to
    // the latest mm_data_sources.source_ref (in case the upload recorded the source
    // but did not set dataset_id). Both should point at a `datasets` row.
    $dq = $pv->prepare('SELECT dataset_id FROM mm_projects WHERE id = :p AND user_id = :u');
    $dq->execute([':p' => $projectId, ':u' => $uid]); $datasetId = (int)($dq->fetchColumn() ?: 0);
    if ($datasetId <= 0) {
      try {
        $sq = $pv->prepare("SELECT source_ref FROM mm_data_sources WHERE project_id = :p ORDER BY id DESC LIMIT 1");
        $sq->execute([':p' => $projectId]); $datasetId = (int)($sq->fetchColumn() ?: 0);
      } catch (Throwable $e) {}
    }
    if ($datasetId > 0) {
      $rawInfo['dataset_id'] = $datasetId;
      $drq = $pv->prepare('SELECT column_meta, data FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
      $drq->execute([':d' => $datasetId, ':u' => $uid]); $drow = $drq->fetch(PDO::FETCH_ASSOC);
      if ($drow) {
        $cm   = json_decode((string)$drow['column_meta'], true) ?: [];
        $data = json_decode((string)$drow['data'], true) ?: [];
        if ($cm && $data) {
          $ttVars['datasetReady'] = true;
          $rawInfo['linked'] = true; $rawInfo['rows'] = count($data); $rawInfo['cols'] = count($cm);
          foreach ($cm as $i => $c) {
            if (!is_array($c)) continue;
            $name      = (string)($c['name'] ?? ('col_' . $i));
            $savedType = (string)($c['type'] ?? '');
            // A column explicitly saved as a string-categorical type in the Data Map
            // overrides numeric detection for the groupings list (Binary / Dichotomous
            // → 'single'). 'demographic' is NOT included here because numeric demographics
            // (e.g. YearsExperience) must remain visible as correlation/regression outcomes.
            $isSavedCat = in_array($savedType, ['single', 'multi'], true);
            $nonEmpty = 0; $numCount = 0; $distinct = [];
            foreach ($data as $row) {
              if (!is_array($row) || !array_key_exists($i, $row)) continue;
              $v = trim((string)$row[$i]); if ($v === '') continue;
              $nonEmpty++; if (is_numeric($v)) $numCount++;
              $distinct[$v] = ($distinct[$v] ?? 0) + 1;
            }
            if ($nonEmpty < 2) continue;
            $nDistinct  = count($distinct);
            $isNumeric  = $numCount >= 0.8 * $nonEmpty;
            $isId       = ($nonEmpty > 20 && $nDistinct > 0.9 * $nonEmpty);
            if ($isId && !$isSavedCat) continue;
            $avgValLen  = $nDistinct ? array_sum(array_map('strlen', array_keys($distinct))) / $nDistinct : 0;
            $isVerbatim = ($avgValLen > 40);
            if ($isNumeric && !$isSavedCat && $nDistinct >= 3) {
              // Priority 0 = explicitly assigned as Quantitative outcome in Data Map (floats first).
              // Priority 1 = detected as numeric but not explicitly assigned.
              $outPriority = ($savedType === 'numeric' || $savedType === 'criterion') ? 0 : 1;
              $ttVars['outcomes'][] = ['id' => $i, 'name' => $name, 'priority' => $outPriority];
            } elseif (($isSavedCat || !$isNumeric) && !$isVerbatim && $nDistinct >= 2 && $nDistinct <= 30) {
              // Categorical grouping: either explicitly saved as categorical in
              // Data Map, or detected as non-numeric with 2–30 distinct values.
              arsort($distinct); $groups = [];
              foreach ($distinct as $val => $cnt) { $groups[] = ['value' => (string)$val, 'n' => (int)$cnt]; if (count($groups) >= 30) break; }
              $ttVars['groupings'][] = ['id' => $i, 'name' => $name, 'groups' => $groups];
            } elseif ($isNumeric && !$isSavedCat && $nDistinct === 2) {
              // Binary numeric variable auto-detected (0/1, Yes/No, etc.).
              // Categorical by function — route to groupings, not outcomes.
              arsort($distinct); $groups = [];
              foreach ($distinct as $val => $cnt) { $groups[] = ['value' => (string)$val, 'n' => (int)$cnt]; }
              $ttVars['groupings'][] = ['id' => $i, 'name' => $name, 'groups' => $groups];
            }
          }
        }
      }
    }
    // Sort outcomes: explicitly assigned Quantitative outcomes (priority 0) first.
    usort($ttVars['outcomes'], fn($a, $b) => ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1));
  } catch (Throwable $e) { /* pickers degrade to empty */ }
}

// ---------- The user's MM projects (for the Start project dropdown) ----------
$projects = [];
try {
  $pdoP = db();
  $pst = $pdoP->prepare('SELECT id, title FROM mm_projects WHERE user_id = :uid AND status <> "archived" ORDER BY id DESC LIMIT 100');
  $pst->execute([':uid' => $uid]);
  foreach (($pst->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $projects[] = ['id' => (int)$row['id'], 'title' => (string)$row['title']];
  }
} catch (Throwable $e) { /* dropdown degrades to none */ }

// ---------- Resolve the active CORE design ----------
// The studio works in the 3 core designs; the stored value may be a core slug or
// one of the backend's A–E recommendation slugs — map A–E → core.
$storedCore = in_array($storedDesign, $VALID_DESIGNS, true)
            ? $storedDesign
            : ($MM['ae_to_core'][$storedDesign] ?? '');
$qDesign = isset($_GET['design']) ? (string)$_GET['design'] : '';
$activeDesign = in_array($qDesign, $VALID_DESIGNS, true) ? $qDesign
              : ($storedCore !== '' ? $storedCore : $MM['default']);

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$projLabel = $projectTitle !== '' ? $projectTitle : 'Demo walkthrough (no project)';

// A real project with no design chosen yet → prompt "Help me choose" on open
// (lightweight design-at-first-open; does not touch the live creation wizard).
$needsDesign = $projectId > 0 && $storedCore === '';

// Expose boot config to JS safely.
// Value labels (raw code -> human label) per grouping variable, so the studio
// shows "No Training" instead of "SELTraining=0" everywhere. Degrades to empty
// if the mm_value_labels migration has not been run yet.
$valueLabels = new stdClass();
if ($projectId > 0) {
  try {
    $vlStmt = db()->prepare('SELECT var_name, value_key, label FROM mm_value_labels WHERE project_id = :p');
    $vlStmt->execute([':p' => $projectId]);
    $vlMap = [];
    foreach ($vlStmt->fetchAll(PDO::FETCH_ASSOC) as $vlr) {
      $vlMap[(string)$vlr['var_name']][(string)$vlr['value_key']] = (string)$vlr['label'];
    }
    if ($vlMap) $valueLabels = $vlMap;
  } catch (Throwable $e) { /* table not migrated yet -> labels stay empty */ }
}

$BOOT = [
  'projectId'    => $projectId,
  'projectLabel' => $projLabel,
  'design'       => $activeDesign,
  'canPersist'   => $projectId > 0,
  'needsDesign'  => $needsDesign,
  'framing'      => $framing,
  'projects'     => $projects,
  'responses'    => $respCount,
  'projDesc'     => $projectDesc,
  'scores'       => $scores,
  'surveyId'     => $surveyId,
  'ttvars'       => $ttVars,
  'rawinfo'      => $rawInfo,
  'valueLabels'  => $valueLabels,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mixed Methods Studio · <?= htmlspecialchars($projLabel) ?> — ReliCheck</title>
<style>
/* =====================================================
   MM Studio 2026 — Approved prototype design
   ===================================================== */
:root{
  /* Primary accent — indigo per approved prototype */
  --indigo:#5552f6; --indigo-dark:#3f3cc8; --indigo-light:#ededfe;
  /* Mapped aliases kept for existing component code */
  --btn:var(--indigo); --btn-hover:var(--indigo-dark); --acc:var(--indigo); --acc-soft:var(--indigo-light);
  --accent:#6b7280; --accent-hover:#4b5563; --accent-soft:#f3f4f6; --accent-ink:#374151;
  /* Text */
  --text:#1c1c1e; --text-2:#636366; --text-3:#aeaeb2;
  --ink:var(--text); --ink-2:var(--text-2); --ink-3:var(--text-3);
  /* Surfaces */
  --surface:#ffffff; --bg:#f5f5f7; --border:rgba(0,0,0,0.08);
  --panel:var(--surface); --line:rgba(0,0,0,0.08); --line-2:rgba(0,0,0,0.05);
  /* Shadows */
  --shadow-sm:0 1px 3px rgba(0,0,0,0.06),0 4px 12px rgba(0,0,0,0.05);
  --shadow-md:0 2px 8px rgba(0,0,0,0.06),0 12px 32px rgba(0,0,0,0.07);
  --shadow:var(--shadow-sm);
  /* Strand colors */
  --green:#34c759; --green-soft:#e8f9ee;
  --mm:var(--green); --mm-soft:var(--green-soft); --mm-ink:#1a7a3a;
  --quan:#0A6FE8; --quan-soft:#EEF3FA; --quan-ink:#085fcc;
  --qual:#8A4FD0; --qual-soft:#F2EAFB; --qual-ink:#6d36b0;
  /* Font */
  --font:-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;
  --font-display:-apple-system,BlinkMacSystemFont,"SF Pro Display","Helvetica Neue",sans-serif;
  /* Layout */
  --sidebar-w:300px; --topbar-h:76px; --companion:308px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);font-size:13px;color:var(--text);background:var(--bg);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;line-height:1.5}
h1,h2,h3,h4{margin:0;font-weight:700;letter-spacing:-.01em}
button{font-family:inherit;cursor:pointer}

/* ── App shell ── */
.app{display:grid;grid-template-rows:auto var(--topbar-h) 1fr auto;grid-template-columns:var(--sidebar-w) 1fr;height:100vh}
#studioHeader{grid-column:1/-1;grid-row:1}
#studioFooter{grid-column:1/-1;grid-row:4}
/* ── Studio topbar (row 2, full width) ── */
.topbar{grid-column:1/-1;grid-row:2;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;padding:0 20px;background:var(--surface);border-bottom:1px solid var(--border);height:var(--topbar-h);position:relative;z-index:10;}
.topbar-logo{font-family:var(--font-display);font-size:15px;font-weight:600;letter-spacing:-.3px;color:var(--text);justify-self:start;}
.topbar-steps{display:flex;align-items:center;gap:0;}
.topbar-right{display:flex;align-items:center;justify-content:flex-end;gap:10px;}
.topbar-project{font-size:12px;color:var(--text-3);font-weight:400;text-align:right;padding-left:12px;border-left:1px solid var(--border);line-height:1.5;}
.topbar-project strong{color:var(--text-2);font-weight:600;display:block;font-size:13px;}
/* Topbar step rail */
.tb-step{display:flex;align-items:center;cursor:pointer;}
.tb-node{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-size:11px;font-weight:600;background:var(--bg);color:var(--text-3);border:1.5px solid var(--border);transition:all .15s ease;position:relative;flex-shrink:0;}
.tb-step:hover .tb-node{border-color:var(--text-3);color:var(--text-2);}
.tb-step.done .tb-node{background:var(--green-soft);color:var(--green);border-color:transparent;}
.tb-step.active .tb-node{background:var(--indigo);color:#fff;border-color:transparent;box-shadow:0 1px 6px rgba(85,82,246,.4);width:28px;height:28px;}
.tb-node-label{position:absolute;top:calc(100% + 5px);left:50%;transform:translateX(-50%);font-size:10px;font-weight:600;color:var(--indigo);white-space:nowrap;letter-spacing:-.1px;pointer-events:none;}
.tb-connector{width:36px;height:1.5px;background:var(--border);flex-shrink:0;transition:background .15s;}
.tb-connector.done{background:var(--green);opacity:.4;}
/* Topbar action buttons */
.tb-act{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:500;padding:7px 14px;border-radius:999px;cursor:pointer;white-space:nowrap;transition:all .13s;}
.tb-act svg{width:13px;height:13px;flex-shrink:0;}
.tb-act-save{background:transparent;border:1px solid var(--border);color:var(--text-2);}
.tb-act-save:hover{background:var(--bg);color:var(--text);}
.tb-act-save.saved{color:var(--green);border-color:rgba(52,199,89,.3);background:var(--green-soft);}
.tb-act-rpt{background:var(--indigo);border:1px solid transparent;color:#fff;box-shadow:0 1px 4px rgba(85,82,246,.3);}
.tb-act-rpt:hover{background:var(--indigo-dark);}
.rpt-count-badge{font-size:10px;font-weight:700;background:rgba(255,255,255,.25);padding:1px 6px;border-radius:999px;margin-left:2px;display:none;}
/* ── Sidebar (row 3, col 1) ── */
.sidebar{grid-row:3;grid-column:1;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
.sidebar-header{padding:20px 18px;flex:1;overflow-y:auto;}
.sidebar-project-name{font-size:11px;font-weight:600;color:var(--text-3);letter-spacing:.02em;text-transform:uppercase;margin-bottom:10px;}
.sidebar-divider{height:1px;background:var(--border);margin:18px 0;}
.sidebar-design-desc{font-size:12.5px;color:var(--text-2);line-height:1.6;margin-top:12px;}
/* Design switcher */
.design-switcher{display:flex;gap:3px;background:var(--bg);border-radius:8px;padding:3px;}
.ds-btn{flex:1;border:none;background:transparent;font-family:inherit;font-size:11px;font-weight:500;color:var(--text-2);padding:4px 6px;border-radius:6px;cursor:pointer;transition:all .15s ease;white-space:nowrap;letter-spacing:-.1px;}
.ds-btn:hover:not(.active){color:var(--text);background:rgba(0,0,0,.04);}
.ds-btn.active{background:var(--indigo);color:#fff;font-weight:600;box-shadow:0 1px 3px rgba(85,82,246,.35);}
/* Notes block */
.notes-block{background:var(--surface);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-sm);border:1px solid var(--border);}
.notes-block-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px 8px;border-bottom:1px solid var(--border);}
.notes-block-label{display:flex;align-items:center;gap:6px;font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text-3);}
.notes-block-label svg{width:12px;height:12px;}
.notes-step-tag{font-size:10.5px;font-weight:500;color:var(--text-3);}
.notes-wrap{position:relative;border-left:2.5px solid transparent;transition:border-color .15s;}
.notes-wrap:focus-within{border-left-color:var(--indigo);}
.sidebar-notes{width:100%;min-height:148px;font-family:var(--font);font-size:13px;line-height:1.7;color:var(--text);background:transparent;border:none;padding:12px 14px;resize:none;outline:none;}
.sidebar-notes::placeholder{color:var(--text-3);font-style:italic;}
.notes-footer{display:flex;align-items:center;justify-content:space-between;padding:7px 12px 9px;border-top:1px solid var(--border);min-height:34px;}
.notes-saved,.nb-saved{font-size:10.5px;font-weight:500;color:var(--text-3);opacity:0;transition:opacity .3s;}
.notes-saved.vis,.notes-saved.visible,.nb-saved.vis,.nb-saved.visible{opacity:1;}
.btn-note-to-report,.btn-note-rpt{display:inline-flex;align-items:center;gap:5px;font-family:inherit;font-size:11px;font-weight:600;color:var(--indigo);background:transparent;border:none;cursor:pointer;padding:3px 8px;border-radius:999px;opacity:0;pointer-events:none;transition:all .12s;}
.btn-note-to-report.vis,.btn-note-to-report.visible,.btn-note-rpt.vis,.btn-note-rpt.visible{opacity:1;pointer-events:auto;}
.btn-note-to-report:hover,.btn-note-rpt:hover{background:var(--indigo-light);}
/* ── Main content (row 3, col 2) ── */
.main,.center{grid-row:3;grid-column:2;background:var(--bg);overflow-y:auto;padding:40px 32px 80px;}
.content-wrap,.center-inner{max-width:960px;margin:0 auto;}
/* ── Eyebrow ── */
.eyebrow{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--indigo);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.eyebrow-dot{width:5px;height:5px;border-radius:50%;background:var(--indigo);opacity:.5;flex-shrink:0;}
.eyebrow .mode-chip{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.04em;background:var(--text);color:#fff;}
.eyebrow .mode-chip.output{background:var(--quan);} .eyebrow .mode-chip.work{background:var(--indigo);} .eyebrow .mode-chip.setup{background:var(--text-3);}
/* ── Page heading ── */
.page-title,.title,.ov-title{font-family:var(--font-display);font-size:26px;font-weight:700;letter-spacing:-.5px;color:var(--text);margin-bottom:8px;line-height:1.15;}
.page-desc,.lede{font-size:14px;line-height:1.6;color:var(--text-2);max-width:560px;margin-bottom:28px;}
/* ── Card / Panel ── */
.card{background:var(--surface);border-radius:16px;box-shadow:var(--shadow-sm);padding:24px;margin-bottom:20px;}
.card-title{font-size:14px;font-weight:600;color:var(--text);letter-spacing:-.15px;margin-bottom:18px;}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-sm);margin-bottom:20px;overflow:hidden;}
.panel-h{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);}
.panel-h h3{font-size:15px;font-weight:600;color:var(--text);} .panel-h .ph-sub{font-size:12px;color:var(--text-3);margin-top:2px;}
.panel-b{padding:20px;} .panel-b.engine{padding:0;}
.ws-frame{width:100%;min-height:640px;border:0;display:block;background:var(--bg);}
/* ── Form elements ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}
.form-field label,.field label{display:block;font-size:11px;font-weight:600;color:var(--text-3);letter-spacing:.04em;text-transform:uppercase;margin-bottom:7px;}
.form-select{width:100%;font-family:inherit;font-size:13.5px;color:var(--text);background:var(--bg);border:1px solid rgba(0,0,0,.1);border-radius:10px;padding:9px 34px 9px 12px;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23aeaeb2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");background-repeat:no-repeat;background-position:right 11px center;cursor:pointer;transition:border-color .15s;}
.form-select:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(85,82,246,.1);}
/* ── Buttons ── */
.btn-row,.run-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn-primary{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(85,82,246,.3);}
.btn-primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(85,82,246,.4);transform:translateY(-1px);}
.btn-primary:active{transform:translateY(0);} .btn-primary svg{width:14px;height:14px;opacity:.85;}
.btn-secondary{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn-secondary:hover{color:var(--text);background:rgba(0,0,0,.03);}
.btn{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn:hover{color:var(--text);background:rgba(0,0,0,.03);border-color:rgba(0,0,0,.14);}
.btn.primary{background:var(--indigo);border-color:transparent;color:#fff;box-shadow:0 1px 4px rgba(85,82,246,.3);}
.btn.primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(85,82,246,.4);}
/* ── Step nav ── */
.footer-nav,.step-nav{display:flex;align-items:center;justify-content:space-between;padding-top:24px;}
.step-nav-prev{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--text-3);background:none;border:none;cursor:pointer;padding:8px 0;font-family:inherit;transition:color .12s;}
.step-nav-prev:hover{color:var(--text-2);} .step-nav-prev svg{width:14px;height:14px;}
.step-nav-next{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(85,82,246,.3);}
.step-nav-next:hover{background:var(--indigo-dark);transform:translateY(-1px);box-shadow:0 2px 8px rgba(85,82,246,.4);} .step-nav-next svg{width:14px;height:14px;}
/* ── Results ── */
.results-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.results-title{font-size:13px;font-weight:600;color:var(--text);letter-spacing:-.1px;}
.results-meta{font-size:11.5px;color:var(--text-3);}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;}
.badge-sig{background:#e8f9ee;color:#1a7a3a;} .badge-ns{background:var(--bg);color:var(--text-3);}
.badge-dot{width:5px;height:5px;border-radius:50%;} .badge-sig .badge-dot{background:var(--green);} .badge-ns .badge-dot{background:var(--text-3);}
.results-table-wrap{overflow-x:auto;margin:0 -2px;}
.results-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;}
.results-table thead th{font-size:10.5px;font-weight:600;color:var(--text-3);letter-spacing:.05em;text-transform:uppercase;padding:0 14px 10px;text-align:right;border-bottom:1px solid var(--border);white-space:nowrap;}
.results-table thead th:first-child{text-align:left;}
.results-table tbody tr{transition:background .1s;}
.results-table tbody tr:hover td{background:rgba(0,0,0,.018);}
.results-table td{padding:13px 14px;text-align:right;color:var(--text-2);border-bottom:1px solid rgba(0,0,0,.04);font-variant-numeric:tabular-nums;vertical-align:middle;white-space:nowrap;}
.results-table td:first-child{text-align:left;font-weight:500;color:var(--text);}
.results-table tbody tr:last-child td{border-bottom:none;}
.results-table .cell-highlight{font-weight:600;color:var(--text);}
/* Interpretation block */
.interpret-block{background:var(--surface);border-radius:16px;box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:20px;border:1px solid var(--border);}
.interpret-row{display:flex;gap:16px;align-items:flex-start;padding:16px 22px;border-bottom:1px solid rgba(0,0,0,.04);}
.interpret-row:last-child{border-bottom:none;}
.interpret-key{width:130px;flex-shrink:0;font-size:11px;font-weight:600;color:var(--text-3);letter-spacing:.04em;text-transform:uppercase;padding-top:1px;line-height:1.5;}
.interpret-val{flex:1;font-size:13.5px;line-height:1.6;color:var(--text-2);}
.interpret-val strong{color:var(--text);font-weight:600;}
/* Save to report strip */
.str-row{display:none;align-items:center;justify-content:space-between;padding:11px 15px;background:var(--surface);border-top:1px solid var(--border);border-radius:0 0 16px 16px;}
.str-row.vis{display:flex;}
.str-hint{font-size:12px;color:var(--text-3);}
.btn-str{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--indigo);background:var(--indigo-light);border:1px solid rgba(85,82,246,.15);border-radius:999px;padding:7px 13px;cursor:pointer;transition:all .13s;}
.btn-str:hover{background:rgba(85,82,246,.14);} .btn-str svg{width:12px;height:12px;}
/* ── Report drawer ── */
.rpt-scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,.18);z-index:63;}
.rpt-scrim.open{display:block;}
.rpt-drawer{position:fixed;top:0;right:0;bottom:0;width:480px;background:var(--surface);box-shadow:-12px 0 40px rgba(0,0,0,.1);z-index:64;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .28s cubic-bezier(.32,.72,0,1);}
.rpt-drawer.open{transform:translateX(0);}
.rpt-head{display:flex;align-items:center;gap:12px;padding:18px 22px 16px;border-bottom:1px solid var(--border);flex-shrink:0;}
.rpt-head h2{font-size:15px;font-weight:700;letter-spacing:-.2px;flex:1;}
.rpt-badge{font-size:11px;font-weight:700;background:var(--indigo-light);color:var(--indigo);padding:2px 9px;border-radius:999px;}
.rpt-close-btn{width:26px;height:26px;border-radius:7px;border:none;background:var(--bg);color:var(--text-3);cursor:pointer;font-size:15px;display:grid;place-items:center;}
.rpt-close-btn:hover{background:var(--border);color:var(--text);}
.rpt-body{flex:1;overflow-y:auto;padding:20px 22px;}
.rpt-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;padding:40px;color:var(--text-3);}
.rpt-empty svg{width:40px;height:40px;opacity:.25;margin-bottom:14px;display:block;margin-left:auto;margin-right:auto;}
.rpt-empty p{font-size:13.5px;line-height:1.6;max-width:260px;}
.rpt-finding{background:var(--bg);border-radius:12px;padding:16px 18px;margin-bottom:12px;position:relative;}
.rpt-step-tag{font-size:10.5px;font-weight:700;color:var(--indigo);letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;}
.rpt-finding-title{font-size:13.5px;font-weight:600;color:var(--text);margin-bottom:5px;letter-spacing:-.1px;padding-right:22px;}
.rpt-finding-body{font-size:13px;color:var(--text-2);line-height:1.6;}
.rpt-rm{position:absolute;top:12px;right:12px;width:22px;height:22px;border-radius:6px;border:none;background:transparent;color:var(--text-3);cursor:pointer;font-size:14px;display:grid;place-items:center;}
.rpt-rm:hover{background:rgba(0,0,0,.07);color:var(--text);}
.rpt-foot{padding:14px 22px;border-top:1px solid var(--border);flex-shrink:0;}
.btn-export,.btn-export-report{width:100%;display:flex;align-items:center;justify-content:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:999px;cursor:pointer;transition:background .13s;box-shadow:0 1px 4px rgba(85,82,246,.3);}
.btn-export:hover,.btn-export-report:hover{background:var(--indigo-dark);}
/* ── Coach pull tab ── */
.coach-tab-btn,.coach-tab{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:63;display:flex;align-items:center;justify-content:center;width:22px;height:110px;background:var(--surface);border:1px solid var(--border);border-right:none;border-radius:8px 0 0 8px;cursor:pointer;box-shadow:-2px 0 8px rgba(0,0,0,.06);transition:width .15s,background .15s,right .26s cubic-bezier(.32,.72,0,1);}
.coach-tab-btn:hover,.coach-tab:hover{width:26px;background:var(--indigo-light);}
.coach-tab-lbl,.coach-tab-label{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3);user-select:none;white-space:nowrap;transition:color .15s;}
/* when the panel is open the tab rides its left edge so it never gets covered */
body.coach-open .coach-tab-btn,body.coach-open .coach-tab{right:var(--companion);background:var(--indigo-light);border-color:rgba(85,82,246,.2);}
/* hide the Coach tab while the Report drawer is out (it would overlap the drawer edge) */
body.report-open .coach-tab-btn,body.report-open .coach-tab{opacity:0;pointer-events:none;}
body.coach-open .coach-tab-lbl,body.coach-open .coach-tab-label{color:var(--indigo);}
/* ── Companion / Coach panel ── */
.companion{background:var(--surface);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;position:fixed;top:0;right:0;bottom:0;width:var(--companion);z-index:62;box-shadow:-12px 0 40px rgba(0,0,0,.1);transform:translateX(100%);transition:transform .26s cubic-bezier(.32,.72,0,1);}
body.coach-open .companion{transform:translateX(0);}
.comp-head{display:flex;align-items:center;gap:10px;padding:16px 18px 14px;border-bottom:1px solid var(--border);}
.comp-head .ch-ico{width:28px;height:28px;border-radius:8px;background:var(--indigo-light);color:var(--indigo);display:grid;place-items:center;font-size:15px;flex:none;}
.comp-head .ch-ico svg{width:14px;height:14px;}
.comp-head h3{font-size:13px;font-weight:600;color:var(--text);} .comp-head .ch-sub{font-size:11px;color:var(--text-3);}
.comp-toggle{margin-left:auto;width:24px;height:24px;border-radius:6px;border:none;background:transparent;color:var(--text-3);display:grid;place-items:center;flex:none;cursor:pointer;font-size:16px;transition:background .12s,color .12s;}
.comp-toggle:hover{background:var(--bg);color:var(--text);}
.comp-tabs{display:flex;gap:4px;padding:10px 14px 0;}
.comp-tab{flex:1;text-align:center;padding:8px 6px;border-radius:9px;font-size:12px;font-weight:700;color:var(--text-3);cursor:pointer;}
.comp-tab.active{background:var(--indigo-light);color:var(--indigo);}
.comp-body{padding:16px;overflow-y:auto;flex:1;}
.comp-block{margin-bottom:16px;}
.cb-k{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:7px;color:var(--text-3);}
.cb-k .i{width:16px;height:16px;border-radius:5px;display:grid;place-items:center;font-size:10px;color:#fff;background:var(--indigo);}
.cb-t{font-size:13px;line-height:1.55;color:var(--text-2);} .cb-t b{color:var(--text);font-weight:700;}
.comp-why{background:var(--indigo-light);border:1px solid rgba(85,82,246,.2);border-radius:12px;padding:13px 14px;}
.comp-why .cb-k{color:var(--indigo);} .comp-why .cb-t{color:var(--indigo);}
.notes-area{width:100%;min-height:200px;border:1px solid var(--border);border-radius:12px;padding:12px;font-family:inherit;font-size:13px;resize:vertical;color:var(--text);outline:none;}
.notes-area:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(85,82,246,.08);}
.ai-prompt{border:1px solid var(--border);border-radius:12px;padding:12px;font-size:13px;color:var(--text-3);background:var(--bg);margin-bottom:12px;}
.ai-suggest{display:flex;flex-direction:column;gap:8px;}
.ai-chip{text-align:left;border:1px solid var(--border);background:var(--surface);border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600;color:var(--text);cursor:pointer;}
.ai-chip:hover{border-color:var(--indigo);background:var(--indigo-light);color:var(--indigo);}
.ai-answer{border:1px solid rgba(85,82,246,.22);background:var(--indigo-light);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--text);margin-top:12px;}
/* companion-collapsed stubs (kept for JS compat) */
.comp-collapsed-tab{display:none;}
body.companion-collapsed .comp-body,body.companion-collapsed .comp-tabs{display:none;}
body.companion-collapsed .comp-head{justify-content:center;padding:14px 6px;}
body.companion-collapsed .comp-toggle{margin-left:0;}
/* ── Coach content (prototype style) ── */
.coach-context-chip{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:var(--indigo);background:var(--indigo-light);padding:4px 10px;border-radius:999px;margin-bottom:14px;letter-spacing:.01em;}
.coach-context-chip svg{width:10px;height:10px;}
.coach-section{margin-bottom:18px;}
.coach-section-label{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-3);margin-bottom:8px;}
.coach-tip{font-size:13.5px;line-height:1.65;color:var(--text-2);}
.coach-tip strong,.coach-tip b{color:var(--text);font-weight:600;}
.coach-divider{height:1px;background:var(--border);margin:16px 0;}
.coach-prompt-list{display:flex;flex-direction:column;gap:6px;}
.coach-prompt{text-align:left;background:var(--bg);border:1px solid transparent;border-radius:10px;padding:10px 13px;font-family:inherit;font-size:12.5px;font-weight:500;color:var(--text-2);cursor:pointer;line-height:1.4;transition:all .12s;}
.coach-prompt:hover{background:var(--indigo-light);color:var(--indigo);border-color:rgba(85,82,246,.15);}
.coach-prompt.active{background:var(--indigo-light);color:var(--indigo);border-color:rgba(85,82,246,.15);font-weight:600;}
.coach-answer{background:var(--indigo-light);border-radius:12px;padding:13px 15px;font-size:13px;line-height:1.65;color:var(--text);margin-top:10px;display:none;}
.coach-answer.visible{display:block;}
.comp-foot{padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0;}
.coach-input-row{display:flex;gap:8px;align-items:center;}
.coach-input{flex:1;font-family:inherit;font-size:13px;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;outline:none;transition:border-color .15s;}
.coach-input:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(85,82,246,.08);}
.coach-input::placeholder{color:var(--text-3);}
.coach-send{width:32px;height:32px;border-radius:999px;background:var(--indigo);border:none;color:#fff;display:grid;place-items:center;cursor:pointer;flex-shrink:0;transition:background .12s;}
.coach-send:hover{background:var(--indigo-dark);} .coach-send svg{width:13px;height:13px;}
.coach-ai-label{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);margin-bottom:7px;text-align:center;}
/* ── dx-* component classes (used by step renderers) ── */
.dx-scroll{overflow-x:auto;margin:0 -4px;}
.dx-table{width:100%;border-collapse:collapse;font-size:13px;min-width:560px;}
.dx-table th{text-align:right;font-size:10.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);padding:0 12px 10px;border-bottom:1px solid var(--border);white-space:nowrap;}
.dx-table th.l{text-align:left;}
.dx-table td{padding:11px 12px;border-bottom:1px solid rgba(0,0,0,.04);text-align:right;font-variant-numeric:tabular-nums;color:var(--text-2);white-space:nowrap;}
.dx-table tr:last-child td{border-bottom:none;}
.dx-name{text-align:left!important;font-weight:500;color:var(--text);}
.dx-interp{text-align:left!important;color:var(--text-2);white-space:normal!important;}
.dx-neg{color:#c0524a;font-weight:700;} .dx-pos{color:var(--mm-ink);font-weight:700;}
.dx-total td{border-top:1.5px solid var(--border);border-bottom:none;font-weight:700;color:var(--text);}
.dx-layers{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-sm);padding:20px;margin-bottom:20px;}
.dx-l{margin-bottom:16px;} .dx-l:last-child{margin-bottom:0;}
.dx-l-k{font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);margin-bottom:5px;}
.dx-l-t{font-size:14px;line-height:1.55;color:var(--text-2);}
.dx-q{background:rgba(85,82,246,.05);border:1px solid rgba(85,82,246,.12);border-radius:12px;padding:13px 15px;}
.dx-q .dx-l-t{color:var(--text);font-weight:600;}
.dx-caution{background:#fbf8ed;border:1px solid #f0ddb0;border-radius:12px;padding:13px 15px;}
.dx-caution .dx-l-k{color:#8a6418;} .dx-caution .dx-l-t{color:#7a5e1c;}
.dx-next{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--surface);border:1px solid var(--indigo);border-radius:14px;padding:14px 18px;box-shadow:var(--shadow-sm);margin-bottom:18px;}
.dx-next-k{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--indigo);flex:none;}
.dx-next-t{font-size:13.5px;color:var(--text-2);line-height:1.5;} .dx-next-t b{color:var(--text);}
/* ── Strand / type chips ── */
.strand-chip{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.02em;}
.strand-chip.quan{background:var(--quan-soft);color:var(--quan-ink);} .strand-chip.qual{background:var(--qual-soft);color:var(--qual-ink);} .strand-chip.both{background:var(--mm-soft);color:var(--mm-ink);}
.lead{font-size:9px;font-weight:800;letter-spacing:.03em;padding:2px 6px;border-radius:999px;text-transform:uppercase;}
.lead.quan{background:var(--quan-soft);color:var(--quan-ink);} .lead.qual{background:var(--qual-soft);color:var(--qual-ink);} .lead.both{background:var(--mm-soft);color:var(--mm-ink);}
.type-tag{display:inline-block;font-size:10.5px;font-weight:600;letter-spacing:.02em;padding:2px 8px;border-radius:5px;}
.type-tag-quan{background:#eef3fa;color:#0a5fc8;} .type-tag-qual{background:#f3eefb;color:#6330b8;} .type-tag-mm{background:#edfaf2;color:#1a7a3a;}
/* ── Modal ── */
.modal-scrim{display:none;position:fixed;inset:0;background:rgba(20,28,45,.34);z-index:80;align-items:center;justify-content:center;padding:20px;}
.modal-scrim.open{display:flex;}
.modal{background:var(--surface);border-radius:18px;box-shadow:0 14px 40px rgba(0,0,0,.18);width:560px;max-width:100%;max-height:88vh;overflow-y:auto;}
.modal-h{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;}
.modal-h h3{font-size:16px;} .modal-h .mx{margin-left:auto;background:none;border:none;color:var(--text-3);font-size:18px;}
.modal-b{padding:20px 22px;}
.q-card{border:1px solid var(--border);border-radius:13px;padding:16px;margin-bottom:12px;cursor:pointer;}
.q-card:hover{border-color:var(--indigo);background:var(--indigo-light);}
.q-card h4{font-size:14px;margin-bottom:5px;display:flex;align-items:center;gap:8px;}
.q-card p{font-size:12.5px;color:var(--text-2);margin:0;line-height:1.5;}
/* ── Toast ── */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--text);color:#fff;padding:11px 18px;border-radius:999px;font-size:13px;font-weight:600;z-index:90;opacity:0;transition:.25s;pointer-events:none;}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
/* ── Misc component ── */
.ws-header{margin-bottom:14px;}
.context-strip{display:flex;align-items:center;gap:10px;padding:9px 15px;background:var(--surface);border:1px solid var(--border);border-radius:999px;font-size:12.5px;color:var(--text-2);margin-bottom:14px;width:fit-content;}
.context-strip .dot{width:8px;height:8px;border-radius:50%;background:var(--green);}
.context-strip b{color:var(--text);font-weight:700;}
.answer{display:flex;gap:11px;align-items:flex-start;padding:12px 15px;border-radius:13px;background:var(--indigo-light);border:1px solid rgba(85,82,246,.2);margin-bottom:16px;}
.answer .a-ico{width:24px;height:24px;border-radius:7px;flex:none;display:grid;place-items:center;background:var(--indigo);color:#fff;font-size:13px;}
.answer .a-text{font-size:14px;line-height:1.55;color:var(--text);}
.work-surface{border:1.5px dashed var(--border);border-radius:13px;background:var(--bg);padding:24px;color:var(--text-2);font-size:13.5px;line-height:1.6;}
.phase-banner{display:flex;gap:9px;align-items:center;padding:9px 14px;background:var(--indigo-light);border:1px solid rgba(85,82,246,.2);border-radius:11px;font-size:12px;font-weight:600;color:var(--indigo);}
.ws-tool-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.ws-tool-head h4{font-size:15.5px;font-weight:650;}
.ws-tool-head .ws-dot{width:9px;height:9px;border-radius:50%;flex:none;}
.ws-tool-head .ws-dot.quan{background:var(--quan);} .ws-tool-head .ws-dot.qual{background:var(--qual);} .ws-tool-head .ws-dot.both{background:var(--mm);}
.ws-tool-head .ws-tag{margin-left:auto;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.04em;}
/* ── Start page ── */
.start-hero{font-size:28px;font-weight:700;letter-spacing:-.5px;line-height:1.2;margin-bottom:10px;max-width:30ch;font-family:var(--font-display);}
.start-hero .accent{color:var(--indigo);}
.begin-loaded{display:flex;align-items:center;gap:10px;padding:11px 16px;border:1px solid var(--border);background:var(--surface);border-radius:12px;font-size:13.5px;color:var(--text-2);margin-bottom:20px;box-shadow:var(--shadow-sm);}
.begin-loaded .dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex:none;}
.begin-loaded .bl-k{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);}
.proj-select{font-family:inherit;font-size:13.5px;font-weight:700;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:7px 30px 7px 12px;cursor:pointer;max-width:380px;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23aeaeb2' stroke-width='3' stroke-linecap='round'><polyline points='6 9 12 15 18 9'/></svg>");background-repeat:no-repeat;background-position:right 10px center;}
.proj-select:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(85,82,246,.08);}
.bc-ico{width:40px;height:40px;border-radius:11px;background:var(--bg);color:var(--text-2);display:grid;place-items:center;font-size:18px;flex:none;border:1px solid var(--border);}
.begin-feature{display:flex;gap:18px;align-items:flex-start;text-align:left;width:100%;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px;cursor:pointer;transition:.14s;box-shadow:var(--shadow-sm);margin-bottom:26px;}
.begin-feature:hover{border-color:var(--indigo);}
.begin-feature .bc-ico{width:44px;height:44px;font-size:20px;}
.begin-feature h4{font-size:18px;font-weight:800;margin-bottom:6px;}
.begin-feature p{font-size:14px;color:var(--text-2);line-height:1.55;margin:0 0 12px;max-width:72ch;}
.begin-feature .bc-go{font-size:14px;font-weight:800;color:var(--indigo);}
.begin-sec{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-3);margin:0 0 14px;}
.begin-grid2{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;}
@media(max-width:980px){.begin-grid2{grid-template-columns:1fr;}}
.begin-card2{text-align:left;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:20px;cursor:pointer;transition:.14s;box-shadow:var(--shadow-sm);display:flex;flex-direction:column;gap:5px;}
.begin-card2:hover{border-color:var(--indigo);transform:translateY(-1px);}
.begin-card2 .bc-ico{margin-bottom:8px;}
.begin-card2 h4{font-size:15.5px;font-weight:800;}
.begin-card2 p{font-size:13px;color:var(--text-2);line-height:1.5;margin:0;flex:1;}
.begin-card2 .bc-go{font-size:13px;font-weight:700;color:var(--indigo);margin-top:10px;}
/* ── Form fields ── */
.ed-l{display:block;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);margin:14px 0 6px;}
.ed-l:first-child{margin-top:0;}
.ed-in{width:100%;font-family:inherit;font-size:14px;color:var(--text);background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 12px;resize:vertical;outline:none;}
.ed-in:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(85,82,246,.08);}
.ed-foot{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}
/* ── t-test specifics ── */
.tt-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 18px;margin-bottom:6px;}
@media(max-width:680px){.tt-grid{grid-template-columns:1fr;}}
.tt-hint{font-size:11px;font-weight:600;color:var(--text-3);text-transform:none;letter-spacing:0;margin-top:5px;}
label .tt-hint{margin-left:6px;}
.tt-segs{display:inline-flex;gap:4px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:3px;}
.tt-seg{border:none;background:none;padding:7px 13px;border-radius:8px;font-size:12.5px;font-weight:700;color:var(--text-2);cursor:pointer;}
.tt-seg.on{background:var(--surface);color:var(--text);box-shadow:var(--shadow-sm);}
.tt-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:14px;}
.tt-tab{border:1px solid var(--border);background:var(--surface);padding:8px 13px;border-radius:9px;font-size:12.5px;font-weight:700;color:var(--text-2);cursor:pointer;}
.tt-tab.on{background:var(--indigo-light);color:var(--indigo);border-color:rgba(85,82,246,.2);}
.tt-status{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:999px;}
.tt-status.ok{background:var(--green-soft);color:var(--mm-ink);} .tt-status.rev{background:#fbf1df;color:#8a6418;}
/* ── Data Quality ── */
.dq-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:18px;}
.dq-row{display:flex;align-items:center;gap:14px;padding:16px 20px;border-bottom:1px solid rgba(0,0,0,.04);}
.dq-row:last-child{border-bottom:none;}
.dq-ico{width:28px;height:28px;border-radius:8px;flex:none;display:grid;place-items:center;font-size:14px;font-weight:800;background:var(--bg);color:var(--text-2);}
.dq-body{flex:1;} .dq-name{font-size:14.5px;font-weight:700;color:var(--text);}
.dq-risk{font-size:13px;color:var(--text-2);margin-top:2px;}
.dq-status{font-size:11.5px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.04em;flex:none;}
/* ── Data Map ── */
.dm-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;}
.dm-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);padding:13px 15px;display:flex;flex-direction:column;gap:6px;}
.dm-card-k{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text-3);}
.dm-card-v{font-size:23px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;letter-spacing:-.01em;line-height:1.1;}
.dm-card-v.sm{font-size:15px;font-weight:700;letter-spacing:0;word-break:break-word;}
.dm-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;}
.dm-tab{border:1px solid var(--border);background:var(--surface);padding:8px 13px;border-radius:9px;font-size:12.5px;font-weight:700;color:var(--text-2);cursor:pointer;}
.dm-tab:hover{color:var(--text);}
.dm-tab.on{background:var(--indigo-light);color:var(--indigo);border-color:rgba(85,82,246,.2);}
.dm-sel{padding:6px 9px;font-size:12.5px;border-radius:8px;max-width:240px;width:auto;border:1px solid var(--border);}
.dm-flow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);padding:16px 18px;margin-bottom:18px;}
.dm-flow-node{font-size:12.5px;font-weight:700;color:var(--text-2);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;}
.dm-flow-node.quan{background:var(--quan-soft);color:var(--quan-ink);border-color:transparent;}
.dm-flow-node.qual{background:var(--qual-soft);color:var(--qual-ink);border-color:transparent;}
.dm-flow-node.mm{background:var(--mm-soft);color:var(--mm-ink);border-color:transparent;}
.dm-flow-arrow{color:var(--text-3);font-weight:800;}
.dm-fit-sel td{background:var(--indigo-light);}
.dm-fit-sel td:first-child{box-shadow:inset 3px 0 0 var(--indigo);}
.dm-note{font-size:12.5px;color:var(--text-3);}
.dm-save{display:flex;align-items:center;gap:12px;margin:0;padding:12px 0;position:sticky;bottom:0;background:var(--bg);border-top:1px solid var(--border);z-index:5;}
/* ── Qual Themes ── */
.th-bar{height:7px;border-radius:999px;background:rgba(0,0,0,.05);overflow:hidden;min-width:90px;margin-top:5px;}
.th-bar > i{display:block;height:100%;background:var(--qual);border-radius:999px;}
.th-cov{font-size:12.5px;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums;}
.th-sent{font-size:11.5px;font-weight:700;color:var(--text-3);white-space:nowrap;}
.th-sent b.pos{color:var(--mm-ink);} .th-sent b.neg{color:#b3402f;} .th-sent b.neu{color:var(--text-3);}
.th-quotes{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);padding:6px 18px;margin:14px 0 18px;}
.th-quote{padding:13px 0;border-bottom:1px solid rgba(0,0,0,.04);}
.th-quote:last-child{border-bottom:none;}
.th-quote-t{font-size:14px;color:var(--text);line-height:1.55;}
.th-quote-m{font-size:11.5px;font-weight:700;color:var(--text-3);margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;}
.th-empty{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-sm);padding:26px;text-align:center;}
.th-empty h3{font-size:16px;margin-bottom:6px;} .th-empty p{font-size:13.5px;color:var(--text-2);max-width:520px;margin:0 auto 16px;}
/* ── Overview step ── */
.ov-sec{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-3);margin:4px 0 12px;}
.ov-data{border:1px solid var(--border);border-radius:16px;overflow:hidden;background:var(--surface);box-shadow:var(--shadow-sm);margin-bottom:18px;}
.ov-summary{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-sm);padding:6px 20px;margin-bottom:22px;}
.ov-row{display:flex;gap:18px;align-items:flex-start;padding:15px 0;border-bottom:1px solid rgba(0,0,0,.04);}
.ov-row:last-child{border-bottom:none;}
.ov-k{width:150px;flex:none;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);padding-top:5px;}
.ov-v{flex:1;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.ov-design{font-size:14.5px;font-weight:600;color:var(--text);}
.ov-chip{font-size:12.5px;font-weight:600;color:var(--text-2);background:var(--bg);border:1px solid var(--border);padding:5px 11px;border-radius:999px;}
.ov-empty{font-size:13px;color:var(--text-3);font-style:italic;}
.ov-score{font-size:20px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;letter-spacing:-.02em;}
.ov-score-max{font-size:12.5px;font-weight:700;color:var(--text-3);margin-left:-3px;}
.ov-band{font-size:11.5px;font-weight:700;color:var(--text-2);background:var(--bg);border:1px solid var(--border);padding:3px 9px;border-radius:999px;}
.ov-link{font-size:12.5px;font-weight:700;color:var(--indigo);text-decoration:none;margin-left:8px;}
.ov-link:hover{text-decoration:underline;}
/* ── Data Map survey design tabs ── */
.sd-wrap{max-width:680px;}
.sd-h{font-size:22px;font-weight:600;margin:0 0 16px;color:var(--text);}
.sd-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:0;}
.sd-tab{background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;padding:8px 20px;font-size:13.5px;font-weight:600;color:var(--text-3);cursor:pointer;transition:color .15s,border-color .15s;}
.sd-tab:hover{color:var(--text);}
.sd-tab.is-active{color:var(--indigo);border-bottom-color:var(--indigo);}
.sd-tab-body{padding:20px 0 0;}
.sd-tab-intro{font-size:14px;color:var(--text-2);margin-bottom:16px;line-height:1.5;}
.sd-opt{display:flex;align-items:flex-start;gap:12px;border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px;background:var(--surface);cursor:pointer;user-select:none;}
.sd-opt:hover{border-color:var(--indigo);}
.sd-opt.is-on{border-color:var(--indigo);background:var(--indigo-light);}
.sd-opt input{margin-top:3px;flex-shrink:0;accent-color:var(--indigo);}
.sd-opt strong{font-size:13.5px;color:var(--text);}
.sd-help{color:var(--text-3);font-size:12.5px;margin-top:3px;line-height:1.4;}
.sd-rec{display:inline-block;margin-left:8px;background:#1f7a3a;color:#fff;font-size:10.5px;padding:2px 7px;border-radius:999px;font-weight:600;}
.sd-actions{margin-top:20px;display:flex;align-items:center;gap:14px;}
.sd-saved{font-size:13px;color:#1f7a3a;font-weight:600;}
/* ── RSSI badges (dock compat) ── */
.rssi-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid transparent;line-height:1;}
.rssi-badge .rssi-score{font-size:17px;font-weight:800;}
.rssi-badge .rssi-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;opacity:.72;white-space:nowrap;}
.rssi-confident{background:var(--green-soft);color:var(--green);}
.rssi-developing{background:#fff8ee;color:#b45309;}
.rssi-withheld{background:var(--bg);color:var(--text-3);border-color:var(--border);}
/* ── Studio dock (footer plug-in) ── */
.studio-dock{position:relative;padding:12px 22px;background:rgba(255,255,255,.92);-webkit-backdrop-filter:saturate(1.4) blur(12px);backdrop-filter:saturate(1.4) blur(12px);border-top:1px solid var(--border);}
.studio-dock-logo{position:absolute;left:22px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;text-decoration:none;}
.studio-dock-logo img{height:24px;width:auto;display:block;}
.studio-dock-inner{display:flex;align-items:center;justify-content:center;gap:11px;flex-wrap:wrap;min-height:34px;}
.studio-dock .lbl{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-right:2px;}
.as-intake-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:.13s;}
.as-intake-btn:hover{border-color:var(--text-3);color:var(--text);}
.dk-sep{width:1px;height:22px;background:var(--border);margin:0 3px;}
.dk-rssi{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;border:1px solid var(--border);background:var(--surface);font-size:13px;font-weight:700;color:var(--text-2);text-decoration:none;transition:.13s;}
.dk-rssi:hover{border-color:var(--quan);}
.dk-rssi .dot{width:8px;height:8px;border-radius:50%;background:rgba(0,0,0,.1);}
.dk-rssi.is-available .dot{background:var(--mm);}
.dk-rssi small{font-weight:600;color:var(--text-3);}
.tb-rssi{display:flex;align-items:center;}
@media(max-width:760px){.studio-dock-logo{display:none;}}
/* scrollbar */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(0,0,0,.12);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.2);}
/* palette hidden (replaced by in-content tool tabs) */
.palette{display:none!important;}
/* ── In-content tool tabs + footer for analysis steps (replaces the palette).
   Live OUTSIDE #centerInner so per-analysis re-renders don't wipe them. ── */
.q-railbar{max-width:960px;margin:0 auto;}
#qTabsBar{margin-bottom:18px;} #qFootBar{margin-top:4px;}
#qTabsBar:empty,#qFootBar:empty{display:none;}
.q-tooltabs{display:inline-flex;gap:3px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:3px;flex-wrap:wrap;max-width:100%;}
.q-tooltab{border:none;background:transparent;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--text-2);padding:7px 14px;border-radius:7px;cursor:pointer;transition:all .13s;white-space:nowrap;}
.q-tooltab:hover:not(.on){color:var(--text);background:rgba(0,0,0,.04);}
.q-tooltab.on{background:var(--indigo);color:#fff;box-shadow:0 1px 3px rgba(85,82,246,.35);}
/* ── One purple-button family — all match the Study Design switcher's flat indigo
   treatment (same fill, subtle shadow, indigo-dark hover, no glow/lift). ── */
.btn.primary,.btn-primary,.step-nav-next,.tb-act-rpt,.btn-export,.btn-export-report,.coach-send{
  background:var(--indigo)!important;color:#fff!important;border-color:transparent!important;
  box-shadow:0 1px 3px rgba(85,82,246,.35)!important;font-weight:600;}
.btn.primary:hover,.btn-primary:hover,.step-nav-next:hover,.tb-act-rpt:hover,.btn-export:hover,.btn-export-report:hover,.coach-send:hover{
  background:var(--indigo-dark)!important;box-shadow:0 1px 3px rgba(85,82,246,.35)!important;transform:none!important;}
/* soft purple (Save-to-report chip) keeps the tinted style but the same indigo */
.btn-str{background:var(--indigo-light)!important;color:var(--indigo)!important;border-color:rgba(85,82,246,.15)!important;box-shadow:none!important;}
/* ── Variable Map (DataMap component) — compact in MM so all columns fit at once.
   Scoped to #mmDmContainer so other studios are unaffected; the shared component
   file is untouched. ── */
#mmDmContainer .rdm-tbl{font-size:12px;}
#mmDmContainer .rdm-tbl th{padding:7px 8px;font-size:10px;}
#mmDmContainer .rdm-tbl td{padding:6px 8px;}
#mmDmContainer .rdm-vname,#mmDmContainer .rdm-vlabel{max-width:118px;}
#mmDmContainer .rdm-vlabel{font-size:10.5px;}
#mmDmContainer .rdm-chip{font-size:10.5px;padding:2px 6px;}
#mmDmContainer .rdm-sel{min-width:102px;max-width:148px;font-size:11.5px;padding:4px 6px;}
#mmDmContainer .rdm-con{width:116px;font-size:11.5px;padding:4px 6px;}
#mmDmContainer .rdm-analyses{max-width:148px;font-size:10.5px;line-height:1.45;}
#mmDmContainer .rdm-tog{font-size:11px;}
</style>
<script src="/apps/studio/dataset-upload.js?v=<?= filemtime(__DIR__.'/apps/studio/dataset-upload.js') ?>"></script>
<script src="/apps/studio/studio-header.js?v=<?= filemtime(__DIR__.'/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= filemtime(__DIR__.'/apps/studio/studio-footer.js') ?>"></script>
<script src="/apps/studio/type-taxonomy.js?v=<?= filemtime(__DIR__.'/apps/studio/type-taxonomy.js') ?>"></script>
<script src="/apps/studio/data-map.js?v=<?= filemtime(__DIR__.'/apps/studio/data-map.js') ?>"></script>
</head>
<body>
<div class="app">

  <!-- RC global header (studio-header.js, row 1) -->
  <div id="studioHeader"></div>

  <!-- Studio topbar (row 2, full width) -->
  <header class="topbar">
    <div class="topbar-logo">Mixed Methods Studio</div>
    <div class="topbar-steps" id="topbarSteps"></div>
    <div class="topbar-right">
      <button class="tb-act tb-act-save" id="saveProjectBtn" onclick="mmSaveProject()">
        <svg viewBox="0 0 16 16" fill="none"><path d="M13 13H3a1 1 0 0 1-1-1V3l2-1h7l2 2v8a1 1 0 0 1-1 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><rect x="5" y="9" width="6" height="4" rx=".5" stroke="currentColor" stroke-width="1.4"/><rect x="5.5" y="2" width="4" height="3" rx=".5" stroke="currentColor" stroke-width="1.4"/></svg>
        Save
      </button>
      <button class="tb-act tb-act-rpt" onclick="toggleRptDrawer()">
        <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="white" stroke-width="1.4"/><line x1="5" y1="6" x2="11" y2="6" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="8.5" x2="11" y2="8.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="11" x2="8" y2="11" stroke="white" stroke-width="1.4" stroke-linecap="round"/></svg>
        Report <span class="rpt-count-badge" id="rptCountBadge">0</span>
      </button>
<?php if($projectId > 0): ?>
      <div class="topbar-project">
        <strong><?= htmlspecialchars($projectTitle) ?></strong>
        <?= (int)$respCount ?> responses
      </div>
<?php endif; ?>
    </div>
  </header>

  <!-- Hidden JS compat stubs -->
  <div style="display:none" aria-hidden="true">
    <div id="designSwitch"></div>
    <div id="designPick">
      <button class="design-pick-btn" onclick="toggleDesignMenu(event)"><span id="designName"></span></button>
      <div class="design-menu" id="designMenu"></div>
    </div>
    <div id="designFlow"></div>
    <div id="railSteps"></div>
  </div>

  <!-- Sidebar (row 3, col 1) -->
  <aside class="sidebar" id="studioSidebar">
    <div class="sidebar-header">
      <div class="sidebar-project-name">Study Design</div>
      <div class="design-switcher" id="sbDesignPill"></div>
      <div class="sidebar-design-desc" id="sbDesignDesc"></div>

      <div class="sidebar-divider"></div>

      <div class="sidebar-project-name">Researcher's Notes</div>
      <div class="notes-block">
        <div class="notes-block-head">
          <div class="notes-block-label">
            <svg viewBox="0 0 14 14" fill="none"><path d="M2 10.5V12h1.5l6-6L8 4.5l-6 6zM11.7 2.8a1 1 0 0 0 0-1.4l-.6-.6a1 1 0 0 0-1.4 0l-1 1L10.2 3.8l1-1z" fill="currentColor"/></svg>
            This step
          </div>
          <span class="notes-step-tag" id="nbStepTag">Step 1</span>
        </div>
        <div class="notes-wrap">
          <textarea class="sidebar-notes" id="researcherNotes" placeholder="Jot observations, decisions, or hunches..."></textarea>
        </div>
        <div class="notes-footer">
          <span class="nb-saved" id="nbSaved">Saved</span>
          <button class="btn-note-rpt" id="btnNoteRpt" onclick="mmSaveNoteToReport()">
            <svg viewBox="0 0 12 12" fill="none"><line x1="6" y1="1" x2="6" y2="11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="1" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Add to Report
          </button>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main content (row 3, col 2) -->
  <main class="main center" id="mainContent">
    <div id="qTabsBar" class="q-railbar"></div>
    <div class="content-wrap center-inner" id="centerInner"></div>
    <div id="qFootBar" class="q-railbar"></div>
  </main>

  <!-- RC studio footer (studio-footer.js, row 4) -->
  <div id="studioFooter"></div>
</div>

<!-- Hidden palette (JS compat) -->
<div id="palette" style="display:none"></div>

<!-- Coach pull tab -->
<button class="coach-tab" onclick="toggleCoach()" aria-label="ReliCheck Coach">
  <span class="coach-tab-label">Coach</span>
</button>

<!-- Coach panel (slide-in) -->
<aside class="companion" id="companion">
  <div class="comp-head">
    <div class="ch-ico"><svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="6" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M3 14c0-2.761 2.239-5 5-5s5 2.239 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
    <div><h3>ReliCheck Coach</h3><div class="ch-sub" id="coachStepLabel">Guidance</div></div>
    <button class="comp-toggle" onclick="toggleCoach()" title="Close">✕</button>
  </div>
  <div class="comp-tabs" id="compTabs" style="display:none"></div>
  <div class="comp-body" id="compBody"></div>
  <div class="comp-foot">
    <div class="coach-ai-label">✦ Ask ReliCheck Intelligence</div>
    <div class="coach-input-row">
      <input class="coach-input" type="text" id="coachInput" placeholder="Ask a question about this step..." onkeydown="handleCoachInput(event)">
      <button class="coach-send" onclick="handleCoachSend()" title="Ask"><svg viewBox="0 0 16 16" fill="none"><line x1="3" y1="8" x2="13" y2="8" stroke="white" stroke-width="1.8" stroke-linecap="round"/><polyline points="9,4 13,8 9,12" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    </div>
  </div>
</aside>

<!-- Report drawer -->
<div class="rpt-scrim" id="rptScrim" onclick="toggleRptDrawer()"></div>
<div class="rpt-drawer" id="rptDrawer">
  <div class="rpt-head">
    <h2>Report</h2>
    <span class="rpt-badge" id="rptBadgeDrawer">0 sections</span>
    <button class="rpt-close-btn" onclick="toggleRptDrawer()">✕</button>
  </div>
  <div class="rpt-body" id="rptBody">
    <div class="rpt-empty" id="rptEmpty">
      <svg viewBox="0 0 40 40" fill="none"><rect x="6" y="4" width="28" height="32" rx="3" stroke="currentColor" stroke-width="2"/><line x1="12" y1="14" x2="28" y2="14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="20" x2="28" y2="20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="26" x2="20" y2="26" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <p>No findings saved yet. Run an analysis and use Save to Report to build your report.</p>
    </div>
  </div>
  <div class="rpt-foot">
    <button class="btn-export" onclick="mmExportReport()">
      <svg viewBox="0 0 16 16" fill="none"><path d="M3 10v3h10v-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><line x1="8" y1="2" x2="8" y2="10" stroke="white" stroke-width="1.5" stroke-linecap="round"/><polyline points="5,7 8,10 11,7" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Export Report
    </button>
  </div>
</div>

<div class="modal-scrim" id="modalScrim"><div class="modal" id="modal"></div></div>
<div class="toast" id="toast"></div>

<script>
const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES) ?>;

/* ---- design→pipeline mapping comes from the server config (_mm_pipelines.php) ---- */
const MM = <?= json_encode($MM, JSON_UNESCAPED_SLASHES) ?>;
const SETUP=MM.setup, CONCLUDE=MM.conclude, L=MM.tools, P=MM.pivots;
const DESIGNS=MM.designs, DESIGN_ORDER=MM.order, HELP=MM.help;

function buildSteps(){
  const d=DESIGNS[state.design];
  // a mid ref is either a tool key (e.g. "q_inf") or a pivot ref ("@merge")
  const mid=d.mid.map(ref => ref.charAt(0)==='@' ? P[ref.slice(1)] : {...L[ref],id:ref});
  return [...SETUP,...mid,...CONCLUDE].map((s,i)=>({...s,n:i+1,done:i<state.completedThrough}));
}
const state={design:BOOT.design,stepId:null,completedThrough:0,toolSel:null,compTab:"explain",notes:{}};
const $=s=>document.querySelector(s);
// Footer nav whose buttons name the prior step (Back) and the next step (Continue).
function navFooter(){
  const ss=steps(); const cur=activeStep();
  const i=ss.findIndex(function(x){return x.id===cur.id;});
  const prev=i>0?ss[i-1]:null, next=(i>=0&&i<ss.length-1)?ss[i+1]:null;
  const back=prev?'<button class="btn" onclick="stepBy(-1)">← '+esc(prev.label)+'</button>':'<span></span>';
  const fwd=next?'<button class="btn primary" onclick="stepBy(1)">'+esc(next.label)+' →</button>':'<span></span>';
  return '<div class="footer-nav">'+back+fwd+'</div>';
}
function mmStartUpload(){
  if(!window.DatasetUpload)return;
  DatasetUpload.open({projectType:'mm',onLoaded:function(_ds,pid){go('?project_id='+encodeURIComponent(pid));}});
}
function steps(){return buildSteps();}
function activeStep(){return steps().find(s=>s.id===state.stepId)||steps()[0];}
function firstLeadStep(){const s=steps();return (s.find(x=>x.strand!=="neutral")||s[0]).id;}
function toolsOf(step){return (step.palette&&step.palette.groups||[]).flatMap(g=>g.items);}
function currentTool(step){const t=toolsOf(step);return t.find(x=>x.name===state.toolSel)||t[0]||null;}

function renderSwitch(){
  $("#designSwitch").innerHTML=DESIGN_ORDER.map(k=>{const d=DESIGNS[k];
    return `<button class="ds-btn ${k===state.design?'active':''}" onclick="setDesign('${k}')"><span class="lead ${d.lead}">${d.leadLabel}</span>${d.short}</button>`;}).join("");
}
function renderRail(){
  const d=DESIGNS[state.design];
  $("#designName").textContent=d.short;
  $("#designMenu").innerHTML=DESIGN_ORDER.map(k=>{const o=DESIGNS[k];
    return `<div class="dm-opt ${k===state.design?'active':''}" onclick="pickDesign('${k}')">
      <span class="lead ${o.lead}">${o.leadLabel}</span><span class="dm-lbl">${o.short}</span><span class="dm-check">✓</span></div>`;}).join("");
  $("#designFlow").innerHTML=d.flow.map((f,i)=>`${i?'<span class="flow-arrow">→</span>':''}<span class="flow-pill ${f[0]}">${f[1]}</span>`).join("");
  const act=activeStep();
  $("#railSteps").innerHTML=steps().map(s=>
    `<div class="step ${s.strand} ${s.pivot?'pivot':''}" data-active="${s.id===act.id?1:0}" data-done="${s.done?1:0}" onclick="goStep('${s.id}')">
      <span class="num">${s.done?'✓':s.n}</span><span class="lbl">${s.label}</span><span class="sdot"></span></div>`).join("");
}
function startPickDesign(k){state.design=k;persistDesign(k);render();$(".center").scrollTop=0;}
function renderStart(s){
  const loaded = BOOT.projectId>0;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">New project</div>
      <h1 class="start-hero">Connect numbers, narratives, <span class="accent">and meaning.</span></h1>
      <p class="lede">${esc(s.lede)}</p></div>
    ${loaded?`<div class="begin-loaded"><span class="dot"></span><span class="bl-k">Project</span>
      <select class="proj-select" onchange="if(this.value)go('?project_id='+this.value)">
        ${(BOOT.projects||[]).map(p=>`<option value="${p.id}" ${p.id===BOOT.projectId?'selected':''}>${esc(p.title)}</option>`).join('')||`<option selected>${esc(BOOT.projectLabel)}</option>`}
      </select>
      <button class="btn primary" style="margin-left:auto" onclick="stepBy(1)">Continue to analysis →</button></div>`:''}
    <button class="begin-feature" onclick="mmStartUpload()">
      <span class="bc-ico">⤓</span>
      <div><h4>Bring in your data</h4>
        <p>Already have a survey plus interviews or open-ended responses? Upload a CSV or Excel file to create a project and build its dataset, then come back to analyze.</p>
        <span class="bc-go">Upload data →</span></div>
    </button>
    <div class="begin-sec">Or start another way</div>
    <div class="begin-grid2">
      <button class="begin-card2" onclick="go('/studio-mm-projects.php')"><span class="bc-ico">▦</span><h4>Open a saved project</h4><p>Go to your projects and open a mixed-methods study already in ReliCheck.</p><span class="bc-go">Open projects →</span></button>
      <button class="begin-card2" onclick="go('/develop.php?db=1&start=choose')"><span class="bc-ico">✎</span><h4>Create a survey</h4><p>Build and strengthen a new survey in SIRI, then bring its responses back here.</p><span class="bc-go">Go to SIRI →</span></button>
      <button class="begin-card2" onclick="go('/rssi.php')"><span class="bc-ico">◉</span><h4>Check your survey strength</h4><p>Run RSSI to gauge how strong and reliable your survey evidence is.</p><span class="bc-go">Go to RSSI →</span></button>
    </div>`;
}
const DK_LABEL={survey_plus_open:"Survey + open-ended",survey_plus_interviews:"Survey + interviews",quant_plus_interpretation:"Quant + interpretation",open_only:"Open-ended only",build_from_scratch:"Build from scratch"};
const INTENT_LABEL={explain_survey:"Explain the survey",strengthen_report:"Strengthen a report",find_themes:"Find themes",compare_groups:"Compare groups",eval_evidence:"Evaluate evidence",build_variables:"Build variables",explore_patterns:"Explore patterns",findings_section:"Findings section"};
function chip(t){return `<span class="ov-chip">${esc(t)}</span>`;}
function renderOverview(s){
  const f=BOOT.framing||{data_kinds:[],intent_purposes:[],chosen_design:""};
  const dk=(f.data_kinds||[]).map(k=>DK_LABEL[k]||k);
  const intents=(f.intent_purposes||[]).map(k=>INTENT_LABEL[k]||k);
  const d=DESIGNS[state.design];
  const dkHtml=dk.length?dk.map(chip).join(""):'<span class="ov-empty">Not set yet — add data from Start.</span>';
  const inHtml=intents.length?intents.map(chip).join(""):'<span class="ov-empty">Not answered yet.</span>';
  const sc=BOOT.scores||{};
  const siriRow = sc.siri!=null
    ? `<div class="ov-row"><span class="ov-k">Readiness · SIRI</span><div class="ov-v"><span class="ov-score">${sc.siri.toFixed(1)}</span><span class="ov-score-max">/ 100</span><a class="ov-link" href="/develop.php?db=1&start=choose">View →</a></div></div>` : '';
  // Instrument quality lives in RSSI, not the pipeline — always a clickable link out.
  const rssiVal = sc.rssiWithheld
    ? '<span class="ov-empty">Withheld — not enough data yet</span>'
    : (sc.rssi!=null ? '<span class="ov-score">'+sc.rssi.toFixed(1)+'</span><span class="ov-score-max">/ 100</span>'+(sc.rssiBand?'<span class="ov-band">'+esc(sc.rssiBand)+'</span>':'')
       : '<span class="ov-empty">Not checked yet</span>');
  const rssiRow = `<div class="ov-row"><span class="ov-k">Instrument quality · RSSI</span><div class="ov-v">${rssiVal}<a class="ov-link" href="/rssi.php" style="margin-left:auto">${sc.rssi!=null?'View in RSSI →':'Check in RSSI →'}</a></div></div>`;
  const ri=BOOT.rawinfo||{linked:false,rows:0,cols:0};
  const datasetRow = ri.linked
    ? `<div class="ov-row"><span class="ov-k">Dataset</span><div class="ov-v"><span class="ov-design">${ri.rows}</span> rows · <span class="ov-design">${ri.cols}</span> columns</div></div>`
    : `<div class="ov-row"><span class="ov-k">Dataset</span><div class="ov-v"><span class="ov-empty">No dataset linked to this project — upload data from Start.</span></div></div>`;
  const engine = BOOT.projectId>0
    ? `<div class="ov-sec">Your data</div>
       <div class="ov-summary">
         ${siriRow}${rssiRow}
         ${datasetRow}
         <div class="ov-row"><span class="ov-k">Text responses</span><div class="ov-v"><span class="ov-design">${BOOT.responses}</span> open-ended responses</div></div>
         <div class="ov-row"><span class="ov-k">Sources</span><div class="ov-v">${dkHtml}</div></div>
         <div class="ov-row"><span class="ov-k">Full snapshot</span><div class="ov-v">Sample size, variable types, completion, and missingness.<a class="btn" style="margin-left:auto;padding:6px 12px;font-size:12.5px" href="/project-snapshot.php?studio=mm&amp;project_id=${BOOT.projectId}" target="_blank" rel="noopener">Open data overview →</a></div></div>
       </div>`
    : `<div class="ov-sec">Your data</div><div class="work-surface" style="border-radius:16px">Connect a project from Start to see your data here.</div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Setup · what you brought in</div>
      <h1 class="ov-title">Overview</h1><p class="lede">${esc(s.lede)}</p></div>
    <div class="ov-summary">
      <div class="ov-row"><span class="ov-k">Design</span><div class="ov-v"><span class="ov-design">${d.short}</span><span class="lead ${d.lead}" style="font-size:9px;font-weight:800;padding:2px 7px;border-radius:999px;text-transform:uppercase">${d.leadLabel}</span><button class="btn" style="margin-left:auto;padding:6px 12px;font-size:12.5px" onclick="openEdit()">Edit</button></div></div>
      <div class="ov-row"><span class="ov-k">Title</span><div class="ov-v"><span class="ov-design">${esc(BOOT.projectLabel)}</span></div></div>
      <div class="ov-row"><span class="ov-k">Description</span><div class="ov-v">${BOOT.projDesc?esc(BOOT.projDesc):'<span class="ov-empty">No description yet.</span>'}</div></div>
    </div>
    ${engine}
    ${navFooter()}`;
}
/* Quantitative Descriptives — REAL data via /api/mm/descriptives.php.
   Three layers per view: the numbers, a plain-language reading, and what may
   still need explanation. Numeric-vs-categorical is classified server-side so
   it matches the t-test pickers. */
const dx={base:null,busy:false,err:'',freqId:null,xr:null,xc:null,xtab:null,xtabKey:'',xbusy:false,mNum:null,mGrp:null,means:null,meansKey:'',mbusy:false};
const _n2=x=>(x==null||isNaN(x))?'—':Number(x).toFixed(2);
const _pc=x=>(x==null||isNaN(x))?'—':Number(x).toFixed(1)+'%';
const _PICK='margin:0 0 12px;display:flex;align-items:center;gap:6px;flex-wrap:wrap';
function descFetch(params){return fetch('/api/mm/descriptives.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},params))}).then(r=>r.json());}
function dxInitDefaults(){const c=dx.base.categorical,nu=dx.base.numeric;
  if(dx.freqId==null&&c.length)dx.freqId=c[0].id;
  if(dx.xr==null&&c.length)dx.xr=c[0].id;
  if(dx.xc==null)dx.xc=(c.length>1?c[1].id:(c.length?c[0].id:null));
  if(dx.mNum==null&&nu.length)dx.mNum=nu[0].id;
  if(dx.mGrp==null&&c.length)dx.mGrp=c[0].id;}
function descHead(eyebrow,title,lede){return `<div class="ws-header"><div class="eyebrow">${eyebrow} <span class="strand-chip quan">QUAN</span></div><h1 class="title">${title}</h1><p class="lede">${lede}</p></div>`;}
function descMsg(eyebrow,title,lede,msg){return descHead(eyebrow,title,lede)+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>${navFooter()}`;}
function dxSel(cur,list,onch){return `<select class="ed-in" style="max-width:280px" onchange="${onch}">${list.map(o=>`<option value="${o.id}" ${o.id===cur?'selected':''}>${esc(o.name)}</option>`).join('')}</select>`;}
function renderDescriptive(s){
  const tool=currentTool(s); const name=tool?tool.name:'';
  const head={Frequencies:['Frequencies','Frequencies','Who is represented in the quantitative phase, category by category.'],
    'Cross-Tabs':['Cross-tabs','Cross-Tabs','Compare categories across groups before testing patterns.']};
  const H=head[name]||['Means & distributions','Means & Distributions','Summarize the quantitative pattern first.'];
  if(!(BOOT.projectId&&BOOT.rawinfo&&BOOT.rawinfo.linked)){$("#centerInner").innerHTML=descMsg(H[0],H[1],H[2],'No data is linked to this project yet. Upload data from Start, then return here to see real descriptive statistics.');return;}
  if(dx.err){$("#centerInner").innerHTML=descMsg(H[0],H[1],H[2],dx.err);return;}
  if(!dx.base){
    if(!dx.busy){dx.busy=true;descFetch({}).then(j=>{dx.busy=false;if(j&&j.ok){dx.base=j;dxInitDefaults();}else{dx.err=(j&&(j.message||j.error))||'Could not load descriptives.';}renderDescriptive(s);}).catch(()=>{dx.busy=false;dx.err='Could not load your data.';renderDescriptive(s);});}
    $("#centerInner").innerHTML=descMsg(H[0],H[1],H[2],'Loading your data…');return;}
  if(!dx.base.numeric.length&&!dx.base.categorical.length){$("#centerInner").innerHTML=descMsg(H[0],H[1],H[2],'No usable numeric or categorical variables were found in this dataset.');return;}
  if(name==='Frequencies')return renderFreq(s);
  if(name==='Cross-Tabs')return renderCrossTabs(s);
  return renderMeans(s);
}
function setFreq(v){dx.freqId=+v;renderDescriptive(activeStep());}
function isOpenEndedCat(c){
  // A "categorical" variable is actually open-ended verbatim responses if:
  // its top category value is long (responses, not codes), OR
  // nearly every response is unique (high cardinality = free text).
  // Either case means a frequency table of strings is meaningless.
  const top=c.categories&&c.categories[0];
  if(top&&top.value.length>50)return true;
  const total=c.categories?c.categories.reduce((s,r)=>s+r.count,0):0;
  const uniq=c.categories?c.categories.length:0;
  if(total>0&&uniq/total>0.6)return true;
  return false;
}
function renderFreq(s){
  const expl=state.design==='explanatory';
  // Exclude open-ended verbatim columns — they produce meaningless frequency tables.
  // Open-ended variables belong in the Qualitative strand (theme coding layer).
  const allCats=dx.base.categorical||[];
  const openExcluded=allCats.filter(c=>isOpenEndedCat(c));
  const cats=allCats.filter(c=>!isOpenEndedCat(c));
  const openNote=openExcluded.length?`<div class="dm-note" style="margin-bottom:12px"><b>${openExcluded.map(c=>esc(c.name)).join(', ')}</b> ${openExcluded.length===1?'is':'are'} excluded — open-ended responses are not frequency-countable by verbatim string. Use the <b>Qualitative strand</b> (Qualitative Themes step) for theme frequency analysis.</div>`:'';
  if(!cats.length){$("#centerInner").innerHTML=descMsg('Frequencies','Frequencies','Who is represented, category by category.',openExcluded.length?'No categorical variables found. Open-ended response columns are excluded from frequency tables — see the Qualitative strand.':'No categorical variables were found to tabulate.');return;}
  // Reset freqId if it pointed at an excluded variable
  if(dx.freqId!=null&&allCats.find(x=>x.id===dx.freqId)&&!cats.find(x=>x.id===dx.freqId))dx.freqId=null;
  const c=cats.find(x=>x.id===dx.freqId)||cats[0]; dx.freqId=c.id;
  let cum=0; const body=c.categories.map(r=>{cum+=r.valid_pct;return `<tr><td class="dx-name">${esc(r.value)}</td><td>${r.count}</td><td>${_pc(r.pct)}</td><td>${_pc(r.valid_pct)}</td><td>${_pc(cum)}</td></tr>`;}).join('');
  const miss=c.missing?`<tr><td class="dx-name">Missing</td><td>${c.missing}</td><td>${_pc(100*c.missing/(dx.base.rows||1))}</td><td>—</td><td>—</td></tr>`:'';
  const top=c.categories[0];
  $("#centerInner").innerHTML=descHead('Frequencies','Frequencies','Who is represented in the quantitative phase, category by category.')+helpBar('Frequencies')+openNote+`
    <div style="${_PICK}"><label class="ed-l" style="margin:0">Variable</label>${dxSel(c.id,cats,'setFreq(this.value)')}</div>
    <div class="panel"><div class="panel-h"><div><h3>Table 1 · Frequency distribution for ${esc(c.name)}</h3></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">${esc(c.name)}</th><th>Frequency</th><th>Percent</th><th>Valid Percent</th><th>Cumulative Percent</th></tr></thead>
        <tbody>${body}${miss}<tr class="dx-total"><td class="dx-name">Total</td><td>${dx.base.rows}</td><td>100.0%</td><td>100.0%</td><td>—</td></tr></tbody></table></div></div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">${top?`The most common ${esc(c.name)} value was "${esc(top.value)}" — ${_pc(top.valid_pct)} of valid responses (n = ${top.count}).`:'No responses to summarize.'}</div></div>
      <div class="dx-l"><div class="dx-l-k">Why this matters</div><div class="dx-l-t">How the sample is distributed across ${esc(c.name)} can shape how the other results should be read.</div></div>
      ${expl?`<div class="dx-l dx-q"><div class="dx-l-k">Mixed methods use</div><div class="dx-l-t">Which groups are well represented in the quantitative phase, and which may need qualitative follow-up?</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">Counts describe who is in the data; on their own they do not explain differences between groups.</div></div>
    </div>
    ${navFooter()}`;
}
function setMeansNum(v){dx.mNum=+v;renderDescriptive(activeStep());}
function setMeansGrp(v){dx.mGrp=+v;renderDescriptive(activeStep());}
function renderMeans(s){
  const nu=dx.base.numeric; const expl=state.design==='explanatory';
  // Exclude open-ended variables from the grouping picker — grouping by verbatim
  // responses is as meaningless here as it is in cross-tabs.
  const cats=(dx.base.categorical||[]).filter(c=>!isOpenEndedCat(c));
  if(dx.mGrp!=null&&!cats.find(c=>c.id===dx.mGrp))dx.mGrp=cats.length?cats[0].id:null;
  const t1=nu.length?nu.map(r=>`<tr><td class="dx-name">${esc(r.name)}</td><td>${r.n}</td><td>${_n2(r.mean)}</td><td>${_n2(r.sd)}</td><td>${_n2(r.min)}</td><td>${_n2(r.max)}</td><td>${r.missing}</td></tr>`).join(''):`<tr><td colspan="7">No numeric variables were found.</td></tr>`;
  let t2html='';
  if(nu.length&&cats.length){
    const key=dx.mNum+'x'+dx.mGrp;
    if(dx.meansKey!==key&&!dx.mbusy){dx.mbusy=true;descFetch({means_num:dx.mNum,means_group:dx.mGrp}).then(j=>{dx.mbusy=false;dx.meansKey=key;dx.means=(j&&j.ok&&j.means_by)?j.means_by:null;renderDescriptive(activeStep());}).catch(()=>{dx.mbusy=false;dx.meansKey=key;dx.means=null;renderDescriptive(activeStep());});}
    const mb=(dx.meansKey===key)?dx.means:null;
    const rows=(mb&&mb.groups.length)?mb.groups.map(g=>`<tr><td class="dx-name">${esc(g.group)}</td><td>${g.n}</td><td>${_n2(g.mean)}</td><td>${_n2(g.sd)}</td><td class="${g.delta<0?'dx-neg':'dx-pos'}">${g.delta>0?'+':''}${_n2(g.delta)}</td></tr>`).join(''):`<tr><td colspan="5">${(dx.mbusy||dx.meansKey!==key)?'Computing…':'No group means to show.'}</td></tr>`;
    const numName=(nu.find(x=>x.id===dx.mNum)||{}).name||'';
    t2html=`<div class="panel"><div class="panel-h"><div><h3>Table 2 · Mean of <b>${esc(numName)}</b> by group</h3></div>
        <div style="${_PICK}">${dxSel(dx.mNum,nu,'setMeansNum(this.value)')}<span class="ed-l" style="margin:0">by</span>${dxSel(dx.mGrp,cats,'setMeansGrp(this.value)')}</div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>Δ from overall</th></tr></thead>
        <tbody>${rows}</tbody></table></div></div></div>`;
  }
  let hi=null,lo=null; nu.forEach(r=>{if(hi==null||r.mean>hi.mean)hi=r;if(lo==null||r.mean<lo.mean)lo=r;});
  $("#centerInner").innerHTML=descHead('Means & distributions','Means & Distributions',expl?'Summarize the quantitative pattern first — this is what later needs qualitative explanation.':'Summarize the quantitative pattern first.')+helpBar('Means & Distributions')+`
    <div class="panel"><div class="panel-h"><div><h3>Table 1 · Summary statistics for numeric variables</h3></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Min</th><th>Max</th><th>Missing</th></tr></thead>
        <tbody>${t1}</tbody></table></div></div></div>
    ${t2html}
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">${(hi&&lo&&hi!==lo)?`Across ${nu.length} numeric variables, "${esc(hi.name)}" had the highest average (M = ${_n2(hi.mean)}) and "${esc(lo.name)}" the lowest (M = ${_n2(lo.mean)}).`:(hi?`"${esc(hi.name)}" had a mean of ${_n2(hi.mean)}.`:'No numeric variables to summarize.')}</div></div>
      <div class="dx-l"><div class="dx-l-k">Why this matters</div><div class="dx-l-t">${expl?'In an Explanatory Sequential design, these averages help identify which quantitative patterns may need qualitative explanation.':'These averages set up the comparison your qualitative strand will speak to.'}</div></div>
      ${expl?`<div class="dx-l dx-q"><div class="dx-l-k">Possible follow-up question</div><div class="dx-l-t">What experiences might help explain the differences in these averages between groups?</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">These are averages. They do not test whether differences are statistically significant or explain why they exist.</div></div>
    </div>
    ${navFooter()}`;
}
function setXr(v){dx.xr=+v;renderDescriptive(activeStep());}
function setXc(v){dx.xc=+v;renderDescriptive(activeStep());}
function renderCrossTabs(s){
  const expl=state.design==='explanatory';
  const allCats=dx.base.categorical||[];
  const openExcluded=allCats.filter(c=>isOpenEndedCat(c));
  const cats=allCats.filter(c=>!isOpenEndedCat(c));
  const openNote=openExcluded.length?`<div class="dm-note" style="margin-bottom:12px"><b>${openExcluded.map(c=>esc(c.name)).join(', ')}</b> excluded from cross-tab — open-ended responses produce one column per unique verbatim string, which is uninterpretable. Use the coded theme variable (e.g. Coder1_Theme_${openExcluded[0]?openExcluded[0].name.replace(/^Q\d+_/,''):'Q'}) as the column instead, or see the Qualitative strand.</div>`:'';
  // Reset row/col pointers if they landed on an excluded variable
  if(dx.xr!=null&&!cats.find(c=>c.id===dx.xr))dx.xr=cats.length?cats[0].id:null;
  if(dx.xc!=null&&!cats.find(c=>c.id===dx.xc)){const alt=cats.find(c=>c.id!==dx.xr);dx.xc=alt?alt.id:(cats.length?cats[0].id:null);}
  if(cats.length<2){$("#centerInner").innerHTML=descMsg('Cross-tabs','Cross-Tabs','Compare categories across groups before testing patterns.',openExcluded.length?'Cross-tabs need at least two categorical variables. Open-ended response columns are excluded — use coded theme variables from the Qualitative strand instead.':'Cross-tabs need at least two categorical variables; this dataset has fewer.')+openNote;return;}
  if(dx.xr===dx.xc){const alt=cats.find(c=>c.id!==dx.xr); if(alt)dx.xc=alt.id;}
  const pick=openNote+`<div style="${_PICK}"><label class="ed-l" style="margin:0">Rows</label>${dxSel(dx.xr,cats,'setXr(this.value)')}<label class="ed-l" style="margin:0 0 0 12px">Columns</label>${dxSel(dx.xc,cats,'setXc(this.value)')}</div>`;
  const key=dx.xr+'x'+dx.xc;
  if(dx.xtabKey!==key&&!dx.xbusy){dx.xbusy=true;descFetch({xtab_row:dx.xr,xtab_col:dx.xc}).then(j=>{dx.xbusy=false;dx.xtabKey=key;dx.xtab=(j&&j.ok&&j.crosstab)?j.crosstab:null;renderDescriptive(activeStep());}).catch(()=>{dx.xbusy=false;dx.xtabKey=key;dx.xtab=null;renderDescriptive(activeStep());});}
  const ct=(dx.xtabKey===key)?dx.xtab:null;
  let table;
  if(dx.xbusy||dx.xtabKey!==key){table=`<div class="work-surface" style="border-radius:16px">Computing cross-tabulation…</div>`;}
  else if(!ct||!ct.matrix.length){table=`<div class="work-surface" style="border-radius:16px">Not enough overlapping data to cross-tabulate these two variables.</div>`;}
  else{
    const th=ct.col_labels.map(l=>`<th>${esc(l)} n</th><th>${esc(l)} %</th>`).join('');
    const body=ct.matrix.map(row=>{const cells=row.cells.map(c=>`<td>${c.count}</td><td>${_pc(c.row_pct)}</td>`).join('');return `<tr><td class="dx-name">${esc(row.label)}</td>${cells}<td>${row.total}</td></tr>`;}).join('');
    const tot=ct.col_totals.map(t=>`<td>${t}</td><td>${_pc(ct.grand?100*t/ct.grand:0)}</td>`).join('');
    table=`<div class="panel"><div class="panel-h"><div><h3>Table 1 · ${esc(ct.row_var)} × ${esc(ct.col_var)}</h3></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">${esc(ct.row_var)}</th>${th}<th>Total</th></tr></thead>
        <tbody>${body}<tr class="dx-total"><td class="dx-name">Total</td>${tot}<td>${ct.grand}</td></tr></tbody></table></div></div></div>`;
  }
  $("#centerInner").innerHTML=descHead('Cross-tabs','Cross-Tabs','Compare categories across groups before testing patterns.')+helpBar('Cross-Tabs')+pick+table+`
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">Each row shows how that group is split across the column categories (percentages are within the row).</div></div>
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">This table shows a relationship between two categorical variables, but does not yet test whether it is statistically significant.</div></div>
      ${expl?`<div class="dx-l dx-q"><div class="dx-l-k">Possible qualitative follow-up</div><div class="dx-l-t">What experiences help explain the pattern between ${ct?esc(ct.row_var):'these groups'} and ${ct?esc(ct.col_var):'the outcome'}?</div></div>`:''}
    </div>
    <div class="dx-next"><div class="dx-next-k">↳ Recommended next step</div><div class="dx-next-t">Run <b>Chi-square</b> to test whether these two variables are related.</div><button class="btn primary" style="margin-left:auto;padding:7px 14px;font-size:12.5px" onclick="toast('Chi-square — coming to the inferential engine')">Run Chi-square →</button></div>
    ${navFooter()}`;
}
/* ===== Independent Samples t-Test (real data via /api/mm/ttest.php) ===== */
const tt={grouping:null,testType:'auto',conf:0.95,result:null,tab:'desc',busy:false,added:false};
function fmt(n,d){return (n==null||isNaN(n))?'—':Number(n).toFixed(d==null?2:d);}
function ttGroupOpts(g){return (g?g.groups:[]).map(o=>{const lab=g&&g.name?vlabel(g.name,o.value):null;return `<option value="${esc(o.value)}">${esc(lab||o.value)} (n=${o.n})</option>`;}).join('');}
function renderTTest(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]};
  const _ttGroupings=cleanGroupings();
  if(!BOOT.projectId||!V.datasetReady||!V.outcomes.length||!_ttGroupings.length){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Group comparisons <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">Independent Samples t-Test</h1><p class="lede">Compare the mean of one outcome across two groups.</p></div>
      <div class="work-surface" style="border-radius:16px">No structured dataset with numeric and categorical variables is available for this project yet. Build or connect data from Start, then return here.</div>`;
    return;
  }
  if(tt.grouping==null||!_ttGroupings.find(g=>g.id===tt.grouping)) tt.grouping=_ttGroupings[0].id;
  const grp=_ttGroupings.find(g=>g.id===tt.grouping)||_ttGroupings[0];
  const seg=(k,l)=>`<button class="tt-seg ${tt.testType===k?'on':''}" onclick="tt.testType='${k}';renderTTest(activeStep())">${l}</button>`;
  const vlRows=(grp.groups||[]).map(o=>`<div style="display:flex;gap:8px;align-items:center;margin:4px 0"><span style="min-width:90px;font-size:12.5px;color:var(--ink-3)">${esc(o.value)}</span><input class="ed-in vlab-in" data-var="${esc(grp.name)}" data-val="${esc(o.value)}" style="max-width:260px" value="${esc(vlabel(grp.name,o.value)||'')}" placeholder="label for ${esc(o.value)}"></div>`).join('');
  const vlEditor=`<details style="margin:8px 0 0;grid-column:1/-1"><summary style="cursor:pointer;font-weight:600;font-size:12.5px;color:var(--btn)">▸ Label the values of "${esc(grp.name)}" — shown everywhere instead of codes</summary><div style="margin-top:8px;padding:10px;border:1px solid var(--line);border-radius:10px">${vlRows}<div class="dm-note" style="margin:6px 0">Leave blank to keep the raw code. Applies across the whole studio, including saved results.</div><button class="btn" onclick="vlabSave()">Save value labels</button></div></details>`;
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and the two groups to compare</div></div></div>
      <div class="panel-b">
        <div class="tt-grid">
          <div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="ttOut">${V.outcomes.map(o=>`<option value="${o.id}">${esc(o.name)}</option>`).join('')}</select></div>
          <div class="field"><label>Grouping variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="ttGrp" onchange="tt.grouping=+this.value;renderTTest(activeStep())">${_ttGroupings.map(g=>`<option value="${g.id}" ${g.id===tt.grouping?'selected':''}>${esc(g.name)}</option>`).join('')}</select></div>
          <div class="field"><label>Group 1</label><select class="ed-in" id="ttG1" onchange="ttFixG2()">${ttGroupOpts(grp)}</select></div>
          <div class="field"><label>Group 2</label><select class="ed-in" id="ttG2" onchange="ttWarnSame()">${ttGroupOpts(grp)}</select></div>
          <div id="ttSameWarn" style="display:none;color:#8a6418;font-size:12.5px;font-weight:600;margin-top:-8px">Group 1 and Group 2 are the same — pick two different groups before running.</div>
          <div class="field"><label>Test type</label><div class="tt-segs">${seg('auto','Auto')}${seg('welch','Welch')}${seg('student','Student')}</div><div class="tt-hint">Welch is the default — safer when sizes or variances differ.</div></div>
          <div class="field"><label>Confidence</label><select class="ed-in" id="ttConf"><option value="0.90">90%</option><option value="0.95" selected>95%</option><option value="0.99">99%</option></select></div>
          ${vlEditor}
        </div>
        <div class="run-actions"><button class="btn primary" onclick="runTTest()" ${tt.busy?'disabled':''}>${tt.busy?'Running…':'▷ Run t-test'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Group comparisons <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Independent Samples t-Test</h1><p class="lede">Compare the mean of one outcome across two groups.</p></div>
    ${helpBar('t-test')}
    ${setup}
    <div id="ttResults">${tt.result?renderTTestResults(tt.result):''}</div>`;
  // Ensure Group 2 defaults to a different category than Group 1.
  ttFixG2();
}
/* ---- Analysis help registry: one entry per analysis, used by both the
   "How to use this" button (steps + worked example) and the ReliCheck Coach
   explainer (what it is / what it measures / when to use it). Plain language,
   no em dashes. Keyed by palette tool name (quant tests, descriptives) or by
   step id (qualitative and other steps). ---- */
const AHELP={
  'q2q':{title:`Qual → Quant`,
    what:`Qual → Quant turns the themes you built from open-ended responses into measurable variables, so the quantitative phase can test them.`,
    measures:`For each theme it can create a per-respondent variable: theme presence (was the theme in their response, 0/1), theme intensity (how strongly, 0 to 3), plus sentiment and response length. These become numeric columns you can run tests on.`,
    use:`Use it in an exploratory design once you have themes: quantitize them, then test them (for example, does a theme appear more in one group?) on Build & Test Measures. The results flow back into the Joint Display.`,
    steps:[`<b>Choose what to create</b> from your themes — presence is the usual starting point.`,`<b>Create the measurable variables.</b>`,`<b>Go to Build & Test Measures</b> to test them.`,`<b>Check the Joint Display</b> — the statistical result now links back to each theme.`],
    example:`<p><b>Reading it:</b> turning "Equity and access gaps" into a 0/1 presence variable lets you test whether that theme appears more often for one group, and that result then sits beside the theme in the joint display.</p>`},
  'report':{title:`Report Builder`,
    what:`The report builder assembles your whole mixed methods study — methods, qualitative and quantitative results, integration, and recommendations — into one editable write-up.`,
    measures:`It pulls each section from your analysis: templated sections (methods, results) fill from your data, and the ReliCheck Intelligence sections (executive summary, integration, recommendations) draft from your findings. You can edit every section yourself.`,
    use:`Use it last: build the full report, then read and edit each section so it reads in your voice before you export or share it.`,
    steps:[`<b>Build the full report</b> to draft every section.`,`<b>Read each section</b> and edit it in your own words.`,`<b>Regenerate any section</b> you want redone.`,`<b>Save</b> your edits.`],
    example:`<p><b>Reading it:</b> the Integration section weaves your themes and statistical results into prose; you tighten it, and the Methods section already lists your sample and coding approach from the data.</p>`},
  'evidence_strength':{title:`Evidence Strength`,
    what:`This step gauges how strong your integrated mixed methods evidence is before you report it — a set of checks across the quantitative, qualitative, and integration work.`,
    measures:`It runs checks on things like sample size, coding coverage, quote quality, and whether the strands were integrated, and flags each as pass, needs a fix, or not yet run.`,
    use:`Run it near the end: address the high-severity fixes before you write the report, so your claims are well supported.`,
    steps:[`<b>Run the strength checks.</b>`,`<b>Read each result</b> — pass, review, or fix.`,`<b>Fix the high-severity items</b> first.`,`<b>Re-run</b> to confirm, then report.`],
    example:`<p><b>Reading it:</b> a "Fix" on coding coverage tells you several themes have too few tagged responses to support strong claims — worth addressing before the report.</p>`},
  'interp':{title:`Integrated Interpretation`,
    what:`Integrated interpretation is where you say, theme by theme, what the combined quantitative and qualitative evidence means — weaving the numbers and the narratives into one reading.`,
    measures:`For each theme it gathers the evidence (how often it appears, its sentiment, any statistical result, and a quote) and gives you a paragraph to interpret what it all means for your decision.`,
    use:`Use it after convergence: write a short integration paragraph per theme that a reader could act on. These paragraphs become the body of your report.`,
    steps:[`<b>Read each theme's evidence</b> across both strands.`,`<b>Write what it means</b> for the decision in a short paragraph.`,`<b>Optionally let ReliCheck Intelligence draft</b> a starting paragraph to edit.`,`<b>Save</b> each; they flow into the report.`],
    example:`<p><b>Reading it:</b> for an equity theme, the paragraph might note it is frequent and negative, a test shows lower scores for under-resourced groups, and a quote illustrates the lived experience — so the recommendation is targeted investment.</p>`},
  'meta':{title:`Meta-inferences`,
    what:`Meta-inferences are the higher-order conclusions that only the combined quantitative and qualitative evidence supports — the takeaways neither strand could establish on its own.`,
    measures:`It gathers your per-theme convergence readings and gives you a space to write the study's overarching inferences, which then carry into the report.`,
    use:`Use it last in the analysis, after convergence and divergence: step back from the individual themes and name what the whole study concludes.`,
    steps:[`<b>Review</b> your convergence readings.`,`<b>Ask what the combined evidence shows</b> that the numbers or the narratives alone could not.`,`<b>Write each meta-inference</b> in plain language.`,`<b>Save;</b> these flow into the Report Builder.`],
    example:`<p><b>Reading it:</b> that equity concern is frequent in comments and that scores are lower for under-resourced groups combine into the meta-inference that access gaps are both felt and measured — a stronger claim than either alone.</p>`},
  'qual_sampling':{title:`Qualitative Sampling Plan`,
    what:`The sampling plan decides who you follow up with qualitatively, and what you ask them, so the qualitative phase is aimed squarely at explaining the quantitative results you selected.`,
    measures:`It pulls the findings you staged in "Identify Results to Explain" and your grouping variables, then gives you a space to write who to sample, how many, and what to ask. The plan saves into the report's Methods section.`,
    use:`Use it right after you pick your results to explain: name the groups whose differences you want to understand, decide how many people to talk to, and draft the questions that target those differences.`,
    steps:[`<b>Review</b> the staged results and the groups in your data.`,`<b>Scaffold</b> a draft plan from your findings, or write your own.`,`<b>Name who to sample</b> — purposively include the contrasting groups.`,`<b>Draft what to ask</b>, then save; the plan flows into the Report Builder's Methods.`],
    example:`<p><b>Reading it:</b> if SEL-trained staff scored higher, your plan might sample a few trained and a few untrained participants and ask each what their training did or did not change in practice.</p>`},
  'explain_map':{title:`Quant → Qual Explanation Map`,
    what:`The explanation map links each quantitative result you chose to explain to the qualitative theme that accounts for it, with a sentence on how — the core move of an explanatory sequential design.`,
    measures:`It lays out your staged results next to your themes and lets you record, per result, which theme explains it and why. The map saves into the report's Integration section.`,
    use:`Use it after you have themes and have staged results to explain: for each result, pick the theme that explains it and write the connection. These links become your integration narrative.`,
    steps:[`<b>Read each staged result.</b>`,`<b>Pick the theme</b> that best explains it.`,`<b>Write how</b> the theme accounts for the result.`,`<b>Save;</b> the map flows into the Report Builder's Integration section.`],
    example:`<p><b>Reading it:</b> if SEL-trained staff scored higher, you might link that result to a "relationship as SEL foundation" theme and note that trained staff described building trust more deliberately.</p>`},
  'converge':{title:`Convergence & Divergence`,
    what:`This step puts each theme's quantitative and qualitative evidence side by side so you can name where the two strands agree (converge), add to each other (nuanced), or contradict (diverge).`,
    measures:`For each theme it shows the quantitative picture (how often it appears and any statistical result) next to the qualitative picture (sentiment and a quote), and lets you record your reading of how they align.`,
    use:`Use it after the Joint Display: read each theme across both strands and write where they converge or diverge. Convergence strengthens a finding; divergence is often the most interesting result to explain.`,
    steps:[`<b>Read each theme</b> across its quantitative and qualitative evidence.`,`<b>Name the relationship</b> — converge, nuanced, or diverge.`,`<b>Write why</b> in your own words.`,`<b>Save</b> each reading; it carries into the report.`],
    example:`<p><b>Reading it:</b> if a theme is frequent, negative in tone, and a test shows lower scores for one group, the strands converge; if the numbers are flat but the theme is strongly negative, they diverge — worth explaining.</p>`},
  'joint':{title:`Joint Displays`,
    what:`A joint display lays your quantitative and qualitative evidence side by side, one row per theme, so you can see how the numbers and the narratives line up.`,
    measures:`For each theme it shows the quantitative picture (how often it appears, its intensity, and the strongest statistical result tied to it) next to the qualitative picture (the sentiment balance and a representative participant quote).`,
    use:`Use it to integrate the two strands: where a number and a theme tell the same story you have convergence; where they disagree you have something to explain.`,
    steps:[`<b>Read each row</b> as one theme across both strands.`,`<b>Compare the quantitative result</b> with the theme's quote and sentiment.`,`<b>Pick representative quotes</b> to bring each theme to life.`,`<b>Note where strands agree or diverge</b> for the next step.`],
    example:`<p><b>Reading it:</b> if "Equity and access gaps" appears in 40% of responses with negative sentiment, and the t-test shows lower scores for under-resourced schools, the number and the narrative converge on the same finding.</p>`},
  'data_map':{title:`Data Map`,
    what:`The Data Map organizes your dataset into analysis roles before any statistics or theme discovery runs. It identifies which variables are identifiers, demographics, quantitative items, Likert scales, and open-ended responses, and how the quantitative and qualitative strands connect.`,
    measures:`It classifies every variable, proposes a role and strand for each, shows the qualitative-to-quantitative integration links, and reports which mixed methods designs the dataset can support. It organizes the dataset; it does not evaluate it. Evaluation (missingness, response quality, readiness, risk) is Data Quality, the next step.`,
    use:`Use it first, right after upload. Confirm or revise each variable's role, group Likert items into constructs, classify the open-ended questions, and confirm the integration links. A strong Data Map makes later analysis more trustworthy because each variable has a clear purpose.`,
    steps:[`<b>Review the summary cards</b> and the Overview table.`,`<b>Open Variable Roles</b> and confirm or change each assigned role.`,`<b>Group Likert items</b> into constructs on the Quantitative Strand tab.`,`<b>Classify open-ended questions</b> on the Qualitative Strand tab.`,`<b>Confirm the Integration Links,</b> then check Design Fit.`,`<b>Save variable roles,</b> then continue to Data Quality.`],
    example:`<p><b>Reading it:</b> a dataset with a respondent ID, four demographics, ten Likert items, and seven open-ended questions shows Integration Strength = Strong, meaning each respondent carries both numbers and narratives and the data supports case-level mixed methods integration.</p>`},
  'data_quality':{title:`Data Quality`,
    what:`A data-quality check looks for problems in your responses (duplicates, careless answering, impossible values, missing data) before you run any analysis, so your results are not driven by bad data.`,
    measures:`It runs seven checks on your dataset and scores it out of 100: duplicate rows, duplicate IDs, straight-lining on Likert items, numeric outliers, invalid (non-numeric) values, low-effort open-ends, and high item-level missingness. Each check is flagged as clean, a warning, or an alert.`,
    use:`Run it once your data is uploaded and before you interpret any test. Resolve the alerts first (they can invalidate results), then inspect the warnings.`,
    steps:[`<b>Read the score</b> at the top — 95+ is clean, below 70 means significant issues.`,`<b>Scan the alerts</b> (red) first: duplicates and invalid values can distort every result.`,`<b>Check the warnings</b> (amber): outliers, straight-lining, and missingness to inspect.`,`<b>Fix what you can</b> in your source data, then re-open this step to re-run.`,`<b>Note the rest</b> you cannot fix, and read results with that caution in mind.`],
    example:`<p><b>Reading it:</b> a score of 80/100 with one alert ("3 duplicate rows") and one warning ("Belonging — 12 outliers") means you should remove the duplicate rows before analysis and look at whether those extreme Belonging values are real or data-entry errors.</p>`},
  't-test':{title:`Independent Samples t-Test`,
    what:`A t-test checks whether the average of one number is really different between two groups, or whether the gap could just be random variation in the data.`,
    measures:`It compares two group averages and reports the direction of the gap, whether it is statistically reliable (the p-value), and how large it is (the effect size, Cohen's d).`,
    use:`Use it when your outcome is a number (a score or rating) and you are comparing exactly two groups, such as two roles, two sites, or two conditions. For three or more groups, use ANOVA.`,
    steps:[`<b>Pick the Outcome.</b> The number you want to compare, such as a score or rating.`,`<b>Pick the Grouping variable.</b> The category that splits people into groups.`,`<b>Choose the two groups</b> for Group 1 and Group 2.`,`<b>Leave Test type on Auto</b> (Welch) unless you have a reason to change it.`,`<b>Set Confidence</b> (95% is standard).`,`<b>Click Run t-test</b> and read the four tabs: Descriptives, Result, Effect size, and Reporting.`],
    example:`<p><b>Question:</b> Does job performance differ between individual contributors and managers?</p><p><b>Setup:</b> Outcome = Job Performance, Grouping = Role Level, Group 1 = Individual Contributor, Group 2 = Manager.</p><p><b>Reading it:</b> a result like t(69) = -2.46, p = .017, d = -0.46 means managers scored higher, the difference is unlikely to be chance, and the gap is moderate.</p>`},
  'ANOVA':{title:`One-Way ANOVA`,
    what:`ANOVA checks whether the averages of one number differ across three or more groups, instead of just two.`,
    measures:`It tests whether at least one group average stands apart from the others (the F test and its p-value), and follow-up comparisons show which groups differ.`,
    use:`Use it when your outcome is a number and your grouping has three or more categories, such as four departments or three class levels. For exactly two groups, a t-test is simpler.`,
    steps:[`<b>Pick the Outcome</b> (the number to compare).`,`<b>Pick the Grouping variable</b> with three or more categories.`,`<b>Run the test</b> to get the overall F and p-value.`,`<b>If significant,</b> review which specific groups differ.`,`<b>Read the effect size</b> to judge how much the groups differ.`],
    example:`<p><b>Question:</b> Does belonging differ across first-year, sophomore, junior, and senior students?</p><p><b>Setup:</b> Outcome = Belonging, Grouping = Class Level (four groups).</p><p><b>Reading it:</b> a significant F (p below .05) means at least one class level differs; the follow-up shows which ones.</p>`},
  'Chi-square':{title:`Chi-Square Test of Independence`,
    what:`Chi-square checks whether two category variables are related, or whether they vary independently of each other.`,
    measures:`It compares the counts you observed with the counts you would expect if the two variables were unrelated, and reports whether the difference is bigger than chance.`,
    use:`Use it when both variables are categories (not numbers), such as role by department, or pass/fail by site. It works on the counts in a cross-tab.`,
    steps:[`<b>Pick the two category variables.</b>`,`<b>Review the cross-tab</b> of counts.`,`<b>Run the test</b> to get the chi-square value and p-value.`,`<b>A small p-value</b> means the two variables are related.`,`<b>Look at the cells</b> to see where the relationship is strongest.`],
    example:`<p><b>Question:</b> Is student classification related to first-generation status?</p><p><b>Setup:</b> Rows = Class Level, Columns = First-Generation status.</p><p><b>Reading it:</b> a small p-value means the mix of first-generation students changes by class level.</p>`},
  'Correlation':{title:`Correlation`,
    what:`Correlation measures whether two numbers move together, and how strongly.`,
    measures:`It reports a number from -1 to +1 (Pearson's r): positive means they rise together, negative means one rises as the other falls, and near zero means little linear relationship.`,
    use:`Use it when you have two numeric variables and want to see if they are linked, such as advising satisfaction and intent to persist. A link is not proof that one causes the other.`,
    steps:[`<b>Pick the two numeric variables.</b>`,`<b>Run the test</b> to get r and its p-value.`,`<b>Check the sign of r</b> for direction and its size for strength.`,`<b>Read the p-value</b> to see if the link is reliable.`,`<b>Remember</b> that a link is not proof of cause.`],
    example:`<p><b>Question:</b> Do advising satisfaction and intent to persist move together?</p><p><b>Setup:</b> Variable 1 = Advising Satisfaction, Variable 2 = Intent to Persist.</p><p><b>Reading it:</b> r = .42, p below .05 means a moderate positive link.</p>`},
  'Regression':{title:`Linear Regression`,
    what:`Regression estimates how one or more predictor variables relate to a numeric outcome, and lets you predict the outcome from the predictors.`,
    measures:`It reports how much each predictor moves the outcome (the coefficients), whether each is reliable (p-values), and how much of the outcome the model explains (R-squared).`,
    use:`Use it when your outcome is a number and you want to weigh one or several predictors at once, such as predicting belonging from advising and classroom inclusion.`,
    steps:[`<b>Pick the numeric Outcome.</b>`,`<b>Pick one or more predictors.</b>`,`<b>Run the model.</b>`,`<b>Read each predictor's coefficient</b> and p-value.`,`<b>Read R-squared</b> to see how much the model explains.`],
    example:`<p><b>Question:</b> Do advising and classroom inclusion predict belonging?</p><p><b>Setup:</b> Outcome = Belonging, Predictors = Advising, Classroom Inclusion.</p><p><b>Reading it:</b> a positive, significant coefficient means that predictor raises belonging, holding the other constant.</p>`},
  'Reliability':{title:`Scale Reliability (Cronbach's alpha)`,
    what:`Reliability checks whether a set of items meant to measure the same idea actually hang together as one consistent scale.`,
    measures:`It reports Cronbach's alpha, a number from 0 to 1: higher means the items agree with each other. It also flags items that weaken the scale.`,
    use:`Use it before you average several items into one score, such as five belonging questions, to confirm they belong together. In this studio, reliability is referenced read-only from RSSI.`,
    steps:[`<b>Pick the items</b> that form the scale.`,`<b>Run the reliability check</b> to get alpha.`,`<b>Aim for alpha around .70 or higher</b> for a usable scale.`,`<b>Review flagged items</b> that lower alpha.`,`<b>Decide</b> whether to drop or revise weak items.`],
    example:`<p><b>Question:</b> Do the five belonging items form one reliable scale?</p><p><b>Setup:</b> Items = the five belonging questions.</p><p><b>Reading it:</b> alpha = .83 means the items are consistent; an item that would raise alpha if removed may not fit.</p>`},
  'Frequencies':{title:`Frequencies`,
    what:`A frequency table counts how many responses fall into each category of one variable.`,
    measures:`It shows the count, the percent of the whole, the valid percent (excluding missing), and the running cumulative percent for each category.`,
    use:`Use it to see how a sample is distributed across a category, such as class level or role, before comparing groups.`,
    steps:[`<b>Pick the category variable</b> from the dropdown.`,`<b>Read the count and percent</b> for each category.`,`<b>Check the Missing row</b> to see how complete the data is.`,`<b>Use cumulative percent</b> to see how categories stack up.`],
    example:`<p><b>Example:</b> Picking Class Level might show first-year students are 35% of valid responses, the largest group.</p>`},
  'Means & Distributions':{title:`Means & Distributions`,
    what:`This view summarizes each numeric variable with its average and spread, and can break an average down by group.`,
    measures:`For each number it reports N, mean, standard deviation (spread), minimum, maximum, and missing count. The second table shows a chosen number's mean within each group and the gap from the overall average.`,
    use:`Use it to describe the quantitative pattern before testing it, and to spot which groups sit above or below average.`,
    steps:[`<b>Read Table 1</b> for the average and spread of each number.`,`<b>In Table 2,</b> pick a number and a grouping to compare averages across groups.`,`<b>Note groups</b> that sit well above or below the overall average.`,`<b>Carry promising gaps</b> into a t-test or ANOVA to test them.`],
    example:`<p><b>Example:</b> Belonging might average 3.18 overall, while the by-group table shows first-generation students lower than continuing-generation students.</p>`},
  'Cross-Tabs':{title:`Cross-Tabs`,
    what:`A cross-tab shows how two category variables combine, by counting responses in each row-and-column cell.`,
    measures:`It reports the count in each cell and the within-row percent, so you can see how each row group splits across the columns.`,
    use:`Use it to look for a relationship between two categories before testing it, such as class level by first-generation status.`,
    steps:[`<b>Pick a Rows variable and a Columns variable.</b>`,`<b>Read each cell's count</b> and within-row percent.`,`<b>Compare rows</b> to see if the column split changes.`,`<b>If a pattern appears,</b> run Chi-square to test it.`],
    example:`<p><b>Example:</b> Class Level by First-Generation status might show first-generation students concentrated in earlier years.</p>`},
  'Group Summaries':{title:`Group Summaries`,
    what:`Group summaries compare a numeric variable's average across the categories of a grouping variable.`,
    measures:`For each group it reports the count, mean, spread, and how far the group sits from the overall average.`,
    use:`Use it to see which groups are higher or lower before running a formal test.`,
    steps:[`<b>Pick the number</b> to summarize and the grouping.`,`<b>Compare each group's mean</b> and its gap from overall.`,`<b>Note the largest gaps.</b>`,`<b>Test a gap</b> with a t-test (two groups) or ANOVA (three or more).`],
    example:``},
  'l_themes':{title:`Qualitative Themes`,
    what:`Themes are the recurring ideas you build from open-ended responses by reading, coding, and grouping what people said.`,
    measures:`Themes capture meaning, not counts. Each theme is defined by a clear label, the codes beneath it, and the quotes that support it.`,
    use:`Use this to turn raw comments into a small set of well-supported themes that explain the patterns your numbers show.`,
    steps:[`<b>Open a theme</b> to read its coded responses.`,`<b>Add or refine codes</b> as you read.`,`<b>Merge or split themes</b> so each holds one clear idea.`,`<b>Confirm each theme</b> has enough supporting quotes.`,`<b>Keep a codebook</b> so coding stays consistent.`],
    example:``},
  'l_book':{title:`Codebook & Evidence`,
    what:`A codebook is the rulebook for your qualitative analysis: the list of codes, what each one means, and the evidence behind each theme.`,
    measures:`It documents code definitions, coding rules, and how codes co-occur, so another reader could apply them the same way.`,
    use:`Use it to keep coding consistent and to make your qualitative work transparent and defensible.`,
    steps:[`<b>List each code</b> with a clear definition.`,`<b>Write rules</b> for when a code applies and when it does not.`,`<b>Link codes</b> to the themes they build.`,`<b>Review the co-occurrence matrix</b> to see which codes appear together.`],
    example:``},
  'l_trust':{title:`Trustworthiness`,
    what:`Trustworthiness is the qualitative parallel to instrument quality: the steps that make your qualitative findings credible.`,
    measures:`It documents practices such as an audit trail, member checking, and coding agreement (kappa) between coders.`,
    use:`Use it to show your qualitative results are rigorous, not just one reader's impression.`,
    steps:[`<b>Keep an audit trail</b> of analysis decisions.`,`<b>Check interpretations</b> with participants where possible (member checking).`,`<b>Use a second coder</b> and report coding agreement.`,`<b>Note limitations</b> honestly.`],
    example:``},
  'l_exemp':{title:`Exemplar Quotes`,
    what:`Exemplar quotes are the specific participant statements that best represent each theme.`,
    measures:`Good exemplars are clear, on-theme, and varied, and are tied back to the theme they illustrate.`,
    use:`Use them to bring themes to life in the report and to ground each claim in participants' own words.`,
    steps:[`<b>Open a theme</b> and scan its coded quotes.`,`<b>Pick quotes</b> that clearly capture the theme.`,`<b>Choose a range of voices,</b> not just one.`,`<b>Trim quotes</b> for length while keeping meaning.`,`<b>Label each quote</b> with its theme.`],
    example:``},
  'l_bygroup':{title:`Theme by Group`,
    what:`This view shows how each theme appears across the same groups your quantitative side compared.`,
    measures:`It shows where a theme is common or rare by group, so you can connect the qualitative pattern to the numeric one.`,
    use:`Use it to see whether a theme that explains a result shows up more in one group than another.`,
    steps:[`<b>Pick the grouping</b> that matches your quantitative comparison.`,`<b>See how each theme distributes</b> across the groups.`,`<b>Note themes concentrated</b> in one group.`,`<b>Link these</b> to the group differences in your numbers.`],
    example:``},
  'q_instr':{title:`Instrument Quality`,
    what:`Instrument quality confirms your measure is sound before you trust its scores, mainly through reliability.`,
    measures:`It references reliability (read-only from RSSI) and scale composition, showing whether the items form dependable scales.`,
    use:`Use it to make sure the numbers you analyze come from a trustworthy instrument.`,
    steps:[`<b>Review the reliability</b> brought in from RSSI.`,`<b>Check which items</b> make up each scale.`,`<b>Note any weak scales</b> before interpreting results.`],
    example:``},
  'q_build':{title:`Build & Test Measures`,
    what:`This step takes measures you derived from qualitative themes and tests them on the sample.`,
    measures:`It checks item performance and scale reliability for the new measures, then confirms them with a t-test and effect sizes.`,
    use:`Use it in a qualitative-to-quantitative design, after you have turned themes into items, to see whether the new measures hold up.`,
    steps:[`<b>Review item performance</b> for the new measures.`,`<b>Check scale reliability</b> (alpha).`,`<b>Run a t-test</b> to confirm expected group differences.`,`<b>Read effect sizes</b> to judge how large they are.`],
    example:``}
};
function helpKey(s){const t=currentTool(s);if((s.id==='q_inf'||s.id==='q_desc')&&t)return t.name;return s.id;}
function helpBar(key){return AHELP[key]?`<div style="display:flex;gap:8px;margin:0 0 14px"><button class="btn" onclick="openToolHelp('${String(key).replace(/'/g,"\\'")}')"><span style="margin-right:6px">📘</span>How to use this</button></div>`:'';}
function openToolHelp(key){
  const h=AHELP[key]; if(!h){toast('No guide for this one yet.');return;}
  const steps=(h.steps||[]).map(x=>`<li style="margin-bottom:8px">${x}</li>`).join('');
  const ex=h.example?`<div style="background:#f5f6f8;border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.65"><div class="cb-k" style="margin-bottom:8px">Worked example</div>${h.example}</div>`:'';
  $("#modal").innerHTML=`<div class="modal-h"><h3>How to use ${esc(h.title)}</h3><button class="mx" onclick="closeModal()">✕</button></div>
    <div class="modal-b">
      <p class="lede" style="font-size:14px;margin-bottom:14px">${h.what}</p>
      ${steps?`<ol style="margin:0 0 16px;padding-left:20px;line-height:1.7;font-size:13.5px">${steps}</ol>`:''}
      ${ex}
      <div class="ed-foot"><button class="btn primary" onclick="closeModal()">Got it</button></div>
    </div>`;
  $("#modalScrim").classList.add('open');
}
function toolCoachBlock(key){
  const h=AHELP[key]; if(!h) return '';
  return `<div class="comp-block"><div class="cb-k"><span class="i">i</span> What it is</div><div class="cb-t">${h.what}</div></div>
    <div class="comp-block"><div class="cb-k"><span class="i">M</span> What it measures</div><div class="cb-t">${h.measures}</div></div>
    <div class="comp-block"><div class="cb-k"><span class="i">✓</span> When to use it</div><div class="cb-t">${h.use}</div></div>`;
}
function ttFixG2(){
  // When Group 1 changes (or on initial render), auto-advance Group 2 to the
  // first option that differs from Group 1 so the default is never self-vs-self.
  const g1=$("#ttG1"),g2=$("#ttG2");
  if(!g1||!g2)return;
  if(g2.value===g1.value){
    const alt=Array.from(g2.options).find(o=>o.value!==g1.value);
    if(alt)g2.value=alt.value;
  }
  ttWarnSame();
}
function ttWarnSame(){
  const g1=$("#ttG1"),g2=$("#ttG2"),w=$("#ttSameWarn");
  if(!g1||!g2||!w)return;
  w.style.display=g1.value===g2.value?'block':'none';
}
function runTTest(){
  const V=BOOT.ttvars;
  const body={project_id:BOOT.projectId,outcome_id:+$("#ttOut").value,grouping_id:+$("#ttGrp").value,group1:$("#ttG1").value,group2:$("#ttG2").value,test_type:tt.testType,confidence:parseFloat($("#ttConf").value)};
  if(body.group1===body.group2){ toast('Pick two different groups.'); return; }
  tt.busy=true; tt.added=false; renderTTest(activeStep());
  fetch('/api/mm/ttest.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(r=>r.json()).then(j=>{ tt.busy=false; if(!j.ok){ toast(j.error||'Could not run the test.'); renderTTest(activeStep()); return;} tt.result=j; tt.tab='desc'; renderTTest(activeStep()); })
    .catch(()=>{ tt.busy=false; toast('Request failed.'); renderTTest(activeStep()); });
}
function ttTab(n){ tt.tab=n; $("#ttResults").innerHTML=renderTTestResults(tt.result); }
function renderTTestResults(r){
  const tab=(k,l)=>`<button class="tt-tab ${tt.tab===k?'on':''}" onclick="ttTab('${k}')">${l}</button>`;
  let body='';
  if(tt.tab==='desc'){
    const rows=r.descriptives.map(d=>`<tr><td class="dx-name">${esc(d.group)}</td><td>${d.n}</td><td>${fmt(d.mean)}</td><td>${fmt(d.sd)}</td><td>${fmt(d.se)}</td><td>${fmt(d.min)}</td><td>${fmt(d.max)}</td><td>${d.missing}</td></tr>`).join('');
    const D=r.difference;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>SE</th><th>Min</th><th>Max</th><th>Missing</th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="ov-sec" style="margin-top:18px">Mean difference</div>
      <div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>Group 1 mean</th><th>Group 2 mean</th><th>Mean difference</th><th class="l">Direction</th><th class="l">Pattern</th></tr></thead>
        <tbody><tr><td class="dx-name">${esc(r.outcome.name)}</td><td>${fmt(D.mean1)}</td><td>${fmt(D.mean2)}</td><td class="${D.diff<0?'dx-neg':'dx-pos'}">${D.diff>0?'+':''}${fmt(D.diff)}</td><td class="dx-interp">${esc(D.direction)}</td><td class="dx-interp">${esc(D.pattern)}</td></tr></tbody></table></div>`;
  } else if(tt.tab==='ready'){
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Check</th><th class="l">Result</th><th class="l">Status</th><th class="l">ReliCheck guidance</th></tr></thead>
      <tbody>${r.readiness.map(c=>`<tr><td class="dx-name">${esc(c.check)}</td><td class="dx-interp">${esc(c.result)}</td><td class="dx-interp"><span class="tt-status ${c.status==='Pass'?'ok':'rev'}">${esc(c.status)}</span></td><td class="dx-interp">${esc(c.guidance)}</td></tr>`).join('')}</tbody></table></div>`;
  } else if(tt.tab==='result'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Test used</th><th>t</th><th>df</th><th>p</th><th>Mean diff</th><th>95% CI low</th><th>95% CI high</th><th class="l">Result</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.outcome.name)}</td><td class="dx-interp">${esc(R.test_used)}</td><td>${fmt(R.t)}</td><td>${fmt(R.df,1)}</td><td>${esc(R.p_str)}</td><td>${fmt(R.diff)}</td><td>${fmt(R.ci_lo)}</td><td>${fmt(R.ci_hi)}</td><td class="dx-interp"><span class="tt-status ${R.significant?'ok':'rev'}">${R.significant?'Significant':'Not significant'}</span></td></tr></tbody></table></div>`;
  } else if(tt.tab==='effect'){
    const E=r.effect;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.outcome.name)}</td><td class="dx-interp">${esc(E.type)}</td><td>${fmt(E.value)}</td><td class="dx-interp">${esc(E.interpretation)}</td><td class="dx-interp">${esc(E.meaning)}</td></tr></tbody></table></div>`;
  } else {
    const L=r.reporting;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>
      <tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">${esc(L.plain)}</td></tr>
      <tr><td class="dx-name">Researcher summary</td><td class="dx-interp">${esc(L.researcher)}</td></tr>
      <tr><td class="dx-name">Mixed methods next step</td><td class="dx-interp">${esc(L.next)}</td></tr>
      <tr><td class="dx-name">Caution</td><td class="dx-interp">${esc(L.caution)}</td></tr></tbody></table></div>`;
  }
  return `
    <div class="tt-tabs">${tab('desc','Descriptives')}${tab('ready','Readiness')}${tab('result','Test result')}${tab('effect','Effect size')}${tab('report','Reporting language')}</div>
    <div class="panel"><div class="panel-b">${body}</div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">${esc(r.reporting.plain)}</div></div>
      ${state.design==='explanatory'?`<div class="dx-l dx-q"><div class="dx-l-k">Suggested qualitative follow-up</div><div class="dx-l-t">${esc(r.follow_up_question)}</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">${esc(r.reporting.caution)}</div></div>
    </div>
    <div class="dx-next"><div class="dx-next-k">↳ Explanatory next step</div><div class="dx-next-t">Stage this difference for the qualitative phase to explain.</div>
      <button class="btn primary" style="margin-left:auto;padding:7px 14px;font-size:12.5px" onclick="addToExplain()" ${tt.added?'disabled':''}>${tt.added?'Added ✓':'Add to Results to Explain'}</button></div>`;
}
function vlabSave(){
  const ins=Array.from(document.querySelectorAll('.vlab-in'));
  if(!ins.length)return;
  const items=ins.map(el=>({var_name:el.getAttribute('data-var'),value:el.getAttribute('data-val'),label:(el.value||'').trim()}));
  fetch('/api/mm/value-labels.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,items:items})})
    .then(r=>r.json()).then(j=>{
      if(j&&j.ok){
        if(!BOOT.valueLabels)BOOT.valueLabels={};
        items.forEach(it=>{if(!BOOT.valueLabels[it.var_name])BOOT.valueLabels[it.var_name]={};if(it.label)BOOT.valueLabels[it.var_name][it.value]=it.label;else if(BOOT.valueLabels[it.var_name])delete BOOT.valueLabels[it.var_name][it.value];});
        toast('Value labels saved — applied across the studio');
        renderTTest(activeStep());
      }else{toast((j&&(j.message||j.error))||'Could not save value labels.');}
    }).catch(()=>toast('Save failed.'));
}
function addToExplain(){
  const r=tt.result; if(!r) return;
  const g=r.grouping; const g1=String(g.group1), g2=String(g.group2);
  const numGroups=/^\d+$/.test(g1)&&/^\d+$/.test(g2);
  const g1l=numGroups?vlabFmt(g.name,g1):(vlabel(g.name,g1)||g1);
  const g2l=numGroups?vlabFmt(g.name,g2):(vlabel(g.name,g2)||g2);
  const dir=r.difference.diff<0?'lower':'higher';
  const plain=numGroups
    ?`${g1l} reported ${dir} ${r.outcome.name} than ${g2l}. The difference was ${r.result.significant?'statistically reliable':'not statistically reliable'}.`
    :r.reporting.plain;
  const fq=numGroups
    ?`What experiences help explain why ${g1l} reported ${dir} ${r.outcome.name} than ${g2l}?`
    :(r.follow_up_question||'');
  const obj={project_id:BOOT.projectId,source:'t_test',outcome:r.outcome.name,grouping:g.name,group1:g1,group2:g2,
    test_used:r.result.test_used,t:r.result.t,df:r.result.df,p:r.result.p,ci_lo:r.result.ci_lo,ci_hi:r.result.ci_hi,
    effect_type:r.effect.type,effect_value:r.effect.value,diff:r.difference.diff,plain,follow_up_question:fq};
  fetch('/api/mm/results-to-explain.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(obj)})
    .then(r=>r.json()).then(j=>{ if(j.ok){ tt.added=true; $("#ttResults").innerHTML=renderTTestResults(tt.result); toast('Added to Results to Explain'); } else toast(j.error||'Could not add.'); })
    .catch(()=>toast('Request failed.'));
}
/* ===== One-Way ANOVA (real data via /api/mm/anova.php) — panel mirrors the t-test ===== */
const an={grouping:null,outcome:null,result:null,tab:'desc',busy:false,added:false};
// Shared guard: a grouping is verbatim open-ended text if its longest group
// value label is over 50 chars. Real categorical labels are always short.
function isVerbatimGrouping(g){return g.groups&&g.groups.some(o=>o.value&&o.value.length>50);}
function cleanGroupings(){return ((BOOT.ttvars&&BOOT.ttvars.groupings)||[]).filter(g=>!isVerbatimGrouping(g));}
function anGroupings(){return cleanGroupings().filter(g=>g.groups&&g.groups.length>=3);}
function renderANOVA(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]};
  const G=anGroupings();
  if(!BOOT.projectId||!V.datasetReady||!V.outcomes.length||!G.length){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Group comparisons <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">One-Way ANOVA</h1><p class="lede">Compare the mean of one outcome across three or more groups.</p></div>
      ${helpBar('ANOVA')}
      <div class="work-surface" style="border-radius:16px">${(!BOOT.projectId||!V.datasetReady)?'No data is linked to this project yet. Upload data from Start, then return here.':'ANOVA needs a numeric outcome and a grouping with three or more categories. This dataset does not have one yet. For exactly two groups, use the t-test.'}</div>`;
    return;
  }
  if(an.grouping==null||!G.find(g=>g.id===an.grouping)) an.grouping=G[0].id;
  if(an.outcome==null) an.outcome=V.outcomes[0].id;
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and a grouping with three or more categories</div></div></div>
      <div class="panel-b">
        <div class="tt-grid">
          <div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="anOut" onchange="an.outcome=+this.value">${V.outcomes.map(o=>`<option value="${o.id}" ${o.id===an.outcome?'selected':''}>${esc(o.name)}</option>`).join('')}</select></div>
          <div class="field"><label>Grouping variable <span class="tt-hint">3+ categories</span></label><select class="ed-in" id="anGrp" onchange="an.grouping=+this.value;renderANOVA(activeStep())">${G.map(g=>`<option value="${g.id}" ${g.id===an.grouping?'selected':''}>${esc(g.name)} (${g.groups.length} groups)</option>`).join('')}</select></div>
        </div>
        <div class="run-actions"><button class="btn primary" onclick="runANOVA()" ${an.busy?'disabled':''}>${an.busy?'Running…':'▷ Run ANOVA'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Group comparisons <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">One-Way ANOVA</h1><p class="lede">Compare the mean of one outcome across three or more groups.</p></div>
    ${helpBar('ANOVA')}
    ${setup}
    <div id="anResults">${an.result?renderANOVAResults(an.result):''}</div>`;
}
function runANOVA(){
  const body={project_id:BOOT.projectId,outcome_id:+$("#anOut").value,grouping_id:+$("#anGrp").value};
  if(body.outcome_id===body.grouping_id){ toast('Outcome and grouping must differ.'); return; }
  an.busy=true; an.added=false; renderANOVA(activeStep());
  fetch('/api/mm/anova.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(r=>r.json()).then(j=>{ an.busy=false; if(!j.ok){ toast(j.error||'Could not run the test.'); renderANOVA(activeStep()); return;} an.result=j; an.tab='desc'; renderANOVA(activeStep()); })
    .catch(()=>{ an.busy=false; toast('Request failed.'); renderANOVA(activeStep()); });
}
function anTab(n){ an.tab=n; $("#anResults").innerHTML=renderANOVAResults(an.result); }
function renderANOVAResults(r){
  const tab=(k,l)=>`<button class="tt-tab ${an.tab===k?'on':''}" onclick="anTab('${k}')">${l}</button>`;
  let body='';
  if(an.tab==='desc'){
    const rows=r.descriptives.map(d=>`<tr><td class="dx-name">${esc(d.group)}</td><td>${d.n}</td><td>${fmt(d.mean)}</td><td>${fmt(d.sd)}</td><td>${fmt(d.se)}</td><td>${fmt(d.min)}</td><td>${fmt(d.max)}</td><td>${d.missing}</td></tr>`).join('');
    const drop=(r.grouping.dropped&&r.grouping.dropped.length)?`<div class="dx-l-t" style="margin-top:10px;font-size:12px;opacity:.7">Groups with fewer than 2 cases were left out: ${r.grouping.dropped.map(esc).join(', ')}.</div>`:'';
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>SE</th><th>Min</th><th>Max</th><th>Missing</th></tr></thead><tbody>${rows}</tbody></table></div>${drop}`;
  } else if(an.tab==='result'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Test</th><th>F</th><th>df1</th><th>df2</th><th>p</th><th>η²</th><th class="l">Result</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.outcome.name)}</td><td class="dx-interp">${esc(R.test_used)}</td><td>${fmt(R.F)}</td><td>${fmt(R.df1,0)}</td><td>${fmt(R.df2,0)}</td><td>${esc(R.p_str)}</td><td>${fmt(R.eta_sq,3)}</td><td class="dx-interp"><span class="tt-status ${R.significant?'ok':'rev'}">${R.significant?'Significant':'Not significant'}</span></td></tr></tbody></table></div>`;
  } else if(an.tab==='effect'){
    const E=r.effect;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.outcome.name)}</td><td class="dx-interp">${esc(E.type)}</td><td>${fmt(E.value,3)}</td><td class="dx-interp">${esc(E.interpretation)}</td><td class="dx-interp">${esc(E.meaning)}</td></tr></tbody></table></div>`;
  } else {
    const L=r.reporting;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>
      <tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">${esc(L.plain)}</td></tr>
      <tr><td class="dx-name">Researcher summary</td><td class="dx-interp">${esc(L.researcher)}</td></tr>
      <tr><td class="dx-name">Mixed methods next step</td><td class="dx-interp">${esc(L.next)}</td></tr>
      <tr><td class="dx-name">Caution</td><td class="dx-interp">${esc(L.caution)}</td></tr></tbody></table></div>`;
  }
  return `
    <div class="tt-tabs">${tab('desc','Descriptives')}${tab('result','Test result')}${tab('effect','Effect size')}${tab('report','Reporting language')}</div>
    <div class="panel"><div class="panel-b">${body}</div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">${esc(r.reporting.plain)}</div></div>
      ${state.design==='explanatory'?`<div class="dx-l dx-q"><div class="dx-l-k">Suggested qualitative follow-up</div><div class="dx-l-t">${esc(r.follow_up_question)}</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">${esc(r.reporting.caution)}</div></div>
    </div>
    <div class="dx-next"><div class="dx-next-k">↳ Explanatory next step</div><div class="dx-next-t">Stage this result for the qualitative phase to explain.</div>
      <button class="btn primary" style="margin-left:auto;padding:7px 14px;font-size:12.5px" onclick="addAnovaToExplain()" ${an.added?'disabled':''}>${an.added?'Added ✓':'Add to Results to Explain'}</button></div>`;
}
function addAnovaToExplain(){
  const r=an.result; if(!r) return;
  const obj={project_id:BOOT.projectId,source:'anova',outcome:r.outcome.name,grouping:r.grouping.name,
    F:r.result.F,df1:r.result.df1,df2:r.result.df2,p:r.result.p,eta_sq:r.result.eta_sq,
    effect_type:r.effect.type,effect_value:r.effect.value,plain:r.reporting.plain,follow_up_question:r.follow_up_question};
  fetch('/api/mm/results-to-explain.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(obj)})
    .then(r=>r.json()).then(j=>{ if(j.ok){ an.added=true; $("#anResults").innerHTML=renderANOVAResults(an.result); toast('Added to Results to Explain'); } else toast(j.error||'Could not add.'); })
    .catch(()=>toast('Request failed.'));
}
/* ===== Chi-Square test of independence (real data via /api/mm/chisquare.php) — panel mirrors the t-test ===== */
const csq={row:null,col:null,result:null,tab:'table',busy:false,added:false};
// Cramer's V interpretation using Cohen's df-adjusted thresholds.
// k = min(rows, cols) - 1; benchmarks tighten as table size grows.
function csqCramersInterp(v,nRows,nCols){
  const k=Math.min(nRows,nCols)-1;
  const T={1:[0.10,0.30,0.50],2:[0.07,0.21,0.35],3:[0.06,0.17,0.29],4:[0.05,0.15,0.25]};
  const t=T[Math.min(k,4)]||T[4];
  if(v>=t[2])return 'Large';
  if(v>=t[1])return 'Medium';
  if(v>=t[0])return 'Small';
  return 'Negligible';
}
// Check chi-square assumption: all expected cell counts ≥ 5.
function csqCellWarning(table){
  const grand=table.grand; if(!grand||!table.matrix||!table.col_totals)return '';
  const low=[];
  table.matrix.forEach(row=>{
    table.col_totals.forEach((colTotal,j)=>{
      const exp=row.total*colTotal/grand;
      if(exp<5)low.push(`${esc(row.label)} × ${esc(table.col_labels[j])} (expected ${exp.toFixed(1)})`);
    });
  });
  if(!low.length)return '';
  return `<div class="dm-note" style="margin-top:10px;color:#8a6418"><b>Assumption check:</b> ${low.length} cell${low.length>1?'s have':' has'} an expected count below 5 — chi-square results may be unreliable. Consider combining sparse categories or using Fisher's exact test. Affected: ${low.join('; ')}.</div>`;
}
function renderChiSquare(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]};
  const C=cleanGroupings();
  if(!BOOT.projectId||!V.datasetReady||C.length<2){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Category relationships <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">Chi-Square Test of Independence</h1><p class="lede">Test whether two category variables are related.</p></div>
      ${helpBar('Chi-square')}
      <div class="work-surface" style="border-radius:16px">${(!BOOT.projectId||!V.datasetReady)?'No data is linked to this project yet. Upload data from Start, then return here.':'Chi-square needs at least two categorical variables. This dataset does not have enough yet.'}</div>`;
    return;
  }
  if(csq.row==null||!C.find(g=>g.id===csq.row)) csq.row=C[0].id;
  if(csq.col==null||!C.find(g=>g.id===csq.col)) csq.col=(C[1]?C[1].id:C[0].id);
  if(csq.row===csq.col){const alt=C.find(g=>g.id!==csq.row); if(alt)csq.col=alt.id;}
  const opt=(cur)=>C.map(g=>`<option value="${g.id}" ${g.id===cur?'selected':''}>${esc(g.name)} (${g.groups.length} categories)</option>`).join('');
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the two category variables to compare</div></div></div>
      <div class="panel-b">
        <div class="tt-grid">
          <div class="field"><label>Rows variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="csRow" onchange="csq.row=+this.value;renderChiSquare(activeStep())">${opt(csq.row)}</select></div>
          <div class="field"><label>Columns variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="csCol" onchange="csq.col=+this.value;renderChiSquare(activeStep())">${opt(csq.col)}</select></div>
        </div>
        <div class="run-actions"><button class="btn primary" onclick="runChiSquare()" ${csq.busy?'disabled':''}>${csq.busy?'Running…':'▷ Run Chi-square'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Category relationships <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Chi-Square Test of Independence</h1><p class="lede">Test whether two category variables are related.</p></div>
    ${helpBar('Chi-square')}
    ${setup}
    <div id="csResults">${csq.result?renderChiSquareResults(csq.result):''}</div>`;
}
function runChiSquare(){
  const body={project_id:BOOT.projectId,row_id:+$("#csRow").value,col_id:+$("#csCol").value};
  if(body.row_id===body.col_id){ toast('Pick two different variables.'); return; }
  csq.busy=true; csq.added=false; renderChiSquare(activeStep());
  fetch('/api/mm/chisquare.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(r=>r.json()).then(j=>{ csq.busy=false; if(!j.ok){ toast(j.error||'Could not run the test.'); renderChiSquare(activeStep()); return;} csq.result=j; csq.tab='table'; renderChiSquare(activeStep()); })
    .catch(()=>{ csq.busy=false; toast('Request failed.'); renderChiSquare(activeStep()); });
}
function csqTab(n){ csq.tab=n; $("#csResults").innerHTML=renderChiSquareResults(csq.result); }
function renderChiSquareResults(r){
  const tab=(k,l)=>`<button class="tt-tab ${csq.tab===k?'on':''}" onclick="csqTab('${k}')">${l}</button>`;
  let body='';
  if(csq.tab==='table'){
    const ct=r.table;
    const th=ct.col_labels.map(l=>`<th>${esc(l)} n</th><th>${esc(l)} %</th>`).join('');
    const rows=ct.matrix.map(row=>{const cells=row.cells.map(c=>`<td>${c.count}</td><td>${fmt(c.row_pct,1)}%</td>`).join('');return `<tr><td class="dx-name">${esc(row.label)}</td>${cells}<td>${row.total}</td></tr>`;}).join('');
    const tot=ct.col_totals.map(t=>`<td>${t}</td><td>${fmt(ct.grand?100*t/ct.grand:0,1)}%</td>`).join('');
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">${esc(ct.row_var)}</th>${th}<th>Total</th></tr></thead>
      <tbody>${rows}<tr class="dx-total"><td class="dx-name">Total</td>${tot}<td>${ct.grand}</td></tr></tbody></table></div>
      <div class="dx-l-t" style="margin-top:10px;font-size:12px;opacity:.7">Percentages are within each row. Columns: ${esc(ct.col_var)}.</div>
      ${csqCellWarning(ct)}`;
  } else if(csq.tab==='result'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Test</th><th>χ²</th><th>df</th><th>N</th><th>p</th><th class="l">Result</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.row.name)} × ${esc(r.col.name)}</td><td class="dx-interp">${esc(R.test_used)}</td><td>${fmt(R.chi2)}</td><td>${fmt(R.df,0)}</td><td>${R.n_total}</td><td>${esc(R.p_str)}</td><td class="dx-interp"><span class="tt-status ${R.significant?'ok':'rev'}">${R.significant?'Significant':'Not significant'}</span></td></tr></tbody></table></div>`;
  } else if(csq.tab==='effect'){
    const E=r.effect;
    const nR=r.table?r.table.matrix.length:2;
    const nC=r.table?r.table.col_labels.length:2;
    const adjInterp=csqCramersInterp(E.value,nR,nC);
    const interpNote=adjInterp!==E.interpretation?` <span style="font-size:11px;color:var(--ink-3)">(adjusted for ${nR}×${nC} table; API said "${esc(E.interpretation)}")</span>`:'';
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.row.name)} × ${esc(r.col.name)}</td><td class="dx-interp">${esc(E.type)}</td><td>${fmt(E.value,3)}</td><td class="dx-interp">${adjInterp}${interpNote}</td><td class="dx-interp">${esc(E.meaning)}</td></tr></tbody></table></div>`;
  } else {
    const L=r.reporting;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>
      <tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">${esc(L.plain)}</td></tr>
      <tr><td class="dx-name">Researcher summary</td><td class="dx-interp">${esc(L.researcher)}</td></tr>
      <tr><td class="dx-name">Mixed methods next step</td><td class="dx-interp">${esc(L.next)}</td></tr>
      <tr><td class="dx-name">Caution</td><td class="dx-interp">${esc(L.caution)}</td></tr></tbody></table></div>`;
  }
  return `
    <div class="tt-tabs">${tab('table','Contingency')}${tab('result','Test result')}${tab('effect','Effect size')}${tab('report','Reporting language')}</div>
    <div class="panel"><div class="panel-b">${body}</div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">${esc(r.reporting.plain)}</div></div>
      ${state.design==='explanatory'?`<div class="dx-l dx-q"><div class="dx-l-k">Suggested qualitative follow-up</div><div class="dx-l-t">${esc(r.follow_up_question)}</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">${esc(r.reporting.caution)}</div></div>
    </div>
    <div class="dx-next"><div class="dx-next-k">↳ Explanatory next step</div><div class="dx-next-t">Stage this result for the qualitative phase to explain.</div>
      <button class="btn primary" style="margin-left:auto;padding:7px 14px;font-size:12.5px" onclick="addChiToExplain()" ${csq.added?'disabled':''}>${csq.added?'Added ✓':'Add to Results to Explain'}</button></div>`;
}
function addChiToExplain(){
  const r=csq.result; if(!r) return;
  const obj={project_id:BOOT.projectId,source:'chi_square',row:r.row.name,col:r.col.name,
    chi2:r.result.chi2,df:r.result.df,p:r.result.p,n_total:r.result.n_total,cramers_v:r.result.cramers_v,
    effect_type:r.effect.type,effect_value:r.effect.value,plain:r.reporting.plain,follow_up_question:r.follow_up_question};
  fetch('/api/mm/results-to-explain.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(obj)})
    .then(r=>r.json()).then(j=>{ if(j.ok){ csq.added=true; $("#csResults").innerHTML=renderChiSquareResults(csq.result); toast('Added to Results to Explain'); } else toast(j.error||'Could not add.'); })
    .catch(()=>toast('Request failed.'));
}
/* ===== Correlation (real data via /api/mm/correlation.php) — panel mirrors the t-test ===== */
const cor={x:null,y:null,result:null,tab:'desc',busy:false,added:false};
function renderCorrelation(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]}; const O=V.outcomes||[];
  if(!BOOT.projectId||!V.datasetReady||O.length<2){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Relationships <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">Correlation</h1><p class="lede">See whether two numbers move together, and how strongly.</p></div>
      ${helpBar('Correlation')}
      <div class="work-surface" style="border-radius:16px">${(!BOOT.projectId||!V.datasetReady)?'No data is linked to this project yet. Upload data from Start, then return here.':'Correlation needs at least two numeric variables. This dataset does not have enough yet.'}</div>`;
    return;
  }
  if(cor.x==null||!O.find(o=>o.id===cor.x)) cor.x=O[0].id;
  if(cor.y==null||!O.find(o=>o.id===cor.y)) cor.y=(O[1]?O[1].id:O[0].id);
  if(cor.x===cor.y){const alt=O.find(o=>o.id!==cor.x); if(alt)cor.y=alt.id;}
  const opt=(cur)=>O.map(o=>`<option value="${o.id}" ${o.id===cur?'selected':''}>${esc(o.name)}</option>`).join('');
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the two numeric variables to relate</div></div></div>
      <div class="panel-b"><div class="tt-grid">
        <div class="field"><label>Variable 1 <span class="tt-hint">numeric</span></label><select class="ed-in" id="corX" onchange="cor.x=+this.value;renderCorrelation(activeStep())">${opt(cor.x)}</select></div>
        <div class="field"><label>Variable 2 <span class="tt-hint">numeric</span></label><select class="ed-in" id="corY" onchange="cor.y=+this.value;renderCorrelation(activeStep())">${opt(cor.y)}</select></div>
      </div><div class="run-actions"><button class="btn primary" onclick="runCorrelation()" ${cor.busy?'disabled':''}>${cor.busy?'Running…':'▷ Run correlation'}</button></div></div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Relationships <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Correlation</h1><p class="lede">See whether two numbers move together, and how strongly.</p></div>
    ${helpBar('Correlation')}${setup}<div id="corResults">${cor.result?renderCorrelationResults(cor.result):''}</div>`;
}
function runCorrelation(){
  const body={project_id:BOOT.projectId,x_id:+$("#corX").value,y_id:+$("#corY").value};
  if(body.x_id===body.y_id){ toast('Pick two different variables.'); return; }
  cor.busy=true; cor.added=false; renderCorrelation(activeStep());
  fetch('/api/mm/correlation.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(r=>r.json()).then(j=>{ cor.busy=false; if(!j.ok){ toast(j.error||'Could not run the test.'); renderCorrelation(activeStep()); return;} cor.result=j; cor.tab='desc'; renderCorrelation(activeStep()); })
    .catch(()=>{ cor.busy=false; toast('Request failed.'); renderCorrelation(activeStep()); });
}
function corTab(n){ cor.tab=n; $("#corResults").innerHTML=renderCorrelationResults(cor.result); }
function renderCorrelationResults(r){
  const tab=(k,l)=>`<button class="tt-tab ${cor.tab===k?'on':''}" onclick="corTab('${k}')">${l}</button>`;
  let body='';
  if(cor.tab==='desc'){
    const rows=r.descriptives.map(d=>`<tr><td class="dx-name">${esc(d.name)}</td><td>${d.n}</td><td>${fmt(d.mean)}</td><td>${fmt(d.sd)}</td><td>${fmt(d.min)}</td><td>${fmt(d.max)}</td></tr>`).join('');
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Min</th><th>Max</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  } else if(cor.tab==='result'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Test</th><th>r</th><th>r²</th><th>df</th><th>p</th><th class="l">Direction</th><th class="l">Result</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.x.name)} & ${esc(r.y.name)}</td><td class="dx-interp">${esc(R.test_used)}</td><td>${fmt(R.r)}</td><td>${fmt(R.r2,3)}</td><td>${fmt(R.df,0)}</td><td>${esc(R.p_str)}</td><td class="dx-interp">${esc(R.direction)}</td><td class="dx-interp"><span class="tt-status ${R.significant?'ok':'rev'}">${R.significant?'Significant':'Not significant'}</span></td></tr></tbody></table></div>`;
  } else if(cor.tab==='effect'){
    const E=r.effect;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Strength</th><th class="l">Practical meaning</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.x.name)} & ${esc(r.y.name)}</td><td class="dx-interp">${esc(E.type)}</td><td>${fmt(E.value,3)}</td><td class="dx-interp">${esc(E.interpretation)}</td><td class="dx-interp">${esc(E.meaning)}</td></tr></tbody></table></div>`;
  } else {
    const L=r.reporting;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>
      <tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">${esc(L.plain)}</td></tr>
      <tr><td class="dx-name">Researcher summary</td><td class="dx-interp">${esc(L.researcher)}</td></tr>
      <tr><td class="dx-name">Mixed methods next step</td><td class="dx-interp">${esc(L.next)}</td></tr>
      <tr><td class="dx-name">Caution</td><td class="dx-interp">${esc(L.caution)}</td></tr></tbody></table></div>`;
  }
  return `
    <div class="tt-tabs">${tab('desc','Descriptives')}${tab('result','Test result')}${tab('effect','Effect size')}${tab('report','Reporting language')}</div>
    <div class="panel"><div class="panel-b">${body}</div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">${esc(r.reporting.plain)}</div></div>
      ${state.design==='explanatory'?`<div class="dx-l dx-q"><div class="dx-l-k">Suggested qualitative follow-up</div><div class="dx-l-t">${esc(r.follow_up_question)}</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">${esc(r.reporting.caution)}</div></div>
    </div>
    <div class="dx-next"><div class="dx-next-k">↳ Explanatory next step</div><div class="dx-next-t">Stage this relationship for the qualitative phase to explain.</div>
      <button class="btn primary" style="margin-left:auto;padding:7px 14px;font-size:12.5px" onclick="addCorToExplain()" ${cor.added?'disabled':''}>${cor.added?'Added ✓':'Add to Results to Explain'}</button></div>`;
}
function addCorToExplain(){
  const r=cor.result; if(!r) return;
  const obj={project_id:BOOT.projectId,source:'correlation',x:r.x.name,y:r.y.name,r:r.result.r,r2:r.result.r2,df:r.result.df,p:r.result.p,plain:r.reporting.plain,follow_up_question:r.follow_up_question};
  fetch('/api/mm/results-to-explain.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(obj)})
    .then(r=>r.json()).then(j=>{ if(j.ok){ cor.added=true; $("#corResults").innerHTML=renderCorrelationResults(cor.result); toast('Added to Results to Explain'); } else toast(j.error||'Could not add.'); })
    .catch(()=>toast('Request failed.'));
}
/* ===== Linear Regression (real data via /api/mm/regression.php) — panel mirrors the t-test ===== */
const reg={outcome:null,preds:{},result:null,tab:'coef',busy:false,added:false};
function renderRegression(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]}; const O=V.outcomes||[];
  if(!BOOT.projectId||!V.datasetReady||O.length<2){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Prediction <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">Linear Regression</h1><p class="lede">Predict a numeric outcome from one or more numeric predictors.</p></div>
      ${helpBar('Regression')}
      <div class="work-surface" style="border-radius:16px">${(!BOOT.projectId||!V.datasetReady)?'No data is linked to this project yet. Upload data from Start, then return here.':'Regression needs a numeric outcome and at least one numeric predictor. This dataset does not have enough numeric variables yet.'}</div>`;
    return;
  }
  if(reg.outcome==null||!O.find(o=>o.id===reg.outcome)) reg.outcome=O[0].id;
  const avail=O.filter(o=>o.id!==reg.outcome);
  // No auto-selection of predictors — researcher builds the model intentionally.
  // Clean up any stale preds that no longer exist in avail (e.g. after outcome change).
  Object.keys(reg.preds).forEach(k=>{if(!avail.find(o=>o.id===+k))delete reg.preds[k];});
  const outSel=O.map(o=>`<option value="${o.id}" ${o.id===reg.outcome?'selected':''}>${esc(o.name)}</option>`).join('');
  const nChecked=avail.filter(o=>reg.preds[o.id]).length;
  const chks=avail.map(o=>`<label class="rg-chk" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line,#e3e5e9);border-radius:8px;font-size:13px;cursor:pointer"><input type="checkbox" ${reg.preds[o.id]?'checked':''} onchange="regTogglePred(${o.id},this.checked)"> ${esc(o.name)}</label>`).join(' ');
  const predNote=nChecked===0?'<span style="color:#8a6418;font-size:12.5px;font-weight:600">Select at least one predictor before running.</span>':'';
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome, then check one or more predictors</div></div></div>
      <div class="panel-b">
        <div class="field" style="max-width:340px"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="regOut" onchange="reg.outcome=+this.value;reg.preds={};renderRegression(activeStep())">${outSel}</select></div>
        <div class="field" style="margin-top:12px"><label>Predictors <span class="tt-hint">numeric</span></label>
          <div style="display:flex;gap:8px;margin:0 0 8px;align-items:center"><button class="btn" style="padding:4px 10px;font-size:12px" onclick="regSelectAll()">Select all</button><button class="btn" style="padding:4px 10px;font-size:12px" onclick="reg.preds={};renderRegression(activeStep())">Clear</button>${predNote}</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px">${chks||'<span class="tt-hint">No other numeric variables available.</span>'}</div></div>
        <div class="run-actions"><button class="btn primary" onclick="runRegression()" ${reg.busy||!nChecked?'disabled':''}>${reg.busy?'Running…':'▷ Run regression'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Prediction <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Linear Regression</h1><p class="lede">Predict a numeric outcome from one or more numeric predictors.</p></div>
    ${helpBar('Regression')}${setup}<div id="regResults">${reg.result?renderRegressionResults(reg.result):''}</div>`;
}
function regTogglePred(id,on){ if(on)reg.preds[id]=true; else delete reg.preds[id]; }
function regSelectAll(){const V=BOOT.ttvars||{};(V.outcomes||[]).filter(o=>o.id!==reg.outcome).forEach(o=>reg.preds[o.id]=true);renderRegression(activeStep());}
function runRegression(){
  const ids=Object.keys(reg.preds).filter(k=>reg.preds[k]).map(k=>+k).filter(k=>k!==reg.outcome);
  if(!ids.length){ toast('Check at least one predictor.'); return; }
  reg.busy=true; renderRegression(activeStep());
  fetch('/api/mm/regression.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,outcome_id:reg.outcome,predictor_ids:ids})})
    .then(r=>r.json()).then(j=>{ reg.busy=false; if(!j.ok){ toast(j.error||'Could not run the model.'); renderRegression(activeStep()); return;} reg.result=j; reg.tab='coef'; renderRegression(activeStep()); })
    .catch(()=>{ reg.busy=false; toast('Request failed.'); renderRegression(activeStep()); });
}
function regTab(n){ reg.tab=n; $("#regResults").innerHTML=renderRegressionResults(reg.result); }
function renderRegressionResults(r){
  const tab=(k,l)=>`<button class="tt-tab ${reg.tab===k?'on':''}" onclick="regTab('${k}')">${l}</button>`;
  let body='';
  if(reg.tab==='coef'){
    const rows=r.coefficients.map(c=>`<tr><td class="dx-name">${esc(c.term)}</td><td>${fmt(c.b,3)}</td><td>${fmt(c.se,3)}</td><td>${fmt(c.t)}</td><td>${esc(c.p_str)}</td><td class="dx-interp">${c.is_intercept?'—':`<span class="tt-status ${c.sig?'ok':'rev'}">${c.sig?'Significant':'n.s.'}</span>`}</td></tr>`).join('');
    // Detect likely multicollinearity: 4+ predictors, or sign reversals among non-intercept betas
    const preds=r.coefficients.filter(c=>!c.is_intercept);
    const hasSignReversal=preds.some(c=>c.b<0)&&preds.some(c=>c.b>0)&&preds.length>=4;
    const mcolNote=(preds.length>=4||hasSignReversal)?`<div class="dm-note" style="margin-top:10px;color:#8a6418"><b>Multicollinearity note:</b> With ${preds.length} correlated predictors competing simultaneously, individual coefficients can be suppressed, inflated, or sign-reversed even when the construct they belong to genuinely predicts the outcome. Read the overall model R² and F as the primary finding; treat individual betas as directional indicators, not standalone effect sizes. A sign reversal on a predictor (e.g. a negative beta for a positively-worded item) is a common suppression artifact, not evidence of a negative real-world relationship.</div>`:'';
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Term</th><th>b</th><th>SE</th><th>t</th><th>p</th><th class="l">Result</th></tr></thead><tbody>${rows}</tbody></table></div>${mcolNote}`;
  } else if(reg.tab==='fit'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>R²</th><th>Adj. R²</th><th>F</th><th>df1</th><th>df2</th><th>N</th><th>p</th><th class="l">Model</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.outcome.name)}</td><td>${fmt(R.r2,3)}</td><td>${fmt(R.adj_r2,3)}</td><td>${fmt(R.F)}</td><td>${fmt(R.df1,0)}</td><td>${fmt(R.df2,0)}</td><td>${R.n_total}</td><td>${esc(R.p_str)}</td><td class="dx-interp"><span class="tt-status ${R.significant?'ok':'rev'}">${R.significant?'Significant':'Not significant'}</span></td></tr></tbody></table></div>`;
  } else {
    const L=r.reporting;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>
      <tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">${esc(L.plain)}</td></tr>
      <tr><td class="dx-name">Researcher summary</td><td class="dx-interp">${esc(L.researcher)}</td></tr>
      <tr><td class="dx-name">Mixed methods next step</td><td class="dx-interp">${esc(L.next)}</td></tr>
      <tr><td class="dx-name">Caution</td><td class="dx-interp">${esc(L.caution)}</td></tr></tbody></table></div>`;
  }
  return `
    <div class="tt-tabs">${tab('coef','Coefficients')}${tab('fit','Model fit')}${tab('report','Reporting language')}</div>
    <div class="panel"><div class="panel-b">${body}</div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">${esc(r.reporting.plain)}</div></div>
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">${esc(r.reporting.caution)}</div></div>
    </div>
    <div class="dx-next"><div class="dx-next-k">↳ Explanatory next step</div><div class="dx-next-t">Stage this model for the qualitative phase to explain.</div>
      <button class="btn primary" style="margin-left:auto;padding:7px 14px;font-size:12.5px" onclick="addRegToExplain()" ${reg.added?'disabled':''}>${reg.added?'Added ✓':'Add to Results to Explain'}</button></div>`;
}
function addRegToExplain(){
  const r=reg.result; if(!r) return;
  const sigPreds=r.coefficients.filter(c=>!c.is_intercept&&c.sig).map(c=>c.term);
  const obj={project_id:BOOT.projectId,source:'regression',outcome:r.outcome.name,
    r2:r.result.r2,F:r.result.F,df1:r.result.df1,df2:r.result.df2,p:r.result.p,n_total:r.result.n_total,
    significant_predictors:sigPreds.join(', '),plain:r.reporting.plain,follow_up_question:r.follow_up_question||''};
  fetch('/api/mm/results-to-explain.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(obj)})
    .then(r=>r.json()).then(j=>{ if(j.ok){ reg.added=true; $("#regResults").innerHTML=renderRegressionResults(reg.result); toast('Added to Results to Explain'); } else toast(j.error||'Could not add.'); })
    .catch(()=>toast('Request failed.'));
}
/* ===== Scale Reliability / Cronbach's alpha (real data via /api/mm/reliability.php) — panel mirrors the t-test ===== */
const rel={items:{},result:null,tab:'rel',busy:false};
function renderReliability(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]}; const O=V.outcomes||[];
  if(!BOOT.projectId||!V.datasetReady||O.length<2){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Measurement <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">Scale Reliability</h1><p class="lede">Check whether a set of items hangs together as one consistent scale.</p></div>
      ${helpBar('Reliability')}
      <div class="work-surface" style="border-radius:16px">${(!BOOT.projectId||!V.datasetReady)?'No data is linked to this project yet. Upload data from Start, then return here.':'A reliability check needs at least two numeric items. This dataset does not have enough yet.'}</div>`;
    return;
  }
  if(!Object.keys(rel.items).some(k=>rel.items[k])){ O.slice(0,Math.min(2,O.length)).forEach(o=>rel.items[o.id]=true); }
  const chks=O.map(o=>`<label class="rg-chk" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line,#e3e5e9);border-radius:8px;font-size:13px;cursor:pointer"><input type="checkbox" ${rel.items[o.id]?'checked':''} onchange="relToggleItem(${o.id},this.checked)"> ${esc(o.name)}</label>`).join(' ');
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Check the items that are meant to measure the same idea</div></div></div>
      <div class="panel-b">
        <div class="field"><label>Items <span class="tt-hint">2+ numeric</span></label><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">${chks}</div></div>
        <div class="run-actions"><button class="btn primary" onclick="runReliability()" ${rel.busy?'disabled':''}>${rel.busy?'Running…':'▷ Run reliability'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Measurement <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Scale Reliability</h1><p class="lede">Check whether a set of items hangs together as one consistent scale.</p></div>
    ${helpBar('Reliability')}${setup}<div id="relResults">${rel.result?renderReliabilityResults(rel.result):''}</div>`;
}
function relToggleItem(id,on){ if(on)rel.items[id]=true; else delete rel.items[id]; }
function runReliability(){
  const ids=Object.keys(rel.items).filter(k=>rel.items[k]).map(k=>+k);
  if(ids.length<2){ toast('Check at least two items.'); return; }
  rel.busy=true; renderReliability(activeStep());
  fetch('/api/mm/reliability.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,item_ids:ids})})
    .then(r=>r.json()).then(j=>{ rel.busy=false; if(!j.ok){ toast(j.error||'Could not run the check.'); renderReliability(activeStep()); return;} rel.result=j; rel.tab='rel'; renderReliability(activeStep()); })
    .catch(()=>{ rel.busy=false; toast('Request failed.'); renderReliability(activeStep()); });
}
function relTab(n){ rel.tab=n; $("#relResults").innerHTML=renderReliabilityResults(rel.result); }
function renderReliabilityResults(r){
  const tab=(k,l)=>`<button class="tt-tab ${rel.tab===k?'on':''}" onclick="relTab('${k}')">${l}</button>`;
  let body='';
  if(rel.tab==='rel'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Cronbach's alpha</th><th class="l">Rating</th><th>Items</th><th>N</th><th class="l">Usable as one score?</th></tr></thead>
      <tbody><tr><td class="dx-name">${fmt(R.alpha,2)}</td><td class="dx-interp">${esc(R.band)}</td><td>${R.k}</td><td>${R.n}</td><td class="dx-interp"><span class="tt-status ${R.usable?'ok':'rev'}">${R.usable?'Yes':'Not yet'}</span></td></tr></tbody></table></div>`;
  } else if(rel.tab==='items'){
    const rows=r.items.map(it=>`<tr><td class="dx-name">${esc(it.name)}${it.flag?' <span class="tt-status rev">review</span>':''}</td><td>${fmt(it.mean)}</td><td>${fmt(it.sd)}</td><td>${fmt(it.item_total_r,2)}</td><td>${it.alpha_if_deleted==null?'—':fmt(it.alpha_if_deleted,2)}</td></tr>`).join('');
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Item</th><th>Mean</th><th>SD</th><th>Item-total r</th><th>Alpha if dropped</th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="dx-l-t" style="margin-top:10px;font-size:12px;opacity:.7">An item is flagged when its item-total correlation is below .30 or dropping it would raise alpha.</div>`;
  } else {
    const L=r.reporting;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>
      <tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">${esc(L.plain)}</td></tr>
      <tr><td class="dx-name">Researcher summary</td><td class="dx-interp">${esc(L.researcher)}</td></tr>
      <tr><td class="dx-name">What to do next</td><td class="dx-interp">${esc(L.next)}</td></tr>
      <tr><td class="dx-name">Caution</td><td class="dx-interp">${esc(L.caution)}</td></tr></tbody></table></div>`;
  }
  return `
    <div class="tt-tabs">${tab('rel','Reliability')}${tab('items','Items')}${tab('report','Reporting language')}</div>
    <div class="panel"><div class="panel-b">${body}</div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">${esc(r.reporting.plain)}</div></div>
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">${esc(r.reporting.caution)}</div></div>
    </div>`;
}
/* Data Quality — REAL checks via /api/mm/data-quality.php, presented in the
   studio's own pattern: panel + dx-table + tt-status pills + dx-layers, exactly
   like the t-test readiness table. No bespoke styling. With no linked data it
   falls back to the pipeline's framing checks as a preview. */
const dqs={base:null,busy:false,err:''};
function qualityFetch(params){return fetch('/api/mm/data-quality.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},params))}).then(r=>r.json());}
function dqStatus(t){return t==='ok'?'<span class="tt-status ok">Pass</span>':t==='warn'?'<span class="tt-status rev">Review</span>':t==='alert'?'<span class="tt-status rev">Resolve</span>':'—';}
function dqHead(s){return `<div class="ws-header"><div class="eyebrow">Data quality · before you analyze</div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function dqNav(){return `${navFooter()}`;}
function dqMsg(s,msg){$("#centerInner").innerHTML=dqHead(s)+helpBar('data_quality')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+dqNav();}
function renderQuality(s){
  // No linked dataset (or demo): preview the framing checks from the pipeline.
  if(!(BOOT.projectId&&BOOT.rawinfo&&BOOT.rawinfo.linked)){
    const rows=(s.checks||[]).map(c=>`<div class="dq-row"><span class="dq-ico">!</span><div class="dq-body"><div class="dq-name">${esc(c.name)}</div><div class="dq-risk">${esc(c.risk)}</div></div><span class="dq-status">Preview</span></div>`).join('');
    $("#centerInner").innerHTML=dqHead(s)+helpBar('data_quality')+`<p class="lede">Connect a project with uploaded data to run these checks on your own responses.</p><div class="dq-card">${rows}</div>`+dqNav();
    return;
  }
  if(dqs.err){dqMsg(s,dqs.err);return;}
  if(!dqs.base){
    if(!dqs.busy){dqs.busy=true;qualityFetch({}).then(j=>{dqs.busy=false;if(j&&j.ok){dqs.base=j;}else{dqs.err=(j&&(j.message||j.error))||'Could not run quality checks.';}renderQuality(activeStep());}).catch(()=>{dqs.busy=false;dqs.err='Could not load your data.';renderQuality(activeStep());});}
    dqMsg(s,'Running quality checks on your data…');return;
  }
  const d=dqs.base;
  const rows=d.checks.map(c=>`<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${esc(c.finding)}</td><td class="dx-interp">${dqStatus(c.tone)}</td><td class="dx-interp">${esc(c.detail||'—')}</td></tr>`).join('');
  const score=`<div style="margin:2px 0 16px"><span class="ov-score" style="font-size:34px">${d.score}</span><span class="ov-score-max">/100 · ${esc(d.band)}</span></div>`;
  const summary=`${d.alerts} alert${d.alerts===1?'':'s'} and ${d.warns} warning${d.warns===1?'':'s'} across ${d.checks.length} checks. ${esc(d.interp)}`;
  $("#centerInner").innerHTML=dqHead(s)+helpBar('data_quality')+score+`
    <div class="panel"><div class="panel-h"><div><h3>Data-quality checks</h3></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Check</th><th class="l">Finding</th><th class="l">Status</th><th class="l">Detail</th></tr></thead>
        <tbody>${rows}</tbody></table></div></div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">${summary}</div></div>
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">Resolve the items marked Resolve before you analyze, and review the rest. These checks describe the data; they do not fix it.</div></div>
    </div>`+dqNav();
}
/* ============ Data Map — classification & organization (NOT evaluation) ============
   Seven tabs over the detected variable roles via /api/mm/data-map.php. Confirmed
   roles persist to the dataset (column_meta) for later modules. Studio pattern only:
   summary cards + panel + dx-table + tt-status badges; purple (--btn) is the accent. */
const dm={base:null,busy:false,err:'',saving:false,tab:'overview',edits:{}};
const DM_TABS=[['overview','Overview'],['roles','Variable Roles'],['quant','Quantitative Strand'],['qual','Qualitative Strand'],['demo','Demographics / Grouping'],['links','Integration Links'],['design','Design Fit']];
const DM_ROLE={
 'Case identifier':{type:'identifier',strand:'Integration',uses:'Link qualitative and quantitative records'},
 'Demographic grouping variable':{type:'demographic',strand:'Demographic / Grouping',uses:'Frequencies, group comparisons, chi-square'},
 'Demographic / covariate':{type:'demographic',strand:'Demographic / Grouping',uses:'Descriptives, grouping, correlations'},
 'Binary / Dichotomous':{type:'single',strand:'Demographic / Grouping',uses:'Chi-square, t-test grouping, group comparisons'},
 'Quantitative outcome':{type:'numeric',strand:'Quantitative',uses:'Descriptives, correlations, group comparisons'},
 'Likert item':{type:'likert',strand:'Quantitative',uses:'Means, reliability, t-test, ANOVA'},
 'Scale item':{type:'likert',strand:'Quantitative',uses:'Means, reliability, scale construction'},
 'Qualitative response':{type:'open',strand:'Qualitative',uses:'Theme discovery, quote analysis'},
 'Open-ended explanation':{type:'open',strand:'Qualitative',uses:'Explanation mapping, quotes'},
 'Exclude from analysis':{type:'ignore',strand:'Excluded',uses:'—'}
};
const DM_ROLE_ORDER=Object.keys(DM_ROLE);
const DM_QUAL_PURPOSE=['Explain quantitative pattern','Expand quantitative result','Identify emerging issue','Provide quote evidence','Capture concerns','Capture recommendations','Other'];
function dmFetch(payload){return fetch('/api/mm/data-map.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},payload||{}))}).then(r=>r.json());}
function dmRole(c){const e=dm.edits[c.idx];return (e&&e.role)||c.assigned_role;}
function dmConstruct(c){const e=dm.edits[c.idx];return (e&&e.construct!=null)?e.construct:(c.construct||'');}
function dmBadge(t){const ok=(t==='Ready'||t==='Strong'||t==='Yes');return `<span class="tt-status ${ok?'ok':'rev'}">${esc(t)}</span>`;}
function dmTab(t){dm.tab=t;renderDataMap(activeStep());}
function dmSetRole(idx,val){dm.edits[idx]=Object.assign({},dm.edits[idx],{role:val});renderDataMap(activeStep());}
function dmSetConstruct(idx,val){dm.edits[idx]=Object.assign({},dm.edits[idx],{construct:val||''}); /* no re-render on keystroke — save bar reflects dirty state */}
function dmSetPoints(idx,val){const n=parseInt(val,10);if(n>=2&&n<=10){dm.edits[idx]=Object.assign({},dm.edits[idx],{points:n});renderDataMap(activeStep());}}
function dmPointsCell(c){
  if(c.detected_type!=='Likert')return esc(c.format||c.detected_type);
  const cur=(dm.edits[c.idx]&&dm.edits[c.idx].points!=null)?dm.edits[c.idx].points:(c.points||c.distinct||'');
  return `<span style="white-space:nowrap">Likert (<input type="number" class="ed-in" style="width:42px;padding:2px 5px;display:inline-block;text-align:center;font-size:12.5px" min="2" max="10" value="${esc(String(cur))}" onchange="dmSetPoints(${c.idx},this.value)" title="Override scale point count">-point)</span>`;
}
function dmInclude(idx,on,role){dmSetRole(idx,on?role:'Exclude from analysis');}
function dmStage(idx,key,val){dm.edits[idx]=Object.assign({},dm.edits[idx],{[key]:val});if(key!=='focus')renderDataMap(activeStep());}
function dmRoleSelect(idx,cur){return `<select class="ed-in dm-sel" onchange="dmSetRole(${idx},this.value)">`+DM_ROLE_ORDER.map(r=>`<option ${r===cur?'selected':''}>${esc(r)}</option>`).join('')+`</select>`;}
function dmConstructSelect(idx,cur){const v=cur||'';return `<input class="ed-in dm-sel" style="min-width:160px" value="${esc(v)}" placeholder="e.g. Engagement" oninput="dmSetConstruct(${idx},this.value.trim())">`;}

function dmPurposeSelect(idx,cur){return `<select class="ed-in dm-sel" onchange="dmStage(${idx},'purpose',this.value)">`+DM_QUAL_PURPOSE.map(o=>`<option ${o===cur?'selected':''}>${esc(o)}</option>`).join('')+`</select>`;}
function dmFocusInput(idx,cur){return `<input class="ed-in dm-sel" style="min-width:190px" value="${esc(cur)}" placeholder="What this question asks" oninput="dmStage(${idx},'focus',this.value)">`;}
function dmBandSelect(idx){const e=dm.edits[idx]||{};const cur=e.band||'Keep as numeric';return `<div style="margin-top:6px"><select class="ed-in dm-sel" onchange="dmStage(${idx},'band',this.value)">`+['Keep as numeric','Create age bands','Exclude from grouping'].map(o=>`<option ${o===cur?'selected':''}>${esc(o)}</option>`).join('')+`</select></div>`;}
function dmCheck(idx,on,role){return `<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--ink-2)"><input type="checkbox" ${on?'checked':''} onchange="dmInclude(${idx},this.checked,'${role.replace(/'/g,"\\'")}')"> ${on?'Yes':'No'}</label>`;}
function dmPanel(title,thead,rows){return `<div class="panel"><div class="panel-h"><div><h3>${title}</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead>${thead}</thead><tbody>${rows}</tbody></table></div></div></div>`;}
function dmSaveBar(){
  const dirty=Object.keys(dm.edits).length>0;
  const allConfirmed=dm.base&&dm.base.columns.every(c=>!dm.edits[c.idx]&&c.confirmed);
  const note=dm.saving?'Saving…':allConfirmed?'<span style="color:var(--mm-ink);font-weight:700">✓ All roles confirmed</span>':dirty?'Unsaved changes — save to lock in your map, or use Confirm all.':'Some roles not yet confirmed. Use Confirm all to lock in every row.';
  return `<div class="dm-save"><button class="btn primary" ${dm.saving||!dirty?'disabled':''} onclick="dmSave()">${dm.saving?'Saving…':'Save changes'}</button><button class="btn" ${dm.saving?'disabled':''} onclick="dmConfirmAll()">Confirm all roles</button><span class="dm-note">${note}</span></div>`;
}
function dmHead(s){return `<div class="ws-header"><div class="eyebrow">Data map · organize before you analyze</div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function dmNav(){return navFooter();}
function dmMsg(s,msg){$("#centerInner").innerHTML=dmHead(s)+helpBar('data_map')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+dmNav();}
function dmSave(){
  // Persist only the columns the user actually touched, so we never clobber
  // existing column_meta (e.g. an RSSI-tagged dataset) for untouched variables.
  const idxs=Object.keys(dm.edits);
  if(!idxs.length){toast('No changes to save');return;}
  const save=idxs.map(k=>{const c=dm.base.columns.find(x=>String(x.idx)===String(k));if(!c)return null;const m=DM_ROLE[dmRole(c)]||{};const pts=(dm.edits[k]&&dm.edits[k].points!=null)?dm.edits[k].points:null;return {idx:c.idx,type:m.type||'ignore',construct:dmConstruct(c),points:pts};}).filter(Boolean);
  if(!save.length){toast('No changes to save');return;}
  dm.saving=true;renderDataMap(activeStep());
  dmFetch({save}).then(j=>{dm.saving=false;if(j&&j.ok){dm.base=j;dm.edits={};toast('Variable roles saved');}else{toast((j&&(j.message||j.error))||'Could not save roles.');}renderDataMap(activeStep());}).catch(()=>{dm.saving=false;toast('Save failed.');renderDataMap(activeStep());});
}
function dmConfirmAll(){
  if(dm.saving||!dm.base)return;
  dm.saving=true;renderDataMap(activeStep());
  const save=dm.base.columns.map(c=>{const m=DM_ROLE[dmRole(c)]||{};const pts=(dm.edits[c.idx]&&dm.edits[c.idx].points!=null)?dm.edits[c.idx].points:(c.points||null);return {idx:c.idx,type:m.type||'ignore',construct:dmConstruct(c),points:pts};});
  dmFetch({save}).then(j=>{dm.saving=false;if(j&&j.ok){dm.base=j;dm.edits={};toast('All roles confirmed');}else{toast((j&&(j.message||j.error))||'Could not confirm roles.');}renderDataMap(activeStep());}).catch(()=>{dm.saving=false;toast('Confirm failed.');renderDataMap(activeStep());});
}
function dmTabBody(d){
  const t=dm.tab;
  if(t==='overview'){
    const rows=d.overview.map(o=>`<tr><td class="dx-name">${esc(o.section)}</td><td class="dx-interp">${esc(o.variables)}</td><td>${o.count}</td><td>${dmBadge(o.status)}</td><td class="dx-interp">${esc(o.guidance)}</td></tr>`).join('');
    return dmPanel('Dataset structure overview','<tr><th class="l">Data Section</th><th class="l">Detected Variables</th><th>Count</th><th class="l">Status</th><th class="l">ReliCheck Guidance</th></tr>',rows);
  }
  if(t==='roles'){
    const rows=d.columns.map(c=>{const role=dmRole(c);const m=DM_ROLE[role]||{};const conf=dm.edits[c.idx]?'Pending':(c.confirmed?'Yes':'No');
      return `<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${esc(c.detected_type)}</td><td>${dmRoleSelect(c.idx,role)}</td><td class="dx-interp">${esc(m.strand||'—')}</td><td class="dx-interp">${esc(m.uses||'—')}</td><td>${dmBadge(conf)}</td></tr>`;}).join('');
    return dmPanel('Variable roles','<tr><th class="l">Variable</th><th class="l">Detected Type</th><th class="l">Assigned Role</th><th class="l">Strand</th><th class="l">Use in Analysis</th><th class="l">Confirmed</th></tr>',rows)+dmSaveBar();
  }
  if(t==='quant'){
    const list=d.columns.filter(c=>{const m=DM_ROLE[dmRole(c)]||{};return m.strand==='Quantitative'||c.detected_type==='Likert'||c.detected_type==='Numeric';});
    if(!list.length)return dmPanel('Quantitative strand','<tr><th class="l">Variable</th></tr>','<tr><td>No quantitative or Likert variables detected.</td></tr>');
    const rows=list.map(c=>{const inc=dmRole(c)!=='Exclude from analysis';const cons=dmConstruct(c);const def=c.detected_type==='Numeric'?'Quantitative outcome':'Likert item';
      return `<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${dmPointsCell(c)}</td><td class="dx-interp">${esc(cons||'—')}</td><td>${dmConstructSelect(c.idx,cons)}</td><td class="dx-interp">${esc((DM_ROLE[dmRole(c)]||{}).uses||'Means, reliability, t-test, ANOVA')}</td><td>${dmCheck(c.idx,inc,def)}</td></tr>`;}).join('');
    return dmPanel('Quantitative strand','<tr><th class="l">Variable</th><th class="l">Detected Type</th><th class="l">Suggested Construct</th><th class="l">Scale Membership</th><th class="l">Possible Uses</th><th class="l">Include</th></tr>',rows)+dmSaveBar();
  }
  if(t==='qual'){
    const list=d.columns.filter(c=>c.detected_type==='Open-Ended Text'||(DM_ROLE[dmRole(c)]||{}).strand==='Qualitative');
    if(!list.length)return dmPanel('Qualitative strand','<tr><th class="l">Variable</th></tr>','<tr><td>No open-ended variables detected.</td></tr>');
    const rows=list.map(c=>{const inc=dmRole(c)!=='Exclude from analysis';const e=dm.edits[c.idx]||{};const focus=e.focus||'';const purpose=e.purpose||DM_QUAL_PURPOSE[1];
      return `<tr><td class="dx-name">${esc(c.name)}</td><td>${dmFocusInput(c.idx,focus)}</td><td>${dmPurposeSelect(c.idx,purpose)}</td><td class="dx-interp">Theme discovery, quote analysis</td><td>${dmCheck(c.idx,inc,'Qualitative response')}</td></tr>`;}).join('');
    return dmPanel('Qualitative strand','<tr><th class="l">Variable</th><th class="l">Question Focus</th><th class="l">Qualitative Purpose</th><th class="l">Possible Uses</th><th class="l">Include</th></tr>',rows)+dmSaveBar();
  }
  if(t==='demo'){
    const list=d.columns.filter(c=>c.detected_type==='Demographic'||c.detected_type==='Categorical');
    if(!list.length)return dmPanel('Demographics / grouping','<tr><th class="l">Variable</th></tr>','<tr><td>No demographic or grouping variables detected.</td></tr>');
    const rows=list.map(c=>{const inc=dmRole(c)!=='Exclude from analysis';const an=c.distinct>2?'Frequencies, ANOVA, chi-square':'Frequencies, t-test, chi-square';const band=c.numeric_demo?dmBandSelect(c.idx):'';
      return `<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${esc(c.detected_type)}</td><td class="dx-interp">${esc(c.format||'—')}${band}</td><td class="dx-interp">${c.numeric_demo?'Demographic variable':'Grouping variable'}</td><td class="dx-interp">${esc(an)}</td><td>${dmCheck(c.idx,inc,'Demographic grouping variable')}</td></tr>`;}).join('');
    return dmPanel('Demographics / grouping','<tr><th class="l">Variable</th><th class="l">Detected Type</th><th class="l">Categories or Format</th><th class="l">Suggested Use</th><th class="l">Possible Analyses</th><th class="l">Include as Grouping</th></tr>',rows)+dmSaveBar();
  }
  if(t==='links'){
    const idn=d.summary.id_variable!=='—'?d.summary.id_variable:'Same-row link';
    const flow=`<div class="dm-flow"><span class="dm-flow-node quan">Quantitative Strand</span><span class="dm-flow-arrow">→</span><span class="dm-flow-node">${esc(idn)}</span><span class="dm-flow-arrow">→</span><span class="dm-flow-node qual">Qualitative Strand</span><span class="dm-flow-arrow">→</span><span class="dm-flow-node mm">Joint Display</span></div>`;
    const rows=d.integration_links.map(l=>`<tr><td class="dx-name">${esc(l.link_type)}</td><td class="dx-interp">${esc(l.status)}</td><td>${dmBadge(l.strength)}</td><td class="dx-interp">${esc(l.meaning)}</td><td class="dx-interp">${esc(l.action)}</td></tr>`).join('');
    return flow+dmPanel('Integration links','<tr><th class="l">Link Type</th><th class="l">Detected Status</th><th class="l">Strength</th><th class="l">Meaning</th><th class="l">User Action</th></tr>',rows);
  }
  // design fit
  const sel=state.design;
  const rows=d.design_fit.map(f=>`<tr class="${f.design===sel?'dm-fit-sel':''}"><td class="dx-name">${esc(f.label)}${f.design===sel?' <span class="tt-status ok">Selected</span>':''}</td><td>${dmBadge(f.fit)}</td><td class="dx-interp">${esc(f.reason)}</td><td class="dx-interp">${esc(f.use)}</td></tr>`).join('');
  const flows={explanatory:['QUAN','explain with QUAL','Integrated Finding'],convergent:['QUAN + QUAL analyzed separately','merged comparison','Integrated Finding'],exploratory:['QUAL','build / refine QUAN','Integrated Finding']};
  const fl=flows[sel]||flows.explanatory;
  const flow=`<div class="dm-flow">`+fl.map((n,i)=>`${i?'<span class="dm-flow-arrow">→</span>':''}<span class="dm-flow-node${i===0?' quan':i===fl.length-1?' mm':''}">${esc(n)}</span>`).join('')+`</div>`;
  return dmPanel('Design fit','<tr><th class="l">Mixed Methods Design</th><th class="l">Fit</th><th class="l">Reason</th><th class="l">Recommended Use</th></tr>',rows)+`<div class="ov-sec" style="margin-top:4px">Your selected design</div>`+flow;
}
// ── Data Map RE infrastructure helpers ───────────────────────────────────────
// Map MM display roles → canonical analysis_type (RE taxonomy).
function mmRoleToAnalysisType(role){
  var map={'Case identifier':'identifier','Demographic grouping variable':'demographic_nominal',
    'Demographic / covariate':'demographic_numeric','Binary / Dichotomous':'binary',
    'Quantitative outcome':'demographic_numeric','Likert item':'likert_item',
    'Scale item':'likert_item','Qualitative response':'open_ended',
    'Open-ended explanation':'open_ended','Exclude from analysis':'structural'};
  return map[role]||'open_ended';
}
// Map canonical analysis_type back to MM internal type for column_meta bridge.
function mmAnalysisTypeToMmType(at){
  var map={likert_item:'likert',scale_score:'numeric',demographic_numeric:'numeric',
    demographic_nominal:'demographic',demographic_ordinal:'demographic',
    binary:'single',open_ended:'open',narrative:'open',identifier:'identifier',
    computed_score:'numeric'};
  return map[at]||'ignore';
}
// After DataMap confirms: write back to column_meta so MM's analysis pipeline
// (t-test, ANOVA, etc.) keeps reading the right types. Bridge until unified upload.
function mmBridgeSave(vars){
  var save=vars.filter(function(v){return v._mm_idx!=null;}).map(function(v){
    return {idx:v._mm_idx,type:mmAnalysisTypeToMmType(v.analysis_type),
      construct:v._construct_name||'',points:null};
  });
  if(save.length) dmFetch({save:save}).then(function(j){if(j&&j.ok)toast('Variable map saved');}).catch(function(){});
}
// Tracks whether DataMap has been mounted for this page session.
var _mmDmMounted=false;

function renderDataMap(s){
  if(!(BOOT.projectId&&BOOT.rawinfo&&BOOT.rawinfo.linked)){
    $("#centerInner").innerHTML=dmHead(s)+helpBar('data_map')
      +`<p class="lede">Connect a project with uploaded data to map your variables into analysis roles.</p>`+dmNav();
    return;
  }
  if(!window.DataMap){
    dmMsg(s,'Loading Variable Map component…');return;
  }
  // The DataMap component renders its own canonical "Variable Map" header
  // (eyebrow + title + the "Confirm data map to unlock" instruction), so MM does
  // NOT add a second header here — that was the duplicate. Just the help
  // affordance, then the component, then nav.
  $("#centerInner").innerHTML=helpBar('data_map')
    +`<div id="mmDmContainer"></div>`+dmNav();
  var container=document.getElementById('mmDmContainer');
  if(!container) return;
  if(!_mmDmMounted){
    _mmDmMounted=true;
    // Fetch current column_meta from MM's own endpoint to get names + saved roles.
    dmFetch({}).then(function(j){
      if(!j||!j.ok||!j.columns){container.innerHTML='<div class="placeholder">Could not load variable data.</div>';return;}
      var rawVars=(j.columns||[]).map(function(c,i){
        return {variable_name:c.name,display_label:c.name,detected_type:c.detected_type||'',
          source:'dataset_column',dataset_id:BOOT.rawinfo.dataset_id||null,
          analysis_type:mmRoleToAnalysisType(c.assigned_role||''),
          include_in_analysis:c.assigned_role!=='Exclude from analysis',
          _mm_idx:c.idx,position:i};
      });
      DataMap.init({container:container,projectId:BOOT.projectId,projectType:'mm',
        rawVars:rawVars,constructs:[],
        onConfirmed:function(vars){mmBridgeSave(vars);}
      });
    }).catch(function(){container.innerHTML='<div class="placeholder">Could not load variable data.</div>';});
  } else {
    DataMap.mount(container);
  }
}
/* ============ Trustworthiness (l_trust) — qualitative credibility ============
   Three real practices via /api/mm/trustworthiness.php, chosen from the palette:
   Audit trail (derived project events), Member checking (a saved log), Coding
   agreement (Cohen's/Fleiss' kappa from the coders' codes). Studio pattern only:
   panel + dx-table + tt-status badges + ov-score number + dx-layers prose. */
const tr={base:null,busy:false,err:'',saving:false,member:[]};
function trFetch(payload){return fetch('/api/mm/trustworthiness.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},payload||{}))}).then(r=>r.json());}
function trHead(s){return `<div class="ws-header"><div class="eyebrow">Trustworthiness · qualitative credibility <span class="strand-chip qual">QUAL</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function trNav(){return `${navFooter()}`;}
function trMsg(s,msg){$("#centerInner").innerHTML=trHead(s)+helpBar('l_trust')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+trNav();}
function trOutcomeBadge(o){return `<span class="tt-status ${o==='Confirmed'?'ok':'rev'}">${esc(o)}</span>`;}
function trPanel(title,thead,rows){return `<div class="panel"><div class="panel-h"><div><h3>${title}</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead>${thead}</thead><tbody>${rows}</tbody></table></div></div></div>`;}
function trAdd(){const f=($("#trFind").value||'').trim();if(!f){toast('Add a finding to record');return;}tr.member.push({finding:f,outcome:$("#trOut").value,feedback:($("#trFb").value||'').trim(),date:new Date().toISOString().slice(0,10)});renderTrust(activeStep());}
function trRemove(i){tr.member.splice(i,1);renderTrust(activeStep());}
function trSaveMember(){tr.saving=true;renderTrust(activeStep());trFetch({save_member:tr.member}).then(j=>{tr.saving=false;if(j&&j.ok){tr.base=j;tr.member=(j.member_checking||[]).slice();toast('Member checking saved');}else{toast((j&&j.message)||'Could not save.');}renderTrust(activeStep());}).catch(()=>{tr.saving=false;toast('Save failed.');renderTrust(activeStep());});}
function trBodyAudit(d){
  const rows=d.audit.length?d.audit.map(a=>`<tr><td class="dx-name">${esc(a.when)}</td><td class="dx-interp">${esc(a.event)}</td><td class="dx-interp">${esc(a.detail)}</td></tr>`).join(''):`<tr><td colspan="3">No activity recorded yet.</td></tr>`;
  return trPanel('Audit trail','<tr><th class="l">When</th><th class="l">Event</th><th class="l">Detail</th></tr>',rows)+
    `<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">An audit trail records the key decisions and milestones in your analysis so another researcher could follow your reasoning. It is built automatically from your project's history.</div></div></div>`;
}
function trBodyMember(d){
  const rows=tr.member.length?tr.member.map((m,i)=>`<tr><td class="dx-name">${esc(m.date||'')}</td><td class="dx-interp">${esc(m.finding||'')}</td><td>${trOutcomeBadge(m.outcome||'Confirmed')}</td><td class="dx-interp">${esc(m.feedback||'')}</td><td><button class="btn" style="padding:4px 9px" onclick="trRemove(${i})">Remove</button></td></tr>`).join(''):`<tr><td colspan="5">No member checks recorded yet. Add one below.</td></tr>`;
  const note=d.storage_ready?'':`<div class="dm-note" style="margin-bottom:10px">Saving will be available after the next deploy; you can still draft entries here.</div>`;
  const form=`<div class="panel"><div class="panel-h"><div><h3>Record a member check</h3></div></div><div class="panel-b">
    <label class="ed-l">Finding or theme checked</label><input id="trFind" class="ed-in" placeholder="e.g. Teachers worry AI will widen the equity gap">
    <label class="ed-l">Participant response</label>
    <select id="trOut" class="ed-in dm-sel"><option>Confirmed</option><option>Revised</option><option>Mixed</option></select>
    <label class="ed-l">What they said (optional)</label><textarea id="trFb" class="ed-in" rows="2" placeholder="Summary of the participant feedback"></textarea>
    <div class="dm-save"><button class="btn" onclick="trAdd()">+ Add to log</button></div></div></div>`;
  return note+trPanel('Member checking log','<tr><th class="l">Date</th><th class="l">Finding</th><th class="l">Outcome</th><th class="l">Participant feedback</th><th></th></tr>',rows)+
    `<div class="dm-save"><button class="btn primary" ${tr.saving?'disabled':''} onclick="trSaveMember()">${tr.saving?'Saving…':'Save member checking'}</button><span class="dm-note">Member checking takes findings back to participants to confirm they ring true.</span></div>`+form;
}
function trBodyKappa(d){
  const ag=d.agreement;
  const roster=d.coders.length?`<div class="dm-note" style="margin-bottom:14px">Coders: ${d.coders.map(c=>esc(c.label)+' ('+c.coded+' codes)').join(' · ')}</div>`:'';
  if(!ag.computable){
    return roster+`<div class="work-surface" style="border-radius:16px">${esc(ag.note||'Coding agreement is not available yet.')}</div>`+
      `<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this measures</div><div class="dx-l-t">Coding agreement (kappa) checks how consistently two or more coders apply the same codes to the same responses, beyond what chance alone would produce. It needs at least two coders coding the same responses.</div></div></div>`;
  }
  const score=`<div style="margin:2px 0 14px"><span class="ov-score" style="font-size:34px">${ag.kappa!=null?Number(ag.kappa).toFixed(2):'—'}</span><span class="ov-score-max">/1.00 · ${esc(ag.interpretation)} · ${esc(ag.method)}</span></div>`;
  const facts=`<div class="dm-note" style="margin-bottom:14px">${ag.percent_agreement!=null?ag.percent_agreement+'% raw agreement · ':''}${ag.coders} coders · ${ag.shared_responses} shared responses · ${ag.categories} themes</div>`;
  const rows=ag.per_category.map(c=>`<tr><td class="dx-name">${esc(c.category)}</td><td>${c.kappa!=null?Number(c.kappa).toFixed(2):'—'}</td><td class="dx-interp">${esc(c.label)}</td><td>${c.n}</td></tr>`).join('');
  return roster+score+facts+trPanel('Agreement by theme','<tr><th class="l">Theme</th><th>κ</th><th class="l">Interpretation</th><th>Responses</th></tr>',rows)+
    `<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">Kappa adjusts raw agreement for chance. By common guidance, .41 to .60 is moderate, .61 to .80 substantial, and above .80 almost perfect. A low value on a theme means the coders applied that code differently and the codebook may need clarifying.</div></div><div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">Kappa can read low when a code is rare even if coders mostly agree. Read it alongside the raw agreement and the theme's response count.</div></div></div>`;
}
function renderTrust(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=trHead(s)+helpBar('l_trust')+`<p class="lede">Connect a project to document trustworthiness for your qualitative work.</p>`+trNav();return;}
  if(tr.err){trMsg(s,tr.err);return;}
  if(!tr.base){
    if(!tr.busy){tr.busy=true;trFetch({}).then(j=>{tr.busy=false;if(j&&j.ok){tr.base=j;tr.member=(j.member_checking||[]).slice();}else{tr.err=(j&&(j.message||j.error))||'Could not load trustworthiness.';}renderTrust(activeStep());}).catch(()=>{tr.busy=false;tr.err='Could not load your data.';renderTrust(activeStep());});}
    trMsg(s,'Loading your project history and coding agreement…');return;
  }
  const tool=(currentTool(s)||{}).name||'Audit trail';
  let body;
  if(tool.indexOf('Member')===0)body=trBodyMember(tr.base);
  else if(tool.indexOf('Coding')===0)body=trBodyKappa(tr.base);
  else body=trBodyAudit(tr.base);
  $("#centerInner").innerHTML=trHead(s)+helpBar('l_trust')+body+trNav();
}
/* ============ Qualitative Themes (l_themes) — native, on the real engine ============
   Wired to codebook.php (themes + coverage + sentiment in ONE call),
   coded-responses.php (quotes per theme), and build.php (AI discovery). Studio
   pattern only: summary cards + dx-table + tt-status badges + a quotes panel. */
const th={base:null,busy:false,err:'',building:false,coding:false,codingAi:false,clearing:false,sel:null,quotes:null,qbusy:false,menu:null,acting:0,codeView:false};
/* ===== Manual per-response coding workspace (lives inside the Themes step) =====
   One fetch (coder-responses.php) gives every response + this coder's codes + the
   theme picker; every edit reuses coder-set-coding.php (set/clear, idempotent,
   coder-scoped). Lets a coder read each response with its participant context and
   assign one or more theme codes by hand, and flags uncoded / over-coded rows. */
const cr={loaded:false,busy:false,err:'',data:null,filter:'all'};
function crFetch(){return fetch('/api/mm/coder-responses.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function crSetReq(rid,cid,action){return fetch('/api/mm/coder-set-coding.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,response_id:rid,category_id:cid,action:action})}).then(r=>r.json());}
function crOpen(){th.codeView=true;cr.loaded=false;cr.data=null;renderThemes(activeStep());}
function crClose(){th.codeView=false;th.base=null;renderThemes(activeStep());}
function crFilter(f){cr.filter=f;renderThemes(activeStep());}
function crRecount(){if(!cr.data)return;const rs=cr.data.responses||[];cr.data.coded=rs.filter(r=>r.code_count>0).length;cr.data.uncoded=rs.length-cr.data.coded;}
function crAddCode(rid,cid){if(!cid)return;const r=(cr.data.responses||[]).find(x=>x.id===rid);if(!r||r.codes.indexOf(cid)>=0)return;r.codes.push(cid);r.code_count=r.codes.length;crRecount();renderThemes(activeStep());
  crSetReq(rid,cid,'set').then(j=>{if(!(j&&j.ok)){r.codes=r.codes.filter(c=>c!==cid);r.code_count=r.codes.length;crRecount();toast((j&&(j.message||j.error))||'Could not add code.');renderThemes(activeStep());}}).catch(()=>{r.codes=r.codes.filter(c=>c!==cid);r.code_count=r.codes.length;crRecount();toast('Could not add code.');renderThemes(activeStep());});}
function crRemoveCode(rid,cid){const r=(cr.data.responses||[]).find(x=>x.id===rid);if(!r)return;const had=r.codes.indexOf(cid);if(had<0)return;r.codes=r.codes.filter(c=>c!==cid);r.code_count=r.codes.length;crRecount();renderThemes(activeStep());
  crSetReq(rid,cid,'clear').then(j=>{if(!(j&&j.ok)){r.codes.push(cid);r.code_count=r.codes.length;crRecount();toast((j&&(j.message||j.error))||'Could not remove code.');renderThemes(activeStep());}}).catch(()=>{r.codes.push(cid);r.code_count=r.codes.length;crRecount();toast('Could not remove code.');renderThemes(activeStep());});}
function crStatus(n){if(n===0)return '<span class="tt-status rev">uncoded</span>';if(n>3)return '<span class="tt-status" style="background:#f6e0e1;color:#a3262b">over-coded</span>';return '<span class="tt-status ok">coded</span>';}
function renderCodeWorkspace(s){
  const head=thHead(s)+helpBar('l_themes')+`<div class="context-strip"><span class="dot"></span>${esc(BOOT.projectLabel)}</div>`;
  if(cr.err){$("#centerInner").innerHTML=head+`<div class="work-surface" style="border-radius:16px">${esc(cr.err)}</div><div class="dm-save"><button class="btn" onclick="crClose()">← Back to themes</button></div>`+thNav();return;}
  if(!cr.loaded){
    if(!cr.busy){cr.busy=true;crFetch().then(j=>{cr.busy=false;cr.loaded=true;if(j&&j.ok){cr.data=j;}else{cr.err=(j&&(j.message||j.error))||'Could not load responses.';}renderThemes(activeStep());}).catch(()=>{cr.busy=false;cr.loaded=true;cr.err='Could not load your responses.';renderThemes(activeStep());});}
    $("#centerInner").innerHTML=head+`<div class="work-surface" style="border-radius:16px;color:var(--ink-3)">Loading responses…</div>`+thNav();return;
  }
  const d=cr.data||{responses:[],themes:[]};
  const themes=d.themes||[];
  if(!themes.length){$("#centerInner").innerHTML=head+`<div class="th-empty"><h3>No themes to code with</h3><p>Add or discover themes first, then return here to code responses by hand.</p></div><div class="dm-save"><button class="btn" onclick="crClose()">← Back to themes</button></div>`+thNav();return;}
  const tName={};themes.forEach(t=>tName[t.id]=t.name);
  let rows=d.responses||[];
  if(cr.filter==='uncoded')rows=rows.filter(r=>r.code_count===0);
  const bar=`<div class="dm-save"><button class="btn" onclick="crClose()">← Back to themes</button>
    <span style="margin:0 8px;color:var(--ink-2);font-size:13px">${d.coded||0} of ${d.total||0} coded · ${d.uncoded||0} uncoded</span>
    <button class="btn ${cr.filter==='all'?'primary':''}" style="padding:4px 10px;font-size:12px" onclick="crFilter('all')">All</button>
    <button class="btn ${cr.filter==='uncoded'?'primary':''}" style="padding:4px 10px;font-size:12px" onclick="crFilter('uncoded')">Uncoded only</button></div>`;
  const cards=rows.map(r=>{
    const meta=[r.respondent_ref?('ID '+esc(r.respondent_ref)):'',r.group_value?esc(r.group_value):'',r.numeric_value?('score '+esc(r.numeric_value)):''].filter(Boolean).join(' · ');
    const chips=r.codes.map(cid=>`<span class="th-cov" style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border:1px solid var(--line);border-radius:12px;margin:2px 4px 2px 0">${esc(tName[cid]||('#'+cid))} <span style="cursor:pointer;color:#a3262b;font-weight:700" onclick="crRemoveCode(${r.id},${cid})">×</span></span>`).join('');
    const avail=themes.filter(t=>r.codes.indexOf(t.id)<0);
    const picker=avail.length?`<select class="ed-in" style="max-width:240px;margin-top:6px" onchange="crAddCode(${r.id},+this.value);this.value=''"><option value="">+ add code…</option>${avail.map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select>`:`<span class="dm-note" style="margin-top:6px;display:inline-block">All themes applied</span>`;
    return `<div class="panel" style="margin-bottom:10px"><div class="panel-b">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><div class="dm-note" style="margin:0">${meta||'—'}</div>${crStatus(r.code_count)}</div>
      <div style="font-size:13.5px;line-height:1.55;margin:8px 0;white-space:pre-wrap">${esc(r.text)}</div>
      <div>${chips||'<span class="dm-note">No codes yet</span>'}</div>
      <div>${picker}</div>
    </div></div>`;}).join('');
  const body=`<div class="dx-scroll" style="max-height:460px;overflow:auto;border:1px solid var(--line);border-radius:12px;padding:10px;background:var(--surface-2,#f6f7f9)">${cards||'<div class="dm-note" style="padding:8px">No responses match this filter.</div>'}</div>`;
  const layers=`<div class="dx-layers" style="margin-top:14px"><div class="dx-l"><div class="dx-l-k">What this is</div><div class="dx-l-t">Read each response with its participant context and assign the theme codes that fit — by hand. A response may carry more than one code. "Uncoded" flags responses you have not yet judged; "over-coded" (4+) flags ones worth a second look. Edits save as you go.</div></div></div>`;
  $("#centerInner").innerHTML=head+bar+body+layers+thNav();
}

function thFetch(){return fetch('/api/mm/codebook.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function thQuotesFetch(cid){return fetch('/api/mm/coded-responses.php?project_id='+BOOT.projectId+'&category_id='+cid+'&limit=8',{credentials:'same-origin'}).then(r=>r.json());}
function thBuildReq(force){return fetch('/api/mm/build.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,mode:'auto',force:!!force})}).then(r=>r.json());}
function thCodeReq(){return fetch('/api/mm/code-existing.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId})}).then(r=>r.json());}
function thCodeAiReq(){return fetch('/api/mm/code-existing-semantic.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId})}).then(r=>r.json());}
function thClearReq(){return fetch('/api/mm/clear-tags.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId})}).then(r=>r.json());}
function thCode(){if(th.coding||th.codingAi)return;th.coding=true;renderThemes(activeStep());thCodeReq().then(j=>{th.coding=false;if(j&&j.ok){th.base=null;th.sel=null;th.quotes=null;toast('Tagged '+(j.coded_rows||0)+' responses to themes');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not tag responses.');renderThemes(activeStep());}}).catch(()=>{th.coding=false;toast('Tagging failed.');renderThemes(activeStep());});}
function thCodeAi(){if(th.coding||th.codingAi||th.clearing)return;toast('Working with ReliCheck Intelligence…');th.codingAi=true;renderThemes(activeStep());thCodeAiReq().then(j=>{th.codingAi=false;if(j&&j.ok){th.base=null;th.sel=null;th.quotes=null;toast('Tagged '+(j.coded_rows||0)+' responses to themes');if(j.batch_failures>0)toast('Note: '+j.batch_failures+' batch(es) failed — re-run to fill the gap.');if(j.unmatched_theme_names&&j.unmatched_theme_names.length)toast('Note: some AI labels did not match your themes ('+j.unmatched_theme_names.slice(0,3).join(', ')+'). Re-run if coverage looks low.');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not tag responses.');renderThemes(activeStep());}}).catch(()=>{th.codingAi=false;toast('Semantic tagging failed or timed out.');renderThemes(activeStep());});}
function thClearTags(){if(th.coding||th.codingAi||th.clearing)return;if(!window.confirm('Remove all tags from your responses? Your themes stay; only the coverage and quotes are cleared so you can re-tag.'))return;th.clearing=true;renderThemes(activeStep());thClearReq().then(j=>{th.clearing=false;if(j&&j.ok){th.base=null;th.sel=null;th.quotes=null;toast('Cleared tags from responses');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not clear tags.');renderThemes(activeStep());}}).catch(()=>{th.clearing=false;toast('Could not clear tags.');renderThemes(activeStep());});}
function thHead(s){return `<div class="ws-header"><div class="eyebrow">Qualitative themes · what the responses mean <span class="strand-chip qual">QUAL</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function thNav(){return `${navFooter()}`;}
function thMsg(s,msg){$("#centerInner").innerHTML=thHead(s)+helpBar('l_themes')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+thNav();}
function thConf(c){return `<span class="tt-status ${c==='high'?'ok':'rev'}">${esc(c||'—')}</span>`;}
function thSent(mix){mix=mix||{};const p=mix.positive||0,g=mix.negative||0,n=(mix.neutral||0)+(mix.mixed||0);const parts=[];if(p)parts.push('<b class="pos">'+p+' +</b>');if(g)parts.push('<b class="neg">'+g+' −</b>');if(n)parts.push('<b class="neu">'+n+' ·</b>');return parts.length?parts.join(' '):'—';}
function thSelect(cid){if(th.sel===cid){th.sel=null;th.quotes=null;renderThemes(activeStep());return;}th.sel=cid;th.quotes=null;th.qbusy=true;renderThemes(activeStep());thQuotesFetch(cid).then(j=>{th.qbusy=false;th.quotes=(j&&j.ok)?j:null;renderThemes(activeStep());}).catch(()=>{th.qbusy=false;th.quotes=null;renderThemes(activeStep());});}
function thBuild(force){if(th.building)return;toast('Working with ReliCheck Intelligence…');th.building=true;renderThemes(activeStep());thBuildReq(force).then(j=>{th.building=false;
  if(j&&j.ok){th.base=null;toast('Themes discovered');renderThemes(activeStep());return;}
  // Guard: project already has themes — confirm before adding a second set.
  if(j&&j.error==='mm_themes_exist'){renderThemes(activeStep());if(window.confirm((j.message||'This project already has themes.')+'\n\nClick OK to discover and ADD a new set anyway (this creates duplicates you would need to reconcile), or Cancel to keep your current themes.')){thBuild(true);}return;}
  toast((j&&(j.message||j.error))||'Could not discover themes.');renderThemes(activeStep());
}).catch(()=>{th.building=false;toast('Discovery failed or timed out.');renderThemes(activeStep());});}
function thAddReq(name){return fetch('/api/mm/categories.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',project_id:BOOT.projectId,name:name})}).then(r=>r.json());}
function thCatReq(payload){return fetch('/api/mm/categories.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},payload))}).then(r=>r.json());}
function thRowMenu(id){th.menu=(th.menu===id?null:id);renderThemes(activeStep());}
function thRename(id){if(th.acting)return;const el=document.getElementById('thRen_'+id);const n=el?el.value.trim():'';if(!n){toast('Enter a name');return;}th.acting=id;renderThemes(activeStep());thCatReq({action:'rename',category_id:id,name:n}).then(j=>{th.acting=0;if(j&&j.ok){th.base=null;th.menu=null;toast('Theme renamed');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not rename.');renderThemes(activeStep());}}).catch(()=>{th.acting=0;toast('Rename failed.');renderThemes(activeStep());});}
function thRemove(id){if(th.acting)return;const themes=((th.base&&th.base.entries)||[]);const t=themes.find(x=>x.category_id===id);const name=t?t.name:'this theme';if(!window.confirm('Remove the theme "'+name+'"? Its tags (if any) are removed too; other themes are unaffected. This cannot be undone.'))return;th.acting=id;renderThemes(activeStep());thCatReq({action:'delete',category_id:id}).then(j=>{th.acting=0;if(j&&j.ok){th.base=null;th.menu=null;th.sel=null;th.quotes=null;toast('Theme removed');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not remove.');renderThemes(activeStep());}}).catch(()=>{th.acting=0;toast('Remove failed.');renderThemes(activeStep());});}
function thMerge(id){if(th.acting)return;const sel=document.getElementById('thMrg_'+id);const targetId=sel?+sel.value:0;if(!targetId||targetId===id){toast('Pick a different theme to merge into');return;}const themes=((th.base&&th.base.entries)||[]);const target=themes.find(t=>t.category_id===targetId);if(!target){toast('Target theme not found');return;}if(!window.confirm('Merge into "'+target.name+'"? Both themes become one and their tags are pooled. This cannot be undone.'))return;th.acting=id;renderThemes(activeStep());thCatReq({action:'merge',source_ids:[id,targetId],target_name:target.name,target_description:target.description||''}).then(j=>{th.acting=0;if(j&&j.ok){th.base=null;th.menu=null;th.sel=null;th.quotes=null;toast('Themes merged');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not merge.');renderThemes(activeStep());}}).catch(()=>{th.acting=0;toast('Merge failed.');renderThemes(activeStep());});}
function thAdd(){const el=document.getElementById('thNewName');const n=el?el.value.trim():'';if(!n){toast('Enter a theme name');return;}thAddReq(n).then(j=>{if(j&&j.ok){th.base=null;toast('Theme added');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not add theme.');}}).catch(()=>toast('Add failed.'));}
function thQuotesPanel(){
  if(th.sel==null)return '';
  if(th.qbusy)return `<div class="th-quotes" style="padding:16px 18px">Loading quotes…</div>`;
  const q=th.quotes;
  if(!q||!q.responses||!q.responses.length)return `<div class="th-quotes" style="padding:16px 18px">No coded quotes for this theme yet.</div>`;
  const rows=q.responses.map(r=>`<div class="th-quote"><div class="th-quote-t">"${esc(r.text)}"</div><div class="th-quote-m">${r.respondent_ref?'<span>'+esc(r.respondent_ref)+'</span>':''}${r.group_value?'<span>· '+esc(r.group_value)+'</span>':''}${r.sentiment?'<span>· '+esc(r.sentiment)+'</span>':''}${r.quote_worthy?'<span>· ★ quote-worthy</span>':''}</div></div>`).join('');
  return `<div class="ov-sec" style="margin-top:2px">Example quotes · ${esc(q.category?q.category.name:'')} (${q.total} coded)</div><div class="th-quotes">${rows}</div>`;
}
function renderThemes(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=thHead(s)+helpBar('l_themes')+`<p class="lede">Connect a project with open-ended responses to discover and review themes.</p>`+thNav();return;}
  if(th.codeView){ return renderCodeWorkspace(s); }
  if(th.err){thMsg(s,th.err);return;}
  if(!th.base){
    if(!th.busy){th.busy=true;thFetch().then(j=>{th.busy=false;if(j&&j.ok){th.base=j;}else{th.err=(j&&(j.message||j.error))||'Could not load themes.';}renderThemes(activeStep());}).catch(()=>{th.busy=false;th.err='Could not load your data.';renderThemes(activeStep());});}
    thMsg(s,'Loading your themes…');return;
  }
  const d=th.base,themes=d.entries||[],total=d.total_responses||0;
  if(!themes.length){
    const body=`<div class="th-empty"><h3>No themes yet</h3><p>${total?('Build the themes that run through your '+total+' open-ended responses — add them yourself, or let ReliCheck Intelligence propose a starting set you can edit.'):'No open-ended responses are linked to this project yet. Add qualitative data first.'}</p>${total?`<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:12px"><input id="thNewName" class="ed-in" style="max-width:300px" placeholder="Name a theme"><button class="btn primary" onclick="thAdd()">+ Add theme</button></div><div class="dm-note" style="margin-bottom:8px">or</div><button class="btn" ${th.building?'disabled':''} onclick="thBuild()">${th.building?'Discovering…':'✦ Discover themes with ReliCheck Intelligence'}</button>`:''}</div>`;
    $("#centerInner").innerHTML=thHead(s)+helpBar('l_themes')+body+thNav();return;
  }
  const coded=themes.reduce((a,t)=>a+(t.coded_count||0),0);
  const cards=`<div class="dm-cards">
    <div class="dm-card"><div class="dm-card-k">Themes</div><div class="dm-card-v">${themes.length}</div></div>
    <div class="dm-card"><div class="dm-card-k">Open-Ended Responses</div><div class="dm-card-v">${total}</div></div>
    <div class="dm-card"><div class="dm-card-k">Coded Tags</div><div class="dm-card-v">${coded}</div></div></div>`;
  const rows=themes.map(t=>{const pct=t.percent||0;const sel=t.category_id===th.sel;const open=th.menu===t.category_id;const acting=th.acting===t.category_id;
    const others=themes.filter(o=>o.category_id!==t.category_id);
    const menuRow=open?`<tr class="th-menu-row"><td colspan="5" style="background:var(--surface-2,#f6f7f9)"><div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:4px 2px">
      <input id="thRen_${t.category_id}" class="ed-in" style="max-width:280px" value="${esc(t.name)}"><button class="btn" ${acting?'disabled':''} onclick="thRename(${t.category_id})">Save name</button>
      <span style="color:var(--ink-3)">·</span>
      <label style="font-size:12.5px;color:var(--ink-2);font-weight:600">Merge into</label><select id="thMrg_${t.category_id}" class="ed-in" style="max-width:240px">${others.map(o=>`<option value="${o.category_id}">${esc(o.name)}</option>`).join('')}</select><button class="btn" ${acting||!others.length?'disabled':''} onclick="thMerge(${t.category_id})">Merge</button>
      <span style="color:var(--ink-3)">·</span>
      <button class="btn" style="color:#a3262b;border-color:#e6c9cb" ${acting?'disabled':''} onclick="thRemove(${t.category_id})">Remove theme</button>
    </div></td></tr>`:'';
    return `<tr${sel?' class="dm-fit-sel"':''}><td class="dx-name">${esc(t.name)}${t.description?`<div class="th-sent" style="font-weight:600;margin-top:3px;white-space:normal">${esc(t.description)}</div>`:''}</td><td><div class="th-cov">${t.coded_count||0} <span style="color:var(--ink-3)">(${pct}%)</span></div><div class="th-bar"><i style="width:${Math.min(100,pct)}%"></i></div></td><td class="th-sent">${thSent(t.sentiment_mix)}</td><td>${thConf(t.confidence)}</td><td style="white-space:nowrap"><button class="btn" style="padding:5px 11px" onclick="thSelect(${t.category_id})">${sel?'Hide quotes':'View quotes'}</button> <button class="btn" style="padding:5px 9px" title="Manage theme" onclick="thRowMenu(${t.category_id})">${open?'✕':'⋯'}</button></td></tr>${menuRow}`;}).join('');
  const table=`<div class="panel"><div class="panel-h"><div><h3>Themes</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Theme</th><th class="l">Coverage</th><th class="l">Sentiment</th><th class="l">Confidence</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></div></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">Each theme is a pattern of meaning found across your open-ended responses. Coverage is how many responses were tagged with it; sentiment shows the balance of positive, negative, and neutral tone. Open a theme to read the participant quotes behind it.</div></div></div>`;
  const allZero=themes.every(t=>(t.coded_count||0)===0);
  const busy=th.coding||th.codingAi||th.clearing;
  const clearBtn=allZero?'':`<button class="btn" ${busy?'disabled':''} onclick="thClearTags()">${th.clearing?'Clearing…':'Clear tags'}</button>`;
  const codeByHand=`<button class="btn" ${busy?'disabled':''} onclick="crOpen()" title="Read each response and assign codes yourself">✎ Code by hand</button>`;
  const codeBar=`<div class="dm-save"><button class="btn primary" ${busy?'disabled':''} onclick="thCodeAi()">${th.codingAi?'Tagging with ReliCheck Intelligence…':(allZero?'Tag responses with ReliCheck Intelligence':'Re-tag with ReliCheck Intelligence')}</button><button class="btn" ${busy?'disabled':''} onclick="thCode()">${th.coding?'Tagging…':'Keyword tag'}</button>${clearBtn}${codeByHand}<span class="dm-note">${allZero?'Your themes are not tagged to any responses yet. ReliCheck Intelligence reads meaning (recommended); keyword tagging is a fast literal-match pass.':'ReliCheck Intelligence reads meaning, so it catches themes worded differently. Keyword tagging is a fast literal match. Clear tags to start over without losing your themes.'}</span></div>`;
  const addBar=`<div class="dm-save" style="margin:6px 0 4px"><input id="thNewName" class="ed-in" style="max-width:260px" placeholder="Add a theme by name"><button class="btn" onclick="thAdd()">+ Add theme</button><span class="dm-note">Add a code by hand.</span></div>`;
  $("#centerInner").innerHTML=thHead(s)+helpBar('l_themes')+cards+codeBar+addBar+table+thQuotesPanel()+layers+thNav();
}
/* ============ Codebook & Evidence (l_book) — the rulebook for each code ============
   Wired to codebook.php (entries + POST save + POST draft[AI]) and
   codebook-evidence.php (coded quotes behind a code). Per-code editor with an
   AI-draft assist and an evidence panel. Studio pattern only. */
const bk={base:null,busy:false,err:'',sel:null,edits:{},saving:false,drafting:false,evidence:null,ebusy:false};
function bkFetch(){return fetch('/api/mm/codebook.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function bkEvidenceFetch(cid){return fetch('/api/mm/codebook-evidence.php?project_id='+BOOT.projectId+'&category_id='+cid,{credentials:'same-origin'}).then(r=>r.json());}
function bkVal(cid,k){const e=bk.edits[cid];if(e&&e[k]!=null)return e[k];const t=((bk.base&&bk.base.entries)||[]).find(x=>x.category_id===cid)||{};return t[k]!=null?t[k]:'';}
function bkCapture(){if(bk.sel==null)return;const g=id=>{const e=document.getElementById(id);return e?e.value:undefined;};const f={};['short_definition','full_description','inclusion_rules','exclusion_rules','borderline_cases','status'].forEach(k=>{const v=g('bk_'+k);if(v!==undefined)f[k]=v;});if(Object.keys(f).length)bk.edits[bk.sel]=Object.assign({},bk.edits[bk.sel],f);}
function bkLoadEvidence(cid){bk.ebusy=true;bkEvidenceFetch(cid).then(j=>{bk.ebusy=false;bk.evidence=(j&&j.ok)?j:null;renderBook(activeStep());}).catch(()=>{bk.ebusy=false;bk.evidence=null;renderBook(activeStep());});}
function bkSelect(v){bkCapture();bk.sel=+v;bk.evidence=null;renderBook(activeStep());bkLoadEvidence(bk.sel);}
function bkSave(){if(bk.saving||bk.sel==null)return;bkCapture();bk.saving=true;renderBook(activeStep());const cid=bk.sel,e=bk.edits[cid]||{};fetch('/api/mm/codebook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save',project_id:BOOT.projectId,category_id:cid,short_definition:e.short_definition||'',full_description:e.full_description||'',inclusion_rules:e.inclusion_rules||'',exclusion_rules:e.exclusion_rules||'',borderline_cases:e.borderline_cases||'',status:e.status||'draft'})}).then(r=>r.json()).then(j=>{bk.saving=false;if(j&&j.ok){toast('Codebook entry saved');renderBook(activeStep());}else{toast((j&&(j.message||j.error))||'Could not save.');renderBook(activeStep());}}).catch(()=>{bk.saving=false;toast('Save failed.');renderBook(activeStep());});}
function bkDraft(){if(bk.drafting||bk.sel==null)return;toast('Working with ReliCheck Intelligence…');bkCapture();bk.drafting=true;renderBook(activeStep());const cid=bk.sel;fetch('/api/mm/codebook.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'draft',project_id:BOOT.projectId,category_id:cid})}).then(r=>r.json()).then(j=>{bk.drafting=false;if(j&&j.ok&&j.draft){const d=j.draft;bk.edits[cid]=Object.assign({},bk.edits[cid],{short_definition:d.short_definition||'',full_description:d.full_description||'',inclusion_rules:d.inclusion_rules||'',exclusion_rules:d.exclusion_rules||'',borderline_cases:d.borderline_cases||''});toast('ReliCheck Intelligence draft ready — review and save');renderBook(activeStep());}else{toast((j&&(j.message||j.error))||'Could not draft (needs tagged responses).');renderBook(activeStep());}}).catch(()=>{bk.drafting=false;toast('Draft failed.');renderBook(activeStep());});}
function bkHead(s){return `<div class="ws-header"><div class="eyebrow">Codebook & evidence · the rulebook for your codes <span class="strand-chip qual">QUAL</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function bkNav(){return `${navFooter()}`;}
function bkMsg(s,msg){$("#centerInner").innerHTML=bkHead(s)+helpBar('l_book')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+bkNav();}
function bkField(label,id,val,rows){return `<label class="ed-l">${label}</label>`+(rows?`<textarea id="${id}" class="ed-in" rows="${rows}">${esc(val)}</textarea>`:`<input id="${id}" class="ed-in" value="${esc(val)}">`);}
function bkEvidencePanel(){
  if(bk.ebusy)return `<div class="th-quotes" style="padding:16px 18px">Loading evidence…</div>`;
  const q=bk.evidence;
  if(!q||!q.responses||!q.responses.length)return `<div class="th-quotes" style="padding:16px 18px">No coded evidence for this code yet. Tag responses on the Qualitative Themes step first.</div>`;
  const rows=q.responses.slice(0,12).map(r=>`<div class="th-quote"><div class="th-quote-t">"${esc(r.text)}"</div><div class="th-quote-m">${r.respondent_ref?'<span>'+esc(r.respondent_ref)+'</span>':''}${r.group_value?'<span>· '+esc(r.group_value)+'</span>':''}${r.sentiment?'<span>· '+esc(r.sentiment)+'</span>':''}</div></div>`).join('');
  return `<div class="ov-sec" style="margin-top:2px">Evidence · ${q.responses.length} coded responses</div><div class="th-quotes">${rows}</div>`;
}
function renderBook(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=bkHead(s)+helpBar('l_book')+`<p class="lede">Connect a project to build the codebook.</p>`+bkNav();return;}
  if(bk.err){bkMsg(s,bk.err);return;}
  if(!bk.base){
    if(!bk.busy){bk.busy=true;bkFetch().then(j=>{bk.busy=false;if(j&&j.ok){bk.base=j;if(bk.sel==null&&j.entries&&j.entries.length){bk.sel=j.entries[0].category_id;bkLoadEvidence(bk.sel);}}else{bk.err=(j&&(j.message||j.error))||'Could not load the codebook.';}renderBook(activeStep());}).catch(()=>{bk.busy=false;bk.err='Could not load your data.';renderBook(activeStep());});}
    bkMsg(s,'Loading your codebook…');return;
  }
  const themes=bk.base.entries||[];
  if(!themes.length){$("#centerInner").innerHTML=bkHead(s)+helpBar('l_book')+`<div class="th-empty"><h3>No codes yet</h3><p>Discover themes on the Qualitative Themes step first, then return here to define each code and document its evidence.</p></div>`+bkNav();return;}
  if(bk.sel==null)bk.sel=themes[0].category_id;
  const cur=themes.find(t=>t.category_id===bk.sel)||themes[0];bk.sel=cur.category_id;
  const opts=themes.map(t=>`<option value="${t.category_id}" ${t.category_id===bk.sel?'selected':''}>${esc(t.name)} (${t.coded_count||0})</option>`).join('');
  const picker=`<div style="margin:0 0 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"><label class="ed-l" style="margin:0">Code</label><select class="ed-in dm-sel" style="max-width:340px" onchange="bkSelect(this.value)">${opts}</select><span class="dm-note">${themes.length} codes · ${cur.coded_count||0} responses tagged</span></div>`;
  const editor=`<div class="panel"><div class="panel-h"><div><h3>${esc(cur.name)}</h3><div class="ph-sub">Define this code so another reader could apply it the same way</div></div></div><div class="panel-b">
    ${bkField('Short definition (one sentence)','bk_short_definition',bkVal(bk.sel,'short_definition'),0)}
    ${bkField('Full description','bk_full_description',bkVal(bk.sel,'full_description'),3)}
    ${bkField('Inclusion rules — when this code applies','bk_inclusion_rules',bkVal(bk.sel,'inclusion_rules'),2)}
    ${bkField('Exclusion rules — when it does NOT apply','bk_exclusion_rules',bkVal(bk.sel,'exclusion_rules'),2)}
    ${bkField('Borderline cases','bk_borderline_cases',bkVal(bk.sel,'borderline_cases'),2)}
    <label class="ed-l">Status</label><select id="bk_status" class="ed-in dm-sel">${['draft','reviewed','approved'].map(o=>`<option ${o===(bkVal(bk.sel,'status')||'draft')?'selected':''}>${o}</option>`).join('')}</select>
    <div class="dm-save"><button class="btn primary" ${bk.saving?'disabled':''} onclick="bkSave()">${bk.saving?'Saving…':'Save code'}</button><button class="btn" ${bk.drafting?'disabled':''} onclick="bkDraft()">${bk.drafting?'Drafting…':'✦ Draft with ReliCheck Intelligence'}</button><span class="dm-note">ReliCheck Intelligence reads this code's tagged responses and proposes the definition and rules to review.</span></div></div></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">Why this matters</div><div class="dx-l-t">A codebook makes your qualitative analysis transparent and repeatable: each code carries a clear definition, rules for when it applies and when it does not, and the evidence behind it, so another reader could code the same way.</div></div></div>`;
  $("#centerInner").innerHTML=bkHead(s)+helpBar('l_book')+picker+editor+bkEvidencePanel()+layers+bkNav();
}
/* ============ Joint Displays (joint) — quant + qual side by side per theme ============
   Native table on joint-display.php GET (frequency + statistical result on the
   quant side; sentiment + representative quote on the qual side). AI picks the
   illustrative quote per theme. Studio pattern only. */
const jd={base:null,busy:false,err:'',picking:false,choosing:null,candidates:null,cbusy:false,candFallback:false};
function jdFetch(){return fetch('/api/mm/joint-display.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function jdPickAll(){if(jd.picking)return;toast('Working with ReliCheck Intelligence…');jd.picking=true;renderJoint(activeStep());fetch('/api/mm/joint-display.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'pick_all_quotes'})}).then(r=>r.json()).then(j=>{jd.picking=false;if(j&&j.ok){jd.base=null;toast('Picked '+(j.picked||0)+' quotes');renderJoint(activeStep());}else{toast((j&&(j.message||j.error))||'Could not pick quotes.');renderJoint(activeStep());}}).catch(()=>{jd.picking=false;toast('Quote picking failed or timed out.');renderJoint(activeStep());});}
function jdHead(s){return `<div class="ws-header"><div class="eyebrow">Joint display · numbers and narratives together <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function jdNav(){return `${navFooter()}`;}
function jdMsg(s,msg){$("#centerInner").innerHTML=jdHead(s)+helpBar('joint')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+jdNav();}
function jdAnalysis(a){if(!a||!a.test)return '—';const pp=a.predictor?esc(a.predictor):'';const oo=a.outcome?esc(a.outcome):'';const pair=(pp||oo)?` (${pp}${pp&&oo?' → ':''}${oo})`:'';return esc(a.test)+pair;}
function jdEvidenceFetch(cid){return fetch('/api/mm/codebook-evidence.php?project_id='+BOOT.projectId+'&category_id='+cid,{credentials:'same-origin'}).then(r=>r.json());}
function jdAllFetch(){return fetch('/api/mm/responses.php?project_id='+BOOT.projectId+'&limit=60',{credentials:'same-origin'}).then(r=>r.json());}
function jdChoose(themeId){if(jd.choosing===themeId){jd.choosing=null;jd.candidates=null;jd.candFallback=false;renderJoint(activeStep());return;}jd.choosing=themeId;jd.candidates=null;jd.candFallback=false;jd.cbusy=true;renderJoint(activeStep());jdEvidenceFetch(themeId).then(j=>{const coded=(j&&j.ok)?(j.responses||[]):[];if(coded.length){jd.cbusy=false;jd.candidates=coded;jd.candFallback=false;renderJoint(activeStep());}else{jdAllFetch().then(a=>{jd.cbusy=false;jd.candidates=(a&&a.ok)?(a.rows||[]):[];jd.candFallback=true;renderJoint(activeStep());}).catch(()=>{jd.cbusy=false;jd.candidates=[];jd.candFallback=true;renderJoint(activeStep());});}}).catch(()=>{jd.cbusy=false;jd.candidates=null;renderJoint(activeStep());});}
function jdSetQuote(themeId,responseId){fetch('/api/mm/joint-display.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'set_quote',theme_id:themeId,response_id:responseId})}).then(r=>r.json()).then(j=>{if(j&&j.ok){jd.base=null;jd.choosing=null;jd.candidates=null;toast('Quote set');renderJoint(activeStep());}else{toast((j&&(j.message||j.error))||'Could not set quote.');}}).catch(()=>toast('Failed.'));}
function jdManualQuote(themeId){const ta=document.getElementById('jdManualText');const at=document.getElementById('jdManualAttrib');const q=ta?ta.value.trim():'';const a=at?at.value.trim():'';if(!q){toast('Type or paste a quote first');return;}fetch('/api/mm/joint-display.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'set_quote_manual',theme_id:themeId,quote_text:q,attribution:a})}).then(r=>r.json()).then(j=>{if(j&&j.ok){jd.base=null;jd.choosing=null;jd.candidates=null;toast('Quote saved');renderJoint(activeStep());}else{toast((j&&(j.message||j.error))||'Could not save quote.');}}).catch(()=>toast('Failed.'));}
function jdCandidatePanel(){if(jd.choosing==null)return '';const t=((jd.base&&jd.base.rows)||[]).find(r=>r.theme_id===jd.choosing);const name=t?t.theme_name:'';
  // Manual entry is ALWAYS available — the researcher can type/paste a quote
  // whether or not any responses are tagged.
  const manual=`<div style="margin:8px 0;padding:10px;border:1px dashed var(--line);border-radius:10px">
    <div class="dm-note" style="margin:0 0 6px">Type or paste a quote yourself:</div>
    <textarea id="jdManualText" class="ed-in" rows="2" placeholder="Paste the verbatim quote for this theme…"></textarea>
    <div style="display:flex;gap:8px;margin-top:6px;align-items:center;flex-wrap:wrap"><input id="jdManualAttrib" class="ed-in" style="max-width:220px" placeholder="Participant ID (optional)"><button class="btn primary" style="padding:4px 12px" onclick="jdManualQuote(${jd.choosing})">Use typed quote</button></div></div>`;
  let pick;
  if(jd.cbusy){pick=`<div class="th-quotes" style="padding:12px 14px">Loading responses…</div>`;}
  else{const c=jd.candidates;
    if(!c||!c.length){pick=`<div class="dm-note" style="margin:6px 0">No tagged or open-ended responses to pick from yet — type a quote above, or tag responses on the Qualitative Themes step.</div>`;}
    else{const note=jd.candFallback?`<div class="dm-note" style="margin:0 0 8px">No responses are tagged to "${esc(name)}" yet — pick any response below, or type your own above.</div>`:'';const rows=c.slice(0,15).map(r=>{const rid=r.response_id||r.id;return `<div class="th-quote"><div class="th-quote-t">"${esc(r.text)}"</div><div class="th-quote-m">${r.respondent_ref?'<span>'+esc(r.respondent_ref)+'</span>':''}${r.group_value?'<span>· '+esc(r.group_value)+'</span>':''}${r.sentiment?'<span>· '+esc(r.sentiment)+'</span>':''} <button class="btn" style="padding:2px 9px" onclick="jdSetQuote(${jd.choosing},${rid})">Use this quote</button></div></div>`;}).join('');pick=`${note}<div class="th-quotes">${rows}</div>`;}}
  return `<div class="ov-sec" style="margin-top:2px">Set a quote for ${esc(name)}</div>${manual}${pick}`;}
function renderJoint(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=jdHead(s)+helpBar('joint')+`<p class="lede">Connect a project with themes and analyses to build a joint display.</p>`+jdNav();return;}
  if(jd.err){jdMsg(s,jd.err);return;}
  if(!jd.base){
    if(!jd.busy){jd.busy=true;jdFetch().then(j=>{jd.busy=false;if(j&&j.ok){jd.base=j;}else{jd.err=(j&&(j.message||j.error))||'Could not load the joint display.';}renderJoint(activeStep());}).catch(()=>{jd.busy=false;jd.err='Could not load your data.';renderJoint(activeStep());});}
    jdMsg(s,'Building your joint display…');return;
  }
  const d=jd.base,rows=d.rows||[];
  if(!rows.length){$("#centerInner").innerHTML=jdHead(s)+helpBar('joint')+`<div class="th-empty"><h3>Nothing to display yet</h3><p>Discover themes and tag responses on the Qualitative Themes step (and run your quantitative tests) first. The joint display merges both strands per theme.</p></div>`+jdNav();return;}
  const anyQuote=rows.some(r=>r.quote&&r.quote.text);
  const noTags=rows.every(r=>!(r.frequency&&r.frequency.n));
  const tagWarn=noTags?`<div class="dm-note" style="margin-bottom:12px;padding:10px 12px;border:1px solid #e8d9a8;background:#fdf7e3;border-radius:10px">No responses are tagged to your themes yet, so ReliCheck Intelligence cannot auto-pick quotes. You can still build the display: click <b>Choose quote</b> on any theme to <b>type or paste a quote by hand</b>, or tag responses on the Qualitative Themes step first.</div>`:'';
  const body=rows.map(r=>{const f=r.frequency||{};const sp=(r.sentiment&&r.sentiment.percent)||{};
    return `<tr><td class="dx-name">${esc(r.theme_name)}</td><td><div class="th-cov">${f.n||0} <span style="color:var(--ink-3)">(${f.percent||0}%)</span></div></td><td class="dx-interp">${jdAnalysis(r.analysis)}</td><td class="th-sent">${thSent({positive:sp.positive,negative:sp.negative,neutral:(sp.neutral||0)+(sp.mixed||0)})}</td><td class="dx-interp">${r.quote&&r.quote.text?'"'+esc(r.quote.text)+'"':'<span style="color:var(--ink-3)">— no quote yet —</span>'}<div style="margin-top:5px"><button class="btn" style="padding:3px 9px" onclick="jdChoose(${r.theme_id})">${jd.choosing===r.theme_id?'Close':'Choose quote'}</button></div></td></tr>`;}).join('');
  const table=`<div class="panel"><div class="panel-h"><div><h3>Joint display · ${rows.length} themes</h3><div class="ph-sub">${d.total_responses||0} open-ended responses</div></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Theme</th><th class="l">Frequency (QUAN)</th><th class="l">Statistical result (QUAN)</th><th class="l">Sentiment (QUAL)</th><th class="l">Representative quote (QUAL)</th></tr></thead><tbody>${body}</tbody></table></div></div></div>`;
  const bar=`<div class="dm-save"><button class="btn" ${jd.picking||noTags?'disabled':''} onclick="jdPickAll()">${jd.picking?'Picking quotes…':'✦ Pick all with ReliCheck Intelligence'}</button><span class="dm-note">${noTags?'Auto-pick needs tagged responses. Use Choose quote → type a quote, or tag first.':'Choose a quote per theme yourself (above), or let ReliCheck Intelligence pick them all at once.'}</span></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">Each row is one theme seen through both strands at once: how common and how strong it is in the numbers, and the tone and a real quote from the narratives. Rows where the number and the quote agree are convergence; rows where they disagree are what you explain next.</div></div></div>`;
  $("#centerInner").innerHTML=jdHead(s)+helpBar('joint')+tagWarn+bar+table+jdCandidatePanel()+layers+jdNav();
}
/* ============ Qual → Quant (q2q) — quantitize themes into measurable variables ============
   Turns each theme into per-respondent variables (presence 0/1, intensity 0-3,
   sentiment, length) via dataset.php, linked back to the theme by
   source_category_id so they can be tested and feed the Joint Display. */
const q2={base:null,busy:false,err:'',building:false,result:null,vars:{presence:true,intensity:false,sentiment:true,length:false}};
function q2Fetch(){return fetch('/api/mm/codebook.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function q2Toggle(k){q2.vars[k]=!q2.vars[k];renderQ2Q(activeStep());}
function q2Build(){if(q2.building)return;q2.building=true;renderQ2Q(activeStep());const v=q2.vars;const variables={presence:!!v.presence,intensity:!!v.intensity,sentiment_cat:!!v.sentiment,sentiment_num:!!v.sentiment,length_chars:!!v.length,length_words:!!v.length};fetch('/api/mm/dataset.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,title:'Quantitized themes',variables:variables})}).then(r=>r.json()).then(j=>{q2.building=false;if(j&&j.ok){q2.result=j;mt.vars=null;mt.result=null;mt.pred=0;mt.out=0;jd.base=null;toast('Created '+(j.col_count||0)+' variables');renderQ2Q(activeStep());}else{toast((j&&(j.message||j.error))||'Could not build variables.');renderQ2Q(activeStep());}}).catch(()=>{q2.building=false;toast('Build failed.');renderQ2Q(activeStep());});}
function q2Head(s){return `<div class="ws-header"><div class="eyebrow">Qual → Quant · make themes measurable <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function q2Nav(){return `${navFooter()}`;}
function q2Msg(s,msg){$("#centerInner").innerHTML=q2Head(s)+helpBar('q2q')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+q2Nav();}
function q2Opt(k,label,desc){const on=!!q2.vars[k];return `<label class="dq-row" style="cursor:pointer"><input type="checkbox" ${on?'checked':''} onchange="q2Toggle('${k}')" style="margin-right:4px"><div class="dq-body"><div class="dq-name">${esc(label)}</div><div class="dq-risk">${esc(desc)}</div></div></label>`;}
function renderQ2Q(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=q2Head(s)+helpBar('q2q')+`<p class="lede">Connect a project with themes to turn them into measurable variables.</p>`+q2Nav();return;}
  if(q2.err){q2Msg(s,q2.err);return;}
  if(!q2.base){
    if(!q2.busy){q2.busy=true;q2Fetch().then(j=>{q2.busy=false;if(j&&j.ok){q2.base=j;}else{q2.err=(j&&(j.message||j.error))||'Could not load themes.';}renderQ2Q(activeStep());}).catch(()=>{q2.busy=false;q2.err='Could not load your data.';renderQ2Q(activeStep());});}
    q2Msg(s,'Loading your themes…');return;
  }
  const themes=q2.base.entries||[];
  if(!themes.length){$("#centerInner").innerHTML=q2Head(s)+helpBar('q2q')+`<div class="th-empty"><h3>No themes yet</h3><p>Build themes on the Qualitative Themes step first, then return here to turn them into measurable variables.</p></div>`+q2Nav();return;}
  const nameById={};themes.forEach(t=>nameById[t.category_id]=t.name);
  const opts=`<div class="dq-card">${q2Opt('presence','Theme presence (0/1)','For each respondent, whether each theme appears in their response.')}${q2Opt('intensity','Theme intensity (0–3)','How strongly each theme appears (needs intensity coding).')}${q2Opt('sentiment','Sentiment','The sentiment of each response, as a label and a number.')}${q2Opt('length','Response length','How long each open-ended response is (characters and words).')}</div>`;
  const anySel=q2.vars.presence||q2.vars.intensity||q2.vars.sentiment||q2.vars.length;
  const buildBar=`<div class="dm-save"><button class="btn primary" ${q2.building||!anySel?'disabled':''} onclick="q2Build()">${q2.building?'Creating variables…':'Create measurable variables'}</button><span class="dm-note">Creates per-respondent variables from your ${themes.length} themes, linked back to each theme.</span></div>`;
  let resultCard='';
  if(q2.result&&q2.result.columns){const cols=q2.result.columns;const rows=cols.map(c=>`<tr><td class="dx-name">${esc(c.label||c.var_name)}</td><td class="dx-interp">${esc(c.type||'')}</td><td class="dx-interp">${c.category_id&&nameById[c.category_id]?esc(nameById[c.category_id]):'—'}</td></tr>`).join('');
    resultCard=`<div class="panel"><div class="panel-h"><div><h3>${cols.length} variables created</h3><div class="ph-sub">Now testable in Build & Test Measures</div></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variable</th><th class="l">Type</th><th class="l">From theme</th></tr></thead><tbody>${rows}</tbody></table></div></div></div>`;}
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What happens next</div><div class="dx-l-t">These quantitized variables join your dataset linked to the themes they came from. Test them on Build & Test Measures (for example, does a theme appear more in one group?), and the result will appear beside the theme in the Joint Display.</div></div></div>`;
  $("#centerInner").innerHTML=q2Head(s)+helpBar('q2q')+`<div class="ov-sec" style="margin-top:2px">Choose what to create from your ${themes.length} themes</div>`+opts+buildBar+resultCard+layers+q2Nav();
}
/* ============ Build & Test Measures — test theme-derived variables (PERSISTED) ============
   Lists the q2q generated variables (generated-variables.php), runs a stored test
   on two of them via analysis-run.php (writes mm_analysis_results), so the result
   links back to the theme and the Joint Display's statistical column populates. */
const mt={vars:null,busy:false,err:'',pred:0,out:0,test:'t_test',running:false,result:null};
function mtFetch(){return fetch('/api/mm/generated-variables.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function mtSet(k,v){mt[k]=(k==='test')?v:+v;renderMeasureTest(activeStep());}
function mtRun(){if(mt.running||!mt.pred||!mt.out||mt.pred===mt.out)return;mt.running=true;renderMeasureTest(activeStep());fetch('/api/mm/analysis-run.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,predictor_id:mt.pred,outcome_id:mt.out,test:mt.test})}).then(r=>r.json()).then(j=>{mt.running=false;if(j&&j.ok){mt.result=j.result;jd.base=null;toast('Test run and saved');renderMeasureTest(activeStep());}else{toast((j&&(j.message||j.error))||'Could not run the test.');renderMeasureTest(activeStep());}}).catch(()=>{mt.running=false;toast('Test failed.');renderMeasureTest(activeStep());});}
function mtHead(s){return `<div class="ws-header"><div class="eyebrow">Build & test measures · test the measures from your themes <span class="strand-chip quan">QUAN</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function mtNav(){return `${navFooter()}`;}
function mtMsg(s,msg){$("#centerInner").innerHTML=mtHead(s)+helpBar('q_build')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+mtNav();}
function mtSel(cur,vars,onch){return `<select class="ed-in dm-sel" style="max-width:320px" onchange="${onch}"><option value="0">— choose —</option>${vars.map(v=>`<option value="${v.id}" ${v.id===cur?'selected':''}>${esc(v.name)}${v.theme?' · '+esc(v.theme):''}</option>`).join('')}</select>`;}
function renderMeasureTest(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=mtHead(s)+helpBar('q_build')+`<p class="lede">Connect a project to test your measures.</p>`+mtNav();return;}
  if(mt.err){mtMsg(s,mt.err);return;}
  if(mt.vars==null){
    if(!mt.busy){mt.busy=true;mtFetch().then(j=>{mt.busy=false;if(j&&j.ok){mt.vars=j.variables||[];}else{mt.err=(j&&(j.message||j.error))||'Could not load measures.';}renderMeasureTest(activeStep());}).catch(()=>{mt.busy=false;mt.err='Could not load your data.';renderMeasureTest(activeStep());});}
    mtMsg(s,'Loading your measures…');return;
  }
  const vars=mt.vars;
  if(!vars.length){$("#centerInner").innerHTML=mtHead(s)+helpBar('q_build')+`<div class="th-empty"><h3>No theme measures yet</h3><p>Turn your themes into measurable variables on the <b>Qual → Quant</b> step first. Then come back here to test them — for example, whether a theme's presence relates to a group or another measure.</p></div>`+mtNav();return;}
  const TESTS=[['t_test','t-test'],['chi_square','Chi-square'],['anova','ANOVA'],['pearson','Correlation']];
  const picker=`<div class="panel"><div class="panel-h"><div><h3>Test a measure</h3><div class="ph-sub">Pick two of your theme-derived variables and a test</div></div></div><div class="panel-b">
    <label class="ed-l">Predictor / group</label>${mtSel(mt.pred,vars,"mtSet('pred',this.value)")}
    <label class="ed-l">Outcome / measure</label>${mtSel(mt.out,vars,"mtSet('out',this.value)")}
    <label class="ed-l">Test</label><select class="ed-in dm-sel" style="max-width:200px" onchange="mtSet('test',this.value)">${TESTS.map(t=>`<option value="${t[0]}" ${t[0]===mt.test?'selected':''}>${t[1]}</option>`).join('')}</select>
    <div class="dm-save"><button class="btn primary" ${mt.running||!mt.pred||!mt.out||mt.pred===mt.out?'disabled':''} onclick="mtRun()">${mt.running?'Running…':'Run test'}</button><span class="dm-note">Runs the test and saves it, so the result appears beside the theme in the Joint Display.</span></div></div></div>`;
  let resultCard='';
  if(mt.result){const r=mt.result;resultCard=`<div class="panel"><div class="panel-h"><div><h3>Result</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Test</th><th>Statistic</th><th>p</th><th class="l">Effect</th><th>N</th></tr></thead><tbody><tr><td class="dx-name">${esc(r.predictor_name)} × ${esc(r.outcome_name)} (${esc(r.test)})</td><td>${r.statistic!=null?Number(r.statistic).toFixed(3):'—'}</td><td>${r.p_value!=null?Number(r.p_value).toFixed(4):'—'}</td><td class="dx-interp">${r.effect_size!=null?Number(r.effect_size).toFixed(3)+(r.effect_label?' ('+esc(r.effect_label)+')':''):'—'}</td><td>${r.n_total||'—'}</td></tr></tbody></table></div>${r.summary?`<div class="dx-layers" style="margin-top:14px"><div class="dx-l"><div class="dx-l-k">Reading it</div><div class="dx-l-t">${esc(r.summary)} This result is now linked to its theme in the Joint Display.</div></div></div>`:''}</div></div>`;}
  $("#centerInner").innerHTML=mtHead(s)+helpBar('q_build')+picker+resultCard+mtNav();
}
/* ============ Convergence & Divergence (converge) — name where the strands meet ============
   Reuses joint-display.php data (per-theme quant + qual) so you classify each
   theme's alignment and write your reading (saved via save_notes). Manual-first;
   ReliCheck Intelligence "suggest alignment" (alignment.php) is the secondary assist. */
const cv={base:null,busy:false,err:'',edits:{},saving:0,ai:null,aibusy:false};
function cvFetch(){return fetch('/api/mm/joint-display.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function cvCapture(){((cv.base&&cv.base.rows)||[]).forEach(r=>{const el=document.getElementById('cv_note_'+r.theme_id);if(el)cv.edits[r.theme_id]=el.value;});}
function cvClassify(themeId,label){const el=document.getElementById('cv_note_'+themeId);if(!el)return;let body=el.value.trim();['Converge','Diverge','Nuanced','Insufficient'].forEach(l=>{if(body.indexOf(l+':')===0)body=body.slice(l.length+1).trim();});el.value=label+': '+body;cv.edits[themeId]=el.value;el.focus();}
function cvSave(themeId){cvCapture();const note=cv.edits[themeId]||'';cv.saving=themeId;renderConverge(activeStep());fetch('/api/mm/joint-display.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'save_notes',theme_id:themeId,notes:note})}).then(r=>r.json()).then(j=>{cv.saving=0;if(j&&j.ok){toast('Reading saved');}else{toast((j&&(j.message||j.error))||'Could not save.');}renderConverge(activeStep());}).catch(()=>{cv.saving=0;toast('Save failed.');renderConverge(activeStep());});}
function cvSuggest(){if(cv.aibusy)return;
  const themes=((cv.base&&cv.base.rows)||[]).map(r=>{const f=r.frequency||{};const sp=(r.sentiment&&r.sentiment.percent)||{};return {name:r.theme_name,freq_n:f.n||0,freq_pct:f.percent||0,sentiment:{positive:sp.positive||0,negative:sp.negative||0,neutral:sp.neutral||0,mixed:sp.mixed||0},analysis:r.analysis||null,quote:(r.quote&&r.quote.text)?r.quote.text.slice(0,300):''};});
  if(!themes.length){toast('Build the joint display (themes + quotes) first.');return;}
  toast('Working with ReliCheck Intelligence…');cvCapture();cv.aibusy=true;renderConverge(activeStep());
  fetch('/api/mm/alignment.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,themes:themes})}).then(r=>r.json()).then(j=>{cv.aibusy=false;cv.ai=(j&&j.ok)?j:null;if(!(j&&j.ok))toast((j&&(j.message||j.error))||'Could not analyze alignment.');renderConverge(activeStep());}).catch(()=>{cv.aibusy=false;toast('Analysis failed or timed out.');renderConverge(activeStep());});}
function cvHead(s){return `<div class="ws-header"><div class="eyebrow">Convergence & divergence · where the strands meet <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function cvNav(){return `${navFooter()}`;}
function cvMsg(s,msg){$("#centerInner").innerHTML=cvHead(s)+helpBar('converge')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+cvNav();}
function cvAiPanel(){if(cv.aibusy)return `<div class="th-quotes" style="padding:16px 18px">ReliCheck Intelligence is analyzing alignment…</div>`;if(!cv.ai)return '';const f=cv.ai.findings||[];const rows=f.map(x=>`<tr><td class="dx-name">${esc(x.quant_label||'')}</td><td><span class="tt-status ${x.alignment==='aligned'?'ok':'rev'}">${esc(x.alignment||'')}</span></td><td class="dx-interp">${esc(x.interpretation||'')}</td></tr>`).join('');return `<div class="ov-sec" style="margin-top:6px">ReliCheck Intelligence · suggested alignment</div>${cv.ai.summary?`<div class="dm-note" style="margin:0 0 8px">${esc(cv.ai.summary)}</div>`:''}${f.length?`<div class="panel"><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Finding</th><th class="l">Alignment</th><th class="l">Reading</th></tr></thead><tbody>${rows}</tbody></table></div></div></div>`:`<div class="th-quotes" style="padding:14px 18px">No quant-linked findings to align yet.</div>`}`;}
function renderConverge(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=cvHead(s)+helpBar('converge')+`<p class="lede">Connect a project with themes and analyses to compare the strands.</p>`+cvNav();return;}
  if(cv.err){cvMsg(s,cv.err);return;}
  if(!cv.base){
    if(!cv.busy){cv.busy=true;cvFetch().then(j=>{cv.busy=false;if(j&&j.ok){cv.base=j;}else{cv.err=(j&&(j.message||j.error))||'Could not load the comparison.';}renderConverge(activeStep());}).catch(()=>{cv.busy=false;cv.err='Could not load your data.';renderConverge(activeStep());});}
    cvMsg(s,'Laying out the two strands…');return;
  }
  const rows=cv.base.rows||[];
  if(!rows.length){$("#centerInner").innerHTML=cvHead(s)+helpBar('converge')+`<div class="th-empty"><h3>Nothing to compare yet</h3><p>Build themes, tag responses, and run your tests first. This step then lays each theme's quantitative and qualitative evidence side by side.</p></div>`+cvNav();return;}
  const cards=rows.map(r=>{const f=r.frequency||{};const sp=(r.sentiment&&r.sentiment.percent)||{};const note=(cv.edits[r.theme_id]!=null)?cv.edits[r.theme_id]:(r.notes||'');const sv=cv.saving===r.theme_id;
    return `<div class="panel"><div class="panel-b">
      <div style="font-size:15px;font-weight:700;margin-bottom:8px">${esc(r.theme_name)}</div>
      <div class="ov-row" style="border:none;padding:6px 0"><div class="ov-k">Quantitative</div><div class="ov-v"><span class="th-cov">${f.n||0} (${f.percent||0}%)</span> · ${jdAnalysis(r.analysis)}</div></div>
      <div class="ov-row" style="border:none;padding:6px 0"><div class="ov-k">Qualitative</div><div class="ov-v"><span class="th-sent">${thSent({positive:sp.positive,negative:sp.negative,neutral:(sp.neutral||0)+(sp.mixed||0)})}</span>${r.quote&&r.quote.text?' · "'+esc(r.quote.text.slice(0,120))+'"':''}</div></div>
      <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">${['Converge','Nuanced','Diverge'].map(l=>`<button class="btn" style="padding:4px 10px" onclick="cvClassify(${r.theme_id},'${l}')">${l}</button>`).join('')}</div>
      <textarea id="cv_note_${r.theme_id}" class="ed-in" rows="2" style="margin-top:8px" placeholder="Your reading: where do the strands agree or diverge, and why?">${esc(note)}</textarea>
      <div class="dm-save"><button class="btn primary" ${sv?'disabled':''} onclick="cvSave(${r.theme_id})">${sv?'Saving…':'Save reading'}</button></div>
    </div></div>`;}).join('');
  const aiBar=`<div class="dm-save"><button class="btn" ${cv.aibusy?'disabled':''} onclick="cvSuggest()">${cv.aibusy?'Analyzing…':'✦ Suggest alignment with ReliCheck Intelligence'}</button><span class="dm-note">Classify each theme yourself above, or get a suggested alignment from the quant-linked findings.</span></div>`;
  $("#centerInner").innerHTML=cvHead(s)+helpBar('converge')+cards+aiBar+cvAiPanel()+cvNav();
}
/* ============ Meta-inferences (meta) — study-level synthesis ============
   The higher-order conclusions only the combined evidence supports. Reuses the
   convergence readings (joint-display notes) as raw material; the synthesis saves
   to the project notes (project.php), which the Report Builder reads. Manual-first. */
const mi={notes:'',rows:null,loaded:false,busy:false,err:'',saving:false};
function miFetch(){return Promise.all([fetch('/api/mm/project.php?id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null),fetch('/api/mm/joint-display.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null)]);}
function miScaffold(){const el=document.getElementById('miText');if(!el)return;const reads=(mi.rows||[]).filter(r=>r.notes&&r.notes.trim()).map(r=>'• '+r.theme_name+': '+r.notes.trim());if(!reads.length){toast('No convergence readings yet');return;}el.value=(el.value.trim()?el.value.trim()+'\n\n':'')+'From my convergence readings:\n'+reads.join('\n');el.focus();}
function miSave(){const el=document.getElementById('miText');const notes=el?el.value:'';mi.saving=true;renderMeta(activeStep());fetch('/api/mm/project.php',{method:'PATCH',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:BOOT.projectId,notes:notes})}).then(r=>r.json()).then(j=>{mi.saving=false;mi.notes=notes;if(j&&j.ok){toast('Meta-inferences saved');}else{toast((j&&(j.message||j.error))||'Could not save.');}renderMeta(activeStep());}).catch(()=>{mi.saving=false;toast('Save failed.');renderMeta(activeStep());});}
function miHead(s){return `<div class="ws-header"><div class="eyebrow">Meta-inferences · what the whole study concludes <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function miNav(){return `${navFooter()}`;}
function miMsg(s,msg){$("#centerInner").innerHTML=miHead(s)+helpBar('meta')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+miNav();}
function renderMeta(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=miHead(s)+helpBar('meta')+`<p class="lede">Connect a project to draw the study's meta-inferences.</p>`+miNav();return;}
  if(mi.err){miMsg(s,mi.err);return;}
  if(!mi.loaded){
    if(!mi.busy){mi.busy=true;miFetch().then(a=>{mi.busy=false;mi.loaded=true;const p=a[0],j=a[1];mi.notes=(p&&p.ok&&p.project&&p.project.notes)?p.project.notes:'';mi.rows=(j&&j.ok)?(j.rows||[]):[];renderMeta(activeStep());}).catch(()=>{mi.busy=false;mi.err='Could not load your data.';renderMeta(activeStep());});}
    miMsg(s,'Loading your readings…');return;
  }
  const reads=(mi.rows||[]).filter(r=>r.notes&&r.notes.trim());
  const refPanel=reads.length?`<div class="panel"><div class="panel-h"><div><h3>Your convergence readings</h3><div class="ph-sub">The per-theme judgments to synthesize from</div></div></div><div class="panel-b">${reads.map(r=>`<div class="ov-row" style="padding:8px 0"><div class="ov-k" style="width:210px">${esc(r.theme_name)}</div><div class="ov-v">${esc(r.notes)}</div></div>`).join('')}</div></div>`:`<div class="dm-note" style="margin-bottom:12px">Tip: record convergence readings on the previous step first; they feed your meta-inferences.</div>`;
  const composer=`<div class="panel"><div class="panel-h"><div><h3>Meta-inferences</h3><div class="ph-sub">The higher-order conclusions only the combined evidence supports</div></div></div><div class="panel-b"><textarea id="miText" class="ed-in" rows="8" placeholder="What can you conclude from the whole study that neither the numbers nor the narratives could show alone?">${esc(mi.notes||'')}</textarea><div class="dm-save"><button class="btn primary" ${mi.saving?'disabled':''} onclick="miSave()">${mi.saving?'Saving…':'Save meta-inferences'}</button><button class="btn" onclick="miScaffold()">Start from my readings</button><span class="dm-note">Saved to the project and carried into the Report Builder.</span></div></div></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this is</div><div class="dx-l-t">A meta-inference is a conclusion the combined evidence supports that neither strand could establish on its own. Draw on where the strands converged and diverged.</div></div></div>`;
  $("#centerInner").innerHTML=miHead(s)+helpBar('meta')+refPanel+composer+layers+miNav();
}
/* ============ Integrated Interpretation (interp) — per-theme integration paragraphs ============
   integration.php GET gives each theme's combined evidence + an editable
   interpretation paragraph (save_text manual / generate AI). Manual-first; the
   tool box and a per-theme button offer ReliCheck Intelligence as the assist. */
const ip={base:null,busy:false,err:'',edits:{},saving:0,gen:0,genAll:false};
function ipFetch(){return fetch('/api/mm/integration.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function ipCapture(){((ip.base&&ip.base.rows)||[]).forEach(r=>{const el=document.getElementById('ip_'+r.theme_id);if(el)ip.edits[r.theme_id]=el.value;});}
function ipPara(r){return (ip.edits[r.theme_id]!=null)?ip.edits[r.theme_id]:(r.paragraph||'');}
function ipSave(themeId){ipCapture();const text=ip.edits[themeId]||'';ip.saving=themeId;renderInterp(activeStep());fetch('/api/mm/integration.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'save_text',theme_id:themeId,paragraph_text:text})}).then(r=>r.json()).then(j=>{ip.saving=0;if(j&&j.ok){toast('Interpretation saved');}else{toast((j&&(j.message||j.error))||'Could not save.');}renderInterp(activeStep());}).catch(()=>{ip.saving=0;toast('Save failed.');renderInterp(activeStep());});}
function ipGenerate(themeId){if(ip.gen)return;toast('Working with ReliCheck Intelligence…');ipCapture();ip.gen=themeId;renderInterp(activeStep());fetch('/api/mm/integration.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'generate',theme_id:themeId})}).then(r=>r.json()).then(j=>{ip.gen=0;if(j&&j.ok&&j.paragraph){ip.edits[themeId]=j.paragraph;toast('Draft ready — review and save');}else{toast((j&&(j.message||j.error))||'Could not draft.');}renderInterp(activeStep());}).catch(()=>{ip.gen=0;toast('Draft failed.');renderInterp(activeStep());});}
function ipGenerateAll(){if(ip.genAll)return;toast('Working with ReliCheck Intelligence…');ipCapture();ip.genAll=true;renderInterp(activeStep());fetch('/api/mm/integration.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'generate_all'})}).then(r=>r.json()).then(j=>{ip.genAll=false;if(j&&j.ok){ip.base=null;ip.edits={};toast('Interpretations drafted');}else{toast((j&&(j.message||j.error))||'Could not draft.');}renderInterp(activeStep());}).catch(()=>{ip.genAll=false;toast('Draft failed or timed out.');renderInterp(activeStep());});}
function ipHead(s){return `<div class="ws-header"><div class="eyebrow">Integrated interpretation · what it means, theme by theme <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function ipNav(){return `${navFooter()}`;}
function ipMsg(s,msg){$("#centerInner").innerHTML=ipHead(s)+helpBar('interp')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+ipNav();}
function renderInterp(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=ipHead(s)+helpBar('interp')+`<p class="lede">Connect a project to interpret the combined evidence.</p>`+ipNav();return;}
  if(ip.err){ipMsg(s,ip.err);return;}
  if(!ip.base){
    if(!ip.busy){ip.busy=true;ipFetch().then(j=>{ip.busy=false;if(j&&j.ok){ip.base=j;}else{ip.err=(j&&(j.message||j.error))||'Could not load the interpretation.';}renderInterp(activeStep());}).catch(()=>{ip.busy=false;ip.err='Could not load your data.';renderInterp(activeStep());});}
    ipMsg(s,'Gathering the evidence per theme…');return;
  }
  const rows=ip.base.rows||[];
  if(!rows.length){$("#centerInner").innerHTML=ipHead(s)+helpBar('interp')+`<div class="th-empty"><h3>Nothing to interpret yet</h3><p>Build themes and tag responses first. This step then lays out each theme's combined evidence for you to interpret.</p></div>`+ipNav();return;}
  const cards=rows.map(r=>{const f=r.frequency||{};const sp=(r.sentiment&&r.sentiment.percent)||{};const sv=ip.saving===r.theme_id;const gn=ip.gen===r.theme_id;const src=r.source==='ai'?'<span class="tt-status rev">ReliCheck Intelligence draft</span>':(r.source==='user'?'<span class="tt-status ok">Yours</span>':'');
    return `<div class="panel"><div class="panel-b">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><div style="font-size:15px;font-weight:700">${esc(r.theme_name)}</div>${src}</div>
      <div class="dm-note" style="margin:6px 0 8px">${f.n||0} (${f.percent||0}%) · ${thSent({positive:sp.positive,negative:sp.negative,neutral:(sp.neutral||0)+(sp.mixed||0)})} · ${jdAnalysis(r.analysis)}${r.quote&&r.quote.text?' · "'+esc(r.quote.text.slice(0,100))+'"':''}</div>
      <textarea id="ip_${r.theme_id}" class="ed-in" rows="4" placeholder="What does the combined evidence mean for this theme, and for the decision?">${esc(ipPara(r))}</textarea>
      <div class="dm-save"><button class="btn primary" ${sv?'disabled':''} onclick="ipSave(${r.theme_id})">${sv?'Saving…':'Save interpretation'}</button><button class="btn" ${gn?'disabled':''} onclick="ipGenerate(${r.theme_id})">${gn?'Drafting…':'✦ Draft with ReliCheck Intelligence'}</button></div>
    </div></div>`;}).join('');
  $("#centerInner").innerHTML=ipHead(s)+helpBar('interp')+cards+ipNav();
}
/* ============ Evidence Strength (evidence_strength) — strength checks ============
   strength-check.php runs checks across the quant/qual/integration work (pass |
   fix | skip + severity). Manual run; "Include ReliCheck Intelligence checks" is
   an opt-in. Needs the Phase 161 table; degrades to a clear note if absent. */
const sg={base:null,busy:false,err:'',running:false,includeAi:false};
function sgFetch(){return fetch('/api/mm/strength-check.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function sgToggleAi(){sg.includeAi=!sg.includeAi;renderStrength(activeStep());}
function sgRun(){if(sg.running)return;sg.running=true;if(sg.includeAi)toast('Working with ReliCheck Intelligence…');renderStrength(activeStep());fetch('/api/mm/strength-check.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,include_ai:sg.includeAi})}).then(r=>r.json()).then(j=>{sg.running=false;if(j&&j.ok){sg.base=j;toast('Strength checks complete');}else{toast((j&&(j.message||j.error))||'Could not run checks.');}renderStrength(activeStep());}).catch(()=>{sg.running=false;toast('Run failed.');renderStrength(activeStep());});}
function sgDedupe(rows){const seen={};const out=[];(rows||[]).forEach(r=>{if(seen[r.check_key])return;seen[r.check_key]=1;out.push(r);});return out;}
function sgBadge(r){if(r.status==='pass')return '<span class="tt-status ok">Pass</span>';if(r.status==='skip')return '<span style="color:var(--ink-3)">— not yet —</span>';return `<span class="tt-status rev">${r.severity==='high'?'Fix':'Review'}</span>`;}
function sgHead(s){return `<div class="ws-header"><div class="eyebrow">Evidence strength · how strong is the integrated evidence <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function sgNav(){return `${navFooter()}`;}
function sgMsg(s,msg){$("#centerInner").innerHTML=sgHead(s)+helpBar('evidence_strength')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+sgNav();}
function renderStrength(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=sgHead(s)+helpBar('evidence_strength')+`<p class="lede">Connect a project to gauge the strength of its integrated evidence.</p>`+sgNav();return;}
  if(sg.err){sgMsg(s,sg.err);return;}
  if(!sg.base){
    if(!sg.busy){sg.busy=true;sgFetch().then(j=>{sg.busy=false;if(j&&j.ok){sg.base=j;}else{sg.err=(j&&(j.message||j.error))||'Could not load the checks.';}renderStrength(activeStep());}).catch(()=>{sg.busy=false;sg.err='Could not load your data.';renderStrength(activeStep());});}
    sgMsg(s,'Loading the strength checks…');return;
  }
  const d=sg.base,noTable=(d.has_table===false),rows=sgDedupe(d.rows);
  const pass=rows.filter(r=>r.status==='pass').length,fix=rows.filter(r=>r.status==='fix').length;
  const note=noTable?`<div class="dm-note" style="margin-bottom:12px">Evidence-strength checks need a one-time database migration (schema_phase161.sql) before they can run.</div>`:'';
  const runBar=`<div class="dm-save"><button class="btn primary" ${sg.running||noTable?'disabled':''} onclick="sgRun()">${sg.running?'Running checks…':(rows.length?'Re-run strength checks':'Run strength checks')}</button><label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--ink-2)"><input type="checkbox" ${sg.includeAi?'checked':''} onchange="sgToggleAi()"> Include ReliCheck Intelligence checks</label><span class="dm-note">${rows.length?(pass+' pass · '+fix+' to fix'):'Deterministic checks; the optional ones use ReliCheck Intelligence.'}</span></div>`;
  let table='';
  if(rows.length){const body=rows.map(r=>`<tr><td class="dx-name">${esc(r.title||r.check_key||'')}</td><td>${sgBadge(r)}</td><td class="dx-interp">${esc(r.message||'')}</td><td class="dx-interp">${(r.status!=='pass'&&r.status!=='skip'&&r.fix_hint)?esc(r.fix_hint):'—'}</td></tr>`).join('');
    table=`<div class="panel"><div class="panel-h"><div><h3>Strength checks</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Check</th><th class="l">Status</th><th class="l">What it means</th><th class="l">How to fix</th></tr></thead><tbody>${body}</tbody></table></div></div></div>`;}
  else if(!noTable)table=`<div class="th-empty"><h3>Not run yet</h3><p>Run the strength checks to gauge how well your integrated evidence supports the claims you're about to report.</p></div>`;
  const layers=rows.length?`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">Each check looks at one way your evidence could be weak — too small a sample, thin coding, unintegrated strands. Clear the high-severity fixes before you write the report.</div></div></div>`:'';
  $("#centerInner").innerHTML=sgHead(s)+helpBar('evidence_strength')+note+runBar+table+layers+sgNav();
}
/* ============ Report Builder (report) — assemble the write-up ============
   report.php's 7-section model. Templated sections fill from data; AI sections
   (exec summary, integration, recommendations) draft from findings; every
   section is editable + saved (save_section). Manual-first; "Build the full
   report" (generate_all) and per-section generate are the assists. */
const rp={base:null,busy:false,err:'',edits:{},saving:'',gen:'',genAll:false};
function rpFetch(){return fetch('/api/mm/report.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function rpDownloadDocx(){const a=document.createElement('a');a.href='/api/mm/report-docx.php?project_id='+BOOT.projectId;a.click();toast('Downloading Word…');}
function rpDownloadMd(){const a=document.createElement('a');a.href='/api/mm/report-export.php?project_id='+BOOT.projectId+'&format=md';a.click();toast('Downloading Markdown…');}
function rpRowByKey(){const m={};((rp.base&&rp.base.rows)||[]).forEach(r=>m[r.section_key]=r);return m;}
function rpVal(key,row){return (rp.edits[key]!=null)?rp.edits[key]:((row&&row.body_text)||'');}
function rpCapture(){((rp.base&&rp.base.sections)||[]).forEach(sec=>{const el=document.getElementById('rp_'+sec.key);if(el)rp.edits[sec.key]=el.value;});}
function rpSave(key){rpCapture();const text=rp.edits[key]||'';rp.saving=key;renderReport(activeStep());fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'save_section',section_key:key,body_text:text})}).then(r=>r.json()).then(j=>{rp.saving='';if(j&&j.ok){toast('Section saved');}else{toast((j&&(j.message||j.error))||'Could not save.');}renderReport(activeStep());}).catch(()=>{rp.saving='';toast('Save failed.');renderReport(activeStep());});}
function rpGenerate(key,isAi){if(rp.gen)return;rpCapture();rp.gen=key;if(isAi)toast('Working with ReliCheck Intelligence…');renderReport(activeStep());fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'generate_section',section_key:key})}).then(r=>r.json()).then(j=>{rp.gen='';if(j&&j.ok){rp.base=null;delete rp.edits[key];toast('Section generated');}else{toast((j&&(j.message||j.error))||'Could not generate.');}renderReport(activeStep());}).catch(()=>{rp.gen='';toast('Generate failed.');renderReport(activeStep());});}
function rpGenerateAll(){if(rp.genAll)return;rpCapture();rp.genAll=true;toast('Building the report…');renderReport(activeStep());fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'generate_all'})}).then(r=>r.json()).then(j=>{rp.genAll=false;if(j&&j.ok){rp.base=null;rp.edits={};toast('Report assembled');}else{toast((j&&(j.message||j.error))||'Could not build the report.');}renderReport(activeStep());}).catch(()=>{rp.genAll=false;toast('Build failed or timed out.');renderReport(activeStep());});}
function rpHead(s){return `<div class="ws-header"><div class="eyebrow">Report builder · assemble the write-up <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function rpNav(){return `${navFooter()}`;}
function rpMsg(s,msg){$("#centerInner").innerHTML=rpHead(s)+helpBar('report')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+rpNav();}
function renderReport(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=rpHead(s)+helpBar('report')+`<p class="lede">Connect a project to assemble its report.</p>`+rpNav();return;}
  if(rp.err){rpMsg(s,rp.err);return;}
  if(!rp.base){
    if(!rp.busy){rp.busy=true;rpFetch().then(j=>{rp.busy=false;if(j&&j.ok){rp.base=j;}else{rp.err=(j&&(j.message||j.error))||'Could not load the report.';}renderReport(activeStep());}).catch(()=>{rp.busy=false;rp.err='Could not load your data.';renderReport(activeStep());});}
    rpMsg(s,'Loading the report…');return;
  }
  const d=rp.base,noTable=(d.has_table===false),byKey=rpRowByKey(),secs=d.sections||[];
  const note=noTable?`<div class="dm-note" style="margin-bottom:12px">Report storage needs a one-time database migration before sections can be saved.</div>`:'';
  const bulkBar=`<div class="dm-save"><button class="btn primary" ${rp.genAll||noTable?'disabled':''} onclick="rpGenerateAll()">${rp.genAll?'Building…':'✦ Build the full report'}</button><span class="dm-note">Drafts every section from your analysis with ReliCheck Intelligence; edit any of them below, or write each yourself.</span></div>`;
  const cards=secs.map(sec=>{const row=byKey[sec.key];const isAi=sec.source==='ai';const sv=rp.saving===sec.key;const gn=rp.gen===sec.key;const srcBadge=isAi?'<span class="tt-status rev">ReliCheck Intelligence</span>':'<span class="tt-status ok">Template</span>';const userBadge=(row&&row.source==='user')?' <span class="tt-status ok">Edited</span>':'';const genBtn=(sec.key==='findings')?'':`<button class="btn" ${gn?'disabled':''} onclick="rpGenerate('${sec.key}',${isAi})">${gn?(isAi?'Generating…':'Refreshing…'):(isAi?'✦ Generate with ReliCheck Intelligence':'Refresh from data')}</button>`;
    return `<div class="panel"><div class="panel-b">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><div style="font-size:15px;font-weight:700">${esc(sec.title)}</div><div>${srcBadge}${userBadge}</div></div>
      <textarea id="rp_${sec.key}" class="ed-in" rows="5" style="margin-top:8px" placeholder="${esc(sec.title)} — build it from your analysis, or write it yourself.">${esc(rpVal(sec.key,row))}</textarea>
      <div class="dm-save"><button class="btn primary" ${sv?'disabled':''} onclick="rpSave('${sec.key}')">${sv?'Saving…':'Save section'}</button>${genBtn}</div>
    </div></div>`;}).join('');
  const exportBar=`<div class="panel"><div class="panel-h"><div><h3>Download your report</h3><div class="ph-sub">Includes your edits; build or save the sections first</div></div></div><div class="panel-b"><div class="dm-save" style="margin:0"><a class="btn primary" href="/api/mm/report-docx.php?project_id=${BOOT.projectId}">⬇ Download Word (.docx)</a><a class="btn" href="/api/mm/report-export.php?project_id=${BOOT.projectId}&format=md">⬇ Download Markdown</a></div></div></div>`;
  $("#centerInner").innerHTML=rpHead(s)+helpBar('report')+note+bulkBar+cards+exportBar+rpNav();
}
/* ===== Identify Results to Explain — Step 7 pivot engine ===== */
const expl={items:[],loaded:false,busy:false,sel:{}};
function explSrcLabel(src){return {t_test:'t-test',anova:'ANOVA',chi_square:'Chi-square',correlation:'Correlation',regression:'Regression'}[src]||src||'Analysis';}
function explLoad(){
  if(!BOOT.projectId||expl.busy)return;
  expl.busy=true;
  fetch('/api/mm/results-to-explain.php?project_id='+BOOT.projectId,{credentials:'same-origin'})
    .then(r=>r.json()).then(j=>{
      expl.items=j.ok?(j.items||[]):[];
      // Default: carry all staged findings forward
      expl.items.forEach(it=>{ if(!(it.id in expl.sel)) expl.sel[it.id]=true; });
      expl.loaded=true; expl.busy=false; renderCenter();
    }).catch(()=>{ expl.loaded=true; expl.busy=false; renderCenter(); });
}
function explToggle(id){ expl.sel[id]=!expl.sel[id]; renderCenter(); }
function explNav(){return `${navFooter()}`;}
function renderExplain(s){
  const modeChip=`<span class="mode-chip work">Workstation</span>`;
  const chip=`<span class="strand-chip both">MIXED</span>`;
  const hd=`<div class="ws-header"><div class="eyebrow">Step ${s.n} · ${esc(s.title)} ${modeChip} ${chip}</div>
    <h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;
  if(!BOOT.projectId){
    $("#centerInner").innerHTML=hd+helpBar('explain')+`<div class="work-surface" style="border-radius:16px">Connect a project to use this step.</div>`+explNav();
    return;
  }
  if(!expl.loaded){
    explLoad();
    $("#centerInner").innerHTML=hd+helpBar('explain')+`<div class="context-strip"><span class="dot"></span>${esc(BOOT.projectLabel)}</div>
      <div class="panel"><div class="panel-h"><div><h3>${esc(s.title)}</h3><div class="ph-sub">Workstation · Pivot</div></div></div>
        <div class="panel-b"><div class="work-surface" style="color:var(--ink-3)">Loading staged findings…</div></div></div>`+explNav();
    return;
  }
  const items=expl.items;
  const nSel=items.filter(it=>expl.sel[it.id]).length;
  let panelBody;
  if(!items.length){
    panelBody=`<div class="panel-b"><div class="work-surface">
      <p style="margin:0 0 8px;font-weight:650">No findings staged yet.</p>
      <p style="margin:0;color:var(--ink-2)">After running a significant result in any analysis step — t-test, ANOVA, Chi-square, or Correlation — click <b>Add to Results to Explain</b>. Those findings will appear here for you to prioritize.</p>
    </div></div>`;
  } else {
    const cards=items.map(it=>{
      const on=expl.sel[it.id];
      const d=it.data||it; // payload is nested under .data by the GET endpoint
      const src_raw=it.source||it.src||'';
      const src=esc(explSrcLabel(src_raw));
      let plainText=d.plain||'', fqText=d.follow_up_question||'';
      if(src_raw==='t_test'&&d.grouping&&/^\d+$/.test(String(d.group1||''))&&/^\d+$/.test(String(d.group2||''))){
        const g1l=vlabFmt(d.grouping,d.group1), g2l=vlabFmt(d.grouping,d.group2);
        const dir=d.diff!=null&&d.diff<0?'lower':'higher';
        const sig=d.p!=null&&d.p<0.05;
        plainText=`${g1l} reported ${dir} ${d.outcome||''} than ${g2l}. The difference was ${sig?'statistically reliable':'not statistically reliable'}.`;
        fqText=`What experiences help explain why ${g1l} reported ${dir} ${d.outcome||''} than ${g2l}?`;
      }
      const plain=esc(plainText);
      const fq=fqText?`<div style="margin-top:8px;font-size:12.5px;color:var(--ink-3)">↳ ${esc(fqText)}</div>`:'';
      const pVal=d.p!=null?d.p:null;
      const pStr=pVal!=null?` <span style="font-size:12px;color:var(--ink-3)">p ${pVal<.001?'< .001':'= '+Number(pVal).toFixed(3)}</span>`:'';
      return `<div class="dx-layers" style="border:2px solid ${on?'var(--btn)':'var(--line)'};margin-bottom:12px;transition:border-color .15s">
        <div style="display:flex;align-items:flex-start;gap:12px">
          <div style="flex:1;min-width:0">
            <div style="margin-bottom:7px"><span class="strand-chip quan" style="font-size:11px">${src}</span>${pStr}</div>
            <div style="font-size:14px;line-height:1.55">${plain}</div>
            ${fq}
          </div>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;white-space:nowrap;padding-top:2px;flex-shrink:0">
            <input type="checkbox" ${on?'checked':''} onchange="explToggle(${it.id})"> Carry forward
          </label>
        </div>
      </div>`;
    }).join('');
    const summary=`<div style="margin-bottom:14px;font-size:13.5px;color:var(--ink-2)">${nSel} of ${items.length} finding${items.length===1?'':'s'} selected to carry into the qualitative phase.</div>`;
    panelBody=`<div class="panel-b">${summary}${cards}
      <div style="margin-top:4px"><button class="btn" onclick="expl.loaded=false;renderCenter()">↺ Refresh findings</button></div></div>`;
  }
  $("#centerInner").innerHTML=hd+helpBar('explain')+`<div class="context-strip"><span class="dot"></span>${esc(BOOT.projectLabel)}</div>
    <div class="panel"><div class="panel-h"><div><h3>${esc(s.title)}</h3><div class="ph-sub">Workstation · Pivot</div></div></div>${panelBody}</div>`+explNav();
}
/* ===== Qualitative Sampling Plan (qual_sampling) — Step 8 ============
   Decide who to follow up with qualitatively and what to ask, driven by the
   findings staged in Step 7 (results-to-explain GET) and the project's grouping
   variables (BOOT.ttvars). The plan is a living document persisted as a note on
   the report's Methods section (report.php add_note/update_note), so it carries
   straight into the Report Builder. Manual-first; a scaffold button drafts it
   from the staged findings. No new server endpoints. */
const qs={loaded:false,busy:false,err:'',saving:false,findings:[],groupings:[],noteId:null,plan:'',hasTable:true};
const QS_MARK='Qualitative sampling plan';
// A grouping variable that is actually a qualitative theme-code column (e.g.
// Coder1_Theme_Q1) must NOT appear as a demographic to sample from: selecting
// participants by their own theme code is circular in a study where themes are
// derived from the same responses. Excluded by the coder/theme naming convention.
function qsIsCodingVar(name){return /coder|theme|_code\b|coding/i.test(String(name||''));}
// Value labels: BOOT.valueLabels = { varName: { rawValue: humanLabel } }.
// vlabel returns the human label if one was defined, else null.
function vlabel(varName,raw){const m=(BOOT.valueLabels||{})[varName];if(m&&Object.prototype.hasOwnProperty.call(m,String(raw)))return m[String(raw)];return null;}
// vlabFmt formats a value for display: the human label if set, else "Var=value".
function vlabFmt(varName,raw){return vlabel(varName,raw)||(varName+'='+raw);}
// Mirror Step 7's label fix: integer-coded groups get a human label or var-name prefix.
function qsReadFinding(it){
  const d=it.data||it; const src=it.source||it.src||'';
  let plain=d.plain||'', fq=d.follow_up_question||'';
  if(src==='t_test'&&d.grouping&&/^\d+$/.test(String(d.group1||''))&&/^\d+$/.test(String(d.group2||''))){
    const g1l=vlabFmt(d.grouping,d.group1),g2l=vlabFmt(d.grouping,d.group2);
    const dir=d.diff!=null&&d.diff<0?'lower':'higher';
    plain=`${g1l} reported ${dir} ${d.outcome||''} than ${g2l}.`;
    fq=`What experiences help explain why ${g1l} reported ${dir} ${d.outcome||''} than ${g2l}?`;
  }
  return {src:explSrcLabel(src),plain,fq,grouping:d.grouping||d.row||'',outcome:d.outcome||''};
}
function qsFetch(){return Promise.all([
  fetch('/api/mm/results-to-explain.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null),
  fetch('/api/mm/report.php?project_id='+BOOT.projectId+'&action=list_notes&section_key=methods',{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null)
]);}
function qsHead(s){return `<div class="ws-header"><div class="eyebrow">Qualitative sampling plan · who to follow up with <span class="strand-chip qual">QUAL</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function qsNav(){return `${navFooter()}`;}
function qsMsg(s,msg){$("#centerInner").innerHTML=qsHead(s)+helpBar('qual_sampling')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+qsNav();}
function qsCapture(){const el=document.getElementById('qsText');if(el)qs.plan=el.value;}
function qsScaffold(){
  const el=document.getElementById('qsText');if(!el)return;
  const reads=qs.findings.map(qsReadFinding);
  // Kept compact: the Methods-note store caps the saved plan at 800 chars.
  const lines=[QS_MARK,'',
    'Purposive, maximum-variation sampling; ~3-5 per contrasting group (≈12-15 total), until themes saturate.'];
  if(reads.length){
    lines.push('');
    reads.forEach((r,i)=>{
      lines.push(`${i+1}. ${r.plain}`);
      if(r.grouping)lines.push(`   Who: contrasting groups of ${r.grouping} (include the highest- and lowest-scoring).`);
      if(r.fq)lines.push(`   Ask: ${r.fq}`);
    });
    lines.push('','Specify exact counts per group above and note any group to oversample for breadth.');
  } else {
    lines.push('','(Stage results in "Identify Results to Explain" first, then scaffold.)');
  }
  let txt=lines.join('\n');
  if(txt.length>800)txt=txt.slice(0,797)+'...';
  el.value=txt;qs.plan=txt;el.focus();
}
function qsSave(){
  qsCapture();const text=qs.plan||'';
  if(!text.trim()){toast('Write or scaffold a plan first');return;}
  // Keep the marker heading so the note can be re-found on reload.
  const body=text.trim().indexOf(QS_MARK)===0?text:(QS_MARK+'\n\n'+text);
  // The Methods-note store caps body_text at 800 chars; block rather than let
  // the server silently truncate the plan.
  if(body.length>800){toast(`Plan is ${body.length} characters; the limit is 800. Please shorten it before saving.`);return;}
  qs.saving=true;renderQualSampling(activeStep());
  const payload=qs.noteId
    ?{project_id:BOOT.projectId,action:'update_note',note_id:qs.noteId,body_text:body}
    :{project_id:BOOT.projectId,action:'add_note',section_key:'methods',body_text:body};
  fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(j=>{qs.saving=false;if(j&&j.ok){if(j.id)qs.noteId=j.id;qs.plan=body;toast('Sampling plan saved to Methods');}else{toast((j&&(j.message||j.error))||'Could not save.');}renderQualSampling(activeStep());})
    .catch(()=>{qs.saving=false;toast('Save failed.');renderQualSampling(activeStep());});
}
function renderQualSampling(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=qsHead(s)+helpBar('qual_sampling')+`<p class="lede">Connect a project to plan the qualitative follow-up.</p>`+qsNav();return;}
  if(qs.err){qsMsg(s,qs.err);return;}
  if(!qs.loaded){
    if(!qs.busy){qs.busy=true;qsFetch().then(a=>{
      qs.busy=false;qs.loaded=true;
      const ex=a[0],rp=a[1];
      qs.findings=(ex&&ex.ok)?(ex.items||[]):[];
      qs.groupings=((BOOT.ttvars&&BOOT.ttvars.groupings)||[]).filter(g=>!qsIsCodingVar(g.name));
      if(rp&&rp.ok){
        qs.hasTable=rp.has_table!==false;
        const note=(rp.notes||[]).find(n=>String(n.body_text||'').trim().indexOf(QS_MARK)===0);
        if(note){qs.noteId=note.id;qs.plan=note.body_text||'';}
      }
      renderQualSampling(activeStep());
    }).catch(()=>{qs.busy=false;qs.err='Could not load your data.';renderQualSampling(activeStep());});}
    qsMsg(s,'Gathering your staged findings…');return;
  }
  const reads=qs.findings.map(qsReadFinding);
  const findPanel=reads.length
    ?`<div class="panel"><div class="panel-h"><div><h3>Results you chose to explain</h3><div class="ph-sub">Staged in the previous step — these shape who you follow up with</div></div></div><div class="panel-b">${reads.map(r=>`<div class="ov-row" style="padding:8px 0"><div class="ov-k" style="width:120px">${esc(r.src)}</div><div class="ov-v">${esc(r.plain)}${r.fq?`<div style="margin-top:4px;font-size:12.5px;color:var(--ink-3)">↳ ${esc(r.fq)}</div>`:''}</div></div>`).join('')}</div></div>`
    :`<div class="dm-note" style="margin-bottom:12px">No findings staged yet. Run an analysis (t-test, ANOVA, Chi-square, Correlation, or Regression), then click <b>Add to Results to Explain</b> at the bottom of the result. Those findings will appear here.</div>`;
  const grpRef=qs.groupings.length
    ?`<div class="panel"><div class="panel-h"><div><h3>Groups you can sample from</h3><div class="ph-sub">From your data — purposively sample across the contrasting groups</div></div></div><div class="panel-b">${qs.groupings.map(g=>`<div class="ov-row" style="padding:6px 0"><div class="ov-k" style="width:160px">${esc(g.name)}</div><div class="ov-v">${(g.groups||[]).map(o=>`${esc(o.value)} (n=${o.n})`).join(' · ')}</div></div>`).join('')}</div></div>`
    :'';
  const tableNote=qs.hasTable?'':`<div class="dm-note" style="margin-bottom:12px">Saving needs a one-time database migration before it can persist; you can still draft the plan here.</div>`;
  const composer=`<div class="panel"><div class="panel-h"><div><h3>Your sampling plan</h3><div class="ph-sub">Who to interview, how many, and what to ask</div></div></div><div class="panel-b"><textarea id="qsText" class="ed-in" rows="10" placeholder="Who will you follow up with, how many, and what will you ask them to explain the results above?">${esc(qs.plan||'')}</textarea><div class="dm-save"><button class="btn primary" ${qs.saving||!qs.hasTable?'disabled':''} onclick="qsSave()">${qs.saving?'Saving…':'Save sampling plan'}</button><button class="btn" onclick="qsScaffold()">Scaffold from my findings</button><span class="dm-note">Saved to the report's Methods section and carried into the Report Builder. Keep it under 800 characters.</span></div></div></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this is</div><div class="dx-l-t">In an explanatory sequential design the qualitative phase exists to explain the quantitative results. A good sampling plan deliberately includes the groups whose differences you are trying to understand, and asks questions aimed squarely at those differences.</div></div></div>`;
  $("#centerInner").innerHTML=qsHead(s)+helpBar('qual_sampling')+`<div class="context-strip"><span class="dot"></span>${esc(BOOT.projectLabel)}</div>`+findPanel+grpRef+tableNote+composer+layers+qsNav();
}
/* ===== Quant → Qual Explanation Map (explain_map) — Step 11 ============
   For each quantitative result staged in Step 7, the researcher names the
   qualitative theme that explains it and writes why. Findings come from
   results-to-explain.php GET (reusing qsReadFinding's label fix); themes from
   categories.php GET. The composed map persists as a note on the report's
   Integration section (report.php add_note/update_note), so it carries into the
   Report Builder. Manual mapping; no new server endpoint. */
const em={loaded:false,busy:false,err:'',findings:[],themes:[],noteId:null,savedText:'',hasTable:true,saving:false};
const EM_MARK='Quant → Qual explanation map';
function emFetch(){return Promise.all([
  fetch('/api/mm/results-to-explain.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null),
  fetch('/api/mm/categories.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null),
  fetch('/api/mm/report.php?project_id='+BOOT.projectId+'&action=list_notes&section_key=integration',{credentials:'same-origin'}).then(r=>r.json()).catch(()=>null)
]);}
function emHead(s){return `<div class="ws-header"><div class="eyebrow">Quant → Qual explanation map · link each result to the theme that explains it <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function emNav(){return `${navFooter()}`;}
function emMsg(s,msg){$("#centerInner").innerHTML=emHead(s)+helpBar('explain_map')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+emNav();}
function emCompose(){
  const lines=[EM_MARK,''];
  em.findings.forEach((it,i)=>{
    const r=qsReadFinding(it);
    const tsel=document.getElementById('em_t_'+it.id);
    const nin=document.getElementById('em_n_'+it.id);
    const tid=tsel?+tsel.value:0;
    const theme=em.themes.find(t=>t.id===tid);
    const note=nin?nin.value.trim():'';
    const divChk=document.getElementById('em_div_'+it.id);
    const ctx=((document.getElementById('em_ctx_'+it.id)||{}).value||'').trim();
    const grp=((document.getElementById('em_grp_'+it.id)||{}).value||'').trim();
    const ctr=((document.getElementById('em_ctr_'+it.id)||{}).value||'').trim();
    const act=((document.getElementById('em_act_'+it.id)||{}).value||'').trim();
    lines.push(`${i+1}. ${r.plain}`);
    lines.push(theme?`   Explained by "${theme.name}"${note?': '+note:''}`:'   (not yet mapped to a theme)');
    if(divChk&&divChk.checked) lines.push('   [!] Aggregate and experience diverge for this finding');
    if(ctx) lines.push('   Context: '+ctx);
    if(grp) lines.push('   Voice: '+grp);
    if(ctr) lines.push('   Counter-Pattern: '+ctr);
    if(act) lines.push('   Consequence: '+act);
  });
  return lines.join('\n');
}
function emSave(){
  if(!em.findings.length){toast('No staged findings to map');return;}
  const text=emCompose();
  if(text.length>2500){toast(`Map is ${text.length} characters; the limit is 2500. Shorten your notes.`);return;}
  em.saving=true;renderExplainMap(activeStep());
  const payload=em.noteId
    ?{project_id:BOOT.projectId,action:'update_note',note_id:em.noteId,body_text:text}
    :{project_id:BOOT.projectId,action:'add_note',section_key:'integration',body_text:text};
  fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(j=>{em.saving=false;if(j&&j.ok){if(j.id)em.noteId=j.id;em.savedText=text;toast('Explanation map saved to Integration');renderExplainMap(activeStep());}else{toast((j&&(j.message||j.error))||'Could not save.');renderExplainMap(activeStep());}})
    .catch(()=>{em.saving=false;toast('Save failed.');renderExplainMap(activeStep());});
}
function renderExplainMap(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=emHead(s)+helpBar('explain_map')+`<p class="lede">Connect a project to map results to themes.</p>`+emNav();return;}
  if(em.err){emMsg(s,em.err);return;}
  if(!em.loaded){
    if(!em.busy){em.busy=true;emFetch().then(a=>{
      em.busy=false;em.loaded=true;
      const ex=a[0],cat=a[1],rp=a[2];
      em.findings=(ex&&ex.ok)?(ex.items||[]):[];
      em.themes=(cat&&cat.ok)?(cat.categories||[]):[];
      if(rp&&rp.ok){em.hasTable=rp.has_table!==false;const note=(rp.notes||[]).find(n=>String(n.body_text||'').trim().indexOf(EM_MARK)===0);if(note){em.noteId=note.id;em.savedText=note.body_text||'';}}
      renderExplainMap(activeStep());
    }).catch(()=>{em.busy=false;em.err='Could not load your data.';renderExplainMap(activeStep());});}
    emMsg(s,'Gathering your staged results and themes…');return;
  }
  if(!em.findings.length){$("#centerInner").innerHTML=emHead(s)+helpBar('explain_map')+`<div class="th-empty"><h3>No results staged yet</h3><p>Run an analysis and click <b>Add to Results to Explain</b> at the bottom of the result. Once you have staged findings, return here to map each to the qualitative theme that explains it.</p><button class="btn primary" style="margin-top:14px" onclick="goStep('q_inf')">Go to Inferential Analysis →</button></div>`+emNav();return;}
  if(!em.themes.length){$("#centerInner").innerHTML=emHead(s)+helpBar('explain_map')+`<div class="th-empty"><h3>No themes yet</h3><p>Build or discover your qualitative themes first, then return here to link each quantitative result to the theme that explains it.</p></div>`+emNav();return;}
  const opts=em.themes.map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('');
  const rows=em.findings.map((it,i)=>{const r=qsReadFinding(it);
    const clPanel=`<details style="border:1px solid #e0d4f5;border-radius:8px;margin-top:12px">
      <summary style="padding:9px 13px;cursor:pointer;font-size:12.5px;font-weight:700;color:var(--ink-2);list-style:none;display:flex;align-items:center;gap:8px;user-select:none">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#6B3FA0;color:#fff;font-size:9px;font-weight:800;flex-shrink:0">CL</span>
        Contextual Lens <span style="font-weight:400;color:var(--ink-3);font-size:11.5px">optional</span>
      </summary>
      <div style="padding:12px 14px 14px;border-top:1px solid #e0d4f5">
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;margin-bottom:12px;cursor:pointer;color:var(--ink-2)">
          <input type="checkbox" id="em_div_${it.id}"> Aggregate and experience diverge for this finding
        </label>
        <div style="margin-bottom:10px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:3px;color:var(--ink-2)">Context<span style="display:block;font-weight:400;color:var(--ink-3)">What setting, history, role, condition, or environment shapes this finding?</span></label><textarea id="em_ctx_${it.id}" style="width:100%;min-height:52px;padding:7px 10px;border:1px solid var(--line);border-radius:7px;font:inherit;font-size:12.5px;resize:vertical;box-sizing:border-box" placeholder="Optional"></textarea></div>
        <div style="margin-bottom:10px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:3px;color:var(--ink-2)">Voice<span style="display:block;font-weight:400;color:var(--ink-3)">Whose experience is visible in this finding, and whose might be missing or underrepresented?</span></label><textarea id="em_grp_${it.id}" style="width:100%;min-height:52px;padding:7px 10px;border:1px solid var(--line);border-radius:7px;font:inherit;font-size:12.5px;resize:vertical;box-sizing:border-box" placeholder="Optional"></textarea></div>
        <div style="margin-bottom:10px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:3px;color:var(--ink-2)">Counter-Pattern<span style="display:block;font-weight:400;color:var(--ink-3)">What responses challenge, complicate, or contradict this joint finding?</span></label><textarea id="em_ctr_${it.id}" style="width:100%;min-height:52px;padding:7px 10px;border:1px solid var(--line);border-radius:7px;font:inherit;font-size:12.5px;resize:vertical;box-sizing:border-box" placeholder="Optional"></textarea></div>
        <div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:3px;color:var(--ink-2)">Consequence<span style="display:block;font-weight:400;color:var(--ink-3)">What could happen if this finding is used shallowly, incorrectly, or without context?</span></label><textarea id="em_act_${it.id}" style="width:100%;min-height:52px;padding:7px 10px;border:1px solid var(--line);border-radius:7px;font:inherit;font-size:12.5px;resize:vertical;box-sizing:border-box" placeholder="Optional"></textarea></div>
      </div>
    </details>`;
    return `<div class="panel"><div class="panel-b">
      <div style="font-size:14px;line-height:1.5"><span class="strand-chip quan" style="font-size:11px">${esc(r.src)}</span> ${esc(r.plain)}</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px">
        <label style="font-size:12.5px;color:var(--ink-2);font-weight:600">Explained by</label>
        <select id="em_t_${it.id}" class="ed-in" style="max-width:280px"><option value="">— choose a theme —</option>${opts}</select>
        <input id="em_n_${it.id}" class="ed-in" style="flex:1;min-width:200px" placeholder="How does this theme explain this result?">
      </div>
      ${clPanel}
    </div></div>`;}).join('');
  const tableNote=em.hasTable?'':`<div class="dm-note" style="margin-bottom:12px">Saving needs a one-time database migration before it can persist; you can still draft the map here.</div>`;
  const saved=em.savedText?`<div class="panel"><div class="panel-h"><div><h3>Saved map</h3><div class="ph-sub">Carried into the report's Integration section</div></div></div><div class="panel-b"><pre style="white-space:pre-wrap;font:inherit;margin:0;color:var(--ink-2)">${esc(em.savedText)}</pre></div></div>`:'';
  const saveBar=`<div class="dm-save"><button class="btn primary" ${em.saving||!em.hasTable?'disabled':''} onclick="emSave()">${em.saving?'Saving…':'Save explanation map'}</button><span class="dm-note">Saved to the report's Integration section and carried into the Report Builder. Keep it under 800 characters.</span></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this is</div><div class="dx-l-t">The explanation map is the heart of an explanatory sequential design: each quantitative result you chose to explain is linked to the qualitative theme that accounts for it, with a sentence on how. These links become your Integration narrative and feed the joint display.</div></div></div>`;
  $("#centerInner").innerHTML=emHead(s)+helpBar('explain_map')+`<div class="context-strip"><span class="dot"></span>${esc(BOOT.projectLabel)}</div>`+rows+tableNote+saveBar+saved+layers+emNav();
}
// ── Study Setup step (3-tab panel) ───────────────────────────────────────────
const sd={
  tab:'data_kind',
  dataKinds:[...(BOOT.framing.data_kinds||[])],
  purposes:[...(BOOT.framing.intent_purposes||[])],
  design:(MM.ae_to_core||{})[BOOT.framing.chosen_design||'']||BOOT.framing.chosen_design||'',
  saving:false,
};
const DK_OPTS=[
  ['open_ended_only',           'Open-ended responses only',                               'Comments, interview text, focus group notes, or any qualitative responses with no numeric scores attached.'],
  ['survey_plus_open',          'Survey data with open-ended responses',                   'Closed-ended survey items plus one or more comment columns.'],
  ['survey_plus_separate_qual', 'Survey data and separate interview / focus group data',   'Quantitative survey results AND qualitative data collected separately.'],
  ['quant_only_with_qual',      'Quantitative data only, but I want to add qualitative interpretation','Scores or metrics in hand; you plan to add qualitative interpretation now.'],
  ['from_scratch',              'Building a mixed-methods study from scratch',              'No data yet — start the project structure and add data later.'],
];
const INTENT_OPTS=[
  ['explain_survey_results',   'Explain survey results',                 'Use comments to explain why the numbers came out the way they did.'],
  ['find_themes',              'Find themes in open-ended responses',    'Group qualitative responses into the patterns that recur most.'],
  ['compare_groups',           'Compare groups',                         'See how themes or scores differ across roles, departments, or other groups.'],
  ['build_variables_from_text','Build variables from text',              'Turn open-ended responses into quantitative variables for statistical analysis.'],
  ['strengthen_report',        'Strengthen a report with qualitative evidence','Bring quotes and themes into a report driven by quantitative findings.'],
  ['mixed_methods_section',    'Create a mixed-methods findings section','Produce a full integrated findings section with joint displays.'],
  ['evaluation_accreditation', 'Prepare evaluation or accreditation evidence','Generate a defensible evidence package for an evaluation or accreditation review.'],
  ['pre_survey_exploration',   'Explore patterns before building a survey','Use qualitative data to find the constructs worth measuring next.'],
];
const DESIGN_OPTS=[
  ['explanatory', 'Explanatory Sequential','Quant first, then qual. Run the numbers, then use comments to explain why they came out the way they did.'],
  ['exploratory', 'Exploratory Sequential','Qual first, then quant. Find themes in open-ended data, then turn them into variables or test them with numbers.'],
  ['convergent',  'Convergent Parallel',   'Both at once. Analyze quant and qual independently and compare them side by side in a joint display.'],
];
function sdDesignRec(){
  const dks=new Set(sd.dataKinds),ps=new Set(sd.purposes);
  return {
    explanatory: dks.has('survey_plus_open')||ps.has('explain_survey_results'),
    exploratory: dks.has('open_ended_only')||ps.has('find_themes')||ps.has('build_variables_from_text'),
    convergent:  ps.has('compare_groups')||ps.has('mixed_methods_section')||ps.has('evaluation_accreditation')||ps.has('strengthen_report'),
  };
}
function renderStudyDesign(s){
  const c=$('.center'); if(!c) return;
  const rec=sdDesignRec();
  const tabs=[
    {key:'data_kind',label:'Data Kind'},
    {key:'intent',   label:'Intent'},
    {key:'design',   label:'Design'},
  ];

  // Tab bar HTML
  const tabBar=tabs.map(t=>`<button class="sd-tab${sd.tab===t.key?' is-active':''}" data-sdtab="${t.key}">${esc(t.label)}</button>`).join('');

  // Tab body HTML
  let body='';
  if(sd.tab==='data_kind'){
    body=`<div class="sd-tab-intro">What kind of data do you have? Select all that apply.</div>
      <div class="sd-opts" id="sdDk">${DK_OPTS.map(([v,l,h])=>`
        <label class="sd-opt${sd.dataKinds.includes(v)?' is-on':''}" data-val="${esc(v)}">
          <input type="checkbox"${sd.dataKinds.includes(v)?' checked':''}><div><strong>${esc(l)}</strong><div class="sd-help">${esc(h)}</div></div>
        </label>`).join('')}</div>`;
  } else if(sd.tab==='intent'){
    body=`<div class="sd-tab-intro">What are you trying to understand? Select all that apply.</div>
      <div class="sd-opts" id="sdIntent">${INTENT_OPTS.map(([v,l,h])=>`
        <label class="sd-opt${sd.purposes.includes(v)?' is-on':''}" data-val="${esc(v)}">
          <input type="checkbox"${sd.purposes.includes(v)?' checked':''}><div><strong>${esc(l)}</strong><div class="sd-help">${esc(h)}</div></div>
        </label>`).join('')}</div>`;
  } else {
    body=`<div class="sd-tab-intro">Choose your mixed-methods design. We highlight a recommendation based on your Data Kind and Intent answers. The final choice is yours.</div>
      <div class="sd-opts" id="sdDesign">${DESIGN_OPTS.map(([v,l,h])=>{
        const isOn=sd.design===v;
        const pill=rec[v]?'<span class="sd-rec">Recommended</span>':'';
        return `<label class="sd-opt${isOn?' is-on':''}" data-val="${esc(v)}">
          <input type="radio" name="sdDesign"${isOn?' checked':''}><div><strong>${esc(l)}</strong>${pill}<div class="sd-help">${esc(h)}</div></div>
        </label>`;
      }).join('')}</div>`;
  }

  c.innerHTML=`<div class="sd-wrap">
    <h3 class="sd-h">Study Setup</h3>
    <div class="sd-tabs">${tabBar}</div>
    <div class="sd-tab-body">${body}</div>
    <div class="sd-actions">
      <button class="btn btn-primary" id="sdSaveBtn"${sd.saving?' disabled':''}>
        ${sd.saving?'Saving…':'Save'}
      </button>
      <span class="sd-saved" id="sdSavedMsg" hidden>Saved</span>
    </div>
  </div>`;

  // Tab switching
  c.querySelectorAll('[data-sdtab]').forEach(btn=>btn.addEventListener('click',function(){
    sd.tab=btn.getAttribute('data-sdtab'); renderStudyDesign(activeStep());
  }));

  // Data Kind checkboxes
  if(sd.tab==='data_kind'){
    c.querySelectorAll('#sdDk .sd-opt').forEach(o=>o.addEventListener('click',function(e){
      e.preventDefault(); const v=o.getAttribute('data-val');
      const cb=o.querySelector('input'); cb.checked=!cb.checked; o.classList.toggle('is-on',cb.checked);
      if(cb.checked){if(!sd.dataKinds.includes(v))sd.dataKinds.push(v);}
      else{sd.dataKinds=sd.dataKinds.filter(x=>x!==v);}
    }));
  }

  // Intent checkboxes
  if(sd.tab==='intent'){
    c.querySelectorAll('#sdIntent .sd-opt').forEach(o=>o.addEventListener('click',function(e){
      e.preventDefault(); const v=o.getAttribute('data-val');
      const cb=o.querySelector('input'); cb.checked=!cb.checked; o.classList.toggle('is-on',cb.checked);
      if(cb.checked){if(!sd.purposes.includes(v))sd.purposes.push(v);}
      else{sd.purposes=sd.purposes.filter(x=>x!==v);}
    }));
  }

  // Design radio
  if(sd.tab==='design'){
    c.querySelectorAll('#sdDesign .sd-opt').forEach(o=>o.addEventListener('click',function(e){
      e.preventDefault(); const v=o.getAttribute('data-val');
      sd.design=v;
      c.querySelectorAll('#sdDesign .sd-opt').forEach(x=>{
        const on=x.getAttribute('data-val')===v;
        x.classList.toggle('is-on',on);
        const r=x.querySelector('input'); if(r) r.checked=on;
      });
    }));
  }

  // Save
  c.querySelector('#sdSaveBtn').addEventListener('click',function(){
    sd.saving=true; renderStudyDesign(activeStep());
    const postWiz=body=>fetch('/api/mm/wizard.php',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(Object.assign({project_id:BOOT.projectId},body))}).then(r=>r.json());
    // Build only the posts that have data; wizard rejects empty arrays.
    const jobs=[];
    if(sd.dataKinds.length) jobs.push({step:'data_kind',values:sd.dataKinds});
    if(sd.purposes.length) jobs.push({step:'purpose',values:sd.purposes});
    if(sd.design) jobs.push({step:'design_choice',value:(MM.core_to_ae||{})[sd.design]||sd.design});
    jobs.reduce((p,b)=>p.then(()=>postWiz(b)),Promise.resolve({ok:true}))
      .then(()=>{
        sd.saving=false;
        if(sd.design){state.design=sd.design;persistDesign(sd.design);}
        renderStudyDesign(activeStep());
        const msg=document.getElementById('sdSavedMsg');
        if(msg){msg.hidden=false;setTimeout(()=>{msg.hidden=true;},2500);}
      })
      .catch(()=>{sd.saving=false;toast('Could not save study setup.');renderStudyDesign(activeStep());});
  });
}

function renderCenter(){
  const s=activeStep(); const tool=currentTool(s);
  if(s.mode==='start'){ return renderStart(s); }
  if(s.mode==='overview'){ return renderOverview(s); }
  if(s.mode==='datamap'){ return renderDataMap(s); }
  if(s.mode==='study_design'){ return renderStudyDesign(s); }
  if(s.mode==='quality'){ return renderQuality(s); }
  if(s.id==='l_trust'){ return renderTrust(s); }
  if(s.id==='l_themes'){ return renderThemes(s); }
  if(s.id==='l_book'){ return renderBook(s); }
  if(s.id==='q_build'){ const tb=(currentTool(s)||{}).name||''; return (tb==='T-Test'||tb==='Effect Sizes')?renderMeasureTest(s):renderReliability(s); }
  if(s.id==='joint'){ return renderJoint(s); }
  if(s.id==='explain'){ return renderExplain(s); }
  if(s.id==='qual_sampling'){ return renderQualSampling(s); }
  if(s.id==='explain_map'){ return renderExplainMap(s); }
  if(s.id==='q2q'){ return renderQ2Q(s); }
  if(s.id==='converge'){ return renderConverge(s); }
  if(s.id==='meta'){ return renderMeta(s); }
  if(s.id==='interp'){ return renderInterp(s); }
  if(s.id==='evidence_strength'){ return renderStrength(s); }
  if(s.id==='report'){ return renderReport(s); }
  if(s.id==='q_desc'){ return renderDescriptive(s); }
  if(s.id==='q_inf' && currentTool(s) && currentTool(s).name==='t-test'){ return renderTTest(s); }
  if(s.id==='q_inf' && currentTool(s) && currentTool(s).name==='ANOVA'){ return renderANOVA(s); }
  if(s.id==='q_inf' && currentTool(s) && currentTool(s).name==='Chi-square'){ return renderChiSquare(s); }
  if(s.id==='q_inf' && currentTool(s) && currentTool(s).name==='Correlation'){ return renderCorrelation(s); }
  if(s.id==='q_inf' && currentTool(s) && currentTool(s).name==='Regression'){ return renderRegression(s); }
  if(s.id==='q_inf' && currentTool(s) && currentTool(s).name==='Reliability'){ return renderReliability(s); }
  const modeLabel={setup:"Setup",work:"Workstation",output:"Output",overview:"Review",start:"Start"}[s.mode]||"";
  const modeChip=modeLabel?`<span class="mode-chip ${s.mode}">${modeLabel}</span>`:'';
  const chip=s.strand==='both'?'<span class="strand-chip both">MIXED</span>':s.strand==='quan'?'<span class="strand-chip quan">QUAN</span>':s.strand==='qual'?'<span class="strand-chip qual">QUAL</span>':'';
  const toolHead=tool?`<div class="ws-tool-head"><span class="ws-dot ${tool.strand}"></span><h4>${tool.name}</h4><span class="ws-tag">selected from palette →</span></div>`:'';
  // Phase 3: a step with a route mounts its existing MM engine page chromelessly.
  const hasEngine = s.route && BOOT.projectId>0;
  let body, sub;
  if(hasEngine){
    const src = s.route + '?studio=mm&project_id=' + BOOT.projectId + '&embed=1';
    sub = 'Live engine · ' + s.route;
    body = `<div class="panel-b engine"><iframe class="ws-frame" src="${src}" loading="eager" title="${esc(s.title)}"></iframe></div>`;
  } else if(s.route){
    sub = modeLabel + (tool?' · '+tool.name:'');
    body = `<div class="panel-b">${toolHead}<div class="work-surface">Connect a project to load the <b>${esc(s.title)}</b> engine. In demo mode (no project) the live engine is not mounted.</div></div>`;
  } else {
    sub = modeLabel + (tool?' · '+tool.name:'');
    body = `<div class="panel-b">${toolHead}<div class="work-surface">The <b>${esc(s.title)}</b> workstation is a new build (joint synthesis / pivot screens), not yet wired to an engine.</div>
        <div class="run-actions"><button class="btn primary">▷ Run</button><button class="btn">Configure</button><button class="btn">Save to report</button></div></div>`;
  }
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Step ${s.n} · ${s.title} ${modeChip} ${chip}</div>
      <h1 class="title">${s.title}</h1><p class="lede">${s.lede}</p></div>
    ${helpBar(helpKey(s))}
    <div class="context-strip"><span class="dot"></span>${esc(BOOT.projectLabel)}</div>
    <div class="panel"><div class="panel-h"><div><h3>${s.title}</h3><div class="ph-sub">${sub}</div></div></div>${body}</div>
    ${navFooter()}`;
}
function renderPalette(){
  const s=activeStep(); const p=s.palette||{intro:"",groups:[]};
  const head=s.mode==="work"?"Tools":"This step";
  let html=`<div class="palette-h">${head}</div><div class="palette-intro">${p.intro||""}</div>`;
  if(!p.groups||!p.groups.length){html+=`<div class="pal-empty">Nothing to select on this step. Work in the center panel.</div>`;}
  else{const cur=currentTool(s);p.groups.forEach(g=>{html+=`<div class="pal-group">${g.name}</div>`;
    html+=g.items.map(it=>`<div class="pal-item ${it.strand} ${cur&&it.name===cur.name?'active':''}" onclick="${it.action||("selPal('"+it.name.replace(/'/g,"\\'")+"')")}"><span class="pdot"></span>${it.name}</div>`).join("");});}
  $("#palette").innerHTML=html;
}
// Coach content (prototype style): context chip, Guidance tip, divider,
// Common questions with click-to-reveal answers. Content is drawn from the
// existing per-step AHELP so it works across all pipeline steps. The footer
// ask box is the labeled ReliCheck Intelligence secondary (manual-first).
function coachData(s){
  const d=DESIGNS[state.design];
  const h=AHELP[helpKey(s)];
  const strip=t=>String(t==null?'':t).replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim();
  // Guidance tip: the step's "what it is" paragraph, else the step lede.
  const tip = (h&&h.what) ? h.what : esc(s.lede||('This step is part of the '+(d?d.short:'')+' workflow.'));
  // Common questions mapped to the existing help fields (all plain-language).
  const qs=[], ans=[];
  if(h){
    if(h.measures){qs.push('What does this step give me?'); ans.push(strip(h.measures));}
    if(h.use){qs.push('When should I use it?'); ans.push(strip(h.use));}
    if(h.example){qs.push('How do I read the result?'); ans.push(strip(h.example));}
  }
  if(d&&d.why){qs.push('Why is this step in this order?'); ans.push(strip(d.why));}
  if(!qs.length){qs.push('What am I doing on this step?'); ans.push(strip(s.lede||'Work through this step, then continue.'));}
  return {tip:tip, qs:qs, ans:ans};
}
let coachAns=[];
function renderCompanion(){
  const s=activeStep(); const ss=steps();
  const sub=document.getElementById('coachStepLabel');
  if(sub) sub.textContent='Step '+s.n+' guidance';
  const cd=coachData(s); coachAns=cd.ans;
  const prompts=cd.qs.map((q,i)=>`<button class="coach-prompt" onclick="showCoachAnswer(${i})">${esc(q)}</button>`).join('');
  $("#compBody").innerHTML=`
    <div class="coach-context-chip">
      <svg viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.3"/><line x1="6" y1="5" x2="6" y2="8.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="6" cy="3.5" r=".6" fill="currentColor"/></svg>
      Step ${s.n} of ${ss.length}
    </div>
    <div class="coach-section">
      <div class="coach-section-label">Guidance</div>
      <div class="coach-tip">${cd.tip}</div>
    </div>
    <div class="coach-divider"></div>
    <div class="coach-section">
      <div class="coach-section-label">Common questions</div>
      <div class="coach-prompt-list">${prompts}</div>
      <div class="coach-answer" id="coachAnswer"></div>
    </div>`;
}
function coachType(el,text){
  el.textContent=''; el.classList.add('visible');
  let pos=0; clearInterval(window._coachTyper);
  window._coachTyper=setInterval(function(){ if(pos<text.length){el.textContent+=text[pos++];} else {clearInterval(window._coachTyper);} },10);
}
function showCoachAnswer(i){
  const ans=document.getElementById('coachAnswer');
  document.querySelectorAll('.coach-prompt').forEach((p,idx)=>p.classList.toggle('active',idx===i));
  if(ans) coachType(ans, coachAns[i]||'');
}
function handleCoachInput(e){ if(e.key==='Enter') handleCoachSend(); }
function handleCoachSend(){
  const input=document.getElementById('coachInput'); if(!input) return;
  const val=input.value.trim(); if(!val) return;
  if(typeof toast==='function') toast('Working with ReliCheck Intelligence…');
  const ans=document.getElementById('coachAnswer'); const s=activeStep();
  document.querySelectorAll('.coach-prompt').forEach(p=>p.classList.remove('active'));
  if(ans) coachType(ans, "In the full build, ReliCheck Intelligence answers from your own data and design. For now, the common questions above cover "+(s.title||'this step')+", and you can carry anything useful into your Researcher's Notes.");
  input.value='';
}
function esc(s){return (s==null?"":String(s)).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
function alignPalette(){}
function render(){renderSwitch();renderRail();renderCenter();appendReportSave();renderPalette();renderCompanion();alignPalette();mmRenderTopbarSteps();mmRenderSidebar();mmRenderToolBars();}
// In-content tool tabs (replaces the hidden palette) + a save/nav footer, for the
// analysis steps. Rendered into persistent bars OUTSIDE #centerInner so the
// per-analysis re-renders (runTTest, setFreq, etc.) never wipe them.
function mmRenderToolBars(){
  const tb=document.getElementById('qTabsBar'), fb=document.getElementById('qFootBar');
  if(!tb||!fb) return;
  const s=activeStep();
  const isAnalysis=(s.id==='q_desc'||s.id==='q_inf');
  if(!isAnalysis){ tb.innerHTML=''; fb.innerHTML=''; return; }
  const tools=toolsOf(s); const cur=currentTool(s);
  tb.innerHTML = (tools&&tools.length>1)
    ? '<div class="q-tooltabs">'+tools.map(function(t){
        return '<button class="q-tooltab '+(cur&&t.name===cur.name?'on':'')+'" onclick="selPal(\''+String(t.name).replace(/'/g,"\\'")+'\')">'+esc(t.name)+'</button>';
      }).join('')+'</div>'
    : '';
  // q_inf analyses have no footer of their own → provide save + nav here.
  // q_desc fns render their own inline footer-nav, so leave its footer bar empty.
  if(s.id==='q_inf'){
    const save=(BOOT.projectId>0)
      ? '<div class="dm-save" id="mmReportSave" style="margin:0 0 14px"><button class="btn primary" onclick="saveAreaToReport(activeStep())">＋ Save to report</button><span class="dm-note">Adds this result to the report’s Findings section.</span></div>'
      : '';
    fb.innerHTML=save+navFooter();
  } else {
    fb.innerHTML='';
  }
}
// Per-area "Save to report": on any analysis area (work/output step), append a
// "Save to report" button that adds this area's result to the report's Findings
// section. Reuses the existing report.php (save_section) + .dm-save styling.
function appendReportSave(){
  const s=activeStep();
  if((s.mode!=='work'&&s.mode!=='output')||s.id==='report') return;
  if(s.id==='q_inf') return; // q_inf save lives in the persistent #qFootBar
  if(!(BOOT.projectId&&BOOT.projectId>0)) return;
  const ci=document.getElementById('centerInner'); if(!ci) return;
  const panel=ci.querySelector('.panel'); if(!panel) return;
  if(document.getElementById('mmReportSave')) return;
  const bar=document.createElement('div'); bar.id='mmReportSave'; bar.className='dm-save'; bar.style.marginTop='14px';
  bar.innerHTML='<button class="btn primary" id="mmReportSaveBtn">＋ Save to report</button>'
    +'<span class="dm-note" id="mmReportSaveNote">Adds this area’s result to the report’s Findings section.</span>';
  const nav=ci.querySelector('.footer-nav');
  if(nav) ci.insertBefore(bar,nav); else ci.appendChild(bar);
  document.getElementById('mmReportSaveBtn').addEventListener('click',function(){ saveAreaToReport(s); });
}
function saveAreaToReport(s){
  const btn=document.getElementById('mmReportSaveBtn'), note=document.getElementById('mmReportSaveNote');
  if(btn){btn.disabled=true;btn.textContent='Saving…';}
  const ci=document.getElementById('centerInner'); const panel=ci?ci.querySelector('.panel'):null;
  let text='';
  if(panel){ if(panel.querySelector('iframe')){ text='(Engine output — see the "'+(s.title||'')+'" step in MM Studio.)'; }
    else { text=(panel.innerText||panel.textContent||'').trim().replace(/\n{3,}/g,'\n\n'); } }
  const entry='## '+(s.title||'Analysis')+'\n'+(text||'(no result captured)');
  fetch('/api/mm/report.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();}).then(function(j){
      let cur=''; if(j&&j.ok&&Array.isArray(j.rows)){ const row=j.rows.find(function(x){return x.section_key==='findings';}); if(row) cur=row.body_text||''; }
      const combined=(cur?cur.trim()+'\n\n':'')+entry;
      return fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'save_section',section_key:'findings',body_text:combined})});
    }).then(function(r){return r.json();}).then(function(j){
      if(j&&j.ok){ if(btn)btn.textContent='Saved to report ✓'; if(note)note.textContent='Added to the Findings section. Open the Report step to see it.'; if(typeof toast==='function')toast('Saved to report'); if(document.getElementById('rptDrawer')&&document.getElementById('rptDrawer').classList.contains('open')) mmLoadReport(); }
      else { throw 0; }
    }).catch(function(){ if(btn){btn.disabled=false;btn.textContent='＋ Save to report';} if(note)note.textContent='Could not save — please try again.'; });
}

/* persist the design choice on the project (Phase 2 wiring; no-op in demo) */
function persistDesign(coreSlug){
  if(!BOOT.canPersist)return;
  // wizard.php validates the A–E slugs, so persist the representative A–E for this core design.
  const aeSlug=(MM.core_to_ae||{})[coreSlug]||coreSlug;
  fetch('/api/mm/wizard.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({project_id:BOOT.projectId,step:'design_choice',value:aeSlug})})
   .catch(()=>{});
}
function setDesign(k){state.design=k;state.stepId=steps()[0].id;state.completedThrough=0;state.toolSel=null;render();$(".center").scrollTop=0;persistDesign(k);toast("Design → "+DESIGNS[k].short);mmRenderSidebar();}
function go(u){window.location.href=u;}
function dockIntake(kind){const m={siri:'Open from SIRI responses',saved:'Open saved project',open:'Open a project',upload:'Upload data',sample:'Try a sample'};toast((m[kind]||'Data intake')+' — opens the shared intake.');}
function toggleDesignMenu(e){if(e)e.stopPropagation();$("#designPick").classList.toggle('open');}
function pickDesign(k){$("#designPick").classList.remove('open');if(k!==state.design)setDesign(k);}
document.addEventListener('click',e=>{const p=$("#designPick");if(p&&!p.contains(e.target))p.classList.remove('open');});
function goStep(id){state.stepId=id;state.toolSel=null;render();$(".center").scrollTop=0;mmUpdateNotes();}
function stepBy(dir){const s=steps();const i=s.findIndex(x=>x.id===activeStep().id);const ni=Math.max(0,Math.min(s.length-1,i+dir));state.stepId=s[ni].id;state.toolSel=null;if(dir>0&&ni+1>state.completedThrough)state.completedThrough=ni;render();$(".center").scrollTop=0;}
function selPal(name){state.toolSel=name;render();const c=document.querySelector('.center');if(c)c.scrollTop=0;}
function setCompTab(t){state.compTab=t;renderCompanion();}
function toggleCompanion(){document.body.classList.toggle('companion-collapsed');}
function toggleCoach(){
  const opening=!document.body.classList.contains('coach-open');
  if(opening){ // close the report drawer first so the two never overlap
    document.getElementById('rptDrawer').classList.remove('open');
    document.getElementById('rptScrim').classList.remove('open');
    document.body.classList.remove('report-open');
  }
  document.body.classList.toggle('coach-open',opening);
  if(opening) renderCompanion();
}
window.addEventListener('resize',()=>{$(".center").scrollTop=0;alignPalette();});

function openEdit(){
  if(!BOOT.canPersist){ toast('Open a project to edit it.'); return; }
  const opts=DESIGN_ORDER.map(k=>`<option value="${k}" ${k===state.design?'selected':''}>${DESIGNS[k].short}</option>`).join('');
  $("#modal").innerHTML=`
    <div class="modal-h"><h3>Edit project</h3><button class="mx" onclick="closeModal()">✕</button></div>
    <div class="modal-b">
      <label class="ed-l">Title</label>
      <input class="ed-in" id="edTitle" value="${esc(BOOT.projectLabel)}">
      <label class="ed-l">Description</label>
      <textarea class="ed-in" id="edDesc" rows="4" placeholder="What is this study about?">${esc(BOOT.projDesc||'')}</textarea>
      <label class="ed-l">Design</label>
      <select class="ed-in" id="edDesign">${opts}</select>
      <div class="ed-foot"><button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="saveEdit()">Save</button></div>
    </div>`;
  $("#modalScrim").classList.add('open');
}
function saveEdit(){
  const title=$("#edTitle").value.trim(); const notes=$("#edDesc").value; const design=$("#edDesign").value;
  if(!title){ toast('Title cannot be empty.'); return; }
  const jobs=[fetch('/api/mm/project.php',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:BOOT.projectId,title:title,notes:notes})})];
  if(design!==state.design){ const ae=(MM.core_to_ae||{})[design]||design; jobs.push(fetch('/api/mm/wizard.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,step:'design_choice',value:ae})})); }
  Promise.all(jobs).then(()=>{ toast('Saved'); go('?project_id='+BOOT.projectId+'&design='+design); }).catch(()=>toast('Save failed'));
}
function openHelp(){
  $("#modal").innerHTML=`<div class="modal-h"><h3>Help me choose a design</h3><button class="mx" onclick="closeModal()">✕</button></div>
    <div class="modal-b"><p class="lede" style="font-size:14px;margin-bottom:16px">Pick the statement that best fits your study. You can change it any time from the buttons up top.</p>
    ${HELP.map(h=>`<div class="q-card" onclick="chooseHelp('${h[0]}')"><h4><span class="strand-chip ${h[1]}">${h[2]}</span> ${h[3]}</h4><p>${h[4]}</p></div>`).join("")}</div>`;
  $("#modalScrim").classList.add('open');
}
function chooseHelp(k){closeModal();setDesign(k);}
function closeModal(){$("#modalScrim").classList.remove('open');}
$("#modalScrim").addEventListener('click',e=>{if(e.target.id==='modalScrim')closeModal();});
let tT;function toast(m){const t=$("#toast");t.textContent=m;t.classList.add('show');clearTimeout(tT);tT=setTimeout(()=>t.classList.remove('show'),1800);}

// ─── NEW STUDIO LAYER ─────────────────────────────────────────────────────────

const MM_DESIGN_DESC = {
  convergent:  'Collect quantitative and qualitative data independently, analyze each strand, then merge to see where they converge or diverge.',
  explanatory: 'Run the quantitative phase first, then use qualitative follow-up to explain what drove the numbers.',
  exploratory: 'Start qualitatively to build understanding, then use findings to design or inform the quantitative phase.',
};

// Topbar step rail (prototype design: .done/.active, .tb-node-label, .tb-connector)
function mmRenderTopbarSteps(){
  const el=document.getElementById('topbarSteps'); if(!el) return;
  const ss=steps(); const act=activeStep();
  let html='';
  ss.forEach(function(s,i){
    const isDone=s.done, isAct=s.id===act.id;
    const cls=isDone?'done':isAct?'active':'';
    const inner=isDone?'&#x2713;':s.n;
    html+=`<div class="tb-step ${cls}" onclick="goStep('${s.id}')" title="${esc(s.label)}">
      <div class="tb-node">${inner}${isAct?`<span class="tb-node-label">${esc(s.label)}</span>`:''}</div>
    </div>`;
    if(i<ss.length-1) html+=`<div class="tb-connector ${isDone?'done':''}"></div>`;
  });
  el.innerHTML=html;
}

// Sidebar design switcher + description (prototype: .ds-btn inside .design-switcher)
function mmRenderSidebar(){
  const pill=document.getElementById('sbDesignPill');
  const desc=document.getElementById('sbDesignDesc');
  if(pill){
    pill.innerHTML=DESIGN_ORDER.map(k=>{
      const lbl=k.charAt(0).toUpperCase()+k.slice(1);
      return `<button class="ds-btn ${k===state.design?'active':''}" onclick="setDesign('${k}')">${lbl}</button>`;
    }).join('');
  }
  if(desc) desc.textContent = MM_DESIGN_DESC[state.design]||'';
}

// Notes per step
const mmNotesStore={};
let mmNoteTimer=null;
document.addEventListener('input',function(e){
  if(e.target.id!=='researcherNotes') return;
  const act=activeStep();
  mmNotesStore[act.id]=e.target.value;
  const saved=document.getElementById('nbSaved');
  const addBtn=document.getElementById('btnNoteRpt');
  if(saved) saved.classList.remove('vis');
  if(addBtn) addBtn.classList.toggle('vis', e.target.value.trim().length>0);
  clearTimeout(mmNoteTimer);
  mmNoteTimer=setTimeout(function(){
    if(saved){saved.classList.add('vis');setTimeout(function(){saved.classList.remove('vis');},2000);}
  },800);
});
function mmUpdateNotes(){
  const ta=document.getElementById('researcherNotes');
  const tag=document.getElementById('nbStepTag');
  const addBtn=document.getElementById('btnNoteRpt');
  const act=activeStep();
  if(ta){ ta.value=mmNotesStore[act.id]||''; ta.placeholder='Jot observations for '+esc(act.label)+'…'; }
  if(tag) tag.textContent='Step '+act.n;
  if(addBtn) addBtn.classList.toggle('vis', !!(ta&&ta.value.trim()));
}

// Save button
function mmSaveProject(){
  const btn=document.getElementById('saveProjectBtn'); if(!btn) return;
  btn.classList.add('saved');
  btn.innerHTML='<svg viewBox="0 0 16 16" fill="none"><polyline points="3,8 6.5,12 13,4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg> Saved';
  setTimeout(function(){
    btn.classList.remove('saved');
    btn.innerHTML='<svg viewBox="0 0 16 16" fill="none"><path d="M13 13H3a1 1 0 0 1-1-1V3l2-1h7l2 2v8a1 1 0 0 1-1 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><rect x="5" y="9" width="6" height="4" rx=".5" stroke="currentColor" stroke-width="1.4"/><rect x="5.5" y="2" width="4" height="3" rx=".5" stroke="currentColor" stroke-width="1.4"/></svg> Save';
  },2200);
}

// ── Report drawer — live view of the real saved report (api/mm/report.php) ──
// The drawer reads the project's stored report sections on open, so "what's
// inside" is the actual report, not an ephemeral list. Filled sections show as
// cards; clicking one (or Export) jumps to the Report Builder step.
const mmReport={loaded:false,sections:[],hasTable:true};
function mmUpdateReportCount(){
  const n=mmReport.sections.length;
  const b1=document.getElementById('rptCountBadge');
  const b2=document.getElementById('rptBadgeDrawer');
  if(b1){b1.style.display=n>0?'inline':'none';b1.textContent=n;}
  if(b2) b2.textContent=n+(n===1?' section':' sections');
}
function toggleRptDrawer(){
  const opening=!document.getElementById('rptDrawer').classList.contains('open');
  if(opening) document.body.classList.remove('coach-open'); // never both right-side drawers at once
  document.getElementById('rptDrawer').classList.toggle('open',opening);
  document.getElementById('rptScrim').classList.toggle('open',opening);
  document.body.classList.toggle('report-open',opening);
  if(opening) mmLoadReport();
}
function mmReportMsg(msg){
  const empty=document.getElementById('rptEmpty'), body=document.getElementById('rptBody');
  if(body) body.querySelectorAll('.rpt-finding').forEach(function(el){el.remove();});
  if(empty){ empty.style.display='flex'; const p=empty.querySelector('p'); if(p&&msg) p.textContent=msg; }
}
function mmLoadReport(){
  if(!(BOOT.projectId&&BOOT.projectId>0)){ mmReport.sections=[]; mmUpdateReportCount(); mmReportMsg('Open a project to build and view its report.'); return; }
  mmReportMsg('Loading your report…');
  fetch('/api/mm/report.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();}).then(function(j){
      if(!j||!j.ok){ mmReport.sections=[]; mmUpdateReportCount(); mmReportMsg('Could not load the report.'); return; }
      mmReport.hasTable=j.has_table!==false; mmReport.loaded=true;
      mmReport.sections=(j.rows||[]).filter(function(r){return String(r.body_text||'').trim()!=='';});
      mmUpdateReportCount(); mmRenderReport();
    }).catch(function(){ mmReport.sections=[]; mmUpdateReportCount(); mmReportMsg('Could not load the report.'); });
}
function mmRenderReport(){
  const body=document.getElementById('rptBody'), empty=document.getElementById('rptEmpty');
  if(!body) return;
  body.querySelectorAll('.rpt-finding').forEach(function(el){el.remove();});
  if(!mmReport.sections.length){ mmReportMsg('No report content yet. Use “Save to report” on an analysis, add a note, or build sections in the Report step.'); return; }
  if(empty) empty.style.display='none';
  mmReport.sections.forEach(function(sec){
    const raw=String(sec.body_text||'').trim();
    const preview=raw.length>260?raw.slice(0,257)+'…':raw;
    const el=document.createElement('div'); el.className='rpt-finding'; el.style.cursor='pointer';
    el.title='Open in the Report Builder';
    el.addEventListener('click',function(){ toggleRptDrawer(); goStep('report'); });
    el.innerHTML=`<div class="rpt-step-tag">${esc(sec.title||sec.section_key)}</div>
      <div class="rpt-finding-body">${esc(preview)}</div>`;
    body.appendChild(el);
  });
}
function mmExportReport(){
  if(!(BOOT.projectId&&BOOT.projectId>0)){ toast('Open a project to export the report.'); return; }
  toggleRptDrawer(); goStep('report');
}
// Researcher's Notes "Add to Report" now persists to the real report's Findings
// section (server), so the note is part of the actual report and survives reloads.
function mmSaveNoteToReport(){
  const ta=document.getElementById('researcherNotes'); if(!ta||!ta.value.trim()) return;
  const act=activeStep();
  if(!(BOOT.projectId&&BOOT.projectId>0)){ toast('Open a project to save notes to its report.'); return; }
  const entry='Note ('+act.label+'): '+ta.value.trim();
  fetch('/api/mm/report.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();}).then(function(j){
      let cur=''; if(j&&j.ok&&Array.isArray(j.rows)){ const row=j.rows.find(function(x){return x.section_key==='findings';}); if(row) cur=row.body_text||''; }
      const combined=(cur?cur.trim()+'\n\n':'')+entry;
      return fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'save_section',section_key:'findings',body_text:combined})});
    }).then(function(r){return r.json();}).then(function(j){
      if(j&&j.ok){ if(typeof toast==='function')toast('Note added to report'); if(document.getElementById('rptDrawer').classList.contains('open')) mmLoadReport(); }
      else { throw 0; }
    }).catch(function(){ if(typeof toast==='function')toast('Could not save the note to the report.'); });
  const btn=document.getElementById('btnNoteRpt'); if(!btn) return;
  btn.style.color='var(--mm)';
  btn.innerHTML='<svg viewBox="0 0 12 12" fill="none"><polyline points="1,6 4.5,10 11,2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Added';
  setTimeout(function(){btn.style.color='';btn.innerHTML='<svg viewBox="0 0 12 12" fill="none"><line x1="6" y1="1" x2="6" y2="11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="1" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> Add to Report';},2000);
}

// Escape closes coach
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&document.body.classList.contains('coach-open'))toggleCoach();});

// ─── END NEW STUDIO LAYER ──────────────────────────────────────────────────────

// ---- Uniform studio header + footer (plug-ins) ----
if(typeof StudioHeader!=='undefined'){
  StudioHeader.init({
    logoSrc:      '/MM-Studio-long.png',
    logoAlt:      'Mixed Methods Studio',
    projectLabel: BOOT.projectLabel,
    projectLive:  BOOT.projectId > 0,
    projectsUrl:  '/studio-mm-projects.php',
    initials:     '<?= htmlspecialchars($initials) ?>'
  });
  // Design-switch pill moved to sidebar; no longer injected into header.
  // Show RSSI badge from server-resolved scores (no extra fetch needed).
  (function(){
    const sc=BOOT.scores||{};
    if(sc.rssi==null&&!sc.rssiWithheld) return;
    const pct=sc.rssi!=null?Math.round(sc.rssi):null;
    StudioHeader.setRssiStub({
      has_rssi: true,
      score:    sc.rssi,
      pct:      pct,
      band:     sc.rssiBand||'',
      withheld: !!sc.rssiWithheld,
      tier:     sc.rssiWithheld?'withheld':(pct>=85?'confident':'developing'),
      link:     BOOT.surveyId>0?'/rssi-app.php?project_id='+BOOT.surveyId:'/rssi.php'
    });
  })();
} else {
  // Fallback: render the MM header directly if the plug-in script failed to load.
  (function(){
    var el=document.getElementById('studioHeader'); if(!el) return;
    el.innerHTML='<header style="display:flex;align-items:center;gap:14px;height:90px;'
      +'padding:0 22px;background:#fff;border-bottom:1px solid #e6e8ec">'
      +'<a href="/app-2026v4.php" style="display:flex;align-items:center;text-decoration:none">'
      +'<img src="/MM-Studio-long.png" alt="Mixed Methods Studio" style="height:50px;width:auto;display:block"></a>'
      +'<div id="designSwitch" style="flex:1;display:flex;justify-content:center"></div>'
      +'<div style="width:32px;height:32px;border-radius:50%;background:#1f9e44;color:#fff;'
      +'display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px">'
      +'<?= htmlspecialchars($initials) ?></div></header>';
  })();
}
if(typeof StudioFooter!=='undefined'){
  StudioFooter.init();
  // Populate SIRI footer popup from server-resolved score.
  var sc=BOOT.scores||{};
  if(sc.siri!=null){
    StudioFooter.setSiriInfo({
      score: sc.siri,
      band:  '',
      link:  '/develop.php?db=1&start=choose'
    });
  }
  // RSSI footer popup is populated via StudioHeader.setRssiStub below.
  // Data info: show row/col count if dataset is linked.
  var ri=BOOT.rawinfo||{};
  if(ri.linked&&ri.rows>0){
    StudioFooter.setDataInfo(ri.rows, ri.cols||0);
  }
}

state.stepId='start';   // users come straight in to the Start overview (like SIRI)
render();
mmRenderTopbarSteps();
mmRenderSidebar();
mmUpdateNotes();
// First open of a project with no design yet → guide the choice.
if(BOOT.needsDesign){ openHelp(); }

// ----- Bridge: persist a browser-only upload to the server, then link it -----
// The Evidence Intake step (apps/evidence-intake/evidence-intake.js) saves the
// uploaded dataset ONLY to localStorage ('relicheck.dataset.<projectId>'); it
// never creates a server `datasets` row or sets mm_projects.dataset_id. But the
// server-side t-test and Overview read the data via mm_projects.dataset_id, so
// without this they report "No dataset linked". On load, if this project has no
// linked dataset yet but the browser holds its upload, we create a real dataset
// (api/datasets/create.php) and link it (api/mm/link-dataset.php), then reload
// so the server-side pickers populate. Runs at most once per project per tab.
function maybeLinkLocalDataset(){
  if(!BOOT.projectId) return;                                  // demo / no project
  if(BOOT.rawinfo && BOOT.rawinfo.linked) return;              // already linked server-side
  var raw; try{ raw=localStorage.getItem('relicheck.dataset.'+BOOT.projectId); }catch(e){ raw=null; }
  if(!raw) return;                                             // nothing uploaded in this browser
  var ds; try{ ds=((JSON.parse(raw)||{}).payload||{}).dataset; }catch(e){ ds=null; }
  if(!ds || !Array.isArray(ds.variables) || !ds.variables.length) return;
  var guard='mmv4.linktried.'+BOOT.projectId;                  // avoid retry loops / dup datasets
  try{ if(sessionStorage.getItem(guard)) return; sessionStorage.setItem(guard,'1'); }catch(e){}

  var vars=ds.variables;
  var nRows=ds.rowCount || ((vars[0] && vars[0].values) ? vars[0].values.length : 0);
  // The studio derives numeric-vs-categorical from the actual cell values
  // (see PHP $ttVars builder), so `type` here is best-effort, not load-bearing.
  var TMAP={likert:'likert',numeric:'numeric',scale:'numeric',single:'single',categorical:'single',group:'single',multi:'multi',open:'open',text:'open'};
  var column_meta=vars.map(function(v){ var t=(v.types&&v.types[0])||''; return {name:v.name, type:TMAP[t]||'ignore'}; });
  var data=[];                                                 // transpose column-oriented values → row-oriented cells
  for(var r=0;r<nRows;r++){ var row=[]; for(var c=0;c<vars.length;c++){ var vv=vars[c].values||[]; row.push(r<vv.length?vv[r]:''); } data.push(row); }

  toast('Linking your uploaded data…');
  fetch('/api/datasets/create.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({title:(BOOT.projectLabel||'MM project')+' — data',source_filename:String(ds.source||''),column_meta:column_meta,settings:{likertPoints:5},data:data})})
    .then(function(r){return r.json();})
    .then(function(j){ var did=j&&j.dataset&&j.dataset.id; if(!did) throw new Error((j&&j.message)||'Could not save the dataset.');
      return fetch('/api/mm/link-dataset.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({project_id:BOOT.projectId,dataset_id:did})}).then(function(r){return r.json();}); })
    .then(function(j){ if(!j||!j.ok) throw new Error((j&&j.message)||'Could not link the dataset.');
      go('?project_id='+BOOT.projectId); })                    // reload so server-side pickers read the linked data
    .catch(function(e){ console.warn('auto-link dataset failed:',e); });
}
maybeLinkLocalDataset();
</script>
</body>
</html>
