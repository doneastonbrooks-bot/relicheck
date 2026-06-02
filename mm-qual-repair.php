<?php
// mm-qual-repair.php
//
// Standalone, self-contained repair utility for the MM Studio qualitative side.
// It is a friendly front-end over the already-deployed, tested endpoint
// /api/mm/reingest-dataset-text.php, which copies a project's open-ended
// dataset columns into mm_text_responses so the "Qualitative Themes" step can
// read them.
//
// This page adds NO new server logic and edits NO existing file. All work is
// done by the existing reingest endpoint, called same-origin so it carries the
// signed-in session. Safe to remove at any time.
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Repair qualitative data &middot; ReliCheck</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f4f5f7;color:#1f2330;margin:0;padding:32px}
  .card{max-width:680px;margin:0 auto;background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:24px 26px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
  h1{font-size:22px;margin:0 0 6px}
  p.sub{color:#667085;margin:0 0 14px;font-size:15px;line-height:1.5}
  label{display:block;font-weight:600;font-size:13px;margin:14px 0 6px}
  input{width:160px;font-size:16px;padding:9px 11px;border:1px solid #d0d5dd;border-radius:9px}
  button{font-size:15px;font-weight:600;color:#fff;background:#5b3df5;border:0;border-radius:10px;padding:11px 18px;cursor:pointer;margin-top:16px}
  button:disabled{opacity:.55;cursor:default}
  .out{margin-top:22px;padding:16px 18px;border-radius:10px;font-size:15px;line-height:1.55;display:none}
  .out.ok{background:#ecfdf3;border:1px solid #abefc6;color:#067647}
  .out.warn{background:#fffaeb;border:1px solid #fedf89;color:#b54708}
  .out.err{background:#fef3f2;border:1px solid #fecdca;color:#b42318}
  .out b{font-weight:700}
  code{background:#f2f4f7;padding:1px 6px;border-radius:5px;font-size:13px}
  ul{margin:8px 0 0 18px;padding:0}
</style>
</head>
<body>
  <div class="card">
    <h1>Repair qualitative data</h1>
    <p class="sub">This copies your open-ended answers into the place the <b>Qualitative Themes</b> step reads from. It only reads your uploaded data and rebuilds the qualitative text for one project. It does not change your survey, your dataset, or your themes.</p>

    <label for="pid">Project ID</label>
    <input id="pid" type="number" min="1" placeholder="e.g. 189">
    <p class="sub" style="margin-top:6px">Find this in the studio web address, after <code>project_id=</code>.</p>

    <button id="go">Copy my responses</button>

    <div id="out" class="out"></div>
  </div>

<script>
(function(){
  var q = new URLSearchParams(location.search);
  var pidInput = document.getElementById('pid');
  if (q.get('project_id')) pidInput.value = q.get('project_id');

  var btn = document.getElementById('go');
  var out = document.getElementById('out');

  function show(kind, html){ out.className = 'out ' + kind; out.style.display = 'block'; out.innerHTML = html; }
  function esc(s){ return String(s).replace(/[&<>]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; }); }

  btn.addEventListener('click', function(){
    var pid = parseInt(pidInput.value, 10);
    if (!pid || pid < 1){ show('warn', 'Please enter your Project ID first.'); return; }
    btn.disabled = true;
    show('warn', 'Working&hellip;');

    fetch('/api/mm/reingest-dataset-text.php', {
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
        var msg = j.message || j.error_message || j.error || ('HTTP ' + res.status);
        show('err', '<b>It could not run.</b><br>' + esc(String(msg)) +
          '<br><br>If it mentions no linked dataset, your file is not attached to this project yet.');
        return;
      }
      var respondents = j.respondent_count || 0;
      var made = j.text_rows_made || 0;
      var cols = j.open_columns || [];
      if (made > 0){
        show('ok',
          '<b>Done.</b> Found <b>' + respondents + '</b> respondents and copied <b>' + made + '</b> answers.' +
          (cols.length ? '<br>Columns treated as responses:<ul><li>' + cols.map(esc).join('</li><li>') + '</li></ul>' : '') +
          '<br><br>Go back to the <b>Qualitative Themes</b> step and reload. Your responses should be there now.');
      } else if (respondents > 0){
        show('warn',
          '<b>Your data is here</b> (' + respondents + ' respondents), but no column was copied.<br>' +
          'The columns you marked qualitative were skipped, most likely because the answers are short. ' +
          'Tell me this is what you see and I will fix the rule so it trusts the columns you confirmed.');
      } else {
        show('warn',
          '<b>No rows were found for this project.</b> The dataset may not be linked, or this Project ID may be wrong. ' +
          'Double-check the number after <code>project_id=</code> in the studio address.');
      }
    })
    .catch(function(e){
      btn.disabled = false;
      show('err', '<b>Something blocked the request.</b> ' + esc(e && e.message ? e.message : '') +
        '<br>Make sure you are signed in and opening this on the same site as your studio.');
    });
  });
})();
</script>
</body>
</html>
