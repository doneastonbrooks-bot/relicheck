// Analysis Studios — in-workspace upload popup (no full-page SIRI detour).
// A self-contained modal: drag/drop, choose a file, or paste CSV/TSV; confirm
// each column's role; then persist through THIS studio's endpoints
// (/api/analysis/projects.php to create a project if needed, then
// /api/analysis/save-dataset.php). Exposed as window.AnalysisUpload.open(ctx).
//
// ctx = { kind, projectId, onLoaded(dataset, projectId) }
(function () {
  'use strict';
  const ROLES = [
    { v: 'id',          label: 'ID' },
    { v: 'categorical', label: 'Categorical' },
    { v: 'likert',      label: 'Likert / Scale' },
    { v: 'numeric',     label: 'Numeric' },
    { v: 'open',        label: 'Open-ended' },
    { v: 'date',        label: 'Date' },
    { v: 'ignore',      label: 'Ignore' },
  ];
  const ROLE_TO_TYPE = { id:'identifier', categorical:'single', likert:'likert', numeric:'numeric', open:'open', date:'open' };

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  function num(v){ if(v===''||v==null) return null; const x=parseFloat(v); return isFinite(x)?x:null; }

  // ---- CSV / TSV parsing (handles quoted fields) ----
  function parse(text){
    text = String(text||'').replace(/\r\n?/g,'\n').replace(/\n+$/,'');
    if (!text.trim()) return { headers:[], rows:[] };
    const firstLine = text.split('\n',1)[0];
    const delim = firstLine.indexOf('\t')>=0 ? '\t' : ',';
    const lines = splitRows(text, delim);
    if (!lines.length) return { headers:[], rows:[] };
    const headers = lines[0].map(function(h,i){ h=String(h).trim(); return h || ('Column '+(i+1)); });
    const rows = lines.slice(1).filter(function(r){ return r.some(function(c){ return String(c).trim()!==''; }); });
    return { headers: headers, rows: rows };
  }
  function splitRows(text, delim){
    const out=[]; let row=[], field='', q=false;
    for (let i=0;i<text.length;i++){
      const c=text[i];
      if (q){ if(c==='"'){ if(text[i+1]==='"'){ field+='"'; i++; } else q=false; } else field+=c; }
      else if (c==='"') q=true;
      else if (c===delim){ row.push(field); field=''; }
      else if (c==='\n'){ row.push(field); out.push(row); row=[]; field=''; }
      else field+=c;
    }
    row.push(field); out.push(row);
    return out;
  }

  function detectRole(values, colIdx){
    const nm = values.filter(function(v){ return v!=null && String(v).trim()!==''; });
    if (!nm.length) return 'ignore';
    const distinct = new Set(nm.map(String));
    const numFrac = nm.filter(function(v){ return num(v)!==null; }).length / nm.length;
    if (numFrac >= 0.85) {
      const allInt = nm.every(function(v){ const x=num(v); return x!==null && Number.isInteger(x); });
      const lo = Math.min.apply(null, nm.map(num)), hi = Math.max.apply(null, nm.map(num));
      if (allInt && lo>=0 && hi<=11 && distinct.size<=11) return 'likert';
      return 'numeric';
    }
    const avgLen = nm.reduce(function(s,v){ return s + String(v).length; },0)/nm.length;
    const dateLike = nm.filter(function(v){ return /\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4}/.test(String(v)); }).length/nm.length;
    if (dateLike >= 0.7) return 'date';
    if (distinct.size === nm.length && colIdx === 0) return 'id';
    if (avgLen > 25 || distinct.size > Math.max(20, nm.length*0.6)) return 'open';
    if (distinct.size <= 20) return 'categorical';
    return 'open';
  }

  function buildDataset(parsed, roles, source){
    const variables = [];
    parsed.headers.forEach(function(name, ci){
      const role = roles[ci];
      if (role === 'ignore') return;
      const values = parsed.rows.map(function(r){ return r[ci]!=null ? r[ci] : ''; });
      variables.push({ name: name, types: [ROLE_TO_TYPE[role] || 'open'], values: values });
    });
    return { source: source || 'Uploaded data', variables: variables, rowCount: parsed.rows.length };
  }

  // ---- Modal ----
  function open(ctx){
    ctx = ctx || {};
    const overlay = document.createElement('div');
    overlay.className = 'au-overlay';
    overlay.innerHTML =
      '<div class="au-panel" role="dialog" aria-label="Bring in your data">'
      + '<button class="au-close" aria-label="Close">&times;</button>'
      + '<h2 class="au-title">Bring in your data</h2>'
      + '<p class="au-sub">Drag a file, paste from a spreadsheet, or choose a file. CSV, TSV, or tab-separated text.</p>'
      + '<div class="au-stage" id="auStage"></div>'
      + '</div>';
    document.body.appendChild(overlay);
    const close = function(){ overlay.remove(); };
    overlay.addEventListener('click', function(e){ if(e.target===overlay) close(); });
    overlay.querySelector('.au-close').addEventListener('click', close);
    const stage = overlay.querySelector('#auStage');
    let fileName = 'Uploaded data';

    function showDrop(){
      stage.innerHTML =
        '<div class="au-drop" id="auDrop">'
        + '<div class="au-drop-ico">&#8681;</div>'
        + '<div class="au-drop-h">Drop your data file here</div>'
        + '<div class="au-drop-sub">CSV, TSV, or tab-separated text</div>'
        + '<div class="au-drop-actions"><button class="au-btn" id="auPaste">Paste data</button>'
        + '<span class="au-or">or</span><button class="au-btn primary" id="auChoose">Choose file</button></div>'
        + '<input type="file" id="auFile" accept=".csv,.tsv,.txt,text/csv,text/plain" hidden>'
        + '</div>'
        + '<textarea id="auPasteBox" class="au-paste" placeholder="Paste tab- or comma-separated rows here, including the header row…" hidden></textarea>'
        + '<div class="au-paste-actions" hidden id="auPasteActions"><button class="au-btn primary" id="auPasteGo">Use pasted data</button></div>';
      const drop = stage.querySelector('#auDrop');
      const fileInput = stage.querySelector('#auFile');
      stage.querySelector('#auChoose').addEventListener('click', function(){ fileInput.click(); });
      fileInput.addEventListener('change', function(){ const f=fileInput.files[0]; if(f){ fileName=f.name.replace(/\.[^.]+$/,''); f.text().then(function(t){ toConfirm(parse(t)); }); } });
      ['dragover','dragenter'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('over'); }); });
      ['dragleave','drop'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('over'); }); });
      drop.addEventListener('drop', function(e){ const f=e.dataTransfer.files[0]; if(f){ fileName=f.name.replace(/\.[^.]+$/,''); f.text().then(function(t){ toConfirm(parse(t)); }); } });
      stage.querySelector('#auPaste').addEventListener('click', function(){
        const box=stage.querySelector('#auPasteBox'), act=stage.querySelector('#auPasteActions');
        box.hidden=false; act.hidden=false; box.focus();
      });
      stage.querySelector('#auPasteGo').addEventListener('click', function(){ const t=stage.querySelector('#auPasteBox').value; fileName='Pasted data'; toConfirm(parse(t)); });
    }

    function toConfirm(parsed){
      if (!parsed.headers.length || !parsed.rows.length){ alert('Could not read any rows. Check the file or paste.'); return; }
      const roles = parsed.headers.map(function(h,ci){ return detectRole(parsed.rows.map(function(r){ return r[ci]; }), ci); });
      const rolesSel = function(ci){ return '<select class="au-role ed-in" data-col="'+ci+'">' + ROLES.map(function(r){ return '<option value="'+r.v+'"'+(r.v===roles[ci]?' selected':'')+'>'+esc(r.label)+'</option>'; }).join('') + '</select>'; };
      const sample = function(ci){ return parsed.rows.slice(0,3).map(function(r){ return esc(String(r[ci]==null?'':r[ci])).slice(0,18); }).filter(Boolean).join(', '); };
      stage.innerHTML =
        '<p class="au-confirm-h">Confirm each column’s role. '+parsed.rows.length+' rows · '+parsed.headers.length+' columns.</p>'
        + '<div class="au-table-wrap"><table class="au-table"><thead><tr><th>Column</th><th>Role</th><th>Sample values</th></tr></thead><tbody>'
        + parsed.headers.map(function(h,ci){ return '<tr><td class="au-col">'+esc(h)+'</td><td>'+rolesSel(ci)+'</td><td class="au-sample">'+sample(ci)+'</td></tr>'; }).join('')
        + '</tbody></table></div>'
        + '<div class="au-confirm-actions"><button class="au-btn" id="auBack">&larr; Back</button>'
        + '<button class="au-btn primary" id="auUse">Use this data &rarr;</button></div>'
        + '<div class="au-msg" id="auMsg"></div>';
      stage.querySelector('#auBack').addEventListener('click', showDrop);
      stage.querySelector('#auUse').addEventListener('click', function(){
        const sels = stage.querySelectorAll('.au-role');
        const roles2 = []; sels.forEach(function(s){ roles2[+s.getAttribute('data-col')] = s.value; });
        const dataset = buildDataset(parsed, roles2, fileName);
        if (!dataset.variables.length){ stage.querySelector('#auMsg').textContent = 'Every column is set to Ignore — keep at least one.'; return; }
        persist(dataset, stage.querySelector('#auUse'), stage.querySelector('#auMsg'));
      });
    }

    function persist(dataset, btn, msg){
      btn.disabled = true; btn.textContent = 'Saving…';
      const payload = { studio: ctx.kind, dataset: dataset };
      function save(pid){
        return fetch('/api/analysis/save-dataset.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ project_id: pid, payload: payload }) })
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (!d || !d.ok) throw new Error('save failed');
            try { window.localStorage.setItem('relicheck.dataset.'+pid, JSON.stringify({ savedAt: Date.now(), studio: ctx.kind, payload: payload })); } catch(e){}
            close();
            if (ctx.onLoaded) ctx.onLoaded(dataset, pid);
          });
      }
      const go = ctx.projectId
        ? save(ctx.projectId)
        : fetch('/api/analysis/projects.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ kind: ctx.kind, title: dataset.source }) })
            .then(function(r){ return r.json(); })
            .then(function(d){ if(!d||!d.ok||!d.project) throw new Error('create failed'); return save(d.project.id); });
      go.catch(function(){ btn.disabled=false; btn.textContent='Use this data →'; if(msg) msg.textContent='Could not save your data. Please try again.'; });
    }

    showDrop();
  }

  window.AnalysisUpload = { open: open };
})();
