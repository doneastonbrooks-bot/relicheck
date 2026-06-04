<?php
// qual-studio-workspaceV4.php — Qualitative Studio, 2026 MM-mirrored shell. PROTOTYPE.
//
// Mirrors mmstudioV4.php's infrastructure wholesale: the 4-row CSS grid
// (RC header / 76px topbar / sidebar+main / footer), the horizontal top step
// rail, the left sidebar, the slide-in ReliCheck Coach, and the Report drawer.
// Accent is Qual's forest green (per the per-studio accent convention); the
// structure is MM's.
//
// PROTOTYPE STAGE: every step renders high-fidelity SAMPLE data. No /api/qual
// calls are wired yet — that happens after the design is signed off, at which
// point the renderers point at the same endpoints the live V3 already uses.
// This file is NEW and does not touch the live qual-studio-workspaceV3.php.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_qual_studio.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/qual-studio-workspaceV4.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();
try { qual_ensure_schema($pdo); } catch (Throwable $e) { /* prototype tolerates */ }

// ─── PROJECT LOAD (optional — prototype runs on sample data either way) ───────
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectRow = null;
if ($projectId > 0) {
    try {
        $s = $pdo->prepare("SELECT * FROM qual_projects WHERE id=:id AND user_id=:u AND status<>'archived' LIMIT 1");
        $s->execute([':id' => $projectId, ':u' => $uid]);
        $projectRow = $s->fetch(PDO::FETCH_ASSOC);
        if (!$projectRow) $projectId = 0;
    } catch (Throwable $e) { $projectId = 0; }
}

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$projLabel = $projectRow ? ($projectRow['title'] ?? 'Untitled study') : 'Teacher Wellbeing Pulse (sample)';

// ─── PIPELINE CONFIG ─────────────────────────────────────────────────────────
// Single linear qualitative pipeline (no design branching). Inlined here for the
// prototype; trivially extractable to a _qual_pipeline.php mirror of _mm_pipelines.php.
$QUAL_PHASES = [
    ['key' => 'prepare',  'label' => 'Prepare',             'desc' => 'Bring in your data, map the columns, and clean it before coding.'],
    ['key' => 'code',     'label' => 'Familiarize & Code',  'desc' => 'Read the data, capture impressions, and apply codes consistently.'],
    ['key' => 'meaning',  'label' => 'Build Meaning',        'desc' => 'Group codes into categories and themes, then gather quote evidence.'],
    ['key' => 'validate', 'label' => 'Validate & Report',    'desc' => 'Document trustworthiness and assemble the report.'],
];
$QUAL_STEPS = [
    ['id'=>'start',           'label'=>'Start',        'phase'=>'prepare',  'mode'=>'start',    'title'=>'Start',                'lede'=>'Bring in your qualitative data, or open a saved project to begin.'],
    ['id'=>'setup',           'label'=>'Setup',        'phase'=>'prepare',  'mode'=>'form',     'title'=>'Project Setup',        'lede'=>'Name your study and frame the research question your analysis will answer.'],
    ['id'=>'upload',          'label'=>'Data',         'phase'=>'prepare',  'mode'=>'upload',   'title'=>'Data Entry',           'lede'=>'Upload interview transcripts, open-ended survey responses, or documents to analyze.'],
    ['id'=>'datamap',         'label'=>'Columns',      'phase'=>'prepare',  'mode'=>'datamap',  'title'=>'Column Setup',         'lede'=>'Tell ReliCheck which columns hold the text to analyze and which describe the respondent.'],
    ['id'=>'cleaning',        'label'=>'Clean',        'phase'=>'prepare',  'mode'=>'work',     'title'=>'Data Cleaning',        'lede'=>'Review your text and mask any personal information before coding.'],
    ['id'=>'familiarization', 'label'=>'Familiarize',  'phase'=>'code',     'mode'=>'work',     'title'=>'Familiarization',      'lede'=>'Read through the data and capture first impressions before formal coding.'],
    ['id'=>'coding',          'label'=>'Code',         'phase'=>'code',     'mode'=>'work',     'title'=>'Coding Workspace',     'lede'=>'Work through each segment and apply codes that capture what it is about.'],
    ['id'=>'codebook',        'label'=>'Codebook',     'phase'=>'code',     'mode'=>'work',     'title'=>'Codebook Builder',     'lede'=>'Define each code with a clear description and inclusion rules so coding stays consistent.'],
    ['id'=>'dual',            'label'=>'Dual Coder',   'phase'=>'code',     'mode'=>'work',     'title'=>'Dual Coder',           'lede'=>'Invite a second coder and compare where you agree and disagree.'],
    ['id'=>'categories',      'label'=>'Categories',   'phase'=>'meaning',  'mode'=>'work',     'title'=>'Category Builder',     'lede'=>'Group related codes into higher-level categories.'],
    ['id'=>'themes',          'label'=>'Themes',       'phase'=>'meaning',  'mode'=>'work',     'title'=>'Theme Builder',        'lede'=>'Build themes from your categories and see how widely each is supported.'],
    ['id'=>'quotes',          'label'=>'Quotes',       'phase'=>'meaning',  'mode'=>'work',     'title'=>'Quote Finder',         'lede'=>'Pin the most representative quotes to each theme as evidence.'],
    ['id'=>'trustworthiness', 'label'=>'Trust',        'phase'=>'validate', 'mode'=>'output',   'title'=>'Trustworthiness',      'lede'=>'Document the steps that make your analysis credible and dependable.'],
    ['id'=>'report',          'label'=>'Report',       'phase'=>'validate', 'mode'=>'output',   'title'=>'Report & Export',      'lede'=>'Assemble your themes, evidence, and trustworthiness into a shareable report.'],
];
// Coach guidance per step (what it is / what it gives you / when to use it).
$QUAL_HELP = [
    'start'           => ['what'=>'The entry point. Bring qualitative data into ReliCheck or reopen a study you have already started.', 'measures'=>'A project with your text data attached, ready to map and code.', 'use'=>'Start here whenever you begin a new qualitative analysis.'],
    'setup'           => ['what'=>'Where you name the study and write the research question that anchors every later decision.', 'measures'=>'A clear question and study description that keep your coding focused.', 'use'=>'Set this before coding so your themes answer a real question.'],
    'upload'          => ['what'=>'Brings transcripts or open-ended responses into the project using the shared upload widget.', 'measures'=>'Your raw text, split into analyzable segments.', 'use'=>'Use whenever you have new text to add.'],
    'datamap'         => ['what'=>'Classifies each column so ReliCheck knows which text to analyze and which fields describe the respondent.', 'measures'=>'A clean map of open-ended columns versus grouping columns.', 'use'=>'Confirm this right after upload, before cleaning.'],
    'cleaning'        => ['what'=>'Scans your text for personal information and lets you mask it before anyone reads the data.', 'measures'=>'De-identified text that is safe to share with co-coders.', 'use'=>'Run before inviting a second coder or exporting.'],
    'familiarization' => ['what'=>'A reading pass where you capture first impressions and recurring ideas before formal coding.', 'measures'=>'Early memos and candidate concepts to ground your codebook.', 'use'=>'Do this before you start applying codes.'],
    'coding'          => ['what'=>'The core workspace. You read each segment and apply codes that capture what it is about.', 'measures'=>'A coded dataset where every relevant segment carries one or more codes.', 'use'=>'This is the heart of the analysis — spend the most time here.'],
    'codebook'        => ['what'=>'Where each code gets a definition plus inclusion and exclusion rules.', 'measures'=>'A codebook that keeps coding consistent across segments and coders.', 'use'=>'Refine as codes emerge; lock it before dual coding.'],
    'dual'            => ['what'=>'Brings in a second coder and compares your codes to measure agreement.', 'measures'=>'An inter-coder agreement score and a list of disagreements to resolve.', 'use'=>'Use when you need evidence that coding is reliable.'],
    'categories'      => ['what'=>'Groups related codes into higher-level categories.', 'measures'=>'A tidy structure that sits between raw codes and themes.', 'use'=>'Use once your codebook has settled.'],
    'themes'          => ['what'=>'Builds themes from your categories and shows how widely each is supported across respondents.', 'measures'=>'A set of themes with coverage and sentiment, the backbone of your findings.', 'use'=>'This is where the analysis becomes a story.'],
    'quotes'          => ['what'=>'Helps you pick the most representative quotes and pin them to each theme.', 'measures'=>'Quote evidence that makes each theme concrete and credible.', 'use'=>'Use while drafting findings.'],
    'trustworthiness' => ['what'=>'Documents the steps that make your analysis credible, dependable, and confirmable.', 'measures'=>'A trustworthiness record reviewers can check.', 'use'=>'Complete before you finalize the report.'],
    'report'          => ['what'=>'Assembles your themes, quotes, and trustworthiness into a shareable report.', 'measures'=>'A draft report you can refine and export to Word or Markdown.', 'use'=>'The final step — build and export here.'],
];

