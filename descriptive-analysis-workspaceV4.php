<?php
// descriptive-analysis-workspaceV4.php — Descriptive Analysis Studio, v4 shell.
//
// Mirrors mmstudioV4.php / qual-studio-workspaceV4.php infrastructure wholesale:
// the 4-row CSS grid (RC header / 76px topbar / sidebar+main / footer), the
// horizontal top step rail, the left sidebar (Researcher's Notes), the slide-in
// ReliCheck Coach, and the Report drawer.
// Accent: QUAN blue (#0A6FE8). Shell only — step renderers are stubs to be
// wired after the shell design is signed off.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/descriptive-analysis-workspaceV4.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// ─── PROJECT LOAD ─────────────────────────────────────────────────────────────
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectRow = null;
if ($projectId > 0) {
    try {
        $pdo = db();
        $s = $pdo->prepare("SELECT * FROM analysis_projects WHERE id=:id AND user_id=:u AND kind='descriptive' AND status<>'archived' LIMIT 1");
        $s->execute([':id' => $projectId, ':u' => $uid]);
        $projectRow = $s->fetch(PDO::FETCH_ASSOC);
        if (!$projectRow) $projectId = 0;
    } catch (Throwable $e) { $projectId = 0; }
}

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$projLabel = $projectRow ? ($projectRow['title'] ?? 'Untitled analysis') : 'Sample walkthrough';

// ─── PIPELINE ────────────────────────────────────────────────────────────────
$DA_STEPS = [
    ['id'=>'start',            'label'=>'Start',              'eyebrow'=>'New analysis',                    'mode'=>'start',    'title'=>'Start',                 'lede'=>'Upload your data or open a saved project to begin.'],
    ['id'=>'mode',             'label'=>'Mode',               'eyebrow'=>'Mode · choose your approach',     'mode'=>'mode',     'title'=>'How do you want to analyze?', 'lede'=>'Pick the approach that fits your goal.'],
    ['id'=>'overview',         'label'=>'Overview',           'eyebrow'=>'Overview · your data',            'mode'=>'overview', 'title'=>'Overview',              'lede'=>'Confirm what is in your dataset before analyzing.'],
    ['id'=>'datamap',          'label'=>'Variable Map',       'eyebrow'=>'Variable Map · tag columns',      'mode'=>'datamap',  'title'=>'Variable Map',          'lede'=>'Tell ReliCheck which columns are numeric and which describe groups.'],
    ['id'=>'frequencies',      'label'=>'Frequencies',        'eyebrow'=>'Frequencies · how often?',        'mode'=>'work',     'title'=>'Frequencies',           'lede'=>'How many responses in each category — the first look at any categorical variable.',  'tool'=>'frequencies'],
    ['id'=>'distributions',    'label'=>'Distributions',      'eyebrow'=>'Distributions · central tendency','mode'=>'work',     'title'=>'Means & Distributions', 'lede'=>'Averages, spreads, and the shape of your numeric variables.',                          'tool'=>'distributions'],
    ['id'=>'cross_tabs',       'label'=>'Cross-Tabs',         'eyebrow'=>'Cross-Tabs · two variables',      'mode'=>'work',     'title'=>'Cross-Tabs',            'lede'=>'See how two categorical variables relate to each other.',                              'tool'=>'cross_tabs'],
    ['id'=>'group_summaries',  'label'=>'Group Summaries',    'eyebrow'=>'Group Summaries · compare groups','mode'=>'work',     'title'=>'Group Summaries',       'lede'=>'Compare a numeric outcome across two or more groups.',                                'tool'=>'group_summaries'],
    ['id'=>'top_bottom_items', 'label'=>'Top & Bottom',       'eyebrow'=>'Top & Bottom · rank items',       'mode'=>'work',     'title'=>'Top & Bottom Items',    'lede'=>'Rank items by mean or frequency to find what stands out.',                            'tool'=>'top_bottom_items'],
    ['id'=>'scale_scores',     'label'=>'Scale Scores',       'eyebrow'=>'Scale Scores · composite',        'mode'=>'work',     'title'=>'Scale Scores',          'lede'=>'Compute averages across a set of related Likert items.',                              'tool'=>'scale_scores'],
    ['id'=>'report',           'label'=>'Report',             'eyebrow'=>'Report · put it together',        'mode'=>'output',   'title'=>'Report',                'lede'=>'Assemble your saved analyses into a final report.'],
];

$DA_HELP = [
    'start'           => ['what'=>'The entry point. Upload survey or collected data, or reopen a saved project.', 'measures'=>'A project with your data loaded, ready to map and analyze.', 'use'=>'Start here whenever you begin a new descriptive analysis.'],
    'mode'            => ['what'=>'Choose how you want to work through your data — step by step yourself, or let ReliCheck Intelligence run it automatically.', 'measures'=>'A clear direction so the right tools open next.', 'use'=>'Pick Self analyze to explore on your own. Pick Auto analyze or Auto report for instant AI-generated results.'],
    'overview'        => ['what'=>'A quick read of what is in your dataset before any analysis begins.', 'measures'=>'Row counts, variable counts, and a full variable list so nothing surprises you mid-analysis.', 'use'=>'Review this after loading data. It gates the analysis steps.'],
    'datamap'         => ['what'=>'Tags each column with the type ReliCheck needs to run the right analysis.', 'measures'=>'A clean variable map so every step knows what to compute.', 'use'=>'Confirm right after Overview, before any analysis step.'],
    'frequencies'     => ['what'=>'Counts how often each value appears in a categorical variable.', 'measures'=>'A frequency table with counts and valid percentages for each category.', 'use'=>'Use on any variable with a limited set of distinct values — Yes/No, role, department.'],
    'distributions'   => ['what'=>'Summarizes numeric variables with mean, median, standard deviation, and distribution shape.', 'measures'=>'Descriptive statistics that answer "what is typical, and how spread out?"', 'use'=>'Use on any Likert scale or numeric measure before comparing groups.'],
    'cross_tabs'      => ['what'=>'Shows how two categorical variables relate — how does one group split across another?', 'measures'=>'A contingency table showing counts and row or column percentages.', 'use'=>'Use when you want to know whether membership in one category predicts membership in another.'],
    'group_summaries' => ['what'=>'Compares a numeric variable across two or more groups.', 'measures'=>'Group means, standard deviations, and n, so you can see a pattern before running inferential tests.', 'use'=>'Use as an immediate precursor to group comparison tests in the Inferential Studio.'],
    'top_bottom_items'=> ['what'=>'Ranks items by their mean or frequency to identify what stands out highest and lowest.', 'measures'=>'A ranked item list showing where to focus attention and where concerns cluster.', 'use'=>'Use on a battery of Likert items to spot standout and lagging items at a glance.'],
    'scale_scores'    => ['what'=>'Combines related Likert items into a single composite score by averaging them.', 'measures'=>'A scale score per respondent that summarizes an underlying construct.', 'use'=>'Use when a set of items is designed to measure one construct — compute the composite before comparing groups.'],
    'report'          => ['what'=>'Assembles your saved analyses into a single structured output.', 'measures'=>'A report you can download as Word or print as PDF.', 'use'=>'Return here after saving each analysis step with Add to report.'],
];

$validStepIds = array_column($DA_STEPS, 'id');
$initialStep  = (isset($_GET['step']) && in_array($_GET['step'], $validStepIds, true)) ? (string)$_GET['step'] : null;

$BOOT = [
    'projectId'    => $projectId,
    'projectLabel' => $projLabel,
    'projectLive'  => $projectId > 0,
    'projectsUrl'  => '/descriptive-analysis-projects.php',
    'projectType'  => 'analysis',
    'initials'     => $initials,
    'isSample'     => $projectId === 0,
    'project'      => $projectRow ?: null,
    'initialStep'  => $initialStep,
];
$DA = ['steps' => $DA_STEPS, 'help' => $DA_HELP];

function _dav4(string $path): string { $f = __DIR__ . $path; return is_file($f) ? (string)filemtime($f) : (string)time(); }
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Descriptive Studio · <?= htmlspecialchars($projLabel) ?> — ReliCheck</title>
<link rel="icon" href="/logo-brand.svg">
<style>
/* =====================================================
   Descriptive Analysis Studio v4 — mirrors MM + Qual v4
   ===================================================== */
