<?php
// analysis-report.php — Standalone "Full Report" for an Inferential/Descriptive
// analysis project. Assembles the saved analyses into a real, readable document
// (hybrid: deterministic structure + a ReliCheck Intelligence narrative layer),
// independent of the studio shell. Reachable at:
//   /analysis-report.php?project_id=N
//
// Data sources (all existing endpoints):
//   GET /api/analysis/results.php?project_id=N   — saved analyses
//   GET /api/analysis/dataset.php?project_id=N    — sample size / variables (best effort)
//   POST /api/analysis/ai-inferential-report.php  — narrative layer
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/analysis-report.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectTitle = '';
$projectKind  = 'inferential';
if ($projectId > 0) {
  if (is_file(__DIR__ . '/api/_analysis_studio.php')) require_once __DIR__ . '/api/_analysis_studio.php';
  try {
    $pdo = db();
    if (function_exists('analysis_ensure_schema')) analysis_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT title, kind FROM analysis_projects WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $projectTitle = (string)$row['title']; $projectKind = (string)($row['kind'] ?: 'inferential'); }
    else { $projectId = 0; }
  } catch (Throwable $e) { /* leave defaults */ }
}
$workspaceUrl = $projectKind === 'descriptive'
  ? '/descriptive-analysis-workspace.php?project_id=' . $projectId
  : '/inferential-statistics-workspaceV4.php?project_id=' . $projectId;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($projectTitle !== '' ? $projectTitle : 'Analysis') ?> — Report</title>
