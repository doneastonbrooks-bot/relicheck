<?php
// qual-studio-workspaceV4.php — Qualitative Studio, 2026 MM-mirrored shell.
//
// Mirrors mmstudioV4.php's infrastructure wholesale: the 4-row CSS grid
// (RC header / 76px topbar / sidebar+main / footer), the horizontal top step
// rail, the left sidebar, the slide-in ReliCheck Coach, and the Report drawer.
// Accent is Qual's forest green (per the per-studio accent convention); the
// structure is MM's.
//
// All 14 steps are wired to the same /api/qual endpoints the live V3 uses.
// Built prototype-first (sample data), then wired chunk by chunk and verified
// on project 13. The landing page (qual-studio.php) still points at V3 until
// this is flipped over.

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
$QUAL_STEPS = [
    ['id'=>'start',           'label'=>'Start',        'eyebrow'=>'New project',                   'mode'=>'start',  'title'=>'Start',            'lede'=>'Bring in your qualitative data, or open a saved project to begin.'],
    ['id'=>'setup',           'label'=>'Setup',        'eyebrow'=>'Setup · frame the study',        'mode'=>'form',   'title'=>'Project Setup',    'lede'=>'Name your study and frame the research question your analysis will answer.'],
    ['id'=>'upload',          'label'=>'Data',         'eyebrow'=>'Data · bring it in',             'mode'=>'upload', 'title'=>'Data Entry',       'lede'=>'Upload interview transcripts, open-ended survey responses, or documents to analyze.'],
    ['id'=>'datamap',         'label'=>'Columns',      'eyebrow'=>'Data map · organize first',      'mode'=>'datamap','title'=>'Column Setup',     'lede'=>'Tell ReliCheck which columns hold the text to analyze and which describe the respondent.'],
    ['id'=>'cleaning',        'label'=>'Clean',        'eyebrow'=>'Data quality · before you code', 'mode'=>'work',   'title'=>'Data Cleaning',    'lede'=>'Review your text and mask any personal information before coding.'],
    ['id'=>'familiarization', 'label'=>'Familiarize',  'eyebrow'=>'Familiarization · read first',    'mode'=>'work',   'title'=>'Familiarization',  'lede'=>'Read through the data and capture first impressions before formal coding.'],
    ['id'=>'coding',          'label'=>'Code',         'eyebrow'=>'Coding · the core pass',         'mode'=>'work',   'title'=>'Coding Workspace', 'lede'=>'Work through each segment and apply codes that capture what it is about.'],
    ['id'=>'codebook',        'label'=>'Codebook',     'eyebrow'=>'Codebook · stay consistent',     'mode'=>'work',   'title'=>'Codebook Builder', 'lede'=>'Define each code with a clear description and inclusion rules so coding stays consistent.'],
    ['id'=>'dual',            'label'=>'Dual Coder',   'eyebrow'=>'Reliability · second coder',     'mode'=>'work',   'title'=>'Dual Coder',       'lede'=>'Invite a second coder and compare where you agree and disagree.'],
    ['id'=>'categories',      'label'=>'Categories',   'eyebrow'=>'Structure · group codes',        'mode'=>'work',   'title'=>'Category Builder', 'lede'=>'Group related codes into higher-level categories.'],
    ['id'=>'themes',          'label'=>'Themes',       'eyebrow'=>'Findings · build themes',        'mode'=>'work',   'title'=>'Theme Builder',    'lede'=>'Build themes from your categories and see how widely each is supported.'],
    ['id'=>'quotes',          'label'=>'Quotes',       'eyebrow'=>'Evidence · quotes by theme',     'mode'=>'work',   'title'=>'Quote Finder',     'lede'=>'Pin the most representative quotes to each theme as evidence.'],
    ['id'=>'trustworthiness', 'label'=>'Trust',        'eyebrow'=>'Rigor · trustworthiness',        'mode'=>'output', 'title'=>'Trustworthiness',  'lede'=>'Document the steps that make your analysis credible and dependable.'],
    ['id'=>'report',          'label'=>'Report',       'eyebrow'=>'Output · assemble the report',   'mode'=>'output', 'title'=>'Report & Export',  'lede'=>'Assemble your themes, evidence, and trustworthiness into a shareable report.'],
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

// Honor ?step= deep links (e.g. the upload widget redirects to &step=datamap).
$validStepIds = array_column($QUAL_STEPS, 'id');
$initialStep  = (isset($_GET['step']) && in_array($_GET['step'], $validStepIds, true)) ? (string)$_GET['step'] : null;

$BOOT = [
    'projectId'    => $projectId,
    'projectLabel' => $projLabel,
    'projectLive'  => $projectId > 0,
    'projectsUrl'  => '/qual-studio.php',
    'projectType'  => 'qual',
    'initials'     => $initials,
    'isSample'     => $projectId === 0,
    'project'      => $projectRow ?: null,   // full qual_projects row for Setup pre-fill
    'initialStep'  => $initialStep,
];
$QUAL = ['steps' => $QUAL_STEPS, 'help' => $QUAL_HELP];

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
.btn-export svg,.btn-export-report svg{width:14px;height:14px;flex-shrink:0;}
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
/* ── Qual V4 overrides ── */
/* Step rail uses MM Studio's default node + connector spacing (no tightening). */
/* Workstation canvas: one solid white (not MM's faint gray). Panels keep their
   white fill + hairline border + soft shadow, so cards still read. */
.main,.center{background:var(--surface);}
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
<script src="/apps/studio/contextual-lens.js?v=<?= _qsv4('/apps/studio/contextual-lens.js') ?>"></script>
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

  <!-- Sidebar (row 3, col 1): Researcher's Notes (mirrors MM's sidebar) -->
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
const STEPS = QUAL.steps, HELP = QUAL.help;

const $=s=>document.querySelector(s);
function esc(s){return (s==null?"":String(s)).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
function go(u){window.location.href=u;}
let tT;function toast(m){const t=$("#toast");t.textContent=m;t.classList.add('show');clearTimeout(tT);tT=setTimeout(()=>t.classList.remove('show'),1800);}

const state={stepId:(BOOT.initialStep||STEPS[0].id),completedThrough:0,notes:{}};
function buildSteps(){return STEPS.map((s,i)=>Object.assign({},s,{n:i+1,done:i<state.completedThrough}));}
function steps(){return buildSteps();}
function activeStep(){return steps().find(s=>s.id===state.stepId)||steps()[0];}

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
  return '<div class="ws-header"><div class="eyebrow"><span class="eyebrow-dot"></span>'+esc(s.eyebrow||('Step '+s.n))
    +' <span class="strand-chip qual">QUAL</span></div>'
    +'<h1 class="title">'+esc(s.title)+'</h1><p class="lede">'+esc(s.lede)+'</p></div>';
}


/* ════════ STEP RENDERERS (live data) ════════ */
/* api helper (mirrors V3's api(): throws on !ok) */
function qapi(path,opts){opts=opts||{};return fetch(path,Object.assign({credentials:'same-origin',headers:{'Content-Type':'application/json'}},opts)).then(r=>r.json()).then(d=>{if(!d.ok)throw new Error(d.message||d.error||'Request failed');return d;});}

/* ── Step 1 · Start — real saved-project list (api/qual/list-projects.php) ── */
function renderStart(s){
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Your saved projects</h3><div class="ph-sub">Open a qualitative study already in ReliCheck</div></div>
      <a class="ov-link" href="/qual-studio.php" style="margin-left:auto">View all →</a></div>
      <div class="panel-b" id="stProjBody"><div class="work-surface">Loading your projects…</div></div></div>
    <button class="begin-feature" onclick="qStartUpload()">
      <span class="bc-ico">⤓</span>
      <div><h4>Bring in your data</h4>
        <p>Have interview transcripts or open-ended survey responses? Upload a CSV, Excel, or document file to create a project and split it into analyzable segments, then come back to code.</p>
        <span class="bc-go">Upload data →</span></div>
    </button>
    <div class="begin-sec">Or start another way</div>
    <div class="begin-grid2">
      <button class="begin-card2" onclick="go('/qual-studio.php')"><span class="bc-ico">▦</span><h4>All projects</h4><p>Go to your full qualitative projects list.</p><span class="bc-go">Open projects →</span></button>
      <button class="begin-card2" onclick="goStep('setup')"><span class="bc-ico">✎</span><h4>Set up this study</h4><p>Name the study and frame its research question.</p><span class="bc-go">Project setup →</span></button>
      <button class="begin-card2" onclick="go('/mmstudioV4.php')"><span class="bc-ico">⇄</span><h4>Mixed methods?</h4><p>Pair this text with survey numbers in MM Studio.</p><span class="bc-go">Go to MM →</span></button>
    </div>`;
  fetch('/api/qual/list-projects.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='start')return;
    const body=$("#stProjBody"); if(!body)return;
    if(!d.ok||!d.projects||!d.projects.length){body.innerHTML='<div class="work-surface">No saved projects yet. Upload data to begin.</div>';return;}
    const opts=d.projects.map(p=>{const m=[];if(p.seg_count)m.push(p.seg_count+' segments');if(p.code_count)m.push(p.code_count+' codes');return `<option value="${p.id}" ${p.id===BOOT.projectId?'selected':''}>${esc(p.title||'Untitled')}${m.length?' — '+esc(m.join(', ')):''}</option>`;}).join('');
    body.innerHTML=`<div style="display:flex;gap:10px;align-items:center"><select class="ed-in" id="stProjSel" style="flex:1">${opts}</select><button class="btn primary" onclick="qOpenProject()">Open →</button></div>`;
  }).catch(()=>{if(activeStep().id==='start'){const b=$("#stProjBody");if(b)b.innerHTML='<div class="work-surface">Could not load projects.</div>';}});
}
function qOpenProject(){const sel=$("#stProjSel");if(sel&&sel.value)go('?project_id='+sel.value);}

/* ── Step 2 · Setup — real project metadata + project-level Contextual Lens ── */
function renderSetup(s){
  const p=BOOT.project||{};
  const approaches=[['thematic','Thematic Analysis'],['content','Content Analysis'],['framework','Framework Analysis'],['open_ended_survey','Open-Ended Survey Analysis'],['document','Document Analysis']];
  const dtypes=[['open_ended_survey','Open-Ended Survey'],['interview','Interview Transcript'],['focus_group','Focus Group Transcript'],['document','Document / Field Notes']];
  const sel=(id,cur,opts)=>`<select class="ed-in" id="${id}">${opts.map(o=>`<option value="${o[0]}" ${(cur||opts[0][0])===o[0]?'selected':''}>${esc(o[1])}</option>`).join('')}</select>`;
  const hasCL=(typeof ContextualLens!=='undefined');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Project information</h3><div class="ph-sub">These frame every coding decision</div></div></div>
      <div class="panel-b">
        <label class="ed-l">Project title</label>
        <input class="ed-in" id="suTitle" value="${esc(p.title||BOOT.projectLabel||'')}" placeholder="e.g. Marketing Research Open-Ends">
        <div class="form-grid" style="margin:14px 0 0">
          <div><label class="ed-l">Analysis approach</label>${sel('suApproach',p.analysis_approach,approaches)}</div>
          <div><label class="ed-l">Data type</label>${sel('suDatatype',p.data_type,dtypes)}</div>
        </div>
        <label class="ed-l">Research question</label>
        <input class="ed-in" id="suRq" value="${esc(p.research_question||'')}" placeholder="What are participants saying about…">
        <label class="ed-l">Purpose</label>
        <input class="ed-in" id="suPurpose" value="${esc(p.purpose||'')}" placeholder="To inform the 2026 decision…">
        <label class="ed-l">Researcher stance memo</label>
        <textarea class="ed-in" id="suStance" rows="3" placeholder="What assumptions or roles might shape how you read this data?">${esc(p.researcher_stance_memo||'')}</textarea>
      </div></div>
    ${hasCL?ContextualLens.panel('project',p,'su_cl_'):''}
    <div class="dm-save" style="position:static"><button class="btn primary" onclick="qSaveSetup()">Save setup</button><span class="dm-note" id="suMsg">Saves to your project.</span></div>`+navFooter();
}
function qSaveSetup(){
  const msg=$("#suMsg");
  const body={title:$("#suTitle").value.trim(),analysis_approach:$("#suApproach").value,data_type:$("#suDatatype").value,research_question:$("#suRq").value.trim(),purpose:$("#suPurpose").value.trim(),researcher_stance_memo:$("#suStance").value.trim()};
  if(typeof ContextualLens!=='undefined')Object.assign(body,ContextualLens.gather('project','su_cl_'));
  if(!body.title){if(msg){msg.style.color='#c0392b';msg.textContent='Title is required.';}return;}
  if(msg){msg.style.color='';msg.textContent='Saving…';}
  const req=BOOT.projectId
    ?qapi('/api/qual/save-project.php',{method:'POST',body:JSON.stringify(Object.assign({project_id:BOOT.projectId},body))})
    :qapi('/api/qual/create-project.php',{method:'POST',body:JSON.stringify(body)}).then(d=>{BOOT.projectId=d.project_id;history.replaceState({},'','?project_id='+d.project_id+'&step=setup');});
  req.then(()=>{BOOT.project=Object.assign(BOOT.project||{},body);if(msg){msg.style.color='var(--mm-ink)';msg.textContent='Saved.';}toast('Setup saved');}).catch(e=>{if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});
}