:root{
  /* Primary accent — QUAN blue */
  --indigo:#0A6FE8; --indigo-dark:#085fcc; --indigo-light:#EEF3FA;
  /* Mapped aliases */
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
.tb-step.active .tb-node{background:var(--indigo);color:#fff;border-color:transparent;box-shadow:0 1px 6px rgba(10,111,232,.4);width:28px;height:28px;}
.tb-node-label{position:absolute;top:calc(100% + 5px);left:50%;transform:translateX(-50%);font-size:10px;font-weight:600;color:var(--indigo);white-space:nowrap;letter-spacing:-.1px;pointer-events:none;}
.tb-connector{width:36px;height:1.5px;background:var(--border);flex-shrink:0;transition:background .15s;}
.tb-connector.done{background:var(--green);opacity:.4;}
/* Topbar action buttons */
.tb-act{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:500;padding:7px 14px;border-radius:999px;cursor:pointer;white-space:nowrap;transition:all .13s;}
.tb-act svg{width:13px;height:13px;flex-shrink:0;}
.tb-act-save{background:transparent;border:1px solid var(--border);color:var(--text-2);}
.tb-act-save:hover{background:var(--bg);color:var(--text);}
.tb-act-save.saved{color:var(--green);border-color:rgba(52,199,89,.3);background:var(--green-soft);}
/* ── Sidebar (row 3, col 1) ── */
.sidebar{grid-row:3;grid-column:1;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
.sidebar-header{padding:20px 18px;flex:1;overflow-y:auto;}
.sidebar-project-name{font-size:11px;font-weight:600;color:var(--text-3);letter-spacing:.02em;text-transform:uppercase;margin-bottom:10px;}
.sidebar-divider{height:1px;background:var(--border);margin:18px 0;}
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
.nb-saved{font-size:10.5px;font-weight:500;color:var(--text-3);opacity:0;transition:opacity .3s;}
.nb-saved.vis{opacity:1;}
.btn-note-rpt{display:inline-flex;align-items:center;gap:5px;font-family:inherit;font-size:11px;font-weight:600;color:var(--indigo);background:transparent;border:none;cursor:pointer;padding:3px 8px;border-radius:999px;opacity:0;pointer-events:none;transition:all .12s;}
.btn-note-rpt.vis{opacity:1;pointer-events:auto;}
.btn-note-rpt:hover{background:var(--indigo-light);}
/* ── Main content (row 3, col 2) ── */
.main,.center{grid-row:3;grid-column:2;background:var(--surface);overflow-y:auto;padding:40px 32px 80px;}
.content-wrap,.center-inner{max-width:960px;margin:0 auto;}
/* ── Eyebrow ── */
.eyebrow{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--indigo);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.eyebrow-dot{width:5px;height:5px;border-radius:50%;background:var(--indigo);opacity:.5;flex-shrink:0;}
.eyebrow .mode-chip{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.04em;background:var(--text);color:#fff;}
.eyebrow .mode-chip.output{background:var(--quan);} .eyebrow .mode-chip.work{background:var(--indigo);} .eyebrow .mode-chip.setup{background:var(--text-3);}
/* ── Page heading ── */
.page-title,.title{font-family:var(--font-display);font-size:26px;font-weight:700;letter-spacing:-.5px;color:var(--text);margin-bottom:8px;line-height:1.15;}
.page-desc,.lede{font-size:14px;line-height:1.6;color:var(--text-2);max-width:560px;margin-bottom:28px;}
/* ── Card / Panel ── */
.card{background:var(--surface);border-radius:16px;box-shadow:var(--shadow-sm);padding:24px;margin-bottom:20px;}
.card-title{font-size:14px;font-weight:600;color:var(--text);letter-spacing:-.15px;margin-bottom:18px;}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow-sm);margin-bottom:20px;overflow:hidden;}
.panel-h{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);}
.panel-h h3{font-size:15px;font-weight:600;color:var(--text);} .panel-h .ph-sub{font-size:12px;color:var(--text-3);margin-top:2px;}
.panel-b{padding:20px;}
/* ── Buttons ── */
.btn-row,.run-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn-primary{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(10,111,232,.3);}
.btn-primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(10,111,232,.4);transform:translateY(-1px);}
.btn-primary:active{transform:translateY(0);}
.btn-secondary{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn-secondary:hover{color:var(--text);background:rgba(0,0,0,.03);}
.btn{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn:hover{color:var(--text);background:rgba(0,0,0,.03);border-color:rgba(0,0,0,.14);}
.btn.primary{background:var(--indigo);border-color:transparent;color:#fff;box-shadow:0 1px 4px rgba(10,111,232,.3);}
.btn.primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(10,111,232,.4);}
/* ── Step nav ── */
.footer-nav,.step-nav{display:flex;align-items:center;justify-content:space-between;padding-top:24px;}
.step-nav-prev{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--text-3);background:none;border:none;cursor:pointer;padding:8px 0;font-family:inherit;transition:color .12s;}
.step-nav-prev:hover{color:var(--text-2);}
.step-nav-next{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(10,111,232,.3);}
.step-nav-next:hover{background:var(--indigo-dark);transform:translateY(-1px);box-shadow:0 2px 8px rgba(10,111,232,.4);}
/* ── dx-* table components ── */
.dx-scroll{overflow-x:auto;margin:0 -4px;max-height:260px;overflow-y:auto;}
.dx-table{width:100%;border-collapse:collapse;font-size:13px;min-width:460px;}
.dx-table th{text-align:right;font-size:10.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);padding:0 12px 10px;border-bottom:1px solid var(--border);white-space:nowrap;position:sticky;top:0;background:var(--surface);}
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
.dx-q{background:rgba(10,111,232,.05);border:1px solid rgba(10,111,232,.12);border-radius:12px;padding:13px 15px;}
.dx-q .dx-l-t{color:var(--text);font-weight:600;}
.dx-caution{background:#fbf8ed;border:1px solid #f0ddb0;border-radius:12px;padding:13px 15px;}
.dx-caution .dx-l-k{color:#8a6418;} .dx-caution .dx-l-t{color:#7a5e1c;}
.dx-next{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--surface);border:1px solid var(--indigo);border-radius:14px;padding:14px 18px;box-shadow:var(--shadow-sm);margin-bottom:18px;}
.dx-next-k{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--indigo);flex:none;}
.dx-next-t{font-size:13.5px;color:var(--text-2);line-height:1.5;} .dx-next-t b{color:var(--text);}
/* ── dm-cards ── */
.dm-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;}
.dm-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);padding:13px 15px;display:flex;flex-direction:column;gap:6px;}
.dm-card-k{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text-3);}
.dm-card-v{font-size:23px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;letter-spacing:-.01em;line-height:1.1;}
.dm-save{display:flex;align-items:center;gap:12px;margin:0;padding:12px 0;position:sticky;bottom:0;background:var(--surface);border-top:1px solid var(--border);z-index:5;}
.dm-note{font-size:12.5px;color:var(--text-3);}
/* ── Strand chips ── */
.strand-chip{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.02em;}
.strand-chip.quan{background:var(--quan-soft);color:var(--quan-ink);}
/* ── Save-to-report strip ── */
.str-row{display:none;align-items:center;justify-content:space-between;padding:11px 15px;background:var(--surface);border-top:1px solid var(--border);border-radius:0 0 16px 16px;}
.str-row.vis{display:flex;}
.str-hint{font-size:12px;color:var(--text-3);}
.btn-str{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--indigo);background:var(--indigo-light);border:1px solid rgba(10,111,232,.15);border-radius:999px;padding:7px 13px;cursor:pointer;transition:all .13s;}
.btn-str:hover{background:rgba(10,111,232,.14);} .btn-str svg{width:12px;height:12px;}
/* ── Report drawer ── */
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
.btn-export{width:100%;display:flex;align-items:center;justify-content:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:999px;cursor:pointer;transition:background .13s;box-shadow:0 1px 4px rgba(10,111,232,.3);}
.btn-export:hover{background:var(--indigo-dark);}
.btn-export svg{width:14px;height:14px;flex-shrink:0;}
/* ── Coach pull tab ── */
.coach-tab{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:63;display:flex;align-items:center;justify-content:center;width:22px;height:110px;background:var(--surface);border:1px solid var(--border);border-right:none;border-radius:8px 0 0 8px;cursor:pointer;box-shadow:-2px 0 8px rgba(0,0,0,.06);transition:width .15s,background .15s,right .26s cubic-bezier(.32,.72,0,1);}
.coach-tab:hover{width:26px;background:var(--indigo-light);}
.coach-tab-label{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3);user-select:none;white-space:nowrap;transition:color .15s;}
body.coach-open .coach-tab{right:var(--companion);background:var(--indigo-light);border-color:rgba(10,111,232,.2);}
body.report-open .coach-tab{opacity:0;pointer-events:none;}
body.coach-open .coach-tab-label{color:var(--indigo);}
/* ── Companion / Coach panel ── */
.companion{background:var(--surface);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;position:fixed;top:0;right:0;bottom:0;width:var(--companion);z-index:62;box-shadow:-12px 0 40px rgba(0,0,0,.1);transform:translateX(100%);transition:transform .26s cubic-bezier(.32,.72,0,1);}
body.coach-open .companion{transform:translateX(0);}
.comp-head{display:flex;align-items:center;gap:10px;padding:16px 18px 14px;border-bottom:1px solid var(--border);}
.comp-head .ch-ico{width:28px;height:28px;border-radius:8px;background:var(--indigo-light);color:var(--indigo);display:grid;place-items:center;font-size:15px;flex:none;}
.comp-head .ch-ico svg{width:14px;height:14px;}
.comp-head h3{font-size:13px;font-weight:600;color:var(--text);} .comp-head .ch-sub{font-size:11px;color:var(--text-3);}
.comp-toggle{margin-left:auto;width:24px;height:24px;border-radius:6px;border:none;background:transparent;color:var(--text-3);display:grid;place-items:center;flex:none;cursor:pointer;font-size:16px;transition:background .12s,color .12s;}
.comp-toggle:hover{background:var(--bg);color:var(--text);}
.comp-body{padding:16px;overflow-y:auto;flex:1;}
.comp-block{margin-bottom:16px;}
.cb-k{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:7px;color:var(--text-3);}
.cb-k .i{width:16px;height:16px;border-radius:5px;display:grid;place-items:center;font-size:10px;color:#fff;background:var(--indigo);}
.cb-t{font-size:13px;line-height:1.55;color:var(--text-2);} .cb-t b{color:var(--text);font-weight:700;}
.comp-why{background:var(--indigo-light);border:1px solid rgba(10,111,232,.2);border-radius:12px;padding:13px 14px;}
.comp-why .cb-k{color:var(--indigo);} .comp-why .cb-t{color:var(--indigo);}
.comp-foot{padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0;}
.coach-ai-label{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);margin-bottom:7px;text-align:center;}
.coach-input-row{display:flex;gap:8px;align-items:center;}
.coach-input{flex:1;font-family:inherit;font-size:13px;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;outline:none;transition:border-color .15s;}
.coach-input:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(10,111,232,.08);}
.coach-input::placeholder{color:var(--text-3);}
.coach-send{width:32px;height:32px;border-radius:999px;background:var(--indigo);border:none;color:#fff;display:grid;place-items:center;cursor:pointer;flex-shrink:0;transition:background .12s;}
.coach-send:hover{background:var(--indigo-dark);} .coach-send svg{width:13px;height:13px;}
.coach-context-chip{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:var(--indigo);background:var(--indigo-light);padding:4px 10px;border-radius:999px;margin-bottom:14px;letter-spacing:.01em;}
.coach-context-chip svg{width:10px;height:10px;}
.coach-section{margin-bottom:18px;}
.coach-section-label{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-3);margin-bottom:8px;}
.coach-tip{font-size:13.5px;line-height:1.65;color:var(--text-2);}
.coach-tip strong,.coach-tip b{color:var(--text);font-weight:600;}
.coach-divider{height:1px;background:var(--border);margin:16px 0;}
.coach-prompt-list{display:flex;flex-direction:column;gap:6px;}
.coach-prompt{text-align:left;background:var(--bg);border:1px solid transparent;border-radius:10px;padding:10px 13px;font-family:inherit;font-size:12.5px;font-weight:500;color:var(--text-2);cursor:pointer;line-height:1.4;transition:all .12s;}
.coach-prompt:hover{background:var(--indigo-light);color:var(--indigo);border-color:rgba(10,111,232,.15);}
.coach-prompt.active{background:var(--indigo-light);color:var(--indigo);border-color:rgba(10,111,232,.15);font-weight:600;}
.coach-answer{background:var(--indigo-light);border-radius:12px;padding:13px 15px;font-size:13px;line-height:1.65;color:var(--text);margin-top:10px;display:none;}
.coach-answer.visible{display:block;}
/* ── Modal ── */
.modal-scrim{display:none;position:fixed;inset:0;background:rgba(20,28,45,.34);z-index:80;align-items:center;justify-content:center;padding:20px;}
.modal-scrim.open{display:flex;}
.modal{background:var(--surface);border-radius:18px;box-shadow:0 14px 40px rgba(0,0,0,.18);width:560px;max-width:100%;max-height:88vh;overflow-y:auto;}
.modal-h{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;}
.modal-h h3{font-size:16px;} .modal-h .mx{margin-left:auto;background:none;border:none;color:var(--text-3);font-size:18px;cursor:pointer;}
.modal-b{padding:20px 22px;}
/* ── Toast ── */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--text);color:#fff;padding:11px 18px;border-radius:999px;font-size:13px;font-weight:600;z-index:90;opacity:0;transition:.25s;pointer-events:none;}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
/* ── Start page ── */
.ws-header{margin-bottom:14px;}
.start-hero{font-size:28px;font-weight:700;letter-spacing:-.5px;line-height:1.2;margin-bottom:10px;max-width:30ch;font-family:var(--font-display);}
.start-hero .accent{color:var(--indigo);}
.begin-loaded{display:flex;align-items:center;gap:10px;padding:11px 16px;border:1px solid var(--border);background:var(--surface);border-radius:12px;font-size:13.5px;color:var(--text-2);margin-bottom:20px;box-shadow:var(--shadow-sm);}
.begin-loaded .dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex:none;}
.begin-loaded .bl-k{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);}
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
/* ── Overview / data stat cells ── */
.ov-stat{display:flex;flex-direction:column;gap:4px;min-width:80px;}
.ov-stat-n{font-size:26px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;line-height:1;}
.ov-stat-k{font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;}
/* ── Work surface placeholder ── */
.work-surface{border:1.5px dashed var(--border);border-radius:13px;background:var(--bg);padding:32px;color:var(--text-3);font-size:13.5px;line-height:1.6;text-align:center;}
/* ── One button family ── */
.tb-act-rpt{background:var(--indigo);border:1px solid transparent;color:#fff;box-shadow:0 1px 4px rgba(10,111,232,.3);}
.tb-act-rpt:hover{background:var(--indigo-dark);}
.rpt-count-badge{font-size:10px;font-weight:700;background:rgba(255,255,255,.25);padding:1px 6px;border-radius:999px;margin-left:2px;display:none;}
.rpt-scrim{display:none;position:fixed;inset:0;background:rgba(0,0,0,.18);z-index:63;}
.rpt-scrim.open{display:block;}
.rpt-drawer{position:fixed;top:0;right:0;bottom:0;width:420px;background:var(--surface);box-shadow:-12px 0 40px rgba(0,0,0,.1);z-index:64;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .28s cubic-bezier(.32,.72,0,1);}
.rpt-drawer.open{transform:translateX(0);}
.btn.primary,.btn-primary,.step-nav-next,.tb-act-rpt,.btn-export,.coach-send{
  background:var(--indigo)!important;color:#fff!important;border-color:transparent!important;
  box-shadow:0 1px 3px rgba(10,111,232,.35)!important;font-weight:600;}
.btn.primary:hover,.btn-primary:hover,.step-nav-next:hover,.btn-export:hover,.coach-send:hover{
  background:var(--indigo-dark)!important;box-shadow:0 1px 3px rgba(10,111,232,.35)!important;transform:none!important;}
.btn-str{background:var(--indigo-light)!important;color:var(--indigo)!important;border-color:rgba(10,111,232,.15)!important;box-shadow:none!important;}
/* ── Scrollbar ── */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(0,0,0,.12);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.2);}
/* ── DataMap component compact scoping ── */
#daDmContainer .rdm-tbl{font-size:12px;}
#daDmContainer .rdm-tbl th{padding:7px 8px;font-size:10px;}
#daDmContainer .rdm-tbl td{padding:6px 8px;}
#daDmContainer .rdm-vname,#daDmContainer .rdm-vlabel{max-width:118px;}
#daDmContainer .rdm-chip{font-size:10.5px;padding:2px 6px;}
#daDmContainer .rdm-sel{min-width:102px;max-width:148px;font-size:11.5px;padding:4px 6px;}
#daDmContainer .rdm-con{width:116px;font-size:11.5px;padding:4px 6px;}
/* ── Analysis engine classes (analysis-studio.js output) ── */
.as-empty-tool,.placeholder{padding:32px;text-align:center;color:var(--text-3);border:1.5px dashed var(--border);border-radius:14px;font-size:13.5px;}
.as-field{margin-bottom:16px;}
.as-field label,.as-field>label{display:block;font-size:11px;font-weight:600;color:var(--text-3);letter-spacing:.04em;text-transform:uppercase;margin-bottom:7px;}
.as-field .hint{font-size:11px;font-weight:400;color:var(--text-3);text-transform:none;letter-spacing:0;margin-left:5px;}
.ed-in{width:100%;font-family:inherit;font-size:13.5px;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:9px 12px;outline:none;transition:border-color .15s;display:block;}
.ed-in:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(10,111,232,.08);}
select.ed-in{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23aeaeb2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");background-repeat:no-repeat;background-position:right 11px center;padding-right:30px;cursor:pointer;}
.dx-bar{display:inline-block;width:80px;height:7px;background:var(--bg);border-radius:999px;overflow:hidden;vertical-align:middle;margin-left:4px;}
.dx-bar i{display:block;height:100%;background:var(--quan);border-radius:999px;}
.as-chart-wrap{margin:14px 0;}
.as-chart{max-width:100%;height:auto;display:block;}
.as-chart-seg{display:inline-flex;gap:3px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:3px;margin-bottom:14px;}
.as-chart-seg button{border:none;background:none;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--text-2);padding:6px 12px;border-radius:6px;cursor:pointer;transition:all .13s;}
.as-chart-seg button.on{background:var(--surface);color:var(--indigo);box-shadow:0 1px 3px rgba(0,0,0,.08);}
.as-help-bar{margin-bottom:18px;}
.btn-help{border:none;background:var(--indigo-light);color:var(--indigo);font-family:inherit;font-size:12.5px;font-weight:600;padding:7px 14px;border-radius:999px;cursor:pointer;transition:background .13s;}
.btn-help:hover{background:rgba(10,111,232,.14);}
/* Help overlay */
.au-overlay{position:fixed;inset:0;background:rgba(20,28,45,.34);z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;}
.au-panel{background:var(--surface);border-radius:18px;box-shadow:0 14px 40px rgba(0,0,0,.18);width:560px;max-width:100%;max-height:88vh;overflow-y:auto;padding:28px 30px;position:relative;}
.au-close{position:absolute;top:16px;right:18px;background:none;border:none;font-size:20px;color:var(--text-3);cursor:pointer;}
.au-title{font-size:18px;font-weight:700;margin-bottom:8px;}
.au-sub{font-size:14px;color:var(--text-2);line-height:1.5;margin-bottom:16px;}
.as-help-steps{padding-left:20px;margin:0 0 16px;}
.as-help-steps li{font-size:13.5px;color:var(--text-2);line-height:1.6;margin-bottom:8px;}
.au-example{background:var(--indigo-light);border-radius:12px;padding:14px 16px;font-size:13.5px;line-height:1.6;margin-bottom:16px;}
.au-confirm-actions{display:flex;justify-content:flex-end;margin-top:16px;}
.au-btn.primary{display:inline-flex;align-items:center;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;}
/* Coach content (engine's coachExplain output) */
.cb{margin-bottom:14px;}
.cb-i{width:16px;height:16px;border-radius:5px;display:inline-grid;place-items:center;font-size:10px;color:#fff;background:var(--indigo);vertical-align:middle;}
/* Save-to-report bar */
.as-save-bar{display:flex;align-items:center;gap:14px;margin-top:18px;padding:12px 16px;border:1px solid var(--border);border-radius:14px;background:var(--surface);box-shadow:var(--shadow-sm);}
.as-save-note{flex:1;font-size:13px;color:var(--text-3);}
/* Report step */
.as-rep-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.as-rep-block{border:1px solid var(--border);border-radius:14px;background:var(--surface);box-shadow:var(--shadow-sm);margin-bottom:14px;overflow:hidden;}
.as-rep-block-h{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-2);}
.as-rep-del{border:none;background:none;color:#c2492f;font-weight:600;font-size:12.5px;cursor:pointer;font-family:inherit;}
.as-rep-snap{padding:16px;}

/* ── Mode step ── */
.mode-loaded-strip{display:flex;align-items:center;gap:8px;padding:9px 16px;background:var(--bg);border:1px solid var(--border);border-radius:999px;font-size:13px;color:var(--text-2);width:fit-content;margin-bottom:22px;}
.mode-loaded-strip .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;flex:none;}
.mode-loaded-strip b{color:var(--text);font-weight:700;}
.mode-hero{margin-bottom:26px;}
.mode-hero-title{font-size:38px;font-weight:800;letter-spacing:-.03em;color:var(--text);line-height:1.08;margin:0 0 8px;}
.mode-hero-sub{font-size:15px;color:var(--text-2);margin:0;line-height:1.5;}
/* Featured card */
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
/* ── Lively report step ── */
.rep-v2-hero{margin-bottom:24px;}
.rep-v2-title{font-size:28px;font-weight:800;letter-spacing:-.03em;color:var(--text);margin:0 0 5px;}
.rep-v2-sub{font-size:14px;color:var(--text-2);margin:0;}
.rep-v2-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:22px;}
.rep-v2-block{border-radius:16px;background:var(--surface);border:1px solid var(--border);box-shadow:0 1px 3px rgba(0,0,0,.05),0 4px 16px rgba(0,0,0,.04);margin-bottom:16px;overflow:hidden;}
.rep-v2-stripe{height:4px;width:100%;}
.rep-v2-inner{padding:16px 20px 18px;}
.rep-v2-meta{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.rep-v2-badge{display:inline-flex;align-items:center;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 10px;border-radius:999px;}
.rep-v2-del{margin-left:auto;border:none;background:none;font-size:12px;font-weight:600;color:var(--text-3);cursor:pointer;padding:4px 8px;border-radius:6px;transition:background .12s,color .12s;}
.rep-v2-del:hover{background:#fee2e2;color:#dc2626;}
.rep-v2-snap{font-size:13px;color:var(--text-2);line-height:1.6;}
.rep-v2-empty{padding:48px 24px;text-align:center;color:var(--text-3);}
.rep-v2-empty p{font-size:14px;line-height:1.6;max-width:280px;margin:12px auto 0;}
@media print{.topbar,.sidebar,.companion,.coach-tab,.as-save-bar,.as-rep-actions,.as-rep-del,.footer-nav,.btn-help{display:none!important}
  .app{display:block!important;height:auto!important}.main,.center{overflow:visible!important;padding:0!important}
  .dx-scroll,.as-chart-wrap{max-height:none!important;overflow:visible!important}}
</style>
<script src="/apps/studio/dataset-upload.js?v=<?= _dav4('/apps/studio/dataset-upload.js') ?>"></script>
<script src="/apps/studio/studio-header.js?v=<?= _dav4('/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= _dav4('/apps/studio/studio-footer.js') ?>"></script>
<script src="/apps/studio/type-taxonomy.js?v=<?= _dav4('/apps/studio/type-taxonomy.js') ?>"></script>
<script src="/apps/studio/data-map.js?v=<?= _dav4('/apps/studio/data-map.js') ?>"></script>
<script src="/apps/analysis-studio/analysis-studio.js?v=<?= _dav4('/apps/analysis-studio/analysis-studio.js') ?>"></script>
</head>
<body>
<div class="app">

  <!-- RC global header (studio-header.js, row 1) -->
  <div id="studioHeader"></div>

  <!-- Studio topbar (row 2, full width) -->
  <header class="topbar">
    <div class="topbar-logo">Descriptive Studio</div>
    <div class="topbar-steps" id="topbarSteps"></div>
    <div class="topbar-right">
      <button class="tb-act tb-act-rpt" onclick="toggleRptDrawer()">
        <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="white" stroke-width="1.4"/><line x1="5" y1="6" x2="11" y2="6" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="8.5" x2="11" y2="8.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="11" x2="8" y2="11" stroke="white" stroke-width="1.4" stroke-linecap="round"/></svg>
        Report <span class="rpt-count-badge" id="rptCountBadge">0</span>
      </button>
      <button class="tb-act tb-act-save" id="saveProjectBtn" onclick="daSaveProject()">
        <svg viewBox="0 0 16 16" fill="none"><path d="M13 13H3a1 1 0 0 1-1-1V3l2-1h7l2 2v8a1 1 0 0 1-1 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><rect x="5" y="9" width="6" height="4" rx=".5" stroke="currentColor" stroke-width="1.4"/><rect x="5.5" y="2" width="4" height="3" rx=".5" stroke="currentColor" stroke-width="1.4"/></svg>
        Save
      </button>
      <div class="topbar-project">
        <strong><?= htmlspecialchars($projLabel) ?></strong>
        <?php if($projectId === 0): ?><span style="font-size:9.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#8a6418;background:#fbf1df;padding:2px 8px;border-radius:999px;">Sample</span><?php else: ?>Live project<?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Sidebar (row 3, col 1): Researcher's Notes -->
  <aside class="sidebar" id="studioSidebar">
    <div class="sidebar-header">
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
          <button class="btn-note-rpt" id="btnNoteRpt" onclick="daSaveNoteToReport()">
            <svg viewBox="0 0 12 12" fill="none"><line x1="6" y1="1" x2="6" y2="11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="1" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Add to Report
          </button>
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
    <button class="comp-toggle" onclick="toggleCoach()" title="Close">&#x2715;</button>
  </div>
  <div class="comp-body" id="compBody"></div>
  <div class="comp-foot">
    <div class="coach-ai-label">&#x2726; Ask ReliCheck Intelligence</div>
    <div class="coach-input-row">
      <input class="coach-input" type="text" id="coachInput" placeholder="Ask a question about this step..." onkeydown="handleCoachInput(event)">
      <button class="coach-send" onclick="handleCoachSend()" title="Ask"><svg viewBox="0 0 16 16" fill="none"><line x1="3" y1="8" x2="13" y2="8" stroke="white" stroke-width="1.8" stroke-linecap="round"/><polyline points="9,4 13,8 9,12" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    </div>
  </div>
</aside>


<!-- Report drawer: saved analyses only -->
<div class="rpt-scrim" id="rptScrim" onclick="toggleRptDrawer()"></div>
<div class="rpt-drawer" id="rptDrawer">
  <div class="rpt-head">
    <h2>Saved analyses</h2>
    <span class="rpt-badge" id="rptBadgeDrawer">0 saved</span>
    <button class="rpt-close-btn" onclick="toggleRptDrawer()">&#x2715;</button>
  </div>
  <div class="rpt-body" id="rptBody">
    <div class="rpt-empty" id="rptEmpty">
      <svg viewBox="0 0 40 40" fill="none"><rect x="6" y="4" width="28" height="32" rx="3" stroke="currentColor" stroke-width="2"/><line x1="12" y1="14" x2="28" y2="14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="20" x2="28" y2="20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="26" x2="20" y2="26" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <p>Nothing saved yet. Run an analysis step and click <strong>Add to report</strong>.</p>
    </div>
  </div>
  <div class="rpt-foot" style="padding:14px 22px;border-top:1px solid var(--border);">
    <button class="btn-export" onclick="goStep('report');toggleRptDrawer();">
      <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="white" stroke-width="1.4"/><line x1="5" y1="6" x2="11" y2="6" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="8.5" x2="11" y2="8.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="11" x2="8" y2="11" stroke="white" stroke-width="1.4" stroke-linecap="round"/></svg>
      Go to Report step
    </button>
  </div>
</div>

<div class="modal-scrim" id="modalScrim"><div class="modal" id="modal"></div></div>
<div class="toast" id="toast"></div>

<script>
const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES) ?>;
const DA   = <?= json_encode($DA,   JSON_UNESCAPED_SLASHES) ?>;
const STEPS = DA.steps, HELP = DA.help;

const $=s=>document.querySelector(s);
function esc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function go(u){window.location.href=u;}
let tT;function toast(m){const t=$("#toast");t.textContent=m;t.classList.add('show');clearTimeout(tT);tT=setTimeout(()=>t.classList.remove('show'),1800);}

const state={stepId:(BOOT.initialStep||STEPS[0].id),completedThrough:0,notes:{},dataset:null,view:'table',_dsLoading:false};
function buildSteps(){return STEPS.map((s,i)=>Object.assign({},s,{n:i+1,done:i<state.completedThrough}));}
function steps(){return buildSteps();}
function activeStep(){return steps().find(s=>s.id===state.stepId)||steps()[0];}

function navFooter(){
  const ss=steps(),cur=activeStep();
  const i=ss.findIndex(x=>x.id===cur.id);
  const prev=i>0?ss[i-1]:null, next=(i>=0&&i<ss.length-1)?ss[i+1]:null;
  const back=prev?'<button class="btn" onclick="stepBy(-1)">&#8592; '+esc(prev.label)+'</button>':'<span></span>';
  const fwd=next?'<button class="btn primary" onclick="stepBy(1)">'+esc(next.label)+' &#8594;</button>':'<span></span>';
  return '<div class="footer-nav">'+back+fwd+'</div>';
}
function wsHead(s){
  return '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(s.eyebrow||('Step '+s.n))
    +' <span class="strand-chip quan">QUAN</span></div>'
    +'<h1 class="title">'+esc(s.title)+'</h1><p class="lede">'+esc(s.lede)+'</p></div>';
}
function stub(s){
  return wsHead(s)+'<div class="work-surface">'+esc(s.title)+' will run here once data is loaded and variables are mapped.</div>'+navFooter();
}

/* ════════ STEP RENDERERS ════════ */

function renderStart(s){
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Your saved projects</h3><div class="ph-sub">Open a descriptive analysis you have already started</div></div>
      <a style="margin-left:auto;font-size:12.5px;font-weight:700;color:var(--indigo);text-decoration:none" href="${esc(BOOT.projectsUrl)}">View all &#8594;</a></div>
      <div class="panel-b" id="stProjBody"><div class="work-surface">Loading your projects&#8230;</div></div></div>
    <button class="begin-feature" onclick="daStartUpload()">
      <span class="bc-ico">&#8681;</span>
      <div><h4>Bring in your data</h4>
        <p>Upload a CSV, Excel, or Qualtrics export. ReliCheck reads every column and lets you tag which are numeric scales, categorical groups, and identifiers.</p>
        <span class="bc-go">Upload data &#8594;</span></div>
    </button>
    <div class="begin-sec">Or start another way</div>
    <div class="begin-grid2">
      <button class="begin-card2" onclick="go(BOOT.projectsUrl)"><span class="bc-ico">&#9638;</span><h4>All projects</h4><p>Open your full list of saved analyses.</p><span class="bc-go">Open projects &#8594;</span></button>
      <button class="begin-card2" onclick="go('/rssi-app.php')"><span class="bc-ico">&#9650;</span><h4>From SIRI responses</h4><p>Analyze data collected from a published SIRI survey.</p><span class="bc-go">SIRI responses &#8594;</span></button>
      <button class="begin-card2" onclick="go('/inferential-statistics-workspaceV4.php')"><span class="bc-ico">&#10178;</span><h4>Need inferential tests?</h4><p>t-tests, ANOVA, regression, and effect sizes live in the Inferential Studio.</p><span class="bc-go">Inferential Studio &#8594;</span></button>
    </div>`;
  fetch('/api/analysis/list-projects.php?kind=descriptive',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='start')return;
    const body=$("#stProjBody");if(!body)return;
    if(!d.ok||!d.projects||!d.projects.length){body.innerHTML='<div class="work-surface">No saved projects yet. Upload data to begin.</div>';return;}
    const opts=d.projects.map(function(p){return '<option value="'+p.id+'" '+(p.id===BOOT.projectId?'selected':'')+'>'+esc(p.title||'Untitled')+'</option>';}).join('');
    body.innerHTML=`<div style="display:flex;gap:10px;align-items:center"><select class="ed-in" id="stProjSel" style="flex:1;font-family:inherit;font-size:13.5px;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:9px 12px">${opts}</select><button class="btn primary" onclick="daOpenProject()">Open &#8594;</button></div>`;
  }).catch(()=>{const b=$("#stProjBody");if(b&&activeStep().id==='start')b.innerHTML='<div class="work-surface">Could not load projects.</div>';});
}
function daOpenProject(){const sel=$("#stProjSel");if(sel&&sel.value)go('?project_id='+sel.value+'&step=mode');}

