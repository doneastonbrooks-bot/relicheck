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

  function isNumericVar(v){ const t=(v.types||[]).join(',').toLowerCase(); if(/likert|numeric/.test(t)) return true; const nm=nonMissing(v.values); if(!nm.length) return false; const ok=nm.filter(function(x){return num(x)!==null;}).length; return ok/nm.length>=0.8; }
  function isCategoricalVar(v){ const t=(v.types||[]).join(',').toLowerCase(); if(/single|multi|categor|demograph|identifier/.test(t)) return true; const nm=nonMissing(v.values); const d=new Set(nm); return d.size>1 && d.size<=20; }
  function numericVars(ds){ return ds.variables.filter(isNumericVar); }
  function catVars(ds){ return ds.variables.filter(isCategoricalVar); }
  function varByName(ds,name){ return ds.variables.find(function(v){return v.name===name;}); }

  function header(eyebrow, title, lede){
    return '<div class="ws-header"><div class="eyebrow">'+esc(eyebrow)+' <span class="strand-chip">QUAN</span></div>'
      + '<h1 class="title">'+esc(title)+'</h1>'
      + (lede?'<p class="lede">'+esc(lede)+'</p>':'') + '</div>';
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
    const cands = catVars(ds).length ? catVars(ds) : ds.variables;
    const chosen = (sel.frequencies && varByName(ds, sel.frequencies)) ? sel.frequencies : cands[0].name;
    sel.frequencies = chosen;
    host.innerHTML = header('Descriptive Analysis', 'Frequencies', 'Counts and percentages for a categorical or Likert variable.')
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
      out.innerHTML = '<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr>'
        + '<th class="l">'+esc(v.name)+'</th><th>Frequency</th><th>Percent</th><th>Valid %</th><th>Cumulative %</th><th></th></tr></thead><tbody>'
        + body + missRow
        + '<tr class="dx-total"><td class="dx-name">Total</td><td>'+total+'</td><td>100.0%</td><td>'+(miss>0?'100.0%':'100.0%')+'</td><td>—</td><td></td></tr>'
        + '</tbody></table></div>'
        + layers([
          {k:'What this shows', t: top? 'The most common value of <strong>'+esc(v.name)+'</strong> was "'+esc(top.value)+'" — '+pc(100*top.count/nm.length)+' of '+nm.length+' non-missing responses.' : 'No non-missing responses.'},
          {k:'Why this matters', t:'How the sample distributes across '+esc(v.name)+' shapes how every other result should be read.'},
          {k:'Caution', caution:true, t:'Counts describe who is in the data; on their own they do not explain differences between groups.'}
        ]);
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
    host.innerHTML = header('Descriptive Analysis','Means & Distributions','Center, spread, and range for every numeric variable.')
      + '<div class="panel"><div class="panel-h"><h3>Table 1 · Summary statistics</h3></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
      + '<thead><tr><th class="l">Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Median</th><th>Min</th><th>Max</th><th>Missing</th></tr></thead><tbody>'+body+'</tbody></table></div>'
      + layers([
          {k:'What this shows', t: rows.length? 'Summary statistics for '+rows.length+' numeric variable'+(rows.length!==1?'s':'')+'. Compare the mean and median to spot skew, and the SD for spread.' : 'No numeric variables to summarize.'},
          {k:'Why this matters', t:'Distribution shape decides which later analyses are appropriate (e.g., a heavily skewed variable may need a different test).'},
          {k:'Caution', caution:true, t:'A mean alone can hide a bimodal or skewed distribution — read it alongside the SD and min/max.'}
        ]) + '</div></div>';
  }

  // ---- Cross-Tabs ----
  function renderCross(host, ds){
    const cv = catVars(ds).length>=2 ? catVars(ds) : ds.variables;
    const rowName = (sel.cross_row && varByName(ds,sel.cross_row)) ? sel.cross_row : cv[0].name;
    let colName = (sel.cross_col && varByName(ds,sel.cross_col)) ? sel.cross_col : (cv[1]?cv[1].name:cv[0].name);
    sel.cross_row=rowName; sel.cross_col=colName;
    host.innerHTML = header('Descriptive Analysis','Cross-Tabs','A two-variable contingency table with row percentages.')
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
      out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">'+esc(rv.name)+'</th>'
        + colVals.map(function(c){return '<th>'+esc(c)+' n</th><th>'+esc(c)+' %</th>';}).join('')+'<th>Total</th></tr></thead><tbody>'
        + trs + '<tr class="dx-total"><td class="dx-name">Total</td>'+totTds+'<td>'+grand+'</td></tr></tbody></table></div>'
        + layers([
            {k:'What this shows', t:'How '+esc(rv.name)+' breaks down across '+esc(cvv.name)+'. Row % reads each row as 100%.'},
            {k:'Caution', caution:true, t:'A visible difference here is descriptive only — a chi-square test (Inferential Studio) is needed to judge whether it is more than sampling noise.'}
          ]);
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
    host.innerHTML=header('Descriptive Analysis','Group Summaries','Per-group means, with each group’s gap from the overall mean.')
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
      out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>Δ from overall</th></tr></thead><tbody>'
        + body + '<tr class="dx-total"><td class="dx-name">Overall</td><td>'+all.length+'</td><td>'+n2(grand)+'</td><td>'+n2(sd(all))+'</td><td>—</td></tr></tbody></table></div>'
        + layers([
            {k:'What this shows', t: rows.length? 'On '+esc(ov.name)+', "'+esc(hi.group)+'" is highest (mean '+n2(hi.mean)+') and "'+esc(lo.group)+'" lowest (mean '+n2(lo.mean)+').' : 'No groups to compare.'},
            {k:'Caution', caution:true, t:'These are descriptive gaps. Whether the difference is statistically meaningful is a t-test / ANOVA question (Inferential Studio).'}
          ]);
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
    host.innerHTML=header('Descriptive Analysis','Top & Bottom Items','Every numeric item ranked by mean, with low-variance / ceiling / floor flags.')
      + '<div class="panel"><div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Item (ranked)</th><th>N</th><th>Mean</th><th>SD</th><th class="l">Flags</th></tr></thead><tbody>'+body+'</tbody></table></div>'
      + layers([
          {k:'What this shows', t:'Highest-scoring item: <strong>'+esc(items[0].name)+'</strong> (mean '+n2(items[0].mean)+'). Lowest: <strong>'+esc(items[items.length-1].name)+'</strong> (mean '+n2(items[items.length-1].mean)+').'},
          {k:'Caution', caution:true, t:'Ceiling/low-variance flags suggest items that may not discriminate between respondents — useful to review, not a verdict.'}
        ])+'</div></div>';
  }

  // ---- Scale Scores (no reliability) ----
  function renderScale(host, ds){
    const nv=numericVars(ds);
    if(!nv.length){ host.innerHTML=header('Descriptive Analysis','Scale Scores','')+'<div class="as-empty-tool">No numeric items to combine.</div>'; return; }
    sel.scale = sel.scale || nv.slice(0,Math.min(3,nv.length)).map(function(v){return v.name;});
    const checks=nv.map(function(v){ const on=sel.scale.indexOf(v.name)>=0; return '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:500;padding:5px 0"><input type="checkbox" class="scItem" value="'+esc(v.name)+'"'+(on?' checked':'')+'> '+esc(v.name)+'</label>'; }).join('');
    host.innerHTML=header('Descriptive Analysis','Scale Scores','Combine items into a composite (sum of item means per respondent). No reliability — that lives in RSSI.')
      + '<div class="panel"><div class="panel-b"><div class="as-field"><label>Items in this scale</label>'+checks+'</div><div id="scOut"></div></div></div>';
    const drawOut=function(){
      const out=host.querySelector('#scOut'); if(!out) return;
      const chosen=sel.scale.map(function(nm){return varByName(ds,nm);}).filter(Boolean);
      if(chosen.length<2){ out.innerHTML='<div class="as-empty-tool">Pick at least two items to form a composite.</div>'; return; }
      const n=ds.rowCount||chosen[0].values.length; const comp=[];
      for(let i=0;i<n;i++){ const vals=chosen.map(function(v){return num(v.values[i]);}).filter(function(x){return x!==null;}); if(vals.length===chosen.length) comp.push(mean(vals)); }
      out.innerHTML='<div class="dx-scroll" style="margin-top:8px"><table class="dx-table"><thead><tr><th class="l">Composite</th><th>Items</th><th>N (complete)</th><th>Mean</th><th>SD</th><th>Min</th><th>Max</th></tr></thead><tbody>'
        + '<tr><td class="dx-name">Scale score</td><td>'+chosen.length+'</td><td>'+comp.length+'</td><td>'+n2(mean(comp))+'</td><td>'+n2(sd(comp))+'</td><td>'+n2(comp.length?Math.min.apply(null,comp):null)+'</td><td>'+n2(comp.length?Math.max.apply(null,comp):null)+'</td></tr></tbody></table></div>'
        + layers([
            {k:'What this shows', t:'A composite of '+chosen.length+' items, averaged per respondent over '+comp.length+' complete cases.'},
            {k:'Caution', caution:true, t:'This is a descriptive composite only. Whether these items reliably belong together (Cronbach’s α) is answered in RSSI, not here.'}
          ]);
    };
    host.querySelectorAll('.scItem').forEach(function(cb){ cb.addEventListener('change',function(){ const v=cb.value; const i=sel.scale.indexOf(v); if(cb.checked&&i<0) sel.scale.push(v); else if(!cb.checked&&i>=0) sel.scale.splice(i,1); drawOut(); }); });
    drawOut();
  }

  window.AnalysisStudio = AS;
})();