/* ── Step 3 · Upload — real shared widget; show real linked state ── */
function renderUpload(s){
  const linked=BOOT.projectId>0;
  $("#centerInner").innerHTML=wsHead(s)+`
    <button class="begin-feature" onclick="qStartUpload()">
      <span class="bc-ico">⤓</span>
      <div><h4>Upload your text data</h4>
        <p>CSV, Excel, or a Qualtrics / Google Forms export. ReliCheck reads each open-ended column and splits responses into segments you can code.</p>
        <span class="bc-go">Choose a file →</span></div>
    </button>
    ${linked?`<div class="begin-loaded"><span class="dot"></span><span class="bl-k">Linked</span><span style="font-weight:600">${esc(BOOT.projectLabel)}</span><span style="color:var(--text-3);margin-left:auto" id="upMeta">Loading data summary…</span></div>`:''}`+navFooter();
  if(linked){
    fetch('/api/qual/get-project.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(activeStep().id!=='upload')return;const el=$("#upMeta");if(!el)return;const st=(d&&d.stats)||{};
      el.textContent=(st.doc_count||0)+' source'+((st.doc_count||0)!==1?'s':'')+' · '+(st.seg_count||0)+' segments';
    }).catch(()=>{const el=$("#upMeta");if(el)el.textContent='';});
  }
}

/* ── Step 4 · Column Setup — real get-variable-meta → save-column-roles ── */
const QUAL_ROLES=[['open_ended','Code this','Open-ended response — each cell becomes a coded segment'],['participant_id','Participant ID','Links segments back to the same person across questions'],['participant_info','Participant context','Attaches to every segment as background (age, region, role, etc.)'],['skip','Skip','Exclude this column from the analysis entirely']];
function qColDefaultRole(v){if(v.qual_role)return v.qual_role;const at=v.analysis_type||'';if(at==='open_ended'||at==='narrative')return 'open_ended';if(at==='identifier')return 'participant_id';return 'participant_info';}
function renderColumnSetup(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet. Upload data from Start.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading columns…</div>`+navFooter();
  fetch('/api/qual/get-variable-meta.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='datamap')return;
    if(!d.ok||!d.variables||!d.variables.length){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No dataset linked yet. Go to <b>Data</b> first to upload.</div>`+navFooter();return;}
    qColForm(s,d.variables);
  }).catch(()=>{if(activeStep().id==='datamap')$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Could not load columns. Refresh and try again.</div>`+navFooter();});
}
function qColForm(s,variables){
  const roleSel=(name,def)=>`<select class="ed-in qcol-role" data-col="${esc(name)}" style="max-width:230px">${QUAL_ROLES.map(r=>`<option value="${r[0]}" ${r[0]===def?'selected':''}>${esc(r[1])}</option>`).join('')}</select>`;
  const rows=variables.map(v=>{const name=v.name||v.variable_name||'';const def=qColDefaultRole(v);const auto=def==='open_ended'?' <span class="tt-status ok">auto</span>':'';return `<tr><td class="dx-name">${esc(name)}${auto}</td><td>${roleSel(name,def)}</td></tr>`;}).join('');
  const legend=QUAL_ROLES.map(r=>`<div class="dx-l" style="margin-bottom:10px"><div class="dx-l-k">${esc(r[1])}</div><div class="dx-l-t">${esc(r[2])}</div></div>`).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Column roles</h3><div class="ph-sub">${variables.length} columns detected</div></div></div>
      <div class="panel-b"><div class="dx-scroll" style="max-height:420px;overflow-y:auto"><table class="dx-table">
        <thead><tr><th class="l">Column</th><th class="l">Role</th></tr></thead><tbody>${rows}</tbody></table></div>
        <div class="dm-save" style="position:static;margin-top:14px"><button class="btn primary" id="qcsBtn" onclick="qSaveColumnRoles()">Confirm and build segments</button><span class="dm-note" id="qcsMsg">Mark each open-ended question as <b>Code this</b>.</span></div>
      </div></div>
    <div class="dx-layers">${legend}</div>`+navFooter();
}
function qSaveColumnRoles(){
  const btn=$("#qcsBtn"),msg=$("#qcsMsg");
  const sels=[].slice.call(document.querySelectorAll('.qcol-role'));
  const columns=sels.map(x=>({name:x.getAttribute('data-col'),qual_role:x.value}));
  if(!columns.filter(c=>c.qual_role==='open_ended').length){if(msg){msg.style.color='#c0392b';msg.innerHTML='Mark at least one column as <b>Code this</b> to create segments.';}return;}
  if(btn){btn.disabled=true;btn.textContent='Building…';}
  qapi('/api/qual/save-column-roles.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,columns:columns})}).then(r=>{
    const n=r.seg_count||0;
    if(btn){btn.disabled=false;btn.textContent='Confirm and build segments';}
    if(msg){msg.style.color=n>0?'var(--mm-ink)':'#c0392b';msg.textContent=n>0?(n+' segment'+(n!==1?'s':'')+' created. Moving to Data Cleaning…'):'No segments created. Check that your open-ended columns have text.';}
    if(n>0)setTimeout(()=>goStep('cleaning'),1400);
  }).catch(e=>{if(btn){btn.disabled=false;btn.textContent='Confirm and build segments';}if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});
}