/* ── Auto analyze / report via quick-analyze-core ── */
function daOpenQuickAnalyze(mode){
  if(!BOOT.projectId||!state.dataset){toast('Load a project with data first.');return;}
  function run(){
    if(window.QuickAnalyze&&window.QuickAnalyze.openPopup){
      window.QuickAnalyze.openPopup(BOOT.projectId,{compact:true,mode:mode,
        workspaceUrl:'/descriptive-analysis-workspaceV4.php?project_id='+BOOT.projectId});
      return;
    }
    var s=document.createElement('script');
    s.src='/apps/analysis-studio/quick-analyze-core.js?v='+Date.now();
    s.onload=run;document.head.appendChild(s);
  }
  run();
}

function renderMode(s){
  const host=$("#centerInner"),has=!!state.dataset;
  const rows=has?(state.dataset.rowCount||0):0;
  const src=has?esc(state.dataset.source||BOOT.projectLabel):'';
  host.innerHTML=
    (has?'<div class="mode-loaded-strip"><span class="dot"></span><b>'+src+'</b><span>&middot;&nbsp;'+rows+' rows loaded</span></div>':'')
    +'<div class="mode-hero">'
      +'<h1 class="mode-hero-title">'+(has?'Your data is ready.':'Bring in your data first.')+'</h1>'
      +'<p class="mode-hero-sub">'+(has?'Choose how you want to work with it&nbsp;&mdash; step by step, or let ReliCheck Intelligence take the wheel.':'Upload a file on Step&nbsp;1, then come back to choose your approach.')+'</p>'
    +'</div>'
    // Self analyze
    +'<button class="option-card self" onclick="goStep(\'overview\')" style="display:flex;flex-direction:column;justify-content:flex-start;">'
      +'<div class="option-number-bg">01</div>'
      +'<div style="display:flex;gap:20px;align-items:flex-start;position:relative;z-index:1;">'
        +'<div class="option-icon">&#9776;</div>'
        +'<div style="flex:1;">'
          +'<div class="option-step">Your pace · full control</div>'
          +'<div class="option-title">Self analyze</div>'
        +'</div>'
      +'</div>'
      +'<div class="option-copy">Step through Frequencies, Distributions, Cross-Tabs, Group Summaries, and more at your own pace. You decide what runs and what goes in your report.</div>'
    +'</button>'
    // AI pair
    +'<p style="text-align:center;font-size:14px;color:var(--text-3);margin:32px 0;font-weight:600;">+ Or let ReliCheck Intelligence do it</p>'
    +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:8px;">'
      +'<button class="option-card auto" onclick="'+(has?'daOpenQuickAnalyze(\'auto\');':'')+'return false;"'+(has?'':'disabled style="opacity:.5;cursor:default;"')+'>'
        +'<div class="option-number-bg">02</div>'
        +'<div style="display:flex;gap:18px;align-items:flex-start;margin-bottom:24px;position:relative;z-index:1;">'
          +'<div class="option-icon">⚡</div>'
          +'<div style="flex:1;">'
            +'<div class="option-step" style="background:rgba(232, 132, 18, .15);padding:4px 10px;border-radius:999px;display:inline-block;">Instant Results</div>'
          +'</div>'
        +'</div>'
        +'<div class="option-title">Auto analyze</div>'
        +'<div class="option-copy">'+(has?'Every recommended test runs at once. Results appear instantly, ready to print or save as PDF.':'Load your data on Step 1 to unlock this.')+'</div>'
        +(has?'<a class="option-cta">Open analyzer →</a>':'')
      +'</button>'
      +'<button class="option-card report" onclick="'+(has?'daOpenQuickAnalyze(\'report\');':'')+'return false;"'+(has?'':'disabled style="opacity:.5;cursor:default;"')+'>'
        +'<div class="option-number-bg">03</div>'
        +'<div style="display:flex;gap:18px;align-items:flex-start;margin-bottom:24px;position:relative;z-index:1;">'
          +'<div class="option-icon">✦</div>'
          +'<div style="flex:1;">'
            +'<div class="option-step" style="background:rgba(31, 141, 79, .15);padding:4px 10px;border-radius:999px;display:inline-block;">Ai Written</div>'
          +'</div>'
        +'</div>'
        +'<div class="option-title">Auto report</div>'
        +'<div class="option-copy">'+(has?'ReliCheck Intelligence writes a plain-language report from your data — ready to print or save as PDF.':'Load your data on Step 1 to unlock this.')+'</div>'
        +(has?'<a class="option-cta">Generate report →</a>':'')
      +'</button>'
    +'</div>'
    +navFooter();
}

