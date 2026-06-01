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
    $fst = $pdo->prepare('SELECT data_kinds, intent_purposes, chosen_design FROM mm_project_framing WHERE project_id = :p LIMIT 1');
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
            $name = (string)($c['name'] ?? ('col_' . $i));
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
            if ($isId) continue;
            if ($isNumeric && $nDistinct >= 3) {
              $ttVars['outcomes'][] = ['id' => $i, 'name' => $name];
            } elseif (!$isNumeric && $nDistinct >= 2 && $nDistinct <= 30) {
              arsort($distinct); $groups = [];
              foreach ($distinct as $val => $cnt) { $groups[] = ['value' => (string)$val, 'n' => (int)$cnt]; if (count($groups) >= 30) break; }
              $ttVars['groupings'][] = ['id' => $i, 'name' => $name, 'groups' => $groups];
            }
          }
        }
      }
    }
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
  'ttvars'       => $ttVars,
  'rawinfo'      => $rawInfo,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mixed Methods Studio · <?= htmlspecialchars($projLabel) ?> — ReliCheck</title>
<style>
:root{
  --ink:#15171a; --ink-2:#5f6368; --ink-3:#8a8f98;
  --bg:#f5f6f8; --panel:#ffffff; --line:#e6e8ec; --line-2:#eef0f3;
  /* chrome is neutral gray; purple is reserved for action buttons only */
  --accent:#6b7280; --accent-hover:#565b63; --accent-soft:#eef0f3; --accent-ink:#2a2f3a;
  --btn:#6d4ad8; --btn-hover:#5a37c0;
  --green:#1f9e44; --green-soft:#e9f7ee;
  --quan:#0A6FE8; --quan-soft:#EEF3FA; --quan-ink:#085fcc;
  --qual:#8A4FD0; --qual-soft:#F2EAFB; --qual-ink:#6d36b0;
  --mm:#1f9e44;   --mm-soft:#e9f7ee;   --mm-ink:#157a33;
  --font:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif;
  --rail:248px; --palette:262px; --companion:336px;
  --shadow:0 1px 2px rgba(20,28,45,.04),0 4px 16px rgba(20,28,45,.05);
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:var(--font);background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;font-size:14px;line-height:1.5}
h1,h2,h3,h4{margin:0;font-weight:700;letter-spacing:-.01em}
button{font-family:inherit;cursor:pointer}
.app{display:grid;grid-template-rows:auto 1fr auto;height:100vh}
/* studio dock (footer) — ReliCheck logo bottom-left, studio name centered (shared studio pattern) */
.studio-dock{position:relative;padding:12px 22px;box-sizing:border-box;background:rgba(255,255,255,0.92);-webkit-backdrop-filter:saturate(1.4) blur(12px);backdrop-filter:saturate(1.4) blur(12px);border-top:1px solid var(--line);box-shadow:0 -4px 22px rgba(15,23,42,0.07)}
.studio-dock-logo{position:absolute;left:22px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;text-decoration:none}
.studio-dock-logo img{height:24px;width:auto;display:block}
.studio-dock-inner{display:flex;align-items:center;justify-content:center;gap:11px;flex-wrap:wrap;min-height:34px}
.studio-dock .lbl{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-right:2px}
.as-intake-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:10px;border:1px solid var(--line);background:var(--panel);color:var(--ink-2);font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:.13s}
.as-intake-btn:hover{border-color:var(--accent);color:var(--accent-ink);background:var(--accent-soft)}
.as-intake-btn svg{color:var(--accent)}
.dk-sep{width:1px;height:22px;background:var(--line);margin:0 3px}
.dk-rssi{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel);font-size:13px;font-weight:700;color:var(--ink-2);text-decoration:none;transition:.13s}
.dk-rssi:hover{border-color:var(--quan)}
.dk-rssi .dot{width:8px;height:8px;border-radius:50%;background:#cdd6e4}
.dk-rssi.is-available .dot{background:var(--mm)}
.dk-rssi small{font-weight:600;color:var(--ink-3)}
@media(max-width:760px){.studio-dock-logo{display:none}}
.topbar{display:flex;align-items:center;gap:14px;height:90px;padding:0 22px;background:var(--panel);border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;text-decoration:none}
.brand img{height:70px;width:auto;display:block}
.design-switch{margin:0 auto;display:flex;gap:4px;background:var(--bg);border:1px solid var(--line);border-radius:999px;padding:4px;max-width:62vw;overflow-x:auto}
.ds-btn{border:none;background:none;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:700;color:var(--ink-2);transition:.13s;display:flex;align-items:center;gap:7px;white-space:nowrap}
.ds-btn:hover{color:var(--ink)}
.ds-btn .lead{font-size:9px;font-weight:800;letter-spacing:.03em;padding:2px 6px;border-radius:999px;text-transform:uppercase}
.ds-btn .lead.quan{background:var(--quan-soft);color:var(--quan-ink)}
.ds-btn .lead.qual{background:var(--qual-soft);color:var(--qual-ink)}
.ds-btn .lead.both{background:var(--mm-soft);color:var(--mm-ink)}
.ds-btn.active{background:var(--panel);color:var(--ink);box-shadow:var(--shadow)}
.topbar-right{display:flex;align-items:center;gap:12px}
.help-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:999px;border:1px dashed var(--accent);background:var(--accent-soft);color:var(--accent-ink);font-size:12.5px;font-weight:700}
.help-btn:hover{border-style:solid}
.ctx{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:var(--ink-2);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ctx .dot{width:7px;height:7px;border-radius:50%;background:var(--green);flex:none}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700;flex:none}
.body{display:grid;grid-template-columns:var(--rail) minmax(0,1fr) var(--companion);min-height:0;overflow:hidden;transition:grid-template-columns .22s ease}
body.companion-collapsed{--companion:46px}
.stage{display:flex;gap:26px;min-width:0;overflow:hidden;padding:0 24px 0 120px}
@media(max-width:1320px){.stage{padding:0 20px 0 clamp(20px,3vw,48px)}}
/* the left pipeline rail keeps the purple brand; the rest of the app stays gray */
.rail{background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:16px 12px;overflow-y:auto;--accent:#6d4ad8;--accent-soft:#efeafd;--accent-ink:#4a2aa0}
.rail-h{font-size:10.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-3);padding:4px 12px 8px}
.design-pick{position:relative;padding:0 10px 4px}
.design-pick-btn{display:flex;align-items:center;gap:8px;width:100%;text-align:left;background:none;border:1px solid transparent;border-radius:9px;padding:5px 8px;cursor:pointer;transition:.12s}
.design-pick-btn:hover{background:var(--bg);border-color:var(--line)}
.dp-name{flex:1;font-size:14px;font-weight:800;letter-spacing:-.01em;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dp-caret{color:var(--ink-3);font-size:11px;flex:none}
.design-pick.open .dp-caret{color:var(--accent)}
.design-menu{display:none;position:absolute;left:8px;right:8px;top:100%;z-index:40;margin-top:4px;background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 28px rgba(20,28,45,.14);padding:6px;max-height:60vh;overflow-y:auto}
.design-pick.open .design-menu{display:block}
.dm-opt{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:9px;cursor:pointer;transition:.1s}
.dm-opt:hover{background:var(--bg)}
.dm-opt.active{background:var(--accent-soft)}
.dm-opt .lead{font-size:8.5px;font-weight:800;letter-spacing:.02em;padding:2px 6px;border-radius:999px;text-transform:uppercase;flex:none}
.dm-opt .lead.quan{background:var(--quan-soft);color:var(--quan-ink)} .dm-opt .lead.qual{background:var(--qual-soft);color:var(--qual-ink)} .dm-opt .lead.both{background:var(--mm-soft);color:var(--mm-ink)}
.dm-opt .dm-lbl{font-size:12.5px;font-weight:650;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dm-opt.active .dm-lbl{color:var(--accent-ink)}
.dm-opt .dm-check{margin-left:auto;color:var(--accent);font-weight:800;font-size:12px;visibility:hidden}
.dm-opt.active .dm-check{visibility:visible}
.design-flow{display:flex;align-items:center;gap:5px;padding:6px 12px 14px;flex-wrap:wrap}
.flow-pill{font-size:9.5px;font-weight:800;letter-spacing:.02em;padding:3px 8px;border-radius:999px}
.flow-pill.quan{background:var(--quan-soft);color:var(--quan-ink)} .flow-pill.qual{background:var(--qual-soft);color:var(--qual-ink)} .flow-pill.both{background:var(--mm-soft);color:var(--mm-ink)}
.flow-arrow{color:var(--ink-3);font-size:11px}
.step{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:11px;color:var(--ink-2);font-size:14px;font-weight:600;border:1px solid transparent;transition:.12s;margin-bottom:1px}
.step:hover{background:var(--bg);color:var(--ink)}
.step .num{width:24px;height:24px;border-radius:8px;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--bg);color:var(--ink-3);border:1px solid var(--line);flex:none}
.step .lbl{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.step .sdot{width:7px;height:7px;border-radius:50%;flex:none;background:var(--line)}
.step.quan .sdot{background:var(--quan)} .step.qual .sdot{background:var(--qual)} .step.both .sdot{background:var(--mm)}
.step .tick{display:none}
.step[data-done="1"] .num{background:var(--green-soft);color:var(--green);border-color:transparent}
.step[data-done="1"] .sdot{display:none}
.step[data-active="1"]{background:var(--accent-soft);color:var(--accent-ink);border-color:rgba(232,93,58,.18)}
.step[data-active="1"] .num{background:var(--accent);color:#fff;border-color:transparent}
.step[data-active="1"] .sdot{display:none}
.step.pivot{border:1px dashed var(--accent);background:linear-gradient(180deg,#fffdfb,var(--accent-soft))}
.step.pivot .num{background:#fff;color:var(--accent);border-color:var(--accent)}
.step.pivot[data-active="1"] .num{background:var(--accent);color:#fff}
.center{flex:1 1 auto;overflow-y:auto;min-width:0;padding:30px 4px 60px 0}
.center-inner{max-width:none}
.ws-header{position:sticky;top:0;z-index:5;background:linear-gradient(180deg,var(--bg) 78%,rgba(245,246,248,0));padding-top:6px;margin-bottom:14px}
.eyebrow{display:inline-flex;align-items:center;gap:9px;font-size:11.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--accent-ink);margin-bottom:8px}
.eyebrow .mode-chip{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.04em;background:var(--ink);color:#fff}
.eyebrow .mode-chip.output{background:var(--quan)} .eyebrow .mode-chip.work{background:var(--accent)} .eyebrow .mode-chip.setup{background:var(--ink-3)}
.strand-chip{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.02em}
.strand-chip.quan{background:var(--quan-soft);color:var(--quan-ink)} .strand-chip.qual{background:var(--qual-soft);color:var(--qual-ink)} .strand-chip.both{background:var(--mm-soft);color:var(--mm-ink)}
.title{font-size:24px;font-weight:650;letter-spacing:-.02em;margin-bottom:6px}
.lede{font-size:15px;color:var(--ink-2);max-width:640px;margin-bottom:0;line-height:1.5}
.context-strip{display:flex;align-items:center;gap:10px;padding:9px 15px;background:var(--panel);border:1px solid var(--line);border-radius:999px;font-size:12.5px;color:var(--ink-2);margin-bottom:14px;width:fit-content}
.context-strip .dot{width:8px;height:8px;border-radius:50%;background:var(--mm)}
.context-strip b{color:var(--ink);font-weight:700}
.answer{display:flex;gap:11px;align-items:flex-start;padding:12px 15px;border-radius:13px;background:var(--accent-soft);border:1px solid rgba(232,93,58,.2);margin-bottom:16px}
.answer .a-ico{width:24px;height:24px;border-radius:7px;flex:none;display:grid;place-items:center;background:var(--accent);color:#fff;font-size:13px}
.answer .a-text{font-size:14px;line-height:1.55;color:var(--accent-ink)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden}
.panel-h{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line-2)}
.panel-h h3{font-size:15px;font-weight:650}.panel-h .ph-sub{font-size:12.5px;color:var(--ink-3);margin-top:2px}
.panel-b{padding:20px}
.panel-b.engine{padding:0}
.ws-frame{width:100%;min-height:640px;border:0;display:block;background:var(--bg)}
/* Independent Samples t-Test */
.tt-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 18px;margin-bottom:6px}
@media(max-width:680px){.tt-grid{grid-template-columns:1fr}}
.field label{display:block;font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3);margin-bottom:6px}
.tt-hint{font-size:11px;font-weight:600;color:var(--ink-3);text-transform:none;letter-spacing:0;margin-top:5px}
label .tt-hint{margin-left:6px}
.tt-segs{display:inline-flex;gap:4px;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:3px}
.tt-seg{border:none;background:none;padding:7px 13px;border-radius:8px;font-size:12.5px;font-weight:700;color:var(--ink-2);cursor:pointer}
.tt-seg.on{background:var(--panel);color:var(--ink);box-shadow:var(--shadow)}
.tt-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:14px}
.tt-tab{border:1px solid var(--line);background:var(--panel);padding:8px 13px;border-radius:9px;font-size:12.5px;font-weight:700;color:var(--ink-2);cursor:pointer}
.tt-tab.on{background:var(--accent-soft);color:var(--accent-ink);border-color:var(--accent-ring,var(--line))}
.tt-status{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:999px}
.tt-status.ok{background:var(--mm-soft);color:var(--mm-ink)} .tt-status.rev{background:#fbf1df;color:#8a6418}
/* Quantitative Descriptives — tables + layered interpretation */
.dx-scroll{overflow-x:auto;margin:0 -4px}
.dx-table{width:100%;border-collapse:collapse;font-size:13px;min-width:560px}
.dx-table th{text-align:right;font-size:10.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3);padding:0 12px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
.dx-table th.l{text-align:left}
.dx-table td{padding:11px 12px;border-bottom:1px solid var(--line-2);text-align:right;font-variant-numeric:tabular-nums;color:var(--ink-2);white-space:nowrap}
.dx-table tr:last-child td{border-bottom:none}
.dx-name{text-align:left!important;font-weight:650;color:var(--ink)}
.dx-interp{text-align:left!important;color:var(--ink-2);white-space:normal}
.dx-neg{color:#c0524a;font-weight:700} .dx-pos{color:var(--mm-ink);font-weight:700}
.dx-total td{border-top:1.5px solid var(--line);border-bottom:none;font-weight:700;color:var(--ink)}
.dx-layers{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:20px;margin-bottom:18px}
.dx-l{margin-bottom:16px}.dx-l:last-child{margin-bottom:0}
.dx-l-k{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-3);margin-bottom:5px}
.dx-l-t{font-size:14px;line-height:1.55;color:var(--ink-2)}
.dx-q{background:var(--accent-soft);border:1px solid var(--line);border-radius:12px;padding:13px 15px}
.dx-q .dx-l-t{color:var(--ink);font-weight:600}
.dx-caution{background:#fbf1df;border:1px solid #f0ddb0;border-radius:12px;padding:13px 15px}
.dx-caution .dx-l-k{color:#8a6418}.dx-caution .dx-l-t{color:#7a5e1c}
.dx-next{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--panel);border:1px solid var(--btn);border-radius:14px;padding:14px 18px;box-shadow:var(--shadow);margin-bottom:18px}
.dx-next-k{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--btn);flex:none}
.dx-next-t{font-size:13.5px;color:var(--ink-2);line-height:1.5}.dx-next-t b{color:var(--ink)}
/* Data Quality step — pre-analysis checks, each with its risk */
.dq-card{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:18px}
.dq-row{display:flex;align-items:center;gap:14px;padding:16px 20px;border-bottom:1px solid var(--line-2)}
.dq-row:last-child{border-bottom:none}
.dq-ico{width:28px;height:28px;border-radius:8px;flex:none;display:grid;place-items:center;font-size:14px;font-weight:800;background:var(--accent-soft);color:var(--ink-2)}
.dq-body{flex:1}
.dq-name{font-size:14.5px;font-weight:700;color:var(--ink)}
.dq-risk{font-size:13px;color:var(--ink-2);margin-top:2px}
.dq-status{font-size:11.5px;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em;flex:none}
/* Data Map — classification step: summary cards, tabs, integration flow */
.dm-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.dm-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:13px 15px;display:flex;flex-direction:column;gap:6px}
.dm-card-k{font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-3)}
.dm-card-v{font-size:23px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:-.01em;line-height:1.1}
.dm-card-v.sm{font-size:15px;font-weight:700;letter-spacing:0;word-break:break-word}
.dm-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px}
.dm-tab{border:1px solid var(--line);background:var(--panel);padding:8px 13px;border-radius:9px;font-size:12.5px;font-weight:700;color:var(--ink-2);cursor:pointer}
.dm-tab:hover{color:var(--ink)}
.dm-tab.on{background:var(--accent-soft);color:var(--btn);border-color:var(--btn)}
.dm-sel{padding:6px 9px;font-size:12.5px;border-radius:8px;max-width:240px;width:auto}
.dm-flow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:16px 18px;margin-bottom:18px}
.dm-flow-node{font-size:12.5px;font-weight:700;color:var(--ink-2);background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:8px 12px}
.dm-flow-node.quan{background:var(--quan-soft);color:var(--quan-ink);border-color:transparent}
.dm-flow-node.qual{background:var(--qual-soft);color:var(--qual-ink);border-color:transparent}
.dm-flow-node.mm{background:var(--mm-soft);color:var(--mm-ink);border-color:transparent}
.dm-flow-arrow{color:var(--ink-3);font-weight:800}
.dm-fit-sel td{background:var(--accent-soft)}
.dm-fit-sel td:first-child{box-shadow:inset 3px 0 0 var(--btn)}
.dm-note{font-size:12.5px;color:var(--ink-3)}
.dm-save{display:flex;align-items:center;gap:12px;margin:14px 0 4px}
/* Qualitative Themes — coverage meter, sentiment chips, quotes */
.th-bar{height:7px;border-radius:999px;background:var(--line-2);overflow:hidden;min-width:90px;margin-top:5px}
.th-bar > i{display:block;height:100%;background:var(--qual);border-radius:999px}
.th-cov{font-size:12.5px;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums}
.th-sent{font-size:11.5px;font-weight:700;color:var(--ink-3);white-space:nowrap}
.th-sent b.pos{color:var(--mm-ink)} .th-sent b.neg{color:#b3402f} .th-sent b.neu{color:var(--ink-3)}
.th-quotes{background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:6px 18px;margin:14px 0 18px}
.th-quote{padding:13px 0;border-bottom:1px solid var(--line-2)}
.th-quote:last-child{border-bottom:none}
.th-quote-t{font-size:14px;color:var(--ink);line-height:1.55}
.th-quote-m{font-size:11.5px;font-weight:700;color:var(--ink-3);margin-top:5px;display:flex;gap:8px;flex-wrap:wrap}
.th-empty{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:26px;text-align:center}
.th-empty h3{font-size:16px;margin-bottom:6px}.th-empty p{font-size:13.5px;color:var(--ink-2);max-width:520px;margin:0 auto 16px}
/* Overview step — review of setup (data + answered questions + design) */
.ov-title{font-size:24px;font-weight:650;letter-spacing:-.02em;margin-bottom:6px}
.ov-sec{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);margin:4px 0 12px}
.ov-data{border:1px solid var(--line);border-radius:16px;overflow:hidden;background:var(--panel);box-shadow:var(--shadow);margin-bottom:18px}
.ov-summary{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:6px 20px;margin-bottom:22px}
.ov-row{display:flex;gap:18px;align-items:flex-start;padding:15px 0;border-bottom:1px solid var(--line-2)}
.ov-row:last-child{border-bottom:none}
.ov-k{width:150px;flex:none;font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3);padding-top:5px}
.ov-v{flex:1;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ov-design{font-size:14.5px;font-weight:650;color:var(--ink)}
.ov-chip{font-size:12.5px;font-weight:600;color:var(--ink-2);background:var(--accent-soft);border:1px solid var(--line);padding:5px 11px;border-radius:999px}
.ov-empty{font-size:13px;color:var(--ink-3);font-style:italic}
.ov-score{font-size:20px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:-.02em}
.ov-score-max{font-size:12.5px;font-weight:700;color:var(--ink-3);margin-left:-3px}
.ov-band{font-size:11.5px;font-weight:700;color:var(--ink-2);background:var(--accent-soft);border:1px solid var(--line);padding:3px 9px;border-radius:999px}
.ov-link{font-size:12.5px;font-weight:700;color:var(--btn);text-decoration:none;margin-left:8px}
.ov-link:hover{text-decoration:underline}
.ed-l{display:block;font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3);margin:14px 0 6px}
.ed-l:first-child{margin-top:0}
.ed-in{width:100%;font-family:inherit;font-size:14px;color:var(--ink);background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:10px 12px;resize:vertical}
.ed-in:focus{outline:none;border-color:var(--btn);box-shadow:0 0 0 3px var(--accent-soft)}
.ed-foot{display:flex;gap:10px;justify-content:flex-end;margin-top:20px}
/* Start step — bold landing-style hero + SIRI begin layout */
.start-hero{font-size:28px;font-weight:650;letter-spacing:-.02em;line-height:1.25;margin-bottom:10px;max-width:30ch}
.start-hero .accent{color:var(--btn)}
.begin-loaded{display:flex;align-items:center;gap:10px;padding:11px 16px;border:1px solid var(--line);background:var(--panel);border-radius:12px;font-size:13.5px;color:var(--ink-2);margin-bottom:20px;box-shadow:var(--shadow)}
.begin-loaded .dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex:none}
.begin-loaded .bl-k{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3)}
.proj-select{font-family:inherit;font-size:13.5px;font-weight:700;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:9px;padding:7px 30px 7px 12px;cursor:pointer;max-width:380px;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8f98' stroke-width='3' stroke-linecap='round'><polyline points='6 9 12 15 18 9'/></svg>");background-repeat:no-repeat;background-position:right 10px center}
.proj-select:focus{outline:none;border-color:var(--btn);box-shadow:0 0 0 3px var(--accent-soft)}
.bc-ico{width:40px;height:40px;border-radius:11px;background:var(--accent-soft);color:var(--ink-2);display:grid;place-items:center;font-size:18px;flex:none}
.begin-feature{display:flex;gap:18px;align-items:flex-start;text-align:left;width:100%;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:24px;cursor:pointer;transition:.14s;box-shadow:var(--shadow);margin-bottom:26px}
.begin-feature:hover{border-color:var(--btn)}
.begin-feature .bc-ico{width:44px;height:44px;font-size:20px}
.begin-feature h4{font-size:18px;font-weight:800;margin-bottom:6px}
.begin-feature p{font-size:14px;color:var(--ink-2);line-height:1.55;margin:0 0 12px;max-width:72ch}
.begin-feature .bc-go{font-size:14px;font-weight:800;color:var(--btn)}
.begin-sec{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);margin:0 0 14px}
.begin-grid2{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
@media(max-width:980px){.begin-grid2{grid-template-columns:1fr}}
.begin-grid2 .begin-card2{padding:18px}
.begin-card2{text-align:left;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px;cursor:pointer;transition:.14s;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:5px}
.begin-card2:hover{border-color:var(--btn);transform:translateY(-2px)}
.begin-card2 .bc-ico{margin-bottom:8px}
.begin-card2 h4{font-size:15.5px;font-weight:800}
.begin-card2 p{font-size:13px;color:var(--ink-2);line-height:1.5;margin:0;flex:1}
.begin-card2 .bc-go{font-size:13px;font-weight:800;color:var(--ink-2);margin-top:10px}
.work-surface{border:1.5px dashed var(--line);border-radius:13px;background:var(--bg);padding:24px;color:var(--ink-2);font-size:13.5px;line-height:1.6}
.btn{border:1px solid var(--line);background:var(--panel);color:var(--ink-2);font-weight:700;font-size:13.5px;padding:10px 16px;border-radius:11px;transition:.12s;display:inline-flex;align-items:center;gap:7px}
.btn:hover{background:var(--bg);color:var(--ink)}
.btn.primary{background:var(--btn);border-color:var(--btn);color:#fff}
.btn.primary:hover{background:var(--btn-hover);border-color:var(--btn-hover)}
.run-actions{display:flex;gap:9px;margin-top:18px;flex-wrap:wrap}
.ws-tool-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.ws-tool-head h4{font-size:15.5px;font-weight:650}
.ws-tool-head .ws-dot{width:9px;height:9px;border-radius:50%;flex:none}
.ws-tool-head .ws-dot.quan{background:var(--quan)} .ws-tool-head .ws-dot.qual{background:var(--qual)} .ws-tool-head .ws-dot.both{background:var(--mm)}
.ws-tool-head .ws-tag{margin-left:auto;font-size:11px;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em}
.footer-nav{display:flex;justify-content:space-between;align-items:center;margin-top:22px}
.palette{flex:none;width:var(--palette);align-self:flex-start;margin:30px 0 18px;background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow-y:auto;max-height:calc(100vh - 58px - 60px);padding:16px 16px}
.palette-h{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-3);margin-bottom:4px}
.palette-intro{font-size:13px;color:var(--ink-2);line-height:1.5;margin-bottom:16px}
.pal-group{font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-3);margin:14px 0 8px}
.pal-item{display:flex;align-items:center;gap:9px;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font-size:13.5px;font-weight:600;color:var(--ink);margin-bottom:7px;transition:.12s}
.pal-item:hover{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-ink)}
.pal-item.active{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-ink)}
.pal-item .pdot{width:7px;height:7px;border-radius:50%;flex:none}
.pal-item.quan .pdot{background:var(--quan)} .pal-item.qual .pdot{background:var(--qual)} .pal-item.both .pdot{background:var(--mm)}
.pal-empty{font-size:12.5px;color:var(--ink-3);line-height:1.5;border:1.5px dashed var(--line);border-radius:11px;padding:16px;background:var(--bg)}
.companion{background:var(--panel);border-left:1px solid var(--line);display:flex;flex-direction:column;min-height:0;overflow:hidden;position:relative}
.comp-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line-2)}
.comp-head .ch-ico{width:30px;height:30px;border-radius:9px;background:var(--accent-soft);color:var(--accent-ink);display:grid;place-items:center;font-size:15px;flex:none}
.comp-head h3{font-size:14px}.comp-head .ch-sub{font-size:11px;color:var(--ink-3)}
.comp-toggle{margin-left:auto;width:26px;height:26px;border-radius:7px;border:1px solid var(--line);background:var(--panel);color:var(--ink-2);display:grid;place-items:center;flex:none}
.comp-tabs{display:flex;gap:4px;padding:10px 14px 0}
.comp-tab{flex:1;text-align:center;padding:8px 6px;border-radius:9px;font-size:12px;font-weight:700;color:var(--ink-3)}
.comp-tab.active{background:var(--accent-soft);color:var(--accent-ink)}
.comp-body{padding:16px;overflow-y:auto;flex:1}
.comp-block{margin-bottom:16px}
.cb-k{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:7px;color:var(--ink-3)}
.cb-k .i{width:16px;height:16px;border-radius:5px;display:grid;place-items:center;font-size:10px;color:#fff;background:var(--accent)}
.cb-t{font-size:13px;line-height:1.55;color:var(--ink-2)}.cb-t b{color:var(--ink);font-weight:700}
.comp-why{background:var(--accent-soft);border:1px solid rgba(232,93,58,.2);border-radius:12px;padding:13px 14px}
.comp-why .cb-k{color:var(--accent-ink)}.comp-why .cb-t{color:var(--accent-ink)}
.notes-area{width:100%;min-height:200px;border:1px solid var(--line);border-radius:12px;padding:12px;font-family:inherit;font-size:13px;resize:vertical;color:var(--ink)}
.ai-prompt{border:1px solid var(--line);border-radius:12px;padding:12px;font-size:13px;color:var(--ink-3);background:var(--bg);margin-bottom:12px}
.ai-suggest{display:flex;flex-direction:column;gap:8px}
.ai-chip{text-align:left;border:1px solid var(--line);background:var(--panel);border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600;color:var(--ink)}
.ai-chip:hover{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-ink)}
.ai-answer{border:1px solid rgba(232,93,58,.22);background:var(--accent-soft);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--accent-ink);margin-top:12px}
.comp-collapsed-tab{display:none}
.ctab-vert{writing-mode:vertical-rl;transform:rotate(180deg);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--accent-ink)}
body.companion-collapsed .comp-body,body.companion-collapsed .comp-tabs,body.companion-collapsed .comp-head h3,body.companion-collapsed .comp-head .ch-meta{display:none}
body.companion-collapsed .comp-head{justify-content:center;padding:14px 6px}
body.companion-collapsed .comp-toggle{margin-left:0}
body.companion-collapsed .comp-collapsed-tab{display:flex;flex-direction:column;align-items:center;gap:14px;padding-top:18px;cursor:pointer}
.phase-banner{display:flex;gap:9px;align-items:center;padding:9px 14px;background:var(--accent-soft);border:1px solid rgba(232,93,58,.2);border-radius:11px;font-size:12px;font-weight:600;color:var(--accent-ink);margin:0 22px}
.modal-scrim{display:none;position:fixed;inset:0;background:rgba(20,28,45,.34);z-index:80;align-items:center;justify-content:center;padding:20px}
.modal-scrim.open{display:flex}
.modal{background:var(--panel);border-radius:18px;box-shadow:0 14px 40px rgba(20,28,45,.18);width:560px;max-width:100%;max-height:88vh;overflow-y:auto}
.modal-h{padding:18px 22px;border-bottom:1px solid var(--line-2);display:flex;align-items:center}
.modal-h h3{font-size:16px}.modal-h .mx{margin-left:auto;background:none;border:none;color:var(--ink-3);font-size:18px}
.modal-b{padding:20px 22px}
.q-card{border:1px solid var(--line);border-radius:13px;padding:16px;margin-bottom:12px}
.q-card:hover{border-color:var(--accent);background:var(--accent-soft)}
.q-card h4{font-size:14px;margin-bottom:5px;display:flex;align-items:center;gap:8px}
.q-card p{font-size:12.5px;color:var(--ink-2);margin:0;line-height:1.5}
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--ink);color:#fff;padding:11px 18px;border-radius:999px;font-size:13px;font-weight:600;z-index:90;opacity:0;transition:.25s;pointer-events:none}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
@media(max-width:1280px){.body{grid-template-columns:var(--rail) minmax(0,1fr)} .companion{display:none}}
@media(max-width:1040px){.palette{display:none}}
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <a class="brand" href="/app-2026v4.php" aria-label="Mixed Methods Studio">
      <img src="/MM-Studio-long.png" alt="Mixed Methods Studio">
    </a>
    <div class="design-switch" id="designSwitch"></div>
    <div class="topbar-right">
      <button class="help-btn" onclick="openHelp()">✦ Help me choose</button>
      <div class="ctx"><span class="dot"></span><?= htmlspecialchars($projLabel) ?></div>
      <div class="avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
  </header>

  <div class="body">
    <nav class="rail">
      <div class="rail-h">Analysis Pipeline</div>
      <div class="design-pick" id="designPick">
        <button class="design-pick-btn" onclick="toggleDesignMenu(event)">
          <span class="dp-name" id="designName"></span><span class="dp-caret">▾</span>
        </button>
        <div class="design-menu" id="designMenu"></div>
      </div>
      <div class="design-flow" id="designFlow"></div>
      <div id="railSteps"></div>
    </nav>

    <div class="stage">
      <main class="center"><div class="center-inner" id="centerInner"></div></main>
      <aside class="palette" id="palette"></aside>
    </div>

    <aside class="companion" id="companion">
      <div class="comp-collapsed-tab" onclick="toggleCompanion()"><span style="font-size:16px">✦</span><span class="ctab-vert">Coach</span></div>
      <div class="comp-head">
        <div class="ch-ico">✦</div>
        <div class="ch-meta"><h3>ReliCheck Coach</h3><div class="ch-sub">Explain · Notes · Intelligence</div></div>
        <button class="comp-toggle" onclick="toggleCompanion()" title="Collapse">⟩</button>
      </div>
      <div class="comp-tabs" id="compTabs"></div>
      <div class="comp-body" id="compBody"></div>
    </aside>
  </div>

  <footer class="studio-dock" role="region" aria-label="Data and apps">
    <a class="studio-dock-logo" href="/app-2026v4.php" aria-label="ReliCheck home">
      <img src="/logo-brand.svg" alt="ReliCheck">
    </a>
    <div class="studio-dock-inner">
      <span class="lbl">Apps</span>
      <a class="as-intake-btn" href="/develop.php?db=1&amp;start=choose" title="Build and strengthen a survey in SIRI">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        SIRI
      </a>
      <a class="dk-rssi" id="dockRssi" href="/rssi.php" title="Check survey strength &amp; reliability in RSSI">
        <span class="dot"></span>RSSI <small id="dockRssiState">strength</small>
      </a>
    </div>
  </footer>
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
    <button class="begin-feature" onclick="go('/mm-wizard.php?return=mmstudioV4')">
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
    <div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue to analysis →</button></div>`;
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
function descMsg(eyebrow,title,lede,msg){return descHead(eyebrow,title,lede)+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div><div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
function renderFreq(s){
  const cats=dx.base.categorical; const expl=state.design==='explanatory';
  if(!cats.length){$("#centerInner").innerHTML=descMsg('Frequencies','Frequencies','Who is represented, category by category.','No categorical variables were found to tabulate.');return;}
  const c=cats.find(x=>x.id===dx.freqId)||cats[0]; dx.freqId=c.id;
  let cum=0; const body=c.categories.map(r=>{cum+=r.valid_pct;return `<tr><td class="dx-name">${esc(r.value)}</td><td>${r.count}</td><td>${_pc(r.pct)}</td><td>${_pc(r.valid_pct)}</td><td>${_pc(cum)}</td></tr>`;}).join('');
  const miss=c.missing?`<tr><td class="dx-name">Missing</td><td>${c.missing}</td><td>${_pc(100*c.missing/(dx.base.rows||1))}</td><td>—</td><td>—</td></tr>`:'';
  const top=c.categories[0];
  $("#centerInner").innerHTML=descHead('Frequencies','Frequencies','Who is represented in the quantitative phase, category by category.')+helpBar('Frequencies')+`
    <div style="${_PICK}"><label class="ed-l" style="margin:0">Variable</label>${dxSel(c.id,cats,'setFreq(this.value)')}</div>
    <div class="panel"><div class="panel-h"><div><h3>Table 1 · Frequency distribution for ${esc(c.name)}</h3></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">${esc(c.name)}</th><th>Frequency</th><th>Percent</th><th>Valid Percent</th><th>Cumulative Percent</th></tr></thead>
        <tbody>${body}${miss}<tr class="dx-total"><td class="dx-name">Total</td><td>${dx.base.rows}</td><td>100.0%</td><td>100.0%</td><td>—</td></tr></tbody></table></div></div></div>
    <div class="dx-layers">
      <div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">${top?`The most common ${esc(c.name)} value was “${esc(top.value)}” — ${_pc(top.valid_pct)} of valid responses (n = ${top.count}).`:'No responses to summarize.'}</div></div>
      <div class="dx-l"><div class="dx-l-k">Why this matters</div><div class="dx-l-t">How the sample is distributed across ${esc(c.name)} can shape how the other results should be read.</div></div>
      ${expl?`<div class="dx-l dx-q"><div class="dx-l-k">Mixed methods use</div><div class="dx-l-t">Which groups are well represented in the quantitative phase, and which may need qualitative follow-up?</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">Counts describe who is in the data; on their own they do not explain differences between groups.</div></div>
    </div>
    <div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;
}
function setMeansNum(v){dx.mNum=+v;renderDescriptive(activeStep());}
function setMeansGrp(v){dx.mGrp=+v;renderDescriptive(activeStep());}
function renderMeans(s){
  const nu=dx.base.numeric,cats=dx.base.categorical; const expl=state.design==='explanatory';
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
      <div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">${(hi&&lo&&hi!==lo)?`Across ${nu.length} numeric variables, “${esc(hi.name)}” had the highest average (M = ${_n2(hi.mean)}) and “${esc(lo.name)}” the lowest (M = ${_n2(lo.mean)}).`:(hi?`“${esc(hi.name)}” had a mean of ${_n2(hi.mean)}.`:'No numeric variables to summarize.')}</div></div>
      <div class="dx-l"><div class="dx-l-k">Why this matters</div><div class="dx-l-t">${expl?'In an Explanatory Sequential design, these averages help identify which quantitative patterns may need qualitative explanation.':'These averages set up the comparison your qualitative strand will speak to.'}</div></div>
      ${expl?`<div class="dx-l dx-q"><div class="dx-l-k">Possible follow-up question</div><div class="dx-l-t">What experiences might help explain the differences in these averages between groups?</div></div>`:''}
      <div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">These are averages. They do not test whether differences are statistically significant or explain why they exist.</div></div>
    </div>
    <div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;
}
function setXr(v){dx.xr=+v;renderDescriptive(activeStep());}
function setXc(v){dx.xc=+v;renderDescriptive(activeStep());}
function renderCrossTabs(s){
  const cats=dx.base.categorical; const expl=state.design==='explanatory';
  if(cats.length<2){$("#centerInner").innerHTML=descMsg('Cross-tabs','Cross-Tabs','Compare categories across groups before testing patterns.','Cross-tabs need at least two categorical variables; this dataset has fewer.');return;}
  if(dx.xr===dx.xc){const alt=cats.find(c=>c.id!==dx.xr); if(alt)dx.xc=alt.id;}
  const pick=`<div style="${_PICK}"><label class="ed-l" style="margin:0">Rows</label>${dxSel(dx.xr,cats,'setXr(this.value)')}<label class="ed-l" style="margin:0 0 0 12px">Columns</label>${dxSel(dx.xc,cats,'setXc(this.value)')}</div>`;
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
    <div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;
}
/* ===== Independent Samples t-Test (real data via /api/mm/ttest.php) ===== */
const tt={grouping:null,testType:'auto',conf:0.95,result:null,tab:'desc',busy:false,added:false};
function fmt(n,d){return (n==null||isNaN(n))?'—':Number(n).toFixed(d==null?2:d);}
function ttGroupOpts(g){return (g?g.groups:[]).map(o=>`<option value="${esc(o.value)}">${esc(o.value)} (n=${o.n})</option>`).join('');}
function renderTTest(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]};
  if(!BOOT.projectId||!V.datasetReady||!V.outcomes.length||!V.groupings.length){
    $("#centerInner").innerHTML=`
      <div class="ws-header"><div class="eyebrow">Group comparisons <span class="strand-chip quan">QUAN</span></div>
        <h1 class="title">Independent Samples t-Test</h1><p class="lede">Compare the mean of one outcome across two groups.</p></div>
      <div class="work-surface" style="border-radius:16px">No structured dataset with numeric and categorical variables is available for this project yet. Build or connect data from Start, then return here.</div>`;
    return;
  }
  if(tt.grouping==null) tt.grouping=V.groupings[0].id;
  const grp=V.groupings.find(g=>g.id===tt.grouping)||V.groupings[0];
  const seg=(k,l)=>`<button class="tt-seg ${tt.testType===k?'on':''}" onclick="tt.testType='${k}';renderTTest(activeStep())">${l}</button>`;
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and the two groups to compare</div></div></div>
      <div class="panel-b">
        <div class="tt-grid">
          <div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="ttOut">${V.outcomes.map(o=>`<option value="${o.id}">${esc(o.name)}</option>`).join('')}</select></div>
          <div class="field"><label>Grouping variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="ttGrp" onchange="tt.grouping=+this.value;renderTTest(activeStep())">${V.groupings.map(g=>`<option value="${g.id}" ${g.id===tt.grouping?'selected':''}>${esc(g.name)}</option>`).join('')}</select></div>
          <div class="field"><label>Group 1</label><select class="ed-in" id="ttG1">${ttGroupOpts(grp)}</select></div>
          <div class="field"><label>Group 2</label><select class="ed-in" id="ttG2">${ttGroupOpts(grp)}</select></div>
          <div class="field"><label>Test type</label><div class="tt-segs">${seg('auto','Auto')}${seg('welch','Welch')}${seg('student','Student')}</div><div class="tt-hint">Welch is the default — safer when sizes or variances differ.</div></div>
          <div class="field"><label>Confidence</label><select class="ed-in" id="ttConf"><option value="0.90">90%</option><option value="0.95" selected>95%</option><option value="0.99">99%</option></select></div>
        </div>
        <div class="run-actions"><button class="btn primary" onclick="runTTest()" ${tt.busy?'disabled':''}>${tt.busy?'Running…':'▷ Run t-test'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Group comparisons <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Independent Samples t-Test</h1><p class="lede">Compare the mean of one outcome across two groups.</p></div>
    ${helpBar('t-test')}
    ${setup}
    <div id="ttResults">${tt.result?renderTTestResults(tt.result):''}</div>`;
  // default group2 to the second distinct value
  const g2=$("#ttG2"); if(g2&&g2.options.length>1&&!g2.value) g2.selectedIndex=1;
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
function addToExplain(){
  const r=tt.result; if(!r) return;
  const obj={project_id:BOOT.projectId,source:'t_test',outcome:r.outcome.name,grouping:r.grouping.name,group1:r.grouping.group1,group2:r.grouping.group2,
    test_used:r.result.test_used,t:r.result.t,df:r.result.df,p:r.result.p,ci_lo:r.result.ci_lo,ci_hi:r.result.ci_hi,
    effect_type:r.effect.type,effect_value:r.effect.value,diff:r.difference.diff,plain:r.reporting.plain,follow_up_question:r.follow_up_question};
  fetch('/api/mm/results-to-explain.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(obj)})
    .then(r=>r.json()).then(j=>{ if(j.ok){ tt.added=true; $("#ttResults").innerHTML=renderTTestResults(tt.result); toast('Added to Results to Explain'); } else toast(j.error||'Could not add.'); })
    .catch(()=>toast('Request failed.'));
}
/* ===== One-Way ANOVA (real data via /api/mm/anova.php) — panel mirrors the t-test ===== */
const an={grouping:null,outcome:null,result:null,tab:'desc',busy:false,added:false};
function anGroupings(){return ((BOOT.ttvars&&BOOT.ttvars.groupings)||[]).filter(g=>g.groups&&g.groups.length>=3);}
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
function renderChiSquare(s){
  const V=BOOT.ttvars||{datasetReady:false,outcomes:[],groupings:[]};
  const C=(V.groupings||[]);
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
      <div class="dx-l-t" style="margin-top:10px;font-size:12px;opacity:.7">Percentages are within each row. Columns: ${esc(ct.col_var)}.</div>`;
  } else if(csq.tab==='result'){
    const R=r.result;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Test</th><th>χ²</th><th>df</th><th>N</th><th>p</th><th class="l">Result</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.row.name)} × ${esc(r.col.name)}</td><td class="dx-interp">${esc(R.test_used)}</td><td>${fmt(R.chi2)}</td><td>${fmt(R.df,0)}</td><td>${R.n_total}</td><td>${esc(R.p_str)}</td><td class="dx-interp"><span class="tt-status ${R.significant?'ok':'rev'}">${R.significant?'Significant':'Not significant'}</span></td></tr></tbody></table></div>`;
  } else if(csq.tab==='effect'){
    const E=r.effect;
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead>
      <tbody><tr><td class="dx-name">${esc(r.row.name)} × ${esc(r.col.name)}</td><td class="dx-interp">${esc(E.type)}</td><td>${fmt(E.value,3)}</td><td class="dx-interp">${esc(E.interpretation)}</td><td class="dx-interp">${esc(E.meaning)}</td></tr></tbody></table></div>`;
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
const reg={outcome:null,preds:{},result:null,tab:'coef',busy:false};
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
  // default: select the first available predictor if none chosen
  const avail=O.filter(o=>o.id!==reg.outcome);
  if(!Object.keys(reg.preds).some(k=>reg.preds[k]&&avail.find(o=>o.id===+k))) { if(avail[0]) reg.preds[avail[0].id]=true; }
  const outSel=O.map(o=>`<option value="${o.id}" ${o.id===reg.outcome?'selected':''}>${esc(o.name)}</option>`).join('');
  const chks=avail.map(o=>`<label class="rg-chk" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line,#e3e5e9);border-radius:8px;font-size:13px;cursor:pointer"><input type="checkbox" ${reg.preds[o.id]?'checked':''} onchange="regTogglePred(${o.id},this.checked)"> ${esc(o.name)}</label>`).join(' ');
  const setup=`
    <div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome, then check one or more predictors</div></div></div>
      <div class="panel-b">
        <div class="field" style="max-width:340px"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="regOut" onchange="reg.outcome=+this.value;renderRegression(activeStep())">${outSel}</select></div>
        <div class="field" style="margin-top:12px"><label>Predictors <span class="tt-hint">numeric</span></label><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">${chks||'<span class="tt-hint">No other numeric variables available.</span>'}</div></div>
        <div class="run-actions"><button class="btn primary" onclick="runRegression()" ${reg.busy?'disabled':''}>${reg.busy?'Running…':'▷ Run regression'}</button></div>
      </div></div>`;
  $("#centerInner").innerHTML=`
    <div class="ws-header"><div class="eyebrow">Prediction <span class="strand-chip quan">QUAN</span></div>
      <h1 class="title">Linear Regression</h1><p class="lede">Predict a numeric outcome from one or more numeric predictors.</p></div>
    ${helpBar('Regression')}${setup}<div id="regResults">${reg.result?renderRegressionResults(reg.result):''}</div>`;
}
function regTogglePred(id,on){ if(on)reg.preds[id]=true; else delete reg.preds[id]; }
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
    body=`<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Term</th><th>b</th><th>SE</th><th>t</th><th>p</th><th class="l">Result</th></tr></thead><tbody>${rows}</tbody></table></div>`;
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
    </div>`;
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
function dqNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
 'Quantitative outcome':{type:'numeric',strand:'Quantitative',uses:'Descriptives, correlations, group comparisons'},
 'Likert item':{type:'likert',strand:'Quantitative',uses:'Means, reliability, t-test, ANOVA'},
 'Scale item':{type:'likert',strand:'Quantitative',uses:'Means, reliability, scale construction'},
 'Qualitative response':{type:'open',strand:'Qualitative',uses:'Theme discovery, quote analysis'},
 'Open-ended explanation':{type:'open',strand:'Qualitative',uses:'Explanation mapping, quotes'},
 'Exclude from analysis':{type:'ignore',strand:'Excluded',uses:'—'}
};
const DM_ROLE_ORDER=Object.keys(DM_ROLE);
const DM_CONSTRUCTS=['—','AI Readiness','AI Trust','AI Equity Concern','AI Teacher Support','AI Risk Concern','Custom Construct'];
const DM_QUAL_PURPOSE=['Explain quantitative pattern','Expand quantitative result','Identify emerging issue','Provide quote evidence','Capture concerns','Capture recommendations','Other'];
function dmFetch(payload){return fetch('/api/mm/data-map.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},payload||{}))}).then(r=>r.json());}
function dmRole(c){const e=dm.edits[c.idx];return (e&&e.role)||c.assigned_role;}
function dmConstruct(c){const e=dm.edits[c.idx];return (e&&e.construct!=null)?e.construct:(c.construct||'');}
function dmBadge(t){const ok=(t==='Ready'||t==='Strong'||t==='Yes');return `<span class="tt-status ${ok?'ok':'rev'}">${esc(t)}</span>`;}
function dmTab(t){dm.tab=t;renderDataMap(activeStep());}
function dmSetRole(idx,val){dm.edits[idx]=Object.assign({},dm.edits[idx],{role:val});renderDataMap(activeStep());}
function dmSetConstruct(idx,val){dm.edits[idx]=Object.assign({},dm.edits[idx],{construct:val==='—'?'':val});renderDataMap(activeStep());}
function dmInclude(idx,on,role){dmSetRole(idx,on?role:'Exclude from analysis');}
function dmStage(idx,key,val){dm.edits[idx]=Object.assign({},dm.edits[idx],{[key]:val});if(key!=='focus')renderDataMap(activeStep());}
function dmRoleSelect(idx,cur){return `<select class="ed-in dm-sel" onchange="dmSetRole(${idx},this.value)">`+DM_ROLE_ORDER.map(r=>`<option ${r===cur?'selected':''}>${esc(r)}</option>`).join('')+`</select>`;}
function dmConstructSelect(idx,cur){const c=cur||'—';return `<select class="ed-in dm-sel" onchange="dmSetConstruct(${idx},this.value)">`+DM_CONSTRUCTS.map(o=>`<option ${o===c?'selected':''}>${esc(o)}</option>`).join('')+`</select>`;}
function dmPurposeSelect(idx,cur){return `<select class="ed-in dm-sel" onchange="dmStage(${idx},'purpose',this.value)">`+DM_QUAL_PURPOSE.map(o=>`<option ${o===cur?'selected':''}>${esc(o)}</option>`).join('')+`</select>`;}
function dmFocusInput(idx,cur){return `<input class="ed-in dm-sel" style="min-width:190px" value="${esc(cur)}" placeholder="What this question asks" oninput="dmStage(${idx},'focus',this.value)">`;}
function dmBandSelect(idx){const e=dm.edits[idx]||{};const cur=e.band||'Keep as numeric';return `<div style="margin-top:6px"><select class="ed-in dm-sel" onchange="dmStage(${idx},'band',this.value)">`+['Keep as numeric','Create age bands','Exclude from grouping'].map(o=>`<option ${o===cur?'selected':''}>${esc(o)}</option>`).join('')+`</select></div>`;}
function dmCheck(idx,on,role){return `<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--ink-2)"><input type="checkbox" ${on?'checked':''} onchange="dmInclude(${idx},this.checked,'${role.replace(/'/g,"\\'")}')"> ${on?'Yes':'No'}</label>`;}
function dmPanel(title,thead,rows){return `<div class="panel"><div class="panel-h"><div><h3>${title}</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead>${thead}</thead><tbody>${rows}</tbody></table></div></div></div>`;}
function dmSaveBar(){const dirty=Object.keys(dm.edits).length>0;return `<div class="dm-save"><button class="btn primary" ${dm.saving?'disabled':''} onclick="dmSave()">${dm.saving?'Saving…':'Save variable roles'}</button><span class="dm-note">${dirty?'You have unsaved changes.':'Confirmed roles are saved to your dataset for later analysis.'}</span></div>`;}
function dmHead(s){return `<div class="ws-header"><div class="eyebrow">Data map · organize before you analyze</div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function dmNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue to Data Quality →</button></div>`;}
function dmMsg(s,msg){$("#centerInner").innerHTML=dmHead(s)+helpBar('data_map')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+dmNav();}
function dmSave(){
  // Persist only the columns the user actually touched, so we never clobber
  // existing column_meta (e.g. an RSSI-tagged dataset) for untouched variables.
  const idxs=Object.keys(dm.edits);
  if(!idxs.length){toast('No changes to save');return;}
  const save=idxs.map(k=>{const c=dm.base.columns.find(x=>String(x.idx)===String(k));if(!c)return null;const m=DM_ROLE[dmRole(c)]||{};return {idx:c.idx,type:m.type||'ignore',construct:dmConstruct(c)};}).filter(Boolean);
  if(!save.length){toast('No changes to save');return;}
  dm.saving=true;renderDataMap(activeStep());
  dmFetch({save}).then(j=>{dm.saving=false;if(j&&j.ok){dm.base=j;dm.edits={};toast('Variable roles saved');}else{toast((j&&(j.message||j.error))||'Could not save roles.');}renderDataMap(activeStep());}).catch(()=>{dm.saving=false;toast('Save failed.');renderDataMap(activeStep());});
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
      return `<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${esc(c.format||c.detected_type)}</td><td class="dx-interp">${esc(cons||'—')}</td><td>${dmConstructSelect(c.idx,cons)}</td><td class="dx-interp">${esc((DM_ROLE[dmRole(c)]||{}).uses||'Means, reliability, t-test, ANOVA')}</td><td>${dmCheck(c.idx,inc,def)}</td></tr>`;}).join('');
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
function renderDataMap(s){
  if(!(BOOT.projectId&&BOOT.rawinfo&&BOOT.rawinfo.linked)){
    $("#centerInner").innerHTML=dmHead(s)+helpBar('data_map')+`<p class="lede">Connect a project with uploaded data to map your variables into analysis roles.</p>`+dmNav();
    return;
  }
  if(dm.err){dmMsg(s,dm.err);return;}
  if(!dm.base){
    if(!dm.busy){dm.busy=true;dmFetch({}).then(j=>{dm.busy=false;if(j&&j.ok){dm.base=j;}else{dm.err=(j&&(j.message||j.error))||'Could not build the data map.';}renderDataMap(activeStep());}).catch(()=>{dm.busy=false;dm.err='Could not load your data.';renderDataMap(activeStep());});}
    dmMsg(s,'Reading your dataset and detecting variable roles…');return;
  }
  const d=dm.base,su=d.summary;
  const cards=[
    ['Respondents',su.respondents,false],
    ['ID Variable',su.id_variable,true],
    ['Demographics',su.demographics,false],
    ['Quantitative Variables',su.quantitative,false],
    ['Likert Items',su.likert,false],
    ['Open-Ended Responses',su.open_ended,false],
  ].map(c=>`<div class="dm-card"><div class="dm-card-k">${esc(c[0])}</div><div class="dm-card-v ${c[2]?'sm':''}">${esc(String(c[1]))}</div></div>`).join('')
   +`<div class="dm-card"><div class="dm-card-k">Integration Strength</div><div class="dm-card-v sm">${dmBadge(su.integration_strength)}</div></div>`;
  const tabs=DM_TABS.map(t=>`<button class="dm-tab ${dm.tab===t[0]?'on':''}" onclick="dmTab('${t[0]}')">${t[1]}</button>`).join('');
  $("#centerInner").innerHTML=dmHead(s)+helpBar('data_map')+`<div class="dm-cards">${cards}</div><div class="dm-tabs">${tabs}</div>${dmTabBody(d)}`+dmNav();
}
/* ============ Trustworthiness (l_trust) — qualitative credibility ============
   Three real practices via /api/mm/trustworthiness.php, chosen from the palette:
   Audit trail (derived project events), Member checking (a saved log), Coding
   agreement (Cohen's/Fleiss' kappa from the coders' codes). Studio pattern only:
   panel + dx-table + tt-status badges + ov-score number + dx-layers prose. */