<style>
:root{--ink:#15171a;--ink-2:#5f6368;--ink-3:#8a8f98;--line:#e6e8ec;--acc:#1d4ed8;--acc-soft:#eff2ff;--bg:#f5f6f8}
*{box-sizing:border-box}
html,body{margin:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
.rep-toolbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:10px;padding:12px 20px;background:#fff;border-bottom:1px solid var(--line)}
.rep-toolbar .sp{flex:1}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:9px;border:1px solid var(--line);background:#fff;color:var(--ink);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none}
.btn.primary{background:var(--acc);border-color:var(--acc);color:#fff}
.btn:disabled{opacity:.55;cursor:default}
.btn.ghost{border:none;color:var(--ink-2)}
.doc{max-width:780px;margin:26px auto 80px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:54px 60px;box-shadow:0 1px 2px rgba(20,28,45,.04),0 6px 24px rgba(20,28,45,.05)}
.doc h1{font-size:27px;line-height:1.25;margin:0 0 6px;letter-spacing:-.02em}
.doc .meta{color:var(--ink-3);font-size:13.5px;margin-bottom:26px;padding-bottom:18px;border-bottom:2px solid var(--ink)}
.doc h2{font-size:17px;margin:30px 0 10px;padding-bottom:5px;border-bottom:1px solid var(--line);letter-spacing:-.01em}
.doc h3{font-size:15px;margin:20px 0 4px}
.doc p{margin:0 0 12px}
.doc .lead{font-size:16.5px;color:var(--ink);font-weight:500;line-height:1.5;margin-bottom:20px}
.doc ul{margin:0 0 12px;padding-left:20px}
.doc li{margin-bottom:5px}
.rtable{width:100%;border-collapse:collapse;font-size:13.5px;margin:8px 0 6px}
.rtable th,.rtable td{border:1px solid var(--line);padding:7px 10px;text-align:left;vertical-align:top}
.rtable th{background:#fafbfc;font-weight:700;font-size:12.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--ink-2)}
.rtable td.num{font-variant-numeric:tabular-nums;white-space:nowrap}
.tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700}
.tag.sig{background:#e9f7ee;color:#1f9e44}.tag.ns{background:#eef0f3;color:#5f6368}
.note{background:var(--acc-soft);border:1px solid #dbe4ff;border-radius:10px;padding:12px 16px;font-size:13.5px;color:#28406e;margin:14px 0}
.ai-tag{font-size:11px;font-weight:700;color:var(--acc);text-transform:uppercase;letter-spacing:.05em}
.muted{color:var(--ink-3)}
.center{max-width:780px;margin:60px auto;text-align:center;color:var(--ink-2)}
.spin{display:inline-block;width:15px;height:15px;border:2px solid var(--line);border-top-color:var(--acc);border-radius:50%;animation:sp .7s linear infinite;vertical-align:-2px;margin-right:6px}
@keyframes sp{to{transform:rotate(360deg)}}
@media print{
  @page{margin:1.8cm 1.6cm}
  html,body{background:#fff;font-size:11.5pt}
  .rep-toolbar{display:none!important}
  .doc{box-shadow:none;border:none;border-radius:0;margin:0;max-width:none;padding:0}
  .doc h1{font-size:21pt}
  .doc h2{font-size:14pt;break-after:avoid;page-break-after:avoid}
  .doc h3{font-size:12pt;break-after:avoid;page-break-after:avoid}
  .doc .meta{margin-bottom:18pt}
  /* Keep results readable across page breaks */
  .rtable{page-break-inside:auto}
  .rtable tr{page-break-inside:avoid;break-inside:avoid}
  .rtable thead{display:table-header-group}
  .note,.doc h3+p,.lead{page-break-inside:avoid;break-inside:avoid}
  .note,.tag.sig,.tag.ns{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  /* Section starts stay with their content */
  .doc h2{page-break-before:auto}
  .ai-tag{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body data-project-id="<?= (int)$projectId ?>">
<div class="rep-toolbar">
  <a class="btn ghost" href="<?= htmlspecialchars($workspaceUrl) ?>">&larr; Back to studio</a>
  <span class="sp"></span>
  <button class="btn" id="btnRegen" title="Rewrite the narrative">Regenerate narrative</button>
  <button class="btn" id="btnWord">Word</button>
  <button class="btn" id="btnPrint">Print</button>
  <button class="btn primary" id="btnPdf">Download PDF</button>
</div>

<div id="reportHost">
  <div class="center"><span class="spin"></span> Assembling your report&hellip;</div>
</div>

<script>
(function(){
  'use strict';
  var PID = <?= (int)$projectId ?>;
  var PROJECT_TITLE = <?= json_encode($projectTitle !== '' ? $projectTitle : 'Analysis Project') ?>;
  var host = document.getElementById('reportHost');

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  function stripTags(h){ var d=document.createElement('div'); d.innerHTML=h||''; return (d.textContent||d.innerText||'').replace(/\s+/g,' ').trim(); }

  // tool_key -> human label (mirrors the studio's titleFor).
  var TOOL_LABEL = {
    frequencies:'Frequencies', distributions:'Means & Distributions', cross_tabs:'Cross-Tabs',
    group_summaries:'Group Summaries', top_bottom_items:'Top & Bottom Items', scale_scores:'Scale Scores',
    variables_fit:'Variables & Fit', descriptive:'Descriptive Analysis',
    t_test:'Independent t-test', paired_t:'Paired t-test', paired_t_test:'Paired t-test',
    anova:'One-way ANOVA', welch_anova:'Welch ANOVA', post_hoc:'Post-Hoc Comparison',
    chi_square:'Chi-square', correlation:'Correlation', regression:'Regression',
    effect_sizes:'Effect Sizes', confidence_interval:'Confidence Intervals',
    assumption_checks:'Assumption Checks', assumption_check:'Assumption Checks',
    two_way_anova:'Two-Way ANOVA', twoway_anova:'Two-Way ANOVA', logistic_regression:'Logistic Regression',
    recommended_analyses:'Recommended Analyses'
  };
  function toolLabel(k){ return TOOL_LABEL[k] || (k ? String(k).replace(/_/g,' ') : 'Analysis'); }

  // Parse a saved snapshot into ONLY its meaningful result: the selected
  // variables (from the dropdowns) and the statistics table. The Setup form,
  // buttons, tab strip, and dropdown option lists are stripped out — that noise
  // is exactly what made the studio's stacked snapshots unreadable.
  function extractClean(item){
    var div = document.createElement('div');
    div.innerHTML = (item && item.result && item.result.html) || '';
    // 1. Selected variables, read from the dropdowns before we remove them.
    var vars = [];
    Array.prototype.forEach.call(div.querySelectorAll('select'), function(sel){
      var opt = sel.options && sel.options[sel.selectedIndex];
      var t = opt ? opt.textContent.trim() : '';
      t = t.replace(/\s*\(n=\d+\)\s*$/,'').replace(/\s*\(\d+\s+(groups|levels|categories)\)\s*$/,'');
      if (t && t.charAt(0) !== '—' && vars.indexOf(t) < 0) vars.push(t);
    });
    // 2. Strip all interactive / structural noise.
    Array.prototype.forEach.call(
      div.querySelectorAll('select,button,.tt-segs,.run-actions,.tt-hint,.ph-sub,.as-help-bar,.tt-tabs,.tt-tab'),
      function(n){ n.remove(); }
    );
    // 3. Read the first real stats table into key/value pairs.
    var kv = [];
    var tbl = div.querySelector('table');
    if (tbl){
      var rows = tbl.querySelectorAll('tr');
      Array.prototype.forEach.call(rows, function(tr){
        var tds = tr.querySelectorAll('td');
        if (tds.length === 2){
          var k = tds[0].textContent.trim(), v = tds[1].textContent.trim();
          if (k && v) kv.push({ k: k, v: v });
        }
      });
      // Wide result table (header + one data row) → pair headers with the row.
      if (!kv.length){
        var ths = tbl.querySelectorAll('th');
        var firstRow = tbl.querySelector('tbody tr') || rows[1];
        if (firstRow){
          var cells = firstRow.querySelectorAll('td');
          Array.prototype.forEach.call(ths, function(th, i){
            if (cells[i]){ var k2 = th.textContent.trim(), v2 = cells[i].textContent.trim(); if (k2 && v2) kv.push({ k: k2, v: v2 }); }
          });
        }
      }
    }
    return { vars: vars.join(' vs '), kv: kv.slice(0, 8) };
  }
  function kvCompact(kv){ return kv.map(function(p){ return p.k + ': ' + p.v; }).join('  ·  '); }
  function decisionFromKv(kv){
    var txt = kv.map(function(p){ return p.k + ' ' + p.v; }).join(' ').toLowerCase();
    if (/not significant|n\.s\.|p[:\s]*[=>]\s*\.?[1-9]/.test(txt)) return {t:'n.s.', cls:'ns', c:'#5f6368'};
    if (/significant|p[:\s]*<\s*\.?0|p[:\s]*[=:]\s*\.?0?0[0-4]/.test(txt)) return {t:'significant', cls:'sig', c:'#1f9e44'};
    return {t:'', cls:'', c:'#5f6368'};
  }

  function fetchJSON(url, opts){
    return fetch(url, Object.assign({credentials:'same-origin',headers:{Accept:'application/json'}}, opts||{}))
      .then(function(r){ return r.ok ? r.json() : null; }).catch(function(){ return null; });
  }

  var STATE = { items:[], sample:{n:0,k:0}, ai:null };

  function loadAll(){
    if (!PID){ host.innerHTML = '<div class="center"><p><strong>No project selected.</strong></p><p class="muted">Open this report with a project in the address, for example:<br><code>/analysis-report.php?project_id=YOUR_PROJECT_ID</code></p><p class="muted">You can get the id from your studio URL (the <code>project_id=</code> value).</p></div>'; return; }
    Promise.all([
      fetchJSON('/api/analysis/results.php?project_id=' + PID),
      fetchJSON('/api/analysis/dataset.php?project_id=' + PID)
    ]).then(function(res){
      var rd = res[0], dd = res[1];
      var items = (rd && Array.isArray(rd.results)) ? rd.results : [];
      STATE.items = items;
      var ds = dd && (dd.dataset || dd.data || dd);
      if (ds){ STATE.sample.n = ds.rowCount || ds.n || (ds.analyzable_n||0) || 0; STATE.sample.k = (ds.variables && ds.variables.length) || 0; }
      if (!items.length){
        host.innerHTML = '<div class="center">No saved analyses yet. In the studio, run a step and click <strong>Save to report</strong>, then come back.</div>';
        return;
      }
      generateNarrative(/*then*/render);
    });
  }

  function buildAnalysesPayload(){
    return STATE.items.map(function(it){
      var c = extractClean(it);
      return {
        test: toolLabel(it.tool_key),
        variables: c.vars,
        stats: kvCompact(c.kv),
        finding: kvCompact(c.kv)
      };
    });
  }

  function generateNarrative(then){
    if (!PID){ STATE.ai = null; then(); return; }
    var payload = { project_id: PID, analyses: buildAnalysesPayload(), sample: STATE.sample };
    // project_id is also on the URL as a fallback against body-dropping redirects.
    fetch('/api/analysis/ai-inferential-report.php?project_id=' + PID, {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(d){ STATE.ai = (d && d.ok) ? d.report : null; STATE.aiError = (d && !d.ok) ? (d.message || d.error || 'narrative unavailable') : null; then(); })
      .catch(function(){ STATE.ai = null; STATE.aiError = 'network error'; then(); });
  }

  function render(){
    var ai = STATE.ai, items = STATE.items;
    var today = new Date().toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'});
    var distinctTests = [];
    items.forEach(function(it){ var l=toolLabel(it.tool_key); if(distinctTests.indexOf(l)<0) distinctTests.push(l); });

    var h = '<div class="doc">';
    // Title block
    h += '<h1>' + esc(ai && ai.headline ? ai.headline : PROJECT_TITLE + ': Analysis Report') + '</h1>';
    h += '<div class="meta">' + esc(PROJECT_TITLE) + ' &nbsp;·&nbsp; ' + esc(today)
       + (STATE.sample.n ? ' &nbsp;·&nbsp; N = ' + STATE.sample.n : '')
       + ' &nbsp;·&nbsp; ' + items.length + ' analys' + (items.length===1?'is':'es') + '</div>';

    // Overview (AI) or deterministic fallback
    if (ai && ai.overview){
      h += '<p class="lead">' + esc(ai.overview) + '</p>';
    } else {
      h += '<p class="lead">This report summarizes ' + items.length + ' analys' + (items.length===1?'is':'es')
         + ' conducted on ' + esc(PROJECT_TITLE) + (STATE.sample.n ? ' (N = ' + STATE.sample.n + ')' : '') + '.</p>';
    }

    // Methods
    h += '<h2>Methods</h2>';
    h += '<p>The following analys' + (items.length===1?'is was':'es were') + ' performed'
       + (STATE.sample.n ? ' on a sample of ' + STATE.sample.n + ' cases' : '')
       + (STATE.sample.k ? ' across ' + STATE.sample.k + ' variables' : '') + ': '
       + distinctTests.map(esc).join(', ') + '.</p>';

    // Results — clean table, one row per saved analysis (parsed, not a snapshot)
    h += '<h2>Results</h2>';
    h += '<table class="rtable"><thead><tr><th>Analysis</th><th>Variables</th><th>Key result</th><th>Decision</th></tr></thead><tbody>';
    items.forEach(function(it){
      var c = extractClean(it), dec = decisionFromKv(c.kv);
      h += '<tr><td>' + esc(toolLabel(it.tool_key)) + '</td>'
         + '<td>' + esc(c.vars || '—') + '</td>'
         + '<td class="num">' + esc(kvCompact(c.kv) || '—') + '</td>'
         + '<td>' + (dec.t ? '<span class="tag ' + dec.cls + '">' + dec.t + '</span>' : '—') + '</td></tr>';
    });
    h += '</tbody></table>';

    // Synthesis (AI)
    if (ai && ai.synthesis){
      h += '<h2>Interpretation</h2>';
      h += '<div class="ai-tag">ReliCheck Intelligence</div>';
      ai.synthesis.split(/\n+/).forEach(function(par){ if(par.trim()) h += '<p>' + esc(par.trim()) + '</p>'; });
    }

    // Limitations
    var lims = (ai && ai.limitations && ai.limitations.length) ? ai.limitations : [
      'These results describe the sample analyzed and may not generalize beyond it.',
      'Statistical significance does not establish causation.',
      'When several tests are run together, some results can reach significance by chance.'
    ];
    h += '<h2>Limitations</h2><ul>' + lims.map(function(l){ return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>';

    if (!ai){
      h += '<div class="note">The narrative layer could not be generated this time'
        + (STATE.aiError ? ' (' + esc(STATE.aiError) + ')' : '')
        + ', so the report shows the deterministic structure and exact numbers below. '
        + '<button class="btn" id="btnRetryNarr" style="margin-left:8px">Retry narrative</button></div>';
    }
    h += '</div>';
    host.innerHTML = h;
    var rb = document.getElementById('btnRetryNarr');
    if (rb) rb.addEventListener('click', function(){
      rb.disabled = true; rb.textContent = 'Retrying…';
      generateNarrative(function(){ render(); });
    });
  }

  // ── One-click PDF (client-side, real text via pdfmake) ──────────────────────
  var PDFMAKE_LOADED = null;
  function loadPdfMake(){
    if (PDFMAKE_LOADED) return PDFMAKE_LOADED;
    PDFMAKE_LOADED = new Promise(function(resolve, reject){
      if (window.pdfMake && window.pdfMake.createPdf) return resolve();
      var base = 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/';
      var s1 = document.createElement('script'); s1.src = base + 'pdfmake.min.js';
      s1.onload = function(){
        var s2 = document.createElement('script'); s2.src = base + 'vfs_fonts.js';
        s2.onload = function(){ resolve(); };
        s2.onerror = function(){ reject(new Error('font load failed')); };
        document.head.appendChild(s2);
      };
      s1.onerror = function(){ reject(new Error('pdfmake load failed')); };
      document.head.appendChild(s1);
    });
    return PDFMAKE_LOADED;
  }

  function buildPdfDef(){
    var ai = STATE.ai, items = STATE.items;
    var today = new Date().toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'});
    var distinctTests = [];
    items.forEach(function(it){ var l=toolLabel(it.tool_key); if(distinctTests.indexOf(l)<0) distinctTests.push(l); });
    var content = [];

    content.push({ text: (ai && ai.headline) ? ai.headline : (PROJECT_TITLE + ': Analysis Report'), style:'h1' });
    content.push({ text: PROJECT_TITLE + '   ·   ' + today
      + (STATE.sample.n ? '   ·   N = ' + STATE.sample.n : '')
      + '   ·   ' + items.length + ' analys' + (items.length===1?'is':'es'), style:'meta' });

    if (ai && ai.overview) content.push({ text: ai.overview, style:'lead' });

    content.push({ text:'Methods', style:'h2' });
    content.push({ text: 'The following analys' + (items.length===1?'is was':'es were') + ' performed'
      + (STATE.sample.n ? ' on a sample of ' + STATE.sample.n + ' cases' : '')
      + (STATE.sample.k ? ' across ' + STATE.sample.k + ' variables' : '') + ': '
      + distinctTests.join(', ') + '.', margin:[0,0,0,4] });

    content.push({ text:'Results', style:'h2' });
    var tbody = [[
      {text:'Analysis', style:'th'},{text:'Variables', style:'th'},{text:'Key result', style:'th'},{text:'Decision', style:'th'}
    ]];
    items.forEach(function(it){
      var c = extractClean(it), dec = decisionFromKv(c.kv);
      tbody.push([
        { text: toolLabel(it.tool_key), bold:true },
        { text: c.vars || '—', color:'#3a4050' },
        { text: kvCompact(c.kv) || '—', color:'#3a4050' },
        { text: dec.t || '—', color: dec.c, bold: !!dec.t }
      ]);
    });
    content.push({ table:{ headerRows:1, widths:['20%','24%','*','auto'], body: tbody }, layout:{
      hLineColor:function(){return '#e5e8ef';}, vLineColor:function(){return '#e5e8ef';},
      hLineWidth:function(i){return i===1?1:0.5;}, vLineWidth:function(){return 0.5;},
      paddingTop:function(){return 5;}, paddingBottom:function(){return 5;}
    }, margin:[0,2,0,10] });

    if (ai && ai.synthesis){
      content.push({ text:'Interpretation', style:'h2' });
      content.push({ text:'ReliCheck Intelligence', style:'aitag' });
      ai.synthesis.split(/\n+/).forEach(function(p){ if(p.trim()) content.push({ text:p.trim(), margin:[0,0,0,8] }); });
    }

    var lims = (ai && ai.limitations && ai.limitations.length) ? ai.limitations : [
      'These results describe the sample analyzed and may not generalize beyond it.',
      'Statistical significance does not establish causation.',
      'When several tests are run together, some results can reach significance by chance.'
    ];
    content.push({ text:'Limitations', style:'h2' });
    content.push({ ul: lims, margin:[0,0,0,4] });

    return {
      info: { title: PROJECT_TITLE + ' Report', author: 'ReliCheck' },
      pageMargins: [48, 54, 48, 56],
      content: content,
      styles: {
        h1:    { fontSize:20, bold:true, margin:[0,0,0,3] },
        meta:  { fontSize:9, color:'#777', margin:[0,0,0,16] },
        lead:  { fontSize:12, margin:[0,0,0,12] },
        h2:    { fontSize:14, bold:true, margin:[0,16,0,6] },
        h3:    { fontSize:11, bold:true, margin:[0,10,0,2] },
        th:    { fontSize:8.5, bold:true, color:'#5a6070' },
        aitag: { fontSize:8, bold:true, color:'#1d4ed8', margin:[0,0,0,6] }
      },
      defaultStyle: { fontSize:10.5, lineHeight:1.3, color:'#1a1d23' },
      footer: function(cur,total){ return { columns:[
        { text: PROJECT_TITLE, fontSize:8, color:'#aaa', margin:[48,0,0,0] },
        { text: cur + ' / ' + total, alignment:'right', fontSize:8, color:'#aaa', margin:[0,0,48,0] }
      ] }; }
    };
  }

  function downloadPdf(){
    var btn = document.getElementById('btnPdf');
    btn.disabled = true; var old = btn.textContent; btn.textContent = 'Preparing PDF…';
    loadPdfMake().then(function(){
      var fname = (PROJECT_TITLE||'analysis').replace(/[^\w]+/g,'_') + '_report.pdf';
      window.pdfMake.createPdf(buildPdfDef()).download(fname);
      btn.disabled = false; btn.textContent = old;
    }).catch(function(){
      btn.disabled = false; btn.textContent = old;
      alert('Could not load the PDF generator (check your connection). Falling back to Print, where you can choose Save as PDF.');
      window.print();
    });
  }

  document.getElementById('btnPdf').addEventListener('click', downloadPdf);
  document.getElementById('btnPrint').addEventListener('click', function(){ window.print(); });
  document.getElementById('btnRegen').addEventListener('click', function(){
    var b=this; b.disabled=true; b.textContent='Regenerating…';
    generateNarrative(function(){ render(); b.disabled=false; b.textContent='Regenerate narrative'; });
  });
  document.getElementById('btnWord').addEventListener('click', function(){
    var docEl = host.querySelector('.doc'); if(!docEl) return;
    var css = '<style>body{font-family:Calibri,Arial,sans-serif;color:#15171a;font-size:11pt}h1{font-size:20pt}h2{font-size:13pt;border-bottom:1px solid #ccc}h3{font-size:11.5pt}table{border-collapse:collapse;width:100%;font-size:10pt}th,td{border:1px solid #ccc;padding:5px 8px;text-align:left}.meta{color:#666;border-bottom:2px solid #000;padding-bottom:8pt}.ai-tag{color:#1d4ed8;font-size:8pt;font-weight:bold}.note{border:1px solid #dbe4ff;background:#eff2ff;padding:8pt}</style>';
    var doc = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8">'+css+'</head><body>'+docEl.innerHTML+'</body></html>';
    var blob = new Blob(['﻿'+doc], {type:'application/msword'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = (PROJECT_TITLE||'analysis').replace(/[^\w]+/g,'_') + '_report.doc';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function(){ URL.revokeObjectURL(a.href); }, 1500);
  });

  loadAll();
})();
</script>
</body>
</html>
