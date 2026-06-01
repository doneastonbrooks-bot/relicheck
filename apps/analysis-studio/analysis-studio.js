// Analysis Studios — work-step presentation engine (Descriptive first).
// Computes client-side from the engine-shape dataset
//   { variables:[{name, types:[...], values:[...]}], rowCount }
// and renders in MM Studio's dx-table + interpretation-layer style (a fresh
// reproduction — no MM files are touched). Exposed as window.AnalysisStudio;
// the v4 shell calls AnalysisStudio.renderWork(host, {kind, tool, dataset}).
(function () {
  'use strict';
  const AS = {};
  const sel = {}; // per-tool selection memory

  // ---- helpers ----
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  function num(v){ if(v===''||v==null) return null; const x=parseFloat(v); return isFinite(x)?x:null; }
  function isMissing(v){ return v===''||v==null; }
  function nonMissing(vals){ return (vals||[]).filter(function(v){ return !isMissing(v); }); }
  function nums(vals){ return (vals||[]).map(num).filter(function(v){ return v!==null; }); }
  function mean(a){ return a.length? a.reduce(function(s,v){return s+v;},0)/a.length : 0; }
  function variance(a){ if(a.length<2) return 0; const m=mean(a); return a.reduce(function(s,v){return s+(v-m)*(v-m);},0)/(a.length-1); }
  function sd(a){ return Math.sqrt(variance(a)); }
  function median(a){ if(!a.length) return 0; const s=a.slice().sort(function(x,y){return x-y;}); const m=Math.floor(s.length/2); return s.length%2?s[m]:(s[m-1]+s[m])/2; }
  function n2(x){ return (x==null||!isFinite(x))?'—':(Math.round(x*100)/100).toFixed(2); }
  function pc(x){ return (x==null||!isFinite(x))?'—':(Math.round(x*10)/10).toFixed(1)+'%'; }
  function trim(s,n){ s=String(s==null?'':s); return s.length>n ? s.slice(0,n-1)+'…' : s; }

  // ---- Curated, type-aware SVG charts (no library) ----
  let VIEW = 'table';
  const DONUT_COLORS = ['#c2271b','#c79114','#0A6FE8','#1f9e44','#8A4FD0','#e07a3a'];
  function svgBarsH(rows, opts){
    opts = opts || {}; if (!rows.length) return '';
    const vf = opts.valueFmt || function(v){ return String(v); };
    const max = Math.max.apply(null, rows.map(function(r){ return Math.abs(r.value||0); })) || 1;
    const rowH=30, gap=8, labelW=150, valW=70, innerW=300;
    const w=labelW+innerW+valW, h=rows.length*(rowH+gap)+8;
    let s='<svg class="as-chart" viewBox="0 0 '+w+' '+h+'" preserveAspectRatio="xMinYMin meet" width="100%">';
    rows.forEach(function(r,i){ const y=4+i*(rowH+gap); const bw=Math.max(2,(Math.abs(r.value||0)/max)*innerW);
      s+='<text x="'+(labelW-8)+'" y="'+(y+rowH/2+4)+'" text-anchor="end" font-size="12" fill="var(--ink-2,#5f6368)">'+esc(trim(r.label,22))+'</text>';
      s+='<rect x="'+labelW+'" y="'+y+'" width="'+bw.toFixed(1)+'" height="'+rowH+'" rx="4" fill="var(--acc,#c2271b)" opacity="0.88"/>';
      s+='<text x="'+(labelW+bw+6)+'" y="'+(y+rowH/2+4)+'" font-size="12" font-weight="600" fill="var(--ink,#15171a)">'+esc(vf(r.value))+'</text>';
    });
    return s+'</svg>';
  }
  function svgDonut(rows){
    const total = rows.reduce(function(s,r){ return s+(r.value||0); },0) || 1;
    const R=64, C=2*Math.PI*R, cx=82, cy=82; let off=0, segs='', legend='';
    rows.forEach(function(r,i){ const frac=(r.value||0)/total; const len=frac*C; const col=DONUT_COLORS[i%DONUT_COLORS.length];
      segs+='<circle cx="'+cx+'" cy="'+cy+'" r="'+R+'" fill="none" stroke="'+col+'" stroke-width="26" stroke-dasharray="'+len.toFixed(2)+' '+(C-len).toFixed(2)+'" stroke-dashoffset="'+(-off).toFixed(2)+'" transform="rotate(-90 '+cx+' '+cy+')"/>';
      off+=len;
      legend+='<div style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:6px"><span style="width:11px;height:11px;border-radius:3px;background:'+col+';display:inline-block;flex:none"></span>'+esc(r.label)+' · '+Math.round(frac*100)+'%</div>';
    });
    return '<div style="display:flex;gap:26px;align-items:center;flex-wrap:wrap;padding:6px 0"><svg width="164" height="164" viewBox="0 0 164 164" style="flex:none">'+segs+'</svg><div>'+legend+'</div></div>';
  }
  function svgHist(values, opts){
    opts=opts||{}; const v=values.filter(function(x){ return x!=null && isFinite(x); }); if(!v.length) return '';
    const min=Math.min.apply(null,v), max=Math.max.apply(null,v), bins=opts.bins||10, span=(max-min)||1, bw=span/bins;
    const counts=new Array(bins).fill(0); v.forEach(function(x){ let b=Math.floor((x-min)/bw); if(b>=bins)b=bins-1; if(b<0)b=0; counts[b]++; });
    const maxc=Math.max.apply(null,counts)||1, barW=34, gap=6, innerH=160, w=bins*(barW+gap)+20, h=innerH+40;
    let s='<svg class="as-chart" viewBox="0 0 '+w+' '+h+'" preserveAspectRatio="xMinYMin meet" width="100%">';
    counts.forEach(function(c,i){ const x=10+i*(barW+gap); const bh=Math.max(1,(c/maxc)*innerH); const y=10+innerH-bh;
      s+='<rect x="'+x+'" y="'+y+'" width="'+barW+'" height="'+bh+'" rx="3" fill="var(--acc,#c2271b)" opacity="0.88"/>';
      s+='<text x="'+(x+barW/2)+'" y="'+(y-4)+'" text-anchor="middle" font-size="10" fill="var(--ink-2,#5f6368)">'+c+'</text>';
      if(i===0) s+='<text x="'+(x+barW/2)+'" y="'+(innerH+26)+'" text-anchor="middle" font-size="10" fill="var(--ink-3,#8a8f98)">'+n2(min)+'</text>';
      if(i===bins-1) s+='<text x="'+(x+barW/2)+'" y="'+(innerH+26)+'" text-anchor="middle" font-size="10" fill="var(--ink-3,#8a8f98)">'+n2(max)+'</text>';
    });
    return s+'</svg>';
  }
  function chartWrap(inner){ return '<div class="as-chart-wrap">'+inner+'</div>'; }
  function chartTypeSeg(opts, cur){ if(!opts || opts.length<2) return ''; return '<div class="as-chart-seg">'+opts.map(function(o){ return '<button data-c="'+esc(o.k)+'" class="'+(o.k===cur?'on':'')+'">'+esc(o.l)+'</button>'; }).join('')+'</div>'; }
  function wireChartSeg(host, cb){ host.querySelectorAll('.as-chart-seg button').forEach(function(b){ b.addEventListener('click', function(){ cb(b.getAttribute('data-c')); }); }); }

  function isNumericVar(v){ const t=(v.types||[]).join(',').toLowerCase(); if(/likert|numeric/.test(t)) return true; const nm=nonMissing(v.values); if(!nm.length) return false; const ok=nm.filter(function(x){return num(x)!==null;}).length; return ok/nm.length>=0.8; }
  function isCategoricalVar(v){ const t=(v.types||[]).join(',').toLowerCase(); if(/single|multi|categor|demograph|identifier/.test(t)) return true; const nm=nonMissing(v.values); const d=new Set(nm); return d.size>1 && d.size<=20; }
  function numericVars(ds){ return ds.variables.filter(isNumericVar); }
  function catVars(ds){ return ds.variables.filter(isCategoricalVar); }
  // Variables you can sensibly take a FREQUENCY of: categorical or Likert
  // (discrete) — never open-ended free text, identifiers, or dates.
  function discreteVars(ds){ return ds.variables.filter(function(v){
    const t=(v.types||[]).join(',').toLowerCase();
    if(/open|identifier|free_text|date/.test(t)) return false;
    if(/likert|single|multi|categor|demograph/.test(t)) return true;
    const nm=nonMissing(v.values); const d=new Set(nm).size;
    return d>1 && d<=15 && d < nm.length*0.6; // discrete-ish, not mostly-unique text
  }); }
  function varByName(ds,name){ return ds.variables.find(function(v){return v.name===name;}); }

  // ---- "How to use this" help (mirrors MM's per-step guidance) ----
  const HELP = {
    overview: { title:'the Overview', what:'The Overview reads your dataset before any analysis runs: how many rows and variables you have, each variable’s role, and where values are missing.',
      steps:['Check the Rows / Variables / Numeric summary cards.','Scan the Variables table for each variable’s type.','Watch the Missing column — high missingness weakens later results.','When it looks right, Continue to analysis.'],
      example:'<strong>Reading it:</strong> 250 rows, 12 variables, 8 numeric, with 0 missing on the key items means you can analyze every case with confidence.' },
    frequencies: { title:'Frequencies', what:'Frequencies count how often each value of a categorical or Likert variable appears, with percentages and a running cumulative total.',
      steps:['Pick a categorical or Likert variable.','Read the count and Valid % for each level (sorted most→least common).','Use the Cumulative % to see where most responses fall.'],
      example:'<strong>Reading it:</strong> if "Agree" + "Strongly agree" together are 70% of valid responses, most respondents lean positive on that item.' },
    distributions: { title:'Means & Distributions', what:'Summary statistics for every numeric variable: center (mean, median), spread (SD), and range (min–max), plus missing counts.',
      steps:['Compare each variable’s mean and median — a gap signals skew.','Read the SD for spread (small = clustered, large = spread out).','Note missing counts before using a variable downstream.'],
      example:'<strong>Reading it:</strong> a mean of 3.9 with a median of 4.0 and SD of 0.9 (1–5 scale) is a mild left-leaning, fairly tight distribution.' },
    cross_tabs: { title:'Cross-Tabs', what:'A two-variable contingency table: counts and row percentages for how one categorical variable breaks down across another.',
      steps:['Choose a row variable and a column variable.','Read each cell’s count and row % (each row sums to 100%).','Compare rows to spot patterns between the two variables.'],
      example:'<strong>Reading it:</strong> if 80% of one group picks option A but only 40% of another does, that gap is worth testing in the Inferential Studio.' },
    group_summaries: { title:'Group Summaries', what:'Per-group means for a numeric outcome, with each group’s gap (Δ) from the overall mean.',
      steps:['Pick a numeric outcome and a grouping variable.','Read each group’s mean and its Δ from overall.','Larger Δs flag groups that stand out (descriptively).'],
      example:'<strong>Reading it:</strong> a group sitting +0.6 above the overall mean scores noticeably higher — confirm with a t-test / ANOVA.' },
    top_bottom_items: { title:'Top & Bottom Items', what:'Every numeric item ranked by mean, with flags for low-variance, ceiling, and floor effects.',
      steps:['Scan the highest- and lowest-scoring items.','Check the Flags column for items that may not discriminate.','Use this to spot strong and weak items at a glance.'],
      example:'<strong>Reading it:</strong> an item flagged "ceiling" (almost everyone picks the top option) tells you little about differences between respondents.' },
    scale_scores: { title:'Scale Scores', what:'Combine several numeric items into one composite (the average of the chosen items per respondent). Descriptive only — no reliability.',
      steps:['Tick the items that belong to the scale (at least two).','Read the composite’s N, mean, and SD.','For reliability (Cronbach’s α), use RSSI — not here.'],
      example:'<strong>Reading it:</strong> a 4-item composite with mean 3.8 / SD 0.7 summarizes the scale; whether the items hang together is an RSSI question.' },
  };
  function helpButton(key){ return HELP[key] ? '<div class="as-help-bar"><button class="btn-help" data-as-help="'+esc(key)+'">📘 How to use this</button></div>' : ''; }
  AS.help = function(key){
    const h = HELP[key]; if (!h) return;
    const steps = (h.steps||[]).map(function(s){ return '<li>'+s+'</li>'; }).join('');
    const ex = h.example ? '<div class="au-example"><div class="dx-l-k">Worked example</div>'+h.example+'</div>' : '';
    const overlay = document.createElement('div');
    overlay.className = 'au-overlay';
    overlay.innerHTML = '<div class="au-panel" role="dialog" aria-label="How to use">'
      + '<button class="au-close" aria-label="Close">&times;</button>'
      + '<h2 class="au-title">How to use '+esc(h.title)+'</h2>'
      + '<p class="au-sub">'+esc(h.what)+'</p>'
      + (steps?'<ol class="as-help-steps">'+steps+'</ol>':'')
      + ex
      + '<div class="au-confirm-actions" style="justify-content:flex-end"><button class="au-btn primary" data-au-close="1">Got it</button></div></div>';
    document.body.appendChild(overlay);
    const close=function(){ overlay.remove(); };
    overlay.addEventListener('click', function(e){ if(e.target===overlay || e.target.getAttribute('data-au-close')) close(); });
    overlay.querySelector('.au-close').addEventListener('click', close);
  };
  AS.helpButton = helpButton;

  // Coach "Explain" content per step (mirrors MM's What is / measures / when).
  const COACH = {
    overview:{ measures:'Row and variable counts, each variable’s role, and how much data is missing.', use:'At the start of any analysis, to understand your dataset before drawing conclusions.' },
    frequencies:{ measures:'The count, the percent of the whole, the valid percent (excluding missing), and the running cumulative percent for each category.', use:'To see how a sample is distributed across a category, such as role or education, before comparing groups.' },
    distributions:{ measures:'Center (mean, median), spread (SD), and range (min–max) for each numeric variable, plus group means by a category.', use:'To understand the shape of numeric variables before choosing a statistical test.' },
    cross_tabs:{ measures:'Joint counts and row percentages for two categorical variables.', use:'To explore whether two categories appear related — before testing it with chi-square.' },
    group_summaries:{ measures:'Each group’s mean, SD, and gap from the overall mean on a numeric outcome.', use:'To compare a numeric outcome across groups, descriptively.' },
    top_bottom_items:{ measures:'Every numeric item’s mean and SD, ranked, with ceiling / floor / low-variance flags.', use:'To spot the strongest and weakest items, and items that may not discriminate.' },
    scale_scores:{ measures:'A composite (average of chosen items per respondent) with its N, mean, SD, and range.', use:'To summarize a set of items as one score. Whether the set is reliable lives in RSSI.' },
  };
  AS.coachExplain = function(key){
    const h=HELP[key]||{}, c=COACH[key]||{};
    function blk(letter,label,text){ return '<div class="cb"><div class="cb-k"><span class="cb-i">'+letter+'</span>'+label+'</div><div class="cb-t">'+text+'</div></div>'; }
    let s='';
    if(h.what) s+=blk('i','What it is',esc(h.what));
    if(c.measures) s+=blk('M','What it measures',esc(c.measures));
    if(c.use) s+=blk('✓','When to use it',esc(c.use));
    return s;
  };

  function header(eyebrow, title, lede, helpKey){
    return '<div class="ws-header"><div class="eyebrow">'+esc(eyebrow)+' <span class="strand-chip">QUAN</span></div>'
      + '<h1 class="title">'+esc(title)+'</h1>'
      + (lede?'<p class="lede">'+esc(lede)+'</p>':'')
      + (helpKey?helpButton(helpKey):'') + '</div>';
  }
  function selectField(id, label, hint, vars, selectedName){
    const opts = vars.map(function(v){ return '<option value="'+esc(v.name)+'"'+(v.name===selectedName?' selected':'')+'>'+esc(v.name)+'</option>'; }).join('');
    return '<div class="as-field"><label>'+esc(label)+(hint?' <span class="hint">'+esc(hint)+'</span>':'')+'</label><select class="ed-in" id="'+id+'">'+opts+'</select></div>';
  }
  function layers(blocks){
    return '<div class="dx-layers">'+blocks.map(function(b){
      return '<div class="dx-l'+(b.caution?' dx-caution':'')+'"><div class="dx-l-k">'+esc(b.k)+'</div><div class="dx-l-t">'+b.t+'</div></div>';
    }).join('')+'</div>';
  }

  // ---- public ----
  AS.renderWork = function(host, ctx){
    const ds = ctx.dataset, tool = ctx.tool;
    VIEW = ctx.view || 'table';
    if (!ds || !Array.isArray(ds.variables) || !ds.variables.length){
      host.innerHTML = header('Descriptive Analysis', titleFor(tool), '')
        + '<div class="as-empty-tool">Load data first — use the <strong>Data</strong> bar below.</div>';
      return;
    }
    const fns = { frequencies:renderFreq, distributions:renderMeans, cross_tabs:renderCross, group_summaries:renderGroups, top_bottom_items:renderTopBottom, scale_scores:renderScale };
    const fn = fns[tool];
    if (!fn){ host.innerHTML = header('Descriptive Analysis', titleFor(tool), '') + '<div class="as-empty-tool">This analysis is being built.</div>'; return; }
    fn(host, ds);
  };
  function titleFor(t){ return ({frequencies:'Frequencies',distributions:'Means & Distributions',cross_tabs:'Cross-Tabs',group_summaries:'Group Summaries',top_bottom_items:'Top & Bottom Items',scale_scores:'Scale Scores'})[t]||'Descriptive'; }

  // ---- Frequencies ----
  function renderFreq(host, ds){
    const cands = discreteVars(ds).length ? discreteVars(ds) : (catVars(ds).length ? catVars(ds) : ds.variables);
    const chosen = (sel.frequencies && varByName(ds, sel.frequencies)) ? sel.frequencies : cands[0].name;
    sel.frequencies = chosen;
    host.innerHTML = header('Descriptive Analysis', 'Frequencies', 'Counts and percentages for a categorical or Likert variable.', 'frequencies')
      + '<div class="panel"><div class="panel-b">'
      + selectField('frqVar','Variable','Categorical or Likert', cands, chosen)
      + '<div id="frqOut"></div></div></div>';
    const drawOut = function(){
      const v = varByName(ds, sel.frequencies); const out = host.querySelector('#frqOut'); if(!v||!out) return;
      const nm = nonMissing(v.values); const total=v.values.length, miss=total-nm.length;
      const counts = {}; nm.forEach(function(x){ counts[x]=(counts[x]||0)+1; });
      const rows = Object.keys(counts).map(function(k){ return {value:k,count:counts[k]}; }).sort(function(a,b){ return b.count-a.count; });
      const maxc = rows.length?rows[0].count:1; let cum=0;
      const body = rows.map(function(r){ const valid=100*r.count/nm.length; cum+=valid; const all=100*r.count/total;
        return '<tr><td class="dx-name">'+esc(r.value)+'</td><td>'+r.count+'</td><td>'+pc(all)+'</td><td>'+pc(valid)+'</td><td>'+pc(cum)+'</td>'
          + '<td style="width:90px"><span class="dx-bar"><i style="width:'+(100*r.count/maxc)+'%"></i></span></td></tr>';
      }).join('');
      const missRow = miss>0 ? '<tr><td class="dx-name">Missing</td><td>'+miss+'</td><td>'+pc(100*miss/total)+'</td><td>—</td><td>—</td><td></td></tr>' : '';
      const top = rows[0];
      const layerBlocks = [
        {k:'What this shows', t: top? 'The most common value of <strong>'+esc(v.name)+'</strong> was "'+esc(top.value)+'" — '+pc(100*top.count/nm.length)+' of '+nm.length+' non-missing responses.' : 'No non-missing responses.'},
        {k:'Why this matters', t:'How the sample distributes across '+esc(v.name)+' shapes how every other result should be read.'},
        {k:'Caution', caution:true, t:'Counts describe who is in the data; on their own they do not explain differences between groups.'}
      ];
      if (VIEW === 'graph') {
        // Bar is the default; donut only when it won't mislead (≤6 parts-of-whole).
        const eligibleDonut = rows.length <= 6;
        if (!sel.freq_chart || (sel.freq_chart==='donut' && !eligibleDonut)) sel.freq_chart = 'bar';
        const chartRows = rows.map(function(r){ return { label:r.value, value:r.count }; });
        const chart = sel.freq_chart==='donut'
          ? svgDonut(chartRows)
          : svgBarsH(chartRows, { valueFmt:function(n){ return n+' ('+Math.round(100*n/nm.length)+'%)'; } });
        out.innerHTML = chartTypeSeg([{k:'bar',l:'Bar'}].concat(eligibleDonut?[{k:'donut',l:'Donut'}]:[]), sel.freq_chart)
          + chartWrap(chart) + layers(layerBlocks);
        wireChartSeg(out, function(k){ sel.freq_chart=k; drawOut(); });
        return;
      }
      out.innerHTML = '<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr>'
        + '<th class="l">'+esc(v.name)+'</th><th>Frequency</th><th>Percent</th><th>Valid %</th><th>Cumulative %</th><th></th></tr></thead><tbody>'
        + body + missRow
        + '<tr class="dx-total"><td class="dx-name">Total</td><td>'+total+'</td><td>100.0%</td><td>100.0%</td><td>—</td><td></td></tr>'
        + '</tbody></table></div>'
        + layers(layerBlocks);
    };
    host.querySelector('#frqVar').addEventListener('change', function(e){ sel.frequencies=e.target.value; drawOut(); });
    drawOut();
  }

  // ---- Means & Distributions ----
  function renderMeans(host, ds){
    const nv = numericVars(ds);
    const rows = nv.map(function(v){ const a=nums(v.values); const total=v.values.length; return {name:v.name,n:a.length,mean:mean(a),sd:sd(a),min:a.length?Math.min.apply(null,a):null,max:a.length?Math.max.apply(null,a):null,median:median(a),missing:total-nonMissing(v.values).length}; });
    const body = rows.length? rows.map(function(r){ return '<tr><td class="dx-name">'+esc(r.name)+'</td><td>'+r.n+'</td><td>'+n2(r.mean)+'</td><td>'+n2(r.sd)+'</td><td>'+n2(r.median)+'</td><td>'+n2(r.min)+'</td><td>'+n2(r.max)+'</td><td>'+r.missing+'</td></tr>'; }).join('')
      : '<tr><td colspan="8" class="l">No numeric variables were found.</td></tr>';
    const layerBlocks = [
      {k:'What this shows', t: rows.length? 'Summary statistics for '+rows.length+' numeric variable'+(rows.length!==1?'s':'')+'. Compare the mean and median to spot skew, and the SD for spread.' : 'No numeric variables to summarize.'},
      {k:'Why this matters', t:'Distribution shape decides which later analyses are appropriate (e.g., a heavily skewed variable may need a different test).'},
      {k:'Caution', caution:true, t:'A mean alone can hide a bimodal or skewed distribution — read it alongside the SD and min/max.'}
    ];
    const inner = (VIEW==='graph')
      ? chartWrap(svgBarsH(rows.filter(function(r){return r.n>0;}).map(function(r){ return {label:r.name, value:r.mean}; }), { valueFmt:n2 }))
      : '<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Median</th><th>Min</th><th>Max</th><th>Missing</th></tr></thead><tbody>'+body+'</tbody></table></div>';

    // Table 2 · Mean of [a numeric variable] by [a group] (like MM).
    const cv = catVars(ds);
    let t2html = '';
    if (nv.length && cv.length) {
      sel.means_out = (sel.means_out && varByName(ds,sel.means_out)) ? sel.means_out : nv[0].name;
      sel.means_grp = (sel.means_grp && varByName(ds,sel.means_grp)) ? sel.means_grp : cv[0].name;
      t2html = '<div class="panel"><div class="panel-b">'
        + '<div class="as-pickgrid" style="margin-bottom:6px">'
        + selectField('mOut','Variable','numeric', nv, sel.means_out)
        + selectField('mGrp','By group','', cv, sel.means_grp)
        + '</div><h3 style="font-size:14px;font-weight:700;margin:8px 0 0" id="m2title"></h3><div id="m2out"></div></div></div>';
    }

    host.innerHTML = header('Descriptive Analysis','Means & Distributions','Center, spread, and range for every numeric variable.','distributions')
      + '<div class="panel"><div class="panel-h"><h3>'+(VIEW==='graph'?'Mean by variable':'Table 1 · Summary statistics for numeric variables')+'</h3></div><div class="panel-b">'
      + inner + layers(layerBlocks) + '</div></div>' + t2html;

    if (nv.length && cv.length) {
      const drawM2 = function(){
        const ov=varByName(ds,sel.means_out), gv=varByName(ds,sel.means_grp); const out=host.querySelector('#m2out'); if(!ov||!gv||!out) return;
        const tt=host.querySelector('#m2title'); if(tt) tt.textContent = 'Table 2 · Mean of '+ov.name+' by '+gv.name;
        const n=Math.min(ov.values.length,gv.values.length); const groups={};
        for(let i=0;i<n;i++){ const g=gv.values[i], y=num(ov.values[i]); if(isMissing(g)||y===null) continue; (groups[g]=groups[g]||[]).push(y); }
        const all=[].concat.apply([],Object.keys(groups).map(function(k){return groups[k];})); const grand=mean(all);
        const grows=Object.keys(groups).map(function(g){ const a=groups[g]; const m=mean(a); return {group:g,n:a.length,mean:m,sd:sd(a),delta:m-grand}; }).sort(function(a,b){return b.mean-a.mean;});
        if (VIEW==='graph'){ out.innerHTML = chartWrap(svgBarsH(grows.map(function(r){return {label:r.group,value:r.mean};}),{valueFmt:n2})); return; }
        const gbody=grows.map(function(r){ return '<tr><td class="dx-name">'+esc(r.group)+'</td><td>'+r.n+'</td><td>'+n2(r.mean)+'</td><td>'+n2(r.sd)+'</td><td class="'+(r.delta<0?'dx-neg':'dx-pos')+'">'+(r.delta>0?'+':'')+n2(r.delta)+'</td></tr>'; }).join('');
        out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>Δ from overall</th></tr></thead><tbody>'
          + gbody + '<tr class="dx-total"><td class="dx-name">Overall</td><td>'+all.length+'</td><td>'+n2(grand)+'</td><td>'+n2(sd(all))+'</td><td>—</td></tr></tbody></table></div>';
      };
      const mo=host.querySelector('#mOut'), mg=host.querySelector('#mGrp');
      if(mo) mo.addEventListener('change',function(e){ sel.means_out=e.target.value; drawM2(); });
      if(mg) mg.addEventListener('change',function(e){ sel.means_grp=e.target.value; drawM2(); });
      drawM2();
    }
  }

  // ---- Cross-Tabs ----
  function renderCross(host, ds){
    const cv = catVars(ds).length>=2 ? catVars(ds) : ds.variables;
    const rowName = (sel.cross_row && varByName(ds,sel.cross_row)) ? sel.cross_row : cv[0].name;
    let colName = (sel.cross_col && varByName(ds,sel.cross_col)) ? sel.cross_col : (cv[1]?cv[1].name:cv[0].name);
    sel.cross_row=rowName; sel.cross_col=colName;
    host.innerHTML = header('Descriptive Analysis','Cross-Tabs','A two-variable contingency table with row percentages.','cross_tabs')
      + '<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      + selectField('ctRow','Row variable','', cv, rowName)
      + selectField('ctCol','Column variable','', cv, colName)
      + '</div><div id="ctOut"></div></div></div>';
    const drawOut=function(){
      const rv=varByName(ds,sel.cross_row), cvv=varByName(ds,sel.cross_col); const out=host.querySelector('#ctOut'); if(!rv||!cvv||!out) return;
      const n=Math.min(rv.values.length,cvv.values.length);
      const colVals=[]; for(let i=0;i<n;i++){ const c=cvv.values[i]; if(!isMissing(c)&&colVals.indexOf(c)<0) colVals.push(c); }
      const rowMap={};
      for(let i=0;i<n;i++){ const r=rv.values[i], c=cvv.values[i]; if(isMissing(r)||isMissing(c)) continue; rowMap[r]=rowMap[r]||{}; rowMap[r][c]=(rowMap[r][c]||0)+1; }
      const rowKeys=Object.keys(rowMap); const colTotals={}; let grand=0;
      const trs=rowKeys.map(function(rk){ const cells=rowMap[rk]; const rt=colVals.reduce(function(s,c){return s+(cells[c]||0);},0); grand+=rt;
        const tds=colVals.map(function(c){ const v=cells[c]||0; colTotals[c]=(colTotals[c]||0)+v; return '<td>'+v+'</td><td>'+pc(rt?100*v/rt:0)+'</td>'; }).join('');
        return '<tr><td class="dx-name">'+esc(rk)+'</td>'+tds+'<td>'+rt+'</td></tr>'; }).join('');
      const totTds=colVals.map(function(c){ return '<td>'+(colTotals[c]||0)+'</td><td>'+pc(grand?100*(colTotals[c]||0)/grand:0)+'</td>'; }).join('');
      const layerBlocks=[
        {k:'What this shows', t:'How '+esc(rv.name)+' breaks down across '+esc(cvv.name)+'. Row % reads each row as 100%.'},
        {k:'Caution', caution:true, t:'A visible difference here is descriptive only — a chi-square test (Inferential Studio) is needed to judge whether it is more than sampling noise.'}
      ];
      if (VIEW==='graph'){
        const chartRows=[]; rowKeys.forEach(function(rk){ colVals.forEach(function(c){ chartRows.push({ label: trim(rk,12)+' · '+trim(c,10), value:(rowMap[rk][c]||0) }); }); });
        out.innerHTML = chartWrap(svgBarsH(chartRows)) + layers(layerBlocks);
        return;
      }
      out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">'+esc(rv.name)+'</th>'
        + colVals.map(function(c){return '<th>'+esc(c)+' n</th><th>'+esc(c)+' %</th>';}).join('')+'<th>Total</th></tr></thead><tbody>'
        + trs + '<tr class="dx-total"><td class="dx-name">Total</td>'+totTds+'<td>'+grand+'</td></tr></tbody></table></div>'
        + layers(layerBlocks);
    };
    host.querySelector('#ctRow').addEventListener('change',function(e){sel.cross_row=e.target.value;drawOut();});
    host.querySelector('#ctCol').addEventListener('change',function(e){sel.cross_col=e.target.value;drawOut();});
    drawOut();
  }

  // ---- Group Summaries ----
  function renderGroups(host, ds){
    const nv=numericVars(ds), cv=catVars(ds);
    if(!nv.length||!cv.length){ host.innerHTML=header('Descriptive Analysis','Group Summaries','')+'<div class="as-empty-tool">Need at least one numeric variable and one grouping variable.</div>'; return; }
    const outName=(sel.gs_out&&varByName(ds,sel.gs_out))?sel.gs_out:nv[0].name;
    const grpName=(sel.gs_grp&&varByName(ds,sel.gs_grp))?sel.gs_grp:cv[0].name;
    sel.gs_out=outName; sel.gs_grp=grpName;
    host.innerHTML=header('Descriptive Analysis','Group Summaries','Per-group means, with each group’s gap from the overall mean.','group_summaries')
      + '<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      + selectField('gsOut','Outcome (numeric)','', nv, outName)
      + selectField('gsGrp','Group by','', cv, grpName)
      + '</div><div id="gsOut2"></div></div></div>';
    const drawOut=function(){
      const ov=varByName(ds,sel.gs_out), gv=varByName(ds,sel.gs_grp); const out=host.querySelector('#gsOut2'); if(!ov||!gv||!out) return;
      const n=Math.min(ov.values.length,gv.values.length); const groups={};
      for(let i=0;i<n;i++){ const g=gv.values[i], y=num(ov.values[i]); if(isMissing(g)||y===null) continue; (groups[g]=groups[g]||[]).push(y); }
      const all=[].concat.apply([],Object.keys(groups).map(function(k){return groups[k];})); const grand=mean(all);
      const rows=Object.keys(groups).map(function(g){ const a=groups[g]; const m=mean(a); return {group:g,n:a.length,mean:m,sd:sd(a),delta:m-grand}; }).sort(function(a,b){return b.mean-a.mean;});
      const body=rows.map(function(r){ return '<tr><td class="dx-name">'+esc(r.group)+'</td><td>'+r.n+'</td><td>'+n2(r.mean)+'</td><td>'+n2(r.sd)+'</td><td class="'+(r.delta<0?'dx-neg':'dx-pos')+'">'+(r.delta>0?'+':'')+n2(r.delta)+'</td></tr>'; }).join('');
      const hi=rows[0], lo=rows[rows.length-1];
      const layerBlocks=[
        {k:'What this shows', t: rows.length? 'On '+esc(ov.name)+', "'+esc(hi.group)+'" is highest (mean '+n2(hi.mean)+') and "'+esc(lo.group)+'" lowest (mean '+n2(lo.mean)+').' : 'No groups to compare.'},
        {k:'Caution', caution:true, t:'These are descriptive gaps. Whether the difference is statistically meaningful is a t-test / ANOVA question (Inferential Studio).'}
      ];
      if (VIEW==='graph'){
        out.innerHTML = chartWrap(svgBarsH(rows.map(function(r){ return {label:r.group, value:r.mean}; }), { valueFmt:n2 })) + layers(layerBlocks);
        return;
      }
      out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>Δ from overall</th></tr></thead><tbody>'
        + body + '<tr class="dx-total"><td class="dx-name">Overall</td><td>'+all.length+'</td><td>'+n2(grand)+'</td><td>'+n2(sd(all))+'</td><td>—</td></tr></tbody></table></div>'
        + layers(layerBlocks);
    };
    host.querySelector('#gsOut').addEventListener('change',function(e){sel.gs_out=e.target.value;drawOut();});
    host.querySelector('#gsGrp').addEventListener('change',function(e){sel.gs_grp=e.target.value;drawOut();});
    drawOut();
  }

  // ---- Top & Bottom Items ----
  function renderTopBottom(host, ds){
    const items=numericVars(ds).map(function(v){ const a=nums(v.values); const m=mean(a); const s=sd(a); const max=a.length?Math.max.apply(null,a):0; const min=a.length?Math.min.apply(null,a):0;
      const flags=[]; if(s<0.4&&a.length>1) flags.push('low variance'); if(m>=0.9*max&&max>0) flags.push('ceiling'); if(min>0&&m<=1.1*min) flags.push('floor');
      return {name:v.name,n:a.length,mean:m,sd:s,flags:flags}; }).sort(function(a,b){return b.mean-a.mean;});
    if(!items.length){ host.innerHTML=header('Descriptive Analysis','Top & Bottom Items','')+'<div class="as-empty-tool">No numeric/Likert items found to rank.</div>'; return; }
    const row=function(r,rank){ return '<tr><td class="dx-name">'+rank+'. '+esc(r.name)+'</td><td>'+r.n+'</td><td>'+n2(r.mean)+'</td><td>'+n2(r.sd)+'</td><td class="dx-interp">'+(r.flags.length?esc(r.flags.join(', ')):'—')+'</td></tr>'; };
    const body=items.map(function(r,i){return row(r,i+1);}).join('');
    const layerBlocks=[
      {k:'What this shows', t:'Highest-scoring item: <strong>'+esc(items[0].name)+'</strong> (mean '+n2(items[0].mean)+'). Lowest: <strong>'+esc(items[items.length-1].name)+'</strong> (mean '+n2(items[items.length-1].mean)+').'},
      {k:'Caution', caution:true, t:'Ceiling/low-variance flags suggest items that may not discriminate between respondents — useful to review, not a verdict.'}
    ];
    const inner = (VIEW==='graph')
      ? chartWrap(svgBarsH(items.map(function(r){ return {label:r.name, value:r.mean}; }), { valueFmt:n2 }))
      : '<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Item (ranked)</th><th>N</th><th>Mean</th><th>SD</th><th class="l">Flags</th></tr></thead><tbody>'+body+'</tbody></table></div>';
    host.innerHTML=header('Descriptive Analysis','Top & Bottom Items','Every numeric item ranked by mean, with low-variance / ceiling / floor flags.','top_bottom_items')
      + '<div class="panel"><div class="panel-b">'+inner+layers(layerBlocks)+'</div></div>';
  }

  // ---- Scale Scores (no reliability) ----
  function renderScale(host, ds){
    const nv=numericVars(ds);
    if(!nv.length){ host.innerHTML=header('Descriptive Analysis','Scale Scores','')+'<div class="as-empty-tool">No numeric items to combine.</div>'; return; }
    sel.scale = sel.scale || nv.slice(0,Math.min(3,nv.length)).map(function(v){return v.name;});
    const checks=nv.map(function(v){ const on=sel.scale.indexOf(v.name)>=0; return '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:500;padding:5px 0"><input type="checkbox" class="scItem" value="'+esc(v.name)+'"'+(on?' checked':'')+'> '+esc(v.name)+'</label>'; }).join('');
    host.innerHTML=header('Descriptive Analysis','Scale Scores','Combine items into a composite (sum of item means per respondent). No reliability — that lives in RSSI.','scale_scores')
      + '<div class="panel"><div class="panel-b"><div class="as-field"><label>Items in this scale</label>'+checks+'</div><div id="scOut"></div></div></div>';
    const drawOut=function(){
      const out=host.querySelector('#scOut'); if(!out) return;
      const chosen=sel.scale.map(function(nm){return varByName(ds,nm);}).filter(Boolean);
      if(chosen.length<2){ out.innerHTML='<div class="as-empty-tool">Pick at least two items to form a composite.</div>'; return; }
      const n=ds.rowCount||chosen[0].values.length; const comp=[];
      for(let i=0;i<n;i++){ const vals=chosen.map(function(v){return num(v.values[i]);}).filter(function(x){return x!==null;}); if(vals.length===chosen.length) comp.push(mean(vals)); }
      const layerBlocks=[
        {k:'What this shows', t:'A composite of '+chosen.length+' items, averaged per respondent over '+comp.length+' complete cases.'},
        {k:'Caution', caution:true, t:'This is a descriptive composite only. Whether these items reliably belong together (Cronbach’s α) is answered in RSSI, not here.'}
      ];
      if (VIEW==='graph'){
        out.innerHTML = chartWrap(svgHist(comp, { bins:10 })) + layers(layerBlocks);
        return;
      }
      out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">Composite</th><th>Items</th><th>N (complete)</th><th>Mean</th><th>SD</th><th>Min</th><th>Max</th></tr></thead><tbody>'
        + '<tr><td class="dx-name">Scale score</td><td>'+chosen.length+'</td><td>'+comp.length+'</td><td>'+n2(mean(comp))+'</td><td>'+n2(sd(comp))+'</td><td>'+n2(comp.length?Math.min.apply(null,comp):null)+'</td><td>'+n2(comp.length?Math.max.apply(null,comp):null)+'</td></tr></tbody></table></div>'
        + layers(layerBlocks);
    };
    host.querySelectorAll('.scItem').forEach(function(cb){ cb.addEventListener('change',function(){ const v=cb.value; const i=sel.scale.indexOf(v); if(cb.checked&&i<0) sel.scale.push(v); else if(!cb.checked&&i>=0) sel.scale.splice(i,1); drawOut(); }); });
    drawOut();
  }

  // Delegated: any "How to use this" button (in a work step or the Overview)
  // opens its help modal.
  document.addEventListener('click', function(e){
    const b = e.target.closest ? e.target.closest('[data-as-help]') : null;
    if (b) AS.help(b.getAttribute('data-as-help'));
  });

  window.AnalysisStudio = AS;
})();
