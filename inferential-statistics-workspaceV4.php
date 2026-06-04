<?php
// inferential-statistics-workspaceV4.php — Inferential Statistics Studio.
//
// Wears the MM / Qual v4 CHROME (the 4-row CSS grid: RC header / 76px topbar /
// sidebar+main / footer, the horizontal top step rail, the left sidebar notes,
// the slide-in ReliCheck Coach, and the Report drawer — copied from
// qual-studio-workspaceV4.php) but runs the INFERENTIAL BRAIN (the data flow,
// step renderers, DataMap, Save-to-report, and AnalysisStudio.renderWork path
// ported from _analysis_studio_v4_shell.php).
//
// Accent is inferential BLUE (Qual's forest green swapped out). The studio works
// on real data exactly like _analysis_studio_v4_shell.php does for inferential:
// dataset from /api/analysis/dataset.php, DatasetUpload projectType:'analysis',
// save-to-report via /api/analysis/results.php. No statistics are reimplemented
// here — work steps render through window.AnalysisStudio.renderWork.
//
// This file is NEW and self-contained. It does not touch any other file.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/inferential-statistics-workspaceV4.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// ─── PROJECT LOAD ────────────────────────────────────────────────────────────
// ?project_id=N is an analysis_projects.id (kind='inferential'). Its dataset is
// loaded client-side from /api/analysis/dataset.php. _analysis_studio.php is
// prod-resident; guard its include + the schema call so this page still renders
// where the helper is not present (project simply resolves to 0 / demo).
$projectId    = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectTitle = '';
if ($projectId > 0) {
    if (is_file(__DIR__ . '/api/_analysis_studio.php')) require_once __DIR__ . '/api/_analysis_studio.php';
    try {
        $pdo = db();
        if (function_exists('analysis_ensure_schema')) analysis_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT title FROM analysis_projects WHERE id = :id AND user_id = :uid AND kind = 'inferential' AND status <> 'archived'");
        $stmt->execute([':id' => $projectId, ':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $projectTitle = (string)$row['title']; } else { $projectId = 0; }
    } catch (Throwable $e) { $projectId = 0; }
}

// ─── PIPELINE CONFIG ─────────────────────────────────────────────────────────
$pipelines = require __DIR__ . '/_analysis_pipelines.php';
$pipeline  = $pipelines['inferential'] ?? [];

// Honor ?step= deep links (e.g. the upload widget redirects with &step=datamap).
$validStepIds = array_column($pipeline, 'id');
$initialStep  = (isset($_GET['step']) && in_array($_GET['step'], $validStepIds, true)) ? (string)$_GET['step'] : null;

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$projLabel = $projectTitle !== '' ? $projectTitle : 'Inferential Analysis';

$BOOT = [
    'slug'         => 'inferential',
    'name'         => 'Inferential Statistics Studio',
    'projectId'    => $projectId,
    'projectLabel' => $projLabel,
    'projectLive'  => $projectId > 0,
    'canPersist'   => $projectId > 0,
    'projectsUrl'  => '/studio-is.php',
    'pipeline'     => array_values($pipeline),
    'initialStep'  => $initialStep,
];

function _isv4(string $path): string { $f = __DIR__ . $path; return is_file($f) ? (string)filemtime($f) : (string)time(); }

if (!headers_sent()) {
    header('Cache-Control: no-store, must-revalidate');
    header('Pragma: no-cache');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Inferential Statistics Studio · <?= htmlspecialchars($projLabel) ?> — ReliCheck</title>
<link rel="icon" href="/logo-brand.svg">
<style>
/* =====================================================
   MM Studio 2026 — Approved prototype design
   (chrome copied from qual-studio-workspaceV4.php; accent swapped to
    inferential blue: Qual's green RGB rgba(30,92,58,...) → rgba(29,78,216,...))
   ===================================================== */
:root{
  /* Primary accent — inferential blue */
  --indigo:#1d4ed8; --indigo-dark:#1741b6; --indigo-light:#eff2ff;
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
.tb-step.active .tb-node{background:var(--indigo);color:#fff;border-color:transparent;box-shadow:0 1px 6px rgba(29,78,216,.4);width:28px;height:28px;}
.tb-node-label{position:absolute;top:calc(100% + 5px);left:50%;transform:translateX(-50%);font-size:10px;font-weight:600;color:var(--indigo);white-space:nowrap;letter-spacing:-.1px;pointer-events:none;}
.tb-connector{width:36px;height:1.5px;background:var(--border);flex-shrink:0;transition:background .15s;}
.tb-connector.done{background:var(--green);opacity:.4;}
/* Topbar action buttons */
.tb-act{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:500;padding:7px 14px;border-radius:999px;cursor:pointer;white-space:nowrap;transition:all .13s;}
.tb-act svg{width:13px;height:13px;flex-shrink:0;}
.tb-act-save{background:transparent;border:1px solid var(--border);color:var(--text-2);}
.tb-act-save:hover{background:var(--bg);color:var(--text);}
.tb-act-save.saved{color:var(--green);border-color:rgba(52,199,89,.3);background:var(--green-soft);}
.tb-act-rpt{background:var(--indigo);border:1px solid transparent;color:#fff;box-shadow:0 1px 4px rgba(29,78,216,.3);}
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
.ds-btn.active{background:var(--indigo);color:#fff;font-weight:600;box-shadow:0 1px 3px rgba(29,78,216,.35);}
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
.form-select:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(29,78,216,.1);}
/* ── Buttons ── */
.btn-row,.run-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn-primary{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(29,78,216,.3);}
.btn-primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(29,78,216,.4);transform:translateY(-1px);}
.btn-primary:active{transform:translateY(0);} .btn-primary svg{width:14px;height:14px;opacity:.85;}
.btn-secondary{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn-secondary:hover{color:var(--text);background:rgba(0,0,0,.03);}
.btn{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn:hover{color:var(--text);background:rgba(0,0,0,.03);border-color:rgba(0,0,0,.14);}
.btn.primary{background:var(--indigo);border-color:transparent;color:#fff;box-shadow:0 1px 4px rgba(29,78,216,.3);}
.btn.primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(29,78,216,.4);}
/* ── Step nav ── */
.footer-nav,.step-nav{display:flex;align-items:center;justify-content:space-between;padding-top:24px;}
.step-nav-prev{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--text-3);background:none;border:none;cursor:pointer;padding:8px 0;font-family:inherit;transition:color .12s;}
.step-nav-prev:hover{color:var(--text-2);} .step-nav-prev svg{width:14px;height:14px;}
.step-nav-next{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(29,78,216,.3);}
.step-nav-next:hover{background:var(--indigo-dark);transform:translateY(-1px);box-shadow:0 2px 8px rgba(29,78,216,.4);} .step-nav-next svg{width:14px;height:14px;}
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
.btn-str{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--indigo);background:var(--indigo-light);border:1px solid rgba(29,78,216,.15);border-radius:999px;padding:7px 13px;cursor:pointer;transition:all .13s;}
.btn-str:hover{background:rgba(29,78,216,.14);} .btn-str svg{width:12px;height:12px;}
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
.btn-export,.btn-export-report{width:100%;display:flex;align-items:center;justify-content:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:999px;cursor:pointer;transition:background .13s;box-shadow:0 1px 4px rgba(29,78,216,.3);}
.btn-export:hover,.btn-export-report:hover{background:var(--indigo-dark);}
.btn-export svg,.btn-export-report svg{width:14px;height:14px;flex-shrink:0;}
/* ── Coach pull tab ── */
.coach-tab-btn,.coach-tab{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:63;display:flex;align-items:center;justify-content:center;width:22px;height:110px;background:var(--surface);border:1px solid var(--border);border-right:none;border-radius:8px 0 0 8px;cursor:pointer;box-shadow:-2px 0 8px rgba(0,0,0,.06);transition:width .15s,background .15s,right .26s cubic-bezier(.32,.72,0,1);}
.coach-tab-btn:hover,.coach-tab:hover{width:26px;background:var(--indigo-light);}
.coach-tab-lbl,.coach-tab-label{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3);user-select:none;white-space:nowrap;transition:color .15s;}
/* when the panel is open the tab rides its left edge so it never gets covered */
body.coach-open .coach-tab-btn,body.coach-open .coach-tab{right:var(--companion);background:var(--indigo-light);border-color:rgba(29,78,216,.2);}
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
.comp-tab.active,.comp-tab.on{background:var(--indigo-light);color:var(--indigo);}
.comp-body{padding:16px;overflow-y:auto;flex:1;}
.comp-block{margin-bottom:16px;}
.cb-k{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:7px;color:var(--text-3);}
.cb-k .i{width:16px;height:16px;border-radius:5px;display:grid;place-items:center;font-size:10px;color:#fff;background:var(--indigo);}
.cb-t{font-size:13px;line-height:1.55;color:var(--text-2);} .cb-t b{color:var(--text);font-weight:700;}
.comp-why{background:var(--indigo-light);border:1px solid rgba(29,78,216,.2);border-radius:12px;padding:13px 14px;}
.comp-why .cb-k{color:var(--indigo);} .comp-why .cb-t{color:var(--indigo);}
.notes-area{width:100%;min-height:200px;border:1px solid var(--border);border-radius:12px;padding:12px;font-family:inherit;font-size:13px;resize:vertical;color:var(--text);outline:none;}
.notes-area:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(29,78,216,.08);}
.ai-prompt{border:1px solid var(--border);border-radius:12px;padding:12px;font-size:13px;color:var(--text-3);background:var(--bg);margin-bottom:12px;}
.ai-suggest{display:flex;flex-direction:column;gap:8px;}
.ai-chip{text-align:left;border:1px solid var(--border);background:var(--surface);border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600;color:var(--text);cursor:pointer;}
.ai-chip:hover{border-color:var(--indigo);background:var(--indigo-light);color:var(--indigo);}
.ai-answer{border:1px solid rgba(29,78,216,.22);background:var(--indigo-light);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--text);margin-top:12px;}
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
.coach-prompt:hover{background:var(--indigo-light);color:var(--indigo);border-color:rgba(29,78,216,.15);}
.coach-prompt.active{background:var(--indigo-light);color:var(--indigo);border-color:rgba(29,78,216,.15);font-weight:600;}
.coach-answer{background:var(--indigo-light);border-radius:12px;padding:13px 15px;font-size:13px;line-height:1.65;color:var(--text);margin-top:10px;display:none;}
.coach-answer.visible{display:block;}
.comp-foot{padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0;}
.coach-input-row{display:flex;gap:8px;align-items:center;}
.coach-input{flex:1;font-family:inherit;font-size:13px;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;outline:none;transition:border-color .15s;}
.coach-input:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(29,78,216,.08);}
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
.dx-q{background:rgba(29,78,216,.05);border:1px solid rgba(29,78,216,.12);border-radius:12px;padding:13px 15px;}
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
.answer{display:flex;gap:11px;align-items:flex-start;padding:12px 15px;border-radius:13px;background:var(--indigo-light);border:1px solid rgba(29,78,216,.2);margin-bottom:16px;}
.answer .a-ico{width:24px;height:24px;border-radius:7px;flex:none;display:grid;place-items:center;background:var(--indigo);color:#fff;font-size:13px;}
.answer .a-text{font-size:14px;line-height:1.55;color:var(--text);}
.work-surface{border:1.5px dashed var(--border);border-radius:13px;background:var(--bg);padding:24px;color:var(--text-2);font-size:13.5px;line-height:1.6;}
.phase-banner{display:flex;gap:9px;align-items:center;padding:9px 14px;background:var(--indigo-light);border:1px solid rgba(29,78,216,.2);border-radius:11px;font-size:12px;font-weight:600;color:var(--indigo);}
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
.proj-select:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(29,78,216,.08);}
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
.ed-in:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(29,78,216,.08);}
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
.tt-tab.on{background:var(--indigo-light);color:var(--indigo);border-color:rgba(29,78,216,.2);}
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
.dm-tab.on{background:var(--indigo-light);color:var(--indigo);border-color:rgba(29,78,216,.2);}
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
/* ── One purple-button family — all match the Study Design switcher's flat indigo
   treatment (same fill, subtle shadow, indigo-dark hover, no glow/lift). ── */
.btn.primary,.btn-primary,.step-nav-next,.tb-act-rpt,.btn-export,.btn-export-report,.coach-send{
  background:var(--indigo)!important;color:#fff!important;border-color:transparent!important;
  box-shadow:0 1px 3px rgba(29,78,216,.35)!important;font-weight:600;}
.btn.primary:hover,.btn-primary:hover,.step-nav-next:hover,.tb-act-rpt:hover,.btn-export:hover,.btn-export-report:hover,.coach-send:hover{
  background:var(--indigo-dark)!important;box-shadow:0 1px 3px rgba(29,78,216,.35)!important;transform:none!important;}
/* soft purple (Save-to-report chip) keeps the tinted style but the same indigo */
.btn-str{background:var(--indigo-light)!important;color:var(--indigo)!important;border-color:rgba(29,78,216,.15)!important;box-shadow:none!important;}
/* ── Variable Map (DataMap component) — compact so all columns fit at once. ── */
#centerInner .rdm-tbl{font-size:12px;}
#centerInner .rdm-tbl th{padding:7px 8px;font-size:10px;}
#centerInner .rdm-tbl td{padding:6px 8px;}
#centerInner .rdm-vname,#centerInner .rdm-vlabel{max-width:118px;}
#centerInner .rdm-vlabel{font-size:10.5px;}
#centerInner .rdm-chip{font-size:10.5px;padding:2px 6px;}
#centerInner .rdm-sel{min-width:102px;max-width:148px;font-size:11.5px;padding:4px 6px;}
#centerInner .rdm-con{width:116px;font-size:11.5px;padding:4px 6px;}
#centerInner .rdm-analyses{max-width:148px;font-size:10.5px;line-height:1.45;}
#centerInner .rdm-tog{font-size:11px;}
/* Workstation canvas: one solid white (panels keep white fill + hairline border). */
.main,.center{background:var(--surface);}
/* ── View toggle (Table / Graph), from the analysis shell ── */
.view-bar{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin:-6px 0 14px;}
.view-bar:empty{display:none;}
.vb-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);}
.seg{display:inline-flex;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:3px;gap:3px;box-sizing:border-box;}
.seg button{border:none;background:none;font-family:inherit;font-size:13px;font-weight:600;color:var(--text-2);padding:7px 16px;border-radius:8px;cursor:pointer;}
.seg button.on{background:#fff;color:var(--indigo);box-shadow:0 1px 3px rgba(0,0,0,.08);}
/* ── Save to report + Report step (from the analysis shell) ── */
.save-bar{display:flex;align-items:center;gap:14px;margin-top:18px;padding:14px 16px;border:1px solid var(--border);border-radius:14px;background:var(--surface);box-shadow:var(--shadow-sm);}
.save-note{flex:1;font-size:13.5px;color:var(--text-2);}
.rep-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.rep-block{border:1px solid var(--border);border-radius:14px;background:var(--surface);box-shadow:var(--shadow-sm);margin-bottom:16px;overflow:hidden;}
.rep-block-h{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-2);}
.rep-del{border:none;background:none;color:#c2492f;font-weight:600;font-size:12.5px;cursor:pointer;font-family:inherit;}
.rep-snap{padding:16px;}
.placeholder{padding:40px;text-align:center;color:var(--text-3);border:1.5px dashed var(--border);border-radius:14px;}
/* ── ReliCheck Intelligence panel (Coach › Intelligence tab) ── */
.intel-head{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);margin-bottom:10px;}
.intel-head .intel-i{color:var(--indigo);}
.intel-prompt{font-size:13px;color:var(--text-2);line-height:1.55;margin-bottom:12px;}
.intel-prompt strong{color:var(--text);}
.intel-sug{display:block;width:100%;text-align:left;border:1px solid var(--border);background:var(--surface);border-radius:10px;padding:10px 12px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--text);cursor:pointer;margin-bottom:8px;}
.intel-sug:hover{border-color:var(--indigo);background:var(--indigo-light);color:var(--indigo);}
.intel-out{border:1px solid rgba(29,78,216,.22);background:var(--indigo-light);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--text);margin-top:12px;}
@media print{
  #studioHeader,.topbar,.sidebar,.companion,.coach-tab,.rpt-drawer,.rpt-scrim,#studioFooter,.view-bar,.save-bar,.rep-actions,.rep-del{display:none!important;}
  .app{display:block!important;height:auto!important;}
  .main,.center{overflow:visible!important;padding:0!important;}
  .dx-scroll,.as-chart-wrap{max-height:none!important;overflow:visible!important;}
}
/* ── Decision point (step 2) — mode chooser, mirrors the Descriptive studio ── */
.dp-card{position:relative;display:block;width:100%;text-align:left;border:none;border-radius:20px;padding:28px 30px;overflow:hidden;cursor:pointer;color:#fff;font-family:inherit;box-shadow:0 6px 22px rgba(20,28,45,.10);transition:transform .14s,box-shadow .14s;}
.dp-card:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(20,28,45,.18);}
.dp-self{display:flex;align-items:center;gap:22px;margin-bottom:20px;min-height:138px;background:linear-gradient(120deg,#2554d8 0%,#3f6ae8 55%,#5b6cf0 100%);}
.dp-self .dp-ico{width:56px;height:56px;border-radius:15px;background:rgba(255,255,255,.18);display:grid;place-items:center;flex:none;}
.dp-self .dp-ico svg{width:24px;height:24px;color:#fff;}
.dp-body{display:flex;flex-direction:column;min-width:0;}
.dp-eyebrow{display:block;font-size:12px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;opacity:.72;margin-bottom:7px;}
.dp-title{display:block;font-family:var(--font-display);font-size:25px;font-weight:800;letter-spacing:-.3px;margin-bottom:8px;}
.dp-desc{display:block;font-size:14.5px;line-height:1.55;opacity:.92;max-width:64ch;}
.dp-num{position:absolute;top:4px;right:24px;font-family:var(--font-display);font-size:104px;font-weight:800;line-height:1;opacity:.14;pointer-events:none;}
.dp-arrow{position:absolute;top:50%;right:30px;transform:translateY(-50%);font-size:24px;opacity:.85;}
.dp-divider{display:flex;align-items:center;gap:14px;margin:22px 0;color:var(--text-3);font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;}
.dp-divider::before,.dp-divider::after{content:"";flex:1;height:1px;background:var(--border);}
.dp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
@media(max-width:780px){.dp-grid2{grid-template-columns:1fr;}}
.dp-auto{min-height:206px;background:linear-gradient(125deg,#e6a017 0%,#df7c1a 100%);}
.dp-report{min-height:206px;background:linear-gradient(125deg,#1f9460 0%,#147a4c 100%);}
.dp-auto .dp-title,.dp-report .dp-title{margin-top:12px;}
.dp-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.22);padding:4px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;}
.dp-go{display:inline-block;margin-top:16px;font-size:14px;font-weight:800;}
.dp-nav{display:flex;align-items:center;justify-content:space-between;margin-top:26px;}
/* ── Mode step (shared with DA) ── */
.mode-hero{margin-bottom:26px;}
.mode-hero-title{font-size:38px;font-weight:800;letter-spacing:-.03em;color:var(--text);line-height:1.08;margin:0 0 8px;}
.mode-hero-sub{font-size:15px;color:var(--text-2);margin:0;line-height:1.5;}
.mode-card-main{display:flex;gap:20px;align-items:center;padding:24px 28px;border-radius:16px;cursor:pointer;transition:all .2s;margin-bottom:24px;text-align:left;width:100%;border:none;font-family:inherit;background:linear-gradient(135deg,#b8d8f0 0%,#d4e8f7 50%,#c5dff5 100%);color:var(--text);position:relative;overflow:hidden;}
.mode-card-main::after{content:"01";position:absolute;right:36px;top:50%;transform:translateY(-50%);font-size:120px;font-weight:900;color:rgba(255,255,255,.08);line-height:1;pointer-events:none;letter-spacing:-.04em;}
.mode-card-main:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(59,91,219,.35);}
.mode-card-main .mc-ico{width:48px;height:48px;border-radius:12px;background:rgba(10,111,232,.25);display:grid;place-items:center;font-size:24px;flex:none;color:#0A6FE8;}
.mode-card-main .mc-body{flex:1;min-width:0;}
.mode-card-main .mc-title{font-size:22px;font-weight:800;letter-spacing:-.02em;margin:0 0 8px;color:var(--text);}
.mode-card-main .mc-desc{font-size:15px;color:rgba(255,255,255,.9);line-height:1.6;margin:0;max-width:60ch;}
.mode-card-main .mc-arrow{font-size:18px;color:#0A6FE8;transition:all .15s;margin-left:8px;}
.mode-card-main:hover .mc-arrow{transform:translateX(3px);}
.mode-ai-lbl{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-3);margin:0 0 14px;display:flex;align-items:center;gap:8px;}
.mode-ai-lbl::before,.mode-ai-lbl::after{content:'';flex:1;height:1px;background:var(--border);}
.mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:8px;}
@media(max-width:620px){.mode-grid{grid-template-columns:1fr;}}
.mode-card-ai{padding:22px 20px;border-radius:14px;cursor:pointer;transition:all .2s;text-align:left;border:none;font-family:inherit;position:relative;overflow:hidden;display:flex;flex-direction:column;}
.mode-card-ai::after{display:none;}
.mode-card-ai.amber{background:linear-gradient(135deg,#f5ddb8 0%,#f9e8d1 50%,#f7e0c0 100%);color:var(--text);}
.mode-card-ai.emerald{background:linear-gradient(135deg,#b8ead9 0%,#d4f5e8 50%,#c5f0df 100%);color:var(--text);}
.mode-card-ai:not(:disabled):hover{transform:translateY(-2px);}
.mode-card-ai.amber:not(:disabled):hover{box-shadow:0 8px 24px rgba(245,158,11,.12);}
.mode-card-ai.emerald:not(:disabled):hover{box-shadow:0 8px 24px rgba(16,185,129,.12);}
.mode-card-ai:disabled{opacity:.45;cursor:default;}
.mode-card-ai .mc-ico{width:44px;height:44px;border-radius:10px;display:grid;place-items:center;font-size:22px;margin-bottom:12px;color:var(--text);}
.mode-card-ai.amber .mc-ico{background:rgba(245,158,11,.2);color:#d97706;}
.mode-card-ai.emerald .mc-ico{background:rgba(16,185,129,.2);color:#059669;}
.mode-card-ai .mc-title{font-size:16px;font-weight:700;letter-spacing:-.01em;margin:0 0 6px;color:var(--text);}
.mode-card-ai .mc-desc{font-size:13px;color:var(--text-2);line-height:1.5;margin:0;}
.mode-card-ai .mc-cta{margin-top:10px;font-size:12.5px;font-weight:700;display:flex;align-items:center;gap:4px;transition:gap .15s;}
.mode-card-ai.amber .mc-cta{color:#d97706;}
.mode-card-ai.emerald .mc-cta{color:#059669;}
.mode-card-ai:hover .mc-cta{gap:7px;}
/* ── Decision boxes (Mode step) — Descriptive studio's option-card design, sized compact ── */
.option-card{position:relative;overflow:hidden;width:100%;text-align:left;font-family:inherit;border-radius:20px;padding:26px 30px;border:1px solid rgba(0,0,0,.06);box-shadow:0 12px 36px rgba(0,0,0,.06);cursor:pointer;transition:transform .18s,box-shadow .18s;display:block;}
.option-card:hover{transform:translateY(-3px);box-shadow:0 20px 52px rgba(0,0,0,.10);}
.option-card.self{margin-bottom:6px;background:radial-gradient(circle at 90% 90%,rgba(66,133,244,.18),transparent 42%),linear-gradient(135deg,#fff 0%,#f6f9ff 48%,#eaf2ff 100%);}
.option-card.auto{background:radial-gradient(circle at 90% 90%,rgba(238,137,20,.18),transparent 42%),linear-gradient(135deg,#fff 0%,#fff8ef 48%,#ffeacc 100%);}
.option-card.report{background:radial-gradient(circle at 90% 90%,rgba(31,141,79,.15),transparent 42%),linear-gradient(135deg,#fff 0%,#f4fbf7 48%,#e1f5e9 100%);}
.option-card[disabled]{opacity:.5;cursor:default;box-shadow:none;}
.option-card[disabled]:hover{transform:none;box-shadow:0 12px 36px rgba(0,0,0,.06);}
.option-icon{width:52px;height:52px;border-radius:15px;display:grid;place-items:center;background:rgba(255,255,255,.62);border:1px solid rgba(255,255,255,.7);box-shadow:0 10px 24px rgba(0,0,0,.07);font-size:23px;flex-shrink:0;}
.option-number-bg{position:absolute;right:28px;top:12px;font-family:var(--font-display);font-size:96px;line-height:1;font-weight:700;letter-spacing:-.08em;opacity:.09;color:#111827;pointer-events:none;}
.option-step{font-size:13px;font-weight:700;letter-spacing:.02em;margin-bottom:6px;}
.option-title{font-size:26px;line-height:1.05;font-weight:800;letter-spacing:-.03em;color:#111827;margin:0 0 8px;}
.option-copy{max-width:520px;font-size:14.5px;line-height:1.45;color:#5f6368;margin:0 0 12px;}
.option-cta{font-size:14px;font-weight:700;text-decoration:none;}
.option-card.self .option-step,.option-card.self .option-cta{color:#3267e3;}
.option-card.auto .option-step,.option-card.auto .option-cta{color:#e88412;}
.option-card.report .option-step,.option-card.report .option-cta{color:#228552;}
</style>
<link rel="stylesheet" href="/apps/analysis-studio/analysis-studio.css?v=<?= _isv4('/apps/analysis-studio/analysis-studio.css') ?>">
<script src="/apps/studio/studio-header.js?v=<?= _isv4('/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= _isv4('/apps/studio/studio-footer.js') ?>"></script>
<script src="/apps/studio/type-taxonomy.js?v=<?= _isv4('/apps/studio/type-taxonomy.js') ?>"></script>
<script src="/apps/studio/data-map.js?v=<?= _isv4('/apps/studio/data-map.js') ?>"></script>
<script src="/apps/analysis-studio/analysis-studio.js?v=<?= _isv4('/apps/analysis-studio/analysis-studio.js') ?>"></script>
<script src="/apps/studio/dataset-upload.js?v=<?= _isv4('/apps/studio/dataset-upload.js') ?>"></script>
</head>
<body>
<div class="app">

  <!-- RC global header (studio-header.js, row 1) -->
  <div id="studioHeader"></div>

  <!-- Studio topbar (row 2, full width) -->
  <header class="topbar">
    <div class="topbar-logo">Inferential Statistics Studio</div>
    <div class="topbar-steps" id="topbarSteps"></div>
    <div class="topbar-right">
      <button class="tb-act tb-act-rpt" onclick="goReport()">
        <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="white" stroke-width="1.4"/><line x1="5" y1="6" x2="11" y2="6" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="8.5" x2="11" y2="8.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="11" x2="8" y2="11" stroke="white" stroke-width="1.4" stroke-linecap="round"/></svg>
        Report <span class="rpt-count-badge" id="rptCountBadge">0</span>
      </button>
      <div class="topbar-project">
        <strong><?= htmlspecialchars($projLabel) ?></strong>
        <?php if($projectId === 0): ?><span class="proto-pill">Demo</span><?php else: ?>Live project<?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Sidebar (row 3, col 1): Researcher's Notes (mirrors MM's sidebar) -->
  <aside class="sidebar" id="studioSidebar">
    <div class="sidebar-header">
      <div class="sidebar-project-name" id="sbProjectName">Project</div>
      <div class="sidebar-design-desc" id="sbProjectLabel" style="margin-top:0"></div>
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
        </div>
      </div>
    </div>
  </aside>

  <!-- Main content (row 3, col 2) -->
  <main class="main center" id="mainContent">
    <div class="content-wrap center-inner" id="centerInner"></div>
  </main>

  <!-- RC studio footer (studio-footer.js, row 4) -->
  <div id="studioFooter"></div>
</div>

<!-- Coach pull tab -->
<button class="coach-tab" onclick="toggleCoach()" aria-label="ReliCheck Coach">
  <span class="coach-tab-label">Coach</span>
</button>

<!-- Coach panel (slide-in) -->
<aside class="companion" id="companion">
  <div class="comp-head">
    <div class="ch-ico"><svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="6" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M3 14c0-2.761 2.239-5 5-5s5 2.239 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
    <div><h3>ReliCheck Coach</h3><div class="ch-sub" id="coachStepLabel">Guidance</div></div>
    <button class="comp-toggle" onclick="toggleCoach()" title="Close">&#10005;</button>
  </div>
  <div class="comp-tabs">
    <button class="comp-tab active" data-tab="explain">Explain</button>
    <button class="comp-tab" data-tab="notes">Notes</button>
    <button class="comp-tab" data-tab="intelligence">Intelligence</button>
  </div>
  <div class="comp-body" id="compBody"></div>
</aside>

<!-- Report drawer (markup present; the report renders in the center via the Report step) -->
<div class="rpt-scrim" id="rptScrim" onclick="toggleRptDrawer()"></div>
<div class="rpt-drawer" id="rptDrawer">
  <div class="rpt-head">
    <h2>Report</h2>
    <span class="rpt-badge" id="rptBadgeDrawer">0 sections</span>
    <button class="rpt-close-btn" onclick="toggleRptDrawer()">&#10005;</button>
  </div>
  <div class="rpt-body" id="rptBody">
    <div class="rpt-empty" id="rptEmpty">
      <svg viewBox="0 0 40 40" fill="none"><rect x="6" y="4" width="28" height="32" rx="3" stroke="currentColor" stroke-width="2"/><line x1="12" y1="14" x2="28" y2="14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="20" x2="28" y2="20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="26" x2="20" y2="26" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <p>Open the Report step to assemble your saved analyses.</p>
    </div>
  </div>
  <div class="rpt-foot">
    <button class="btn-export" onclick="goReport()">
      <svg viewBox="0 0 16 16" fill="none"><path d="M3 10v3h10v-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><line x1="8" y1="2" x2="8" y2="10" stroke="white" stroke-width="1.5" stroke-linecap="round"/><polyline points="5,7 8,10 11,7" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Open Report step
    </button>
  </div>
</div>

<div class="modal-scrim" id="modalScrim"><div class="modal" id="modal"></div></div>
<div class="toast" id="toast"></div>

<script>
const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
(function(){
  'use strict';

  // ── Uniform studio header + footer (shared plug-ins) ──
  StudioHeader.init({
    logoSrc:      '/IS-Studio-long.png',
    logoAlt:      'Inferential Statistics Studio',
    projectLabel: BOOT.projectLabel,
    projectLive:  BOOT.projectId > 0,
    projectsUrl:  BOOT.projectsUrl,
    initials:     '<?= htmlspecialchars($initials) ?>'
  });
  StudioFooter.init();

  // ── State (analysis brain) ──
  const state = { stepId: (BOOT.initialStep || 'start'), compTab: 'explain', notes: {}, dataset: null,
                  view: 'table', datamapConfirmed: false, _datamapMounted: false, rssiProjectId: null,
                  autoMode: null };

  const CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  function steps(){ return BOOT.pipeline; }
  function activeStep(){ return steps().find(function(s){ return s.id===state.stepId; }) || steps()[0]; }
  function stepIndex(){ return steps().findIndex(function(s){ return s.id===state.stepId; }); }

  // ── CHROME: topbar step rail (replaces the analysis shell's renderRail) ──
  // Writes into #topbarSteps using Qual's .tb-step / .tb-node / .tb-connector /
  // .tb-node-label markup. Active = state.stepId; done = index < current index;
  // clicking a step sets state.stepId and re-renders.
  function renderTopbarSteps(){
    const el = document.getElementById('topbarSteps'); if (!el) return;
    const ss = steps(), idx = stepIndex(); let html = '';
    ss.forEach(function(s, i){
      const isDone = i < idx, isAct = s.id === state.stepId;
      const cls = isDone ? 'done' : (isAct ? 'active' : '');
      const inner = isDone ? '&#x2713;' : (i+1);
      html += '<div class="tb-step '+cls+'" data-step="'+esc(s.id)+'" title="'+esc(s.label)+'">'
        + '<div class="tb-node">'+inner+(isAct ? '<span class="tb-node-label">'+esc(s.label)+'</span>' : '')+'</div></div>';
      if (i < ss.length-1) html += '<div class="tb-connector '+(isDone?'done':'')+'"></div>';
    });
    el.innerHTML = html;
    el.querySelectorAll('.tb-step').forEach(function(b){
      b.addEventListener('click', function(){ state.stepId = b.getAttribute('data-step'); render(); });
    });
  }

  // ── Center dispatch (analysis brain) ──
  function renderCenter(){
    const host = document.getElementById('centerInner');
    const s = activeStep();
    if (s.mode==='start')    return renderStart(host);
    if (s.mode==='mode')     return renderMode(host, s);
    if (s.mode==='overview') return renderOverview(host);
    if (s.mode==='datamap')  return renderDataMap(host);
    if (s.mode==='report')   return renderReport(host, s);
    return renderWork(host, s);
  }

  // Start is ALWAYS the data hub: bring in new data or open a saved project.
  function renderStart(host){
    const has = !!state.dataset;
    let html = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+(has?'Your data':'New analysis')+'</div>'
      + '<h1 class="start-hero">Test what your data <span class="accent">can support.</span></h1>'
      + '<p class="lede">Comparisons, relationships, regression, effect sizes, and assumptions, with supported interpretation.</p></div>';

    if (has) {
      html += '<div class="begin-loaded"><span class="dot"></span><span class="bl-k">Loaded</span>'
        + '<span>' + esc(state.dataset.source || BOOT.projectLabel) + ' &middot; ' + (state.dataset.rowCount||0) + ' rows</span>'
        + '<button class="btn primary" style="margin-left:auto" id="stOverview">Go to Overview &rarr;</button></div>';
    }
    html += '<button class="begin-feature" id="stUpload"><span class="bc-ico">&#8681;</span>'
      + '<div><h4>Upload data</h4><p>Drop an Excel (.xlsx), CSV, or TSV file and tag your columns.</p>'
      + '<span class="bc-go">Upload data &rarr;</span></div></button>';
    html += '<div class="begin-sec">Or</div><div class="begin-grid2">'
      + '<button class="begin-card2" id="stSiri"><span class="bc-ico">&#9889;</span><h4>Open from SIRI responses</h4><p>Analyze a published survey’s collected responses.</p></button>'
      + '<button class="begin-card2" id="stProjects"><span class="bc-ico">&#9638;</span><h4>Open a saved project</h4><p>Your saved data, from any ReliCheck studio.</p></button>'
      + '</div>';
    host.innerHTML = html;
    const ov = document.getElementById('stOverview'); if (ov) ov.addEventListener('click', function(){ state.stepId='overview'; render(); });
    const u = document.getElementById('stUpload'); if (u) u.addEventListener('click', openUpload);
    const si = document.getElementById('stSiri'); if (si) si.addEventListener('click', openSiri);
    const pr = document.getElementById('stProjects'); if (pr) pr.addEventListener('click', openSaved);
  }

  // Mode step: choose analysis approach (Self / Auto / Report)
  function renderMode(host, s){
    const has = !!state.dataset;
    const rows = has ? (state.dataset.rowCount || 0) : 0;
    const src = has ? esc(state.dataset.source || BOOT.projectLabel) : '';
    host.innerHTML = (has ? '<div style="display:flex;align-items:center;gap:8px;padding:9px 16px;background:var(--bg);border:1px solid var(--border);border-radius:999px;font-size:13px;color:var(--text-2);width:fit-content;margin-bottom:22px;"><span style="width:8px;height:8px;border-radius:50%;background:#22c55e;flex:none"></span><b style="color:var(--text)">'+src+'</b><span>&middot;&nbsp;'+rows+' rows loaded</span></div>' : '')
      + '<button class="option-card self" id="mdSelf" style="display:flex;flex-direction:column;justify-content:flex-start;">'
      + '<div class="option-number-bg">01</div>'
      + '<div style="display:flex;gap:20px;align-items:flex-start;position:relative;z-index:1;">'
      + '<div class="option-icon">&#9776;</div>'
      + '<div style="flex:1;">'
      + '<div class="option-step">Your pace · full control</div>'
      + '<div class="option-title">Self analyze</div>'
      + '</div></div>'
      + '<div class="option-copy">Step through t-Tests, ANOVA, Correlation, Regression, and more at your own pace. You decide what runs and what goes in your report.</div>'
      + '</button>'
      + '<p style="text-align:center;font-size:14px;color:var(--text-3);margin:32px 0;font-weight:600;">+ Or let ReliCheck Intelligence do it</p>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:8px;">'
      + '<button class="option-card auto" id="mdAuto"' + (has ? '' : ' disabled style="opacity:.5;cursor:default;"') + '>'
      + '<div class="option-number-bg">02</div>'
      + '<div style="display:flex;gap:18px;align-items:flex-start;margin-bottom:24px;position:relative;z-index:1;">'
      + '<div class="option-icon">⚡</div>'
      + '<div style="flex:1;">'
      + '<div class="option-step" style="background:rgba(232, 132, 18, .15);padding:4px 10px;border-radius:999px;display:inline-block;">Instant Results</div>'
      + '</div></div>'
      + '<div class="option-title">Auto analyze</div>'
      + '<div class="option-copy">' + (has ? 'Every recommended test runs at once. Results appear instantly, ready to print or save as PDF.' : 'Load your data on Step 1 to unlock this.') + '</div>'
      + (has ? '<a class="option-cta">Open analyzer →</a>' : '') + '</button>'
      + '<button class="option-card report" id="mdReport"' + (has ? '' : ' disabled style="opacity:.5;cursor:default;"') + '>'
      + '<div class="option-number-bg">03</div>'
      + '<div style="display:flex;gap:18px;align-items:flex-start;margin-bottom:24px;position:relative;z-index:1;">'
      + '<div class="option-icon">✦</div>'
      + '<div style="flex:1;">'
      + '<div class="option-step" style="background:rgba(31, 141, 79, .15);padding:4px 10px;border-radius:999px;display:inline-block;">Ai Written</div>'
      + '</div></div>'
      + '<div class="option-title">Auto report</div>'
      + '<div class="option-copy">' + (has ? 'ReliCheck Intelligence writes a plain-language report from your data — ready to print or save as PDF.' : 'Load your data on Step 1 to unlock this.') + '</div>'
      + (has ? '<a class="option-cta">Generate report →</a>' : '') + '</button>'
      + '</div>';
    const openPop = function(mode){
      if (window.AnalysisStudio && window.AnalysisStudio.openAutoPopup) {
        window.AnalysisStudio.openAutoPopup({ dataset: state.dataset, mode: mode, projectId: BOOT.projectId, projectTitle: BOOT.projectLabel });
      }
    };
    const sf = document.getElementById('mdSelf');   if (sf) sf.addEventListener('click', function(){ state.stepId='datamap'; render(); });
    const au = document.getElementById('mdAuto');   if (au && has) au.addEventListener('click', function(){ openPop('auto'); });
    const rp = document.getElementById('mdReport'); if (rp && has) rp.addEventListener('click', function(){ openPop('report'); });
  }

  // Overview is the landing view once data is loaded.
  function renderOverview(host){
    const ds = state.dataset;
    if (!ds) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+'</div><h1 class="title">Overview</h1></div>'
        + '<div class="placeholder">No data yet. Go to <strong>Start</strong> to upload a file or open a saved project.</div>';
      return;
    }
    // Step 3 = overview of the data (the decision lives on the Mode step).
    const vars = ds.variables || [];
    const isNum = function(v){ return /likert|numeric/.test((v.types||[]).join(',').toLowerCase()); };
    const valid = function(v){ return (v.values||[]).filter(function(x){ return x!=='' && x!=null; }).length; };
    const stat = function(n,l){ return '<div><div style="font-size:26px;font-weight:700;line-height:1">'+n+'</div><div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:3px">'+l+'</div></div>'; };
    const rows = vars.map(function(v){ const n=valid(v), total=(v.values||[]).length;
      return '<tr><td class="dx-name">'+esc(v.name)+'</td><td class="l">'+esc((v.types||['—'])[0])+'</td><td>'+n+'</td><td>'+(total-n)+'</td></tr>'; }).join('');
    host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+' <span class="strand-chip quan">QUAN</span></div><h1 class="title">Overview</h1>'
      + '<p class="lede">What is in this dataset, before you analyze it.</p></div>'
      + (window.AnalysisStudio && window.AnalysisStudio.helpButton ? window.AnalysisStudio.helpButton('overview') : '')
      + '<div class="panel"><div class="panel-h"><h3>Dataset</h3></div><div class="panel-b">'
      + '<div style="display:flex;gap:34px;flex-wrap:wrap">' + stat(ds.rowCount||0,'Rows') + stat(vars.length,'Variables') + stat(vars.filter(isNum).length,'Numeric') + '</div>'
      + '<p style="margin:14px 0 0;color:var(--text-3);font-size:13px">Source: '+esc(ds.source || BOOT.projectLabel)+'</p></div></div>'
      + '<div class="panel"><div class="panel-h"><h3>Variables</h3></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
      + '<thead><tr><th class="l">Variable</th><th class="l">Type</th><th>Valid n</th><th>Missing</th></tr></thead><tbody>'+rows+'</tbody></table></div></div></div>'
      + '<div class="dp-nav"><button class="btn" id="ovBack">&larr; Mode</button><button class="btn primary" id="ovGo">Variable Map &rarr;</button></div>';
    const go = document.getElementById('ovGo'); if (go) go.addEventListener('click', function(){ state.stepId='datamap'; render(); });
    const bk = document.getElementById('ovBack'); if (bk) bk.addEventListener('click', function(){ state.stepId='mode'; render(); });
  }

  // Data Map step — shared DataMap component (apps/studio/data-map.js + type-taxonomy.js).
  function renderDataMap(host){
    if (!state.dataset) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+'</div>'
        + '<h1 class="title">Variable Map</h1></div>'
        + '<div class="placeholder">Load data first. Go to <strong>Start</strong> to upload a file.</div>';
      return;
    }
    if (!window.DataMap) {
      host.innerHTML = '<div class="placeholder">Variable Map is loading&hellip;</div>';
      return;
    }
    host.innerHTML = '';
    const container = document.createElement('div');
    host.appendChild(container);

    if (!state._datamapMounted) {
      state._datamapMounted = true;
      const rawVars = (state.dataset.variables || []).map(function(v, i){
        return {
          variable_name:       v.name,
          display_label:       v.name,
          detected_type:       (v.types || [])[0] || '',
          source:              'dataset_column',
          include_in_analysis: true,
          position:            i,
        };
      });
      DataMap.init({
        container:   container,
        projectId:   BOOT.projectId || 0,
        projectType: 'analysis',
        rawVars:     rawVars,
        constructs:  [],
        onConfirmed: function(){
          state.datamapConfirmed = true;
          const nx = steps().find(function(s){ return s.mode === 'work'; });
          if (nx){ state.stepId = nx.id; render(); }
        },
      });
    } else {
      DataMap.mount(container);
    }
  }

  function renderWork(host, s){
    if (!state.dataset) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+' <span class="strand-chip quan">QUAN</span></div>'
        + '<h1 class="title">'+esc(s.label)+'</h1></div>'
        + '<div class="placeholder">Load data to run <strong>'+esc(s.label)+'</strong>. Go to <strong>Start</strong> to upload a file.</div>';
      return;
    }
    if (!state.datamapConfirmed) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+' <span class="strand-chip quan">QUAN</span></div>'
        + '<h1 class="title">'+esc(s.label)+'</h1></div>'
        + '<div class="placeholder">Map your variables before running analysis. '
        + '<button class="btn primary" style="margin-left:8px" id="dmGate">Go to Variable Map &rarr;</button></div>';
      const gateBtn = document.getElementById('dmGate');
      if (gateBtn) gateBtn.addEventListener('click', function(){ state.stepId='datamap'; render(); });
      return;
    }
    // Inferential tools render through the shared presentation module
    // (MM-style dx-table + interpretation layers). The render function calls
    // onResult after results are drawn (sync or after fetch) so the Save bar
    // attaches to the final result.
    if (window.AnalysisStudio && window.AnalysisStudio.renderWork) {
      window.AnalysisStudio.renderWork(host, {
        kind: 'inferential', tool: s.tool, dataset: state.dataset, view: state.view,
        onResult: function() {
          const old = host.querySelector('.save-bar');
          if (old) old.remove();
          const snap = host.innerHTML;
          insertSaveBar(host, s, snap);
        }
      });
      return;
    }
    host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+' <span class="strand-chip quan">QUAN</span></div>'
      + '<h1 class="title">'+esc(s.label)+'</h1></div>'
      + '<div class="placeholder">Analysis engine is loading&hellip;</div>';
  }

  function toolLabel(tool){ const st = steps().find(function(x){ return x.tool===tool; }); return st ? st.label : tool; }
  function extractSummary(html, fallback){ const m = String(html).match(/dx-l-t[^>]*>([^<]+)</); return ((m && m[1]) ? m[1] : fallback).slice(0,200); }

  // "Save to report" — snapshot the current analysis to the project's report.
  function insertSaveBar(host, s, snapshotHtml){
    const bar = document.createElement('div'); bar.className = 'save-bar';
    if (!BOOT.projectId) {
      bar.innerHTML = '<span class="save-note">Save your data to a project (Upload, or Open saved project) to add analyses to a report.</span>';
      host.appendChild(bar); return;
    }
    bar.innerHTML = '<span class="save-note" id="saveNote">Add this analysis to your report.</span>'
      + '<button class="btn primary" id="saveBtn">Save to report</button>';
    host.appendChild(bar);
    const summary = extractSummary(snapshotHtml, s.label);
    bar.querySelector('#saveBtn').addEventListener('click', function(){
      const btn = this; btn.disabled = true; btn.textContent = 'Saving…';
      fetch('/api/analysis/results.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ project_id: BOOT.projectId, tool_key: s.tool, inputs:{ view: state.view }, result:{ html: snapshotHtml, label: s.label }, summary: summary }) })
        .then(function(r){ return r.json(); })
        .then(function(d){ if(!d||!d.ok) throw 0; btn.textContent='Saved ✓'; const n=document.getElementById('saveNote'); if(n) n.textContent='Saved to your report. Open the Report step to assemble it.'; })
        .catch(function(){ btn.disabled=false; btn.textContent='Save to report'; const n=document.getElementById('saveNote'); if(n) n.textContent='Could not save — please try again.'; });
    });
  }

  // Report step — renders in the CENTER via the shared engine.
  function renderReport(host){
    if (window.AnalysisStudio && window.AnalysisStudio.renderReport) {
      return window.AnalysisStudio.renderReport(host, BOOT);
    }
    host.innerHTML = '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(BOOT.name)+'</div><h1 class="title">Report</h1>'
      + '<p class="lede">Your saved analyses, assembled. Print or save as PDF.</p></div><div id="repBody"><div class="placeholder">Loading…</div></div>';
    const body = host.querySelector('#repBody');
    if (!BOOT.projectId) { body.innerHTML = '<div class="placeholder">Save your data to a project first, then click <strong>Save to report</strong> on any analysis step.</div>'; return; }
    fetch('/api/analysis/results.php?project_id=' + encodeURIComponent(BOOT.projectId), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){
        const items = (d && Array.isArray(d.results)) ? d.results : [];
        if (!items.length) { body.innerHTML = '<div class="placeholder">No saved analyses yet. Run a step and click <strong>Save to report</strong>.</div>'; return; }
        body.innerHTML = '<div class="rep-actions"><button class="btn primary" id="repPrint">Print / Save as PDF</button></div>'
          + items.map(function(it){
              return '<div class="rep-block"><div class="rep-block-h"><strong>'+esc(toolLabel(it.tool_key))+'</strong>'
                + '<button class="rep-del" data-id="'+it.id+'">Remove</button></div>'
                + '<div class="rep-snap">'+((it.result && it.result.html) || '<p class="save-note">'+esc(it.summary||'')+'</p>')+'</div></div>';
            }).join('');
        const pr = document.getElementById('repPrint'); if (pr) pr.addEventListener('click', function(){ window.print(); });
        body.querySelectorAll('.rep-del').forEach(function(b){
          b.addEventListener('click', function(){
            fetch('/api/analysis/results.php', { method:'DELETE', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: +b.getAttribute('data-id') }) })
              .then(function(){ render(); });
          });
        });
      })
      .catch(function(){ body.innerHTML = '<div class="placeholder">Could not load your report.</div>'; });
  }

  // ── Coach (slide-in companion): Explain / Notes / Intelligence ──
  function renderCompanion(){
    const body = document.getElementById('compBody');
    if (!body) return;
    const s = activeStep();
    const sub = document.getElementById('coachStepLabel'); if (sub) sub.textContent = esc(s.label) + ' guidance';

    if (state.compTab==='notes') {
      const v = state.notes[state.stepId] || '';
      body.innerHTML = '<textarea class="notes-area" id="noteBox" placeholder="Notes for this step…">'+esc(v)+'</textarea>';
      const t = document.getElementById('noteBox');
      if (t) t.addEventListener('input', function(){ state.notes[state.stepId]=t.value; });
      return;
    }

    const key = s.mode==='overview' ? 'overview' : (s.mode==='work' ? s.tool : null);

    if (state.compTab==='intelligence') {
      const label = esc(s.label);
      const can = key && window.AnalysisStudio && window.AnalysisStudio.intel;
      body.innerHTML = '<div class="intel-head"><span class="intel-i">&#10022;</span> ReliCheck Intelligence</div>'
        + '<div class="intel-prompt">Ask about <strong>'+label+'</strong>, or pick a suggestion.</div>'
        + '<button class="intel-sug" data-k="explain">Explain this step in plain language</button>'
        + '<button class="intel-sug" data-k="draft">Draft a sentence for my report</button>'
        + '<button class="intel-sug" data-k="next">What should I do next?</button>'
        + '<div class="intel-out" id="intelOut">This step adds to your project. Run it, then use <strong>Save to report</strong> to keep the result in your findings.</div>';
      if (can) body.querySelectorAll('.intel-sug').forEach(function(b){
        b.addEventListener('click', function(){ document.getElementById('intelOut').innerHTML = window.AnalysisStudio.intel(b.getAttribute('data-k'), key, s.label); });
      });
      return;
    }

    // Explain — rich per-step explanation (What it is / measures / when), like MM's Coach.
    if (key && window.AnalysisStudio && window.AnalysisStudio.coachExplain) {
      const html = window.AnalysisStudio.coachExplain(key);
      if (html) { body.innerHTML = html; return; }
    }
    body.innerHTML = '<div class="coach-section"><div class="coach-section-label">Guidance</div>'
      + '<div class="coach-tip"><strong>'+esc(s.label)+'</strong><br>'
      + (s.mode==='start' ? 'Pick a data source to begin. '+esc(BOOT.name)+' never computes reliability — that lives in RSSI.'
         : 'This step runs on your loaded dataset.')
      + '</div></div>';
  }

  // ── Data dock / intake ──
  const WORKSPACE_ROUTE = '/inferential-statistics-workspaceV4.php';
  function openUpload(){
    if (!window.DatasetUpload) return;
    window.DatasetUpload.open({ kind: 'inferential', projectType: 'analysis', projectId: BOOT.projectId, onLoaded: openProject });
  }
  function openSaved(){
    if (!window.DatasetUpload) return;
    window.DatasetUpload.openSaved({ kind: 'inferential', projectType: 'analysis', projectId: BOOT.projectId, onLoaded: openProject });
  }
  // Data was saved/linked to project `pid` → open it project-scoped so the
  // workspace loads it from the server (and it persists on reopen).
  function openProject(_dataset, pid){
    if (!pid) return;
    window.location.href = WORKSPACE_ROUTE + '?project_id=' + encodeURIComponent(pid);
  }
  function openSiri(){ alert('SIRI source picker — wired in the data-intake chunk.'); }

  // Most-recent localStorage dataset (engine shape), as a fallback when the
  // project has no server-saved data yet. Server data always wins.
  function findLocalDataset(){
    let best=null;
    try {
      for (let i=0;i<window.localStorage.length;i++){
        const k=window.localStorage.key(i);
        if(!k || k.indexOf('relicheck.dataset.')!==0) continue;
        const w=JSON.parse(window.localStorage.getItem(k));
        const dsv=w&&w.payload&&w.payload.dataset;
        if(dsv && (dsv.rowCount||0)>0 && (!best || (w.savedAt||0)>(best.savedAt||0))) best={savedAt:w.savedAt||0, ds:dsv};
      }
    } catch(e){}
    return best;
  }
  // opts.surveyProjectId: pass when data came from a SIRI survey project so the
  // RSSI stub can look up and display its post-data score in the topbar.
  function applyDataset(ds, opts){
    state.dataset = ds;
    showChip((ds.rowCount||0) + ' rows · ' + ((ds.variables||[]).length) + ' variables');
    if (opts && opts.surveyProjectId) {
      state.rssiProjectId = opts.surveyProjectId;
      if (StudioHeader.loadRssiStub) StudioHeader.loadRssiStub(opts.surveyProjectId);
    }
    // Once data is loaded, Overview becomes the landing view (Start stays the
    // data hub you can return to). Don't yank the user off a step they chose.
    if (state.stepId === 'start') state.stepId = 'overview';
    render();
  }

  let _loaded=false;
  function loadDataset(){
    if (_loaded) return; _loaded=true;
    function fallback(){ const local=findLocalDataset(); if(local) applyDataset(local.ds); else render(); }
    if (!BOOT.projectId){ fallback(); return; }
    fetch('/api/analysis/dataset.php?project_id=' + encodeURIComponent(BOOT.projectId), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){
        if (d && d.ok && d.has_data && d.dataset) applyDataset(d.dataset, d.survey_project_id ? { surveyProjectId: d.survey_project_id } : undefined);
        else fallback();
      })
      .catch(fallback);
  }
  function showChip(text){ if (window.StudioFooter && StudioFooter.showChip) StudioFooter.showChip(text); }

  // ── View toggle (Table / Graph): inserted right after the step header. ──
  function insertViewToggle(host){
    const hdr = host.querySelector('.ws-header'); if (!hdr) return;
    const bar = document.createElement('div');
    bar.className = 'view-bar';
    bar.innerHTML = '<span class="vb-lbl">View</span><div class="seg" id="viewSeg">'
      + '<button data-view="table" class="'+(state.view==='table'?'on':'')+'">Table</button>'
      + '<button data-view="graph" class="'+(state.view==='graph'?'on':'')+'">Graph</button></div>';
    hdr.insertAdjacentElement('afterend', bar);
    bar.querySelectorAll('#viewSeg button').forEach(function(b){ b.addEventListener('click', function(){ state.view=b.getAttribute('data-view'); render(); }); });
  }

  // ── Sidebar notes (bound to state.notes[state.stepId]) ──
  function syncSidebarNotes(){
    const ta = document.getElementById('researcherNotes');
    const tag = document.getElementById('nbStepTag');
    const idx = stepIndex(), s = activeStep();
    if (ta) { ta.value = state.notes[state.stepId] || ''; ta.placeholder = 'Jot observations for ' + (s.label||'this step') + '…'; }
    if (tag) tag.textContent = 'Step ' + (idx+1);
  }
  (function wireSidebarNotes(){
    let timer = null;
    document.addEventListener('input', function(e){
      if (!e.target || e.target.id !== 'researcherNotes') return;
      state.notes[state.stepId] = e.target.value;
      const saved = document.getElementById('nbSaved');
      if (saved) saved.classList.remove('vis');
      clearTimeout(timer);
      timer = setTimeout(function(){ if (saved){ saved.classList.add('vis'); setTimeout(function(){ saved.classList.remove('vis'); }, 2000); } }, 800);
    });
  })();

  // ── Coach tab wiring (Explain / Notes / Intelligence) ──
  document.querySelectorAll('.comp-tab').forEach(function(b){
    b.addEventListener('click', function(){
      state.compTab = b.getAttribute('data-tab');
      document.querySelectorAll('.comp-tab').forEach(function(x){ x.classList.toggle('active', x===b); });
      renderCompanion();
    });
  });

  // ── Coach drawer open/close (toggles body.coach-open). Closes the report drawer first. ──
  window.toggleCoach = function(){
    const opening = !document.body.classList.contains('coach-open');
    if (opening){ const d=document.getElementById('rptDrawer'), sc=document.getElementById('rptScrim');
      if (d) d.classList.remove('open'); if (sc) sc.classList.remove('open'); document.body.classList.remove('report-open'); }
    document.body.classList.toggle('coach-open', opening);
    if (opening) renderCompanion();
  };
  // ── Report drawer open/close (toggles body.report-open + .rpt-drawer.open + .rpt-scrim). ──
  window.toggleRptDrawer = function(){
    const d = document.getElementById('rptDrawer'), sc = document.getElementById('rptScrim');
    if (!d) return;
    const opening = !d.classList.contains('open');
    if (opening) document.body.classList.remove('coach-open');
    d.classList.toggle('open', opening); if (sc) sc.classList.toggle('open', opening);
    document.body.classList.toggle('report-open', opening);
  };
  // ── Topbar Report button jumps to the Report step (renders in the center). ──
  window.goReport = function(){
    const d = document.getElementById('rptDrawer'), sc = document.getElementById('rptScrim');
    if (d) d.classList.remove('open'); if (sc) sc.classList.remove('open'); document.body.classList.remove('report-open');
    state.stepId = 'report'; render();
    const c = document.getElementById('mainContent'); if (c) c.scrollTop = 0;
  };
  document.addEventListener('keydown', function(e){ if (e.key==='Escape' && document.body.classList.contains('coach-open')) window.toggleCoach(); });

  // ── Master render ──
  function render(){
    renderTopbarSteps();
    renderCenter();
    renderCompanion();
    syncSidebarNotes();
    const c = document.getElementById('mainContent'); if (c) c.scrollTop = 0;
  }

  // Sidebar project name
  (function(){
    const nm = document.getElementById('sbProjectLabel');
    if (nm) nm.textContent = BOOT.projectLabel || 'Inferential Analysis';
  })();

  render();
  loadDataset();
})();
</script>
</body>
</html>