function renderOverview(s){
  if(!BOOT.projectId){
    $("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">No project loaded yet. Go to <strong>Start</strong> to upload data.</div>'+navFooter();return;
  }
  $("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">Loading dataset overview&#8230;</div>'+navFooter();
  fetch('/api/analysis/dataset.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(r=>r.json()).then(d=>{
      if(activeStep().id!=='overview')return;
      if(!d.ok||!d.has_data){
        $("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">No dataset linked yet. Go to <strong>Start</strong> to upload data.</div>'+navFooter();return;
      }
      const ds=d.dataset||{};const vars=ds.variables||[];
      const isNum=v=>/likert|numeric/.test((v.types||[]).join(',').toLowerCase());
      const statHtml=(n,l)=>`<div class="ov-stat"><div class="ov-stat-n">${n}</div><div class="ov-stat-k">${l}</div></div>`;
      const rows=vars.map(v=>`<tr><td class="dx-name">${esc(v.name)}</td><td>${esc((v.types||['—'])[0])}</td><td>${(v.values||[]).filter(x=>x!=null&&x!=='').length}</td></tr>`).join('');
      $("#centerInner").innerHTML=wsHead(s)
        +'<div class="panel"><div class="panel-h"><div><h3>Dataset</h3></div></div><div class="panel-b">'
        +'<div style="display:flex;gap:32px;flex-wrap:wrap;margin-bottom:14px">'
        +statHtml(ds.rowCount||0,'Rows')+statHtml(vars.length,'Variables')+statHtml(vars.filter(isNum).length,'Numeric')+'</div>'
        +'<p style="font-size:12.5px;color:var(--text-3)">Source: '+esc(ds.source||BOOT.projectLabel)+'</p></div></div>'
        +'<div class="panel"><div class="panel-h"><div><h3>Variables</h3><div class="ph-sub">'+vars.length+' detected</div></div></div>'
        +'<div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
        +'<thead><tr><th class="l">Variable</th><th class="l">Detected type</th><th>Valid n</th></tr></thead>'
        +'<tbody>'+rows+'</tbody></table></div></div></div>'
        +'<div style="margin-top:6px"><button class="btn primary" onclick="goStep(\'datamap\')">Map variables &#8594;</button></div>'
        +navFooter();
    }).catch(()=>{if(activeStep().id==='overview')$("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">Could not load dataset.</div>'+navFooter();});
}

function renderDataMap(s){
  if(!BOOT.projectId){
    $("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">No project loaded yet. Go to <strong>Start</strong> to upload data.</div>'+navFooter();return;
  }
  if(typeof DataMap==='undefined'){
    $("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">Variable Map is loading&#8230;</div>'+navFooter();return;
  }
  $("#centerInner").innerHTML=wsHead(s)+'<div id="daDmContainer"></div>'+navFooter();
  fetch('/api/analysis/dataset.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(r=>r.json()).then(d=>{
      if(activeStep().id!=='datamap')return;
      const vars=((d.ok&&d.has_data&&d.dataset&&d.dataset.variables)||[]).map((v,i)=>({
        variable_name:v.name,display_label:v.name,detected_type:(v.types||[])[0]||'',
        source:'dataset_column',include_in_analysis:true,position:i
      }));
      DataMap.init({
        container:document.getElementById('daDmContainer'),
        projectId:BOOT.projectId,projectType:'analysis',
        rawVars:vars,constructs:[],
        onConfirmed:function(){goStep('frequencies');}
      });
    }).catch(()=>{if(activeStep().id==='datamap')$("#centerInner").innerHTML=wsHead(s)+'<div class="work-surface">Could not load variable list.</div>'+navFooter();});
}

/* ── Dataset loader ── */
function loadDataset(done){
  if(state.dataset){if(done)done();return;}
  if(!BOOT.projectId){if(done)done();return;}
  if(state._dsLoading){if(done)setTimeout(function(){loadDataset(done);},200);return;}
  state._dsLoading=true;
  fetch('/api/analysis/dataset.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();})
    .then(function(d){state._dsLoading=false;if(d&&d.ok&&d.has_data&&d.dataset)state.dataset=d.dataset;if(done)done();})
    .catch(function(){state._dsLoading=false;if(done)done();});
}

/* ── Save-to-report bar ── */
function addSaveBar(host,s,snap){
  const bar=document.createElement('div');bar.className='as-save-bar';
  if(!BOOT.projectId){
    bar.innerHTML='<span class="as-save-note">Save your data to a project to add analyses to a report.</span>';
    host.appendChild(bar);return;
  }
  bar.innerHTML='<span class="as-save-note" id="asSaveNote">Add this analysis to your report.</span>'
    +'<button class="btn primary" id="asSaveBtn">&#10133; Add to report</button>';
  host.appendChild(bar);
  bar.querySelector('#asSaveBtn').addEventListener('click',function(){
    const btn=this,note=document.getElementById('asSaveNote');
    btn.disabled=true;btn.textContent='Saving…';
    fetch('/api/analysis/results.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({project_id:BOOT.projectId,tool_key:s.tool,inputs:{view:state.view},result:{html:snap},summary:s.label})})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d||!d.ok)throw 0;
        btn.textContent='Saved ✓';
        if(note)note.textContent='Saved. Open Report in the drawer or step 10.';
        daReport.count=(daReport.count||0)+1;daUpdateReportCount();
      })
      .catch(function(){btn.disabled=false;btn.textContent='Add to report';if(note)note.textContent='Could not save — try again.';});
  });
}

/* ── Work step renderer (shared) ── */
function renderWorkStep(s){
  const host=$("#centerInner");
  if(!state.dataset){
    host.innerHTML=wsHead(s)+'<div class="work-surface">Loading data…</div>';
    loadDataset(function(){render();});
    return;
  }
  if(!window.AnalysisStudio){
    host.innerHTML=wsHead(s)+'<div class="work-surface">Analysis engine loading…</div>'+navFooter();return;
  }
  AnalysisStudio.renderWork(host,{kind:'descriptive',tool:s.tool,dataset:state.dataset,view:state.view});
  const snap=host.innerHTML;
  addSaveBar(host,s,snap);
  host.insertAdjacentHTML('beforeend',navFooter());
}

function renderFrequencies(s){   renderWorkStep(s); }
function renderDistributions(s){ renderWorkStep(s); }
function renderCrossTabs(s){     renderWorkStep(s); }
function renderGroupSummaries(s){renderWorkStep(s); }
function renderTopBottom(s){     renderWorkStep(s); }
function renderScaleScores(s){   renderWorkStep(s); }

function renderReport(s){
  const host=$("#centerInner");
  host.innerHTML=wsHead(s)+'<div id="asRepBody"><div class="work-surface">Loading…</div></div>'+navFooter();
  const body=host.querySelector('#asRepBody');
  if(!BOOT.projectId){body.innerHTML='<div class="work-surface">Save your data to a project first, then click <strong>Add to report</strong> on any step.</div>';return;}
  const colorMap={
    frequencies:{stripe:'#06B6D4',badge:'#ecf9ff'},
    distributions:{stripe:'#8B5CF6',badge:'#faf5ff'},
    cross_tabs:{stripe:'#EC4899',badge:'#fff5f8'},
    group_summaries:{stripe:'#F59E0B',badge:'#fffbf0'},
    top_bottom_items:{stripe:'#10B981',badge:'#f0fdf8'},
    scale_scores:{stripe:'#DC2626',badge:'#fef5f5'}
  };
  const labelMap={frequencies:'Frequencies',distributions:'Means & Distributions',cross_tabs:'Cross-Tabs',group_summaries:'Group Summaries',top_bottom_items:'Top & Bottom Items',scale_scores:'Scale Scores'};
  function lbl(k){return labelMap[k]||k;}
  fetch('/api/analysis/results.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();}).catch(function(){return null;})
  .then(function(d){
    const items=(d&&Array.isArray(d.results))?d.results:[];
    if(!items.length){body.innerHTML='<div class="rep-v2-empty"><svg viewBox="0 0 60 60" fill="none" style="width:60px;height:60px;margin:0 auto;opacity:.3"><rect x="10" y="8" width="40" height="44" rx="4" stroke="currentColor" stroke-width="2"/><line x1="18" y1="20" x2="42" y2="20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="18" y1="30" x2="42" y2="30" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="18" y1="40" x2="30" y2="40" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><p>No analyses saved yet.</p><p style="color:var(--text-3)">Run a step and click <strong>Add to report</strong> to build your report.</p></div>';return;}
    let html='<div class="rep-v2-hero"><h1 class="rep-v2-title">Your Report</h1><p class="rep-v2-sub">'+items.length+' analysis'+(items.length===1?'':'es')+' saved and ready</p></div>'
      +'<div class="rep-v2-actions"><button class="btn primary" onclick="window.print()" style="background:#0A6FE8"><svg viewBox="0 0 16 16" fill="none" style="width:14px;height:14px"><rect x="3" y="3" width="10" height="10" rx="1" stroke="currentColor" stroke-width="1.2"/><line x1="5" y1="8" x2="11" y2="8" stroke="currentColor" stroke-width="1.2"/></svg>Print / Save as PDF</button>'
      +'<button class="btn" onclick="daDownloadWord()" style="border:1px solid var(--border)"><svg viewBox="0 0 16 16" fill="none" style="width:14px;height:14px"><path d="M3 13h10M3 3h5l5 5v5H3V3z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>Download Word</button></div>';
    items.forEach(function(it){
      const c=colorMap[it.tool_key]||{stripe:'#6B7280',badge:'#f9fafb'};
      html+='<div class="rep-v2-block" style="border-top:4px solid '+c.stripe+'">'
        +'<div class="rep-v2-inner">'
        +'<div class="rep-v2-meta">'
        +'<span class="rep-v2-badge" style="background:'+c.badge+';color:'+c.stripe+'">'+esc(lbl(it.tool_key))+'</span>'
        +'<button class="rep-v2-del" data-id="'+it.id+'">Remove</button>'
        +'</div>'
        +'<div class="rep-v2-snap">'+((it.result&&it.result.html)||'<p style="color:var(--text-3)">'+esc(it.summary||'')+'</p>')+'</div>'
        +'</div></div>';
    });
    body.innerHTML=html;
    body.querySelectorAll('.rep-v2-del').forEach(function(b){
      b.addEventListener('click',function(){
        fetch('/api/analysis/results.php',{method:'DELETE',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:+b.getAttribute('data-id')})})
          .then(function(){renderReport(s);});
      });
    });
  }).catch(function(){body.innerHTML='<div class="work-surface">Could not load report.</div>';});
}

/* ════════ RENDER DISPATCH ════════ */
function renderCenter(){
  const s=activeStep();
  const map={
    start:renderStart, mode:renderMode, overview:renderOverview, datamap:renderDataMap,
    frequencies:renderFrequencies, distributions:renderDistributions,
    cross_tabs:renderCrossTabs, group_summaries:renderGroupSummaries,
    top_bottom_items:renderTopBottom, scale_scores:renderScaleScores,
    report:renderReport
  };
  (map[s.id]||renderStart)(s);
}

/* ════════ CHROME: topbar rail, coach ════════ */
function renderTopbarSteps(){
  const el=$("#topbarSteps");if(!el)return;
  const ss=steps(),act=activeStep();let html='';
  ss.forEach((s,i)=>{
    const isDone=s.done,isAct=s.id===act.id;
    const cls=isDone?'done':isAct?'active':'';
    const inner=isDone?'&#x2713;':s.n;
    html+=`<div class="tb-step ${cls}" onclick="goStep('${s.id}')" title="${esc(s.label)}">
      <div class="tb-node">${inner}${isAct?`<span class="tb-node-label">${esc(s.label)}</span>`:''}</div></div>`;
    if(i<ss.length-1)html+=`<div class="tb-connector ${isDone?'done':''}"></div>`;
  });
  el.innerHTML=html;
}

/* Coach */
let coachAns=[];
function coachData(s){
  const h=HELP[s.id]||{};
  function strip(t){return String(t==null?'':t).replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim();}
  const tip=h.what?esc(h.what):esc(s.lede);
  const qs=[],ans=[];
  if(h.measures){qs.push('What does this step give me?');ans.push(strip(h.measures));}
  if(h.use){qs.push('When should I use it?');ans.push(strip(h.use));}
  qs.push('Where am I overall?');ans.push('This is step '+s.n+' of '+steps().length+' in the descriptive analysis.');
  return {tip:tip,qs:qs,ans:ans};
}
function renderCompanion(){
  const s=activeStep(),ss=steps();
  const sub=$("#coachStepLabel");if(sub)sub.textContent='Step '+s.n+' guidance';

  // For analysis work steps, use the engine's richer coachExplain content.
  if(s.mode==='work'&&window.AnalysisStudio&&window.AnalysisStudio.coachExplain){
    const engineHtml=AnalysisStudio.coachExplain(s.tool);
    if(engineHtml){
      $("#compBody").innerHTML='<div class="coach-context-chip">'
        +'<svg viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.3"/><line x1="6" y1="5" x2="6" y2="8.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="6" cy="3.5" r=".6" fill="currentColor"/></svg>'
        +' Step '+s.n+' of '+ss.length+'</div>'
        +engineHtml
        +'<div class="coach-divider"></div>'
        +'<div class="coach-section"><div class="coach-section-label">Intelligence</div>'
        +'<div class="ai-suggest">'
        +'<button class="ai-chip" onclick="daCoachIntel(\'explain\')">Explain this step in plain language</button>'
        +'<button class="ai-chip" onclick="daCoachIntel(\'draft\')">Draft a sentence for my report</button>'
        +'<button class="ai-chip" onclick="daCoachIntel(\'next\')">What should I do next?</button>'
        +'</div><div class="ai-answer" id="intelOut" style="display:none"></div></div>';
      return;
    }
  }

  // Fallback: generic guidance from HELP object.
  const cd=coachData(s);coachAns=cd.ans;
  const prompts=cd.qs.map(function(q,i){return '<button class="coach-prompt" onclick="showCoachAnswer('+i+')">'+esc(q)+'</button>';}).join('');
  $("#compBody").innerHTML='<div class="coach-context-chip">'
    +'<svg viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.3"/><line x1="6" y1="5" x2="6" y2="8.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="6" cy="3.5" r=".6" fill="currentColor"/></svg>'
    +' Step '+s.n+' of '+ss.length+'</div>'
    +'<div class="coach-section"><div class="coach-section-label">Guidance</div><div class="coach-tip">'+cd.tip+'</div></div>'
    +'<div class="coach-divider"></div>'
    +'<div class="coach-section"><div class="coach-section-label">Common questions</div>'
    +'<div class="coach-prompt-list">'+prompts+'</div><div class="coach-answer" id="coachAnswer"></div></div>';
}
function daCoachIntel(kind){
  const s=activeStep(),out=document.getElementById('intelOut');if(!out)return;
  out.style.display='block';
  if(window.AnalysisStudio&&window.AnalysisStudio.intel){
    out.textContent=AnalysisStudio.intel(kind,s.tool,s.title)||'Nothing to show yet.';
  } else {
    out.textContent='Run this step first.';
  }
}
function coachType(el,text){el.textContent='';el.classList.add('visible');let pos=0;clearInterval(window._cty);
  window._cty=setInterval(()=>{if(pos<text.length){el.textContent+=text[pos++];}else{clearInterval(window._cty);}},10);}
function showCoachAnswer(i){const ans=$("#coachAnswer");document.querySelectorAll('.coach-prompt').forEach((p,idx)=>p.classList.toggle('active',idx===i));if(ans)coachType(ans,coachAns[i]||'');}
function handleCoachInput(e){if(e.key==='Enter')handleCoachSend();}
function handleCoachSend(){const input=$("#coachInput");if(!input)return;const v=input.value.trim();if(!v)return;
  const ans=$("#coachAnswer");const s=activeStep();document.querySelectorAll('.coach-prompt').forEach(p=>p.classList.remove('active'));
  if(ans)coachType(ans,'ReliCheck Intelligence will answer from your data in the full build. The questions above cover '+(s.title||'this step')+'.');input.value='';}
function toggleCoach(){
  const opening=!document.body.classList.contains('coach-open');
  if(opening){
    const d=document.getElementById('rptDrawer'),sc=document.getElementById('rptScrim');
    if(d)d.classList.remove('open');if(sc)sc.classList.remove('open');
    document.body.classList.remove('report-open');
  }
  document.body.classList.toggle('coach-open',opening);
  if(opening)renderCompanion();
}

/* Navigation */
function goStep(id){state.stepId=id;render();const c=$(".center");if(c)c.scrollTop=0;daUpdateNotes();}
function stepBy(dir){const s=steps();const i=s.findIndex(x=>x.id===activeStep().id);const ni=Math.max(0,Math.min(s.length-1,i+dir));
  state.stepId=s[ni].id;if(dir>0&&ni+1>state.completedThrough)state.completedThrough=ni;render();const c=$(".center");if(c)c.scrollTop=0;daUpdateNotes();}

/* Notes (per step, localStorage) */
const daNotes={};let daNoteTimer=null;
document.addEventListener('input',e=>{
  if(e.target.id!=='researcherNotes')return;
  const act=activeStep();daNotes[act.id]=e.target.value;
  const saved=$("#nbSaved"),addBtn=$("#btnNoteRpt");
  if(saved)saved.classList.remove('vis');
  if(addBtn)addBtn.classList.toggle('vis',e.target.value.trim().length>0);
  clearTimeout(daNoteTimer);daNoteTimer=setTimeout(()=>{if(saved){saved.classList.add('vis');setTimeout(()=>saved.classList.remove('vis'),2000);}},800);
});
function daUpdateNotes(){
  const ta=$("#researcherNotes"),tag=$("#nbStepTag"),addBtn=$("#btnNoteRpt"),act=activeStep();
  if(ta){ta.value=daNotes[act.id]||'';ta.placeholder='Jot observations for '+esc(act.label)+'…';}
  if(tag)tag.textContent='Step '+act.n;
  if(addBtn)addBtn.classList.toggle('vis',!!(ta&&ta.value.trim()));
}

function daSaveProject(){
  const btn=$("#saveProjectBtn");if(!btn)return;btn.classList.add('saved');
  btn.innerHTML='<svg viewBox="0 0 16 16" fill="none"><polyline points="3,8 6.5,12 13,4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg> Saved';
  setTimeout(()=>{btn.classList.remove('saved');btn.innerHTML='<svg viewBox="0 0 16 16" fill="none"><path d="M13 13H3a1 1 0 0 1-1-1V3l2-1h7l2 2v8a1 1 0 0 1-1 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><rect x="5" y="9" width="6" height="4" rx=".5" stroke="currentColor" stroke-width="1.4"/><rect x="5.5" y="2" width="4" height="3" rx=".5" stroke="currentColor" stroke-width="1.4"/></svg> Save';},2200);
}

function daSaveNoteToReport(){
  const ta=$("#researcherNotes");if(!ta||!ta.value.trim()){toast('Write a note first.');return;}
  const act=activeStep();toast('Note added to report');
  const btn=$("#btnNoteRpt");if(btn){btn.style.color='var(--green)';btn.innerHTML='<svg viewBox="0 0 12 12" fill="none"><polyline points="1,6 4.5,10 11,2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Added';
    setTimeout(()=>{btn.style.color='';btn.innerHTML='<svg viewBox="0 0 12 12" fill="none"><line x1="6" y1="1" x2="6" y2="11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="1" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> Add to Report';},2000);}
}
/* Report count + drawer */
const daReport={count:0};
function daUpdateReportCount(){
  const n=daReport.count;
  const b1=document.getElementById('rptCountBadge'),b2=document.getElementById('rptBadgeDrawer');
  if(b1){b1.style.display=n>0?'inline':'none';b1.textContent=n;}
  if(b2)b2.textContent=n+(n===1?' saved':' saved');
}
function daFetchReportCount(){
  if(!BOOT.projectId)return;
  fetch('/api/analysis/results.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();})
    .then(function(d){if(d&&Array.isArray(d.results)){daReport.count=d.results.length;daUpdateReportCount();}})
    .catch(function(){});
}
function toggleRptDrawer(){
  const drawer=document.getElementById('rptDrawer'),scrim=document.getElementById('rptScrim');
  const opening=drawer&&!drawer.classList.contains('open');
  document.body.classList.toggle('coach-open',false);
  if(drawer){drawer.classList.toggle('open',!!opening);}
  if(scrim){scrim.classList.toggle('open',!!opening);}
  document.body.classList.toggle('report-open',!!opening);
  if(opening)daLoadDrawer();
}
function daLoadDrawer(){
  const body=document.getElementById('rptBody'),empty=document.getElementById('rptEmpty');
  if(!body)return;
  body.querySelectorAll('.rpt-finding').forEach(function(el){el.remove();});
  if(!BOOT.projectId){if(empty)empty.style.display='flex';return;}
  fetch('/api/analysis/results.php?project_id='+BOOT.projectId,{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.json();})
    .then(function(d){
      const items=(d&&Array.isArray(d.results))?d.results:[];
      daReport.count=items.length;daUpdateReportCount();
      if(!items.length){if(empty)empty.style.display='flex';return;}
      if(empty)empty.style.display='none';
      const labelMap={frequencies:'Frequencies',distributions:'Means & Distributions',cross_tabs:'Cross-Tabs',group_summaries:'Group Summaries',top_bottom_items:'Top & Bottom Items',scale_scores:'Scale Scores'};
      items.forEach(function(it){
        const el=document.createElement('div');el.className='rpt-finding';
        el.innerHTML='<div class="rpt-step-tag">'+(labelMap[it.tool_key]||it.tool_key||'Analysis')+'</div>'
          +'<div class="rpt-finding-title">'+esc(it.summary||it.tool_key||'')+'</div>';
        body.insertBefore(el,body.querySelector('.rpt-empty'));
      });
    }).catch(function(){});
}

function daDownloadWord(){
  const blocks=document.querySelectorAll('.as-rep-snap');
  if(!blocks.length){toast('No analyses saved yet.');return;}
  let body='<h1>'+esc(BOOT.projectLabel)+' — Descriptive Analysis Report</h1>';
  blocks.forEach(function(b){body+=b.innerHTML;});
  const style='<style>body{font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#1a1d23}h1{font-size:18pt;margin-bottom:12pt}table{border-collapse:collapse;width:100%;font-size:10pt;margin:8pt 0}th,td{border:1px solid #ccc;padding:5px 8px}th{background:#f5f6f8}</style>';
  const doc='<html xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8">'+style+'</head><body>'+body+'</body></html>';
  const blob=new Blob(['﻿'+doc],{type:'application/msword'});
  const a=document.createElement('a');a.href=URL.createObjectURL(blob);
  a.download=(BOOT.projectLabel||'report').replace(/[^\w]+/g,'_')+'_report.doc';
  document.body.appendChild(a);a.click();a.remove();
  setTimeout(function(){URL.revokeObjectURL(a.href);},1500);
}

/* Upload */
function daStartUpload(){
  if(!window.DatasetUpload){toast('Upload widget not loaded.');return;}
  DatasetUpload.open({projectType:'analysis',projectId:BOOT.projectId||0,
    onLoaded:function(_e,pid){go('?project_id='+encodeURIComponent(pid)+'&step=mode');}});
}

/* Master render */
function render(){renderCenter();renderTopbarSteps();}
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.body.classList.contains('coach-open'))toggleCoach();});

/* Uniform studio header + footer */
if(typeof StudioHeader!=='undefined'){
  StudioHeader.init({
    logoSrc:'/DA-Studio-long.png', logoAlt:'Descriptive Studio',
    projectLabel:BOOT.projectLabel, projectLive:BOOT.projectId>0,
    projectsUrl:BOOT.projectsUrl, initials:'<?= htmlspecialchars($initials) ?>'
  });
}
if(typeof StudioFooter!=='undefined'){ StudioFooter.init(); }

/* Boot */
daUpdateReportCount();
render();
daUpdateNotes();
if(BOOT.projectId){
  loadDataset(function(){
    if(state.dataset&&state.stepId==='start')state.stepId='mode';
    render();
  });
  daFetchReportCount();
}
</script>
</body>
</html>