$BOOT = [
    'projectId'    => $projectId,
    'projectLabel' => $projLabel,
    'projectLive'  => $projectId > 0,
    'projectsUrl'  => '/qual-studio.php',
    'projectType'  => 'qual',
    'initials'     => $initials,
    'isSample'     => $projectId === 0,
    // sample project list for the Start dropdown (prototype)
    'projects'     => $projectId === 0 ? [] : [['id'=>$projectId,'title'=>$projLabel]],
];
$QUAL = ['phases' => $QUAL_PHASES, 'steps' => $QUAL_STEPS, 'help' => $QUAL_HELP];

function _qsv4(string $path): string { $f = __DIR__ . $path; return is_file($f) ? (string)filemtime($f) : (string)time(); }
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Qualitative Studio · <?= htmlspecialchars($projLabel) ?> — ReliCheck</title>
<link rel="icon" href="/logo-brand.svg">
<style>
/* =====================================================
   MM Studio 2026 — Approved prototype design
   ===================================================== */
:root{
  /* Primary accent — indigo per approved prototype */
  --indigo:#1e5c3a; --indigo-dark:#174d30; --indigo-light:#e8f5ee;
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
.tb-step.active .tb-node{background:var(--indigo);color:#fff;border-color:transparent;box-shadow:0 1px 6px rgba(30,92,58,.4);width:28px;height:28px;}
.tb-node-label{position:absolute;top:calc(100% + 5px);left:50%;transform:translateX(-50%);font-size:10px;font-weight:600;color:var(--indigo);white-space:nowrap;letter-spacing:-.1px;pointer-events:none;}
.tb-connector{width:36px;height:1.5px;background:var(--border);flex-shrink:0;transition:background .15s;}
.tb-connector.done{background:var(--green);opacity:.4;}
/* Topbar action buttons */
.tb-act{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:500;padding:7px 14px;border-radius:999px;cursor:pointer;white-space:nowrap;transition:all .13s;}
.tb-act svg{width:13px;height:13px;flex-shrink:0;}
.tb-act-save{background:transparent;border:1px solid var(--border);color:var(--text-2);}
.tb-act-save:hover{background:var(--bg);color:var(--text);}
.tb-act-save.saved{color:var(--green);border-color:rgba(52,199,89,.3);background:var(--green-soft);}
.tb-act-rpt{background:var(--indigo);border:1px solid transparent;color:#fff;box-shadow:0 1px 4px rgba(30,92,58,.3);}
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
.ds-btn.active{background:var(--indigo);color:#fff;font-weight:600;box-shadow:0 1px 3px rgba(30,92,58,.35);}
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
.form-select:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(30,92,58,.1);}
/* ── Buttons ── */
.btn-row,.run-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn-primary{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(30,92,58,.3);}
.btn-primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(30,92,58,.4);transform:translateY(-1px);}
.btn-primary:active{transform:translateY(0);} .btn-primary svg{width:14px;height:14px;opacity:.85;}
.btn-secondary{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn-secondary:hover{color:var(--text);background:rgba(0,0,0,.03);}
.btn{display:inline-flex;align-items:center;gap:7px;background:transparent;color:var(--text-2);border:1px solid var(--border);font-family:inherit;font-size:13px;font-weight:500;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .15s ease;}
.btn:hover{color:var(--text);background:rgba(0,0,0,.03);border-color:rgba(0,0,0,.14);}
.btn.primary{background:var(--indigo);border-color:transparent;color:#fff;box-shadow:0 1px 4px rgba(30,92,58,.3);}
.btn.primary:hover{background:var(--indigo-dark);box-shadow:0 2px 8px rgba(30,92,58,.4);}
/* ── Step nav ── */
.footer-nav,.step-nav{display:flex;align-items:center;justify-content:space-between;padding-top:24px;}
.step-nav-prev{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--text-3);background:none;border:none;cursor:pointer;padding:8px 0;font-family:inherit;transition:color .12s;}
.step-nav-prev:hover{color:var(--text-2);} .step-nav-prev svg{width:14px;height:14px;}
.step-nav-next{display:inline-flex;align-items:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 20px;border-radius:999px;cursor:pointer;letter-spacing:-.1px;transition:all .15s ease;box-shadow:0 1px 4px rgba(30,92,58,.3);}
.step-nav-next:hover{background:var(--indigo-dark);transform:translateY(-1px);box-shadow:0 2px 8px rgba(30,92,58,.4);} .step-nav-next svg{width:14px;height:14px;}
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
.btn-str{display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--indigo);background:var(--indigo-light);border:1px solid rgba(30,92,58,.15);border-radius:999px;padding:7px 13px;cursor:pointer;transition:all .13s;}
.btn-str:hover{background:rgba(30,92,58,.14);} .btn-str svg{width:12px;height:12px;}
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
.btn-export,.btn-export-report{width:100%;display:flex;align-items:center;justify-content:center;gap:7px;background:var(--indigo);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:999px;cursor:pointer;transition:background .13s;box-shadow:0 1px 4px rgba(30,92,58,.3);}
.btn-export:hover,.btn-export-report:hover{background:var(--indigo-dark);}
/* ── Coach pull tab ── */
.coach-tab-btn,.coach-tab{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:63;display:flex;align-items:center;justify-content:center;width:22px;height:110px;background:var(--surface);border:1px solid var(--border);border-right:none;border-radius:8px 0 0 8px;cursor:pointer;box-shadow:-2px 0 8px rgba(0,0,0,.06);transition:width .15s,background .15s,right .26s cubic-bezier(.32,.72,0,1);}
.coach-tab-btn:hover,.coach-tab:hover{width:26px;background:var(--indigo-light);}
.coach-tab-lbl,.coach-tab-label{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--text-3);user-select:none;white-space:nowrap;transition:color .15s;}
/* when the panel is open the tab rides its left edge so it never gets covered */
body.coach-open .coach-tab-btn,body.coach-open .coach-tab{right:var(--companion);background:var(--indigo-light);border-color:rgba(30,92,58,.2);}
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
.comp-why{background:var(--indigo-light);border:1px solid rgba(30,92,58,.2);border-radius:12px;padding:13px 14px;}
.comp-why .cb-k{color:var(--indigo);} .comp-why .cb-t{color:var(--indigo);}
.notes-area{width:100%;min-height:200px;border:1px solid var(--border);border-radius:12px;padding:12px;font-family:inherit;font-size:13px;resize:vertical;color:var(--text);outline:none;}
.notes-area:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(30,92,58,.08);}
.ai-prompt{border:1px solid var(--border);border-radius:12px;padding:12px;font-size:13px;color:var(--text-3);background:var(--bg);margin-bottom:12px;}
.ai-suggest{display:flex;flex-direction:column;gap:8px;}
.ai-chip{text-align:left;border:1px solid var(--border);background:var(--surface);border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600;color:var(--text);cursor:pointer;}
.ai-chip:hover{border-color:var(--indigo);background:var(--indigo-light);color:var(--indigo);}
.ai-answer{border:1px solid rgba(30,92,58,.22);background:var(--indigo-light);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--text);margin-top:12px;}
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
.coach-prompt:hover{background:var(--indigo-light);color:var(--indigo);border-color:rgba(30,92,58,.15);}
.coach-prompt.active{background:var(--indigo-light);color:var(--indigo);border-color:rgba(30,92,58,.15);font-weight:600;}
.coach-answer{background:var(--indigo-light);border-radius:12px;padding:13px 15px;font-size:13px;line-height:1.65;color:var(--text);margin-top:10px;display:none;}
.coach-answer.visible{display:block;}
.comp-foot{padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0;}
.coach-input-row{display:flex;gap:8px;align-items:center;}
.coach-input{flex:1;font-family:inherit;font-size:13px;color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:8px 12px;outline:none;transition:border-color .15s;}
.coach-input:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(30,92,58,.08);}
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
.dx-q{background:rgba(30,92,58,.05);border:1px solid rgba(30,92,58,.12);border-radius:12px;padding:13px 15px;}
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
.answer{display:flex;gap:11px;align-items:flex-start;padding:12px 15px;border-radius:13px;background:var(--indigo-light);border:1px solid rgba(30,92,58,.2);margin-bottom:16px;}
.answer .a-ico{width:24px;height:24px;border-radius:7px;flex:none;display:grid;place-items:center;background:var(--indigo);color:#fff;font-size:13px;}
.answer .a-text{font-size:14px;line-height:1.55;color:var(--text);}
.work-surface{border:1.5px dashed var(--border);border-radius:13px;background:var(--bg);padding:24px;color:var(--text-2);font-size:13.5px;line-height:1.6;}
.phase-banner{display:flex;gap:9px;align-items:center;padding:9px 14px;background:var(--indigo-light);border:1px solid rgba(30,92,58,.2);border-radius:11px;font-size:12px;font-weight:600;color:var(--indigo);}
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
.proj-select:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(30,92,58,.08);}
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
.ed-in:focus{border-color:var(--indigo);box-shadow:0 0 0 3px rgba(30,92,58,.08);}
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
.tt-tab.on{background:var(--indigo-light);color:var(--indigo);border-color:rgba(30,92,58,.2);}
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
.dm-tab.on{background:var(--indigo-light);color:var(--indigo);border-color:rgba(30,92,58,.2);}
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
.q-tooltab.on{background:var(--indigo);color:#fff;box-shadow:0 1px 3px rgba(30,92,58,.35);}
/* ── One purple-button family — all match the Study Design switcher's flat indigo
   treatment (same fill, subtle shadow, indigo-dark hover, no glow/lift). ── */
.btn.primary,.btn-primary,.step-nav-next,.tb-act-rpt,.btn-export,.btn-export-report,.coach-send{
  background:var(--indigo)!important;color:#fff!important;border-color:transparent!important;
  box-shadow:0 1px 3px rgba(30,92,58,.35)!important;font-weight:600;}
.btn.primary:hover,.btn-primary:hover,.step-nav-next:hover,.tb-act-rpt:hover,.btn-export:hover,.btn-export-report:hover,.coach-send:hover{
  background:var(--indigo-dark)!important;box-shadow:0 1px 3px rgba(30,92,58,.35)!important;transform:none!important;}
/* soft purple (Save-to-report chip) keeps the tinted style but the same indigo */
.btn-str{background:var(--indigo-light)!important;color:var(--indigo)!important;border-color:rgba(30,92,58,.15)!important;box-shadow:none!important;}
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
/* ── Qual V4 overrides — fit 14 steps in the top rail, phase map in sidebar ── */
.topbar-steps{gap:0;}
.tb-connector{width:18px;}
.tb-node{width:24px;height:24px;}
.tb-step.active .tb-node{width:26px;height:26px;}
.phase-map{display:flex;flex-direction:column;gap:3px;margin-bottom:4px;}
.pm-item{display:flex;align-items:center;gap:9px;padding:7px 10px;border-radius:9px;font-size:12px;font-weight:600;color:var(--text-2);}
.pm-item.on{background:var(--indigo-light);color:var(--indigo);}
.pm-dot{width:7px;height:7px;border-radius:50%;background:var(--border);flex:none;}
.pm-item.on .pm-dot{background:var(--indigo);}
.pm-item.done .pm-dot{background:var(--green);}
/* code chips */
.code-chip{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--qual-ink);background:var(--qual-soft);border:1px solid transparent;border-radius:999px;padding:4px 11px;margin:0 6px 6px 0;}
.code-chip .x{color:var(--qual-ink);opacity:.5;font-weight:700;cursor:pointer;}
.seg-card{background:var(--surface);border:1px solid var(--border);border-radius:13px;box-shadow:var(--shadow-sm);padding:15px 17px;margin-bottom:12px;}
.seg-meta{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-3);margin-bottom:7px;}
.seg-text{font-size:14px;line-height:1.6;color:var(--text);margin-bottom:12px;}
.seg-scroll{max-height:420px;overflow-y:auto;padding-right:4px;}
.cat-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-sm);padding:16px 18px;margin-bottom:12px;}
.cat-card h4{font-size:14.5px;font-weight:700;margin-bottom:4px;}
.cat-card .cat-sub{font-size:12px;color:var(--text-3);margin-bottom:11px;}
.proto-pill{font-size:9.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#8a6418;background:#fbf1df;padding:2px 8px;border-radius:999px;}
</style>
<script src="/apps/studio/dataset-upload.js?v=<?= _qsv4('/apps/studio/dataset-upload.js') ?>"></script>
<script src="/apps/studio/studio-header.js?v=<?= _qsv4('/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= _qsv4('/apps/studio/studio-footer.js') ?>"></script>
</head>
<body>
<div class="app">

  <!-- RC global header (studio-header.js, row 1) -->
  <div id="studioHeader"></div>

  <!-- Studio topbar (row 2, full width) -->
  <header class="topbar">
    <div class="topbar-logo">Qualitative Studio</div>
    <div class="topbar-steps" id="topbarSteps"></div>
    <div class="topbar-right">
      <button class="tb-act tb-act-save" id="saveProjectBtn" onclick="qSaveProject()">
        <svg viewBox="0 0 16 16" fill="none"><path d="M13 13H3a1 1 0 0 1-1-1V3l2-1h7l2 2v8a1 1 0 0 1-1 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><rect x="5" y="9" width="6" height="4" rx=".5" stroke="currentColor" stroke-width="1.4"/><rect x="5.5" y="2" width="4" height="3" rx=".5" stroke="currentColor" stroke-width="1.4"/></svg>
        Save
      </button>
      <button class="tb-act tb-act-rpt" onclick="toggleRptDrawer()">
        <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="white" stroke-width="1.4"/><line x1="5" y1="6" x2="11" y2="6" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="8.5" x2="11" y2="8.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><line x1="5" y1="11" x2="8" y2="11" stroke="white" stroke-width="1.4" stroke-linecap="round"/></svg>
        Report <span class="rpt-count-badge" id="rptCountBadge">0</span>
      </button>
      <div class="topbar-project">
        <strong><?= htmlspecialchars($projLabel) ?></strong>
        <?php if($projectId === 0): ?><span class="proto-pill">Prototype</span><?php else: ?>Live project<?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Sidebar (row 3, col 1): Workflow phase map + Researcher's Notes -->
  <aside class="sidebar" id="studioSidebar">
    <div class="sidebar-header">
      <div class="sidebar-project-name">Workflow</div>
      <div class="phase-map" id="sbPhaseMap"></div>
      <div class="sidebar-design-desc" id="sbPhaseDesc"></div>

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
          <button class="btn-note-rpt" id="btnNoteRpt" onclick="qSaveNoteToReport()">
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
    <button class="comp-toggle" onclick="toggleCoach()" title="Close">✕</button>
  </div>
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
      <p>No findings saved yet. Pin a theme or use Add to Report to build your report.</p>
    </div>
  </div>
  <div class="rpt-foot">
    <button class="btn-export" onclick="qExportReport()">
      <svg viewBox="0 0 16 16" fill="none"><path d="M3 10v3h10v-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><line x1="8" y1="2" x2="8" y2="10" stroke="white" stroke-width="1.5" stroke-linecap="round"/><polyline points="5,7 8,10 11,7" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Export Report
    </button>
  </div>
</div>

<div class="modal-scrim" id="modalScrim"><div class="modal" id="modal"></div></div>
<div class="toast" id="toast"></div>

<script>
const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES) ?>;
const QUAL = <?= json_encode($QUAL, JSON_UNESCAPED_SLASHES) ?>;
const PHASES = QUAL.phases, STEPS = QUAL.steps, HELP = QUAL.help;

