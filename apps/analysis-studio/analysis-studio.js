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
      variables_fit:renderVariablesFit, t_test:renderTTest, anova:renderAnova,
      chi_square:renderChiSquare, correlation:renderCorrelation, regression:renderRegression, effect_sizes:renderEffectSizes };
    const fn = fns[tool];
    if (!fn){ host.innerHTML = header(eyebrow, titleFor(tool), '') + '<div class="as-empty-tool">This analysis is being built.</div>'; return; }
    fn(host, ds, ctx);
  };
  function titleFor(t){ return ({frequencies:'Frequencies',distributions:'Means & Distributions',cross_tabs:'Cross-Tabs',group_summaries:'Group Summaries',top_bottom_items:'Top & Bottom Items',scale_scores:'Scale Scores',variables_fit:'Variables & Fit',t_test:'Independent t-test',anova:'One-way ANOVA',chi_square:'Chi-square',correlation:'Correlation',regression:'Regression',effect_sizes:'Effect Sizes'})[t]||'Analysis'; }

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

  // ---- Inferential helpers ----
  function formatP(p){ if(p<0.0001) return '<.0001'; if(p<0.001) return '<.001'; return p.toFixed(3); }
  function effLabel(label,val){
    if(val==null||!isFinite(val)) return '';
    var thresholds;
    if(label==='cohens_d')   thresholds=[[0.8,'large'],[0.5,'medium'],[0.2,'small']];
    else if(label==='eta_squared') thresholds=[[0.14,'large'],[0.06,'medium'],[0.01,'small']];
    else if(label==='cramers_v')   thresholds=[[0.5,'large'],[0.3,'medium'],[0.1,'small']];
    else if(label==='r_squared')   thresholds=[[0.25,'large'],[0.09,'medium'],[0.01,'small']];
    else return '';
    var a=Math.abs(val);
    for(var i=0;i<thresholds.length;i++){ if(a>=thresholds[i][0]) return thresholds[i][1]; }
    return 'negligible';
  }
  function inferFetch(tool,body,btn,btnLabel,out,onDone){
    btn.disabled=true; btn.textContent='Running…';
    fetch('/api/analysis/infer.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
      .then(function(r){return r.json();})
      .then(function(d){
        btn.disabled=false; btn.textContent='Re-run';
        if(!d||!d.ok){ out.innerHTML='<div class="as-empty-tool">'+esc(d&&d.error?d.error:'Server error — please try again.')+'</div>'; return; }
        onDone(d);
      })
      .catch(function(){
        btn.disabled=false; btn.textContent=btnLabel;
        out.innerHTML='<div class="as-empty-tool">Network error — please try again.</div>';
      });
  }
  function statTable(rows,maxW){
    var body=rows.map(function(r){return '<tr><td class="dx-name">'+esc(r[0])+'</td><td>'+esc(String(r[1]))+'</td></tr>';}).join('');
    return '<div class="dx-scroll" style="margin-bottom:12px"><table class="dx-table"'+(maxW?' style="max-width:'+maxW+'px"':'')+'>'+
      '<thead><tr><th class="l">Statistic</th><th>Value</th></tr></thead><tbody>'+body+'</tbody></table></div>';
  }

  // ---- Variables & Fit ----
  function renderVariablesFit(host,ds,ctx){
    var nv=numericVars(ds),cv=catVars(ds);
    var varBody=ds.variables.map(function(v){
      var kind=isNumericVar(v)?'Numeric':(isCategoricalVar(v)?'Categorical':'Other');
      return '<tr><td class="dx-name">'+esc(v.name)+'</td><td>'+esc((v.types||['—'])[0])+'</td><td>'+kind+'</td><td>'+nonMissing(v.values).length+'</td></tr>';
    }).join('');
    var pairs=[];
    nv.forEach(function(v1){
      nv.forEach(function(v2){ if(v1.name<v2.name) pairs.push({a:v1.name,b:v2.name,test:'Pearson correlation'}); });
      cv.forEach(function(c){
        var levels=new Set(nonMissing(c.values));
        var test=levels.size===2?'Independent t-test':(levels.size>=3?'One-way ANOVA':'— (need 2+ levels)');
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
      {k:'Caution',caution:true,t:'Type detection is heuristic. Review the list and continue to the correct test step — the test itself will flag problems (wrong group count, etc.).'}
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

  // ---- Independent t-test ----
  function renderTTest(host,ds,ctx){
    var nv=numericVars(ds),cv=catVars(ds);
    if(!nv.length||!cv.length){
      host.innerHTML=header('Inferential Analysis','Independent t-test','','t_test')
        +'<div class="as-empty-tool">Need at least one numeric outcome and one grouping variable.</div>'; return;
    }
    sel.tt_out=(sel.tt_out&&varByName(ds,sel.tt_out))?sel.tt_out:nv[0].name;
    sel.tt_grp=(sel.tt_grp&&varByName(ds,sel.tt_grp))?sel.tt_grp:cv[0].name;
    host.innerHTML=header('Inferential Analysis','Independent t-test','Compare the means of two groups on a numeric outcome.','t_test')
      +'<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      +selectField('ttOut','Outcome (numeric)','',nv,sel.tt_out)
      +selectField('ttGrp','Group variable','2 levels',cv,sel.tt_grp)
      +'</div><button class="btn primary" id="ttRun" style="margin-top:12px">Run t-test</button>'
      +'</div></div><div id="ttResult"></div>';
    var outEl=host.querySelector('#ttOut'),grpEl=host.querySelector('#ttGrp');
    if(outEl) outEl.addEventListener('change',function(e){sel.tt_out=e.target.value;});
    if(grpEl) grpEl.addEventListener('change',function(e){sel.tt_grp=e.target.value;});
    var btn=host.querySelector('#ttRun');
    if(btn) btn.addEventListener('click',function(){
      var ov=varByName(ds,sel.tt_out),gv=varByName(ds,sel.tt_grp); if(!ov||!gv) return;
      var n=Math.min(ov.values.length,gv.values.length),vals=[],grps=[];
      for(var i=0;i<n;i++){var v=num(ov.values[i]),g=gv.values[i];if(v!==null&&!isMissing(g)){vals.push(v);grps.push(String(g));}}
      var out=host.querySelector('#ttResult');
      inferFetch('t_test',{tool:'t_test',values:vals,groups:grps},btn,'Run t-test',out,function(d){
        var gkeys=d.details&&d.details.groups?Object.keys(d.details.groups):[];
        var grpRows=gkeys.map(function(g){
          var info=d.details.groups[g];
          return '<tr><td class="dx-name">'+esc(g)+'</td><td>'+info.n+'</td><td>'+n2(info.mean)+'</td><td>'+n2(Math.sqrt(info.var||0))+'</td></tr>';
        }).join('');
        var pStr=formatP(d.p_value),sig=d.p_value<0.05,eff=effLabel(d.effect_label,d.effect_size);
        var sumRows=[['t',n2(d.statistic)],['df',n2(d.df1)],['p',pStr+(sig?' *':' n.s.')],
          ['Cohen\'s d',d.effect_size!=null?n2(d.effect_size)+(eff?' ('+eff+')':''):'—']];
        var layerBlocks=[
          {k:'What this shows',t:esc(d.summary||'')},
          {k:'Why this matters',t:'A significant result (p < .05) means the group difference is unlikely to be sampling noise. Pair with Cohen\'s d for practical significance.'},
          {k:'Caution',caution:true,t:'Welch\'s t-test does not assume equal variances, but assumes roughly normal distributions in each group — especially with small N.'}
        ];
        out.innerHTML='<div class="panel"><div class="panel-h"><h3>Test statistics</h3></div><div class="panel-b">'
          +statTable(sumRows,360)
          +(grpRows?'<div class="dx-scroll" style="margin-bottom:8px"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>SD</th></tr></thead><tbody>'+grpRows+'</tbody></table></div>':'')
          +layers(layerBlocks)+'</div></div>';
        if(ctx&&ctx.onResult) ctx.onResult();
      });
    });
  }

  // ---- One-way ANOVA ----
  function renderAnova(host,ds,ctx){
    var nv=numericVars(ds),cv=catVars(ds);
    if(!nv.length||!cv.length){
      host.innerHTML=header('Inferential Analysis','One-way ANOVA','','anova')
        +'<div class="as-empty-tool">Need at least one numeric outcome and one grouping variable (3+ levels).</div>'; return;
    }
    sel.an_out=(sel.an_out&&varByName(ds,sel.an_out))?sel.an_out:nv[0].name;
    sel.an_grp=(sel.an_grp&&varByName(ds,sel.an_grp))?sel.an_grp:cv[0].name;
    host.innerHTML=header('Inferential Analysis','One-way ANOVA','Test whether a numeric outcome differs across 3 or more groups.','anova')
      +'<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      +selectField('anOut','Outcome (numeric)','',nv,sel.an_out)
      +selectField('anGrp','Group variable','3+ levels',cv,sel.an_grp)
      +'</div><button class="btn primary" id="anRun" style="margin-top:12px">Run ANOVA</button>'
      +'</div></div><div id="anResult"></div>';
    var outEl=host.querySelector('#anOut'),grpEl=host.querySelector('#anGrp');
    if(outEl) outEl.addEventListener('change',function(e){sel.an_out=e.target.value;});
    if(grpEl) grpEl.addEventListener('change',function(e){sel.an_grp=e.target.value;});
    var btn=host.querySelector('#anRun');
    if(btn) btn.addEventListener('click',function(){
      var ov=varByName(ds,sel.an_out),gv=varByName(ds,sel.an_grp); if(!ov||!gv) return;
      var n=Math.min(ov.values.length,gv.values.length),vals=[],grps=[];
      for(var i=0;i<n;i++){var v=num(ov.values[i]),g=gv.values[i];if(v!==null&&!isMissing(g)){vals.push(v);grps.push(String(g));}}
      var out=host.querySelector('#anResult');
      inferFetch('anova',{tool:'anova',values:vals,groups:grps},btn,'Run ANOVA',out,function(d){
        var gkeys=d.details&&d.details.groups?Object.keys(d.details.groups):[];
        var grandMean=d.details&&d.details.grand_mean!=null?d.details.grand_mean:null;
        var grpRows=gkeys.map(function(g){
          var info=d.details.groups[g]; var delta=grandMean!=null?info.mean-grandMean:null;
          return '<tr><td class="dx-name">'+esc(g)+'</td><td>'+info.n+'</td><td>'+n2(info.mean)+'</td>'
            +(delta!=null?'<td class="'+(delta<0?'dx-neg':'dx-pos')+'">'+(delta>0?'+':'')+n2(delta)+'</td>':'<td>—</td>')+'</tr>';
        }).join('');
        var pStr=formatP(d.p_value),sig=d.p_value<0.05,eff=effLabel(d.effect_label,d.effect_size);
        var sumRows=[['F',n2(d.statistic)+' (df₁='+n2(d.df1)+', df₂='+n2(d.df2)+')'],
          ['p',pStr+(sig?' *':' n.s.')],
          ['η²',d.effect_size!=null?n2(d.effect_size)+(eff?' ('+eff+')':''):'—'],['N',String(d.n_total)]];
        var layerBlocks=[
          {k:'What this shows',t:esc(d.summary||'')},
          {k:'Why this matters',t:'A significant F means at least one group mean differs. Pair η² with this result for effect size; a post-hoc test (e.g., Tukey) identifies which groups.'},
          {k:'Caution',caution:true,t:'ANOVA tests the omnibus null only. A significant result does not identify which groups differ — only that at least one does.'}
        ];
        out.innerHTML='<div class="panel"><div class="panel-h"><h3>Test statistics</h3></div><div class="panel-b">'
          +statTable(sumRows,420)
          +(grpRows?'<div class="dx-scroll" style="margin-bottom:8px"><table class="dx-table"><thead><tr><th class="l">Group</th><th>N</th><th>Mean</th><th>Δ from grand mean</th></tr></thead><tbody>'+grpRows+'</tbody></table></div>':'')
          +layers(layerBlocks)+'</div></div>';
        if(ctx&&ctx.onResult) ctx.onResult();
      });
    });
  }

  // ---- Chi-square ----
  function renderChiSquare(host,ds,ctx){
    var cv=catVars(ds);
    if(cv.length<2){
      host.innerHTML=header('Inferential Analysis','Chi-square','','chi_square')
        +'<div class="as-empty-tool">Need at least two categorical variables.</div>'; return;
    }
    sel.cs_a=(sel.cs_a&&varByName(ds,sel.cs_a))?sel.cs_a:cv[0].name;
    sel.cs_b=(sel.cs_b&&varByName(ds,sel.cs_b))?sel.cs_b:cv[1].name;
    host.innerHTML=header('Inferential Analysis','Chi-square','Test whether two categorical variables are independent.','chi_square')
      +'<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      +selectField('csA','Variable A','categorical',cv,sel.cs_a)
      +selectField('csB','Variable B','categorical',cv,sel.cs_b)
      +'</div><button class="btn primary" id="csRun" style="margin-top:12px">Run chi-square</button>'
      +'</div></div><div id="csResult"></div>';
    var elA=host.querySelector('#csA'),elB=host.querySelector('#csB');
    if(elA) elA.addEventListener('change',function(e){sel.cs_a=e.target.value;});
    if(elB) elB.addEventListener('change',function(e){sel.cs_b=e.target.value;});
    var btn=host.querySelector('#csRun');
    if(btn) btn.addEventListener('click',function(){
      var va=varByName(ds,sel.cs_a),vb=varByName(ds,sel.cs_b); if(!va||!vb) return;
      var n=Math.min(va.values.length,vb.values.length),a=[],b=[];
      for(var i=0;i<n;i++){if(!isMissing(va.values[i])&&!isMissing(vb.values[i])){a.push(String(va.values[i]));b.push(String(vb.values[i]));}}
      var out=host.querySelector('#csResult');
      inferFetch('chi_square',{tool:'chi_square',values:a,groups:b},btn,'Run chi-square',out,function(d){
        var pStr=formatP(d.p_value),sig=d.p_value<0.05,eff=effLabel(d.effect_label,d.effect_size);
        var sumRows=[['\u03c7\u00b2',n2(d.statistic)],['df',n2(d.df1)],['p',pStr+(sig?' *':' n.s.')],
          ['Cram\u00e9r\'s V',d.effect_size!=null?n2(d.effect_size)+(eff?' ('+eff+')':''):'—'],['N',String(d.n_total)]];
        var ctHtml='';
        if(d.details&&d.details.rows&&d.details.cols&&d.details.contingency){
          var rkeys=Object.keys(d.details.rows),ckeys=Object.keys(d.details.cols);
          var ctRows=rkeys.map(function(rk){
            var cells=d.details.contingency[rk]||{},rtot=d.details.rows[rk];
            var tds=ckeys.map(function(ck){var v=cells[ck]||0;return '<td>'+v+'</td><td>'+pc(rtot?100*v/rtot:0)+'</td>';}).join('');
            return '<tr><td class="dx-name">'+esc(rk)+'</td>'+tds+'<td>'+rtot+'</td></tr>';
          }).join('');
          var colHdrs=ckeys.map(function(c){return '<th>'+esc(c)+' n</th><th>'+esc(c)+' %</th>';}).join('');
          ctHtml='<div class="dx-scroll" style="margin-top:10px;margin-bottom:8px"><table class="dx-table"><thead><tr>'
            +'<th class="l">'+esc(sel.cs_a)+'</th>'+colHdrs+'<th>Total</th></tr></thead><tbody>'+ctRows+'</tbody></table></div>';
        }
        var layerBlocks=[
          {k:'What this shows',t:esc(d.summary||'')},
          {k:'Why this matters',t:'A significant result means the two variables are not distributed independently. Cramér\'s V quantifies the strength of the association.'},
          {k:'Caution',caution:true,t:'Chi-square is unreliable when expected cell counts fall below 5. Check that your sample is large enough for the number of categories.'}
        ];
        out.innerHTML='<div class="panel"><div class="panel-h"><h3>Test statistics</h3></div><div class="panel-b">'
          +statTable(sumRows,360)+ctHtml+layers(layerBlocks)+'</div></div>';
        if(ctx&&ctx.onResult) ctx.onResult();
      });
    });
  }

  // ---- Correlation ----
  function renderCorrelation(host,ds,ctx){
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Correlation','','correlation')
        +'<div class="as-empty-tool">Need at least two numeric variables.</div>'; return;
    }
    sel.cor_x=(sel.cor_x&&varByName(ds,sel.cor_x))?sel.cor_x:nv[0].name;
    sel.cor_y=(sel.cor_y&&varByName(ds,sel.cor_y))?sel.cor_y:nv[1].name;
    host.innerHTML=header('Inferential Analysis','Correlation','Measure the linear relationship between two numeric variables.','correlation')
      +'<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      +selectField('corX','Variable X','numeric',nv,sel.cor_x)
      +selectField('corY','Variable Y','numeric',nv,sel.cor_y)
      +'</div><button class="btn primary" id="corRun" style="margin-top:12px">Run correlation</button>'
      +'</div></div><div id="corResult"></div>';
    var elX=host.querySelector('#corX'),elY=host.querySelector('#corY');
    if(elX) elX.addEventListener('change',function(e){sel.cor_x=e.target.value;});
    if(elY) elY.addEventListener('change',function(e){sel.cor_y=e.target.value;});
    var btn=host.querySelector('#corRun');
    if(btn) btn.addEventListener('click',function(){
      var vx=varByName(ds,sel.cor_x),vy=varByName(ds,sel.cor_y); if(!vx||!vy) return;
      var n=Math.min(vx.values.length,vy.values.length),xs=[],ys=[];
      for(var i=0;i<n;i++){var xi=num(vx.values[i]),yi=num(vy.values[i]);if(xi!==null&&yi!==null){xs.push(xi);ys.push(yi);}}
      var out=host.querySelector('#corResult');
      inferFetch('correlation',{tool:'correlation',x:xs,y:ys},btn,'Run correlation',out,function(d){
        var pStr=formatP(d.p_value),sig=d.p_value<0.05;
        var rabs=Math.abs(d.statistic);
        var rStrength=rabs>=0.7?'strong':(rabs>=0.4?'moderate':(rabs>=0.2?'weak':'negligible'));
        var rDir=d.statistic>=0?'positive':'negative';
        var sumRows=[['r',n2(d.statistic)],['r\u00b2',d.effect_size!=null?n2(d.effect_size):'—'],
          ['df',n2(d.df1)],['p',pStr+(sig?' *':' n.s.')],['N',String(d.n_total)]];
        var layerBlocks=[
          {k:'What this shows',t:esc(d.summary||'')},
          {k:'Why this matters',t:'r describes direction and strength. r\u00b2 = '+n2(d.effect_size||0)+' means '+n2((d.effect_size||0)*100)+'% of variance in one variable is explained by the other.'},
          {k:'Caution',caution:true,t:'Pearson r measures linear association only. Non-linear relationships and outliers can distort or hide a real association.'}
        ];
        out.innerHTML='<div class="panel"><div class="panel-h"><h3>Pearson r: '+esc(sel.cor_x)+' \u00d7 '+esc(sel.cor_y)+'</h3></div><div class="panel-b">'
          +'<p style="color:var(--ink-2);font-size:13px;margin:0 0 10px">'+rStrength.charAt(0).toUpperCase()+rStrength.slice(1)+' '+rDir+' '+(sig?'significant':'non-significant')+' correlation</p>'
          +statTable(sumRows,360)+layers(layerBlocks)+'</div></div>';
        if(ctx&&ctx.onResult) ctx.onResult();
      });
    });
  }

  // ---- Regression (simple OLS via server) ----
  function renderRegression(host,ds,ctx){
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Regression','','regression')
        +'<div class="as-empty-tool">Need at least two numeric variables.</div>'; return;
    }
    sel.reg_y=(sel.reg_y&&varByName(ds,sel.reg_y))?sel.reg_y:nv[0].name;
    sel.reg_x=(sel.reg_x&&varByName(ds,sel.reg_x))?sel.reg_x:nv[1].name;
    host.innerHTML=header('Inferential Analysis','Regression','Predict a numeric outcome from a numeric predictor (simple OLS).','regression')
      +'<div class="panel"><div class="panel-b"><div class="as-pickgrid">'
      +selectField('regY','Outcome (Y)','numeric',nv,sel.reg_y)
      +selectField('regX','Predictor (X)','numeric',nv,sel.reg_x)
      +'</div><button class="btn primary" id="regRun" style="margin-top:12px">Run regression</button>'
      +'</div></div><div id="regResult"></div>';
    var elY=host.querySelector('#regY'),elX=host.querySelector('#regX');
    if(elY) elY.addEventListener('change',function(e){sel.reg_y=e.target.value;});
    if(elX) elX.addEventListener('change',function(e){sel.reg_x=e.target.value;});
    var btn=host.querySelector('#regRun');
    if(btn) btn.addEventListener('click',function(){
      var vy=varByName(ds,sel.reg_y),vx=varByName(ds,sel.reg_x); if(!vy||!vx) return;
      var n=Math.min(vy.values.length,vx.values.length),xs=[],ys=[];
      for(var i=0;i<n;i++){var xi=num(vx.values[i]),yi=num(vy.values[i]);if(xi!==null&&yi!==null){xs.push(xi);ys.push(yi);}}
      var out=host.querySelector('#regResult');
      inferFetch('regression',{tool:'regression',x:xs,y:ys},btn,'Run regression',out,function(d){
        var det=d.details||{};
        var slope=det.slope||0,intercept=det.intercept||0,r2=det.r_squared||0;
        var pStr=formatP(d.p_value),sig=d.p_value<0.05,eff=effLabel('r_squared',r2);
        var eqn='Ŷ = '+n2(intercept)+(slope>=0?' + ':' − ')+n2(Math.abs(slope))+' \u00d7 '+esc(sel.reg_x);
        var sumRows=[['Intercept (a)',n2(intercept)],['Slope (b)',n2(slope)],['R\u00b2',n2(r2)+(eff?' ('+eff+')':'')],
          ['t (slope)',n2(d.statistic)],['df',n2(d.df1)],['p',pStr+(sig?' *':' n.s.')],['N',String(d.n_total)]];
        var layerBlocks=[
          {k:'What this shows',t:'Simple OLS regression of '+esc(sel.reg_y)+' on '+esc(sel.reg_x)+'. Equation: '+eqn
            +'. R\u00b2 = '+n2(r2)+' ('+n2(r2*100)+'% of variance in Y explained by X).'},
          {k:'Why this matters',t:'The slope tells you how much Y changes per 1-unit increase in X. '
            +(slope>=0?'Positive slope: Y rises as X increases.':'Negative slope: Y falls as X increases.')},
          {k:'Caution',caution:true,t:'This is a simple bivariate OLS model with no controls. Results may reflect confounds. Verify linearity before reporting.'}
        ];
        out.innerHTML='<div class="panel"><div class="panel-h"><h3>'+esc(sel.reg_y)+' ~ '+esc(sel.reg_x)+'</h3></div><div class="panel-b">'
          +'<p style="font-size:13px;color:var(--ink-2);margin:0 0 10px;font-style:italic">'+eqn+'</p>'
          +statTable(sumRows,380)+layers(layerBlocks)+'</div></div>';
        if(ctx&&ctx.onResult) ctx.onResult();
      });
    });
  }

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
          rows.push({outcome:ov.name,group:gv.name,test:'t-test',effect:'Cohen\'s d',value:d,label:effLabel('cohens_d',d)});
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
      var badge=r.label?'<span class="eff-badge eff-'+esc(r.label)+'">'+esc(r.label)+'</span>':'';
      return '<tr><td class="dx-name">'+esc(r.outcome)+'</td><td class="dx-name">'+esc(r.group)+'</td>'
        +'<td>'+esc(r.test)+'</td><td>'+esc(r.effect)+'</td><td>'+n2(r.value)+' '+badge+'</td></tr>';
    }).join(''):'<tr><td colspan="5">No effect sizes computed — need numeric outcomes paired with grouping or numeric variables.</td></tr>';
    var layerBlocks=[
      {k:'What this shows',t:'Effect sizes for all variable pairs — Cohen\'s d (2-group), \u03b7\u00b2 (3+ groups), r\u00b2 (numeric pairs). Sorted largest to smallest.'},
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
