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

  // ---- Non-parametric math helpers ----
  function rankWithTies(values) {
    var indexed = values.map(function(v,i){ return {v:v,i:i}; });
    indexed.sort(function(a,b){ return a.v-b.v; });
    var ranks = new Array(values.length);
    var i = 0;
    while (i < indexed.length) {
      var j = i;
      while (j < indexed.length && indexed[j].v === indexed[i].v) j++;
      var avgRank = (i + 1 + j) / 2;
      for (var k = i; k < j; k++) ranks[indexed[k].i] = avgRank;
      i = j;
    }
    return ranks;
  }
  function normalCDF(z) {
    var t = 1/(1+0.2316419*Math.abs(z));
    var poly = t*(0.319381530+t*(-0.356563782+t*(1.781477937+t*(-1.821255978+t*1.330274429))));
    var phi = Math.exp(-z*z/2)/Math.sqrt(2*Math.PI);
    var p = 1-phi*poly;
    return z>=0 ? p : 1-p;
  }
  function logGammaNP(x) {
    var c=[76.18009172947146,-86.50532032941677,24.01409824083091,-1.231739572450155,0.001208650973866179,-0.000005395239384953];
    var y=x, tmp=x+5.5; tmp-=(x+0.5)*Math.log(tmp); var ser=1.000000000190015;
    for(var j=0;j<6;j++){y+=1;ser+=c[j]/y;} return -tmp+Math.log(2.5066282746310005*ser/x);
  }
  function regGammaPNP(a,x) {
    if(x<=0||a<=0) return 0;
    if(x<a+1){var ap=a,sum=1/a,del=sum;for(var n=1;n<200;n++){ap+=1;del*=x/ap;sum+=del;if(Math.abs(del)<Math.abs(sum)*1e-12)break;}return sum*Math.exp(-x+a*Math.log(x)-logGammaNP(a));}
    var FPMIN=1e-300,b=x+1-a,c=1/FPMIN,d=1/b,h=d;
    for(var i=1;i<200;i++){var an=-i*(i-a);b+=2;d=an*d+b;if(Math.abs(d)<FPMIN)d=FPMIN;c=b+an/c;if(Math.abs(c)<FPMIN)c=FPMIN;d=1/d;var del=d*c;h*=del;if(Math.abs(del-1)<1e-12)break;}
    return 1-Math.exp(-x+a*Math.log(x)-logGammaNP(a))*h;
  }
  function chiSqPNP(x,df) { if(!isFinite(x)||x<0||df<=0) return 1; return 1-regGammaPNP(df/2,x/2); }
  function fmtP(p) { if(p==null||!isFinite(p)) return '—'; if(p<0.001) return '< .001'; if(p<0.01) return p.toFixed(3).replace(/^0/,''); return p.toFixed(2).replace(/^0/,''); }
  function effBand(type,v) {
    v=Math.abs(v);
    if(type==='r'||type==='r_rb') { if(v<0.1) return 'negligible'; if(v<0.3) return 'small'; if(v<0.5) return 'medium'; return 'large'; }
    if(type==='eps2') { if(v<0.01) return 'negligible'; if(v<0.06) return 'small'; if(v<0.14) return 'medium'; return 'large'; }
    if(type==='d') { if(v<0.2) return 'negligible'; if(v<0.5) return 'small'; if(v<0.8) return 'medium'; return 'large'; }
    if(type==='eta2') { if(v<0.01) return 'negligible'; if(v<0.06) return 'small'; if(v<0.14) return 'medium'; return 'large'; }
    return '';
  }
  // Band label for the Effect Sizes screen (Cohen's d, eta-squared, r-squared).
  function effLabel(type,v){
    v=Math.abs(v);
    if(type==='cohens_d')  { if(v<0.2) return 'negligible'; if(v<0.5) return 'small'; if(v<0.8) return 'medium'; return 'large'; }
    if(type==='eta_squared'){ if(v<0.01) return 'negligible'; if(v<0.06) return 'small'; if(v<0.14) return 'medium'; return 'large'; }
    if(type==='r_squared') { if(v<0.01) return 'negligible'; if(v<0.09) return 'small'; if(v<0.25) return 'medium'; return 'large'; }
    return '';
  }
  // Inline-styled effect band chip (the eff-* CSS classes never existed).
  function effChip(label){
    if(!label) return '';
    var c={negligible:['#eef0f3','#5f6368'],small:['#fff8ee','#b45309'],medium:['#e9f7ee','#1f9e44'],large:['#e6efff','#1d4ed8']}[label]||['#eef0f3','#5f6368'];
    return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:'+c[0]+';color:'+c[1]+';margin-left:6px">'+esc(label)+'</span>';
  }
  // Incomplete beta + t/F p-values (shared by parametric extension renderers).
  function betacfNP(x,a,b){var FPMIN=1e-300,qab=a+b,qap=a+1,qam=a-1,c=1,d=1-qab*x/qap;if(Math.abs(d)<FPMIN)d=FPMIN;d=1/d;var h=d;for(var m=1;m<=200;m++){var m2=2*m;var aa=m*(b-m)*x/((qam+m2)*(a+m2));d=1+aa*d;if(Math.abs(d)<FPMIN)d=FPMIN;c=1+aa/c;if(Math.abs(c)<FPMIN)c=FPMIN;d=1/d;h*=d*c;aa=-(a+m)*(qab+m)*x/((a+m2)*(qap+m2));d=1+aa*d;if(Math.abs(d)<FPMIN)d=FPMIN;c=1+aa/c;if(Math.abs(c)<FPMIN)c=FPMIN;d=1/d;var del=d*c;h*=del;if(Math.abs(del-1)<1e-12)break;}return h;}
  function regBetaINP(x,a,b){if(x<=0)return 0;if(x>=1)return 1;var bt=Math.exp(logGammaNP(a+b)-logGammaNP(a)-logGammaNP(b)+a*Math.log(x)+b*Math.log(1-x));if(x<(a+1)/(a+b+2))return bt*betacfNP(x,a,b)/a;return 1-bt*betacfNP(1-x,b,a)/b;}
  function tPValueNP(t,df){if(!isFinite(t)||df<=0)return 1;return regBetaINP(df/(df+t*t),df/2,0.5);}
  function fPValueNP(F,df1,df2){if(!isFinite(F)||F<=0||df1<=0||df2<=0)return 1;return regBetaINP(df2/(df2+df1*F),df2/2,df1/2);}
  function tCriticalNP(alpha,df){var lo=0,hi=50;for(var i=0;i<60;i++){var mid=(lo+hi)/2;if(tPValueNP(mid,df)<alpha)hi=mid;else lo=mid;}return(lo+hi)/2;}
  function invNormalCDFNP(p){if(p<=0)return -Infinity;if(p>=1)return Infinity;var a=[-39.69683028665376,220.9460984245205,-275.9285104469687,138.357751867269,-30.66479806614716,2.506628277459239];var b=[-54.47609879822406,161.5858368580409,-155.6989798598866,66.80131188771972,-13.28068155288572];var c=[-0.007784894002430293,-0.3223964580411365,-2.400758277161838,-2.549732539343734,4.374664141464968,2.938163982698783];var d=[0.007784695709041462,0.3224671290700398,2.445134137142996,3.754408661907416];var plow=0.02425,phigh=1-plow,q,r;if(p<plow){q=Math.sqrt(-2*Math.log(p));return(((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5])/((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1);}if(p<=phigh){q=p-0.5;r=q*q;return(((((a[0]*r+a[1])*r+a[2])*r+a[3])*r+a[4])*r+a[5])*q/(((((b[0]*r+b[1])*r+b[2])*r+b[3])*r+b[4])*r+1);}q=Math.sqrt(-2*Math.log(1-p));return -(((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5])/((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1);}
  function skewNP(a){if(a.length<3)return 0;var m=mean(a),v=variance(a);if(v===0)return 0;var s=Math.sqrt(v);return a.reduce(function(acc,x){return acc+Math.pow((x-m)/s,3);},0)/a.length;}
  function kurtNP(a){if(a.length<4)return 0;var m=mean(a),v=variance(a);if(v===0)return 0;var s=Math.sqrt(v);return a.reduce(function(acc,x){return acc+Math.pow((x-m)/s,4);},0)/a.length-3;}
  // Matrix helpers (logistic regression).
  function matT(M){if(!M.length)return[];var r=M.length,c=M[0].length,T=[];for(var j=0;j<c;j++){T.push([]);for(var i=0;i<r;i++)T[j][i]=M[i][j];}return T;}
  function matMul(A,B){var rA=A.length,cA=A[0].length,cB=B[0].length,C=[];for(var i=0;i<rA;i++){C.push(new Array(cB).fill(0));for(var k=0;k<cA;k++){var aik=A[i][k];for(var j=0;j<cB;j++)C[i][j]+=aik*B[k][j];}}return C;}
  function matInv(M){var n=M.length,A=M.map(function(row,i){var r=row.slice();for(var j=0;j<n;j++)r.push(i===j?1:0);return r;});for(var i=0;i<n;i++){var piv=i;for(var k=i+1;k<n;k++)if(Math.abs(A[k][i])>Math.abs(A[piv][i]))piv=k;if(piv!==i){var t=A[i];A[i]=A[piv];A[piv]=t;}if(Math.abs(A[i][i])<1e-12)return null;for(var k2=0;k2<n;k2++){if(k2===i)continue;var f=A[k2][i]/A[i][i];for(var j2=0;j2<2*n;j2++)A[k2][j2]-=f*A[i][j2];}}return A.map(function(row,i){return row.slice(n).map(function(v){return v/A[i][i];});});}
  function sigmoidNP(z){if(z<-700)return 0;if(z>700)return 1;return 1/(1+Math.exp(-z));}

  // ---- "How to use this" help (mirrors MM's per-step guidance) ----
  const HELP = {
    overview: { title:'the Overview', what:'The Overview reads your dataset before any analysis runs: how many rows and variables you have, each variable’s role, and where values are missing.',
      steps:['<b>Check the summary cards</b> \u2014 Rows, Variables, and Numeric show the shape of your data.','<b>Scan the Variables table.</b> Each row names a variable, its type, and how many values are missing.','<b>Flag high missing counts</b> before you analyze \u2014 a variable with many missing values will weaken results.','<b>Click Continue to analysis</b> when the dataset looks right.'],
      example:'<strong>Reading it:</strong> 250 rows, 12 variables, 8 numeric, with 0 missing on the key items means you can analyze every case with confidence.' },
    frequencies: { title:'Frequencies', what:'Frequencies count how often each value of a categorical or Likert variable appears, with percentages and a running cumulative total.',
      steps:['<b>Pick a variable</b> from the dropdown \u2014 choose categorical or Likert.','<b>Read the Count and Valid %</b> for each category to see how the sample breaks down.','<b>Check the Missing row</b> at the bottom to see how complete the data is.','<b>Toggle to Graph</b> for a bar or donut chart view.','<b>Click Save to report</b> to keep this table.'],
      example:'<strong>Reading it:</strong> if "Agree" + "Strongly agree" together are 70% of valid responses, most respondents lean positive on that item.' },
    distributions: { title:'Means & Distributions', what:'Summary statistics for every numeric variable: center (mean, median), spread (SD), and range (min–max), plus missing counts.',
      steps:['<b>Read Table 1</b> for each numeric variable\u2019s mean, SD, median, and range.','<b>Compare the mean and median</b> \u2014 a wide gap means the distribution is skewed.','<b>In Table 2,</b> pick a numeric variable and a grouping to compare averages by group.','<b>Note large group gaps</b> \u2014 carry them to the Inferential Studio to test.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> a mean of 3.9 with a median of 4.0 and SD of 0.9 (1–5 scale) is a mild left-leaning, fairly tight distribution.' },
    cross_tabs: { title:'Cross-Tabs', what:'A two-variable contingency table: counts and row percentages for how one categorical variable breaks down across another.',
      steps:['<b>Pick a Row variable and a Column variable</b> from the dropdowns.','<b>Read each cell</b> \u2014 the count and the row % (each row sums to 100%).','<b>Compare rows</b> to see whether the column split changes across row categories.','<b>If a pattern appears,</b> take it to Chi-square in the Inferential Studio to test it.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> if 80% of one group picks option A but only 40% of another does, that gap is worth testing in the Inferential Studio.' },
    group_summaries: { title:'Group Summaries', what:'Per-group means for a numeric outcome, with each group’s gap (Δ) from the overall mean.',
      steps:['<b>Pick an Outcome (numeric)</b> and a grouping variable from the dropdowns.','<b>Read each group\u2019s mean</b> and its \u0394 (gap from the overall mean).','<b>Note the largest gaps.</b>','<b>Take notable differences</b> to t-test (2 groups) or ANOVA (3+) in the Inferential Studio.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> a group sitting +0.6 above the overall mean scores noticeably higher — confirm with a t-test / ANOVA.' },
    top_bottom_items: { title:'Top & Bottom Items', what:'Every numeric item ranked by mean, with flags for low-variance, ceiling, and floor effects.',
      steps:['<b>Scan the ranked table</b> \u2014 items are sorted from highest to lowest mean.','<b>Check the Flags column:</b> ceiling items cluster at the top, floor at the bottom, low-variance items give little information about differences.','<b>Review flagged items</b> before relying on them \u2014 they may not discriminate well.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> an item flagged "ceiling" (almost everyone picks the top option) tells you little about differences between respondents.' },
    scale_scores: { title:'Scale Scores', what:'Combine several numeric items into one composite (the average of the chosen items per respondent). Descriptive only — no reliability.',
      steps:['<b>Tick the items</b> that belong to the scale \u2014 pick at least two.','<b>Read the composite N, mean, and SD</b> in the output table.','<b>Note the complete-case N</b> \u2014 any respondent missing one item is dropped.','<b>Toggle to Graph</b> to see the distribution as a histogram.','<b>For reliability (Cronbach\u2019s \u03b1),</b> take this scale to RSSI \u2014 not here.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> a 4-item composite with mean 3.8 / SD 0.7 summarizes the scale; whether the items hang together is an RSSI question.' },
    descriptive: { title:'Descriptive Analysis', what:'Summarize frequencies, distributions, and cross-tabulations for your variables before running inferential tests.',
      steps:['<b>Choose a sub-view</b> using the Frequencies / Means & Distributions / Cross-Tabs tabs at the top.','<b>Pick a variable</b> from the dropdown for the selected view.','<b>Read the table</b> and the interpretation layers below it.','<b>Click Save to report</b> to keep a snapshot.'],
      example:'<strong>Reading it:</strong> in Means & Distributions, the group with the highest average shows what the inferential tests should focus on.' },
    variables_fit: { title:'Variables \u0026 Fit', what:'Detects each variable\'s measurement type (numeric or categorical) and suggests the right statistical test for each pair.',
      steps:['<b>Read the Variables table</b> \u2014 check that each variable\u2019s detected role (Numeric / Categorical) is correct.','<b>Find your variable pair</b> in the Suggested tests table to see which test fits.','<b>Go to the suggested test step</b> in the left rail to run that analysis.','<b>If a variable is misclassified,</b> the test itself will flag the problem when you run it.'],
      example:'<strong>Reading it:</strong> a numeric outcome paired with a 2-level categorical predictor suggests a t-test; 3+ levels suggests ANOVA.' },
    t_test: { title:'Independent t-test', what:'Tests whether the means of two groups on a numeric outcome are statistically different.',
      steps:['<b>Pick the Outcome</b> \u2014 the numeric variable to compare between groups.','<b>Pick the Group variable</b> \u2014 it must have exactly 2 levels (e.g., male/female, yes/no).','<b>Click Run t-test.</b>','<b>Read t, df, and p</b> \u2014 p < .05 means a significant difference.','<b>Check Cohen\u2019s d</b> for effect size: small (\u22480.2), medium (\u22480.5), large (\u22480.8).','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> t(28) = 2.41, p = .023, d = 0.58 \u2014 a medium-effect significant difference between the two groups.' },
    anova: { title:'One-way ANOVA', what:'Tests whether a numeric outcome differs significantly across three or more groups.',
      steps:['<b>Pick the Outcome</b> \u2014 the numeric variable to compare across groups.','<b>Pick the Group variable</b> \u2014 it should have three or more levels.','<b>Click Run ANOVA.</b>','<b>Read F, p, and \u03b7\u00b2</b> \u2014 p < .05 means at least one group differs.','<b>Inspect the group means table</b> to see which groups are above or below the grand mean.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> F(2,57) = 5.12, p = .009, \u03b7\u00b2 = .15 \u2014 a large effect; at least one group mean differs from the others.' },
    chi_square: { title:'Chi-square', what:'Tests whether two categorical variables are statistically independent.',
      steps:['<b>Pick Variable A and Variable B</b> \u2014 both should be categorical.','<b>Click Run chi-square.</b>','<b>Read \u03c7\u00b2, df, and p</b> \u2014 p < .05 means the variables are not independent.','<b>Check Cram\u00e9r\u2019s V</b> for effect size: small (\u22480.1), medium (\u22480.3), large (\u22480.5).','<b>Read the contingency table</b> to see where the relationship is strongest.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> \u03c7\u00b2(2) = 8.34, p = .015, V = 0.29 \u2014 a moderate association; the two variables are not independent.' },
    correlation: { title:'Correlation', what:'Measures the strength and direction of the linear relationship between two numeric variables.',
      steps:['<b>Pick Variable X and Variable Y</b> \u2014 both should be numeric.','<b>Click Run correlation.</b>','<b>Read r and p</b> \u2014 the sign shows direction, the size shows strength.','<b>Check r\u00b2</b> \u2014 the percent of variance in one variable explained by the other.','<b>Remember:</b> a significant correlation is not proof of causation.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> r = .54, p < .001 \u2014 a moderate positive significant correlation; r\u00b2 = .29 means 29% of shared variance.' },
    regression: { title:'Regression', what:'Fits a simple OLS regression line to predict a numeric outcome from a numeric predictor.',
      steps:['<b>Pick the Outcome (Y)</b> \u2014 the variable you want to predict.','<b>Pick the Predictor (X)</b> \u2014 the numeric variable doing the predicting.','<b>Click Run regression.</b>','<b>Read the slope (b)</b> \u2014 how much Y changes per 1-unit increase in X.','<b>Read R\u00b2</b> for how much of Y\u2019s variance X explains.','<b>Check p for the slope</b> \u2014 p < .05 means the predictor is significant.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> Y = 1.2 + 0.6X, R\u00b2 = .34, p = .002 \u2014 for each 1-unit rise in X, Y rises 0.6; X accounts for 34% of Y\'s variance.' },
    effect_sizes: { title:'Effect Sizes', what:'Computes practical effect sizes for all numeric-by-group and numeric-by-numeric variable pairs in your dataset.',
      steps:['<b>Scan the table</b> \u2014 all variable pairs ranked from largest to smallest effect.','<b>Read the Effect column:</b> Cohen\u2019s d for t-test pairs, \u03b7\u00b2 for ANOVA pairs, r\u00b2 for correlation pairs.','<b>Use the size label</b> (negligible / small / medium / large) as a first screen.','<b>Go to the specific test step</b> in the rail to run a significance test on any pair that interests you.','<b>Click Save to report</b> when done.'],
      example:'<strong>Reading it:</strong> Cohen\'s d = 0.62 is a medium effect for a 2-group comparison; \u03b7\u00b2 = 0.14 is a large effect for a 3+ group comparison.' },
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
    descriptive:{ measures:'Frequencies (count/percent/valid%), summary statistics (N, mean, SD, min, max), and cross-tabulations with row percentages.', use:'Before inferential tests, to confirm the shape of the data and spot patterns worth testing.' },
    variables_fit:{ measures:'Variable types (numeric vs categorical) and a test-suggestion table for each variable pair.', use:'At the start of the Inferential Studio, to confirm variable types and choose the right test.' },
    t_test:{ measures:'Welch\'s t statistic, degrees of freedom, two-tailed p-value, and Cohen\'s d.', use:'To test whether two group means on a numeric outcome differ beyond sampling noise.' },
    anova:{ measures:'F statistic, between/within df, p-value, and eta-squared (\u03b7\u00b2), with group means.', use:'To test whether a numeric outcome differs across three or more groups.' },
    chi_square:{ measures:'Chi-square statistic, df, p-value, Cram\u00e9r\'s V, and a contingency table.', use:'To test whether two categorical variables are statistically independent.' },
    correlation:{ measures:'Pearson r, r\u00b2, t statistic, df, and p-value for the linear relationship.', use:'To measure the strength and direction of a linear relationship between two numeric variables.' },
    regression:{ measures:'OLS slope, intercept, R\u00b2, t statistic for the slope, and p-value.', use:'To predict a numeric outcome from a numeric predictor and describe how Y changes with X.' },
    effect_sizes:{ measures:'Cohen\'s d (2-group), \u03b7\u00b2 (3+ groups), or r\u00b2 (numeric pairs) for all variable combinations.', use:'To get a quick overview of practical effect sizes across all pairs before focusing on specific tests.' },
  };
  // Report-ready sentence drafts per step (the "Draft a sentence" suggestion).
  const DRAFT = {
    overview:'The dataset comprised the respondents and variables summarized above, with missing values noted per variable.',
    frequencies:'Frequencies were computed to describe how respondents were distributed across each category of the variable.',
    distributions:'Means, standard deviations, and ranges were calculated for each numeric variable to describe central tendency and spread.',
    cross_tabs:'A cross-tabulation examined how the two categorical variables were jointly distributed, with row percentages reported.',
    group_summaries:'Group means were compared descriptively across the grouping variable, with each group’s gap from the overall mean noted.',
    top_bottom_items:'Items were ranked by mean to identify the highest- and lowest-scoring items, flagging ceiling and low-variance items.',
    scale_scores:'A composite scale score was computed as the mean of the selected items for each respondent.',
    descriptive:'Descriptive statistics were examined prior to inferential testing, including frequency distributions, summary statistics, and cross-tabulations.',
    variables_fit:'Variable types were reviewed and test-variable pairs were identified to guide subsequent inferential analyses.',
    t_test:'An independent-samples t-test was conducted to compare the means of the two groups on the outcome variable.',
    anova:'A one-way ANOVA was conducted to examine differences in the outcome variable across groups.',
    chi_square:'A chi-square test of independence examined whether the two categorical variables were related.',
    correlation:'A Pearson correlation was computed to examine the linear relationship between the two variables.',
    regression:'Simple OLS regression was used to predict the outcome variable from the predictor variable.',
    effect_sizes:'Effect sizes were computed for all variable pairs to describe the practical magnitude of associations.',
  };
  // ReliCheck Intelligence — deterministic, content-aware responses (no fake AI).
  AS.intel = function(kind, key, label){
    const h=HELP[key]||{}, c=COACH[key]||{};
    if (kind==='explain') return esc(h.what || ('This step summarizes part of your data ('+(label||'')+').'));
    if (kind==='draft')   return esc(DRAFT[key] || ('Add a sentence summarizing '+(label||'this step')+' to your report.'));
    if (kind==='next')    return esc(c.use ? (c.use+' Then use Save to report to keep the result.') : 'Run this step, then Save to report and continue to the next step in the rail.');
    return '';
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
    const eyebrow = ctx.kind === 'inferential' ? 'Inferential Analysis' : 'Descriptive Analysis';
    VIEW = ctx.view || 'table';
    if (!ds || !Array.isArray(ds.variables) || !ds.variables.length){
      host.innerHTML = header(eyebrow, titleFor(tool), '')
        + '<div class="as-empty-tool">Load data first — use the <strong>Data</strong> bar below.</div>';
      return;
    }
    const fns = { frequencies:renderFreq, distributions:renderMeans, cross_tabs:renderCross, group_summaries:renderGroups, top_bottom_items:renderTopBottom, scale_scores:renderScale,
      variables_fit:renderVariablesFit, descriptive:renderDescriptive, t_test:renderTTest, paired_t:renderPairedT, paired_t_test:renderPairedT, anova:renderAnova,
      chi_square:renderChiSquare, correlation:renderCorrelation, regression:renderRegression, effect_sizes:renderEffectSizes,
      welch_anova:renderWelchAnova, post_hoc:renderPostHoc, confidence_interval:renderConfidenceInterval,
      assumption_checks:renderAssumptionChecks, assumption_check:renderAssumptionChecks, recommended_analyses:renderVariablesFit,
      two_way_anova:renderTwoWayAnova, twoway_anova:renderTwoWayAnova, logistic_regression:renderLogisticRegression };
    const fn = fns[tool];
    if (!fn){ host.innerHTML = header(eyebrow, titleFor(tool), '') + '<div class="as-empty-tool">This analysis is being built.</div>'; return; }
    fn(host, ds, ctx);
  };
  function titleFor(t){ return ({frequencies:'Frequencies',distributions:'Means & Distributions',cross_tabs:'Cross-Tabs',group_summaries:'Group Summaries',top_bottom_items:'Top & Bottom Items',scale_scores:'Scale Scores',variables_fit:'Variables & Fit',descriptive:'Descriptive Analysis',t_test:'Independent t-test',paired_t:'Paired t-test',paired_t_test:'Paired t-test',anova:'One-way ANOVA',chi_square:'Chi-square',correlation:'Correlation',regression:'Regression',effect_sizes:'Effect Sizes',welch_anova:'Welch ANOVA',post_hoc:'Post-Hoc Comparison',confidence_interval:'Confidence Intervals',assumption_checks:'Assumption Checks',assumption_check:'Assumption Checks',recommended_analyses:'Variables & Fit',two_way_anova:'Two-Way ANOVA',twoway_anova:'Two-Way ANOVA',logistic_regression:'Logistic Regression'})[t]||'Analysis'; }

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
    const checks='<div class="sc-items">'+nv.map(function(v){ const on=sel.scale.indexOf(v.name)>=0; return '<label class="sc-item'+(on?' on':'')+'"><input type="checkbox" class="scItem" value="'+esc(v.name)+'"'+(on?' checked':'')+'> '+esc(v.name)+'</label>'; }).join('')+'</div>';
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
    host.querySelectorAll('.scItem').forEach(function(cb){ cb.addEventListener('change',function(){ const v=cb.value; const i=sel.scale.indexOf(v); if(cb.checked&&i<0) sel.scale.push(v); else if(!cb.checked&&i>=0) sel.scale.splice(i,1); const lab=cb.closest('.sc-item'); if(lab) lab.classList.toggle('on', cb.checked); drawOut(); }); });
    drawOut();
  }

  // Descriptive state (sub-tab + stored refs for tab-switching)
  var desc = {tab:'freq', host:null, ds:null, ctx:null};

  // ---- Descriptive Analysis (Frequencies | Means & Distributions | Cross-Tabs) ----
  function renderDescriptive(host, ds, ctx) {
    desc.host = host; desc.ds = ds; desc.ctx = ctx;
    var subtabs = ttTabs(
      [['freq','Frequencies'],['means','Means & Distributions'],['cross','Cross-Tabs']],
      desc.tab, 'descriptive'
    );
    host.innerHTML = header('Inferential Analysis','Descriptive Analysis',
      'Summarize the quantitative pattern before running inferential tests.','descriptive')
      + subtabs
      + '<div id="descContent"></div>';
    var cont = host.querySelector('#descContent');
    if (desc.tab === 'freq')  renderDescFreq(cont, ds);
    else if (desc.tab === 'means') renderDescMeans(cont, ds);
    else renderDescCross(cont, ds);
    if (ctx && ctx.onResult) ctx.onResult();
  }
  AS.descTab = function(tab) {
    desc.tab = tab;
    if (desc.host && desc.ds) renderDescriptive(desc.host, desc.ds, desc.ctx);
  };

  // ---- Descriptive sub-views ----
  function renderDescFreq(cont, ds) {
    var cands = discreteVars(ds).length ? discreteVars(ds) : (catVars(ds).length ? catVars(ds) : ds.variables);
    if (!cands.length) {
      cont.innerHTML = '<div class="as-empty-tool">No categorical or Likert variables found to tabulate.</div>'; return;
    }
    if (!sel.dFreq || !varByName(ds, sel.dFreq)) sel.dFreq = cands[0].name;
    var vOpts = cands.map(function(v){
      return '<option value="'+esc(v.name)+'"'+(v.name===sel.dFreq?' selected':'')+'>'+esc(v.name)+'</option>';
    }).join('');
    function drawFreq() {
      var v = varByName(ds, sel.dFreq); if (!v) return;
      var nm = nonMissing(v.values); var total = v.values.length; var miss = total - nm.length;
      var counts = {}; nm.forEach(function(x){counts[x]=(counts[x]||0)+1;});
      var rows = Object.keys(counts).map(function(k){return {value:k,count:counts[k]};}).sort(function(a,b){return b.count-a.count;});
      var maxc = rows.length ? rows[0].count : 1; var cum = 0;
      var body = rows.map(function(r){
        var valid = 100*r.count/nm.length; cum += valid; var all = 100*r.count/total;
        return '<tr><td class="dx-name">'+esc(r.value)+'</td><td>'+r.count+'</td>'
          +'<td>'+pc(all)+'</td><td>'+pc(valid)+'</td><td>'+pc(cum)+'</td></tr>';
      }).join('');
      var missRow = miss>0 ? '<tr><td class="dx-name">Missing</td><td>'+miss+'</td><td>'+pc(100*miss/total)+'</td><td>—</td><td>—</td></tr>' : '';
      var top = rows[0];
      var layerBlocks = [
        {k:'What this shows', t: top ? 'The most common value of <strong>'+esc(v.name)+'</strong> was "'+esc(top.value)+'" — '+pc(100*top.count/nm.length)+' of '+nm.length+' valid responses.' : 'No non-missing responses.'},
        {k:'Why this matters', t:'How the sample distributes across '+esc(v.name)+' shapes how every other result should be read.'},
        {k:'Caution', caution:true, t:'Counts describe who is in the data; on their own they do not explain differences between groups.'}
      ];
      cont.querySelector('#dFreqOut').innerHTML =
        '<div style="margin:0 0 12px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">'
        +'<label class="ed-l" style="margin:0">Variable</label>'
        +'<select class="ed-in" style="max-width:280px" id="dFreqSel">'+vOpts+'</select></div>'
        +'<div class="panel"><div class="panel-h"><div><h3>Frequency distribution for '+esc(v.name)+'</h3></div></div>'
        +'<div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
        +'<thead><tr><th class="l">'+esc(v.name)+'</th><th>Frequency</th><th>Percent</th><th>Valid %</th><th>Cumulative %</th></tr></thead>'
        +'<tbody>'+body+missRow
        +'<tr class="dx-total"><td class="dx-name">Total</td><td>'+total+'</td><td>100.0%</td><td>100.0%</td><td>—</td></tr>'
        +'</tbody></table></div>'
        +layers(layerBlocks)+'</div></div>';
      var sel2 = cont.querySelector('#dFreqSel');
      if (sel2) sel2.addEventListener('change', function(e){sel.dFreq=e.target.value; drawFreq();});
    }
    cont.innerHTML = '<div id="dFreqOut"></div>';
    drawFreq();
  }

  function renderDescMeans(cont, ds) {
    var nv = numericVars(ds); var cv = catVars(ds);
    if (!nv.length) {
      cont.innerHTML = '<div class="as-empty-tool">No numeric variables found.</div>'; return;
    }
    var rows = nv.map(function(v){
      var a = nums(v.values); var total = v.values.length;
      return {name:v.name, n:a.length, mean:mean(a), sd:sd(a),
              min:a.length?Math.min.apply(null,a):null, max:a.length?Math.max.apply(null,a):null,
              missing:total-nonMissing(v.values).length};
    });
    var t1body = rows.map(function(r){
      return '<tr><td class="dx-name">'+esc(r.name)+'</td><td>'+r.n+'</td><td>'+n2(r.mean)+'</td><td>'+n2(r.sd)+'</td><td>'+n2(r.min)+'</td><td>'+n2(r.max)+'</td><td>'+r.missing+'</td></tr>';
    }).join('');
    var hi = rows.reduce(function(a,b){return b.mean>a.mean?b:a;}, rows[0]);
    var lo = rows.reduce(function(a,b){return b.mean<a.mean?b:a;}, rows[0]);
    var t1layers = [
      {k:'What this shows', t: rows.length>1
        ? 'Across '+rows.length+' numeric variables, "'+esc(hi.name)+'" had the highest average (M = '+n2(hi.mean)+') and "'+esc(lo.name)+'" the lowest (M = '+n2(lo.mean)+').'
        : '"'+esc(hi.name)+'" had a mean of '+n2(hi.mean)+'.'},
      {k:'Why this matters', t:'These averages set up the comparisons the inferential tests will evaluate.'},
      {k:'Caution', caution:true, t:'Averages do not test whether differences are statistically significant — take promising gaps to t-test or ANOVA.'}
    ];
    var t1html = '<div class="panel"><div class="panel-h"><div><h3>Table 1 · Summary statistics for numeric variables</h3></div></div>'
      +'<div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
      +'<thead><tr><th class="l">Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Min</th><th>Max</th><th>Missing</th></tr></thead>'
      +'<tbody>'+t1body+'</tbody></table></div>'+layers(t1layers)+'</div></div>';
    // Table 2 — means by group
    var t2html = '';
    if (nv.length && cv.length) {
      if (!sel.dMNum || !varByName(ds,sel.dMNum)) sel.dMNum = nv[0].name;
      if (!sel.dMGrp || !varByName(ds,sel.dMGrp)) sel.dMGrp = cv[0].name;
      var numOpts = nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.dMNum?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
      var grpOpts = cv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.dMGrp?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
      t2html = '<div class="panel"><div class="panel-h"><div><h3 id="dMT2title">Table 2 · Mean by group</h3></div>'
        +'<div style="margin:4px 0 0;display:flex;align-items:center;gap:6px;flex-wrap:wrap">'
        +'<select class="ed-in" style="max-width:200px" id="dMNum">'+numOpts+'</select>'
        +'<span class="ed-l" style="margin:0">by</span>'
        +'<select class="ed-in" style="max-width:200px" id="dMGrp">'+grpOpts+'</select>'
        +'</div></div><div class="panel-b"><div id="dMT2out"></div></div></div>';
    }
    cont.innerHTML = t1html + t2html;
    if (nv.length && cv.length) {
      function drawT2(){
        var ov = varByName(ds,sel.dMNum), gv = varByName(ds,sel.dMGrp);
        var out = cont.querySelector('#dMT2out'); if(!ov||!gv||!out) return;
        var ti = cont.querySelector('#dMT2title'); if(ti) ti.textContent = 'Table 2 · Mean of '+ov.name+' by '+gv.name;
        var n = Math.min(ov.values.length,gv.values.length); var groups = {};
        for(var i=0;i<n;i++){var g=gv.values[i],y=num(ov.values[i]);if(!isMissing(g)&&y!==null)(groups[g]=groups[g]||[]).push(y);}
        var all=[].concat.apply([],Object.keys(groups).map(function(k){return groups[k];})); var grand=mean(all);
        var grows=Object.keys(groups).map(function(g){var a=groups[g];var m=mean(a);return {group:g,n:a.length,mean:m,sd:sd(a),delta:m-grand};}).sort(function(a,b){return b.mean-a.mean;});
        var gbody=grows.map(function(r){return '<tr><td class="dx-name">'+esc(r.group)+'</td><td>'+r.n+'</td><td>'+n2(r.mean)+'</td><td>'+n2(r.sd)+'</td><td class="'+(r.delta<0?'dx-neg':'dx-pos')+'">'+(r.delta>0?'+':'')+n2(r.delta)+'</td></tr>';}).join('');
        out.innerHTML='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>Δ from overall</th></tr></thead><tbody>'
          +gbody+'<tr class="dx-total"><td class="dx-name">Overall</td><td>'+all.length+'</td><td>'+n2(grand)+'</td><td>'+n2(sd(all))+'</td><td>—</td></tr></tbody></table></div>';
      }
      var elN=cont.querySelector('#dMNum'),elG=cont.querySelector('#dMGrp');
      if(elN) elN.addEventListener('change',function(e){sel.dMNum=e.target.value;drawT2();});
      if(elG) elG.addEventListener('change',function(e){sel.dMGrp=e.target.value;drawT2();});
      drawT2();
    }
  }

  function renderDescCross(cont, ds) {
    var cv = catVars(ds).length >= 2 ? catVars(ds) : ds.variables;
    if (!cv || cv.length < 2) {
      cont.innerHTML = '<div class="as-empty-tool">Cross-tabs need at least two categorical variables.</div>'; return;
    }
    if (!sel.dCRow || !varByName(ds,sel.dCRow)) sel.dCRow = cv[0].name;
    if (!sel.dCCol || !varByName(ds,sel.dCCol) || sel.dCCol===sel.dCRow) sel.dCCol = cv[1].name;
    var rowOpts = cv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.dCRow?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var colOpts = cv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.dCCol?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    cont.innerHTML = '<div style="margin:0 0 12px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">'
      +'<label class="ed-l" style="margin:0">Rows</label><select class="ed-in" style="max-width:220px" id="dCRow">'+rowOpts+'</select>'
      +'<label class="ed-l" style="margin:0 0 0 12px">Columns</label><select class="ed-in" style="max-width:220px" id="dCCol">'+colOpts+'</select>'
      +'</div><div id="dCOut"></div>';
    function drawCross(){
      var rv = varByName(ds,sel.dCRow), cvv = varByName(ds,sel.dCCol);
      var out = cont.querySelector('#dCOut'); if(!rv||!cvv||!out) return;
      var n = Math.min(rv.values.length,cvv.values.length);
      var colVals=[]; for(var i=0;i<n;i++){var c=cvv.values[i];if(!isMissing(c)&&colVals.indexOf(c)<0)colVals.push(c);}
      var rowMap={};
      for(var i=0;i<n;i++){var r=rv.values[i],c=cvv.values[i];if(!isMissing(r)&&!isMissing(c)){rowMap[r]=rowMap[r]||{};rowMap[r][c]=(rowMap[r][c]||0)+1;}}
      var rowKeys=Object.keys(rowMap); var colTotals={}; var grand=0;
      var trs=rowKeys.map(function(rk){var cells=rowMap[rk];var rt=colVals.reduce(function(s,c){return s+(cells[c]||0);},0);grand+=rt;
        var tds=colVals.map(function(c){var v=cells[c]||0;colTotals[c]=(colTotals[c]||0)+v;return '<td>'+v+'</td><td>'+pc(rt?100*v/rt:0)+'</td>';}).join('');
        return '<tr><td class="dx-name">'+esc(rk)+'</td>'+tds+'<td>'+rt+'</td></tr>';}).join('');
      var totTds=colVals.map(function(c){return '<td>'+(colTotals[c]||0)+'</td><td>'+pc(grand?100*(colTotals[c]||0)/grand:0)+'</td>';}).join('');
      var layerBlocks=[
        {k:'What this shows', t:'How '+esc(rv.name)+' breaks down across '+esc(cvv.name)+'. Row % reads each row as 100%.'},
        {k:'Caution', caution:true, t:'This table shows a pattern; whether it is statistically significant requires a chi-square test.'}
      ];
      out.innerHTML='<div class="panel"><div class="panel-h"><div><h3>'+esc(rv.name)+' × '+esc(cvv.name)+'</h3></div></div>'
        +'<div class="panel-b"><div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">'+esc(rv.name)+'</th>'
        +colVals.map(function(c){return '<th>'+esc(c)+' n</th><th>'+esc(c)+' %</th>';}).join('')+'<th>Total</th></tr></thead><tbody>'
        +trs+'<tr class="dx-total"><td class="dx-name">Total</td>'+totTds+'<td>'+grand+'</td></tr>'
        +'</tbody></table></div>'+layers(layerBlocks)+'</div></div>';
    }
    var elR=cont.querySelector('#dCRow'),elC=cont.querySelector('#dCCol');
    if(elR) elR.addEventListener('change',function(e){sel.dCRow=e.target.value;drawCross();});
    if(elC) elC.addEventListener('change',function(e){sel.dCCol=e.target.value;drawCross();});
    drawCross();
  }


  // ---- Variables & Fit ----
  function renderVariablesFit(host,ds,ctx){
    var nv=numericVars(ds),cv=catVars(ds);
    var varBody=ds.variables.map(function(v){
      var kind=isNumericVar(v)?'Numeric':(isCategoricalVar(v)?'Categorical':'Other');
      return '<tr><td class="dx-name">'+esc(v.name)+'</td><td>'+esc((v.types||['-'])[0])+'</td><td>'+kind+'</td><td>'+nonMissing(v.values).length+'</td></tr>';
    }).join('');
    var pairs=[];
    nv.forEach(function(v1){
      nv.forEach(function(v2){ if(v1.name<v2.name) pairs.push({a:v1.name,b:v2.name,test:'Pearson correlation'}); });
      cv.forEach(function(c){
        var levels=new Set(nonMissing(c.values));
        var test=levels.size===2?'Independent t-test':(levels.size>=3?'One-way ANOVA':'-- (need 2+ levels)');
        pairs.push({a:v1.name,b:c.name,test:test});
      });
    });
    cv.forEach(function(c1){
      cv.forEach(function(c2){ if(c1.name<c2.name) pairs.push({a:c1.name,b:c2.name,test:'Chi-square'}); });
    });
    var pairBody=pairs.length?pairs.map(function(p){
      return '<tr><td class="dx-name">'+esc(p.a)+'</td><td class="dx-name">'+esc(p.b)+'</td><td>'+esc(p.test)+'</td></tr>';
    }).join(''):'<tr><td colspan="3">Need at least two numeric or categorical variables for suggestions.</td></tr>';
    var layerBlocks=[
      {k:'What this shows',t:'Variable types detected in your dataset and the statistical test that fits each pair.'},
      {k:'Why this matters',t:'Matching the right test to the measurement level of your variables is the first step in valid inference.'},
      {k:'Caution',caution:true,t:'Type detection is heuristic. Review the list and continue to the correct test step.'}
    ];
    host.innerHTML=header('Inferential Analysis','Variables & Fit','What types are your variables, and which tests fit each pair?','variables_fit')
      +'<div class="panel"><div class="panel-h"><h3>Variables in this dataset</h3></div><div class="panel-b">'
      +'<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variable</th><th class="l">Reported type</th><th>Role</th><th>Valid n</th></tr></thead><tbody>'
      +varBody+'</tbody></table></div></div></div>'
      +(pairs.length?'<div class="panel"><div class="panel-h"><h3>Suggested tests by pair</h3></div><div class="panel-b">'
        +'<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variable A</th><th class="l">Variable B</th><th>Suggested test</th></tr></thead><tbody>'
        +pairBody+'</tbody></table></div>'+layers(layerBlocks)+'</div></div>'
      :'<div class="panel"><div class="panel-b">'+layers(layerBlocks)+'</div></div>');
    if(ctx&&ctx.onResult) ctx.onResult();
  }

  // ---- Shared: tabbed results ----
  function ttTabs(tabs,cur,testKey){
    var fnMap={t_test:'ttTab',paired_t:'ptdTab',paired_t_test:'ptdTab',anova:'anTab',chi_square:'csqTab',correlation:'corTab',regression:'regTab',descriptive:'descTab',mann_whitney:'mwuTab',wilcoxon:'wsrtTab',kruskal_wallis:'kwTab',spearman:'sprTab',welch_anova:'waTab',post_hoc:'phTab',confidence_interval:'ciTab',assumption_checks:'acTab',two_way_anova:'twaTab',twoway_anova:'twaTab',logistic_regression:'lrTab'};
    var fn=fnMap[testKey]||'ttTab';
    return '<div class="tt-tabs">'+tabs.map(function(t){
      var on=t[0]===cur?' on':'';
      return '<button class="tt-tab'+on+'" onclick="AnalysisStudio.'+fn+'(\''+esc(t[0])+'\')">'+esc(t[1])+'</button>';
    }).join('')+'</div>';
  }
  function ttStatus(sig){
    return '<span class="tt-status '+(sig?'ok':'rev')+'">'+(sig?'Significant':'Not significant')+'</span>';
  }
  function fmtN(v,d){ if(v==null||!isFinite(v)) return '—'; return Number(v).toFixed(d==null?2:d); }

  // t-test state
  var tt={grp:null,g1:'',g2:'',testType:'auto',conf:0.95,result:null,tab:'desc',busy:false,method:'parametric'};

  // ---- Independent t-test ----
  function renderTTest(host,ds,ctx){
    if(tt.method==='mann_whitney') return renderMannWhitney(host,ds,ctx);
    if(tt.method==='wilcoxon')    return renderWilcoxon(host,ds,ctx);
    var nv=numericVars(ds);
    var gv=ds.variables.filter(function(v){
      var nm=nonMissing(v.values); var d=new Set(nm).size; return d>=2&&d<=20;
    });
    if(!nv.length||!gv.length){
      host.innerHTML=header('Inferential Analysis','Independent t-test','','t_test')
        +'<div class="as-empty-tool">Need at least one numeric outcome and one grouping variable (2+ distinct values).</div>'; return;
    }
    if(!tt.grp||!ds.variables.find(function(v){return v.name===tt.grp;})) tt.grp=gv[0].name;
    if(!sel.tt_out||!varByName(ds,sel.tt_out)) sel.tt_out=nv[0].name;
    var gvObj=varByName(ds,tt.grp);
    var glevels=gvObj?Array.from(new Set(nonMissing(gvObj.values))).sort():[];
    var gCounts={};
    if(gvObj) gvObj.values.forEach(function(v){if(!isMissing(v))gCounts[v]=(gCounts[v]||0)+1;});
    if(!tt.g1||glevels.indexOf(tt.g1)<0) tt.g1=glevels[0]||'';
    if(!tt.g2||glevels.indexOf(tt.g2)<0||tt.g2===tt.g1) tt.g2=glevels[1]||'';
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.tt_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts=gv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===tt.grp?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var g1Opts=glevels.map(function(g){return '<option value="'+esc(g)+'"'+(g===tt.g1?' selected':'')+'>'+esc(g)+' (n='+(gCounts[g]||0)+')</option>';}).join('');
    var g2Opts=glevels.map(function(g){return '<option value="'+esc(g)+'"'+(g===tt.g2?' selected':'')+'>'+esc(g)+' (n='+(gCounts[g]||0)+')</option>';}).join('');
    var seg=function(k,l){return '<button class="tt-seg'+(tt.testType===k?' on':'')+'" data-as-tt-type="'+k+'">'+l+'</button>';};
    var mseg=function(k,l){return '<button class="tt-seg'+(tt.method===k?' on':'')+'" data-as-tt-method="'+k+'">'+l+'</button>';};
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and the two groups to compare</div></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Method</label><div class="tt-segs">'+mseg('parametric','Welch t-test')+mseg('mann_whitney','Mann-Whitney U')+mseg('wilcoxon','Wilcoxon (paired)')+'</div></div>'
      +'<div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="ttOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="ttGrp">'+grpOpts+'</select></div>'
      +'<div class="field"><label>Group 1</label><select class="ed-in" id="ttG1">'+g1Opts+'</select></div>'
      +'<div class="field"><label>Group 2</label><select class="ed-in" id="ttG2">'+g2Opts+'</select></div>'
      +'<div class="field"><label>Test type</label><div class="tt-segs">'+seg('auto','Auto')+seg('welch','Welch')+seg('student','Student')+'</div><div class="tt-hint" style="margin-top:5px">Welch is the default — safer when group sizes or variances differ.</div></div>'
      +'<div class="field"><label>Confidence</label><select class="ed-in" id="ttConf"><option value="0.90">90%</option><option value="0.95"'+(tt.conf===0.95?' selected':'')+'>95%</option><option value="0.99">99%</option></select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="ttRun"'+(tt.busy?' disabled':'')+'>'+(tt.busy?'Running…':'▷ Run t-test')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','Independent t-test','Compare the mean of one outcome across two groups.','t_test')
      +setup+'<div id="ttResults">'+(tt.result?renderTTestResults(tt.result,ctx):'')+'</div>';
    var elOut=host.querySelector('#ttOut'),elGrp=host.querySelector('#ttGrp');
    var elG1=host.querySelector('#ttG1'),elG2=host.querySelector('#ttG2'),elConf=host.querySelector('#ttConf');
    if(elOut) elOut.addEventListener('change',function(e){sel.tt_out=e.target.value;});
    if(elGrp) elGrp.addEventListener('change',function(e){tt.grp=e.target.value;tt.g1='';tt.g2='';renderTTest(host,ds,ctx);});
    if(elG1)  elG1.addEventListener('change',function(e){tt.g1=e.target.value;});
    if(elG2)  elG2.addEventListener('change',function(e){tt.g2=e.target.value;});
    if(elConf) elConf.addEventListener('change',function(e){tt.conf=parseFloat(e.target.value);});
    host.querySelectorAll('[data-as-tt-type]').forEach(function(btn){
      btn.addEventListener('click',function(){tt.testType=btn.getAttribute('data-as-tt-type');renderTTest(host,ds,ctx);});
    });
    host.querySelectorAll('[data-as-tt-method]').forEach(function(btn){
      btn.addEventListener('click',function(){tt.method=btn.getAttribute('data-as-tt-method');tt.result=null;renderTTest(host,ds,ctx);});
    });
    var runBtn=host.querySelector('#ttRun');
    if(runBtn) runBtn.addEventListener('click',function(){
      if(tt.busy) return;
      if(tt.g1===tt.g2) return;
      var ov=varByName(ds,sel.tt_out),gvObj2=varByName(ds,tt.grp); if(!ov||!gvObj2) return;
      var n=Math.min(ov.values.length,gvObj2.values.length),vals=[],grps=[];
      for(var i=0;i<n;i++){var v=num(ov.values[i]),g=gvObj2.values[i];if(v!==null&&!isMissing(g)){vals.push(v);grps.push(String(g));}}
      tt.busy=true; tt.result=null;
      renderTTest(host,ds,ctx);
      fetch('/api/analysis/infer.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({tool:'t_test',values:vals,groups:grps,group1:tt.g1,group2:tt.g2,
          outcome_name:sel.tt_out,group_name:tt.grp,confidence:tt.conf})})
        .then(function(r){return r.json();})
        .then(function(d){
          tt.busy=false; tt.result=d; tt.tab='desc';
          var rb=host.querySelector('#ttRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run t-test';}
          var out=host.querySelector('#ttResults');
          if(out) out.innerHTML=renderTTestResults(d,ctx);
          if(d.ok&&ctx&&ctx.onResult) ctx.onResult();
        })
        .catch(function(){
          tt.busy=false;
          var rb=host.querySelector('#ttRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run t-test';}
          var out=host.querySelector('#ttResults');
          if(out) out.innerHTML='<div class="as-empty-tool">Network error — please try again.</div>';
        });
    });
  }
  function renderTTestResults(d,ctx){
    if(!d||!d.ok) return '<div class="as-empty-tool">'+esc(d&&d.error?d.error:'Error running t-test.')+'</div>';
    var tabs=ttTabs([['desc','Descriptives'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],tt.tab,'t_test');
    var body='';
    if(tt.tab==='desc'){
      var rows=(d.descriptives||[]).map(function(g){
        return '<tr><td class="dx-name">'+esc(g.group)+'</td><td>'+g.n+'</td><td>'+fmtN(g.mean)+'</td><td>'+fmtN(g.sd)+'</td><td>'+fmtN(g.se)+'</td><td>'+fmtN(g.min)+'</td><td>'+fmtN(g.max)+'</td></tr>';
      }).join('');
      var D=d.difference||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>SE</th><th>Min</th><th>Max</th></tr></thead><tbody>'+rows+'</tbody></table></div>'
        +'<div class="ov-sec" style="margin-top:18px">Mean difference</div>'
        +'<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>Group 1 mean</th><th>Group 2 mean</th><th>Difference</th><th class="l">Direction</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.outcome&&d.outcome.name||'')+'</td><td>'+fmtN(D.mean1)+'</td><td>'+fmtN(D.mean2)+'</td>'
        +'<td class="'+(D.diff<0?'dx-neg':'dx-pos')+'">'+(D.diff>0?'+':'')+fmtN(D.diff)+'</td>'
        +'<td class="dx-interp">'+esc(D.direction||'')+'</td></tr></tbody></table></div>';
    } else if(tt.tab==='result'){
      var R=d.result||{}; var pct=Math.round((R.conf_level||0.95)*100);
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Test used</th><th>t</th><th>df</th><th>p</th><th>Mean diff</th><th>'+pct+'% CI low</th><th>'+pct+'% CI high</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-interp">'+esc(R.test_used||'')+'</td><td>'+fmtN(R.t)+'</td><td>'+fmtN(R.df,1)+'</td>'
        +'<td>'+esc(R.p_str||'')+'</td><td>'+fmtN(R.diff)+'</td><td>'+fmtN(R.ci_lo)+'</td><td>'+fmtN(R.ci_hi)+'</td>'
        +'<td class="dx-interp">'+ttStatus(R.significant)+'</td></tr></tbody></table></div>';
    } else if(tt.tab==='effect'){
      var E=d.effect||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.outcome&&d.outcome.name||'')+'</td><td class="dx-interp">'+esc(E.type||'')+'</td>'
        +'<td>'+fmtN(E.value,3)+'</td><td class="dx-interp">'+esc(E.interpretation||'')+'</td><td class="dx-interp">'+esc(E.meaning||'')+'</td></tr></tbody></table></div>';
    } else {
      var L=d.reporting||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">'+esc(L.plain||'')+'</td></tr>'
        +'<tr><td class="dx-name">Researcher summary</td><td class="dx-interp">'+esc(L.researcher||'')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">'+esc(L.caution||'')+'</td></tr>'
        +'</tbody></table></div>';
    }
    var rpt=d.reporting||{};
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>'
      +'<div class="dx-layers">'
      +'<div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(rpt.plain||'')+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">'+esc(rpt.caution||'')+'</div></div>'
      +'</div>';
  }
  AS.ttTab=function(tab){tt.tab=tab;var out=document.getElementById('ttResults');if(out&&tt.result)out.innerHTML=renderTTestResults(tt.result,null);};

  // ANOVA state
  var an={result:null,tab:'desc',busy:false,method:'parametric'};

  // ---- One-way ANOVA ----
  function renderAnova(host,ds,ctx){
    if(an.method==='kruskal_wallis') return renderKruskalWallis(host,ds,ctx);
    var nv=numericVars(ds);
    var gv=ds.variables.filter(function(v){var nm=nonMissing(v.values);var d=new Set(nm).size;return d>=3&&d<=20;});
    if(!nv.length||!gv.length){
      host.innerHTML=header('Inferential Analysis','One-way ANOVA','','anova')
        +'<div class="as-empty-tool">ANOVA needs a numeric outcome and a grouping with 3 or more categories. For exactly 2 groups, use the t-test.</div>'; return;
    }
    if(!sel.an_out||!varByName(ds,sel.an_out)) sel.an_out=nv[0].name;
    if(!sel.an_grp||!varByName(ds,sel.an_grp)) sel.an_grp=gv[0].name;
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts=gv.map(function(v){var cnt=new Set(nonMissing(v.values)).size;return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_grp?' selected':'')+'>'+esc(v.name)+' ('+cnt+' groups)</option>';}).join('');
    var amseg=function(k,l){return '<button class="tt-seg'+(an.method===k?' on':'')+'" data-as-an-method="'+k+'">'+l+'</button>';};
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and a grouping with 3 or more categories</div></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Method</label><div class="tt-segs">'+amseg('parametric','Welch ANOVA')+amseg('kruskal_wallis','Kruskal-Wallis')+'</div></div>'
      +'<div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="anOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">3+ categories</span></label><select class="ed-in" id="anGrp">'+grpOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="anRun"'+(an.busy?' disabled':'')+'>'+(an.busy?'Running…':'▷ Run ANOVA')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','One-way ANOVA','Compare the mean of one outcome across three or more groups.','anova')
      +setup+'<div id="anResults">'+(an.result?renderAnovaResults(an.result,ctx):'')+'</div>';
    var elOut=host.querySelector('#anOut'),elGrp=host.querySelector('#anGrp');
    if(elOut) elOut.addEventListener('change',function(e){sel.an_out=e.target.value;});
    if(elGrp) elGrp.addEventListener('change',function(e){sel.an_grp=e.target.value;});
    host.querySelectorAll('[data-as-an-method]').forEach(function(btn){
      btn.addEventListener('click',function(){an.method=btn.getAttribute('data-as-an-method');an.result=null;renderAnova(host,ds,ctx);});
    });
    var runBtn=host.querySelector('#anRun');
    if(runBtn) runBtn.addEventListener('click',function(){
      if(an.busy) return;
      var ov=varByName(ds,sel.an_out),gvObj=varByName(ds,sel.an_grp); if(!ov||!gvObj) return;
      var n=Math.min(ov.values.length,gvObj.values.length),vals=[],grps=[];
      for(var i=0;i<n;i++){var v=num(ov.values[i]),g=gvObj.values[i];if(v!==null&&!isMissing(g)){vals.push(v);grps.push(String(g));}}
      an.busy=true; an.result=null;
      renderAnova(host,ds,ctx);
      fetch('/api/analysis/infer.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({tool:'anova',values:vals,groups:grps,outcome_name:sel.an_out,group_name:sel.an_grp})})
        .then(function(r){return r.json();})
        .then(function(d){
          an.busy=false; an.result=d; an.tab='desc';
          var rb=host.querySelector('#anRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run ANOVA';}
          var out=host.querySelector('#anResults');
          if(out) out.innerHTML=renderAnovaResults(d,ctx);
          if(d.ok&&ctx&&ctx.onResult) ctx.onResult();
        })
        .catch(function(){
          an.busy=false;
          var rb=host.querySelector('#anRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run ANOVA';}
          var out=host.querySelector('#anResults');
          if(out) out.innerHTML='<div class="as-empty-tool">Network error — please try again.</div>';
        });
    });
  }
  function renderAnovaResults(d,ctx){
    if(!d||!d.ok) return '<div class="as-empty-tool">'+esc(d&&d.error?d.error:'Error running ANOVA.')+'</div>';
    var tabs=ttTabs([['desc','Descriptives'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],an.tab,'anova');
    var body='';
    if(an.tab==='desc'){
      var grandM=d.grouping&&d.grouping.grand_mean!=null?d.grouping.grand_mean:null;
      var rows=(d.descriptives||[]).map(function(g){
        var delta=grandM!=null?g.mean-grandM:null;
        return '<tr><td class="dx-name">'+esc(g.group)+'</td><td>'+g.n+'</td><td>'+fmtN(g.mean)+'</td><td>'+fmtN(g.sd)+'</td><td>'+fmtN(g.se)+'</td><td>'+fmtN(g.min)+'</td><td>'+fmtN(g.max)+'</td>'
          +(delta!=null?'<td class="'+(delta<0?'dx-neg':'dx-pos')+'">'+(delta>0?'+':'')+fmtN(delta)+'</td>':'<td>—</td>')+'</tr>';
      }).join('');
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th><th>SE</th><th>Min</th><th>Max</th><th>Δ from grand mean</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
    } else if(an.tab==='result'){
      var R=d.result||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Test</th><th>F</th><th>df1</th><th>df2</th><th>p</th><th>η²</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.outcome&&d.outcome.name||'')+'</td><td class="dx-interp">'+esc(R.test_used||'')+'</td>'
        +'<td>'+fmtN(R.F)+'</td><td>'+fmtN(R.df1,0)+'</td><td>'+fmtN(R.df2,0)+'</td>'
        +'<td>'+esc(R.p_str||'')+'</td><td>'+fmtN(R.eta_sq,3)+'</td>'
        +'<td class="dx-interp">'+ttStatus(R.significant)+'</td></tr></tbody></table></div>';
    } else if(an.tab==='effect'){
      var E=d.effect||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.outcome&&d.outcome.name||'')+'</td><td class="dx-interp">'+esc(E.type||'')+'</td>'
        +'<td>'+fmtN(E.value,3)+'</td><td class="dx-interp">'+esc(E.interpretation||'')+'</td><td class="dx-interp">'+esc(E.meaning||'')+'</td></tr></tbody></table></div>';
    } else {
      var L=d.reporting||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">'+esc(L.plain||'')+'</td></tr>'
        +'<tr><td class="dx-name">Researcher summary</td><td class="dx-interp">'+esc(L.researcher||'')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">'+esc(L.caution||'')+'</td></tr>'
        +'</tbody></table></div>';
    }
    var rpt=d.reporting||{};
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>'
      +'<div class="dx-layers">'
      +'<div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(rpt.plain||'')+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">'+esc(rpt.caution||'')+'</div></div>'
      +'</div>';
  }
  AS.anTab=function(tab){an.tab=tab;var out=document.getElementById('anResults');if(out&&an.result)out.innerHTML=renderAnovaResults(an.result,null);};

  // Chi-square state
  var csq={result:null,tab:'table',busy:false};

  // ---- Chi-square ----
  function renderChiSquare(host,ds,ctx){
    var cv=ds.variables.filter(function(v){var nm=nonMissing(v.values);var d=new Set(nm).size;return d>=2&&d<=20;});
    if(cv.length<2){
      host.innerHTML=header('Inferential Analysis','Chi-square','','chi_square')
        +'<div class="as-empty-tool">Chi-square needs at least two categorical variables (each with 2–20 distinct values).</div>'; return;
    }
    if(!sel.cs_a||!varByName(ds,sel.cs_a)) sel.cs_a=cv[0].name;
    if(!sel.cs_b||!varByName(ds,sel.cs_b)||sel.cs_b===sel.cs_a) sel.cs_b=cv[1]?cv[1].name:cv[0].name;
    var opt=function(cur){return cv.map(function(v){
      var cnt=new Set(nonMissing(v.values)).size;
      return '<option value="'+esc(v.name)+'"'+(v.name===cur?' selected':'')+'>'+esc(v.name)+' ('+cnt+' categories)</option>';
    }).join('');};
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the two category variables to compare</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Rows variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="csRow">'+opt(sel.cs_a)+'</select></div>'
      +'<div class="field"><label>Columns variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="csCol">'+opt(sel.cs_b)+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="csRun"'+(csq.busy?' disabled':'')+'>'+(csq.busy?'Running…':'▷ Run chi-square')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','Chi-square','Test whether two category variables are related.','chi_square')
      +setup+'<div id="csResults">'+(csq.result?renderChiSqResults(csq.result,ctx):'')+'</div>';
    var elA=host.querySelector('#csRow'),elB=host.querySelector('#csCol');
    if(elA) elA.addEventListener('change',function(e){sel.cs_a=e.target.value;});
    if(elB) elB.addEventListener('change',function(e){sel.cs_b=e.target.value;});
    var runBtn=host.querySelector('#csRun');
    if(runBtn) runBtn.addEventListener('click',function(){
      if(csq.busy||sel.cs_a===sel.cs_b) return;
      var va=varByName(ds,sel.cs_a),vb=varByName(ds,sel.cs_b); if(!va||!vb) return;
      var n=Math.min(va.values.length,vb.values.length),a=[],b=[];
      for(var i=0;i<n;i++){if(!isMissing(va.values[i])&&!isMissing(vb.values[i])){a.push(String(va.values[i]));b.push(String(vb.values[i]));}}
      csq.busy=true; csq.result=null;
      renderChiSquare(host,ds,ctx);
      fetch('/api/analysis/infer.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({tool:'chi_square',values:a,groups:b,row_name:sel.cs_a,col_name:sel.cs_b})})
        .then(function(r){return r.json();})
        .then(function(d){
          csq.busy=false; csq.result=d; csq.tab='table';
          var rb=host.querySelector('#csRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run chi-square';}
          var out=host.querySelector('#csResults');
          if(out) out.innerHTML=renderChiSqResults(d,ctx);
          if(d.ok&&ctx&&ctx.onResult) ctx.onResult();
        })
        .catch(function(){
          csq.busy=false;
          var rb=host.querySelector('#csRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run chi-square';}
          var out=host.querySelector('#csResults');
          if(out) out.innerHTML='<div class="as-empty-tool">Network error — please try again.</div>';
        });
    });
  }
  function renderChiSqResults(d,ctx){
    if(!d||!d.ok) return '<div class="as-empty-tool">'+esc(d&&d.error?d.error:'Error running chi-square.')+'</div>';
    var tabs=ttTabs([['table','Contingency'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],csq.tab,'chi_square');
    var body='';
    if(csq.tab==='table'){
      var ct=d.table||{}; var rkeys=ct.row_labels||[]; var ckeys=ct.col_labels||[];
      var th=ckeys.map(function(c){return '<th>'+esc(c)+' n</th><th>'+esc(c)+' %</th>';}).join('');
      var rows=(ct.matrix||[]).map(function(row){
        var cells=(row.cells||[]).map(function(c){return '<td>'+c.count+'</td><td>'+fmtN(c.row_pct,1)+'%</td>';}).join('');
        return '<tr><td class="dx-name">'+esc(row.label)+'</td>'+cells+'<td>'+row.total+'</td></tr>';
      }).join('');
      var tot=(ct.col_totals||[]).map(function(t){return '<td>'+t+'</td><td>'+fmtN(ct.grand?100*t/ct.grand:0,1)+'%</td>';}).join('');
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">'+esc(ct.row_var||'')+'</th>'+th+'<th>Total</th></tr></thead>'
        +'<tbody>'+rows+'<tr class="dx-total"><td class="dx-name">Total</td>'+tot+'<td>'+(ct.grand||0)+'</td></tr></tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">Percentages are within each row. Columns: '+esc(ct.col_var||'')+'.</div>';
    } else if(csq.tab==='result'){
      var R=d.result||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Test</th><th>χ²</th><th>df</th><th>N</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.row&&d.row.name||'')+' × '+esc(d.col&&d.col.name||'')+'</td><td class="dx-interp">'+esc(R.test_used||'')+'</td>'
        +'<td>'+fmtN(R.chi2)+'</td><td>'+fmtN(R.df,0)+'</td><td>'+(R.n_total||0)+'</td><td>'+esc(R.p_str||'')+'</td>'
        +'<td class="dx-interp">'+ttStatus(R.significant)+'</td></tr></tbody></table></div>';
    } else if(csq.tab==='effect'){
      var E=d.effect||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.row&&d.row.name||'')+' × '+esc(d.col&&d.col.name||'')+'</td><td class="dx-interp">'+esc(E.type||'')+'</td>'
        +'<td>'+fmtN(E.value,3)+'</td><td class="dx-interp">'+esc(E.interpretation||'')+'</td><td class="dx-interp">'+esc(E.meaning||'')+'</td></tr></tbody></table></div>';
    } else {
      var L=d.reporting||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">'+esc(L.plain||'')+'</td></tr>'
        +'<tr><td class="dx-name">Researcher summary</td><td class="dx-interp">'+esc(L.researcher||'')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">'+esc(L.caution||'')+'</td></tr>'
        +'</tbody></table></div>';
    }
    var rpt=d.reporting||{};
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>'
      +'<div class="dx-layers">'
      +'<div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(rpt.plain||'')+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">'+esc(rpt.caution||'')+'</div></div>'
      +'</div>';
  }
  AS.csqTab=function(tab){csq.tab=tab;var out=document.getElementById('csResults');if(out&&csq.result)out.innerHTML=renderChiSqResults(csq.result,null);};

  // Correlation state
  var cor={result:null,tab:'desc',busy:false,method:'pearson'};

  // ---- Correlation ----
  function renderCorrelation(host,ds,ctx){
    if(cor.method==='spearman') return renderSpearman(host,ds,ctx);
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Correlation','','correlation')
        +'<div class="as-empty-tool">Need at least two numeric variables.</div>'; return;
    }
    if(!sel.cor_x||!varByName(ds,sel.cor_x)) sel.cor_x=nv[0].name;
    if(!sel.cor_y||!varByName(ds,sel.cor_y)||sel.cor_y===sel.cor_x) sel.cor_y=nv[1]?nv[1].name:nv[0].name;
    var opt=function(cur){return nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===cur?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');};
    var cmseg=function(k,l){return '<button class="tt-seg'+(cor.method===k?' on':'')+'" data-as-cor-method="'+k+'">'+l+'</button>';};
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the two numeric variables to relate</div></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Method</label><div class="tt-segs">'+cmseg('pearson','Pearson r')+cmseg('spearman','Spearman rs')+'</div></div>'
      +'<div class="tt-grid">'
      +'<div class="field"><label>Variable 1 <span class="tt-hint">numeric</span></label><select class="ed-in" id="corX">'+opt(sel.cor_x)+'</select></div>'
      +'<div class="field"><label>Variable 2 <span class="tt-hint">numeric</span></label><select class="ed-in" id="corY">'+opt(sel.cor_y)+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="corRun"'+(cor.busy?' disabled':'')+'>'+(cor.busy?'Running…':'▷ Run correlation')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','Correlation','See whether two numbers move together, and how strongly.','correlation')
      +setup+'<div id="corResults">'+(cor.result?renderCorResults(cor.result,ctx):'')+'</div>';
    var elX=host.querySelector('#corX'),elY=host.querySelector('#corY');
    if(elX) elX.addEventListener('change',function(e){sel.cor_x=e.target.value;});
    if(elY) elY.addEventListener('change',function(e){sel.cor_y=e.target.value;});
    host.querySelectorAll('[data-as-cor-method]').forEach(function(btn){
      btn.addEventListener('click',function(){cor.method=btn.getAttribute('data-as-cor-method');cor.result=null;renderCorrelation(host,ds,ctx);});
    });
    var runBtn=host.querySelector('#corRun');
    if(runBtn) runBtn.addEventListener('click',function(){
      if(cor.busy||sel.cor_x===sel.cor_y) return;
      var vx=varByName(ds,sel.cor_x),vy=varByName(ds,sel.cor_y); if(!vx||!vy) return;
      var n=Math.min(vx.values.length,vy.values.length),xs=[],ys=[];
      for(var i=0;i<n;i++){var xi=num(vx.values[i]),yi=num(vy.values[i]);if(xi!==null&&yi!==null){xs.push(xi);ys.push(yi);}}
      cor.busy=true; cor.result=null;
      renderCorrelation(host,ds,ctx);
      fetch('/api/analysis/infer.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({tool:'correlation',x:xs,y:ys,x_name:sel.cor_x,y_name:sel.cor_y})})
        .then(function(r){return r.json();})
        .then(function(d){
          cor.busy=false; cor.result=d; cor.tab='desc';
          var rb=host.querySelector('#corRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run correlation';}
          var out=host.querySelector('#corResults');
          if(out) out.innerHTML=renderCorResults(d,ctx);
          if(d.ok&&ctx&&ctx.onResult) ctx.onResult();
        })
        .catch(function(){
          cor.busy=false;
          var rb=host.querySelector('#corRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run correlation';}
          var out=host.querySelector('#corResults');
          if(out) out.innerHTML='<div class="as-empty-tool">Network error — please try again.</div>';
        });
    });
  }
  function renderCorResults(d,ctx){
    if(!d||!d.ok) return '<div class="as-empty-tool">'+esc(d&&d.error?d.error:'Error running correlation.')+'</div>';
    var tabs=ttTabs([['desc','Descriptives'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],cor.tab,'correlation');
    var body='';
    if(cor.tab==='desc'){
      var rows=(d.descriptives||[]).map(function(v){
        return '<tr><td class="dx-name">'+esc(v.name)+'</td><td>'+v.n+'</td><td>'+fmtN(v.mean)+'</td><td>'+fmtN(v.sd)+'</td><td>'+fmtN(v.min)+'</td><td>'+fmtN(v.max)+'</td></tr>';
      }).join('');
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Min</th><th>Max</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
    } else if(cor.tab==='result'){
      var R=d.result||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Test</th><th>r</th><th>r²</th><th>df</th><th>p</th><th class="l">Direction</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.x&&d.x.name||'')+' &amp; '+esc(d.y&&d.y.name||'')+'</td><td class="dx-interp">'+esc(R.test_used||'')+'</td>'
        +'<td>'+fmtN(R.r,3)+'</td><td>'+fmtN(R.r2,3)+'</td><td>'+fmtN(R.df,0)+'</td><td>'+esc(R.p_str||'')+'</td>'
        +'<td class="dx-interp">'+esc(R.direction||'')+'</td><td class="dx-interp">'+ttStatus(R.significant)+'</td></tr></tbody></table></div>';
    } else if(cor.tab==='effect'){
      var E=d.effect||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.x&&d.x.name||'')+' &amp; '+esc(d.y&&d.y.name||'')+'</td><td class="dx-interp">'+esc(E.type||'')+'</td>'
        +'<td>'+fmtN(E.value,3)+'</td><td class="dx-interp">'+esc(E.interpretation||'')+'</td><td class="dx-interp">'+esc(E.meaning||'')+'</td></tr></tbody></table></div>';
    } else {
      var L=d.reporting||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">'+esc(L.plain||'')+'</td></tr>'
        +'<tr><td class="dx-name">Researcher summary</td><td class="dx-interp">'+esc(L.researcher||'')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">'+esc(L.caution||'')+'</td></tr>'
        +'</tbody></table></div>';
    }
    var rpt=d.reporting||{};
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>'
      +'<div class="dx-layers">'
      +'<div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(rpt.plain||'')+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">'+esc(rpt.caution||'')+'</div></div>'
      +'</div>';
  }
  AS.corTab=function(tab){cor.tab=tab;var out=document.getElementById('corResults');if(out&&cor.result)out.innerHTML=renderCorResults(cor.result,null);};

  // Regression state
  var reg={preds:{},result:null,tab:'coef',busy:false};

  // ---- Regression ----
  function renderRegression(host,ds,ctx){
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Regression','','regression')
        +'<div class="as-empty-tool">Regression needs a numeric outcome and at least one numeric predictor.</div>'; return;
    }
    if(!sel.reg_y||!varByName(ds,sel.reg_y)) sel.reg_y=nv[0].name;
    var avail=nv.filter(function(v){return v.name!==sel.reg_y;});
    if(!Object.keys(reg.preds).some(function(k){return reg.preds[k]&&avail.find(function(v){return v.name===k;});})){
      if(avail[0]) reg.preds[avail[0].name]=true;
    }
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.reg_y?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var chks=avail.map(function(v){
      return '<label class="rg-chk"><input type="checkbox"'+(reg.preds[v.name]?' checked':'')+' data-as-pred="'+esc(v.name)+'"> '+esc(v.name)+'</label>';
    }).join('');
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome, then check one or more predictors</div></div></div>'
      +'<div class="panel-b">'
      +'<div class="field" style="max-width:340px"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="regOut">'+outOpts+'</select></div>'
      +'<div class="field" style="margin-top:12px"><label>Predictors <span class="tt-hint">numeric</span></label><div class="rg-preds">'+(chks||'<span class="tt-hint">No other numeric variables available.</span>')+'</div></div>'
      +'<div class="run-actions"><button class="btn primary" id="regRun"'+(reg.busy?' disabled':'')+'>'+(reg.busy?'Running…':'▷ Run regression')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','Regression','Predict a numeric outcome from one or more numeric predictors.','regression')
      +setup+'<div id="regResults">'+(reg.result?renderRegResults(reg.result,ctx):'')+'</div>';
    var elOut=host.querySelector('#regOut');
    if(elOut) elOut.addEventListener('change',function(e){sel.reg_y=e.target.value;reg.preds={};renderRegression(host,ds,ctx);});
    host.querySelectorAll('[data-as-pred]').forEach(function(cb){
      cb.addEventListener('change',function(){
        var nm=cb.getAttribute('data-as-pred');
        if(cb.checked) reg.preds[nm]=true; else delete reg.preds[nm];
      });
    });
    var runBtn=host.querySelector('#regRun');
    if(runBtn) runBtn.addEventListener('click',function(){
      if(reg.busy) return;
      var vy=varByName(ds,sel.reg_y); if(!vy) return;
      var predNames=Object.keys(reg.preds).filter(function(k){return reg.preds[k]&&varByName(ds,k)&&k!==sel.reg_y;});
      if(!predNames.length) return;
      var n=vy.values.length;
      predNames.forEach(function(nm){n=Math.min(n,varByName(ds,nm).values.length);});
      var ys=[],xList=predNames.map(function(){return [];});
      for(var i=0;i<n;i++){
        var yi=num(vy.values[i]); if(yi===null) continue;
        var ok=true; var xrow=[];
        for(var j=0;j<predNames.length;j++){var xi=num(varByName(ds,predNames[j]).values[i]);if(xi===null){ok=false;break;}xrow.push(xi);}
        if(ok){ys.push(yi);xrow.forEach(function(x,j){xList[j].push(x);});}
      }
      reg.busy=true; reg.result=null;
      renderRegression(host,ds,ctx);
      fetch('/api/analysis/infer.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({tool:'regression',y:ys,x_list:xList,y_name:sel.reg_y,x_names:predNames})})
        .then(function(r){return r.json();})
        .then(function(d){
          reg.busy=false; reg.result=d; reg.tab='coef';
          var rb=host.querySelector('#regRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run regression';}
          var out=host.querySelector('#regResults');
          if(out) out.innerHTML=renderRegResults(d,ctx);
          if(d.ok&&ctx&&ctx.onResult) ctx.onResult();
        })
        .catch(function(){
          reg.busy=false;
          var rb=host.querySelector('#regRun'); if(rb){rb.disabled=false; rb.innerHTML='▷ Run regression';}
          var out=host.querySelector('#regResults');
          if(out) out.innerHTML='<div class="as-empty-tool">Network error — please try again.</div>';
        });
    });
  }
  function renderRegResults(d,ctx){
    if(!d||!d.ok) return '<div class="as-empty-tool">'+esc(d&&d.error?d.error:'Error running regression.')+'</div>';
    var tabs=ttTabs([['coef','Coefficients'],['fit','Model fit'],['effect','Effect size'],['report','Reporting language']],reg.tab,'regression');
    var body='';
    if(reg.tab==='coef'){
      var rows=(d.coefficients||[]).map(function(c){
        return '<tr><td class="dx-name">'+esc(c.term)+'</td><td>'+fmtN(c.b,3)+'</td><td>'+fmtN(c.se,3)+'</td>'
          +'<td>'+fmtN(c.t,3)+'</td><td>'+esc(c.p_str||'')+'</td>'
          +'<td class="dx-interp">'+(c.is_intercept?'—':'<span class="tt-status '+(c.sig?'ok':'rev')+'">'+(c.sig?'Significant':'n.s.')+'</span>')+'</td></tr>';
      }).join('');
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Term</th><th>b</th><th>SE</th><th>t</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
    } else if(reg.tab==='fit'){
      var R=d.result||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>R²</th><th>Adj. R²</th><th>F</th><th>df1</th><th>df2</th><th>N</th><th>p</th><th class="l">Model</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.outcome&&d.outcome.name||'')+'</td><td>'+fmtN(R.r2,3)+'</td><td>'+fmtN(R.adj_r2,3)+'</td>'
        +'<td>'+fmtN(R.F,3)+'</td><td>'+fmtN(R.df1,0)+'</td><td>'+fmtN(R.df2,0)+'</td><td>'+(R.n_total||0)+'</td><td>'+esc(R.p_str||'')+'</td>'
        +'<td class="dx-interp">'+ttStatus(R.significant)+'</td></tr></tbody></table></div>';
    } else if(reg.tab==='effect'){
      var E=d.effect||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th><th class="l">Practical meaning</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.outcome&&d.outcome.name||'')+'</td><td class="dx-interp">'+esc(E.type||'')+'</td>'
        +'<td>'+fmtN(E.value,3)+'</td><td class="dx-interp">'+esc(E.interpretation||'')+'</td><td class="dx-interp">'+esc(E.meaning||'')+'</td></tr></tbody></table></div>';
    } else {
      var L=d.reporting||{};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language summary</td><td class="dx-interp">'+esc(L.plain||'')+'</td></tr>'
        +'<tr><td class="dx-name">Researcher summary</td><td class="dx-interp">'+esc(L.researcher||'')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">'+esc(L.caution||'')+'</td></tr>'
        +'</tbody></table></div>';
    }
    var rpt=d.reporting||{};
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>'
      +'<div class="dx-layers">'
      +'<div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(rpt.plain||'')+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">'+esc(rpt.caution||'')+'</div></div>'
      +'</div>';
  }
  AS.regTab=function(tab){reg.tab=tab;var out=document.getElementById('regResults');if(out&&reg.result)out.innerHTML=renderRegResults(reg.result,null);};

  // ---- Paired t-test ----
  var ptd={result:null,tab:'desc',method:'parametric'};
  function renderPairedT(host,ds,ctx){
    var nv=numericVars(ds);
    var pseg=function(k,l){return '<button class="tt-seg'+(ptd.method===k?' on':'')+'" data-as-pt-method="'+k+'">'+l+'</button>';};
    var methodBar='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the two paired numeric variables (same respondent, two time points or conditions)</div></div></div>'
      +'<div class="panel-b"><div class="field"><label>Method</label><div class="tt-segs">'+pseg('parametric','Paired t-test')+pseg('wilcoxon','Wilcoxon signed-rank')+'</div></div>';
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Paired t-test','','paired_t')
        +methodBar+'<div class="as-empty-tool" style="margin-top:12px">Need at least two numeric variables.</div></div></div>';
      host.querySelectorAll('[data-as-pt-method]').forEach(function(btn){
        btn.addEventListener('click',function(){ptd.method=btn.getAttribute('data-as-pt-method');ptd.result=null;renderPairedT(host,ds,ctx);});
      });
      return;
    }
    if(!sel.pt_a||!varByName(ds,sel.pt_a)) sel.pt_a=nv[0].name;
    if(!sel.pt_b||!varByName(ds,sel.pt_b)||sel.pt_b===sel.pt_a) sel.pt_b=nv[1]?nv[1].name:nv[0].name;
    var opt=function(cur){return nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===cur?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');};
    var title=ptd.method==='wilcoxon'?'Wilcoxon Signed-Rank':'Paired t-test';
    var lede=ptd.method==='wilcoxon'?'Non-parametric paired test: ranks differences instead of assuming normality.':'Tests whether the mean of within-respondent differences is zero.';
    host.innerHTML=header('Inferential Analysis',title,lede,'paired_t')
      +methodBar
      +'<div class="tt-grid">'
      +'<div class="field"><label>Variable A <span class="tt-hint">numeric, paired</span></label><select class="ed-in" id="ptA">'+opt(sel.pt_a)+'</select></div>'
      +'<div class="field"><label>Variable B <span class="tt-hint">numeric, paired</span></label><select class="ed-in" id="ptB">'+opt(sel.pt_b)+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="ptRun">&#9655; Run '+esc(title)+'</button></div>'
      +'</div></div>'
      +'<div id="ptResults">'+(ptd.result?renderPtResults(ptd.result):'')+'</div>';
    host.querySelectorAll('[data-as-pt-method]').forEach(function(btn){
      btn.addEventListener('click',function(){ptd.method=btn.getAttribute('data-as-pt-method');ptd.result=null;renderPairedT(host,ds,ctx);});
    });
    host.querySelector('#ptA').addEventListener('change',function(e){sel.pt_a=e.target.value;});
    host.querySelector('#ptB').addEventListener('change',function(e){sel.pt_b=e.target.value;});
    host.querySelector('#ptRun').addEventListener('click',function(){
      var va=varByName(ds,sel.pt_a),vb=varByName(ds,sel.pt_b); if(!va||!vb||va.name===vb.name) return;
      var diffs=[];
      for(var i=0;i<Math.min(va.values.length,vb.values.length);i++){
        var a=num(va.values[i]),b=num(vb.values[i]); if(a===null||b===null) continue;
        diffs.push(a-b);
      }
      if(ptd.method==='wilcoxon'){
        var nonzero=diffs.filter(function(d){return d!==0;}),n=nonzero.length;
        if(n<4){host.querySelector('#ptResults').innerHTML='<div class="as-empty-tool">Need at least 4 non-tied pairs.</div>';return;}
        var absDiffs=nonzero.map(function(d,i){return{d:d,abs:Math.abs(d),i:i};});
        absDiffs.sort(function(a,b){return a.abs-b.abs;});
        var ranks=rankWithTies(absDiffs.map(function(x){return x.abs;}));
        var Wplus=0,Wminus=0;
        absDiffs.forEach(function(item,i){if(item.d>0)Wplus+=ranks[i];else Wminus+=ranks[i];});
        var W=Math.min(Wplus,Wminus),meanW=n*(n+1)/4,sigmaW=Math.sqrt(n*(n+1)*(2*n+1)/24);
        var Z=(W-meanW)/sigmaW,p=2*(1-normalCDF(Math.abs(Z))),r=Math.abs(Z)/Math.sqrt(n);
        ptd.result={ok:true,method:'wilcoxon',W:W,Wplus:Wplus,Wminus:Wminus,Z:Z,p:p,r:r,n:n,nTotal:diffs.length,nZero:diffs.length-n,vA:sel.pt_a,vB:sel.pt_b};
      } else {
        if(diffs.length<2){host.querySelector('#ptResults').innerHTML='<div class="as-empty-tool">Need at least 2 complete pairs.</div>';return;}
        var n2=diffs.length,md=mean(diffs),sdd=sd(diffs);
        var t=md/(sdd/Math.sqrt(n2)||1e-12),df=n2-1;
        var xv=df/(df+t*t);
        function bc2(x2,a,b){var F=1e-300,q=a+b,qa=a+1,qm=a-1,c=1,d=1-q*x2/qa;if(Math.abs(d)<F)d=F;d=1/d;var h=d;for(var m=1;m<=200;m++){var m2=2*m;var aa=m*(b-m)*x2/((qm+m2)*(a+m2));d=1+aa*d;if(Math.abs(d)<F)d=F;c=1+aa/c;if(Math.abs(c)<F)c=F;d=1/d;h*=d*c;aa=-(a+m)*(q+m)*x2/((a+m2)*(qa+m2));d=1+aa*d;if(Math.abs(d)<F)d=F;c=1+aa/c;if(Math.abs(c)<F)c=F;d=1/d;var del=d*c;h*=del;if(Math.abs(del-1)<1e-12)break;}return h;}
        function bi2(x2,a,b){if(x2<=0)return 0;if(x2>=1)return 1;var bt=Math.exp(logGammaNP(a+b)-logGammaNP(a)-logGammaNP(b)+a*Math.log(x2)+b*Math.log(1-x2));if(x2<(a+1)/(a+b+2))return bt*bc2(x2,a,b)/a;return 1-bt*bc2(1-x2,b,a)/b;}
        var p2=bi2(xv,df/2,0.5);
        var tCrit=(function(alpha,df2){var lo=0,hi=50;for(var i=0;i<60;i++){var mid=(lo+hi)/2;var pp=bi2(df2/(df2+mid*mid),df2/2,0.5);if(pp<alpha)hi=mid;else lo=mid;}return(lo+hi)/2;})(0.05,df);
        var seMd=sdd/Math.sqrt(n2),ciLo=md-tCrit*seMd,ciHi=md+tCrit*seMd,dz=md/(sdd||1e-12);
        ptd.result={ok:true,method:'parametric',t:t,df:df,p:p2,md:md,sdd:sdd,seMd:seMd,ciLo:ciLo,ciHi:ciHi,dz:dz,n:n2,vA:sel.pt_a,vB:sel.pt_b};
      }
      ptd.tab='desc';
      host.querySelector('#ptResults').innerHTML=renderPtResults(ptd.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderPtResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var sig=d.p<0.05;
    var body='';
    if(d.method==='wilcoxon'){
      var tabs=ttTabs([['desc','Summary'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],ptd.tab,'paired_t');
      if(ptd.tab==='desc'){
        body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Stat</th><th>Value</th></tr></thead><tbody>'
          +'<tr><td class="dx-name">Total pairs</td><td>'+d.nTotal+'</td></tr>'
          +'<tr><td class="dx-name">Tied pairs (excluded)</td><td>'+d.nZero+'</td></tr>'
          +'<tr><td class="dx-name">Pairs used (n)</td><td>'+d.n+'</td></tr>'
          +'<tr><td class="dx-name">Positive rank sum (W+)</td><td>'+fmtN(d.Wplus,1)+'</td></tr>'
          +'<tr><td class="dx-name">Negative rank sum (W-)</td><td>'+fmtN(d.Wminus,1)+'</td></tr>'
          +'<tr><td class="dx-name">Test statistic (W)</td><td>'+fmtN(d.W,1)+'</td></tr>'
          +'</tbody></table></div>';
      } else if(ptd.tab==='result'){
        body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th>W</th><th>Z</th><th>n</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
          +'<tr><td class="dx-name">'+esc(d.vA)+' vs '+esc(d.vB)+'</td><td>'+fmtN(d.W,1)+'</td><td>'+fmtN(d.Z,2)+'</td><td>'+d.n+'</td>'
          +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr>'
          +'</tbody></table></div>';
      } else if(ptd.tab==='effect'){
        var b=effBand('r',d.r);
        body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
          +'<tr><td class="dx-name">'+esc(d.vA)+' vs '+esc(d.vB)+'</td><td class="dx-interp">r = Z / √n</td><td>'+fmtN(d.r,3)+'</td><td class="dx-interp">'+b+'</td></tr>'
          +'</tbody></table></div>';
      } else {
        var plain=sig?'A Wilcoxon signed-rank test showed a significant within-pair difference (W = '+fmtN(d.W,0)+', z = '+fmtN(d.Z,2)+', p '+fmtP(d.p)+', r = '+fmtN(d.r,3)+').':'No significant within-pair difference (W = '+fmtN(d.W,0)+', p '+fmtP(d.p)+').';
        body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
          +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
          +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Report medians for both variables alongside the test statistic. The test assumes symmetric differences, not just any distribution.</td></tr>'
          +'</tbody></table></div>';
      }
      return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
    }
    // parametric
    var tabs=ttTabs([['desc','Descriptives'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],ptd.tab,'paired_t');
    var dzBand=(function(x){x=Math.abs(x);if(x<0.2)return'negligible';if(x<0.5)return'small';if(x<0.8)return'medium';return'large';})(d.dz);
    if(ptd.tab==='desc'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Stat</th><th>Value</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">n (pairs)</td><td>'+d.n+'</td></tr>'
        +'<tr><td class="dx-name">Mean difference (A - B)</td><td>'+fmtN(d.md)+'</td></tr>'
        +'<tr><td class="dx-name">SD of differences</td><td>'+fmtN(d.sdd)+'</td></tr>'
        +'<tr><td class="dx-name">SE</td><td>'+fmtN(d.seMd)+'</td></tr>'
        +'<tr><td class="dx-name">95% CI of difference</td><td>['+fmtN(d.ciLo)+', '+fmtN(d.ciHi)+']</td></tr>'
        +'</tbody></table></div>';
    } else if(ptd.tab==='result'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th>t</th><th>df</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.vA)+' vs '+esc(d.vB)+'</td><td>'+fmtN(d.t,2)+'</td><td>'+d.df+'</td>'
        +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr>'
        +'</tbody></table></div>';
    } else if(ptd.tab==='effect'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.vA)+' vs '+esc(d.vB)+'</td><td class="dx-interp">Cohen\'s d_z</td><td>'+fmtN(d.dz,3)+'</td><td class="dx-interp">'+dzBand+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">d_z < .2 negligible · .2 small · .5 medium · .8 large.</div>';
    } else {
      var dir=d.md>0?d.vA+' is higher':d.vB+' is higher';
      var plain2=sig?'The mean within-pair difference is significant (t('+d.df+') = '+fmtN(d.t,2)+', p '+fmtP(d.p)+'). '+dir+' by '+fmtN(Math.abs(d.md))+' on average (d_z = '+fmtN(d.dz,2)+', '+dzBand+' effect).':'No significant within-pair difference (t('+d.df+') = '+fmtN(d.t,2)+', p '+fmtP(d.p)+').';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain2)+'</td></tr>'
        +'<tr><td class="dx-name">Researcher</td><td class="dx-interp">'+esc('t('+d.df+') = '+fmtN(d.t,2)+', p '+fmtP(d.p)+' (two-tailed), d_z = '+fmtN(d.dz,3)+', 95% CI ['+fmtN(d.ciLo)+', '+fmtN(d.ciHi)+'], n = '+d.n+'.')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Report the mean difference with its CI, not just significance. Check normality of differences if n is small (&lt; 30).</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.ptdTab=function(tab){ptd.tab=tab;var out=document.getElementById('ptResults');if(out&&ptd.result)out.innerHTML=renderPtResults(ptd.result);};

  // ---- Mann-Whitney U ----
  var mwu={result:null,tab:'ranks'};
  function renderMannWhitney(host,ds,ctx){
    var nv=numericVars(ds);
    var gv=ds.variables.filter(function(v){var nm=nonMissing(v.values);var d=new Set(nm).size;return d>=2&&d<=20;});
    var mseg=function(k,l){return '<button class="tt-seg'+(tt.method===k?' on':'')+'" data-as-tt-method="'+k+'">'+l+'</button>';};
    var methodBar='<div class="panel"><div class="panel-h"><div><h3>Setup</h3></div></div><div class="panel-b"><div class="field"><label>Method</label><div class="tt-segs">'+mseg('parametric','Welch t-test')+mseg('mann_whitney','Mann-Whitney U')+mseg('wilcoxon','Wilcoxon (paired)')+'</div></div>';
    if(!nv.length||!gv.length){
      host.innerHTML=header('Inferential Analysis','Mann-Whitney U','Non-parametric test: compares two groups without assuming normality.','t_test')
        +methodBar+'<div class="as-empty-tool" style="margin-top:12px">Need at least one numeric outcome and one grouping variable (2+ distinct values).</div></div></div>';
      host.querySelectorAll('[data-as-tt-method]').forEach(function(btn){
        btn.addEventListener('click',function(){tt.method=btn.getAttribute('data-as-tt-method');mwu.result=null;renderTTest(host,ds,ctx);});
      });
      return;
    }
    if(!sel.tt_out||!varByName(ds,sel.tt_out)) sel.tt_out=nv[0].name;
    if(!tt.grp||!varByName(ds,tt.grp)) tt.grp=gv[0].name;
    var gvObj=varByName(ds,tt.grp);
    var glevels=gvObj?Array.from(new Set(nonMissing(gvObj.values))).sort():[];
    var gCounts={};
    if(gvObj) gvObj.values.forEach(function(v){if(!isMissing(v))gCounts[v]=(gCounts[v]||0)+1;});
    if(!tt.g1||glevels.indexOf(tt.g1)<0) tt.g1=glevels[0]||'';
    if(!tt.g2||glevels.indexOf(tt.g2)<0||tt.g2===tt.g1) tt.g2=glevels[1]||'';
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.tt_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts=gv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===tt.grp?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var g1Opts=glevels.map(function(g){return '<option value="'+esc(g)+'"'+(g===tt.g1?' selected':'')+'>'+esc(g)+' (n='+(gCounts[g]||0)+')</option>';}).join('');
    var g2Opts=glevels.map(function(g){return '<option value="'+esc(g)+'"'+(g===tt.g2?' selected':'')+'>'+esc(g)+' (n='+(gCounts[g]||0)+')</option>';}).join('');
    host.innerHTML=header('Inferential Analysis','Mann-Whitney U','Non-parametric test: compares two groups without assuming normality.','t_test')
      +methodBar
      +'<div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="mwuOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="mwuGrp">'+grpOpts+'</select></div>'
      +'<div class="field"><label>Group 1</label><select class="ed-in" id="mwuG1">'+g1Opts+'</select></div>'
      +'<div class="field"><label>Group 2</label><select class="ed-in" id="mwuG2">'+g2Opts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="mwuRun">&#9655; Run Mann-Whitney U</button></div>'
      +'</div></div>'
      +'<div id="mwuResults">'+(mwu.result?renderMwuResults(mwu.result):'')+'</div>';
    host.querySelectorAll('[data-as-tt-method]').forEach(function(btn){
      btn.addEventListener('click',function(){tt.method=btn.getAttribute('data-as-tt-method');mwu.result=null;renderTTest(host,ds,ctx);});
    });
    host.querySelector('#mwuOut').addEventListener('change',function(e){sel.tt_out=e.target.value;});
    host.querySelector('#mwuGrp').addEventListener('change',function(e){tt.grp=e.target.value;tt.g1='';tt.g2='';mwu.result=null;renderMannWhitney(host,ds,ctx);});
    host.querySelector('#mwuG1').addEventListener('change',function(e){tt.g1=e.target.value;});
    host.querySelector('#mwuG2').addEventListener('change',function(e){tt.g2=e.target.value;});
    host.querySelector('#mwuRun').addEventListener('click',function(){
      var ov=varByName(ds,sel.tt_out),gvObj2=varByName(ds,tt.grp); if(!ov||!gvObj2||tt.g1===tt.g2) return;
      var g1=[],g2=[];
      for(var i=0;i<Math.min(ov.values.length,gvObj2.values.length);i++){
        var v=num(ov.values[i]),g=gvObj2.values[i]; if(v===null||isMissing(g)) continue;
        if(String(g)===tt.g1) g1.push(v); else if(String(g)===tt.g2) g2.push(v);
      }
      if(g1.length<2||g2.length<2){host.querySelector('#mwuResults').innerHTML='<div class="as-empty-tool">Need at least 2 observations per group.</div>';return;}
      var all=g1.map(function(v){return{v:v,g:0};}).concat(g2.map(function(v){return{v:v,g:1};}));
      var vals=all.map(function(x){return x.v;});
      var ranks=rankWithTies(vals);
      var R1=0,R2=0;
      all.forEach(function(item,i){if(item.g===0)R1+=ranks[i];else R2+=ranks[i];});
      var n1=g1.length,n2=g2.length,N=n1+n2;
      var U1=R1-n1*(n1+1)/2,U2=n1*n2-U1,U=Math.min(U1,U2);
      var meanU=n1*n2/2,sigmaU=Math.sqrt(n1*n2*(N+1)/12);
      var Z=(U-meanU)/sigmaU,p=2*(1-normalCDF(Math.abs(Z)));
      var r_rb=(U1-U2)/(n1*n2);
      var m1=mean(g1),m2=mean(g2),med1=median(g1),med2=median(g2);
      mwu.result={ok:true,g1name:tt.g1,g2name:tt.g2,n1:n1,n2:n2,N:N,U:U,U1:U1,U2:U2,R1:R1,R2:R2,Z:Z,p:p,r_rb:r_rb,
        m1:m1,m2:m2,med1:med1,med2:med2,meanR1:R1/n1,meanR2:R2/n2,out:sel.tt_out,grp:tt.grp};
      mwu.tab='ranks';
      host.querySelector('#mwuResults').innerHTML=renderMwuResults(mwu.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderMwuResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['ranks','Group ranks'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],'ranks','mann_whitney');
    var body='';
    if(mwu.tab==='ranks'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>Median</th><th>Mean rank</th><th>Rank sum</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.g1name)+'</td><td>'+d.n1+'</td><td>'+fmtN(d.m1)+'</td><td>'+fmtN(d.med1)+'</td><td>'+fmtN(d.meanR1)+'</td><td>'+fmtN(d.R1)+'</td></tr>'
        +'<tr><td class="dx-name">'+esc(d.g2name)+'</td><td>'+d.n2+'</td><td>'+fmtN(d.m2)+'</td><td>'+fmtN(d.med2)+'</td><td>'+fmtN(d.meanR2)+'</td><td>'+fmtN(d.R2)+'</td></tr>'
        +'</tbody></table></div>';
    } else if(mwu.tab==='result'){
      var sig=d.p<0.05;
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>U</th><th>Z</th><th>N</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.out)+'</td><td>'+fmtN(d.U,1)+'</td><td>'+fmtN(d.Z,2)+'</td><td>'+d.N+'</td>'
        +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">Normal approximation (z) used for p-value. U₁&nbsp;=&nbsp;'+fmtN(d.U1,0)+', U₂&nbsp;=&nbsp;'+fmtN(d.U2,0)+'.</div>';
    } else if(mwu.tab==='effect'){
      var b=effBand('r_rb',d.r_rb);
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.out)+'</td><td class="dx-interp">Rank-biserial r</td><td>'+fmtN(d.r_rb,3)+'</td><td class="dx-interp">'+b+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">|r| < .1 negligible · .1 small · .3 medium · .5 large. Sign shows direction (positive: '+esc(d.g1name)+' has higher ranks).</div>';
    } else {
      var sig2=d.p<0.05;
      var plain=sig2
        ? 'Ranks for '+d.out+' differ significantly between '+d.g1name+' and '+d.g2name+' (U = '+fmtN(d.U,0)+', Z = '+fmtN(d.Z,2)+', p '+fmtP(d.p)+'). The group with higher mean rank ('+( d.meanR1>d.meanR2?d.g1name:d.g2name)+') tends to score higher.'
        : 'Ranks for '+d.out+' do not differ significantly between groups (U = '+fmtN(d.U,0)+', p '+fmtP(d.p)+').';
      var researcher='Mann-Whitney U ('+fmtN(d.U,0)+') = '+fmtN(d.U,0)+', z = '+fmtN(d.Z,2)+', p '+fmtP(d.p)+', rank-biserial r = '+fmtN(d.r_rb,3)+'. (n₁ = '+d.n1+', n₂ = '+d.n2+')';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Researcher</td><td class="dx-interp">'+esc(researcher)+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Mann-Whitney tests whether one group\'s values tend to be ranked higher, not whether means are equal. Report medians alongside the test statistic.</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.mwuTab=function(tab){mwu.tab=tab;var out=document.getElementById('mwuResults');if(out&&mwu.result)out.innerHTML=renderMwuResults(mwu.result);};

  // ---- Wilcoxon Signed-Rank ----
  var wsrt={result:null,tab:'diffs'};
  function renderWilcoxon(host,ds,ctx){
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Wilcoxon Signed-Rank','','t_test')
        +'<div class="as-empty-tool">Need at least two numeric variables for a paired test.</div>'; return;
    }
    if(!sel.tt_out||!varByName(ds,sel.tt_out)) sel.tt_out=nv[0].name;
    if(!sel.wsr_b||!varByName(ds,sel.wsr_b)||sel.wsr_b===sel.tt_out) sel.wsr_b=nv[1]?nv[1].name:nv[0].name;
    var opt=function(cur){return nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===cur?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');};
    var mseg=function(k,l){return '<button class="tt-seg'+(tt.method===k?' on':'')+'" data-as-tt-method="'+k+'">'+l+'</button>';};
    host.innerHTML=header('Inferential Analysis','Wilcoxon Signed-Rank','Non-parametric paired test: compares two measurements on the same respondents.','t_test')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Method</label><div class="tt-segs">'+mseg('parametric','Welch t-test')+mseg('mann_whitney','Mann-Whitney U')+mseg('wilcoxon','Wilcoxon (paired)')+'</div></div>'
      +'<div class="tt-grid">'
      +'<div class="field"><label>Variable A <span class="tt-hint">numeric, paired</span></label><select class="ed-in" id="wsrA">'+opt(sel.tt_out)+'</select></div>'
      +'<div class="field"><label>Variable B <span class="tt-hint">numeric, paired</span></label><select class="ed-in" id="wsrB">'+opt(sel.wsr_b)+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="wsrRun">▷ Run Wilcoxon</button></div>'
      +'</div></div>'
      +'<div id="wsrtResults">'+(wsrt.result?renderWsrtResults(wsrt.result):'')+'</div>';
    host.querySelectorAll('[data-as-tt-method]').forEach(function(btn){
      btn.addEventListener('click',function(){tt.method=btn.getAttribute('data-as-tt-method');wsrt.result=null;renderTTest(host,ds,ctx);});
    });
    host.querySelector('#wsrA').addEventListener('change',function(e){sel.tt_out=e.target.value;});
    host.querySelector('#wsrB').addEventListener('change',function(e){sel.wsr_b=e.target.value;});
    host.querySelector('#wsrRun').addEventListener('click',function(){
      var va=varByName(ds,sel.tt_out),vb=varByName(ds,sel.wsr_b); if(!va||!vb||va.name===vb.name) return;
      var diffs=[];
      for(var i=0;i<Math.min(va.values.length,vb.values.length);i++){
        var a=num(va.values[i]),b=num(vb.values[i]); if(a===null||b===null) continue;
        diffs.push(a-b);
      }
      var nonzero=diffs.filter(function(d){return d!==0;});
      var n=nonzero.length;
      if(n<4){host.querySelector('#wsrtResults').innerHTML='<div class="as-empty-tool">Need at least 4 non-tied pairs.</div>';return;}
      var absDiffs=nonzero.map(function(d,i){return{d:d,abs:Math.abs(d),i:i};});
      absDiffs.sort(function(a,b){return a.abs-b.abs;});
      var absVals=absDiffs.map(function(x){return x.abs;});
      var ranks=rankWithTies(absVals);
      var Wplus=0,Wminus=0;
      absDiffs.forEach(function(item,i){if(item.d>0)Wplus+=ranks[i];else Wminus+=ranks[i];});
      var W=Math.min(Wplus,Wminus);
      var meanW=n*(n+1)/4,sigmaW=Math.sqrt(n*(n+1)*(2*n+1)/24);
      var Z=(W-meanW)/sigmaW,p=2*(1-normalCDF(Math.abs(Z)));
      var r=Math.abs(Z)/Math.sqrt(n);
      var nTotal=diffs.length,nZero=nTotal-n;
      wsrt.result={ok:true,W:W,Wplus:Wplus,Wminus:Wminus,Z:Z,p:p,r:r,n:n,nTotal:nTotal,nZero:nZero,vA:sel.tt_out,vB:sel.wsr_b};
      wsrt.tab='diffs';
      host.querySelector('#wsrtResults').innerHTML=renderWsrtResults(wsrt.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderWsrtResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['diffs','Summary'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],'diffs','wilcoxon');
    var body='';
    if(wsrt.tab==='diffs'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Stat</th><th>Value</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Total pairs</td><td>'+d.nTotal+'</td></tr>'
        +'<tr><td class="dx-name">Pairs with ties (excluded)</td><td>'+d.nZero+'</td></tr>'
        +'<tr><td class="dx-name">Pairs used (n)</td><td>'+d.n+'</td></tr>'
        +'<tr><td class="dx-name">Positive rank sum (W+)</td><td>'+fmtN(d.Wplus,1)+'</td></tr>'
        +'<tr><td class="dx-name">Negative rank sum (W-)</td><td>'+fmtN(d.Wminus,1)+'</td></tr>'
        +'<tr><td class="dx-name">Test statistic (W)</td><td>'+fmtN(d.W,1)+'</td></tr>'
        +'</tbody></table></div>';
    } else if(wsrt.tab==='result'){
      var sig=d.p<0.05;
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th>W</th><th>Z</th><th>n</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.vA)+' vs '+esc(d.vB)+'</td><td>'+fmtN(d.W,1)+'</td><td>'+fmtN(d.Z,2)+'</td><td>'+d.n+'</td>'
        +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">Normal approximation used. W = min(W+, W-).</div>';
    } else if(wsrt.tab==='effect'){
      var b=effBand('r',d.r);
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.vA)+' vs '+esc(d.vB)+'</td><td class="dx-interp">r = Z / √n</td><td>'+fmtN(d.r,3)+'</td><td class="dx-interp">'+b+'</td></tr>'
        +'</tbody></table></div>';
    } else {
      var sig2=d.p<0.05;
      var plain=sig2
        ?'A Wilcoxon signed-rank test showed a significant difference between '+d.vA+' and '+d.vB+' (W = '+fmtN(d.W,0)+', z = '+fmtN(d.Z,2)+', p '+fmtP(d.p)+', r = '+fmtN(d.r,3)+').'
        :'No significant within-pair difference (W = '+fmtN(d.W,0)+', p '+fmtP(d.p)+').';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Report medians and n for both variables. The test assumes symmetric differences around the median, not just any distribution.</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.wsrtTab=function(tab){wsrt.tab=tab;var out=document.getElementById('wsrtResults');if(out&&wsrt.result)out.innerHTML=renderWsrtResults(wsrt.result);};

  // ---- Kruskal-Wallis ----
  var kw={result:null,tab:'ranks'};
  function renderKruskalWallis(host,ds,ctx){
    var nv=numericVars(ds);
    var gv=ds.variables.filter(function(v){var nm=nonMissing(v.values);var d=new Set(nm).size;return d>=3&&d<=20;});
    if(!nv.length||!gv.length){
      host.innerHTML=header('Inferential Analysis','Kruskal-Wallis','','anova')
        +'<div class="as-empty-tool">Kruskal-Wallis needs a numeric outcome and a grouping with 3 or more categories.</div>'; return;
    }
    if(!sel.an_out||!varByName(ds,sel.an_out)) sel.an_out=nv[0].name;
    if(!sel.an_grp||!varByName(ds,sel.an_grp)) sel.an_grp=gv[0].name;
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts=gv.map(function(v){var cnt=new Set(nonMissing(v.values)).size;return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_grp?' selected':'')+'>'+esc(v.name)+' ('+cnt+' groups)</option>';}).join('');
    var amseg=function(k,l){return '<button class="tt-seg'+(an.method===k?' on':'')+'" data-as-an-method="'+k+'">'+l+'</button>';};
    host.innerHTML=header('Inferential Analysis','Kruskal-Wallis','Non-parametric test: compares 3 or more groups without assuming normality.','anova')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Method</label><div class="tt-segs">'+amseg('parametric','Welch ANOVA')+amseg('kruskal_wallis','Kruskal-Wallis')+'</div></div>'
      +'<div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="kwOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">3+ categories</span></label><select class="ed-in" id="kwGrp">'+grpOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="kwRun">▷ Run Kruskal-Wallis</button></div>'
      +'</div></div>'
      +'<div id="kwResults">'+(kw.result?renderKwResults(kw.result):'')+'</div>';
    host.querySelectorAll('[data-as-an-method]').forEach(function(btn){
      btn.addEventListener('click',function(){an.method=btn.getAttribute('data-as-an-method');kw.result=null;renderAnova(host,ds,ctx);});
    });
    host.querySelector('#kwOut').addEventListener('change',function(e){sel.an_out=e.target.value;});
    host.querySelector('#kwGrp').addEventListener('change',function(e){sel.an_grp=e.target.value;});
    host.querySelector('#kwRun').addEventListener('click',function(){
      var ov=varByName(ds,sel.an_out),gvObj=varByName(ds,sel.an_grp); if(!ov||!gvObj) return;
      var groupMap={};
      for(var i=0;i<Math.min(ov.values.length,gvObj.values.length);i++){
        var v=num(ov.values[i]),g=gvObj.values[i]; if(v===null||isMissing(g)) continue;
        var key=String(g); (groupMap[key]=groupMap[key]||[]).push(v);
      }
      var levels=Object.keys(groupMap).sort();
      if(levels.length<3){host.querySelector('#kwResults').innerHTML='<div class="as-empty-tool">Need at least 3 groups.</div>';return;}
      var groups=levels.map(function(l){return groupMap[l];});
      if(groups.some(function(g){return g.length<2;})){host.querySelector('#kwResults').innerHTML='<div class="as-empty-tool">Each group needs at least 2 observations.</div>';return;}
      var all=[];
      groups.forEach(function(g,gi){g.forEach(function(v){all.push({v:v,g:gi});});});
      var N=all.length;
      var allVals=all.map(function(x){return x.v;});
      var ranks=rankWithTies(allVals);
      var Ri=levels.map(function(){return 0;}),ni=groups.map(function(g){return g.length;});
      all.forEach(function(item,i){Ri[item.g]+=ranks[i];});
      var H=(12/(N*(N+1)))*Ri.reduce(function(s,r,i){return s+r*r/ni[i];},0)-3*(N+1);
      // Tie correction
      var sorted=allVals.slice().sort(function(a,b){return a-b;}),tSum=0,ii=0;
      while(ii<N){var jj=ii;while(jj<N&&sorted[jj]===sorted[ii])jj++;var t=jj-ii;if(t>1)tSum+=t*t*t-t;ii=jj;}
      var tieCorr=1-tSum/(N*N*N-N);
      var Hcorr=tieCorr>0?H/tieCorr:H;
      var df=levels.length-1,p=chiSqPNP(Hcorr,df);
      var eps2=Math.max(0,Math.min(1,(Hcorr-df)/(N-df-1)));
      var groupStats=levels.map(function(l,i){var g=groups[i];var s=g.slice().sort(function(a,b){return a-b;});var mid=Math.floor(s.length/2);var med=s.length%2?s[mid]:(s[mid-1]+s[mid])/2;return{name:l,n:ni[i],mean:mean(g),med:med,rankSum:Ri[i],meanRank:Ri[i]/ni[i]};});
      kw.result={ok:true,H:Hcorr,df:df,p:p,eps2:eps2,N:N,groups:groupStats,out:sel.an_out,grp:sel.an_grp};
      kw.tab='ranks';
      host.querySelector('#kwResults').innerHTML=renderKwResults(kw.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderKwResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['ranks','Group ranks'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],'ranks','kruskal_wallis');
    var body='';
    if(kw.tab==='ranks'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>Median</th><th>Mean rank</th><th>Rank sum</th></tr></thead><tbody>'
        +d.groups.map(function(g){return '<tr><td class="dx-name">'+esc(g.name)+'</td><td>'+g.n+'</td><td>'+fmtN(g.mean)+'</td><td>'+fmtN(g.med)+'</td><td>'+fmtN(g.meanRank)+'</td><td>'+fmtN(g.rankSum)+'</td></tr>';}).join('')
        +'</tbody></table></div>';
    } else if(kw.tab==='result'){
      var sig=d.p<0.05;
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>H</th><th>df</th><th>N</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.out)+'</td><td>'+fmtN(d.H,2)+'</td><td>'+d.df+'</td><td>'+d.N+'</td>'
        +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">H is chi-square approximated with tie correction. df = k - 1 = '+d.df+'.</div>';
    } else if(kw.tab==='effect'){
      var b=effBand('eps2',d.eps2);
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.out)+'</td><td class="dx-interp">ε² (epsilon-squared)</td><td>'+fmtN(d.eps2,3)+'</td><td class="dx-interp">'+b+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">ε² < .01 negligible · .01 small · .06 medium · .14 large.</div>';
    } else {
      var sig2=d.p<0.05;
      var plain=sig2
        ?'Ranks on '+d.out+' differ significantly across groups (H('+d.df+') = '+fmtN(d.H,2)+', p '+fmtP(d.p)+', ε² = '+fmtN(d.eps2,3)+'). At least one group tends to have higher values than the others.'
        :'No significant difference in ranks across groups (H('+d.df+') = '+fmtN(d.H,2)+', p '+fmtP(d.p)+').';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">A significant H only indicates at least one group differs. Run pairwise Mann-Whitney U tests with Holm correction to identify which pairs.</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.kwTab=function(tab){kw.tab=tab;var out=document.getElementById('kwResults');if(out&&kw.result)out.innerHTML=renderKwResults(kw.result);};

  // ---- Spearman Rank Correlation ----
  var spr={result:null,tab:'ranks'};
  function renderSpearman(host,ds,ctx){
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Spearman Correlation','','correlation')
        +'<div class="as-empty-tool">Need at least two numeric variables.</div>'; return;
    }
    if(!sel.cor_x||!varByName(ds,sel.cor_x)) sel.cor_x=nv[0].name;
    if(!sel.cor_y||!varByName(ds,sel.cor_y)||sel.cor_y===sel.cor_x) sel.cor_y=nv[1]?nv[1].name:nv[0].name;
    var opt=function(cur){return nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===cur?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');};
    var cmseg=function(k,l){return '<button class="tt-seg'+(cor.method===k?' on':'')+'" data-as-cor-method="'+k+'">'+l+'</button>';};
    host.innerHTML=header('Inferential Analysis','Spearman Correlation','Non-parametric correlation: based on ranks, not raw values.','correlation')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Method</label><div class="tt-segs">'+cmseg('pearson','Pearson r')+cmseg('spearman','Spearman rs')+'</div></div>'
      +'<div class="tt-grid">'
      +'<div class="field"><label>Variable X <span class="tt-hint">numeric</span></label><select class="ed-in" id="sprX">'+opt(sel.cor_x)+'</select></div>'
      +'<div class="field"><label>Variable Y <span class="tt-hint">numeric</span></label><select class="ed-in" id="sprY">'+opt(sel.cor_y)+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="sprRun">▷ Run Spearman</button></div>'
      +'</div></div>'
      +'<div id="sprResults">'+(spr.result?renderSprResults(spr.result):'')+'</div>';
    host.querySelectorAll('[data-as-cor-method]').forEach(function(btn){
      btn.addEventListener('click',function(){cor.method=btn.getAttribute('data-as-cor-method');spr.result=null;renderCorrelation(host,ds,ctx);});
    });
    host.querySelector('#sprX').addEventListener('change',function(e){sel.cor_x=e.target.value;});
    host.querySelector('#sprY').addEventListener('change',function(e){sel.cor_y=e.target.value;});
    host.querySelector('#sprRun').addEventListener('click',function(){
      var vx=varByName(ds,sel.cor_x),vy=varByName(ds,sel.cor_y); if(!vx||!vy||vx.name===vy.name) return;
      var xs=[],ys=[];
      for(var i=0;i<Math.min(vx.values.length,vy.values.length);i++){
        var xi=num(vx.values[i]),yi=num(vy.values[i]); if(xi===null||yi===null) continue;
        xs.push(xi); ys.push(yi);
      }
      var n=xs.length;
      if(n<4){host.querySelector('#sprResults').innerHTML='<div class="as-empty-tool">Need at least 4 complete pairs.</div>';return;}
      var rxs=rankWithTies(xs),rys=rankWithTies(ys);
      var mx=mean(rxs),my=mean(rys),sxy=0,sxx=0,syy=0;
      for(var j=0;j<n;j++){sxy+=(rxs[j]-mx)*(rys[j]-my);sxx+=(rxs[j]-mx)*(rxs[j]-mx);syy+=(rys[j]-my)*(rys[j]-my);}
      var rs=(sxx>0&&syy>0)?sxy/Math.sqrt(sxx*syy):0;
      var t=rs*Math.sqrt(n-2)/Math.sqrt(Math.max(1-rs*rs,1e-12));
      var df=n-2;
      // t p-value via incomplete beta
      var x=df/(df+t*t),p=regGammaPNP?1:1;
      // Use logGammaNP path: tPValue equivalent
      var p2=(function(tt,df2){if(!isFinite(tt)||df2<=0)return 1;var xx=df2/(df2+tt*tt);var bt=Math.exp(logGammaNP((df2+1)/2)-logGammaNP(0.5)-logGammaNP(df2/2)-(df2/2+0.5)*Math.log(1+tt*tt/df2));if(bt===0)return tt>0?0:1;return regGammaPNP(df2/2,xx*df2/2)*2>1?1:regGammaPNP(df2/2,xx*df2/2)*2;})(Math.abs(t),df);
      // Simpler: use betaI path
      var xv=df/(df+t*t);
      function betacfL(x2,a,b){var FPMIN=1e-300,qab=a+b,qap=a+1,qam=a-1,c=1,d=1-qab*x2/qap;if(Math.abs(d)<FPMIN)d=FPMIN;d=1/d;var h=d;for(var m=1;m<=200;m++){var m2=2*m;var aa=m*(b-m)*x2/((qam+m2)*(a+m2));d=1+aa*d;if(Math.abs(d)<FPMIN)d=FPMIN;c=1+aa/c;if(Math.abs(c)<FPMIN)c=FPMIN;d=1/d;h*=d*c;aa=-(a+m)*(qab+m)*x2/((a+m2)*(qap+m2));d=1+aa*d;if(Math.abs(d)<FPMIN)d=FPMIN;c=1+aa/c;if(Math.abs(c)<FPMIN)c=FPMIN;d=1/d;var del=d*c;h*=del;if(Math.abs(del-1)<1e-12)break;}return h;}
      function betaI(x2,a,b){if(x2<=0)return 0;if(x2>=1)return 1;var bt=Math.exp(logGammaNP(a+b)-logGammaNP(a)-logGammaNP(b)+a*Math.log(x2)+b*Math.log(1-x2));if(x2<(a+1)/(a+b+2))return bt*betacfL(x2,a,b)/a;return 1-bt*betacfL(1-x2,b,a)/b;}
      var pVal=betaI(xv,df/2,0.5);
      spr.result={ok:true,rs:rs,t:t,df:df,p:pVal,n:n,vX:sel.cor_x,vY:sel.cor_y};
      spr.tab='ranks';
      host.querySelector('#sprResults').innerHTML=renderSprResults(spr.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderSprResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['ranks','Result'],['effect','Effect size'],['report','Reporting language']],'ranks','spearman');
    var body='';
    if(spr.tab==='ranks'){
      var sig=d.p<0.05;
      var dir=d.rs>0?'positive (higher ranks on X tend with higher ranks on Y)':'negative (higher ranks on X tend with lower ranks on Y)';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th>rs</th><th>t</th><th>df</th><th>n</th><th>p</th><th class="l">Direction</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.vX)+' &amp; '+esc(d.vY)+'</td><td>'+fmtN(d.rs,3)+'</td><td>'+fmtN(d.t,2)+'</td><td>'+d.df+'</td><td>'+d.n+'</td>'
        +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+esc(dir)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr>'
        +'</tbody></table></div>';
    } else if(spr.tab==='effect'){
      var b=effBand('r',d.rs);
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Variables</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.vX)+' &amp; '+esc(d.vY)+'</td><td class="dx-interp">rs (rank correlation)</td><td>'+fmtN(d.rs,3)+'</td><td class="dx-interp">'+b+'</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">|rs| < .1 negligible · .1 small · .3 medium · .5 large. rs² = '+fmtN(d.rs*d.rs,3)+' (shared rank variance).</div>';
    } else {
      var sig2=d.p<0.05;
      var plain=sig2
        ?'A significant '+( d.rs>0?'positive':'negative')+' Spearman correlation between '+d.vX+' and '+d.vY+' (rs = '+fmtN(d.rs,3)+', p '+fmtP(d.p)+').'
        :'No significant rank correlation between '+d.vX+' and '+d.vY+' (rs = '+fmtN(d.rs,3)+', p '+fmtP(d.p)+').';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Researcher</td><td class="dx-interp">'+esc('Spearman’s rs('+d.df+') = '+fmtN(d.rs,3)+', p '+fmtP(d.p)+' (two-tailed), n = '+d.n+'.')+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Spearman rs measures monotonic association, not linear. Use when Pearson assumptions (normality, no extreme outliers) are questionable, or with ordinal data.</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.sprTab=function(tab){spr.tab=tab;var out=document.getElementById('sprResults');if(out&&spr.result)out.innerHTML=renderSprResults(spr.result);};

  // ---- Welch ANOVA (client-side, unequal variances) ----
  var wa={result:null,tab:'desc'};
  function renderWelchAnova(host,ds,ctx){
    var nv=numericVars(ds);
    var gv=ds.variables.filter(function(v){var nm=nonMissing(v.values);var d=new Set(nm).size;return d>=3&&d<=20;});
    if(!nv.length||!gv.length){
      host.innerHTML=header('Inferential Analysis','Welch ANOVA','Compares 3+ group means without assuming equal variances.','welch_anova')
        +'<div class="as-empty-tool">Welch ANOVA needs a numeric outcome and a grouping with 3 or more categories.</div>'; return;
    }
    if(!sel.an_out||!varByName(ds,sel.an_out)) sel.an_out=nv[0].name;
    if(!sel.an_grp||!varByName(ds,sel.an_grp)) sel.an_grp=gv[0].name;
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts=gv.map(function(v){var cnt=new Set(nonMissing(v.values)).size;return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_grp?' selected':'')+'>'+esc(v.name)+' ('+cnt+' groups)</option>';}).join('');
    host.innerHTML=header('Inferential Analysis','Welch ANOVA','Compares 3+ group means without assuming equal variances.','welch_anova')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Welch ANOVA adjusts F and df when group variances differ</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="waOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">3+ categories</span></label><select class="ed-in" id="waGrp">'+grpOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="waRun">&#9655; Run Welch ANOVA</button></div>'
      +'</div></div>'
      +'<div id="waResults">'+(wa.result?renderWaResults(wa.result):'')+'</div>';
    host.querySelector('#waOut').addEventListener('change',function(e){sel.an_out=e.target.value;});
    host.querySelector('#waGrp').addEventListener('change',function(e){sel.an_grp=e.target.value;});
    host.querySelector('#waRun').addEventListener('click',function(){
      var ov=varByName(ds,sel.an_out),gvObj=varByName(ds,sel.an_grp); if(!ov||!gvObj) return;
      var gm={};
      for(var i=0;i<Math.min(ov.values.length,gvObj.values.length);i++){var y=num(ov.values[i]),g=gvObj.values[i];if(y===null||isMissing(g))continue;(gm[String(g)]=gm[String(g)]||[]).push(y);}
      var levels=Object.keys(gm).sort();
      if(levels.length<2){host.querySelector('#waResults').innerHTML='<div class="as-empty-tool">Need at least 2 groups.</div>';return;}
      var gd=levels.map(function(l){return gm[l];}),ns=gd.map(function(a){return a.length;});
      if(ns.some(function(n){return n<2;})){host.querySelector('#waResults').innerHTML='<div class="as-empty-tool">Each group needs at least 2 observations.</div>';return;}
      var k=levels.length,means=gd.map(mean),vars_=gd.map(variance);
      var w=ns.map(function(n,i){return n/(vars_[i]||1e-12);}),W=w.reduce(function(s,v){return s+v;},0);
      var M=w.reduce(function(s,wi,i){return s+wi*means[i];},0)/W;
      var numer=0;for(var i2=0;i2<k;i2++)numer+=w[i2]*Math.pow(means[i2]-M,2);numer/=(k-1);
      var denomSum=0;for(var i3=0;i3<k;i3++)denomSum+=Math.pow(1-w[i3]/W,2)/(ns[i3]-1);
      var denom=1+(2*(k-2)/(k*k-1))*denomSum;
      var F=numer/denom,df1=k-1,df2=(k*k-1)/(3*denomSum),p=fPValueNP(F,df1,df2);
      var all=[].concat.apply([],gd),grand=mean(all);
      var ssB=gd.reduce(function(s,g){return s+g.length*Math.pow(mean(g)-grand,2);},0);
      var ssW=gd.reduce(function(s,g){var gmn=mean(g);return s+g.reduce(function(a,x){return a+(x-gmn)*(x-gmn);},0);},0);
      var eta2=(ssB+ssW)?ssB/(ssB+ssW):0;
      var vr=Math.max.apply(null,vars_)/(Math.min.apply(null,vars_)||1);
      wa.result={ok:true,F:F,df1:df1,df2:df2,p:p,eta2:eta2,vr:vr,out:sel.an_out,grp:sel.an_grp,
        groups:levels.map(function(l,i){return{name:l,n:ns[i],mean:means[i],sd:Math.sqrt(vars_[i])};})};
      wa.tab='desc';
      host.querySelector('#waResults').innerHTML=renderWaResults(wa.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderWaResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['desc','Descriptives'],['result','Test result'],['effect','Effect size'],['report','Reporting language']],wa.tab,'welch_anova');
    var sig=d.p<0.05,body='';
    if(wa.tab==='desc'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th></tr></thead><tbody>'
        +d.groups.map(function(g){return '<tr><td class="dx-name">'+esc(g.name)+'</td><td>'+g.n+'</td><td>'+fmtN(g.mean)+'</td><td>'+fmtN(g.sd)+'</td></tr>';}).join('')
        +'</tbody></table></div><div style="font-size:12px;opacity:.7;margin-top:8px">Variance ratio (max/min) = '+fmtN(d.vr,2)+'. Welch does not assume equal variances.</div>';
    } else if(wa.tab==='result'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th>F</th><th>df1</th><th>df2</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.out)+'</td><td>'+fmtN(d.F,2)+'</td><td>'+d.df1+'</td><td>'+fmtN(d.df2,1)+'</td>'
        +'<td>'+fmtP(d.p)+'</td><td class="dx-interp">'+ttStatus(sig)+'</td></tr></tbody></table></div>';
    } else if(wa.tab==='effect'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Outcome</th><th class="l">Effect size</th><th>Value</th><th class="l">Interpretation</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">'+esc(d.out)+'</td><td class="dx-interp">η² (eta-squared)</td><td>'+fmtN(d.eta2,3)+'</td><td class="dx-interp">'+effBand('eta2',d.eta2)+'</td></tr></tbody></table></div>';
    } else {
      var plain=sig?'At least one group mean on '+d.out+' differs (Welch F('+d.df1+', '+fmtN(d.df2,1)+') = '+fmtN(d.F,2)+', p '+fmtP(d.p)+', η² = '+fmtN(d.eta2,3)+'). Run Post-Hoc Comparison to find which pairs.':'Group means do not differ reliably (Welch F('+d.df1+', '+fmtN(d.df2,1)+') = '+fmtN(d.F,2)+', p '+fmtP(d.p)+').';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Welch is the safer default when group sizes or variances are unequal. A significant omnibus result does not say which groups differ.</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.waTab=function(tab){wa.tab=tab;var out=document.getElementById('waResults');if(out&&wa.result)out.innerHTML=renderWaResults(wa.result);};

  // ---- Post-Hoc Comparison (pairwise Welch t + Bonferroni-Holm) ----
  var ph={result:null};
  function renderPostHoc(host,ds,ctx){
    var nv=numericVars(ds);
    var gv=ds.variables.filter(function(v){var nm=nonMissing(v.values);var d=new Set(nm).size;return d>=3&&d<=20;});
    if(!nv.length||!gv.length){
      host.innerHTML=header('Inferential Analysis','Post-Hoc Comparison','Pairwise group comparisons after a significant ANOVA.','post_hoc')
        +'<div class="as-empty-tool">Post-hoc comparisons need a numeric outcome and a grouping with 3 or more categories.</div>'; return;
    }
    if(!sel.an_out||!varByName(ds,sel.an_out)) sel.an_out=nv[0].name;
    if(!sel.an_grp||!varByName(ds,sel.an_grp)) sel.an_grp=gv[0].name;
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts=gv.map(function(v){var cnt=new Set(nonMissing(v.values)).size;return '<option value="'+esc(v.name)+'"'+(v.name===sel.an_grp?' selected':'')+'>'+esc(v.name)+' ('+cnt+' groups)</option>';}).join('');
    host.innerHTML=header('Inferential Analysis','Post-Hoc Comparison','Pairwise group comparisons with multiple-comparison correction.','post_hoc')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pairwise Welch t-tests with Bonferroni-Holm correction</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="phOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">3+ categories</span></label><select class="ed-in" id="phGrp">'+grpOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="phRun">&#9655; Run Post-Hoc</button></div>'
      +'</div></div>'
      +'<div id="phResults">'+(ph.result?renderPhResults(ph.result):'')+'</div>';
    host.querySelector('#phOut').addEventListener('change',function(e){sel.an_out=e.target.value;});
    host.querySelector('#phGrp').addEventListener('change',function(e){sel.an_grp=e.target.value;});
    host.querySelector('#phRun').addEventListener('click',function(){
      var ov=varByName(ds,sel.an_out),gvObj=varByName(ds,sel.an_grp); if(!ov||!gvObj) return;
      var gm={};
      for(var i=0;i<Math.min(ov.values.length,gvObj.values.length);i++){var y=num(ov.values[i]),g=gvObj.values[i];if(y===null||isMissing(g))continue;(gm[String(g)]=gm[String(g)]||[]).push(y);}
      var levels=Object.keys(gm).sort();
      if(levels.length<3){host.querySelector('#phResults').innerHTML='<div class="as-empty-tool">Need at least 3 groups for post-hoc comparisons.</div>';return;}
      var dataByLvl=levels.map(function(l){return gm[l];}),means=dataByLvl.map(mean),vars_=dataByLvl.map(variance),ns=dataByLvl.map(function(a){return a.length;});
      var pairs=[];
      for(var i2=0;i2<levels.length;i2++){for(var j=i2+1;j<levels.length;j++){
        var m1=means[i2],m2=means[j],v1=vars_[i2],v2=vars_[j],n1=ns[i2],n2=ns[j];
        var se=Math.sqrt(v1/n1+v2/n2),t=(m1-m2)/(se||1e-12);
        var dfN=Math.pow(v1/n1+v2/n2,2),dfD=(v1*v1)/(n1*n1*(n1-1))+(v2*v2)/(n2*n2*(n2-1));
        var df=dfD?dfN/dfD:(n1+n2-2),pr=tPValueNP(Math.abs(t),df)*2>1?1:tPValueNP(Math.abs(t),df)*2;
        var diff=m1-m2,tCrit=tCriticalNP(0.05,df),pooled=Math.sqrt(((n1-1)*v1+(n2-1)*v2)/(n1+n2-2));
        pairs.push({a:levels[i2],b:levels[j],t:t,df:df,p_raw:pr,diff:diff,ciLow:diff-tCrit*se,ciHi:diff+tCrit*se,d:(m1-m2)/(pooled||1e-12)});
      }}
      var sorted=pairs.slice().sort(function(x,y){return x.p_raw-y.p_raw;}),m=sorted.length,last=0;
      sorted.forEach(function(p,kk){var adj=Math.min(1,p.p_raw*(m-kk));p.p_adj=Math.max(adj,last);last=p.p_adj;});
      ph.result={ok:true,out:sel.an_out,grp:sel.an_grp,levels:levels,pairs:pairs};
      host.querySelector('#phResults').innerHTML=renderPhResults(ph.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderPhResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var sig=d.pairs.filter(function(p){return p.p_adj<0.05;}).length;
    var sortedDisplay=d.pairs.slice().sort(function(x,y){return x.p_adj-y.p_adj;});
    var table='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Comparison</th><th>Mean diff</th><th>95% CI</th><th>t</th><th>df</th><th>p (raw)</th><th>p (Holm)</th><th>d</th></tr></thead><tbody>'
      +sortedDisplay.map(function(p){return '<tr><td class="dx-name">'+esc(p.a)+' − '+esc(p.b)+'</td><td>'+fmtN(p.diff)+'</td><td>['+fmtN(p.ciLow)+', '+fmtN(p.ciHi)+']</td><td>'+fmtN(p.t,2)+'</td><td>'+fmtN(p.df,1)+'</td><td>'+fmtP(p.p_raw)+'</td><td>'+(p.p_adj<0.05?'<strong>'+fmtP(p.p_adj)+'</strong>':fmtP(p.p_adj))+'</td><td>'+fmtN(p.d,2)+'</td></tr>';}).join('')
      +'</tbody></table></div><div style="font-size:12px;opacity:.7;margin-top:8px">Pairwise Welch t-tests (unequal variances) with Bonferroni-Holm correction. Rows sorted by adjusted p.</div>';
    var interp=sig?(sig+' of '+d.pairs.length+' comparisons remain significant after Holm correction.'):'No pairs survive the Bonferroni-Holm correction.';
    return '<div class="panel"><div class="panel-b">'
      +'<div class="dx-scroll" style="margin-bottom:12px"><table class="dx-table"><thead><tr><th class="l">Comparisons</th><th class="l">Method</th><th>Significant (α=.05)</th></tr></thead><tbody>'
      +'<tr><td>'+d.pairs.length+'</td><td class="dx-interp">Welch + Holm</td><td>'+sig+'</td></tr></tbody></table></div>'
      +table
      +'<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(interp)+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">Run post-hoc comparisons only after a significant omnibus ANOVA. The Holm correction controls the family-wise error rate across all pairs.</div></div></div>'
      +'</div></div>';
  }
  AS.phTab=function(){};

  // ---- Confidence Intervals ----
  var ci={result:null,kind:'mean'};
  function renderConfidenceInterval(host,ds,ctx){
    var nv=numericVars(ds),cv=catVars(ds);
    if(!nv.length&&!cv.length){
      host.innerHTML=header('Inferential Analysis','Confidence Intervals','','confidence_interval')
        +'<div class="as-empty-tool">Need at least one numeric or categorical variable.</div>'; return;
    }
    var kseg=function(k,l){return '<button class="tt-seg'+(ci.kind===k?' on':'')+'" data-as-ci-kind="'+k+'">'+l+'</button>';};
    var numOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'">'+esc(v.name)+'</option>';}).join('');
    var catOpts=cv.map(function(v){return '<option value="'+esc(v.name)+'">'+esc(v.name)+'</option>';}).join('');
    var grp2=ds.variables.filter(function(v){return isCategoricalVar(v)&&new Set(nonMissing(v.values)).size===2;});
    var grp2Opts=grp2.map(function(v){return '<option value="'+esc(v.name)+'">'+esc(v.name)+'</option>';}).join('');
    var fields='';
    if(ci.kind==='mean') fields='<div class="field"><label>Variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="ciVar">'+numOpts+'</select></div>';
    else if(ci.kind==='prop') fields='<div class="field"><label>Variable <span class="tt-hint">categorical</span></label><select class="ed-in" id="ciVar">'+catOpts+'</select></div><div class="field"><label>Level</label><select class="ed-in" id="ciLvl"></select></div>';
    else if(ci.kind==='meandiff') fields='<div class="field"><label>Outcome <span class="tt-hint">numeric</span></label><select class="ed-in" id="ciOut">'+numOpts+'</select></div><div class="field"><label>Group <span class="tt-hint">2 levels</span></label><select class="ed-in" id="ciGrp">'+grp2Opts+'</select></div>';
    else fields='<div class="field"><label>Variable X <span class="tt-hint">numeric</span></label><select class="ed-in" id="ciX">'+numOpts+'</select></div><div class="field"><label>Variable Y <span class="tt-hint">numeric</span></label><select class="ed-in" id="ciY">'+numOpts+'</select></div>';
    host.innerHTML=header('Inferential Analysis','Confidence Intervals','Estimate a value and the range it plausibly falls in.','confidence_interval')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3></div></div>'
      +'<div class="panel-b">'
      +'<div class="field"><label>Estimate</label><div class="tt-segs">'+kseg('mean','Mean')+kseg('prop','Proportion')+kseg('meandiff','Mean difference')+kseg('pearson_r','Correlation r')+'</div></div>'
      +'<div class="tt-grid">'+fields
      +'<div class="field"><label>Confidence</label><select class="ed-in" id="ciLevel"><option value="0.90">90%</option><option value="0.95" selected>95%</option><option value="0.99">99%</option></select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="ciRun">&#9655; Compute interval</button></div>'
      +'</div></div>'
      +'<div id="ciResults">'+(ci.result?renderCiResults(ci.result):'')+'</div>';
    host.querySelectorAll('[data-as-ci-kind]').forEach(function(btn){
      btn.addEventListener('click',function(){ci.kind=btn.getAttribute('data-as-ci-kind');ci.result=null;renderConfidenceInterval(host,ds,ctx);});
    });
    if(ci.kind==='prop'){
      var pv=host.querySelector('#ciVar'),lv=host.querySelector('#ciLvl');
      var fillLvls=function(){var v=varByName(ds,pv.value);lv.innerHTML='';if(!v)return;Array.from(new Set(nonMissing(v.values))).sort().forEach(function(l){var o=document.createElement('option');o.value=l;o.textContent=l;lv.appendChild(o);});};
      if(pv){pv.addEventListener('change',fillLvls);fillLvls();}
    }
    host.querySelector('#ciRun').addEventListener('click',function(){
      var level=parseFloat(host.querySelector('#ciLevel').value),alpha=1-level,z=invNormalCDFNP(1-alpha/2),r;
      if(ci.kind==='mean'){
        var v=varByName(ds,host.querySelector('#ciVar').value);if(!v)return;var vals=v.values.map(num).filter(function(x){return x!==null;});
        if(vals.length<2){host.querySelector('#ciResults').innerHTML='<div class="as-empty-tool">Need at least 2 numeric values.</div>';return;}
        var m=mean(vals),s=sd(vals),n=vals.length,df=n-1,tC=tCriticalNP(alpha,df),se=s/Math.sqrt(n);
        r={kind:'Mean',variable:v.name,n:n,point:m,lo:m-tC*se,hi:m+tC*se,level:level,note:'t('+df+') critical = '+fmtN(tC,2)+', SE = '+fmtN(se,2)};
      } else if(ci.kind==='prop'){
        var pvar=varByName(ds,host.querySelector('#ciVar').value),lvl=host.querySelector('#ciLvl').value;if(!pvar||!lvl)return;
        var total=pvar.values.filter(function(x){return !isMissing(x);}).length,hits=pvar.values.filter(function(x){return String(x)===lvl;}).length;
        if(total<2){host.querySelector('#ciResults').innerHTML='<div class="as-empty-tool">Need at least 2 non-missing observations.</div>';return;}
        var ph2=hits/total,z2=z*z,dn=1+z2/total,ctr=(ph2+z2/(2*total))/dn,half=(z/dn)*Math.sqrt(ph2*(1-ph2)/total+z2/(4*total*total));
        r={kind:'Proportion',variable:pvar.name+' = '+lvl,n:total,point:ph2,lo:Math.max(0,ctr-half),hi:Math.min(1,ctr+half),level:level,pct:true,note:hits+' / '+total+' (Wilson interval)'};
      } else if(ci.kind==='meandiff'){
        var ov=varByName(ds,host.querySelector('#ciOut').value),gv=varByName(ds,host.querySelector('#ciGrp').value);if(!ov||!gv)return;
        var gm={};for(var i=0;i<Math.min(ov.values.length,gv.values.length);i++){var y=num(ov.values[i]),g=gv.values[i];if(y===null||isMissing(g))continue;(gm[String(g)]=gm[String(g)]||[]).push(y);}
        var lv2=Object.keys(gm);if(lv2.length!==2){host.querySelector('#ciResults').innerHTML='<div class="as-empty-tool">Grouping must have exactly 2 levels.</div>';return;}
        var a=gm[lv2[0]],b=gm[lv2[1]],m1=mean(a),m2=mean(b),v1=variance(a),v2=variance(b),n1=a.length,n2=b.length;
        var se2=Math.sqrt(v1/n1+v2/n2),dfN=Math.pow(v1/n1+v2/n2,2),dfD=(v1*v1)/(n1*n1*(n1-1))+(v2*v2)/(n2*n2*(n2-1)),df2=dfD?dfN/dfD:(n1+n2-2),tC2=tCriticalNP(alpha,df2),diff=m1-m2;
        r={kind:'Mean difference ('+lv2[0]+' − '+lv2[1]+')',variable:ov.name,n:n1+n2,point:diff,lo:diff-tC2*se2,hi:diff+tC2*se2,level:level,note:'Welch df = '+fmtN(df2,1)+', SE = '+fmtN(se2,2)};
      } else {
        var x=varByName(ds,host.querySelector('#ciX').value),y2=varByName(ds,host.querySelector('#ciY').value);if(!x||!y2||x.name===y2.name)return;
        var xs=[],ys=[];for(var i4=0;i4<Math.min(x.values.length,y2.values.length);i4++){var xi=num(x.values[i4]),yi=num(y2.values[i4]);if(xi===null||yi===null)continue;xs.push(xi);ys.push(yi);}
        if(xs.length<4){host.querySelector('#ciResults').innerHTML='<div class="as-empty-tool">Need at least 4 paired observations.</div>';return;}
        var n4=xs.length,mx=mean(xs),my=mean(ys),cov=0,vx=0,vy=0;for(var i5=0;i5<n4;i5++){var dx=xs[i5]-mx,dy=ys[i5]-my;cov+=dx*dy;vx+=dx*dx;vy+=dy*dy;}
        var rr=cov/(Math.sqrt(vx*vy)||1e-12),zr=0.5*Math.log((1+rr)/(1-rr)),se3=1/Math.sqrt(n4-3),ciZ=[zr-z*se3,zr+z*se3];
        r={kind:'Pearson r ('+x.name+', '+y2.name+')',variable:'',n:n4,point:rr,lo:(Math.exp(2*ciZ[0])-1)/(Math.exp(2*ciZ[0])+1),hi:(Math.exp(2*ciZ[1])-1)/(Math.exp(2*ciZ[1])+1),level:level,note:'Fisher-z interval, n = '+n4};
      }
      ci.result=r;host.querySelector('#ciResults').innerHTML=renderCiResults(r);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderCiResults(d){
    if(!d) return '';
    var f=function(x){return d.pct?(x*100).toFixed(1)+'%':fmtN(x,3);};
    var pct=Math.round(d.level*100);
    var body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Estimate</th><th>Point</th><th>'+pct+'% CI low</th><th>'+pct+'% CI high</th><th>n</th></tr></thead><tbody>'
      +'<tr><td class="dx-name">'+esc(d.kind)+(d.variable?': '+esc(d.variable):'')+'</td><td>'+f(d.point)+'</td><td>'+f(d.lo)+'</td><td>'+f(d.hi)+'</td><td>'+d.n+'</td></tr>'
      +'</tbody></table></div><div style="font-size:12px;opacity:.7;margin-top:8px">'+esc(d.note)+'.</div>';
    var excludesZero=(d.lo>0||d.hi<0);
    var interp=(d.kind.indexOf('difference')>=0||d.kind.indexOf('Pearson')>=0)
      ?(excludesZero?'The interval excludes zero, so the effect is distinguishable from zero at α = '+(1-d.level).toFixed(2)+'.':'The interval includes zero, consistent with no effect at α = '+(1-d.level).toFixed(2)+'.')
      :'We are '+pct+'% confident the true '+d.kind.toLowerCase()+' falls in this range.';
    return '<div class="panel"><div class="panel-b">'+body
      +'<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">What this means</div><div class="dx-l-t">'+esc(interp)+'</div></div>'
      +'<div class="dx-l dx-caution"><div class="dx-l-k">Caution</div><div class="dx-l-t">A confidence interval describes uncertainty from sampling, not measurement error or bias. A wider interval means less precision.</div></div></div>'
      +'</div></div>';
  }
  AS.ciTab=function(){};

  // ---- Assumption Checks ----
  var ac={result:null};
  function renderAssumptionChecks(host,ds,ctx){
    var nv=numericVars(ds);
    if(!nv.length){
      host.innerHTML=header('Inferential Analysis','Assumption Checks','','assumption_checks')
        +'<div class="as-empty-tool">Need at least one numeric variable to check.</div>'; return;
    }
    if(!sel.ac_out||!varByName(ds,sel.ac_out)) sel.ac_out=nv[0].name;
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.ac_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var grpOpts='<option value="">— none (normality only) —</option>'+catVars(ds).map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.ac_grp?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    host.innerHTML=header('Inferential Analysis','Assumption Checks','Test normality and equal-variance before choosing a parametric test.','assumption_checks')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Normality (skewness, kurtosis, K-S) and homogeneity of variance (Levene)</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="acOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping <span class="tt-hint">optional, for Levene</span></label><select class="ed-in" id="acGrp">'+grpOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="acRun">&#9655; Check assumptions</button></div>'
      +'</div></div>'
      +'<div id="acResults">'+(ac.result?renderAcResults(ac.result):'')+'</div>';
    host.querySelector('#acOut').addEventListener('change',function(e){sel.ac_out=e.target.value;});
    host.querySelector('#acGrp').addEventListener('change',function(e){sel.ac_grp=e.target.value;});
    host.querySelector('#acRun').addEventListener('click',function(){
      var ov=varByName(ds,sel.ac_out),grpName=host.querySelector('#acGrp').value,grp=grpName?varByName(ds,grpName):null;if(!ov)return;
      var values=ov.values.map(num).filter(function(x){return x!==null;});
      if(values.length<3){host.querySelector('#acResults').innerHTML='<div class="as-empty-tool">Need at least 3 non-missing values.</div>';return;}
      var sk=skewNP(values),ku=kurtNP(values),n=values.length,m=mean(values),s=sd(values);
      var sorted=values.slice().sort(function(a,b){return a-b;}),D=0;
      sorted.forEach(function(x,i){var F=normalCDF((x-m)/(s||1e-12)),Fe=(i+1)/n,Fl=i/n;D=Math.max(D,Math.abs(Fe-F),Math.abs(F-Fl));});
      var Dcrit=1.36/Math.sqrt(n),normPass=Math.abs(sk)<2&&Math.abs(ku)<7&&D<Dcrit;
      var levene=null;
      if(grp){
        var gm={};for(var i=0;i<Math.min(ov.values.length,grp.values.length);i++){var y=num(ov.values[i]),g=grp.values[i];if(y===null||isMissing(g))continue;(gm[String(g)]=gm[String(g)]||[]).push(y);}
        var levels=Object.keys(gm);
        if(levels.length>=2&&levels.every(function(l){return gm[l].length>=2;})){
          var Z=[],groupZ=levels.map(function(l){var arr=gm[l],med=median(arr),z=arr.map(function(x){return Math.abs(x-med);});z.forEach(function(v){Z.push(v);});return z;});
          var N=Z.length,k=levels.length,gMean=mean(Z);
          var ssB=groupZ.reduce(function(s2,gz){return s2+gz.length*Math.pow(mean(gz)-gMean,2);},0);
          var ssW=groupZ.reduce(function(s2,gz){var gmn=mean(gz);return s2+gz.reduce(function(a,x){return a+(x-gmn)*(x-gmn);},0);},0);
          var dfB=k-1,dfW=N-k,F=dfB&&dfW?(ssB/dfB)/(ssW/dfW||1e-12):0,p=fPValueNP(F,dfB,dfW);
          levene={F:F,dfB:dfB,dfW:dfW,p:p,pass:p>0.05};
        }
      }
      ac.result={ok:true,out:sel.ac_out,grp:grpName,n:n,mean:m,sd:s,sk:sk,ku:ku,D:D,Dcrit:Dcrit,normPass:normPass,levene:levene};
      host.querySelector('#acResults').innerHTML=renderAcResults(ac.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderAcResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tone=function(ok){return '<span class="tt-status '+(ok?'ok':'rev')+'">'+(ok?'OK':'Check')+'</span>';};
    var rows='<tr><td class="dx-name">Skewness</td><td>'+fmtN(d.sk,2)+'</td><td class="dx-interp">'+tone(Math.abs(d.sk)<2)+' (target |sk| &lt; 2)</td></tr>'
      +'<tr><td class="dx-name">Excess kurtosis</td><td>'+fmtN(d.ku,2)+'</td><td class="dx-interp">'+tone(Math.abs(d.ku)<7)+' (target |ku| &lt; 7)</td></tr>'
      +'<tr><td class="dx-name">K-S statistic D</td><td>'+fmtN(d.D,3)+'</td><td class="dx-interp">'+tone(d.D<d.Dcrit)+' (crit = '+fmtN(d.Dcrit,3)+')</td></tr>';
    if(d.levene) rows+='<tr><td class="dx-name">Levene F('+d.levene.dfB+', '+d.levene.dfW+')</td><td>'+fmtN(d.levene.F,2)+', p '+fmtP(d.levene.p)+'</td><td class="dx-interp">'+tone(d.levene.pass)+' (equal variances)</td></tr>';
    var body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Check</th><th>Value</th><th class="l">Verdict</th></tr></thead><tbody>'+rows+'</tbody></table></div>'
      +'<div style="font-size:12px;opacity:.7;margin-top:8px">'+esc(d.out)+': n = '+d.n+', mean = '+fmtN(d.mean)+', SD = '+fmtN(d.sd)+'.</div>';
    var interp;
    if(d.normPass&&(!d.levene||d.levene.pass)) interp='Both assumptions hold. Standard parametric tests (Student\'s t, ANOVA, Pearson) are appropriate.';
    else if(!d.normPass&&(!d.levene||d.levene.pass)) interp='Normality is questionable but variances are stable. With n ≥ 30 per group the CLT gives some cover; otherwise consider Mann-Whitney or Kruskal-Wallis.';
    else if(d.normPass&&d.levene&&!d.levene.pass) interp='Normality holds but variances are unequal. Use Welch\'s t-test or Welch ANOVA.';
    else interp='Both assumptions fail. Consider a non-parametric test (Mann-Whitney, Kruskal-Wallis) or a transformation (log, square root).';
    return '<div class="panel"><div class="panel-b">'+body
      +'<div class="dx-layers"><div class="dx-l"><div class="dx-l-k">Recommendation</div><div class="dx-l-t">'+esc(interp)+'</div></div></div>'
      +'</div></div>';
  }
  AS.acTab=function(){};

  // ---- Two-Way ANOVA ----
  var twa={result:null,tab:'table'};
  function renderTwoWayAnova(host,ds,ctx){
    var nv=numericVars(ds);
    var fv=ds.variables.filter(function(v){var d=new Set(nonMissing(v.values)).size;return d>=2&&d<=20;});
    if(!nv.length||fv.length<2){
      host.innerHTML=header('Inferential Analysis','Two-Way ANOVA','Tests two factors and their interaction on a numeric outcome.','two_way_anova')
        +'<div class="as-empty-tool">Two-way ANOVA needs a numeric outcome and two grouping factors (each 2–20 levels).</div>'; return;
    }
    if(!sel.twa_out||!varByName(ds,sel.twa_out)) sel.twa_out=nv[0].name;
    if(!sel.twa_a||!varByName(ds,sel.twa_a)) sel.twa_a=fv[0].name;
    if(!sel.twa_b||!varByName(ds,sel.twa_b)||sel.twa_b===sel.twa_a) sel.twa_b=(fv[1]&&fv[1].name!==sel.twa_a)?fv[1].name:(fv.find(function(v){return v.name!==sel.twa_a;})||fv[0]).name;
    var outOpts=nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.twa_out?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var aOpts=fv.map(function(v){var c=new Set(nonMissing(v.values)).size;return '<option value="'+esc(v.name)+'"'+(v.name===sel.twa_a?' selected':'')+'>'+esc(v.name)+' ('+c+' levels)</option>';}).join('');
    var bOpts=fv.map(function(v){var c=new Set(nonMissing(v.values)).size;return '<option value="'+esc(v.name)+'"'+(v.name===sel.twa_b?' selected':'')+'>'+esc(v.name)+' ('+c+' levels)</option>';}).join('');
    host.innerHTML=header('Inferential Analysis','Two-Way ANOVA','Tests two factors and their interaction on a numeric outcome.','two_way_anova')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">One numeric outcome, two categorical factors</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="twaOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Factor A <span class="tt-hint">categorical</span></label><select class="ed-in" id="twaA">'+aOpts+'</select></div>'
      +'<div class="field"><label>Factor B <span class="tt-hint">categorical</span></label><select class="ed-in" id="twaB">'+bOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="twaRun">&#9655; Run Two-Way ANOVA</button></div>'
      +'</div></div>'
      +'<div id="twaResults">'+(twa.result?renderTwaResults(twa.result):'')+'</div>';
    host.querySelector('#twaOut').addEventListener('change',function(e){sel.twa_out=e.target.value;});
    host.querySelector('#twaA').addEventListener('change',function(e){sel.twa_a=e.target.value;twa.result=null;renderTwoWayAnova(host,ds,ctx);});
    host.querySelector('#twaB').addEventListener('change',function(e){sel.twa_b=e.target.value;twa.result=null;renderTwoWayAnova(host,ds,ctx);});
    host.querySelector('#twaRun').addEventListener('click',function(){
      var ov=varByName(ds,sel.twa_out),av=varByName(ds,sel.twa_a),bv=varByName(ds,sel.twa_b);
      if(!ov||!av||bv&&av.name===bv.name){host.querySelector('#twaResults').innerHTML='<div class="as-empty-tool">Pick a distinct outcome and two different factors.</div>';return;}
      var n=Math.min(ov.values.length,av.values.length,bv.values.length);
      var ally=[],cells={},aMap={},bMap={},aLevels=[],bLevels=[];
      for(var i=0;i<n;i++){
        var y=num(ov.values[i]),a=av.values[i],b=bv.values[i];
        if(y===null||isMissing(a)||isMissing(b))continue;
        a=String(a);b=String(b);
        ally.push(y);
        (aMap[a]=aMap[a]||[]).push(y);(bMap[b]=bMap[b]||[]).push(y);
        var key=a+' '+b;(cells[key]=cells[key]||[]).push(y);
        if(aLevels.indexOf(a)<0)aLevels.push(a);if(bLevels.indexOf(b)<0)bLevels.push(b);
      }
      aLevels.sort();bLevels.sort();
      var A=aLevels.length,B=bLevels.length,N=ally.length;
      if(A<2||B<2){host.querySelector('#twaResults').innerHTML='<div class="as-empty-tool">Each factor needs at least 2 levels present in the data.</div>';return;}
      if(N-A*B<1){host.querySelector('#twaResults').innerHTML='<div class="as-empty-tool">Not enough data: need more rows than (levels of A × levels of B) cells.</div>';return;}
      var grand=mean(ally);
      var ssTotal=ally.reduce(function(s,y){return s+(y-grand)*(y-grand);},0);
      var ssA=aLevels.reduce(function(s,l){var g=aMap[l];return s+g.length*Math.pow(mean(g)-grand,2);},0);
      var ssB=bLevels.reduce(function(s,l){var g=bMap[l];return s+g.length*Math.pow(mean(g)-grand,2);},0);
      var ssCells=0,emptyCell=false,cellTable=[];
      aLevels.forEach(function(al){var row=[];bLevels.forEach(function(bl){var c=cells[al+' '+bl];if(c&&c.length){ssCells+=c.length*Math.pow(mean(c)-grand,2);row.push({n:c.length,mean:mean(c)});}else{emptyCell=true;row.push({n:0,mean:null});}});cellTable.push({level:al,cells:row});});
      var ssAB=ssCells-ssA-ssB,ssError=ssTotal-ssCells;
      var dfA=A-1,dfB=B-1,dfAB=(A-1)*(B-1),dfError=N-A*B;
      var msErr=ssError/dfError;
      var mk=function(ss,df){var ms=ss/df,F=msErr>0?ms/msErr:0;return{ss:ss,df:df,ms:ms,F:F,p:fPValueNP(F,df,dfError),peta:(ss+ssError)>0?ss/(ss+ssError):0};};
      twa.result={ok:true,out:sel.twa_out,fa:sel.twa_a,fb:sel.twa_b,N:N,A:A,B:B,grand:grand,emptyCell:emptyCell,
        effA:mk(ssA,dfA),effB:mk(ssB,dfB),effAB:mk(ssAB,dfAB),
        ssError:ssError,dfError:dfError,msError:msErr,ssTotal:ssTotal,
        aLevels:aLevels,bLevels:bLevels,cellTable:cellTable};
      twa.tab='table';
      host.querySelector('#twaResults').innerHTML=renderTwaResults(twa.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderTwaResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['table','ANOVA table'],['cells','Cell means'],['effect','Effect sizes'],['report','Reporting language']],d._tab||twa.tab,'two_way_anova');
    var body='',row=function(name,e){return '<tr><td class="dx-name">'+esc(name)+'</td><td>'+fmtN(e.ss)+'</td><td>'+e.df+'</td><td>'+fmtN(e.ms)+'</td><td>'+fmtN(e.F,2)+'</td><td>'+fmtP(e.p)+'</td><td class="dx-interp">'+ttStatus(e.p<0.05)+'</td></tr>';};
    if(twa.tab==='table'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Source</th><th>SS</th><th>df</th><th>MS</th><th>F</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'
        +row(d.fa+' (A)',d.effA)+row(d.fb+' (B)',d.effB)+row(d.fa+' × '+d.fb+' (interaction)',d.effAB)
        +'<tr><td class="dx-name">Error</td><td>'+fmtN(d.ssError)+'</td><td>'+d.dfError+'</td><td>'+fmtN(d.msError)+'</td><td>—</td><td>—</td><td>—</td></tr>'
        +'<tr class="dx-total"><td class="dx-name">Total</td><td>'+fmtN(d.ssTotal)+'</td><td>'+(d.N-1)+'</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>'
        +'</tbody></table></div>'+(d.emptyCell?'<div style="font-size:12px;color:#b45309;margin-top:8px">Some factor combinations have no data (empty cells). Interpret the interaction with caution.</div>':'');
    } else if(twa.tab==='cells'){
      var head=d.bLevels.map(function(b){return '<th>'+esc(b)+'</th>';}).join('');
      var rows=d.cellTable.map(function(r){return '<tr><td class="dx-name">'+esc(r.level)+'</td>'+r.cells.map(function(c){return '<td>'+(c.mean===null?'—':fmtN(c.mean)+'<span style="opacity:.5;font-size:11px"> (n='+c.n+')</span>')+'</td>';}).join('')+'</tr>';}).join('');
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">'+esc(d.fa)+' \\ '+esc(d.fb)+'</th>'+head+'</tr></thead><tbody>'+rows+'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">Cell means of '+esc(d.out)+'. Grand mean = '+fmtN(d.grand)+'.</div>';
    } else if(twa.tab==='effect'){
      var er=function(name,e){return '<tr><td class="dx-name">'+esc(name)+'</td><td>'+fmtN(e.peta,3)+'</td><td class="dx-interp">'+effBand('eta2',e.peta)+'</td></tr>';};
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Effect</th><th>Partial η²</th><th class="l">Magnitude</th></tr></thead><tbody>'
        +er(d.fa+' (A)',d.effA)+er(d.fb+' (B)',d.effB)+er('Interaction',d.effAB)+'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">Partial η² = SS_effect / (SS_effect + SS_error). Bands: .01 small · .06 medium · .14 large.</div>';
    } else {
      var s=function(name,e){return name+' '+(e.p<0.05?'was':'was not')+' significant (F('+e.df+', '+d.dfError+') = '+fmtN(e.F,2)+', p '+fmtP(e.p)+', partial η² = '+fmtN(e.peta,3)+')';};
      var plain='A two-way ANOVA examined '+d.out+' by '+d.fa+' and '+d.fb+'. '+s('The main effect of '+d.fa,d.effA)+'. '+s('The main effect of '+d.fb,d.effB)+'. '+s('The '+d.fa+' × '+d.fb+' interaction',d.effAB)+'.';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">A significant interaction means the effect of one factor depends on the level of the other — interpret main effects carefully when it is present. Sums of squares are sequential; for unbalanced designs treat the decomposition as approximate.</td></tr>'
        +'</tbody></table></div>';
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.twaTab=function(tab){twa.tab=tab;var out=document.getElementById('twaResults');if(out&&twa.result)out.innerHTML=renderTwaResults(twa.result);};

  // ---- Logistic Regression (binary outcome, IRLS) ----
  var lr={preds:{},result:null,tab:'coef'};
  function renderLogisticRegression(host,ds,ctx){
    var nv=numericVars(ds);
    var bin=ds.variables.filter(function(v){return new Set(nonMissing(v.values)).size===2;});
    if(!bin.length||!nv.length){
      host.innerHTML=header('Inferential Analysis','Logistic Regression','Predicts a yes/no outcome from numeric predictors.','logistic_regression')
        +'<div class="as-empty-tool">Logistic regression needs a binary outcome (exactly 2 levels) and at least one numeric predictor.</div>'; return;
    }
    if(!sel.lr_y||!varByName(ds,sel.lr_y)||new Set(nonMissing(varByName(ds,sel.lr_y).values)).size!==2) sel.lr_y=bin[0].name;
    var avail=nv.filter(function(v){return v.name!==sel.lr_y;});
    if(!avail.length){
      host.innerHTML=header('Inferential Analysis','Logistic Regression','Predicts a yes/no outcome from numeric predictors.','logistic_regression')
        +'<div class="as-empty-tool">Need at least one numeric predictor distinct from the outcome.</div>'; return;
    }
    if(!Object.keys(lr.preds).some(function(k){return lr.preds[k]&&avail.find(function(v){return v.name===k;});})) lr.preds[avail[0].name]=true;
    var yObj=varByName(ds,sel.lr_y),yLevels=Array.from(new Set(nonMissing(yObj.values))).sort();
    var yOpts=bin.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===sel.lr_y?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');
    var chks=avail.map(function(v){return '<label class="rg-chk"><input type="checkbox"'+(lr.preds[v.name]?' checked':'')+' data-as-lrpred="'+esc(v.name)+'"> '+esc(v.name)+'</label>';}).join('');
    host.innerHTML=header('Inferential Analysis','Logistic Regression','Predicts a yes/no outcome from numeric predictors; reports odds ratios.','logistic_regression')
      +'<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Binary outcome, one or more numeric predictors</div></div></div>'
      +'<div class="panel-b">'
      +'<div class="field" style="max-width:340px"><label>Outcome <span class="tt-hint">2 levels</span></label><select class="ed-in" id="lrY">'+yOpts+'</select></div>'
      +'<div class="tt-hint" style="margin-top:6px">Modeling P('+esc(yObj.name)+' = <strong>'+esc(String(yLevels[1]))+'</strong>). The other level ('+esc(String(yLevels[0]))+') is the reference.</div>'
      +'<div class="field" style="margin-top:12px"><label>Predictors <span class="tt-hint">numeric</span></label><div class="rg-preds">'+chks+'</div></div>'
      +'<div class="run-actions"><button class="btn primary" id="lrRun">&#9655; Run Logistic Regression</button></div>'
      +'</div></div>'
      +'<div id="lrResults">'+(lr.result?renderLrResults(lr.result):'')+'</div>';
    host.querySelector('#lrY').addEventListener('change',function(e){sel.lr_y=e.target.value;lr.preds={};lr.result=null;renderLogisticRegression(host,ds,ctx);});
    host.querySelectorAll('[data-as-lrpred]').forEach(function(cb){cb.addEventListener('change',function(){var nm=cb.getAttribute('data-as-lrpred');if(cb.checked)lr.preds[nm]=true;else delete lr.preds[nm];});});
    host.querySelector('#lrRun').addEventListener('click',function(){
      var yv=varByName(ds,sel.lr_y);if(!yv)return;
      var lvls=Array.from(new Set(nonMissing(yv.values))).sort(),pos=String(lvls[1]);
      var predNames=Object.keys(lr.preds).filter(function(k){return lr.preds[k]&&varByName(ds,k)&&k!==sel.lr_y;});
      if(!predNames.length){host.querySelector('#lrResults').innerHTML='<div class="as-empty-tool">Select at least one predictor.</div>';return;}
      var nAll=yv.values.length;predNames.forEach(function(nm){nAll=Math.min(nAll,varByName(ds,nm).values.length);});
      var X=[],y=[];
      for(var i=0;i<nAll;i++){
        var yr=yv.values[i];if(isMissing(yr))continue;
        var ok=true,xrow=[1];
        for(var j=0;j<predNames.length;j++){var xi=num(varByName(ds,predNames[j]).values[i]);if(xi===null){ok=false;break;}xrow.push(xi);}
        if(!ok)continue;
        X.push(xrow);y.push(String(yr)===pos?1:0);
      }
      var n=y.length,k=predNames.length+1;
      if(n<k+1){host.querySelector('#lrResults').innerHTML='<div class="as-empty-tool">Not enough complete rows for this many predictors.</div>';return;}
      var sumY=y.reduce(function(s,v){return s+v;},0);
      if(sumY===0||sumY===n){host.querySelector('#lrResults').innerHTML='<div class="as-empty-tool">The outcome has no variation in the complete rows (all one class).</div>';return;}
      // IRLS / Newton-Raphson
      var beta=new Array(k).fill(0),Xt=matT(X),converged=false,iter=0,sep=false;
      for(iter=0;iter<50;iter++){
        var p=X.map(function(row){var z=0;for(var a=0;a<k;a++)z+=row[a]*beta[a];return sigmoidNP(z);});
        var W=p.map(function(pi){return Math.max(pi*(1-pi),1e-9);});
        var grad=new Array(k).fill(0);for(var r=0;r<n;r++)for(var c=0;c<k;c++)grad[c]+=X[r][c]*(y[r]-p[r]);
        var H=[];for(var a2=0;a2<k;a2++){H.push(new Array(k).fill(0));for(var b2=0;b2<k;b2++){var s2=0;for(var r2=0;r2<n;r2++)s2+=X[r2][a2]*W[r2]*X[r2][b2];H[a2][b2]=s2;}}
        var Hinv=matInv(H);if(!Hinv){sep=true;break;}
        var step=Hinv.map(function(row){var s3=0;for(var c2=0;c2<k;c2++)s3+=row[c2]*grad[c2];return s3;});
        var maxStep=0;for(var c3=0;c3<k;c3++){beta[c3]+=step[c3];maxStep=Math.max(maxStep,Math.abs(step[c3]));}
        if(maxStep<1e-8){converged=true;break;}
        if(beta.some(function(bb){return !isFinite(bb)||Math.abs(bb)>40;})){sep=true;break;}
      }
      // Final stats
      var pf=X.map(function(row){var z=0;for(var a=0;a<k;a++)z+=row[a]*beta[a];return sigmoidNP(z);});
      var Wf=pf.map(function(pi){return Math.max(pi*(1-pi),1e-9);});
      var Hf=[];for(var a3=0;a3<k;a3++){Hf.push(new Array(k).fill(0));for(var b3=0;b3<k;b3++){var s4=0;for(var r3=0;r3<n;r3++)s4+=X[r3][a3]*Wf[r3]*X[r3][b3];Hf[a3][b3]=s4;}}
      var cov=matInv(Hf);
      var se=beta.map(function(_,i){return cov?Math.sqrt(Math.max(cov[i][i],0)):NaN;});
      var labels=['(Intercept)'].concat(predNames);
      var coefs=beta.map(function(b,i){var z=b/(se[i]||1e-12),pv=2*(1-normalCDF(Math.abs(z)));return{term:labels[i],b:b,se:se[i],or:Math.exp(b),orLo:Math.exp(b-1.96*se[i]),orHi:Math.exp(b+1.96*se[i]),z:z,p:pv,intercept:i===0};});
      var eps=1e-12,LL=0;for(var r4=0;r4<n;r4++){var pp=Math.min(Math.max(pf[r4],eps),1-eps);LL+=y[r4]*Math.log(pp)+(1-y[r4])*Math.log(1-pp);}
      var p0=sumY/n,LL0=sumY*Math.log(p0)+(n-sumY)*Math.log(1-p0);
      var mcfadden=LL0!==0?1-LL/LL0:0,lrChi=2*(LL-LL0),lrDf=k-1,lrP=chiSqPNP(lrChi,lrDf);
      // Classification accuracy at 0.5
      var correct=0;for(var r5=0;r5<n;r5++)correct+=((pf[r5]>=0.5?1:0)===y[r5])?1:0;
      lr.result={ok:true,y:sel.lr_y,pos:pos,ref:String(lvls[0]),preds:predNames,n:n,coefs:coefs,LL:LL,LL0:LL0,mcfadden:mcfadden,lrChi:lrChi,lrDf:lrDf,lrP:lrP,acc:correct/n,converged:converged,sep:sep,iter:iter};
      lr.tab='coef';
      host.querySelector('#lrResults').innerHTML=renderLrResults(lr.result);
      if(ctx&&ctx.onResult) ctx.onResult();
    });
  }
  function renderLrResults(d){
    if(!d||!d.ok) return '<div class="as-empty-tool">No result.</div>';
    var tabs=ttTabs([['coef','Odds ratios'],['fit','Model fit'],['report','Reporting language']],lr.tab,'logistic_regression');
    var warn=d.sep?'<div style="font-size:12px;color:#b45309;margin-top:8px">The model did not fully converge (possible perfect separation or collinearity). Treat coefficients and standard errors with caution.</div>':'';
    var body='';
    if(lr.tab==='coef'){
      var rows=d.coefs.map(function(c){
        return '<tr><td class="dx-name">'+esc(c.term)+'</td><td>'+fmtN(c.b,3)+'</td><td>'+fmtN(c.se,3)+'</td>'
          +'<td><strong>'+(c.intercept?'—':fmtN(c.or,3))+'</strong></td>'
          +'<td>'+(c.intercept?'—':'['+fmtN(c.orLo,2)+', '+fmtN(c.orHi,2)+']')+'</td>'
          +'<td>'+fmtN(c.z,2)+'</td><td>'+fmtP(c.p)+'</td>'
          +'<td class="dx-interp">'+(c.intercept?'—':'<span class="tt-status '+(c.p<0.05?'ok':'rev')+'">'+(c.p<0.05?'Significant':'n.s.')+'</span>')+'</td></tr>';
      }).join('');
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Term</th><th>b (log-odds)</th><th>SE</th><th>Odds ratio</th><th>95% CI (OR)</th><th>z</th><th>p</th><th class="l">Result</th></tr></thead><tbody>'+rows+'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">Odds ratio &gt; 1: predictor raises the odds of '+esc(d.y)+' = '+esc(d.pos)+'; &lt; 1: lowers it. n = '+d.n+'.</div>'+warn;
    } else if(lr.tab==='fit'){
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Statistic</th><th>Value</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Observations</td><td>'+d.n+'</td></tr>'
        +'<tr><td class="dx-name">Modeling P(outcome = '+esc(d.pos)+')</td><td>reference: '+esc(d.ref)+'</td></tr>'
        +'<tr><td class="dx-name">McFadden pseudo-R²</td><td>'+fmtN(d.mcfadden,3)+'</td></tr>'
        +'<tr><td class="dx-name">Likelihood-ratio χ²('+d.lrDf+')</td><td>'+fmtN(d.lrChi,2)+', p '+fmtP(d.lrP)+'</td></tr>'
        +'<tr><td class="dx-name">Log-likelihood (model / null)</td><td>'+fmtN(d.LL,2)+' / '+fmtN(d.LL0,2)+'</td></tr>'
        +'<tr><td class="dx-name">Classification accuracy (cutoff .5)</td><td>'+(d.acc*100).toFixed(1)+'%</td></tr>'
        +'</tbody></table></div>'
        +'<div style="font-size:12px;opacity:.7;margin-top:8px">McFadden R² of .2–.4 indicates good fit. The LR test compares the model against an intercept-only baseline.</div>'+warn;
    } else {
      var sigPreds=d.coefs.filter(function(c){return !c.intercept&&c.p<0.05;});
      var lang=sigPreds.length?sigPreds.map(function(c){return c.term+' (OR = '+fmtN(c.or,2)+', '+fmtP(c.p)+')';}).join(', '):'no individual predictor';
      var plain='A logistic regression predicted the odds of '+d.y+' = '+d.pos+' from '+d.preds.join(', ')+'. The model '+(d.lrP<0.05?'significantly':'did not significantly')+' improve on the null (χ²('+d.lrDf+') = '+fmtN(d.lrChi,2)+', '+fmtP(d.lrP)+', McFadden R² = '+fmtN(d.mcfadden,3)+'). Significant predictors: '+lang+'.';
      body='<div class="dx-scroll"><table class="dx-table"><thead><tr><th class="l">Audience</th><th class="l">Suggested language</th></tr></thead><tbody>'
        +'<tr><td class="dx-name">Plain-language</td><td class="dx-interp">'+esc(plain)+'</td></tr>'
        +'<tr><td class="dx-name">Caution</td><td class="dx-interp">Odds ratios are multiplicative per one-unit change in the predictor and assume a linear relationship on the log-odds scale. Logistic regression does not imply causation; check for influential points and adequate events-per-predictor (≥ 10).</td></tr>'
        +'</tbody></table></div>'+warn;
    }
    return tabs+'<div class="panel"><div class="panel-b">'+body+'</div></div>';
  }
  AS.lrTab=function(tab){lr.tab=tab;var out=document.getElementById('lrResults');if(out&&lr.result)out.innerHTML=renderLrResults(lr.result);};

  // ---- Effect Sizes (client-side) ----
  function renderEffectSizes(host,ds,ctx){
    var nv=numericVars(ds),cv=catVars(ds);
    var rows=[];
    nv.forEach(function(ov){
      cv.forEach(function(gv){
        var n=Math.min(ov.values.length,gv.values.length),groups={};
        for(var i=0;i<n;i++){var v=num(ov.values[i]),g=gv.values[i];if(v!==null&&!isMissing(g)){(groups[g]=groups[g]||[]).push(v);}}
        var gkeys=Object.keys(groups); if(gkeys.length<2) return;
        var all=[].concat.apply([],gkeys.map(function(k){return groups[k];}));
        var grandMean=mean(all),tss=0;
        all.forEach(function(x){tss+=(x-grandMean)*(x-grandMean);});
        if(gkeys.length===2){
          var a=groups[gkeys[0]],b=groups[gkeys[1]];
          var sp=Math.sqrt((((a.length-1)*variance(a))+((b.length-1)*variance(b)))/Math.max(a.length+b.length-2,1));
          var d=sp>0?Math.abs(mean(a)-mean(b))/sp:0;
          rows.push({outcome:ov.name,group:gv.name,test:'t-test',effect:"Cohen's d",value:d,label:effLabel('cohens_d',d)});
        } else {
          var ssBetween=0;
          gkeys.forEach(function(g){var a2=groups[g];ssBetween+=a2.length*Math.pow(mean(a2)-grandMean,2);});
          var eta2=tss>0?ssBetween/tss:0;
          rows.push({outcome:ov.name,group:gv.name,test:'ANOVA',effect:'η²',value:eta2,label:effLabel('eta_squared',eta2)});
        }
      });
    });
    nv.forEach(function(v1){
      nv.forEach(function(v2){
        if(v1.name>=v2.name) return;
        var n=Math.min(v1.values.length,v2.values.length),xs=[],ys=[];
        for(var i=0;i<n;i++){var xi=num(v1.values[i]),yi=num(v2.values[i]);if(xi!==null&&yi!==null){xs.push(xi);ys.push(yi);}}
        if(xs.length<3) return;
        var mx=mean(xs),my=mean(ys),sxy=0,sxx=0,syy=0;
        for(var i=0;i<xs.length;i++){sxy+=(xs[i]-mx)*(ys[i]-my);sxx+=(xs[i]-mx)*(xs[i]-mx);syy+=(ys[i]-my)*(ys[i]-my);}
        if(sxx>0&&syy>0){var r=sxy/Math.sqrt(sxx*syy),r2=r*r;
          rows.push({outcome:v1.name,group:v2.name,test:'correlation',effect:'r²',value:r2,label:effLabel('r_squared',r2)});
        }
      });
    });
    rows.sort(function(a,b){return b.value-a.value;});
    var body=rows.length?rows.map(function(r){
      return '<tr><td class="dx-name">'+esc(r.outcome)+'</td><td class="dx-name">'+esc(r.group)+'</td>'
        +'<td>'+esc(r.test)+'</td><td>'+esc(r.effect)+'</td><td>'+n2(r.value)+' '+effChip(r.label)+'</td></tr>';
    }).join(''):'<tr><td colspan="5">No effect sizes computed — need numeric outcomes paired with grouping or numeric variables.</td></tr>';
    var layerBlocks=[
      {k:'What this shows',t:'Effect sizes for all variable pairs. Sorted largest to smallest.'},
      {k:'Why this matters',t:'Effect sizes describe practical importance independently of sample size. Use them to prioritize which relationships to investigate.'},
      {k:'Caution',caution:true,t:'These are descriptive and not adjusted for multiple comparisons. Treat as a screening tool, not final inference.'}
    ];
    host.innerHTML=header('Inferential Analysis','Effect Sizes','Practical magnitude of associations, sorted largest to smallest.','effect_sizes')
      +'<div class="panel"><div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
      +'<thead><tr><th class="l">Outcome / Variable A</th><th class="l">By / Variable B</th><th>Test</th><th>Measure</th><th>Effect</th></tr></thead><tbody>'
      +body+'</tbody></table></div>'+layers(layerBlocks)+'</div></div>';
    if(ctx&&ctx.onResult) ctx.onResult();
  }


  // Delegated: any "How to use this" button (in a work step or the Overview)
  // opens its help modal.
  document.addEventListener('click', function(e){
    const b = e.target.closest ? e.target.closest('[data-as-help]') : null;
    if (b) AS.help(b.getAttribute('data-as-help'));
  });

  window.AnalysisStudio = AS;
})();

/* ============================================================================
   Descriptive Studio — Start-screen action buttons (added by Claude).
   Replaces the single "Upload data" card on the Start step with three buttons:
   Auto analyze, Auto report, Self analyze. Self-contained and defensive — wrapped
   in try/catch so it can never break the rest of the studio. Does NOT modify the
   shared upload widget (dataset-upload.js); it only CALLS DatasetUpload.open and
   QuickAnalyze.openPopup.
   ========================================================================== */
(function () {
  'use strict';
  try {
    if (!/descriptive-analysis-workspace\.php/.test(location.pathname)) return;

    // A "real" project means the user actually has data loaded (uploaded a file or
    // opened a saved project), not the demo sample. The two Auto buttons stay grayed
    // out until this is > 0.
    function realProjectId() {
      var p = new URLSearchParams(location.search).get('project_id');
      var n = p ? (parseInt(p, 10) || 0) : 0;
      if (n) return n;
      try { if (typeof BOOT !== 'undefined' && BOOT.projectId) return parseInt(BOOT.projectId, 10) || 0; } catch (e) {}
      return 0;
    }

    function openPopup(pid, mode) {
      function go() {
        window.QuickAnalyze.openPopup(pid, {
          mode: mode === 'report' ? 'report' : 'auto',
          projectTitle: '',
          workspaceUrl: '/descriptive-analysis-workspace.php?project_id=' + pid,
        });
      }
      if (window.QuickAnalyze && window.QuickAnalyze.openPopup) { go(); return; }
      var s = document.createElement('script');
      s.src = '/apps/analysis-studio/quick-analyze-core.js?v=' + Date.now();
      s.onload = go;
      document.head.appendChild(s);
    }

    function injectStyles() {
      if (document.getElementById('da-mode-css')) return;
      var st = document.createElement('style');
      st.id = 'da-mode-css';
      st.textContent =
        '.da-mode-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:24px;margin-bottom:14px}'
        + '.da-mode-card{display:flex;gap:12px;align-items:flex-start;text-align:left;padding:18px 16px;border:1px solid #e6e8ec;border-radius:16px;background:#fff;cursor:pointer;font-family:inherit;transition:border-color .12s,box-shadow .12s}'
        + '.da-mode-card:hover{border-color:#c0392b;box-shadow:0 2px 12px rgba(192,57,43,.13)}'
        + '.da-mode-ico{font-size:22px;line-height:1.1;flex:0 0 26px}'
        + '.da-mode-h{display:block;font-size:14.5px;font-weight:700;color:#1a1d23}'
        + '.da-mode-p{display:block;font-size:12px;color:#6b7280;margin-top:4px;line-height:1.4}'
        + '.da-mode-card.da-disabled{opacity:.45;cursor:default}'
        + '.da-mode-card.da-disabled:hover{border-color:#e6e8ec;box-shadow:none}'
        + '@media(max-width:760px){.da-mode-row{grid-template-columns:1fr}}';
      document.head.appendChild(st);
    }

    function makeCard(mode, ico, title, sub) {
      var gated = (mode === 'auto' || mode === 'report');
      var enabled = !gated || realProjectId() > 0;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'da-mode-card' + (enabled ? '' : ' da-disabled');
      b.setAttribute('data-da-mode', mode);
      if (!enabled) b.setAttribute('title', 'Upload your data first to use this.');
      var subText = enabled ? sub : 'Upload data first to enable this.';
      b.innerHTML = '<span class="da-mode-ico">' + ico + '</span>'
        + '<span><span class="da-mode-h">' + title + '</span>'
        + '<span class="da-mode-p">' + subText + '</span></span>';
      b.addEventListener('click', function () {
        if (!enabled) return;
        if (mode === 'auto' || mode === 'report') { openPopup(realProjectId(), mode); return; }
        // Self analyze — step through the studio yourself.
        var ov = document.querySelector('[data-step="overview"]');
        var hasData = !!document.getElementById('stToOverview') || realProjectId() > 0;
        if (hasData && ov) { ov.click(); return; }
        if (typeof DatasetUpload !== 'undefined') {
          DatasetUpload.open({ projectType: 'analysis', kind: 'descriptive', projectId: realProjectId() });
        }
      });
      return b;
    }

    function inject(uploadCard) {
      if (document.getElementById('da-mode-row')) return;
      injectStyles();
      var row = document.createElement('div');
      row.id = 'da-mode-row';
      row.className = 'da-mode-row';
      row.appendChild(makeCard('manual', '&#9776;', 'Self analyze', 'Step through the studio yourself.'));
      row.appendChild(makeCard('auto',   '&#9889;', 'Auto analyze', 'Instant results in a popup you can print or save.'));
      row.appendChild(makeCard('report', '&#9998;', 'Auto report', 'A written report in a popup you can print or save.'));
      // Keep the original Upload card; place the three buttons directly under it.
      if (uploadCard.nextSibling) uploadCard.parentNode.insertBefore(row, uploadCard.nextSibling);
      else uploadCard.parentNode.appendChild(row);
    }

    function scan() {
      var card = document.getElementById('stUpload');
      if (card && !document.getElementById('da-mode-row')) inject(card);
    }

    var obs = new MutationObserver(scan);
    obs.observe(document.documentElement, { childList: true, subtree: true });
    scan();
  } catch (e) { /* never break the studio */ }
})();