/* ── Step 5 · Cleaning — real scan-pii / mask-pii ── */
let qPii=null;
function renderCleaning(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-b">
      <div class="run-actions"><button class="btn primary" onclick="qScanPii()">Scan for personal information</button>
        <button class="btn" onclick="goStep('familiarization')">Skip, continue →</button></div>
      <div id="diBody" style="margin-top:14px"></div>
    </div></div>`+navFooter();
}
function qScanPii(){
  const body=$("#diBody");if(body)body.innerHTML='<div class="work-surface">Scanning segments…</div>';
  fetch('/api/qual/scan-pii.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='cleaning')return;
    if(!d.ok)throw new Error(d.message||'Scan failed.');
    qPii=d;qRenderPii(d);
  }).catch(e=>{if(activeStep().id==='cleaning'){const b=$("#diBody");if(b)b.innerHTML='<div class="work-surface">Scan error: '+esc(e.message)+'</div>';}});
}
function qRenderPii(d){
  const body=$("#diBody");if(!body)return;
  if(d.flag_count===0){body.innerHTML=`<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">Clean</div><div class="dx-l-t">No personal information detected in ${d.total_segments} segments.</div></div></div><div class="run-actions"><button class="btn primary" onclick="goStep('familiarization')">Continue to Familiarization →</button></div>`;return;}
  const tl={email:'Email',phone:'Phone',ssn:'ID number',name_intro:'Name'};
  const rows=d.flagged.map(f=>{const pats=f.patterns.map(p=>`<span class="tt-status rev" style="margin-right:5px">${esc(tl[p.type]||p.type)}: ${esc(p.match)}</span>`).join('');
    return `<div id="qpii-${f.segment_id}" class="dq-row"><div class="dq-body"><div style="margin-bottom:4px">${pats}</div><div class="dq-risk" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(f.original)}</div></div>
      <div style="display:flex;gap:6px;flex:none"><button class="btn" style="padding:5px 11px;font-size:12px" data-mask="${f.segment_id}">Mask</button><button class="btn" style="padding:5px 11px;font-size:12px" data-skip="${f.segment_id}">Skip</button></div></div>`;}).join('');
  body.innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px"><span class="dm-note">${d.flag_count} segment${d.flag_count!==1?'s':''} flagged of ${d.total_segments}</span>
      <div class="run-actions"><button class="btn" onclick="qMaskAll()">Mask all</button><button class="btn primary" onclick="goStep('familiarization')">Continue →</button></div></div>
    <div class="dq-card" id="qpiiList" style="max-height:360px;overflow-y:auto">${rows}</div>`;
  const list=$("#qpiiList");
  if(list)list.addEventListener('click',e=>{const m=e.target.closest('[data-mask]');const sk=e.target.closest('[data-skip]');if(m)qMaskSeg(+m.getAttribute('data-mask'),m);if(sk){const row=document.getElementById('qpii-'+sk.getAttribute('data-skip'));if(row)row.style.opacity='.4';}});
}
function qMaskSeg(sid,btn){if(btn){btn.disabled=true;btn.textContent='Masking…';}
  qapi('/api/qual/mask-pii.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,segment_id:sid})}).then(r=>{const row=document.getElementById('qpii-'+sid);if(row)row.innerHTML='<div class="dq-body"><span class="tt-status ok">Masked</span> <span class="dq-risk">'+esc(r.masked_text)+'</span></div>';}).catch(e=>{if(btn){btn.disabled=false;btn.textContent='Mask';}toast('Could not mask: '+e.message);});}
function qMaskAll(){if(qPii&&qPii.flagged)qPii.flagged.forEach(f=>qMaskSeg(f.segment_id,null));}
/* ── Step 6 · Familiarization — stats, first-impressions memo, AI concept scan ── */
function renderFamiliarization(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="dm-cards" id="famStats"><div class="dm-card"><div class="dm-card-k">Loading…</div><div class="dm-card-v">—</div></div></div>
    <div class="panel"><div class="panel-h"><div><h3>First impressions memo</h3><div class="ph-sub">Saved to the audit trail</div></div></div>
      <div class="panel-b"><div class="dm-note" style="margin-bottom:10px">Before coding, what stands out? What surprises you? What patterns, tensions, or questions do you notice?</div>
        <textarea class="ed-in" id="famMemo" rows="4" placeholder="I noticed several responses mentioned…"></textarea>
        <div class="dm-save" style="position:static;margin-top:10px"><button class="btn primary" onclick="qfamSaveMemo()">Save memo</button><span class="dm-note" id="famMsg"></span></div></div></div>
    <div class="panel"><div class="panel-h"><div><h3>Linguistic concept scan</h3><div class="ph-sub">✦ ReliCheck Intelligence surfaces recurring concepts before you code</div></div></div>
      <div class="panel-b"><div id="scanBody"><button class="btn primary" onclick="qfamScan(true)">Run concept scan</button><div class="dm-note" style="margin-top:8px">Analyzes a sample of your responses; takes 15–30 seconds.</div></div></div></div>`+navFooter();
  fetch('/api/qual/get-project.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='familiarization')return;const st=(d&&d.stats)||{};const el=$("#famStats");if(!el)return;
    el.innerHTML=[['Segments',st.seg_count],['Data sources',st.doc_count],['Total words',st.total_words],['Avg words/seg',st.avg_words],['Codes',st.code_count]].map(x=>`<div class="dm-card"><div class="dm-card-k">${x[0]}</div><div class="dm-card-v">${Number(x[1]||0).toLocaleString()}</div></div>`).join('');
  }).catch(()=>{});
  qapi('/api/qual/concept-scan.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,force:false})}).then(r=>{if(activeStep().id==='familiarization'&&r.from_cache)qfamRenderScan(r);}).catch(()=>{});
}
function qfamSaveMemo(){
  const body=($("#famMemo").value||'').trim();const msg=$("#famMsg");
  if(!body){if(msg){msg.style.color='#c0392b';msg.textContent='Write something before saving.';}return;}
  if(msg){msg.style.color='';msg.textContent='Saving…';}
  qapi('/api/qual/save-memo.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,object_type:'project',memo_type:'first_impressions',title:'First Impressions',body:body})}).then(()=>{if(msg){msg.style.color='var(--mm-ink)';msg.textContent='Memo saved.';}}).catch(e=>{if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});
}
function qfamScan(force){
  const sb=$("#scanBody");if(sb)sb.innerHTML='<div class="work-surface">Analyzing the corpus with ReliCheck Intelligence… this can take 15–30 seconds.</div>';
  qapi('/api/qual/concept-scan.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,force:!!force})}).then(r=>{if(activeStep().id==='familiarization')qfamRenderScan(r);}).catch(e=>{if(sb)sb.innerHTML='<div class="work-surface">Scan failed: '+esc(e.message)+'</div><div class="run-actions" style="margin-top:10px"><button class="btn" onclick="qfamScan(true)">Try again</button></div>';});
}
function qfamRenderScan(r){
  const sb=$("#scanBody");if(!sb)return;
  const cards=(r.concepts||[]).map(c=>{const quotes=(c.example_quotes||[]).map(q=>`<div style="font-size:12px;color:var(--text-2);border-left:3px solid var(--border);padding-left:8px;margin-top:6px;font-style:italic">"${esc(q)}"</div>`).join('');return `<div class="seg-card" style="margin-bottom:0"><div style="display:flex;align-items:center;gap:8px;margin-bottom:4px"><span style="font-weight:700;font-size:13.5px">${esc(c.concept)}</span><span class="strand-chip qual">${esc(c.evidence_type||'')}</span><span class="dm-note" style="margin-left:auto">${c.frequency||'?'} responses</span></div>${quotes}</div>`;}).join('');
  sb.innerHTML=`<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><span class="dm-note">${r.from_cache?'Cached · ':''}${r.segments_scanned||0} segments scanned</span><button class="btn" style="font-size:12px;padding:5px 12px;margin-left:auto" onclick="qfamScan(true)">Re-run</button></div><div style="display:flex;flex-direction:column;gap:10px;max-height:360px;overflow-y:auto">${cards}</div>`;
}
/* ── Step 7 · Coding Workspace — real segments + codes (chunk 2) ── */
const qcw={codes:null,segments:[],uncodedOnly:false,search:''};
function qLoadCodes(){
  if(qcw.codes)return Promise.resolve(qcw.codes);
  return fetch('/api/qual/get-codes.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{qcw.codes=(d&&d.ok&&d.codes)?d.codes:[];return qcw.codes;}).catch(()=>{qcw.codes=[];return qcw.codes;});
}
function renderCoding(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`
    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
      <input class="ed-in" id="segSearch" style="flex:1;min-width:180px" placeholder="Search segments…">
      <button class="btn primary" id="fAll" onclick="qcwFilter(false)">All</button>
      <button class="btn" id="fUn" onclick="qcwFilter(true)">Uncoded only</button>
      <button class="btn" onclick="goStep('codebook')">Manage codebook</button>
    </div>
    <div class="dm-note" id="segCounts" style="margin-bottom:10px">Loading…</div>
    <div id="segList" class="seg-scroll"><div class="work-surface">Loading segments…</div></div>`+navFooter();
  const srch=$("#segSearch"); if(srch)srch.addEventListener('input',function(){qcw.search=this.value;qcwRenderList();});
  const list=$("#segList"); if(list)list.addEventListener('click',qcwListClick);
  qLoadCodes().then(qcwLoad);
}
function qcwFilter(un){qcw.uncodedOnly=un;const a=$("#fAll"),u=$("#fUn");if(a)a.className='btn'+(un?'':' primary');if(u)u.className='btn'+(un?' primary':'');qcwLoad();}
function qcwLoad(){
  const qs='project_id='+BOOT.projectId+'&limit=200'+(qcw.uncodedOnly?'&uncoded=1':'');
  return fetch('/api/qual/get-segments.php?'+qs,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='coding')return;
    qcw.segments=(d&&d.segments)||[]; qcwRenderList(d&&d.total);
  }).catch(()=>{if(activeStep().id==='coding'){const l=$("#segList");if(l)l.innerHTML='<div class="work-surface">Could not load segments.</div>';}});
}
function qcwRenderList(total){
  const list=$("#segList"); if(!list)return;
  const q=qcw.search.toLowerCase();
  const filtered=q?qcw.segments.filter(x=>(x.raw_text||'').toLowerCase().indexOf(q)!==-1):qcw.segments;
  const coded=filtered.filter(x=>x.code_count>0).length, uncoded=filtered.length-coded;
  const counts=$("#segCounts"); if(counts)counts.textContent=filtered.length+' shown'+(total&&total>qcw.segments.length?' of '+total:'')+' · '+coded+' coded · '+uncoded+' uncoded';
  list.innerHTML=filtered.length?filtered.map(qcwSegCard).join(''):'<div class="work-surface">No segments '+(qcw.uncodedOnly?'left to code.':'found.')+'</div>';
}
function qcwChips(seg){return (seg.codes||[]).map(c=>`<span class="code-chip">${esc(c.name)}<span class="x chip-x" data-seg="${seg.id}" data-code="${c.id}">✕</span></span>`).join('');}
function qcwSegCard(seg){
  const meta=seg.metadata_json||{};
  const metaItems=Object.keys(meta).slice(0,4).map(k=>`<span class="ov-chip" style="font-size:11px">${esc(k)}: ${esc(String(meta[k]))}</span>`).join('');
  const pid=seg.participant_id?`<span class="ov-chip" style="font-size:11px">ID: ${esc(seg.participant_id)}</span>`:'';
  const q=seg.question_ref?`<span class="seg-meta" style="margin:0">${esc(seg.question_ref)}</span>`:'';
  const flag=seg.code_count===0?'<span class="tt-status rev">Uncoded</span>':(seg.code_count>=4?'<span class="tt-status rev">Over-coded</span>':'');
  const picker=(qcw.codes&&qcw.codes.length)?qcw.codes.map(c=>`<button class="picker-item" data-seg="${seg.id}" data-code="${c.id}" data-name="${esc(c.name)}" style="display:block;width:100%;text-align:left;padding:7px 12px;border:none;background:none;cursor:pointer;font:inherit;font-size:13px">${esc(c.name)}</button>`).join(''):'<div class="dm-note" style="padding:8px 12px">No codes yet.</div>';
  return `<div class="seg-card" id="seg-${seg.id}">
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;align-items:center">${pid}${q}${metaItems}</div>
    <div class="seg-text">${esc(seg.raw_text)}</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px" id="chips-${seg.id}">${qcwChips(seg)}</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <div style="position:relative" id="pw-${seg.id}">
        <button class="btn add-code-btn" data-seg="${seg.id}" style="padding:4px 12px;font-size:12px">+ Add code</button>
        <div id="picker-${seg.id}" style="display:none;position:absolute;top:100%;left:0;z-index:100;background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow-md);min-width:190px;max-height:220px;overflow-y:auto">${picker}
          <div style="border-top:1px solid var(--border);padding:6px 8px"><button class="picker-new-btn" data-seg="${seg.id}" style="display:block;width:100%;text-align:left;padding:6px 8px;border:none;background:none;cursor:pointer;font:inherit;font-size:12.5px;font-weight:700;color:var(--indigo)">+ New code</button></div>
        </div>
      </div>
      <button class="btn ai-suggest-btn" data-seg="${seg.id}" style="padding:4px 12px;font-size:12px">✦ Suggest codes</button>
      ${flag}
    </div>
    <div id="aip-${seg.id}" style="display:none;margin-top:8px"></div>
  </div>`;
}
function qcwListClick(e){
  const addBtn=e.target.closest('.add-code-btn'), item=e.target.closest('.picker-item'), newBtn=e.target.closest('.picker-new-btn'), rm=e.target.closest('.chip-x'), sug=e.target.closest('.ai-suggest-btn'), applyAi=e.target.closest('.ai-apply-btn'), dismissAi=e.target.closest('.ai-dismiss-btn');
  if(addBtn){const sid=addBtn.getAttribute('data-seg');document.querySelectorAll('[id^="picker-"]').forEach(p=>{if(p.id!=='picker-'+sid)p.style.display='none';});const pk=document.getElementById('picker-'+sid);if(pk)pk.style.display=pk.style.display==='none'?'block':'none';}
  if(item){const sid=+item.getAttribute('data-seg'),cid=+item.getAttribute('data-code'),nm=item.getAttribute('data-name');const pk=document.getElementById('picker-'+sid);if(pk)pk.style.display='none';qcwApply(sid,cid,nm);}
  if(newBtn){const sid=+newBtn.getAttribute('data-seg');const name=prompt('New code name:');if(!name||!name.trim())return;qapi('/api/qual/save-code.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,name:name.trim()})}).then(r=>{qcw.codes.push({id:r.code_id,name:name.trim()});qcwApply(sid,r.code_id,name.trim());}).catch(ex=>toast('Error: '+ex.message));}
  if(rm){const sid=+rm.getAttribute('data-seg'),cid=+rm.getAttribute('data-code');qapi('/api/qual/remove-code.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,segment_id:sid,code_id:cid})}).then(()=>{const seg=qcw.segments.find(x=>x.id===sid);if(seg){seg.codes=seg.codes.filter(c=>c.id!==cid);seg.code_count=seg.codes.length;}const ch=document.getElementById('chips-'+sid);if(ch&&seg)ch.innerHTML=qcwChips(seg);}).catch(ex=>toast('Error: '+ex.message));}
  if(sug){const sid=+sug.getAttribute('data-seg');const panel=document.getElementById('aip-'+sid);if(!panel)return;if(panel.style.display!=='none'&&panel.innerHTML!==''){panel.style.display='none';return;}panel.style.display='block';panel.innerHTML='<div class="dm-note" style="padding:8px 0">ReliCheck Intelligence is analyzing this segment…</div>';sug.disabled=true;qapi('/api/qual/suggest-codes.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,segment_id:sid})}).then(r=>{sug.disabled=false;qcwRenderSug(panel,sid,r.suggestions||[]);}).catch(ex=>{sug.disabled=false;panel.innerHTML='<div class="dm-note" style="padding:8px 0;color:#c0392b">Could not get suggestions: '+esc(ex.message)+'</div>';});}
  if(applyAi){const sid=+applyAi.getAttribute('data-seg'),nm=applyAi.getAttribute('data-name');const exist=qcw.codes.find(c=>(c.name||'').toLowerCase()===nm.toLowerCase());const row=applyAi.closest('.ai-sug-row');if(exist){qcwApply(sid,exist.id,exist.name);if(row)row.style.opacity='.4';}else{qapi('/api/qual/save-code.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,name:nm})}).then(r=>{qcw.codes.push({id:r.code_id,name:nm});qcwApply(sid,r.code_id,nm);if(row)row.style.opacity='.4';}).catch(e2=>toast('Error: '+e2.message));}}
  if(dismissAi){const row=dismissAi.closest('.ai-sug-row');if(row)row.style.opacity='.4';}
}
function qcwApply(sid,cid,cname){
  qapi('/api/qual/apply-code.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,segment_id:sid,code_id:cid})}).then(()=>{
    const seg=qcw.segments.find(x=>x.id===sid);
    if(seg&&!seg.codes.find(c=>c.id===cid)){seg.codes.push({id:cid,name:cname});seg.code_count=seg.codes.length;}
    const ch=document.getElementById('chips-'+sid);if(ch&&seg)ch.innerHTML=qcwChips(seg);
  }).catch(e=>toast('Could not apply code: '+e.message));
}
function qcwRenderSug(panel,sid,suggestions){
  if(!suggestions.length){panel.innerHTML='<div class="dm-note" style="padding:8px 0">No suggestions. Add more codes to the codebook first.</div>';return;}
  const ci={high:'●●●',medium:'●●○',low:'●○○'};
  const rows=suggestions.map(x=>{
    const badge=x.is_existing?'<span class="tt-status ok">in codebook</span>':'<span class="ov-chip" style="font-size:10px">new</span>';
    return `<div class="ai-sug-row" style="margin-bottom:12px">
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px"><span style="font-size:14px;font-weight:700">${esc(x.name)}</span>${badge}<span class="strand-chip qual">${esc(x.evidence_type||'')}</span><span class="dm-note">${ci[x.confidence]||''}</span></div>
      <div class="dm-note" style="margin-bottom:8px;line-height:1.45">${esc(x.rationale||'')}</div>
      <div style="display:flex;gap:8px"><button class="btn primary ai-apply-btn" data-seg="${sid}" data-name="${esc(x.name)}" style="font-size:12px;padding:5px 12px">Apply</button><button class="btn ai-dismiss-btn" style="font-size:12px;padding:5px 10px">Dismiss</button></div>
    </div>`;
  }).join('');
  panel.innerHTML=`<div style="padding:10px 0"><div class="dx-l-k" style="margin-bottom:10px">✦ ReliCheck Intelligence suggestions</div>${rows}</div>`;
}
/* ── Step 8 · Codebook Builder — real get-codes / save-code (shares qcw.codes) ── */
function renderCodebook(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading codebook…</div>`+navFooter();
  fetch('/api/qual/get-codes.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='codebook')return;
    qcw.codes=(d&&d.ok&&d.codes)?d.codes:[];
    qcbForm(s);
  }).catch(()=>{if(activeStep().id==='codebook')$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Could not load codes.</div>`+navFooter();});
}
function qcbTable(){
  const codes=qcw.codes||[];
  if(!codes.length)return '<div class="work-surface">No codes yet. Add your first code below, or create codes while coding in step 7.</div>';
  const rows=codes.map(c=>`<tr><td class="dx-name">${esc(c.name)}</td><td class="dx-interp">${c.definition?esc(c.definition):'<span class="ov-empty">No definition</span>'}</td><td>${c.application_count||0}</td><td><button class="btn" style="padding:4px 10px;font-size:12px" onclick="qcbEdit(${c.id})">Edit</button></td></tr>`).join('');
  return `<div class="dx-scroll" style="max-height:360px;overflow-y:auto"><table class="dx-table"><thead><tr><th class="l">Code</th><th class="l">Definition</th><th>Applied</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`;
}
function qcbForm(s){
  const hasCL=(typeof ContextualLens!=='undefined');
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="panel"><div class="panel-h"><div><h3>Codebook</h3><div class="ph-sub">${(qcw.codes||[]).length} codes</div></div></div>
      <div class="panel-b" id="cbTable">${qcbTable()}</div></div>
    <div class="panel"><div class="panel-h"><div><h3 id="cbFormTitle">Add a new code</h3><div class="ph-sub">A clear definition keeps coding consistent across segments and coders</div></div></div>
      <div class="panel-b">
        <label class="ed-l">Code name</label>
        <input class="ed-in" id="cbName" placeholder="e.g. Sizing issues">
        <label class="ed-l">Definition</label>
        <textarea class="ed-in" id="cbDef" rows="2" placeholder="Apply when a respondent describes…"></textarea>
        <div class="form-grid" style="margin:14px 0 0">
          <div><label class="ed-l">Include when</label><textarea class="ed-in" id="cbInclude" rows="2" placeholder="The response describes…"></textarea></div>
          <div><label class="ed-l">Exclude when</label><textarea class="ed-in" id="cbExclude" rows="2" placeholder="The response is about something else…"></textarea></div>
        </div>
        <label class="ed-l">Example quote</label>
        <input class="ed-in" id="cbQuote" placeholder="&quot;The band was so tight I returned it.&quot;">
        ${hasCL?ContextualLens.panel('code',null,'cb_cl_'):''}
        <input type="hidden" id="cbEditId">
        <div class="dm-save" style="position:static;margin-top:14px"><button class="btn primary" id="cbSave" onclick="qcbSave()">Add code</button><button class="btn" id="cbCancel" style="display:none" onclick="qcbClear()">Cancel</button><span class="dm-note" id="cbMsg"></span></div>
      </div></div>`+navFooter();
}
function qcbClear(){
  ['cbName','cbDef','cbInclude','cbExclude','cbQuote'].forEach(id=>{const el=$('#'+id);if(el)el.value='';});
  const eid=$("#cbEditId");if(eid)eid.value='';
  const t=$("#cbFormTitle");if(t)t.textContent='Add a new code';
  const b=$("#cbSave");if(b)b.textContent='Add code';
  const c=$("#cbCancel");if(c)c.style.display='none';
  if(typeof ContextualLens!=='undefined')ContextualLens.populate('code',{},'cb_cl_');
}
function qcbEdit(id){
  const code=(qcw.codes||[]).find(c=>c.id===id);if(!code)return;
  const set=(i,v)=>{const el=$('#'+i);if(el)el.value=v||'';};
  set('cbEditId',code.id);set('cbName',code.name);set('cbDef',code.definition);set('cbInclude',code.include_when);set('cbExclude',code.exclude_when);set('cbQuote',code.example_quote);
  if(typeof ContextualLens!=='undefined')ContextualLens.populate('code',code,'cb_cl_');
  const t=$("#cbFormTitle");if(t)t.textContent='Edit code: '+code.name;
  const b=$("#cbSave");if(b)b.textContent='Save changes';
  const c=$("#cbCancel");if(c)c.style.display='';
  const panel=$("#cbName");if(panel)panel.scrollIntoView({behavior:'smooth',block:'center'});
}
function qcbSave(){
  const msg=$("#cbMsg");
  const name=$("#cbName").value.trim();
  const editId=+($("#cbEditId").value||0);
  if(!name){if(msg){msg.style.color='#c0392b';msg.textContent='Code name is required.';}return;}
  if(msg){msg.style.color='';msg.textContent='Saving…';}
  const body={project_id:BOOT.projectId,name:name,definition:$("#cbDef").value.trim(),include_when:$("#cbInclude").value.trim(),exclude_when:$("#cbExclude").value.trim(),example_quote:$("#cbQuote").value.trim()};
  if(typeof ContextualLens!=='undefined')Object.assign(body,ContextualLens.gather('code','cb_cl_'));
  if(editId)body.id=editId;
  qapi('/api/qual/save-code.php',{method:'POST',body:JSON.stringify(body)})
    .then(()=>fetch('/api/qual/get-codes.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()))
    .then(d=>{qcw.codes=(d&&d.ok&&d.codes)?d.codes:[];const tbl=$("#cbTable");if(tbl)tbl.innerHTML=qcbTable();qcbClear();if(msg){msg.style.color='var(--mm-ink)';msg.textContent=editId?'Code updated.':'Code added.';}})
    .catch(e=>{if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});
}
/* ── Step 9 · Dual Coder — invites + agreement (chunk 2, part 3) ── */
const dcw={tab:'team',invite:null,disag:null,filter:'disagree'};
function renderDual(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  dcw.invite=null;dcw.disag=null;qdcPage(s);
}
function qdcPage(s){
  const tab=(id,label)=>`<button class="tt-tab ${dcw.tab===id?'on':''}" onclick="dcw.tab='${id}';qdcPage(activeStep())">${label}</button>`;
  $("#centerInner").innerHTML=wsHead(s)+`
    <div class="tt-tabs">${tab('team','Team')}${tab('review','Disagreement Review')}</div>
    <div id="dcPanel"><div class="work-surface">Loading…</div></div>`+navFooter();
  if(dcw.tab==='team')qdcTeam();else qdcReview();
}
function qdcBar(pct,color){return `<div style="background:var(--bg);border-radius:999px;height:6px;overflow:hidden"><div style="height:6px;border-radius:999px;background:${color};width:${pct}%"></div></div><div class="dm-note" style="text-align:right;margin-top:4px">${pct}%</div>`;}
function qdcTeam(){
  const panel=$("#dcPanel");if(!panel)return;
  if(dcw.invite){qdcTeamContent(dcw.invite);return;}
  panel.innerHTML='<div class="work-surface">Loading team…</div>';
  fetch('/api/qual/get-invites.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='dual'||dcw.tab!=='team')return;dcw.invite=d;qdcTeamContent(d);
  }).catch(()=>{if(panel)panel.innerHTML='<div class="work-surface">Could not load team.</div>';});
}
function qdcTeamContent(d){
  const panel=$("#dcPanel");if(!panel)return;
  const total=d.total_segments||0, lead=d.lead||{}, invites=d.invites||[];
  const active=invites.find(i=>i.status==='pending'||i.status==='accepted');
  const leadPct=total>0?Math.round((lead.coded||0)/total*100):0;
  let cards=`<div class="form-grid" style="margin-bottom:20px">
    <div class="panel"><div class="panel-b"><div class="dx-l-k" style="color:var(--indigo)">Lead coder (you)</div><div style="font-size:15px;font-weight:700;margin:4px 0 6px">${esc(lead.name||'You')}</div><div class="dm-note" style="margin-bottom:8px">${lead.coded||0} of ${total} segments coded</div>${qdcBar(leadPct,'var(--indigo)')}</div></div>`;
  if(active&&active.status==='accepted'){const scPct=total>0?Math.round((active.coded||0)/total*100):0;
    cards+=`<div class="panel"><div class="panel-b"><div class="dx-l-k" style="color:var(--quan)">Second coder</div><div style="font-size:15px;font-weight:700;margin:4px 0 6px">${esc(active.coder_name||active.email)}</div><div class="dm-note" style="margin-bottom:8px">${active.coded||0} of ${total} segments coded</div>${qdcBar(scPct,'var(--quan)')}</div></div>`;
  }else{
    cards+=`<div class="panel" style="border-style:dashed"><div class="panel-b" style="text-align:center;color:var(--text-3)"><div style="font-size:28px;margin-bottom:6px">+</div><div style="font-weight:600;margin-bottom:4px">Second coder</div><div class="dm-note">Not yet assigned</div></div></div>`;
  }
  cards+='</div>';
  let invSec='';
  if(active){
    const badge=active.status==='accepted'?'<span class="tt-status ok">accepted</span>':'<span class="tt-status rev">pending</span>';
    invSec=`<div class="panel"><div class="panel-b"><div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px"><span style="font-weight:700">${esc(active.email)}</span>${badge}</div>`;
    if(active.status==='pending'){invSec+=`<div class="dm-note" style="margin-bottom:10px">Share this link with the coder. They must log in to ReliCheck to accept it.</div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input class="ed-in" value="${esc(active.invite_url||'')}" readonly style="flex:1;font-family:monospace;font-size:12px"><button class="btn" onclick="qdcCopy('${esc(active.invite_url||'')}',this)">Copy link</button></div>`;}
    invSec+=`<div class="run-actions" style="margin-top:14px"><button class="btn" onclick="qdcRevoke(${active.id})">Revoke invite</button>${active.status==='accepted'?'<button class="btn primary" onclick="dcw.tab=\'review\';qdcPage(activeStep())">View disagreements →</button>':''}</div></div></div>`;
  }else{
    invSec=`<div class="panel"><div class="panel-h"><div><h3>Invite a second coder</h3><div class="ph-sub">They code the same segments with your codebook</div></div></div>
      <div class="panel-b"><div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><div style="flex:1;min-width:200px"><label class="ed-l">Email address</label><input class="ed-in" id="dcEmail" type="email" placeholder="colleague@example.com"></div><button class="btn primary" onclick="qdcInvite()">Generate invite link</button></div><div id="dcInviteResult" style="margin-top:14px"></div></div></div>`;
  }
  panel.innerHTML=cards+invSec;
}
function qdcCopy(url,btn){navigator.clipboard.writeText(url).catch(()=>{});if(btn){btn.textContent='Copied!';setTimeout(()=>btn.textContent='Copy link',2000);}}
function qdcRevoke(id){
  if(!confirm('Revoke this invite? The second coder will lose access.'))return;
  qapi('/api/qual/revoke-invite.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,invite_id:id})}).then(()=>{dcw.invite=null;qdcTeam();}).catch(e=>toast('Error: '+e.message));
}
function qdcInvite(){
  const email=($("#dcEmail").value||'').trim();if(!email){toast('Enter an email address.');return;}
  const result=$("#dcInviteResult");
  qapi('/api/qual/invite-coder.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,email:email})}).then(r=>{
    if(result)result.innerHTML=`<div style="padding:12px 14px;background:var(--indigo-light);border-radius:10px"><div style="font-weight:700;margin-bottom:6px">Invite link generated</div><div style="display:flex;gap:8px;align-items:center"><input class="ed-in" value="${esc(r.invite_url||'')}" readonly style="flex:1;font-family:monospace;font-size:12px"><button class="btn" onclick="qdcCopy('${esc(r.invite_url||'')}',this)">Copy</button></div></div>`;
    setTimeout(()=>{dcw.invite=null;qdcTeam();},1400);
  }).catch(e=>{if(result)result.innerHTML='<div class="dm-note" style="color:#c0392b">'+esc(e.message)+'</div>';});
}
function qdcReview(){
  const panel=$("#dcPanel");if(!panel)return;
  if(dcw.disag){qdcReviewContent(dcw.disag);return;}
  panel.innerHTML='<div class="work-surface">Comparing coder decisions…</div>';
  fetch('/api/qual/get-disagreements.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='dual'||dcw.tab!=='review')return;dcw.disag=d;qdcReviewContent(d);
  }).catch(()=>{if(panel)panel.innerHTML='<div class="work-surface">Could not load comparison.</div>';});
}
function qdcReviewContent(d){
  const panel=$("#dcPanel");if(!panel)return;
  if(!d.ready){panel.innerHTML=`<div class="work-surface" style="text-align:center"><b>No second coder yet</b><br><span class="dm-note">${esc(d.reason||'Invite a second coder in the Team tab.')}</span></div>`;return;}
  const st=d.stats||{}, segs=d.segments||[];
  const pct=st.agreement_pct, pctColor=pct==null?'var(--text-3)':pct>=75?'var(--mm-ink)':pct>=60?'#d97706':'#c0392b';
  let html=`<div class="dm-cards" style="margin-bottom:18px">
    <div class="dm-card"><div class="dm-card-k">Both coded</div><div class="dm-card-v">${st.both_coded||0}</div></div>
    <div class="dm-card"><div class="dm-card-k">Agreements</div><div class="dm-card-v">${st.agreements||0}</div></div>
    <div class="dm-card"><div class="dm-card-k">Disagreements</div><div class="dm-card-v">${st.disagreements||0}</div></div>
    <div class="dm-card"><div class="dm-card-k">Agreement rate</div><div class="dm-card-v" style="color:${pctColor}">${pct!=null?pct+'%':'—'}</div></div>
  </div>`;
  if(!segs.length){panel.innerHTML=html+'<div class="work-surface">No segments coded by both coders yet.</div>';return;}
  const fbtn=(f,label)=>`<button class="btn ${dcw.filter===f?'primary':''}" style="font-size:12.5px" onclick="dcw.filter='${f}';qdcReviewContent(dcw.disag)">${label}</button>`;
  html+=`<div class="run-actions" style="margin-bottom:16px">${fbtn('disagree','Disagreements ('+(st.disagreements||0)+')')}${fbtn('agree','Agreements ('+(st.agreements||0)+')')}${fbtn('all','All ('+segs.length+')')}</div>`;
  const filtered=segs.filter(x=>dcw.filter==='disagree'?!x.is_agreement:dcw.filter==='agree'?x.is_agreement:true);
  html+='<div class="seg-scroll">'+filtered.map(x=>{
    const meta=x.metadata_json||{};const metaItems=Object.keys(meta).slice(0,3).map(k=>`<span class="ov-chip" style="font-size:11px">${esc(k)}: ${esc(String(meta[k]))}</span>`).join('');
    const badge=x.is_agreement?'<span class="tt-status ok">Agreement</span>':'<span class="tt-status rev">Disagreement</span>';
    const leadCodes=((x.agreed||[]).concat(x.only_lead||[])).map(c=>`<span class="code-chip">${esc(c.name)}</span>`).join('')||'<span class="dm-note">No codes</span>';
    const secCodes=((x.agreed||[]).concat(x.only_second||[])).map(c=>`<span class="code-chip" style="background:var(--quan-soft);color:var(--quan-ink)">${esc(c.name)}</span>`).join('')||'<span class="dm-note">No codes</span>';
    return `<div class="seg-card"><div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;align-items:center">${x.participant_id?`<span class="ov-chip" style="font-size:11px">ID: ${esc(x.participant_id)}</span>`:''}${metaItems}${badge}</div>
      <div class="seg-text">${esc(x.text)}</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><span class="dm-note" style="width:60px;flex:none;font-weight:700">Lead</span><div>${leadCodes}</div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><span class="dm-note" style="width:60px;flex:none;font-weight:700;color:var(--quan-ink)">Second</span><div>${secCodes}</div></div>
      </div></div>`;
  }).join('')+'</div>';
  panel.innerHTML=html;
}
/* ── Step 10 · Category Builder — group codes into categories (chunk 3) ── */
const catw={categories:[],unassigned:[]};
function renderCategories(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading categories…</div>`+navFooter();
  qcatLoad();
}
function qcatLoad(){
  return fetch('/api/qual/get-categories.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='categories')return;
    catw.categories=(d&&d.categories)||[]; catw.unassigned=(d&&d.unassigned)||[];
    qcatPage();
  }).catch(()=>{if(activeStep().id==='categories')$("#centerInner").innerHTML=wsHead(activeStep())+`<div class="work-surface" style="border-radius:16px">Could not load categories.</div>`+navFooter();});
}
function qcatPage(){
  const s=activeStep();
  const hasCats=catw.categories.length>0;
  let unHtml='';
  if(catw.unassigned.length){
    const catOpts=catw.categories.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');
    const items=catw.unassigned.map(c=>`<div class="dq-row"><div class="dq-body"><span class="code-chip" style="background:var(--bg);color:var(--text-2);border:1px solid var(--border)">${esc(c.name)}</span>${c.application_count?`<span class="dm-note" style="margin-left:6px">${c.application_count} applied</span>`:''}</div>${catw.categories.length?`<select class="ed-in" data-code="${c.id}" onchange="qcatAssign(this)" style="max-width:200px"><option value="">Assign to…</option>${catOpts}</select>`:'<span class="dm-note">Create a category first</span>'}</div>`).join('');
    unHtml=`<div class="panel"><div class="panel-h"><div><h3>Unassigned codes</h3><div class="ph-sub">${catw.unassigned.length} not yet grouped</div></div></div><div class="panel-b"><div class="dq-card" style="max-height:280px;overflow-y:auto">${items}</div></div></div>`;
  }else if(!hasCats){
    unHtml='<div class="work-surface" style="margin-bottom:18px">No codes in the codebook yet. Build your codebook (step 8) before grouping codes into categories.</div>';
  }
  const cats=catw.categories.map(cat=>{
    const chips=cat.codes.length?cat.codes.map(c=>`<span class="code-chip">${esc(c.name)}<span class="x" onclick="qcatUnassign(${c.id})">✕</span></span>`).join(''):'<span class="dm-note">No codes assigned</span>';
    return `<div class="cat-card"><div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px"><div><h4>${esc(cat.name)}</h4>${cat.description?`<div class="cat-sub">${esc(cat.description)}</div>`:''}</div><button class="btn" style="padding:4px 10px;font-size:12px;flex-shrink:0" onclick="qcatEdit('${esc(String(cat.id))}')">Edit</button></div><div>${chips}</div></div>`;
  }).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    ${unHtml}
    <div style="font-size:15px;font-weight:700;margin:4px 0 12px">Categories</div>
    <div>${cats}</div>
    <div class="panel"><div class="panel-h"><div><h3 id="catFormTitle">Add a category</h3><div class="ph-sub">Categories become the building blocks of themes</div></div></div>
      <div class="panel-b">
        <label class="ed-l">Category name</label>
        <input class="ed-in" id="catName" placeholder="e.g. Product complaints">
        <label class="ed-l">Description (optional)</label>
        <input class="ed-in" id="catDesc" placeholder="What kind of codes belong here?">
        <input type="hidden" id="catEditId">
        <div class="dm-save" style="position:static;margin-top:14px"><button class="btn primary" id="catSave" onclick="qcatSave()">Add category</button><button class="btn" id="catCancel" style="display:none" onclick="qcatClear()">Cancel</button><span class="dm-note" id="catMsg"></span></div>
      </div></div>`+navFooter();
}
function qcatAssign(sel){if(!sel.value)return;sel.disabled=true;qapi('/api/qual/assign-code-category.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,code_id:+sel.getAttribute('data-code'),category_id:+sel.value})}).then(()=>qcatLoad()).catch(ex=>{sel.disabled=false;toast('Error: '+ex.message);});}
function qcatUnassign(codeId){qapi('/api/qual/assign-code-category.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,code_id:codeId,category_id:0})}).then(()=>qcatLoad()).catch(ex=>toast('Error: '+ex.message));}
function qcatEdit(id){const cat=catw.categories.find(c=>String(c.id)===String(id));if(!cat)return;const set=(i,v)=>{const el=$('#'+i);if(el)el.value=v||'';};set('catEditId',cat.id);set('catName',cat.name);set('catDesc',cat.description);const t=$("#catFormTitle");if(t)t.textContent='Edit: '+cat.name;const b=$("#catSave");if(b)b.textContent='Save changes';const c=$("#catCancel");if(c)c.style.display='';const n=$("#catName");if(n)n.scrollIntoView({behavior:'smooth',block:'center'});}
function qcatClear(){['catName','catDesc'].forEach(i=>{const el=$('#'+i);if(el)el.value='';});const e=$("#catEditId");if(e)e.value='';const t=$("#catFormTitle");if(t)t.textContent='Add a category';const b=$("#catSave");if(b)b.textContent='Add category';const c=$("#catCancel");if(c)c.style.display='none';}
function qcatSave(){const msg=$("#catMsg");const name=($("#catName").value||'').trim();const desc=($("#catDesc").value||'').trim();const editId=+($("#catEditId").value||0);if(!name){if(msg){msg.style.color='#c0392b';msg.textContent='Name is required.';}return;}if(msg){msg.style.color='';msg.textContent='Saving…';}const body={project_id:BOOT.projectId,name:name,description:desc};if(editId)body.id=editId;qapi('/api/qual/save-category.php',{method:'POST',body:JSON.stringify(body)}).then(()=>{qcatClear();qcatLoad();}).catch(e=>{if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});}
/* ── Step 11 · Theme Builder — themes + categories + theme Contextual Lens (chunk 3) ── */
const thw={themes:[],allCategories:[]};
function renderThemes(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading themes…</div>`+navFooter();
  qthLoad();
}
function qthLoad(){
  return fetch('/api/qual/get-themes.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='themes')return;
    thw.themes=(d&&d.themes)||[]; thw.allCategories=(d&&d.all_categories)||[];
    qthPage();
  }).catch(()=>{if(activeStep().id==='themes')$("#centerInner").innerHTML=wsHead(activeStep())+`<div class="work-surface" style="border-radius:16px">Could not load themes.</div>`+navFooter();});
}
function qthPage(){
  const s=activeStep();
  const noCats=!thw.allCategories.length;
  const hasCL=(typeof ContextualLens!=='undefined');
  const cards=thw.themes.map(t=>{
    const catTags=t.categories&&t.categories.length?t.categories.map(c=>`<span class="code-chip">${esc(c.name)}</span>`).join(''):'<span class="dm-note">No categories linked</span>';
    const availCats=thw.allCategories.map(cat=>{const linked=(t.categories||[]).some(tc=>String(tc.id)===String(cat.id));return `<label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0;cursor:pointer"><input type="checkbox" data-theme="${t.id}" data-cat="${cat.id}" ${linked?'checked':''} onchange="qthLink(this)"> ${esc(cat.name)}</label>`;}).join('');
    // Contextual Lens lives ON the theme (its own panel + save), separate from the create form.
    const cl=hasCL?`<div style="margin-top:12px;border-top:1px solid var(--border);padding-top:6px">${ContextualLens.panel('theme',t,'th_cl_'+t.id+'_')}<div class="run-actions" style="margin-top:8px"><button class="btn primary" style="padding:6px 13px;font-size:12.5px" onclick="qthSaveLens('${esc(String(t.id))}')">Save Contextual Lens</button><span class="dm-note">Optional interpretive layer for this theme.</span></div></div>`:'';
    return `<div class="cat-card">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px"><div style="font-size:15px;font-weight:700">${esc(t.name)}</div><button class="btn" style="padding:4px 10px;font-size:12px;flex-shrink:0" onclick="qthEdit('${esc(String(t.id))}')">Edit</button></div>
      <div style="padding:12px 14px;background:var(--indigo-light);border-radius:10px;margin-bottom:10px"><div class="dx-l-k" style="color:var(--indigo);margin-bottom:4px">Finding</div><div style="font-size:14px;color:var(--indigo);line-height:1.55;font-style:italic">"${esc(t.interpretive_claim||'')}"</div></div>
      <div class="dx-l-k" style="margin-bottom:6px">Supporting categories</div><div style="margin-bottom:10px">${catTags}</div>
      ${thw.allCategories.length?`<details style="font-size:13px"><summary style="cursor:pointer;color:var(--indigo);font-weight:600">Link categories…</summary><div style="margin-top:10px;display:flex;flex-direction:column;gap:4px">${availCats}</div></details>`:''}
      ${cl}
    </div>`;
  }).join('');
  $("#centerInner").innerHTML=wsHead(s)+`
    ${noCats?'<div class="work-surface" style="margin-bottom:18px">No categories yet. Group codes in the Category Builder (step 10) first.</div>':''}
    ${thw.themes.length?'':'<div class="dm-note" style="margin-bottom:14px">No themes yet. Create one below — a Contextual Lens panel then appears on each theme card.</div>'}
    <div>${cards}</div>
    <div class="panel"><div class="panel-h"><div><h3 id="thFormTitle">Add a theme</h3><div class="ph-sub">Name it and state the finding. The Contextual Lens is added per theme, on its card above.</div></div></div>
      <div class="panel-b">
        <label class="ed-l">Theme name</label>
        <input class="ed-in" id="thName" placeholder="e.g. Sizing inconsistency erodes trust">
        <label class="ed-l">Interpretive claim — state a finding, not a label</label>
        <textarea class="ed-in" id="thClaim" rows="3" placeholder="Respondents repeatedly described…"></textarea>
        <label class="ed-l">Notes (optional)</label>
        <textarea class="ed-in" id="thNotes" rows="2" placeholder="Consider whether this overlaps with…"></textarea>
        <input type="hidden" id="thEditId">
        <div class="dm-save" style="position:static;margin-top:14px"><button class="btn primary" id="thSave" onclick="qthSave()">Add theme</button><button class="btn" id="thCancel" style="display:none" onclick="qthClear()">Cancel</button><span class="dm-note" id="thMsg"></span></div>
      </div></div>`+navFooter();
}
function qthLink(cb){cb.disabled=true;qapi('/api/qual/link-theme-category.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,theme_id:+cb.getAttribute('data-theme'),category_id:+cb.getAttribute('data-cat'),action:cb.checked?'add':'remove'})}).then(()=>qthLoad()).catch(ex=>{cb.disabled=false;toast('Error: '+ex.message);});}
function qthEdit(id){const t=thw.themes.find(x=>String(x.id)===String(id));if(!t)return;const set=(i,v)=>{const el=$('#'+i);if(el)el.value=v||'';};set('thEditId',t.id);set('thName',t.name);set('thClaim',t.interpretive_claim);set('thNotes',t.notes);const ti=$("#thFormTitle");if(ti)ti.textContent='Edit: '+t.name;const b=$("#thSave");if(b)b.textContent='Save changes';const c=$("#thCancel");if(c)c.style.display='';const n=$("#thName");if(n)n.scrollIntoView({behavior:'smooth',block:'center'});}
function qthClear(){['thName','thClaim','thNotes'].forEach(i=>{const el=$('#'+i);if(el)el.value='';});const e=$("#thEditId");if(e)e.value='';const t=$("#thFormTitle");if(t)t.textContent='Add a theme';const b=$("#thSave");if(b)b.textContent='Add theme';const c=$("#thCancel");if(c)c.style.display='none';}
function qthSave(){
  const msg=$("#thMsg");const name=($("#thName").value||'').trim();const claim=($("#thClaim").value||'').trim();const notes=($("#thNotes").value||'').trim();const editId=+($("#thEditId").value||0);
  if(!name){if(msg){msg.style.color='#c0392b';msg.textContent='Theme name is required.';}return;}
  if(!claim){if(msg){msg.style.color='#c0392b';msg.textContent='An interpretive claim is required.';}return;}
  if(msg){msg.style.color='';msg.textContent='Saving…';}
  const body={project_id:BOOT.projectId,name:name,interpretive_claim:claim,notes:notes};
  if(editId)body.id=editId;
  qapi('/api/qual/save-theme.php',{method:'POST',body:JSON.stringify(body)}).then(()=>{qthClear();qthLoad();}).catch(e=>{if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});
}
// Save the Contextual Lens for one existing theme (its own card), preserving the
// theme's name/claim/notes. Separate from creating a theme — never asks for a name.
function qthSaveLens(id){
  const t=thw.themes.find(x=>String(x.id)===String(id));
  if(!t||typeof ContextualLens==='undefined')return;
  const body=Object.assign({project_id:BOOT.projectId,id:t.id,name:t.name,interpretive_claim:t.interpretive_claim,notes:t.notes||''},ContextualLens.gather('theme','th_cl_'+id+'_'));
  qapi('/api/qual/save-theme.php',{method:'POST',body:JSON.stringify(body)}).then(()=>{toast('Contextual Lens saved for "'+t.name+'"');qthLoad();}).catch(e=>toast('Error: '+e.message));
}

/* ── Step 12 · Quote Finder — pin exemplar quotes per theme (chunk 3) ── */
const qfw={themes:[],theme:null,segments:[],pinnedIds:[]};
function renderQuotes(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading quotes…</div>`+navFooter();
  fetch('/api/qual/get-quotes.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='quotes')return;
    if(d&&d.themes&&d.themes.length===1){qfLoad(d.themes[0].id);}
    else{qfw.themes=(d&&d.themes)||[];qfw.theme=null;qfPage();}
  }).catch(()=>{if(activeStep().id==='quotes')$("#centerInner").innerHTML=wsHead(activeStep())+`<div class="work-surface" style="border-radius:16px">Could not load quotes.</div>`+navFooter();});
}
function qfLoad(themeId){
  let qs='/api/qual/get-quotes.php?project_id='+BOOT.projectId;if(themeId)qs+='&theme_id='+themeId;
  return fetch(qs,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='quotes')return;
    qfw.themes=(d&&d.themes)||[];qfw.theme=(d&&d.theme)||null;qfw.segments=(d&&d.segments)||[];qfw.pinnedIds=(d&&d.pinned_ids)||[];
    qfPage();
  }).catch(()=>{if(activeStep().id==='quotes'){const b=$("#qfBody");if(b)b.innerHTML='<div class="work-surface">Could not load quotes.</div>';}});
}
function qfSegCard(seg,isPinned){
  let meta=seg.metadata_json||{};if(typeof meta==='string'){try{meta=JSON.parse(meta);}catch(_){meta={};}}
  const metaItems=Object.keys(meta).slice(0,3).map(k=>`<span class="ov-chip" style="font-size:11px">${esc(k)}: ${esc(String(meta[k]))}</span>`).join('');
  const codeTags=(seg.theme_codes||[]).map(c=>`<span class="code-chip">${esc(c.code_name)} → ${esc(c.cat_name)}</span>`).join('');
  const pinBtn=isPinned?`<button class="btn primary" style="font-size:12px;padding:4px 12px" onclick="qfPin(${seg.id},'unpin',this)">★ Pinned — remove</button>`:`<button class="btn" style="font-size:12px;padding:4px 12px" onclick="qfPin(${seg.id},'pin',this)">☆ Pin as exemplar</button>`;
  return `<div class="seg-card"${isPinned?' style="border-color:var(--indigo);background:var(--indigo-light)"':''}>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">${seg.participant_id?`<span class="ov-chip" style="font-size:11px">ID: ${esc(seg.participant_id)}</span>`:''}${metaItems}</div>
    <div class="seg-text">${esc(seg.cleaned_text||seg.raw_text||'')}</div>
    ${codeTags?`<div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap">${codeTags}</div>`:''}
    <div>${pinBtn}</div></div>`;
}
function qfPage(){
  const s=activeStep();
  if(!qfw.themes.length){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No themes yet. Build themes in the Theme Builder (step 11) first.</div>`+navFooter();return;}
  const tabs=qfw.themes.map(t=>{const active=qfw.theme&&String(qfw.theme.id)===String(t.id);return `<button class="tt-tab ${active?'on':''}" onclick="qfLoad(${t.id})">${esc(t.name)}</button>`;}).join('');
  let body='';
  if(!qfw.theme){body='<div class="work-surface">Select a theme above to find exemplar quotes.</div>';}
  else{
    const t=qfw.theme;
    body+=`<div style="padding:14px 18px;background:var(--indigo-light);border-radius:12px;margin-bottom:20px"><div class="dx-l-k" style="color:var(--indigo);margin-bottom:4px">Finding</div><div style="font-size:14.5px;color:var(--indigo);line-height:1.55;font-style:italic">"${esc(t.interpretive_claim||'')}"</div></div>`;
    const pinned=qfw.segments.filter(x=>qfw.pinnedIds.indexOf(+x.id)!==-1);
    if(pinned.length)body+=`<div style="margin-bottom:24px"><div class="dx-l-k" style="color:var(--indigo);margin-bottom:10px">★ Exemplar quotes (${pinned.length})</div><div style="display:flex;flex-direction:column;gap:10px">${pinned.map(x=>qfSegCard(x,true)).join('')}</div></div>`;
    const unpinned=qfw.segments.filter(x=>qfw.pinnedIds.indexOf(+x.id)===-1);
    if(!qfw.segments.length)body+='<div class="work-surface">No coded segments linked to this theme yet. Link this theme to categories (step 11) whose codes are applied to segments.</div>';
    else if(unpinned.length)body+=`<div class="dx-l-k" style="margin-bottom:10px">Linked segments — ${unpinned.length} remaining</div><div class="seg-scroll">${unpinned.map(x=>qfSegCard(x,false)).join('')}</div>`;
    else body+='<div class="dm-note">All linked segments are pinned as exemplars.</div>';
  }
  $("#centerInner").innerHTML=wsHead(s)+`<div class="tt-tabs" style="margin-bottom:18px">${tabs}</div><div id="qfBody">${body}</div>`+navFooter();
}
function qfPin(segId,action,btn){if(!qfw.theme)return;if(btn)btn.disabled=true;qapi('/api/qual/save-quote.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,theme_id:qfw.theme.id,segment_id:segId,action:action})}).then(()=>qfLoad(qfw.theme.id)).catch(ex=>{if(btn)btn.disabled=false;toast('Error: '+ex.message);});}
const APPROACH_LABELS={thematic:'Thematic Analysis',content:'Content Analysis',framework:'Framework Analysis',open_ended_survey:'Open-Ended Survey Analysis',document:'Document Analysis'};
/* ── Step 13 · Trustworthiness — reflexivity, agreement, member checks (chunk 4) ── */
function renderTrustworthiness(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading…</div>`+navFooter();
  fetch('/api/qual/get-trustworthiness.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='trustworthiness')return;qtwPage(d);
  }).catch(()=>{if(activeStep().id==='trustworthiness')$("#centerInner").innerHTML=wsHead(activeStep())+`<div class="work-surface" style="border-radius:16px">Could not load trustworthiness.</div>`+navFooter();});
}
function qtwPage(d){
  const s=activeStep();
  const r=d.reflexivity||{};
  const approach=APPROACH_LABELS[r.analysis_approach]||r.analysis_approach||'—';
  const reflex=`<div class="panel"><div class="panel-h"><div><h3>1 — Researcher reflexivity</h3><div class="ph-sub">Your stance and question ground the analysis</div></div></div>
    <div class="panel-b"><div class="form-grid" style="margin-bottom:14px">
      <div><div class="dx-l-k">Analysis approach</div><div style="font-size:14px;margin-top:4px">${esc(approach)}</div></div>
      <div><div class="dx-l-k">Research question</div><div style="font-size:14px;margin-top:4px">${r.research_question?esc(r.research_question):'<span class="ov-empty">Not yet set</span>'}</div></div>
    </div>
    ${r.stance_memo?`<div style="background:var(--indigo-light);border-radius:10px;padding:14px 16px"><div class="dx-l-k" style="color:var(--indigo);margin-bottom:6px">Researcher stance memo</div><div style="font-size:13.5px;line-height:1.6;color:var(--indigo)">${esc(r.stance_memo).replace(/\n/g,'<br>')}</div></div>`:`<div class="dx-caution" style="padding:10px 12px"><div class="dx-l-t" style="color:#7a5e1c">No researcher stance memo recorded. Add one in <a class="ov-link" onclick="goStep('setup')" style="cursor:pointer">Project Setup</a>.</div></div>`}
    </div></div>`;
  const ag=d.agreement||{};
  let agree;
  if(!ag.computable){
    agree=`<div class="panel-b"><div class="dm-note" style="margin-bottom:10px">${esc(ag.note||'')}</div><div class="work-surface">Cohen's kappa compares two coders' decisions. With a single coder there is no inter-rater agreement to compute. Single-coder analysis is valid, especially with member checking.</div></div>`;
  }else{
    const k=ag.kappa, kColor=k==null?'var(--text-3)':k>=0.6?'var(--mm-ink)':k>=0.4?'#d97706':'#c0392b';
    agree=`<div class="panel-b"><div class="dm-cards">
      <div class="dm-card"><div class="dm-card-k">Cohen's kappa</div><div class="dm-card-v" style="color:${kColor}">${k!=null?k.toFixed(3):'—'}</div><div class="dm-note">${esc(ag.interpretation||'')}</div></div>
      <div class="dm-card"><div class="dm-card-k">Percent agreement</div><div class="dm-card-v">${ag.percent_agreement!=null?ag.percent_agreement+'%':'—'}</div><div class="dm-note">${ag.shared_segments||0} shared segments</div></div>
    </div></div>`;
  }
  const agreement=`<div class="panel"><div class="panel-h"><div><h3>2 — Coding agreement</h3><div class="ph-sub">Inter-rater reliability once a second coder has coded</div></div></div>${agree}</div>`;
  const checks=d.member_checks||[];
  const checkList=checks.length?`<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px;max-height:280px;overflow-y:auto">${checks.map(c=>{const oc=c.outcome==='Confirmed'?'ok':'rev';return `<div class="seg-card" style="margin-bottom:0"><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px"><span class="tt-status ${oc}">${esc(c.outcome||'')}</span><span class="dm-note">${esc(c.date||'')}${c.who?' · '+esc(c.who):''}</span></div><div style="font-size:13.5px${c.notes?';margin-bottom:8px':''}">${esc(c.finding)}</div>${c.notes?`<div class="dm-note">${esc(c.notes).replace(/\n/g,'<br>')}</div>`:''}</div>`;}).join('')}</div>`:'';
  const member=`<div class="panel"><div class="panel-h"><div><h3>3 — Member checking</h3><div class="ph-sub">Record when you shared findings with participants or peers</div></div></div>
    <div class="panel-b">${checkList}
      <div style="border:1px solid var(--border);border-radius:12px;padding:16px">
        <div style="font-weight:600;margin-bottom:12px">Record a member check</div>
        <label class="ed-l">Finding or claim you shared</label>
        <textarea class="ed-in" id="mcFinding" rows="2" placeholder="e.g. Respondents felt sizing was inconsistent…"></textarea>
        <div class="form-grid" style="margin:12px 0 0">
          <div><label class="ed-l">Shared with</label><input class="ed-in" id="mcWho" placeholder="e.g. 3 participants, supervisor"></div>
          <div><label class="ed-l">Method</label><select class="ed-in" id="mcMethod"><option value="">Select…</option>${['Email summary','Interview review','Focus group','Peer review','Other'].map(m=>`<option>${m}</option>`).join('')}</select></div>
        </div>
        <div class="form-grid" style="margin:12px 0 0">
          <div><label class="ed-l">Date</label><input class="ed-in" id="mcDate" type="date"></div>
          <div><label class="ed-l">Outcome</label><select class="ed-in" id="mcOutcome">${['Confirmed','Revised','Mixed'].map(o=>`<option>${o}</option>`).join('')}</select></div>
        </div>
        <label class="ed-l">Notes</label>
        <textarea class="ed-in" id="mcNotes" rows="2" placeholder="What did they confirm, challenge, or add?"></textarea>
        <div class="dm-save" style="position:static;margin-top:12px"><button class="btn primary" onclick="qtwSaveCheck()">Save member check</button><span class="dm-note" id="mcMsg"></span></div>
      </div></div></div>`;
  $("#centerInner").innerHTML=wsHead(s)+reflex+agreement+member+navFooter();
}
function qtwSaveCheck(){
  const finding=($("#mcFinding").value||'').trim();const msg=$("#mcMsg");
  if(!finding){if(msg){msg.style.color='#c0392b';msg.textContent='Finding is required.';}return;}
  if(msg){msg.style.color='';msg.textContent='Saving…';}
  const check={finding:finding,who:($("#mcWho").value||'').trim(),method:($("#mcMethod").value||'').trim(),date:($("#mcDate").value||'').trim(),outcome:($("#mcOutcome").value||'Confirmed'),notes:($("#mcNotes").value||'').trim()};
  qapi('/api/qual/save-member-check.php',{method:'POST',body:JSON.stringify({project_id:BOOT.projectId,check:check})}).then(()=>renderTrustworthiness(activeStep())).catch(e=>{if(msg){msg.style.color='#c0392b';msg.textContent='Error: '+e.message;}});
}

/* ── Step 14 · Report & Export — build-report + CSV/JSON export (chunk 4) ── */
function renderReport(s){
  if(!BOOT.projectId){$("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">No project loaded yet.</div>`+navFooter();return;}
  $("#centerInner").innerHTML=wsHead(s)+`<div class="work-surface" style="border-radius:16px">Loading report data…</div>`+navFooter();
  fetch('/api/qual/build-report.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(activeStep().id!=='report')return;qrepPage(d);
  }).catch(()=>{if(activeStep().id==='report')$("#centerInner").innerHTML=wsHead(activeStep())+`<div class="work-surface" style="border-radius:16px">Could not load report data.</div>`+navFooter();});
}
function qrepPage(d){
  const s=activeStep();
  const p=d.project||{},stats=d.stats||{},themes=d.themes||[],checks=d.member_checks||[];
  const approach=APPROACH_LABELS[p.analysis_approach]||p.analysis_approach||'';
  const pid=BOOT.projectId;
  const topBar=`<div class="run-actions" style="margin-bottom:20px"><button class="btn primary" onclick="window.print()">Print / Save as PDF</button><a class="btn" href="/api/qual/export-coded.php?project_id=${pid}">Download coded segments (.csv)</a><button class="btn" onclick="qrepJson(this)">Download themes (.json)</button></div>`;
  const header=`<div class="panel"><div class="panel-b">
    <div class="dx-l-k">Qualitative Analysis Report</div>
    <h2 style="margin:6px 0;font-size:24px;font-weight:700">${esc(p.title||'Untitled Project')}</h2>
    ${approach?`<div class="dm-note" style="margin-bottom:14px">${esc(approach)}</div>`:''}
    ${p.research_question?`<div style="background:var(--indigo-light);border-radius:10px;padding:14px 16px;margin-bottom:14px"><div class="dx-l-k" style="color:var(--indigo);margin-bottom:4px">Research question</div><div style="font-size:14.5px;color:var(--indigo);line-height:1.55">${esc(p.research_question)}</div></div>`:''}
    <div class="form-grid">${p.participant_description?`<div><div class="dx-l-k">Participants</div><div style="font-size:13.5px;margin-top:3px">${esc(p.participant_description)}</div></div>`:''}${p.purpose?`<div><div class="dx-l-k">Purpose</div><div style="font-size:13.5px;margin-top:3px">${esc(p.purpose)}</div></div>`:''}</div>
  </div></div>`;
  const summary=`<div class="dm-cards" style="margin-bottom:20px">
    <div class="dm-card"><div class="dm-card-k">Segments</div><div class="dm-card-v">${stats.seg_count||0}</div></div>
    <div class="dm-card"><div class="dm-card-k">Total words</div><div class="dm-card-v">${stats.total_words||0}</div></div>
    <div class="dm-card"><div class="dm-card-k">Codes</div><div class="dm-card-v">${stats.code_count||0}</div></div>
    <div class="dm-card"><div class="dm-card-k">Themes</div><div class="dm-card-v">${stats.theme_count||0}</div></div>
  </div>`;
  let themeSec=`<h3 style="font-size:18px;font-weight:700;margin:0 0 14px;border-bottom:2px solid var(--indigo-light);padding-bottom:10px">Themes</h3>`;
  if(!themes.length)themeSec+='<div class="work-surface">No themes built yet. Complete the Theme Builder step first.</div>';
  else themeSec+=themes.map((t,i)=>{
    const catChips=t.categories&&t.categories.length?t.categories.map(c=>`<span class="ov-chip">${esc(c)}</span>`).join(''):'<span class="dm-note">No categories linked</span>';
    let quotes='';
    if(t.quotes&&t.quotes.length){quotes=`<div style="margin-top:14px"><div class="dx-l-k" style="margin-bottom:10px">Exemplar quotes</div>${t.quotes.map(q=>{const text=q.cleaned_text||q.raw_text||'';const attr=[];if(q.participant_id)attr.push('ID: '+q.participant_id);if(q.question_ref)attr.push(q.question_ref);return `<div style="border-left:3px solid var(--indigo);padding:8px 14px;margin-bottom:10px;background:var(--bg);border-radius:0 8px 8px 0"><div style="font-size:14px;line-height:1.65;font-style:italic">"${esc(text)}"</div>${attr.length?`<div class="dm-note" style="margin-top:6px">${esc(attr.join(' · '))}</div>`:''}</div>`;}).join('')}</div>`;}
    else quotes='<div class="dm-note" style="margin-top:10px;font-style:italic">No exemplar quotes pinned. Use Quote Finder to pin supporting evidence.</div>';
    return `<div class="panel"><div class="panel-b">
      <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:10px"><span class="tt-status ok">Theme ${i+1}</span><span style="font-size:17px;font-weight:700">${esc(t.name)}</span></div>
      <div style="background:var(--indigo-light);border-radius:10px;padding:12px 16px;margin-bottom:12px"><div class="dx-l-k" style="color:var(--indigo);margin-bottom:4px">Finding</div><div style="font-size:14px;color:var(--indigo);line-height:1.6;font-style:italic">"${esc(t.interpretive_claim||'(no claim set)')}"</div></div>
      <div class="dx-l-k" style="margin-bottom:8px">Supporting categories</div><div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap">${catChips}</div>
      ${quotes}
      ${t.notes?`<div style="margin-top:12px;font-size:13px;color:var(--text-2);border-top:1px solid var(--border);padding-top:12px">${esc(t.notes).replace(/\n/g,'<br>')}</div>`:''}
    </div></div>`;
  }).join('');
  const stance=p.researcher_stance_memo||'';
  const trustSec=`<h3 style="font-size:18px;font-weight:700;margin:24px 0 14px;border-bottom:2px solid var(--indigo-light);padding-bottom:10px">Trustworthiness</h3>
    <div class="panel"><div class="panel-b">
      <div class="dx-l-k" style="margin-bottom:8px">Researcher reflexivity</div>
      ${stance?`<div style="background:var(--bg);border-radius:8px;padding:12px 14px;font-size:13.5px;line-height:1.6;color:var(--text-2)">${esc(stance).replace(/\n/g,'<br>')}</div>`:'<div class="dm-note" style="font-style:italic">No researcher stance memo recorded.</div>'}
      <div class="dx-l-k" style="margin:18px 0 8px">Member checking</div>
      ${checks.length?`<div style="display:flex;flex-direction:column;gap:8px;max-height:260px;overflow-y:auto">${checks.map(c=>`<div class="seg-card" style="margin-bottom:0"><div style="font-weight:700;margin-bottom:3px">${esc(c.finding||'')}</div>${c.notes?`<div class="dm-note" style="margin-top:4px">${esc(c.notes).replace(/\n/g,'<br>')}</div>`:''}<div class="dm-note" style="margin-top:6px">${c.who?esc(c.who)+' · ':''}${esc(c.date||'')}${c.method?' · '+esc(c.method):''}</div></div>`).join('')}</div>`:'<div class="dm-note" style="font-style:italic">No member checks recorded.</div>'}
      ${d.audit_count?`<div class="dm-note" style="margin-top:10px">${d.audit_count} action${d.audit_count!==1?'s':''} logged in the audit trail.</div>`:''}
    </div></div>`;
  const exportSec=`<h3 style="font-size:18px;font-weight:700;margin:24px 0 14px;border-bottom:2px solid var(--indigo-light);padding-bottom:10px">Export &amp; handoff</h3>
    <div class="panel"><div class="panel-b"><div style="font-weight:700;margin-bottom:6px">MM Studio — Joint display handoff</div><p class="dm-note" style="margin-bottom:10px">Download the coded segments CSV and bring it into MM Studio as the qualitative strand.</p><a class="btn" href="/api/qual/export-coded.php?project_id=${pid}">Download coded segments (.csv)</a></div></div>
    <div class="panel"><div class="panel-b"><div style="font-weight:700;margin-bottom:6px">RSSI — Open-ended evidence</div><p class="dm-note" style="margin-bottom:10px">Reference your themes as qualitative evidence alongside the RSSI reliability score.</p><a class="btn" href="/rssi-app.php" target="_blank" rel="noopener">Open RSSI →</a></div></div>`;
  $("#centerInner").innerHTML=wsHead(s)+topBar+`<div id="repPrintable">${header}${summary}${themeSec}${trustSec}${exportSec}</div>`+navFooter();
}
function qrepJson(btn){
  if(btn){btn.disabled=true;btn.textContent='Loading…';}
  fetch('/api/qual/get-themes.php?project_id='+BOOT.projectId,{credentials:'same-origin'}).then(r=>r.json()).then(d=>
    Promise.all((d.themes||[]).map(t=>fetch('/api/qual/get-quotes.php?project_id='+BOOT.projectId+'&theme_id='+t.id,{credentials:'same-origin'}).then(r=>r.json()).then(qd=>{const ids=qd.pinned_ids||[];t.pinned_quotes=(qd.segments||[]).filter(x=>ids.indexOf(+x.id)!==-1).map(x=>({text:x.cleaned_text||x.raw_text,participant_id:x.participant_id||null,question_ref:x.question_ref||null}));return t;})))
  ).then(themes=>{
    const blob=new Blob([JSON.stringify({project_id:BOOT.projectId,themes:themes},null,2)],{type:'application/json'});
    const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='qual-themes-'+BOOT.projectId+'.json';a.click();URL.revokeObjectURL(url);
    if(btn){btn.disabled=false;btn.textContent='Download themes (.json)';}
  }).catch(e=>{if(btn){btn.disabled=false;btn.textContent='Download themes (.json)';}toast('Error: '+e.message);});
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

/* ════════ CHROME: topbar rail, coach ════════ */
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
/* Coach (slide-in) */
let coachAns=[];
function coachData(s){
  const h=HELP[s.id]||{};
  const strip=t=>String(t==null?'':t).replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim();
  const tip=h.what?esc(h.what):esc(s.lede);
  const qs=[],ans=[];
  if(h.measures){qs.push('What does this step give me?');ans.push(strip(h.measures));}
  if(h.use){qs.push('When should I use it?');ans.push(strip(h.use));}
  qs.push('Where am I overall?');ans.push('This is step '+s.n+' of '+steps().length+' in the qualitative analysis.');
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
function render(){renderCenter();renderTopbarSteps();renderCompanion();}
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