const tr={base:null,busy:false,err:'',saving:false,member:[]};
function trFetch(payload){return fetch('/api/mm/trustworthiness.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({project_id:BOOT.projectId},payload||{}))}).then(r=>r.json());}
function trHead(s){return `<div class="ws-header"><div class="eyebrow">Trustworthiness · qualitative credibility <span class="strand-chip qual">QUAL</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function trNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
const th={base:null,busy:false,err:'',building:false,coding:false,sel:null,quotes:null,qbusy:false};
function thFetch(){return fetch('/api/mm/codebook.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json());}
function thQuotesFetch(cid){return fetch('/api/mm/coded-responses.php?project_id='+BOOT.projectId+'&category_id='+cid+'&limit=8',{credentials:'same-origin'}).then(r=>r.json());}
function thBuildReq(){return fetch('/api/mm/build.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,mode:'auto'})}).then(r=>r.json());}
function thCodeReq(){return fetch('/api/mm/code-existing.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId})}).then(r=>r.json());}
function thCode(){if(th.coding)return;th.coding=true;renderThemes(activeStep());thCodeReq().then(j=>{th.coding=false;if(j&&j.ok){th.base=null;th.sel=null;th.quotes=null;toast('Tagged '+(j.coded_rows||0)+' responses to themes');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not tag responses.');renderThemes(activeStep());}}).catch(()=>{th.coding=false;toast('Tagging failed.');renderThemes(activeStep());});}
function thHead(s){return `<div class="ws-header"><div class="eyebrow">Qualitative themes · what the responses mean <span class="strand-chip qual">QUAL</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function thNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
function thMsg(s,msg){$("#centerInner").innerHTML=thHead(s)+helpBar('l_themes')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+thNav();}
function thConf(c){return `<span class="tt-status ${c==='high'?'ok':'rev'}">${esc(c||'—')}</span>`;}
function thSent(mix){mix=mix||{};const p=mix.positive||0,g=mix.negative||0,n=(mix.neutral||0)+(mix.mixed||0);const parts=[];if(p)parts.push('<b class="pos">'+p+' +</b>');if(g)parts.push('<b class="neg">'+g+' −</b>');if(n)parts.push('<b class="neu">'+n+' ·</b>');return parts.length?parts.join(' '):'—';}
function thSelect(cid){if(th.sel===cid){th.sel=null;th.quotes=null;renderThemes(activeStep());return;}th.sel=cid;th.quotes=null;th.qbusy=true;renderThemes(activeStep());thQuotesFetch(cid).then(j=>{th.qbusy=false;th.quotes=(j&&j.ok)?j:null;renderThemes(activeStep());}).catch(()=>{th.qbusy=false;th.quotes=null;renderThemes(activeStep());});}
function thBuild(){if(th.building)return;toast('Working with ReliCheck Intelligence…');th.building=true;renderThemes(activeStep());thBuildReq().then(j=>{th.building=false;if(j&&j.ok){th.base=null;toast('Themes discovered');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not discover themes.');renderThemes(activeStep());}}).catch(()=>{th.building=false;toast('Discovery failed or timed out.');renderThemes(activeStep());});}
function thAddReq(name){return fetch('/api/mm/categories.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',project_id:BOOT.projectId,name:name})}).then(r=>r.json());}
function thAdd(){const el=document.getElementById('thNewName');const n=el?el.value.trim():'';if(!n){toast('Enter a theme name');return;}thAddReq(n).then(j=>{if(j&&j.ok){th.base=null;toast('Theme added');renderThemes(activeStep());}else{toast((j&&(j.message||j.error))||'Could not add theme.');}}).catch(()=>toast('Add failed.'));}
function thQuotesPanel(){
  if(th.sel==null)return '';
  if(th.qbusy)return `<div class="th-quotes" style="padding:16px 18px">Loading quotes…</div>`;
  const q=th.quotes;
  if(!q||!q.responses||!q.responses.length)return `<div class="th-quotes" style="padding:16px 18px">No coded quotes for this theme yet.</div>`;
  const rows=q.responses.map(r=>`<div class="th-quote"><div class="th-quote-t">“${esc(r.text)}”</div><div class="th-quote-m">${r.respondent_ref?'<span>'+esc(r.respondent_ref)+'</span>':''}${r.group_value?'<span>· '+esc(r.group_value)+'</span>':''}${r.sentiment?'<span>· '+esc(r.sentiment)+'</span>':''}${r.quote_worthy?'<span>· ★ quote-worthy</span>':''}</div></div>`).join('');
  return `<div class="ov-sec" style="margin-top:2px">Example quotes · ${esc(q.category?q.category.name:'')} (${q.total} coded)</div><div class="th-quotes">${rows}</div>`;
}
function renderThemes(s){
  if(!(BOOT.projectId&&BOOT.projectId>0)){$("#centerInner").innerHTML=thHead(s)+helpBar('l_themes')+`<p class="lede">Connect a project with open-ended responses to discover and review themes.</p>`+thNav();return;}
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
  const rows=themes.map(t=>{const pct=t.percent||0;const sel=t.category_id===th.sel;
    return `<tr${sel?' class="dm-fit-sel"':''}><td class="dx-name">${esc(t.name)}${t.description?`<div class="th-sent" style="font-weight:600;margin-top:3px;white-space:normal">${esc(t.description)}</div>`:''}</td><td><div class="th-cov">${t.coded_count||0} <span style="color:var(--ink-3)">(${pct}%)</span></div><div class="th-bar"><i style="width:${Math.min(100,pct)}%"></i></div></td><td class="th-sent">${thSent(t.sentiment_mix)}</td><td>${thConf(t.confidence)}</td><td><button class="btn" style="padding:5px 11px" onclick="thSelect(${t.category_id})">${sel?'Hide quotes':'View quotes'}</button></td></tr>`;}).join('');
  const table=`<div class="panel"><div class="panel-h"><div><h3>Themes</h3></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Theme</th><th class="l">Coverage</th><th class="l">Sentiment</th><th class="l">Confidence</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></div></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">Each theme is a pattern of meaning found across your open-ended responses. Coverage is how many responses were tagged with it; sentiment shows the balance of positive, negative, and neutral tone. Open a theme to read the participant quotes behind it.</div></div></div>`;
  const allZero=themes.every(t=>(t.coded_count||0)===0);
  const codeBar=`<div class="dm-save"><button class="btn primary" ${th.coding?'disabled':''} onclick="thCode()">${th.coding?'Tagging responses…':(allZero?'Tag responses to themes':'Re-tag responses')}</button><span class="dm-note">${allZero?'Your themes are not tagged to any responses yet — tag them to fill in coverage and quotes.':'Keyword-based tagging against the current themes.'}</span></div>`;
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
function bkNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
function bkMsg(s,msg){$("#centerInner").innerHTML=bkHead(s)+helpBar('l_book')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+bkNav();}
function bkField(label,id,val,rows){return `<label class="ed-l">${label}</label>`+(rows?`<textarea id="${id}" class="ed-in" rows="${rows}">${esc(val)}</textarea>`:`<input id="${id}" class="ed-in" value="${esc(val)}">`);}
function bkEvidencePanel(){
  if(bk.ebusy)return `<div class="th-quotes" style="padding:16px 18px">Loading evidence…</div>`;
  const q=bk.evidence;
  if(!q||!q.responses||!q.responses.length)return `<div class="th-quotes" style="padding:16px 18px">No coded evidence for this code yet. Tag responses on the Qualitative Themes step first.</div>`;
  const rows=q.responses.slice(0,12).map(r=>`<div class="th-quote"><div class="th-quote-t">“${esc(r.text)}”</div><div class="th-quote-m">${r.respondent_ref?'<span>'+esc(r.respondent_ref)+'</span>':''}${r.group_value?'<span>· '+esc(r.group_value)+'</span>':''}${r.sentiment?'<span>· '+esc(r.sentiment)+'</span>':''}</div></div>`).join('');
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
function jdNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
function jdMsg(s,msg){$("#centerInner").innerHTML=jdHead(s)+helpBar('joint')+`<div class="work-surface" style="border-radius:16px">${esc(msg)}</div>`+jdNav();}
function jdAnalysis(a){if(!a||!a.test)return '—';const pp=a.predictor?esc(a.predictor):'';const oo=a.outcome?esc(a.outcome):'';const pair=(pp||oo)?` (${pp}${pp&&oo?' → ':''}${oo})`:'';return esc(a.test)+pair;}
function jdEvidenceFetch(cid){return fetch('/api/mm/codebook-evidence.php?project_id='+BOOT.projectId+'&category_id='+cid,{credentials:'same-origin'}).then(r=>r.json());}
function jdAllFetch(){return fetch('/api/mm/responses.php?project_id='+BOOT.projectId+'&limit=60',{credentials:'same-origin'}).then(r=>r.json());}
function jdChoose(themeId){if(jd.choosing===themeId){jd.choosing=null;jd.candidates=null;jd.candFallback=false;renderJoint(activeStep());return;}jd.choosing=themeId;jd.candidates=null;jd.candFallback=false;jd.cbusy=true;renderJoint(activeStep());jdEvidenceFetch(themeId).then(j=>{const coded=(j&&j.ok)?(j.responses||[]):[];if(coded.length){jd.cbusy=false;jd.candidates=coded;jd.candFallback=false;renderJoint(activeStep());}else{jdAllFetch().then(a=>{jd.cbusy=false;jd.candidates=(a&&a.ok)?(a.rows||[]):[];jd.candFallback=true;renderJoint(activeStep());}).catch(()=>{jd.cbusy=false;jd.candidates=[];jd.candFallback=true;renderJoint(activeStep());});}}).catch(()=>{jd.cbusy=false;jd.candidates=null;renderJoint(activeStep());});}
function jdSetQuote(themeId,responseId){fetch('/api/mm/joint-display.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'set_quote',theme_id:themeId,response_id:responseId})}).then(r=>r.json()).then(j=>{if(j&&j.ok){jd.base=null;jd.choosing=null;jd.candidates=null;toast('Quote set');renderJoint(activeStep());}else{toast((j&&(j.message||j.error))||'Could not set quote.');}}).catch(()=>toast('Failed.'));}
function jdCandidatePanel(){if(jd.choosing==null)return '';const t=((jd.base&&jd.base.rows)||[]).find(r=>r.theme_id===jd.choosing);const name=t?t.theme_name:'';if(jd.cbusy)return `<div class="th-quotes" style="padding:16px 18px">Loading responses…</div>`;const c=jd.candidates;if(!c||!c.length)return `<div class="th-quotes" style="padding:16px 18px">No open-ended responses found for this project.</div>`;const note=jd.candFallback?`<div class="dm-note" style="margin:0 0 8px">No responses are tagged to “${esc(name)}” yet — pick any response below to feature it as this theme's quote.</div>`:'';const rows=c.slice(0,15).map(r=>{const rid=r.response_id||r.id;return `<div class="th-quote"><div class="th-quote-t">“${esc(r.text)}”</div><div class="th-quote-m">${r.respondent_ref?'<span>'+esc(r.respondent_ref)+'</span>':''}${r.group_value?'<span>· '+esc(r.group_value)+'</span>':''}${r.sentiment?'<span>· '+esc(r.sentiment)+'</span>':''} <button class="btn" style="padding:2px 9px" onclick="jdSetQuote(${jd.choosing},${rid})">Use this quote</button></div></div>`;}).join('');return `<div class="ov-sec" style="margin-top:2px">Choose a quote for ${esc(name)}</div>${note}<div class="th-quotes">${rows}</div>`;}
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
  const body=rows.map(r=>{const f=r.frequency||{};const sp=(r.sentiment&&r.sentiment.percent)||{};
    return `<tr><td class="dx-name">${esc(r.theme_name)}</td><td><div class="th-cov">${f.n||0} <span style="color:var(--ink-3)">(${f.percent||0}%)</span></div></td><td class="dx-interp">${jdAnalysis(r.analysis)}</td><td class="th-sent">${thSent({positive:sp.positive,negative:sp.negative,neutral:(sp.neutral||0)+(sp.mixed||0)})}</td><td class="dx-interp">${r.quote&&r.quote.text?'“'+esc(r.quote.text)+'”':'<span style="color:var(--ink-3)">— no quote yet —</span>'}<div style="margin-top:5px"><button class="btn" style="padding:3px 9px" onclick="jdChoose(${r.theme_id})">${jd.choosing===r.theme_id?'Close':'Choose quote'}</button></div></td></tr>`;}).join('');
  const table=`<div class="panel"><div class="panel-h"><div><h3>Joint display · ${rows.length} themes</h3><div class="ph-sub">${d.total_responses||0} open-ended responses</div></div></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Theme</th><th class="l">Frequency (QUAN)</th><th class="l">Statistical result (QUAN)</th><th class="l">Sentiment (QUAL)</th><th class="l">Representative quote (QUAL)</th></tr></thead><tbody>${body}</tbody></table></div></div></div>`;
  const bar=`<div class="dm-save"><button class="btn" ${jd.picking?'disabled':''} onclick="jdPickAll()">${jd.picking?'Picking quotes…':'✦ Pick all with ReliCheck Intelligence'}</button><span class="dm-note">Choose a quote per theme yourself (above), or let ReliCheck Intelligence pick them all at once.</span></div>`;
  const layers=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this shows</div><div class="dx-l-t">Each row is one theme seen through both strands at once: how common and how strong it is in the numbers, and the tone and a real quote from the narratives. Rows where the number and the quote agree are convergence; rows where they disagree are what you explain next.</div></div></div>`;
  $("#centerInner").innerHTML=jdHead(s)+helpBar('joint')+bar+table+jdCandidatePanel()+layers+jdNav();
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
function q2Nav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
function mtNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
function cvSuggest(){if(cv.aibusy)return;toast('Working with ReliCheck Intelligence…');cvCapture();cv.aibusy=true;renderConverge(activeStep());fetch('/api/mm/alignment.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId})}).then(r=>r.json()).then(j=>{cv.aibusy=false;cv.ai=(j&&j.ok)?j:null;if(!(j&&j.ok))toast((j&&(j.message||j.error))||'Not enough quant-linked data to analyze.');renderConverge(activeStep());}).catch(()=>{cv.aibusy=false;toast('Analysis failed.');renderConverge(activeStep());});}
function cvHead(s){return `<div class="ws-header"><div class="eyebrow">Convergence & divergence · where the strands meet <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function cvNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
      <div class="ov-row" style="border:none;padding:6px 0"><div class="ov-k">Qualitative</div><div class="ov-v"><span class="th-sent">${thSent({positive:sp.positive,negative:sp.negative,neutral:(sp.neutral||0)+(sp.mixed||0)})}</span>${r.quote&&r.quote.text?' · “'+esc(r.quote.text.slice(0,120))+'”':''}</div></div>
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
function miNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
function ipNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
      <div class="dm-note" style="margin:6px 0 8px">${f.n||0} (${f.percent||0}%) · ${thSent({positive:sp.positive,negative:sp.negative,neutral:(sp.neutral||0)+(sp.mixed||0)})} · ${jdAnalysis(r.analysis)}${r.quote&&r.quote.text?' · “'+esc(r.quote.text.slice(0,100))+'”':''}</div>
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
function sgNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
function rpRowByKey(){const m={};((rp.base&&rp.base.rows)||[]).forEach(r=>m[r.section_key]=r);return m;}
function rpVal(key,row){return (rp.edits[key]!=null)?rp.edits[key]:((row&&row.body_text)||'');}
function rpCapture(){((rp.base&&rp.base.sections)||[]).forEach(sec=>{const el=document.getElementById('rp_'+sec.key);if(el)rp.edits[sec.key]=el.value;});}
function rpSave(key){rpCapture();const text=rp.edits[key]||'';rp.saving=key;renderReport(activeStep());fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'save_section',section_key:key,body_text:text})}).then(r=>r.json()).then(j=>{rp.saving='';if(j&&j.ok){toast('Section saved');}else{toast((j&&(j.message||j.error))||'Could not save.');}renderReport(activeStep());}).catch(()=>{rp.saving='';toast('Save failed.');renderReport(activeStep());});}
function rpGenerate(key,isAi){if(rp.gen)return;rpCapture();rp.gen=key;if(isAi)toast('Working with ReliCheck Intelligence…');renderReport(activeStep());fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'generate_section',section_key:key})}).then(r=>r.json()).then(j=>{rp.gen='';if(j&&j.ok){rp.base=null;delete rp.edits[key];toast('Section generated');}else{toast((j&&(j.message||j.error))||'Could not generate.');}renderReport(activeStep());}).catch(()=>{rp.gen='';toast('Generate failed.');renderReport(activeStep());});}
function rpGenerateAll(){if(rp.genAll)return;rpCapture();rp.genAll=true;toast('Building the report…');renderReport(activeStep());fetch('/api/mm/report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:BOOT.projectId,action:'generate_all'})}).then(r=>r.json()).then(j=>{rp.genAll=false;if(j&&j.ok){rp.base=null;rp.edits={};toast('Report assembled');}else{toast((j&&(j.message||j.error))||'Could not build the report.');}renderReport(activeStep());}).catch(()=>{rp.genAll=false;toast('Build failed or timed out.');renderReport(activeStep());});}
function rpHead(s){return `<div class="ws-header"><div class="eyebrow">Report builder · assemble the write-up <span class="strand-chip both">MIXED</span></div><h1 class="title">${esc(s.title)}</h1><p class="lede">${esc(s.lede)}</p></div>`;}
function rpNav(){return `<div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;}
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
  const cards=secs.map(sec=>{const row=byKey[sec.key];const isAi=sec.source==='ai';const sv=rp.saving===sec.key;const gn=rp.gen===sec.key;const srcBadge=isAi?'<span class="tt-status rev">ReliCheck Intelligence</span>':'<span class="tt-status ok">Template</span>';const userBadge=(row&&row.source==='user')?' <span class="tt-status ok">Edited</span>':'';
    return `<div class="panel"><div class="panel-b">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><div style="font-size:15px;font-weight:700">${esc(sec.title)}</div><div>${srcBadge}${userBadge}</div></div>
      <textarea id="rp_${sec.key}" class="ed-in" rows="5" style="margin-top:8px" placeholder="${esc(sec.title)} — build it from your analysis, or write it yourself.">${esc(rpVal(sec.key,row))}</textarea>
      <div class="dm-save"><button class="btn primary" ${sv?'disabled':''} onclick="rpSave('${sec.key}')">${sv?'Saving…':'Save section'}</button><button class="btn" ${gn?'disabled':''} onclick="rpGenerate('${sec.key}',${isAi})">${gn?(isAi?'Generating…':'Refreshing…'):(isAi?'✦ Generate with ReliCheck Intelligence':'Refresh from data')}</button></div>
    </div></div>`;}).join('');
  const exportBar=`<div class="panel"><div class="panel-h"><div><h3>Download your report</h3><div class="ph-sub">Includes your edits; build or save the sections first</div></div></div><div class="panel-b"><div class="dm-save" style="margin:0"><a class="btn primary" href="/api/mm/report-docx.php?project_id=${BOOT.projectId}">⬇ Download Word (.docx)</a><a class="btn" href="/api/mm/report-export.php?project_id=${BOOT.projectId}&format=md">⬇ Download Markdown</a></div></div></div>`;
  $("#centerInner").innerHTML=rpHead(s)+helpBar('report')+note+bulkBar+cards+exportBar+rpNav();
}
function renderCenter(){
  const s=activeStep(); const tool=currentTool(s);
  if(s.mode==='start'){ return renderStart(s); }
  if(s.mode==='overview'){ return renderOverview(s); }
  if(s.mode==='datamap'){ return renderDataMap(s); }
  if(s.mode==='quality'){ return renderQuality(s); }
  if(s.id==='l_trust'){ return renderTrust(s); }
  if(s.id==='l_themes'){ return renderThemes(s); }
  if(s.id==='l_book'){ return renderBook(s); }
  if(s.id==='q_build'){ const tb=(currentTool(s)||{}).name||''; return (tb==='T-Test'||tb==='Effect Sizes')?renderMeasureTest(s):renderReliability(s); }
  if(s.id==='joint'){ return renderJoint(s); }
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
    <div class="footer-nav"><button class="btn" onclick="stepBy(-1)">← Back</button><button class="btn primary" onclick="stepBy(1)">Continue →</button></div>`;
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
function renderCompanion(){
  const d=DESIGNS[state.design]; const s=activeStep(); const tool=currentTool(s);
  $("#compTabs").innerHTML=["explain","notes","intelligence"].map(t=>`<div class="comp-tab ${state.compTab===t?'active':''}" onclick="setCompTab('${t}')">${t==="intelligence"?"Intelligence":t[0].toUpperCase()+t.slice(1)}</div>`).join("");
  const b=$("#compBody");
  if(state.compTab==="notes"){b.innerHTML=`<div class="comp-block"><div class="cb-k"><span class="i">✎</span> Notes for this step</div><textarea class="notes-area" placeholder="Jot decisions for ${esc(s.title)}…" oninput="state.notes[state.stepId]=this.value">${esc(state.notes[state.stepId]||"")}</textarea></div>`;return;}
  if(state.compTab==="intelligence"){const here=tool?tool.name:s.title;
    b.innerHTML=`<div class="comp-block"><div class="cb-k" style="color:var(--accent-ink)"><span class="i">✦</span> ReliCheck Intelligence</div><div class="ai-prompt">Ask about <b>${esc(here)}</b>, or pick a suggestion.</div><div class="ai-suggest"><button class="ai-chip" onclick="aiAnswer('plain')">Explain this step in plain language</button><button class="ai-chip" onclick="aiAnswer('write')">Draft a sentence for my report</button><button class="ai-chip" onclick="aiAnswer('next')">What should I do next?</button></div><div id="aiOut"></div></div>`;return;}
  const ctx=s.pivot?`<b>${esc(s.title)}.</b> ${esc(s.lede)}`:`You are on <b>${esc(s.title)}</b>, ${s.strand==='neutral'?'a shared step':'the '+s.strand.toUpperCase()+' strand'}.`;
  const lead=toolCoachBlock(helpKey(s));
  b.innerHTML=lead+`<div class="comp-block comp-why"><div class="cb-k">✦ Why this order</div><div class="cb-t">${d.why}</div></div>
    <div class="comp-block"><div class="cb-k"><span class="i">i</span> On this screen</div><div class="cb-t">${ctx}</div></div>
    <div class="comp-block"><div class="cb-k"><span class="i">⚖</span> Both strands, fairly</div><div class="cb-t">Quantitative gets <b>Instrument Quality</b>; qualitative gets <b>Trustworthiness</b>. Each strand carries its own credibility check.</div></div>`;
}
function aiAnswer(kind){const s=activeStep();const name=(currentTool(s)||{}).name||s.title;
  let txt=kind==="plain"?`${name}: ${s.lede}`:kind==="write"?`This step contributes to the ${DESIGNS[state.design].short} report; run it, then save the result to your findings.`:s.pivot?`Carry the selected items into the next phase, then build the joint display.`:`Save this to your report, then continue to the next step.`;
  $("#aiOut").innerHTML=`<div class="ai-answer">${esc(txt)}</div>`;}
function esc(s){return (s==null?"":String(s)).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
function alignPalette(){const panel=document.querySelector('.center .panel');const pal=$("#palette");const body=document.querySelector('.body');
  if(!panel||!pal||!body)return;pal.style.marginTop='0px';
  const off=panel.getBoundingClientRect().top-body.getBoundingClientRect().top;pal.style.marginTop=Math.max(0,Math.round(off))+'px';}
function render(){renderSwitch();renderRail();renderCenter();renderPalette();renderCompanion();alignPalette();}

/* persist the design choice on the project (Phase 2 wiring; no-op in demo) */
function persistDesign(coreSlug){
  if(!BOOT.canPersist)return;
  // wizard.php validates the A–E slugs, so persist the representative A–E for this core design.
  const aeSlug=(MM.core_to_ae||{})[coreSlug]||coreSlug;
  fetch('/api/mm/wizard.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({project_id:BOOT.projectId,step:'design_choice',value:aeSlug})})
   .catch(()=>{});
}
function setDesign(k){state.design=k;state.stepId=firstLeadStep();state.toolSel=null;render();$(".center").scrollTop=0;persistDesign(k);toast("Design → "+DESIGNS[k].short);}
function go(u){window.location.href=u;}
function dockIntake(kind){const m={siri:'Open from SIRI responses',saved:'Open saved project',open:'Open a project',upload:'Upload data',sample:'Try a sample'};toast((m[kind]||'Data intake')+' — opens the shared intake.');}
function toggleDesignMenu(e){if(e)e.stopPropagation();$("#designPick").classList.toggle('open');}
function pickDesign(k){$("#designPick").classList.remove('open');if(k!==state.design)setDesign(k);}
document.addEventListener('click',e=>{const p=$("#designPick");if(p&&!p.contains(e.target))p.classList.remove('open');});
function goStep(id){state.stepId=id;state.toolSel=null;render();$(".center").scrollTop=0;}
function stepBy(dir){const s=steps();const i=s.findIndex(x=>x.id===activeStep().id);const ni=Math.max(0,Math.min(s.length-1,i+dir));state.stepId=s[ni].id;state.toolSel=null;if(dir>0&&ni+1>state.completedThrough)state.completedThrough=ni;render();$(".center").scrollTop=0;}
function selPal(name){state.toolSel=name;renderCenter();renderPalette();renderCompanion();$(".center").scrollTop=0;alignPalette();toast("Loaded: "+name);}
function setCompTab(t){state.compTab=t;renderCompanion();}
function toggleCompanion(){document.body.classList.toggle('companion-collapsed');}
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

state.stepId='start';   // users come straight in to the Start overview (like SIRI)
render();
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
