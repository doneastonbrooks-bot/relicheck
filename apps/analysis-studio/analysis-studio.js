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
      variables_fit:renderVariablesFit, descriptive:renderDescriptive, t_test:renderTTest, anova:renderAnova,
      chi_square:renderChiSquare, correlation:renderCorrelation, regression:renderRegression, effect_sizes:renderEffectSizes };
    const fn = fns[tool];
    if (!fn){ host.innerHTML = header(eyebrow, titleFor(tool), '') + '<div class="as-empty-tool">This analysis is being built.</div>'; return; }
    fn(host, ds, ctx);
  };
  function titleFor(t){ return ({frequencies:'Frequencies',distributions:'Means & Distributions',cross_tabs:'Cross-Tabs',group_summaries:'Group Summaries',top_bottom_items:'Top & Bottom Items',scale_scores:'Scale Scores',variables_fit:'Variables & Fit',descriptive:'Descriptive Analysis',t_test:'Independent t-test',anova:'One-way ANOVA',chi_square:'Chi-square',correlation:'Correlation',regression:'Regression',effect_sizes:'Effect Sizes'})[t]||'Analysis'; }

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
    var fnMap={t_test:'ttTab',anova:'anTab',chi_square:'csqTab',correlation:'corTab',regression:'regTab',descriptive:'descTab'};
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
  var tt={grp:null,g1:'',g2:'',testType:'auto',conf:0.95,result:null,tab:'desc',busy:false};

  // ---- Independent t-test ----
  function renderTTest(host,ds,ctx){
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
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and the two groups to compare</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
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
  var an={result:null,tab:'desc',busy:false};

  // ---- One-way ANOVA ----
  function renderAnova(host,ds,ctx){
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
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the outcome and a grouping with 3 or more categories</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Outcome variable <span class="tt-hint">numeric</span></label><select class="ed-in" id="anOut">'+outOpts+'</select></div>'
      +'<div class="field"><label>Grouping variable <span class="tt-hint">3+ categories</span></label><select class="ed-in" id="anGrp">'+grpOpts+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="anRun"'+(an.busy?' disabled':'')+'>'+(an.busy?'Running…':'▷ Run ANOVA')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','One-way ANOVA','Compare the mean of one outcome across three or more groups.','anova')
      +setup+'<div id="anResults">'+(an.result?renderAnovaResults(an.result,ctx):'')+'</div>';
    var elOut=host.querySelector('#anOut'),elGrp=host.querySelector('#anGrp');
    if(elOut) elOut.addEventListener('change',function(e){sel.an_out=e.target.value;});
    if(elGrp) elGrp.addEventListener('change',function(e){sel.an_grp=e.target.value;});
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
  var cor={result:null,tab:'desc',busy:false};

  // ---- Correlation ----
  function renderCorrelation(host,ds,ctx){
    var nv=numericVars(ds);
    if(nv.length<2){
      host.innerHTML=header('Inferential Analysis','Correlation','','correlation')
        +'<div class="as-empty-tool">Need at least two numeric variables.</div>'; return;
    }
    if(!sel.cor_x||!varByName(ds,sel.cor_x)) sel.cor_x=nv[0].name;
    if(!sel.cor_y||!varByName(ds,sel.cor_y)||sel.cor_y===sel.cor_x) sel.cor_y=nv[1]?nv[1].name:nv[0].name;
    var opt=function(cur){return nv.map(function(v){return '<option value="'+esc(v.name)+'"'+(v.name===cur?' selected':'')+'>'+esc(v.name)+'</option>';}).join('');};
    var setup='<div class="panel"><div class="panel-h"><div><h3>Setup</h3><div class="ph-sub">Pick the two numeric variables to relate</div></div></div>'
      +'<div class="panel-b"><div class="tt-grid">'
      +'<div class="field"><label>Variable 1 <span class="tt-hint">numeric</span></label><select class="ed-in" id="corX">'+opt(sel.cor_x)+'</select></div>'
      +'<div class="field"><label>Variable 2 <span class="tt-hint">numeric</span></label><select class="ed-in" id="corY">'+opt(sel.cor_y)+'</select></div>'
      +'</div><div class="run-actions"><button class="btn primary" id="corRun"'+(cor.busy?' disabled':'')+'>'+(cor.busy?'Running…':'▷ Run correlation')+'</button></div>'
      +'</div></div>';
    host.innerHTML=header('Inferential Analysis','Correlation','See whether two numbers move together, and how strongly.','correlation')
      +setup+'<div id="corResults">'+(cor.result?renderCorResults(cor.result,ctx):'')+'</div>';
    var elX=host.querySelector('#corX'),elY=host.querySelector('#corY');
    if(elX) elX.addEventListener('change',function(e){sel.cor_x=e.target.value;});
    if(elY) elY.addEventListener('change',function(e){sel.cor_y=e.target.value;});
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
      var badge=r.label?'<span class="eff-badge eff-'+esc(r.label)+'">'+esc(r.label)+'</span>':'';
      return '<tr><td class="dx-name">'+esc(r.outcome)+'</td><td class="dx-name">'+esc(r.group)+'</td>'
        +'<td>'+esc(r.test)+'</td><td>'+esc(r.effect)+'</td><td>'+n2(r.value)+' '+badge+'</td></tr>';
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