const $=s=>document.querySelector(s);
function esc(s){return (s==null?"":String(s)).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
function go(u){window.location.href=u;}
let tT;function toast(m){const t=$("#toast");t.textContent=m;t.classList.add('show');clearTimeout(tT);tT=setTimeout(()=>t.classList.remove('show'),1800);}

const state={stepId:STEPS[0].id,completedThrough:0,notes:{}};
function buildSteps(){return STEPS.map((s,i)=>Object.assign({},s,{n:i+1,done:i<state.completedThrough}));}
function steps(){return buildSteps();}
function activeStep(){return steps().find(s=>s.id===state.stepId)||steps()[0];}
function phaseLabel(key){const p=PHASES.find(p=>p.key===key);return p?p.label:'';}

// Footer nav whose buttons name the prior step (Back) and the next step (Continue).
function navFooter(){
  const ss=steps(); const cur=activeStep();
  const i=ss.findIndex(x=>x.id===cur.id);
  const prev=i>0?ss[i-1]:null, next=(i>=0&&i<ss.length-1)?ss[i+1]:null;
  const back=prev?'<button class="btn" onclick="stepBy(-1)">← '+esc(prev.label)+'</button>':'<span></span>';
  const fwd=next?'<button class="btn primary" onclick="stepBy(1)">'+esc(next.label)+' →</button>':'<span></span>';
  return '<div class="footer-nav">'+back+fwd+'</div>';
}
function wsHead(s){
  return '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>Step '+s.n+' · '+esc(phaseLabel(s.phase))
    +' <span class="strand-chip qual">QUAL</span></div>'
    +'<h1 class="title">'+esc(s.title)+'</h1><p class="lede">'+esc(s.lede)+'</p></div>';
}

/* ════════ SAMPLE DATA (prototype only) ════════ */
const SAMPLE={
  columns:[
    {name:'RespondentID', role:'Case identifier', kind:'meta'},
    {name:'Role',         role:'Grouping variable', kind:'group'},
    {name:'What is working well in your role?', role:'Open-ended response', kind:'open'},
    {name:'What would make your work more sustainable?', role:'Open-ended response', kind:'open'},
    {name:'Years teaching', role:'Grouping variable', kind:'group'},
  ],
  pii:[
    {kind:'Email', found:'2 segments', example:'…you can reach me at j.•••••@…', status:'warn'},
    {kind:'Person name', found:'4 segments', example:'…my mentor ••••• really helped…', status:'warn'},
    {kind:'Phone number', found:'1 segment', example:'…call me on 07••• •••…', status:'warn'},
  ],
  concepts:[
    {label:'workload / time', n:48},{label:'training & prep', n:31},
    {label:'colleagues / peers', n:27},{label:'recognition', n:19},{label:'admin paperwork', n:23},
  ],
  segments:[
    {ref:'R012 · Q1', text:'My team genuinely supports each other. When I am buried in marking, someone always offers to take a duty.', codes:['Peer collaboration','Supportive manager']},
    {ref:'R027 · Q2', text:'There just is not enough time. By the time I have finished admin paperwork the actual planning happens at home, late.', codes:['Time pressure','Administrative load']},
    {ref:'R031 · Q2', text:'I was thrown into a new year group with almost no training. I am constantly improvising and it is exhausting.', codes:['Lack of training','Burnout signals']},
    {ref:'R044 · Q1', text:'A simple thank you from leadership goes a long way. When I feel seen I can handle a heavier week.', codes:['Feeling valued']},
  ],
  codes:[
    {name:'Time pressure',        def:'References to insufficient time for core tasks.', n:42},
    {name:'Administrative load',  def:'Paperwork or admin that competes with teaching.', n:28},
    {name:'Lack of training',     def:'Feeling under-prepared or unsupported in a new task.', n:19},
    {name:'Unclear expectations', def:'Not knowing what success looks like.', n:12},
    {name:'Supportive manager',   def:'A leader who actively backs the respondent.', n:24},
    {name:'Peer collaboration',   def:'Colleagues sharing load or ideas.', n:31},
    {name:'Feeling valued',       def:'Recognition that sustains motivation.', n:17},
    {name:'Burnout signals',      def:'Exhaustion, depletion, or wanting to leave.', n:15},
  ],
  categories:[
    {name:'Barriers to sustainability', sub:'What drains capacity', codes:['Time pressure','Administrative load','Lack of training','Unclear expectations']},
    {name:'Sources of support',         sub:'What sustains people',  codes:['Supportive manager','Peer collaboration','Feeling valued']},
    {name:'Wellbeing signals',          sub:'Early warning signs',   codes:['Burnout signals']},
  ],
  themes:[
    {name:'Workload outpaces the support around it', cov:62, pos:18, neu:22, neg:60, n:78},
    {name:'Training gaps undercut confidence',       cov:41, pos:12, neu:28, neg:60, n:52},
    {name:'Peer relationships sustain morale',       cov:38, pos:74, neu:18, neg:8,  n:48},
    {name:'Recognition makes heavy weeks bearable',  cov:29, pos:69, neu:23, neg:8,  n:36},
  ],
  quotes:{
    'Workload outpaces the support around it':[
      {t:'By the time I have finished admin paperwork the actual planning happens at home, late.', m:'R027 · Year 4 teacher'},
      {t:'I love the teaching part. It is everything around it that is breaking me.', m:'R009 · Year 6 teacher'},
    ],
    'Peer relationships sustain morale':[
      {t:'When I am buried in marking, someone always offers to take a duty.', m:'R012 · Year 2 teacher'},
    ],
  },
  trust:[
    {k:'Credibility',     status:'Pass',   note:'Dual coding on 20% of segments; member check completed with 3 respondents.'},
    {k:'Transferability', status:'Review', note:'Add a richer description of the school context so readers can judge transfer.'},
    {k:'Dependability',   status:'Pass',   note:'Codebook versioned; every code carries a dated definition and example.'},
    {k:'Confirmability',  status:'Pass',   note:'Audit trail links each theme back to its coded segments and quotes.'},
  ],
};

/* ════════ STEP RENDERERS (sample data) ════════ */
function renderStart(s){
  const loaded=BOOT.projectId>0;
  $("#centerInner").innerHTML=wsHead(s)+`
    ${loaded?`<div class="begin-loaded"><span class="dot"></span><span class="bl-k">Project</span>
      <select class="proj-select" onchange="if(this.value)go('?project_id='+this.value)">
        ${(BOOT.projects||[]).map(p=>`<option value="${p.id}" ${p.id===BOOT.projectId?'selected':''}>${esc(p.title)}</option>`).join('')||`<option selected>${esc(BOOT.projectLabel)}</option>`}
      </select>
      <button class="btn primary" style="margin-left:auto" onclick="stepBy(1)">Continue →</button></div>`:''}
    <button class="begin-feature" onclick="qStartUpload()">
      <span class="bc-ico">⤓</span>
      <div><h4>Bring in your data</h4>
        <p>Have interview transcripts or open-ended survey responses? Upload a CSV, Excel, or document file to create a project and split it into analyzable segments, then come back to code.</p>
        <span class="bc-go">Upload data →</span></div>
    </button>
    <div class="begin-sec">Or start another way</div>
    <div class="begin-grid2">
      <button class="begin-card2" onclick="go('/qual-studio.php')"><span class="bc-ico">▦</span><h4>Open a saved project</h4><p>Return to a qualitative study already in ReliCheck.</p><span class="bc-go">Open projects →</span></button>
      <button class="begin-card2" onclick="stepBy(1)"><span class="bc-ico">✎</span><h4>Set up a new study</h4><p>Name the study and frame its research question first.</p><span class="bc-go">Project setup →</span></button>
      <button class="begin-card2" onclick="go('/mmstudioV4.php')"><span class="bc-ico">⇄</span><h4>Mixed methods?</h4><p>Pair this text with survey numbers in MM Studio.</p><span class="bc-go">Go to MM →</span></button>
    </div>`;
}
function renderSetup(s){
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Study details</h3><div class="ph-sub">These frame every coding decision</div></div></div>
      <div class="panel-b">
        <label class="ed-l">Study title</label>
        <input class="ed-in" value="Teacher Wellbeing Pulse">
        <label class="ed-l">Research question</label>
        <textarea class="ed-in" rows="2">What helps and what hinders teachers in sustaining their work, in their own words?</textarea>
        <label class="ed-l">Analysis approach</label>
        <input class="ed-in" value="Reflexive thematic analysis (Braun &amp; Clarke)">
        <div class="run-actions" style="margin-top:18px"><button class="btn primary" onclick="toast('Saved (prototype)')">Save details</button></div>
      </div></div>`+navFooter();
}
function renderUpload(s){
  $("#centerInner").innerHTML=wsHead(s)+`
    <button class="begin-feature" onclick="qStartUpload()">
      <span class="bc-ico">⤓</span>
      <div><h4>Upload your text data</h4>
        <p>CSV, Excel, or a Qualtrics / Google Forms export. ReliCheck reads each open-ended column and splits responses into segments you can code.</p>
        <span class="bc-go">Choose a file →</span></div>
    </button>
    <div class="begin-loaded"><span class="dot"></span><span class="bl-k">Loaded</span>
      <span style="font-weight:600">teacher_pulse_2026.csv</span>
      <span style="color:var(--text-3);margin-left:auto">214 responses · 480 open-ended segments</span></div>`+navFooter();
}
function renderColumnSetup(s){
  const rows=SAMPLE.columns.map(c=>{
    const tag=c.kind==='open'?'<span class="tt-status ok">Text to analyze</span>':c.kind==='group'?'<span class="ov-chip">Grouping</span>':'<span class="ov-chip">Identifier</span>';
    return `<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${esc(c.role)}</td><td>${tag}</td></tr>`;
  }).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-cards">
      <div class="dm-card"><div class="dm-card-k">Columns</div><div class="dm-card-v">5</div></div>
      <div class="dm-card"><div class="dm-card-k">Open-ended</div><div class="dm-card-v">2</div></div>
      <div class="dm-card"><div class="dm-card-k">Grouping</div><div class="dm-card-v">2</div></div>
      <div class="dm-card"><div class="dm-card-k">Segments</div><div class="dm-card-v">480</div></div>
    </div>
    <div class="panel"><div class="panel-h"><div><h3>Confirm what each column is</h3><div class="ph-sub">ReliCheck analyzes the text columns; grouping columns describe the respondent</div></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Column</th><th class="l">Detected role</th><th class="l">Use</th></tr></thead>
        <tbody>${rows}</tbody></table></div>
        <div class="dm-save" style="position:static;margin-top:14px"><button class="btn primary" onclick="toast('Roles confirmed (prototype)')">Confirm columns</button><span class="dm-note">Two open-ended columns will be split into segments.</span></div>
      </div></div>`+navFooter();
}
function renderCleaning(s){
  const rows=SAMPLE.pii.map(p=>`
    <div class="dq-row"><div class="dq-ico">!</div>
      <div class="dq-body"><div class="dq-name">${esc(p.kind)} · ${esc(p.found)}</div><div class="dq-risk">${esc(p.example)}</div></div>
      <button class="btn" onclick="toast('Masked (prototype)')" style="padding:6px 13px;font-size:12.5px">Mask</button></div>`).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dq-card">${rows}</div>
    <div class="run-actions"><button class="btn primary" onclick="toast('All personal information masked (prototype)')">Mask all flagged</button>
      <button class="btn" onclick="toast('Re-scanned (prototype)')">Re-scan</button></div>
    <div class="dx-layers" style="margin-top:18px"><div class="dx-l"><div class="dx-l-k">Why this matters</div>
      <div class="dx-l-t">Masking personal details before anyone reads or co-codes the data protects respondents and keeps your study shareable.</div></div></div>`+navFooter();
}
function renderFamiliarization(s){
  const chips=SAMPLE.concepts.map(c=>`<span class="ov-chip">${esc(c.label)} · ${c.n}</span>`).join(' ');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>First impressions</h3><div class="ph-sub">Capture what stands out before formal coding</div></div></div>
      <div class="panel-b"><textarea class="ed-in" rows="4" placeholder="What patterns, surprises, or tensions are you noticing on a first read?">Workload and time come up constantly, but so does the buffering effect of supportive colleagues. Worth watching whether recognition moderates burnout.</textarea>
        <div class="run-actions" style="margin-top:14px"><button class="btn primary" onclick="toast('Memo saved (prototype)')">Save memo</button></div></div></div>
    <div class="panel"><div class="panel-h"><div><h3>Recurring concepts</h3><div class="ph-sub">✦ Surfaced by ReliCheck Intelligence from your corpus</div></div></div>
      <div class="panel-b">${chips}<div class="dm-note" style="margin-top:10px">These are starting points, not codes. You decide which become part of the codebook.</div></div></div>`+navFooter();
}
function renderCoding(s){
  const segs=SAMPLE.segments.map(seg=>{
    const codes=seg.codes.map(c=>`<span class="code-chip">${esc(c)} <span class="x" onclick="toast('Removed (prototype)')">✕</span></span>`).join('');
    return `<div class="seg-card"><div class="seg-meta">${esc(seg.ref)}</div><div class="seg-text">${esc(seg.text)}</div>
      <div>${codes}<button class="btn" style="padding:5px 11px;font-size:12px" onclick="toast('Code picker (prototype)')">＋ Add code</button>
      <button class="btn-str" style="margin-left:6px" onclick="toast('ReliCheck Intelligence suggested 2 codes (prototype)')">✦ Suggest codes</button></div></div>`;
  }).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-cards">
      <div class="dm-card"><div class="dm-card-k">Segments</div><div class="dm-card-v">480</div></div>
      <div class="dm-card"><div class="dm-card-k">Coded</div><div class="dm-card-v">312</div></div>
      <div class="dm-card"><div class="dm-card-k">Remaining</div><div class="dm-card-v">168</div></div>
      <div class="dm-card"><div class="dm-card-k">Codes</div><div class="dm-card-v">8</div></div>
    </div>
    <div class="seg-scroll">${segs}</div>`+navFooter();
}
function renderCodebook(s){
  const rows=SAMPLE.codes.map(c=>`<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${esc(c.def)}</td><td>${c.n}</td></tr>`).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Codebook</h3><div class="ph-sub">8 codes · each with a definition and applied count</div></div>
      <button class="btn" style="margin-left:auto;padding:6px 12px;font-size:12.5px" onclick="toast('New code (prototype)')">＋ New code</button></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Code</th><th class="l">Definition</th><th>Applied</th></tr></thead>
        <tbody>${rows}</tbody></table></div></div></div>`+navFooter();
}
function renderDual(s){
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-cards">
      <div class="dm-card"><div class="dm-card-k">Cohen's κ</div><div class="dm-card-v">0.78</div></div>
      <div class="dm-card"><div class="dm-card-k">Agreement</div><div class="dm-card-v">86%</div></div>
      <div class="dm-card"><div class="dm-card-k">Double-coded</div><div class="dm-card-v">96</div></div>
      <div class="dm-card"><div class="dm-card-k">Disagreements</div><div class="dm-card-v">13</div></div>
    </div>
    <div class="panel"><div class="panel-h"><div><h3>Agreement by code</h3><div class="ph-sub">Where you and your second coder line up</div></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Code</th><th>Both applied</th><th>Only one</th><th class="l">Agreement</th></tr></thead>
        <tbody>
          <tr><td class="dx-name">Time pressure</td><td>38</td><td>4</td><td><span class="tt-status ok">Strong</span></td></tr>
          <tr><td class="dx-name">Lack of training</td><td>15</td><td>6</td><td><span class="tt-status rev">Review</span></td></tr>
          <tr><td class="dx-name">Feeling valued</td><td>16</td><td>1</td><td><span class="tt-status ok">Strong</span></td></tr>
        </tbody></table></div>
        <div class="dm-save" style="position:static;margin-top:14px"><button class="btn primary" onclick="toast('Invite link copied (prototype)')">Invite a second coder</button><span class="dm-note">κ = 0.78 is substantial agreement. Resolve the 13 disagreements to lift it.</span></div>
      </div></div>`+navFooter();
}
function renderCategories(s){
  const cards=SAMPLE.categories.map(cat=>{
    const codes=cat.codes.map(c=>`<span class="code-chip">${esc(c)}</span>`).join('');
    return `<div class="cat-card"><h4>${esc(cat.name)}</h4><div class="cat-sub">${esc(cat.sub)}</div>${codes}</div>`;
  }).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-note" style="margin-bottom:14px">Three categories group your 8 codes. Drag-free: use each code's menu to move it. (Prototype shows the grouped result.)</div>
    ${cards}
    <div class="run-actions"><button class="btn" onclick="toast('New category (prototype)')">＋ New category</button></div>`+navFooter();
}
function renderThemes(s){
  const cards=SAMPLE.themes.map(t=>`
    <div class="panel"><div class="panel-b">
      <div style="display:flex;align-items:flex-start;gap:14px">
        <div style="flex:1;min-width:0">
          <div style="font-size:15.5px;font-weight:700;letter-spacing:-.15px;margin-bottom:8px">${esc(t.name)}</div>
          <div class="th-bar"><i style="width:${t.cov}%"></i></div>
          <div style="display:flex;gap:14px;margin-top:8px;align-items:center">
            <span class="th-cov">${t.cov}% coverage</span>
            <span class="th-sent"><b class="pos">${t.pos}%</b> + · <b class="neu">${t.neu}%</b> ~ · <b class="neg">${t.neg}%</b> −</span>
            <span class="th-sent">${t.n} segments</span>
          </div>
        </div>
        <button class="btn-str" onclick="qSaveSampleToReport('${esc(t.name)}')">＋ Save to report</button>
      </div></div></div>`).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-note" style="margin-bottom:16px">Four themes carry the analysis. Coverage is the share of respondents whose segments touch the theme; sentiment is the mix within it.</div>
    ${cards}`+navFooter();
}
function renderQuotes(s){
  let html=wsHead(s);
  SAMPLE.themes.forEach(t=>{
    const qs=SAMPLE.quotes[t.name];
    if(!qs) return;
    const quotes=qs.map(q=>`<div class="th-quote"><div class="th-quote-t">“${esc(q.t)}”</div><div class="th-quote-m"><span>${esc(q.m)}</span><span>· pinned</span></div></div>`).join('');
    html+=`<div class="ws-tool-head" style="margin-top:18px"><span class="ws-dot qual"></span><h4>${esc(t.name)}</h4><span class="ws-tag">${qs.length} pinned</span></div>
      <div class="th-quotes">${quotes}</div>`;
  });
  html+=`<div class="dx-next"><div class="dx-next-k">↳ Tip</div><div class="dx-next-t">Pin <b>one or two</b> vivid quotes per theme. More than that dilutes the evidence.</div></div>`;
  $("#centerInner").innerHTML=html+navFooter();
}
function renderTrustworthiness(s){
  const rows=SAMPLE.trust.map(t=>`<tr><td class="dx-name">${esc(t.k)}</td>
    <td>${t.status==='Pass'?'<span class="tt-status ok">Documented</span>':'<span class="tt-status rev">Review</span>'}</td>
    <td class="dx-interp">${esc(t.note)}</td></tr>`).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Trustworthiness record</h3><div class="ph-sub">The four criteria reviewers look for (Lincoln &amp; Guba)</div></div></div>
      <div class="panel-b"><div class="dx-scroll"><table class="dx-table">
        <thead><tr><th class="l">Criterion</th><th class="l">Status</th><th class="l">How you addressed it</th></tr></thead>
        <tbody>${rows}</tbody></table></div></div></div>
    <div class="dx-layers"><div class="dx-l dx-caution"><div class="dx-l-k">One item to resolve</div>
      <div class="dx-l-t">Transferability is marked Review — add a richer description of the school context so readers can judge how far the findings travel.</div></div></div>`+navFooter();
}
function renderReport(s){
  const secs=[
    ['Introduction','This study asked what helps and hinders teachers in sustaining their work, drawing on 214 open-ended responses analyzed with reflexive thematic analysis.'],
    ['Methods','Responses were segmented and coded against an 8-code codebook. Twenty percent were double-coded (Cohen\'s κ = 0.78). Codes were grouped into three categories and four themes.'],
    ['Findings','Four themes emerged. Workload outpaces the support around it (62% coverage) dominated, while peer relationships and recognition consistently buffered strain.'],
    ['Trustworthiness','Credibility, dependability, and confirmability were documented through dual coding, a versioned codebook, and an audit trail linking themes to quotes.'],
  ];
  const cards=secs.map(([title,body])=>`
    <div class="panel"><div class="panel-b">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><div style="font-size:15px;font-weight:700">${esc(title)}</div>
        <span class="tt-status ok">Drafted</span></div>
      <textarea class="ed-in" rows="3" style="margin-top:8px">${esc(body)}</textarea>
      <div class="dm-save" style="position:static"><button class="btn primary" onclick="toast('Section saved (prototype)')">Save section</button>
        <button class="btn" onclick="toast('✦ ReliCheck Intelligence drafted this section (prototype)')">✦ Generate with ReliCheck Intelligence</button></div>
    </div></div>`).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-save" style="position:static;margin-bottom:14px"><button class="btn primary" onclick="toast('Built full report (prototype)')">✦ Build the full report</button>
      <span class="dm-note">Drafts every section from your themes and evidence. Edit any of them below.</span></div>
    ${cards}
    <div class="panel"><div class="panel-h"><div><h3>Download</h3><div class="ph-sub">Includes your edits</div></div></div>
      <div class="panel-b"><div class="run-actions"><button class="btn primary" onclick="toast('Word export (prototype)')">⬇ Download Word</button>
        <button class="btn" onclick="toast('Markdown export (prototype)')">⬇ Download Markdown</button></div></div></div>`+navFooter();
}

/* ════════ RENDER DISPATCH ════════ */
function renderCenter(){
  const s=activeStep();
  const map={start:renderStart,setup:renderSetup,upload:renderUpload,datamap:renderColumnSetup,
    cleaning:renderCleaning,familiarization:renderFamiliarization,coding:renderCoding,codebook:renderCodebook,
    dual:renderDual,categories:renderCategories,themes:renderThemes,quotes:renderQuotes,
    trustworthiness:renderTrustworthiness,report:renderReport};
  (map[s.id]||renderStart)(s);
}

/* ════════ CHROME: topbar rail, sidebar phase map, coach ════════ */
function renderTopbarSteps(){
  const el=$("#topbarSteps"); if(!el) return;
  const ss=steps(), act=activeStep(); let html='';
  ss.forEach((s,i)=>{
    const isDone=s.done, isAct=s.id===act.id;
    const cls=isDone?'done':isAct?'active':'';
    const inner=isDone?'&#x2713;':s.n;
    html+=`<div class="tb-step ${cls}" onclick="goStep('${s.id}')" title="${esc(s.label)}">
      <div class="tb-node">${inner}${isAct?`<span class="tb-node-label">${esc(s.label)}</span>`:''}</div></div>`;
    if(i<ss.length-1) html+=`<div class="tb-connector ${isDone?'done':''}"></div>`;
  });
  el.innerHTML=html;
}
function renderSidebar(){
  const act=activeStep();
  const map=$("#sbPhaseMap"), desc=$("#sbPhaseDesc");
  // which phases are done: a phase is done if all its steps are before completedThrough
  if(map){
    map.innerHTML=PHASES.map(p=>{
      const on=p.key===act.phase;
      const phaseSteps=steps().filter(s=>s.phase===p.key);
      const done=phaseSteps.length>0 && phaseSteps.every(s=>s.done);
      return `<div class="pm-item ${on?'on':''} ${done?'done':''}"><span class="pm-dot"></span>${esc(p.label)}</div>`;
    }).join('');
  }
  if(desc){const p=PHASES.find(p=>p.key===act.phase); desc.textContent=p?p.desc:'';}
}

/* Coach (slide-in) */
let coachAns=[];
function coachData(s){
  const h=HELP[s.id]||{};
  const strip=t=>String(t==null?'':t).replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim();
  const tip=h.what?esc(h.what):esc(s.lede);
  const qs=[],ans=[];
  if(h.measures){qs.push('What does this step give me?');ans.push(strip(h.measures));}
  if(h.use){qs.push('When should I use it?');ans.push(strip(h.use));}
  qs.push('Where am I in the workflow?');ans.push('You are in the '+phaseLabel(s.phase)+' phase, step '+s.n+' of '+steps().length+'.');
  return {tip,qs,ans};
}
function renderCompanion(){
  const s=activeStep(), ss=steps();
  const sub=$("#coachStepLabel"); if(sub) sub.textContent='Step '+s.n+' guidance';
  const cd=coachData(s); coachAns=cd.ans;
  const prompts=cd.qs.map((q,i)=>`<button class="coach-prompt" onclick="showCoachAnswer(${i})">${esc(q)}</button>`).join('');
  $("#compBody").innerHTML=`
    <div class="coach-context-chip">
      <svg viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.3"/><line x1="6" y1="5" x2="6" y2="8.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="6" cy="3.5" r=".6" fill="currentColor"/></svg>
      Step ${s.n} of ${ss.length}</div>
    <div class="coach-section"><div class="coach-section-label">Guidance</div><div class="coach-tip">${cd.tip}</div></div>
    <div class="coach-divider"></div>
    <div class="coach-section"><div class="coach-section-label">Common questions</div>
      <div class="coach-prompt-list">${prompts}</div><div class="coach-answer" id="coachAnswer"></div></div>`;
}
function coachType(el,text){el.textContent='';el.classList.add('visible');let pos=0;clearInterval(window._cty);
  window._cty=setInterval(()=>{if(pos<text.length){el.textContent+=text[pos++];}else{clearInterval(window._cty);}},10);}
function showCoachAnswer(i){const ans=$("#coachAnswer");document.querySelectorAll('.coach-prompt').forEach((p,idx)=>p.classList.toggle('active',idx===i));if(ans)coachType(ans,coachAns[i]||'');}
function handleCoachInput(e){if(e.key==='Enter')handleCoachSend();}
function handleCoachSend(){const input=$("#coachInput");if(!input)return;const v=input.value.trim();if(!v)return;
  const ans=$("#coachAnswer");const s=activeStep();document.querySelectorAll('.coach-prompt').forEach(p=>p.classList.remove('active'));
  if(ans)coachType(ans,'In the full build, ReliCheck Intelligence answers from your own data. For now, the questions above cover '+(s.title||'this step')+'.');input.value='';}
function toggleCoach(){
  const opening=!document.body.classList.contains('coach-open');
  if(opening){$("#rptDrawer").classList.remove('open');$("#rptScrim").classList.remove('open');document.body.classList.remove('report-open');}
  document.body.classList.toggle('coach-open',opening);
  if(opening)renderCompanion();
}

/* Navigation */
function goStep(id){state.stepId=id;render();const c=$(".center");if(c)c.scrollTop=0;qUpdateNotes();}
function stepBy(dir){const s=steps();const i=s.findIndex(x=>x.id===activeStep().id);const ni=Math.max(0,Math.min(s.length-1,i+dir));
  state.stepId=s[ni].id;if(dir>0&&ni+1>state.completedThrough)state.completedThrough=ni;render();const c=$(".center");if(c)c.scrollTop=0;qUpdateNotes();}

/* Notes (per step) */
const qNotes={};let qNoteTimer=null;
document.addEventListener('input',e=>{
  if(e.target.id!=='researcherNotes')return;
  const act=activeStep();qNotes[act.id]=e.target.value;
  const saved=$("#nbSaved"),addBtn=$("#btnNoteRpt");
  if(saved)saved.classList.remove('vis');
  if(addBtn)addBtn.classList.toggle('vis',e.target.value.trim().length>0);
  clearTimeout(qNoteTimer);qNoteTimer=setTimeout(()=>{if(saved){saved.classList.add('vis');setTimeout(()=>saved.classList.remove('vis'),2000);}},800);
});
function qUpdateNotes(){
  const ta=$("#researcherNotes"),tag=$("#nbStepTag"),addBtn=$("#btnNoteRpt"),act=activeStep();
  if(ta){ta.value=qNotes[act.id]||'';ta.placeholder='Jot observations for '+esc(act.label)+'…';}
  if(tag)tag.textContent='Step '+act.n;
  if(addBtn)addBtn.classList.toggle('vis',!!(ta&&ta.value.trim()));
}
function qSaveProject(){
  const btn=$("#saveProjectBtn");if(!btn)return;btn.classList.add('saved');
  btn.innerHTML='<svg viewBox="0 0 16 16" fill="none"><polyline points="3,8 6.5,12 13,4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg> Saved';
  setTimeout(()=>{btn.classList.remove('saved');btn.innerHTML='<svg viewBox="0 0 16 16" fill="none"><path d="M13 13H3a1 1 0 0 1-1-1V3l2-1h7l2 2v8a1 1 0 0 1-1 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><rect x="5" y="9" width="6" height="4" rx=".5" stroke="currentColor" stroke-width="1.4"/><rect x="5.5" y="2" width="4" height="3" rx=".5" stroke="currentColor" stroke-width="1.4"/></svg> Save';},2200);
}

/* Report drawer (prototype: in-memory) */
const qReport={sections:[]};
function qUpdateReportCount(){const n=qReport.sections.length;const b1=$("#rptCountBadge"),b2=$("#rptBadgeDrawer");
  if(b1){b1.style.display=n>0?'inline':'none';b1.textContent=n;}if(b2)b2.textContent=n+(n===1?' section':' sections');}
function qRenderReport(){
  const body=$("#rptBody"),empty=$("#rptEmpty");if(!body)return;
  body.querySelectorAll('.rpt-finding').forEach(el=>el.remove());
  if(!qReport.sections.length){if(empty)empty.style.display='flex';return;}
  if(empty)empty.style.display='none';
  qReport.sections.forEach(sec=>{const el=document.createElement('div');el.className='rpt-finding';
    el.innerHTML=`<div class="rpt-step-tag">${esc(sec.tag)}</div><div class="rpt-finding-body">${esc(sec.body)}</div>`;body.appendChild(el);});
}
function toggleRptDrawer(){
  const opening=!$("#rptDrawer").classList.contains('open');
  if(opening)document.body.classList.remove('coach-open');
  $("#rptDrawer").classList.toggle('open',opening);$("#rptScrim").classList.toggle('open',opening);
  document.body.classList.toggle('report-open',opening);
  if(opening)qRenderReport();
}
function qSaveSampleToReport(name){qReport.sections.push({tag:'Theme',body:name});qUpdateReportCount();toast('Saved to report (prototype)');if($("#rptDrawer").classList.contains('open'))qRenderReport();}
function qSaveNoteToReport(){
  const ta=$("#researcherNotes");if(!ta||!ta.value.trim()){toast('Write a note first.');return;}
  const act=activeStep();qReport.sections.push({tag:'Note · '+act.label,body:ta.value.trim()});qUpdateReportCount();toast('Note added to report (prototype)');
  const btn=$("#btnNoteRpt");if(btn){btn.style.color='var(--mm)';btn.innerHTML='<svg viewBox="0 0 12 12" fill="none"><polyline points="1,6 4.5,10 11,2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Added';
    setTimeout(()=>{btn.style.color='';btn.innerHTML='<svg viewBox="0 0 12 12" fill="none"><line x1="6" y1="1" x2="6" y2="11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="1" y1="6" x2="11" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg> Add to Report';},2000);}
}
function qExportReport(){toggleRptDrawer();goStep('report');}

/* Upload (real shared widget) */
function qStartUpload(){
  if(!window.DatasetUpload){toast('Upload widget not loaded.');return;}
  DatasetUpload.open({projectType:'qual',projectId:BOOT.projectId||0,onLoaded:function(_e,pid){go('?project_id='+encodeURIComponent(pid)+'&step=datamap');}});
}

/* Master render */
function render(){renderCenter();renderTopbarSteps();renderSidebar();renderCompanion();}
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.body.classList.contains('coach-open'))toggleCoach();});

/* ── Uniform studio header + footer (shared plug-ins) ── */
if(typeof StudioHeader!=='undefined'){
  StudioHeader.init({
    logoSrc:'/QA-Studio-long.png', logoAlt:'Qualitative Studio',
    projectLabel:BOOT.projectLabel, projectLive:BOOT.projectId>0,
    projectsUrl:'/qual-studio.php', initials:'<?= htmlspecialchars($initials) ?>'
  });
}
if(typeof StudioFooter!=='undefined'){ StudioFooter.init(); }

/* Boot */
qUpdateReportCount();
render();
qUpdateNotes();
</script>
</body>
</html>
