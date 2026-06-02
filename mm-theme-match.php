<?php
// mm-theme-match.php
//
// Read-only viewer for the deterministic, codebook-anchored theme matcher
// (POST /api/mm/themes-deterministic.php). Shows, per theme, the words and
// phrases it derived from your codebook (so you can see and tune them) and the
// responses they matched, with the exact phrase that triggered each. It writes
// nothing. No AI. A dry run you can read.
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deterministic theme match &middot; ReliCheck</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f4f5f7;color:#1f2330;margin:0;padding:32px}
  .wrap{max-width:860px;margin:0 auto}
  .card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:22px 24px;box-shadow:0 1px 2px rgba(16,24,40,.04);margin-bottom:18px}
  h1{font-size:22px;margin:0 0 6px}
  p.sub{color:#667085;margin:0 0 14px;font-size:15px;line-height:1.5}
  label{display:block;font-weight:600;font-size:13px;margin:14px 0 6px}
  input{width:160px;font-size:16px;padding:9px 11px;border:1px solid #d0d5dd;border-radius:9px}
  button{font-size:15px;font-weight:600;color:#fff;background:#5b3df5;border:0;border-radius:10px;padding:11px 18px;cursor:pointer;margin-top:16px}
  button:disabled{opacity:.55;cursor:default}
  .msg{margin-top:18px;padding:14px 16px;border-radius:10px;font-size:15px;line-height:1.5;display:none}
  .msg.warn{background:#fffaeb;border:1px solid #fedf89;color:#b54708}
  .msg.err{background:#fef3f2;border:1px solid #fecdca;color:#b42318}
  .summary{font-size:15px;color:#344054;margin:0}
  .theme-h{display:flex;justify-content:space-between;align-items:baseline;gap:12px}
  .theme-h h2{font-size:18px;margin:0}
  .pill{font-size:12px;font-weight:700;color:#3538cd;background:#eef0ff;border-radius:999px;padding:3px 10px;white-space:nowrap}
  .lab{font-size:12px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.03em;margin:14px 0 6px}
  .chips{display:flex;flex-wrap:wrap;gap:6px}
  .chip{font-size:13px;background:#f2f4f7;border:1px solid #e4e7ec;border-radius:7px;padding:2px 8px;color:#344054}
  .chip.ph{background:#f0f9f5;border-color:#cdeede;color:#067647}
  .ex{max-height:300px;overflow:auto;border:1px solid #eef0f3;border-radius:10px;margin-top:6px}
  .ex-row{padding:10px 12px;border-bottom:1px solid #f2f4f7;font-size:14px;line-height:1.45}
  .ex-row:last-child{border-bottom:0}
  .ex-meta{font-size:12px;color:#98a2b3;margin-top:3px}
  .neg{color:#b54708;font-weight:700}
  .none{color:#98a2b3;font-size:14px;margin-top:6px}
  code{background:#f2f4f7;padding:1px 6px;border-radius:5px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Deterministic theme match</h1>
    <p class="sub">A read-only preview. It matches your responses to your themes using the words from your codebook (definitions and inclusion rules), shown under each theme so you can see exactly what drives it. Nothing is saved, and no AI is used here. AI would be a later, separate layer on top.</p>
    <label for="pid">Project ID</label>
    <input id="pid" type="number" min="1" placeholder="e.g. 190">
    <p class="sub" style="margin-top:6px">Find this in the studio web address, after <code>project_id=</code>.</p>
    <button id="go">Run the match</button>
    <div id="msg" class="msg"></div>
  </div>
  <div id="results"></div>
</div>

<script>
(function(){
  var q = new URLSearchParams(location.search);
  var pidInput = document.getElementById('pid');
  if (q.get('project_id')) pidInput.value = q.get('project_id');

  var btn = document.getElementById('go');
  var msg = document.getElementById('msg');
  var results = document.getElementById('results');

  function showMsg(kind, html){ msg.className = 'msg ' + kind; msg.style.display = 'block'; msg.innerHTML = html; }
  function hideMsg(){ msg.style.display = 'none'; }
  function esc(s){ return String(s).replace(/[&<>]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; }); }

  function chips(arr, cls){
    if (!arr || !arr.length) return '<span class="none">none derived</span>';
    return '<div class="chips">' + arr.map(function(t){ return '<span class="chip ' + (cls||'') + '">' + esc(t) + '</span>'; }).join('') + '</div>';
  }

  function renderTheme(t){
    var head = '<div class="theme-h"><h2>' + esc(t.name) + '</h2><span class="pill">' + (t.match_count||0) + ' matched</span></div>';
    var terms = '<div class="lab">Matching on these words</div>' + chips(t.terms);
    var phrases = (t.phrases && t.phrases.length) ? ('<div class="lab">And these phrases</div>' + chips(t.phrases, 'ph')) : '';
    var excl = (t.exclude && t.exclude.length) ? ('<div class="lab">Excluded by</div>' + chips(t.exclude)) : '';
    var ex = '';
    if (t.samples && t.samples.length){
      ex = '<div class="lab">Examples</div><div class="ex">' + t.samples.map(function(s){
        return '<div class="ex-row">' + esc(s.evidence) +
          '<div class="ex-meta">' + esc(s.ref) + ' &middot; matched <code>' + esc(s.term) + '</code>' +
          (s.negated ? ' &middot; <span class="neg">reads as negated</span>' : '') + '</div></div>';
      }).join('') + '</div>';
    } else {
      ex = '<div class="none">No responses matched this theme. Its codebook words may be missing from how people actually wrote.</div>';
    }
    return '<div class="card">' + head + terms + phrases + excl + ex + '</div>';
  }

  btn.addEventListener('click', function(){
    var pid = parseInt(pidInput.value, 10);
    if (!pid || pid < 1){ showMsg('warn', 'Please enter your Project ID first.'); return; }
    btn.disabled = true; hideMsg(); results.innerHTML = '';
    showMsg('warn', 'Matching&hellip;');
    fetch('/api/mm/themes-deterministic.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ project_id: pid })
    })
    .then(function(r){ return r.json().then(function(j){ return { status: r.status, j: j }; }); })
    .then(function(res){
      btn.disabled = false;
      var j = res.j || {};
      if (!j.ok){
        var m = j.message || j.error_message || j.error || ('HTTP ' + res.status);
        showMsg('err', '<b>It could not run.</b><br>' + esc(String(m)) +
          '<br><br>If it says no themes exist, add your themes and codebook first. If it says no responses, run the repair page first.');
        return;
      }
      hideMsg();
      var head = '<div class="card"><p class="summary"><b>' + (j.responses_matched||0) + '</b> of <b>' +
        (j.response_count||0) + '</b> responses matched at least one theme, across ' +
        ((j.themes||[]).length) + ' themes. This is a preview only; nothing was saved.</p></div>';
      results.innerHTML = head + (j.themes||[]).map(renderTheme).join('');
    })
    .catch(function(e){
      btn.disabled = false;
      showMsg('err', '<b>Something blocked the request.</b> ' + esc(e && e.message ? e.message : '') +
        '<br>Make sure you are signed in on the same site as your studio.');
    });
  });
})();
</script>
</body>
</html>
