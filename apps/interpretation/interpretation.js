// ReliCheck Interpretation Suite
// -------------------------------------------------------------------
// One engine, eight lenses. Reads saved blocks from localStorage and
// the current dataset, applies the lens, renders prose/lists into the
// shared markup. The mount page selects the lens via:
//   window.INTERPRETATION_LENS = 'key_findings' | ...
//
// Storage shape (from [[relicheck-reports-model]]):
//   relicheck.report.<project_id>.<report_id>  (report_id = 'default' in v1)
//   { project, projectId, reportId, studio, blocks: [
//       { id, addedAt, studio, project, app, appName, summary, payload }
//   ] }
// Each block.payload is the window.RELICHECK_APP_STATE at save time.
//
// Lens roster:
//   ai_interpretation     plain-language read of any one block
//   key_findings          ranks blocks, surfaces the 3-5 most important
//   practical_significance translates effect sizes into real-world terms
//   limitations           scans blocks + dataset for caveats
//   recommended_actions   turns findings into next steps
//   teaching_moments      explains the methods used in the saved blocks
//   decision_readiness    judges whether evidence supports decisions
//   evidence_alignment    maps findings against the project purpose

(function () {
  'use strict';

  // ==================================================================
  // Resolve project, blocks, dataset
  // ==================================================================
  const projectId = (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID)) || 'untitled-project';
  const reportId  = 'default';
  const storageKey = 'relicheck.report.' + projectId + '.' + reportId;

  let blocks = [];
  try {
    const raw = window.localStorage.getItem(storageKey);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.blocks)) blocks = parsed.blocks;
    }
  } catch (e) {
    console.warn('Interpretation: could not parse saved blocks:', e);
  }

  let dataset = window.INTERPRETATION_DATASET;
  try {
    const ds = window.localStorage.getItem('relicheck.dataset.' + projectId);
    if (ds) {
      const parsed = JSON.parse(ds);
      if (parsed && parsed.payload && parsed.payload.dataset) dataset = parsed.payload.dataset;
    }
  } catch (e) { /* noop */ }

  const lens = window.INTERPRETATION_LENS || 'key_findings';

  // ==================================================================
  // Lens registry: name, sub, and renderer
  // ==================================================================
  const LENSES = {
    ai_interpretation: {
      name: 'AI Interpretation',
      sub:  'Plain-language read of the most recently saved analysis.',
      render: renderAIInterpretation,
      empty: 'AI Interpretation reads the most recently saved result and writes a plain-English explanation. Save something first.',
    },
    key_findings: {
      name: 'Key Findings',
      sub:  'The most important results from everything saved into this report.',
      render: renderKeyFindings,
      empty: 'Key Findings ranks the saved analyses and surfaces the top 3-5. Save at least one result first.',
    },
    practical_significance: {
      name: 'Practical Significance',
      sub:  'Translates statistical effects into real-world meaning.',
      render: renderPracticalSignificance,
      empty: 'Practical Significance reads your saved effect sizes (d, r², η², OR) and translates them into plain terms. Save a test or effect-size result first.',
    },
    limitations: {
      name: 'Limitations',
      sub:  'Sample-size, missingness, reliability, and overclaim risks.',
      render: renderLimitations,
      empty: 'Limitations scans saved blocks and the current dataset for caveats. Save at least one analysis first.',
    },
    recommended_actions: {
      name: 'Recommended Actions',
      sub:  'Findings, turned into concrete next steps.',
      render: renderRecommendedActions,
      empty: 'Recommended Actions turns saved findings into next steps. Save an analysis first.',
    },
    teaching_moments: {
      name: 'Teaching Moments',
      sub:  'Plain-language explanations of the methods used.',
      render: renderTeachingMoments,
      empty: 'Teaching Moments explains the method used in the most recently saved analysis. Save a result first.',
    },
    decision_readiness: {
      name: 'Decision Readiness',
      sub:  'Is this evidence strong enough for exploratory, operational, or high-stakes use?',
      render: renderDecisionReadiness,
      empty: 'Decision Readiness judges whether the saved analyses are strong enough to act on. Save at least one analysis first.',
    },
    evidence_alignment: {
      name: 'Evidence Alignment',
      sub:  "Do the findings support, complicate, or contradict the project's purpose?",
      render: renderEvidenceAlignment,
      empty: "Evidence Alignment compares saved findings against the project's stated purpose. Save at least one analysis first.",
    },
  };
  const L = LENSES[lens] || LENSES.key_findings;

  // ==================================================================
  // Wire up header & source ribbon
  // ==================================================================
  const els = {
    sourceRibbon:    document.getElementById('interpSource'),
    sourceLabel:     document.getElementById('interpSourceLabel'),
    sourceMeta:      document.getElementById('interpSourceMeta'),
    sourceCta:       document.getElementById('interpSourceCta'),
    lensName:        document.getElementById('interpLensName'),
    headline:        document.getElementById('interpHeadline'),
    sub:             document.getElementById('interpSub'),
    empty:           document.getElementById('interpEmpty'),
    body:            document.getElementById('interpBody'),
    foot:            document.getElementById('interpFoot'),
    next:            document.getElementById('interpNext'),
    aiHook:          document.getElementById('interpAiHook'),
    aiNote:          document.getElementById('interpAiNote'),
  };
  if (els.lensName) els.lensName.textContent = L.name;
  if (els.sub)      els.sub.textContent      = L.sub;

  if (blocks.length === 0) {
    if (els.sourceRibbon) els.sourceRibbon.setAttribute('data-source', 'empty');
    if (els.sourceLabel)  els.sourceLabel.textContent = 'No saved results yet';
    if (els.sourceMeta)   els.sourceMeta.textContent  = 'Save an analysis to fill this lens.';
    if (els.headline)     els.headline.textContent    = L.empty;
    if (els.empty)        els.empty.hidden            = false;
    if (els.foot)         els.foot.hidden             = true;
    exposeAppState({ lens: lens, empty: true });
    return;
  }

  if (els.sourceRibbon) els.sourceRibbon.setAttribute('data-source', 'ready');
  if (els.sourceLabel)  els.sourceLabel.textContent = blocks.length + ' saved ' + (blocks.length === 1 ? 'analysis' : 'analyses');
  if (els.sourceMeta)   els.sourceMeta.textContent  = 'Latest: ' + (blocks[blocks.length - 1].appName || blocks[blocks.length - 1].app);
  if (els.sourceCta)    els.sourceCta.textContent   = 'Add another';
  if (els.empty)        els.empty.hidden = true;
  if (els.foot)         els.foot.hidden  = false;

  // Run the lens
  const state = L.render(blocks, dataset, els) || {};
  exposeAppState(Object.assign({ lens: lens, blockCount: blocks.length }, state));

  // Show AI upgrade hook on every lens (it's an aspirational note,
  // not a blocker on the current rule-based output).
  if (els.aiHook) els.aiHook.hidden = false;

  // ==================================================================
  // LENS: ai_interpretation
  // ==================================================================
  function renderAIInterpretation(blocks, dataset, els) {
    const b = blocks[blocks.length - 1];
    els.headline.textContent = 'Reading: ' + (b.appName || b.app);
    const paras = [];
    paras.push(plainSummary(b));
    paras.push(plainImplication(b));
    const c = plainCaveat(b);
    if (c) paras.push(c);
    els.body.innerHTML = '<div class="interp-prose">' +
      paras.map(p => '<p>' + esc(p) + '</p>').join('') +
    '</div>';
    els.next.textContent = 'Open ' + (b.appName || b.app) + ' to make changes, or pick a different lens for a different angle.';
    if (els.aiNote) els.aiNote.textContent = 'The rule-based pass above is templated. An AI pass that reads the same payload and writes a narrative is the planned upgrade.';
    return { headline: b.appName || b.app, paragraphs: paras };
  }

  // ==================================================================
  // LENS: key_findings
  // ==================================================================
  function renderKeyFindings(blocks, dataset, els) {
    const ranked = blocks.map(rankFinding)
                         .filter(f => f.score > 0)
                         .sort((a, b) => b.score - a.score)
                         .slice(0, 5);
    els.headline.textContent = ranked.length
      ? 'Top ' + ranked.length + ' ' + (ranked.length === 1 ? 'finding' : 'findings')
      : 'No standout findings yet';
    if (!ranked.length) {
      els.body.innerHTML = '<p class="interp-flat">Your saved analyses do not have a standout result. Each one is in the "noteworthy" middle. Read them in detail rather than scanning headlines.</p>';
      els.next.textContent = 'Run a new analysis on a specific question or group to look for a clearer pattern.';
      return { rankedCount: 0 };
    }
    els.body.innerHTML = '<ol class="interp-rank">' + ranked.map(f =>
      '<li class="interp-rank-item" data-tone="' + esc(f.tone) + '">' +
        '<div class="interp-rank-eyebrow">' + esc(f.appName) + '</div>' +
        '<div class="interp-rank-line">' + esc(f.line) + '</div>' +
        '<div class="interp-rank-note">' + esc(f.note) + '</div>' +
      '</li>'
    ).join('') + '</ol>';
    els.next.textContent = 'Carry these findings into the Practical Significance lens to translate the numbers into real-world terms, or into Recommended Actions for next steps.';
    return { ranked: ranked.map(f => ({ app: f.appName, line: f.line, score: f.score })) };
  }

  // ==================================================================
  // LENS: practical_significance
  // ==================================================================
  function renderPracticalSignificance(blocks, dataset, els) {
    const items = [];
    blocks.forEach(b => {
      const p = b.payload || {};
      if (b.app === 'inferential' && p.result && p.result.effect) {
        items.push(translateEffect(b.appName, p.result.effect, p.test, p.variables, p.result));
      } else if (b.app === 'effect_size' && p.result) {
        items.push(translateEffect(b.appName, { name: p.result.name, value: p.result.value, band: p.result.band, tone: p.result.tone }, null, null, p.result));
      } else if (b.app === 'strength_index' && p.strength != null) {
        items.push({
          appName: b.appName,
          kind:    'strength_index',
          headline: 'Strength score ' + p.strength + ' (' + p.verdict + ')',
          body:    interpretStrength(p.strength, p.verdict),
          tone:    p.strength >= 80 ? 'strong' : p.strength >= 70 ? 'ok' : p.strength >= 60 ? 'warn' : 'alert',
        });
      } else if (b.app === 'open_ended_summary' && p.signals) {
        const s = p.signals;
        items.push({
          appName: b.appName,
          kind:    'qual',
          headline: Math.round((s.response_rate || 0) * 100) + '% wrote something, averaging ' + (s.mean_words || 0).toFixed(1) + ' words',
          body:     interpretQual(s),
          tone:    s.response_rate >= 0.70 ? 'strong' : s.response_rate >= 0.40 ? 'ok' : s.response_rate >= 0.10 ? 'warn' : 'alert',
        });
      }
    });
    els.headline.textContent = items.length
      ? 'Practical reading of ' + items.length + ' ' + (items.length === 1 ? 'result' : 'results')
      : 'No quantitative effects to translate yet';
    if (!items.length) {
      els.body.innerHTML = '<p class="interp-flat">None of the saved analyses have a numerical effect to translate. Save a t-test, ANOVA, correlation, chi-square, effect-size, strength-index, or open-ended-summary result and come back.</p>';
      els.next.textContent = 'Once you have effects to translate, this lens turns them into plain-English statements about what they mean for a person on the ground.';
      return { items: [] };
    }
    els.body.innerHTML = '<div class="interp-cards">' + items.map(it =>
      '<div class="interp-card-item" data-tone="' + esc(it.tone) + '">' +
        '<div class="interp-card-eyebrow">' + esc(it.appName) + '</div>' +
        '<div class="interp-card-headline">' + esc(it.headline) + '</div>' +
        '<p class="interp-card-body">' + esc(it.body) + '</p>' +
      '</div>'
    ).join('') + '</div>';
    els.next.textContent = 'These translations are conventional. If a stakeholder is comfortable with effect sizes, present both the raw size and this plain reading.';
    return { items: items.map(it => ({ appName: it.appName, kind: it.kind, headline: it.headline })) };
  }

  // ==================================================================
  // LENS: limitations
  // ==================================================================
  function renderLimitations(blocks, dataset, els) {
    const flags = [];
    // Dataset-level
    const rowCount = (dataset && dataset.rowCount) || 0;
    if (rowCount && rowCount < 30) flags.push({ tone: 'warn', txt: 'Small sample (n = ' + rowCount + '). Treat any inferential test as exploratory until you have at least n = 30 per group.' });
    if (rowCount && rowCount < 10) flags.push({ tone: 'alert', txt: 'Very small sample (n = ' + rowCount + '). p-values are unreliable; report findings as descriptive rather than statistical.' });

    // Block-level
    let sigCount = 0;
    blocks.forEach(b => {
      const p = b.payload || {};
      if (b.app === 'strength_index' && p.components) {
        const rel = p.components.reliability;
        if (rel && rel.score != null && rel.score < 70) {
          flags.push({ tone: 'alert', txt: 'Reliability is below "usable" (' + Math.round(rel.score) + '/100). Findings on the affected scale should not be reported as conclusions yet.' });
        }
        const itemQ = p.components.item_quality;
        if (itemQ && itemQ.score != null && itemQ.score < 70) {
          flags.push({ tone: 'warn', txt: 'Item Quality flagged (' + Math.round(itemQ.score) + '/100): ceiling/floor, low-variance, or skewed items are present.' });
        }
      }
      if (b.app === 'inferential' && p.result) {
        if (p.result.p < 0.05) sigCount++;
        if (p.result.assumptions) {
          p.result.assumptions.forEach(a => {
            if (a.ok === 'warn' || a.ok === false) {
              flags.push({ tone: 'warn', txt: '[' + (b.appName || b.app) + '] ' + a.msg });
            }
          });
        }
      }
      if (b.app === 'open_ended_summary' && p.aggregate) {
        const ag = p.aggregate;
        if (ag.responseRate != null && ag.responseRate < 0.30) {
          flags.push({ tone: 'warn', txt: 'Open-ended response rate is only ' + Math.round(ag.responseRate * 100) + '%. Themes drawn from this material are tentative.' });
        }
      }
      if (b.app === 'effect_size' && p.result) {
        if (p.result.detail && p.result.detail.sparseCorrected) {
          flags.push({ tone: 'warn', txt: 'The odds ratio in [' + (b.appName || b.app) + '] used a Haldane 0.5 correction (sparse cell). Treat the CI as approximate.' });
        }
      }
    });
    // Multiple comparisons
    if (sigCount > 1) {
      flags.push({ tone: 'warn', txt: 'You have ' + sigCount + ' statistically significant tests. With multiple comparisons, the family-wise error rate inflates; consider a Bonferroni or Benjamini-Hochberg correction before reporting any single p-value as definitive.' });
    }

    els.headline.textContent = flags.length
      ? flags.length + ' ' + (flags.length === 1 ? 'limitation' : 'limitations') + ' to disclose'
      : 'No major limitations flagged';
    if (!flags.length) {
      els.body.innerHTML = '<p class="interp-flat">No automatic flags. Standard caveats still apply: sample size, single dataset, possible unmeasured confounders. Add them by hand to your write-up.</p>';
      els.next.textContent = 'Move to Recommended Actions to plan next steps.';
      return { flags: [] };
    }
    els.body.innerHTML = '<ul class="interp-flags">' + flags.map(f =>
      '<li class="interp-flag-item" data-tone="' + esc(f.tone) + '">' +
        '<span class="interp-flag-pip" aria-hidden="true"></span>' +
        '<span>' + esc(f.txt) + '</span>' +
      '</li>'
    ).join('') + '</ul>';
    els.next.textContent = 'Lift these caveats into the report\'s Limitations section. Reviewers will look for an explicit acknowledgment of small-n, missingness, and reliability concerns.';
    return { flags: flags.map(f => ({ tone: f.tone, txt: f.txt })) };
  }

  // ==================================================================
  // LENS: recommended_actions
  // ==================================================================
  function renderRecommendedActions(blocks, dataset, els) {
    const actions = [];
    blocks.forEach(b => {
      const p = b.payload || {};
      if (b.app === 'inferential' && p.result) {
        const r = p.result;
        if (r.p < 0.05) {
          if (r.test === 'One-way ANOVA') actions.push({ tone: 'strong', txt: 'Run a post-hoc comparison (Tukey HSD or Games-Howell) on ' + (p.variables ? p.variables[0] + ' by ' + p.variables[1] : 'the saved ANOVA') + ' to see which pairs differ.' });
          else if (r.test.indexOf('t-test') !== -1) actions.push({ tone: 'ok', txt: 'Visualize the group means with error bars and add the t-test to the report.' });
          else if (r.test.indexOf('Chi-square') !== -1) actions.push({ tone: 'ok', txt: 'Inspect standardized residuals on the chi-square to identify which cells drive the association.' });
          else if (r.test === 'Pearson correlation') actions.push({ tone: 'ok', txt: 'Inspect a scatterplot to confirm linearity, then consider whether to report the regression line.' });
        } else {
          actions.push({ tone: 'warn', txt: 'Null result in ' + (b.appName || b.app) + ': confirm sample size is adequate, or reframe the question for a different test.' });
        }
      }
      if (b.app === 'strength_index' && p.components) {
        // Find lowest-scoring domain
        let lowest = null;
        Object.keys(p.components).forEach(k => {
          const c = p.components[k];
          if (c && c.score != null) {
            if (!lowest || c.score < lowest.score) lowest = { key: k, score: c.score };
          }
        });
        if (lowest && lowest.score < 80) {
          const map = { reliability: 'Internal Consistency', factor_structure: 'Factor Structure', item_quality: 'Item Quality', response_quality: 'Response Quality', open_ended: 'Open-Ended Alignment', actionability: 'Actionability' };
          actions.push({ tone: 'warn', txt: 'Address the weakest Strength Index domain first: ' + (map[lowest.key] || lowest.key) + ' (score = ' + Math.round(lowest.score) + ').' });
        }
        if (p.strength >= 80) {
          actions.push({ tone: 'strong', txt: 'Strength Index is ' + p.strength + ' (' + p.verdict + '). The instrument is publication-ready; move to the Report Builder.' });
        }
      }
      if (b.app === 'open_ended_summary' && p.aggregate && p.aggregate.answers >= 10) {
        actions.push({ tone: 'ok', txt: 'Open-ended responses are substantive (' + p.aggregate.answers + ' answers, avg ' + (p.aggregate.avgWords || 0).toFixed(1) + ' words). Move to Theme Analysis to code them.' });
      }
      if (b.app === 'effect_size' && p.result && (p.result.tone === 'ok' || p.result.tone === 'strong')) {
        actions.push({ tone: 'ok', txt: '[' + (b.appName || b.app) + '] reports a ' + p.result.band + ' ' + p.result.name + ' = ' + (p.result.value != null ? p.result.value.toFixed(2) : '—') + '. Include the size alongside the p-value when reporting.' });
      }
    });
    // De-duplicate
    const seen = new Set();
    const uniq = [];
    actions.forEach(a => { if (!seen.has(a.txt)) { seen.add(a.txt); uniq.push(a); } });
    els.headline.textContent = uniq.length
      ? uniq.length + ' recommended ' + (uniq.length === 1 ? 'next step' : 'next steps')
      : 'No specific next steps yet';
    if (!uniq.length) {
      els.body.innerHTML = '<p class="interp-flat">The saved analyses are mostly neutral. Move to Decision Readiness to decide whether the evidence is strong enough as it stands.</p>';
      els.next.textContent = 'Or save a few more analyses (an inferential test, an effect size) to generate concrete next steps.';
      return { actions: [] };
    }
    els.body.innerHTML = '<ol class="interp-actions">' + uniq.map(a =>
      '<li class="interp-action-item" data-tone="' + esc(a.tone) + '">' +
        '<span class="interp-action-pip" aria-hidden="true"></span>' +
        '<span>' + esc(a.txt) + '</span>' +
      '</li>'
    ).join('') + '</ol>';
    els.next.textContent = 'Pick the action with the biggest payoff (post-hoc after a significant ANOVA, the weakest Strength Index domain) and tackle it next.';
    return { actions: uniq.map(a => ({ tone: a.tone, txt: a.txt })) };
  }

  // ==================================================================
  // LENS: teaching_moments
  // ==================================================================
  function renderTeachingMoments(blocks, dataset, els) {
    const b = blocks[blocks.length - 1];
    els.headline.textContent = 'Explaining: ' + (b.appName || b.app);
    const body = methodExplainer(b);
    els.body.innerHTML = '<div class="interp-prose">' + body.map(p => '<p>' + p + '</p>').join('') + '</div>';
    els.next.textContent = 'Pair this explanation with the saved result for non-technical readers in the report\'s Methodology section.';
    return { method: b.appName || b.app, paragraphs: body };
  }

  // ==================================================================
  // LENS: decision_readiness
  // ==================================================================
  function renderDecisionReadiness(blocks, dataset, els) {
    // Score the body of evidence on a few axes
    let evidence = 0; let watch = [];
    let strengthHigh = false, strengthLow = false;
    let inferentialPositive = 0, inferentialNull = 0;
    let openEndedRich = false;
    blocks.forEach(b => {
      const p = b.payload || {};
      if (b.app === 'strength_index' && p.strength != null) {
        if (p.strength >= 80) { evidence += 3; strengthHigh = true; }
        else if (p.strength >= 70) { evidence += 1.5; }
        else { evidence -= 1; strengthLow = true; watch.push('Strength Index ' + p.strength + ' (' + p.verdict + ')'); }
      }
      if (b.app === 'inferential' && p.result) {
        if (p.result.p < 0.05 && (p.result.effect.tone === 'ok' || p.result.effect.tone === 'strong')) { evidence += 2; inferentialPositive++; }
        else if (p.result.p < 0.05) { evidence += 0.5; inferentialPositive++; }
        else { inferentialNull++; }
      }
      if (b.app === 'effect_size' && p.result) {
        if (p.result.tone === 'ok' || p.result.tone === 'strong') evidence += 1;
      }
      if (b.app === 'open_ended_summary' && p.aggregate) {
        if (p.aggregate.responseRate >= 0.50 && p.aggregate.avgWords >= 8) { evidence += 1; openEndedRich = true; }
        else if (p.aggregate.responseRate < 0.20) watch.push('Open-ended response rate ' + Math.round((p.aggregate.responseRate || 0) * 100) + '%');
      }
    });
    const rowCount = (dataset && dataset.rowCount) || 0;
    if (rowCount && rowCount < 30) { evidence -= 1; watch.push('n = ' + rowCount); }
    if (blocks.length >= 4) evidence += 0.5;

    let band, tone, headline;
    if (evidence >= 5)      { band = 'High-stakes ready';   tone = 'strong'; headline = 'Evidence is strong enough to support consequential decisions.'; }
    else if (evidence >= 3) { band = 'Operational';          tone = 'ok';     headline = 'Evidence is good enough for operational decisions, with caveats.'; }
    else if (evidence >= 1) { band = 'Exploratory';          tone = 'warn';   headline = 'Evidence supports exploratory reading; not yet decision-ready.'; }
    else                    { band = 'Insufficient';         tone = 'alert';  headline = 'Not enough evidence to support a defensible decision.'; }

    els.headline.textContent = headline;
    let bodyHtml = '<div class="interp-verdict-card" data-tone="' + tone + '">' +
                     '<div class="interp-verdict-label">Verdict</div>' +
                     '<div class="interp-verdict-band">' + band + '</div>' +
                     '<div class="interp-verdict-score">Evidence score: ' + evidence.toFixed(1) + '</div>' +
                   '</div>';
    bodyHtml += '<h4 class="interp-block-h">What supports the verdict</h4><ul class="interp-bullets">';
    if (strengthHigh)        bodyHtml += '<li>Strength Index is at or above 80 (instrument is publication-ready).</li>';
    if (inferentialPositive) bodyHtml += '<li>' + inferentialPositive + ' inferential test' + (inferentialPositive === 1 ? '' : 's') + ' significant with at least one meaningful effect size.</li>';
    if (openEndedRich)       bodyHtml += '<li>Open-ended responses are rich (rate ≥ 50%, average ≥ 8 words).</li>';
    if (blocks.length >= 4)  bodyHtml += '<li>Multiple analyses saved (' + blocks.length + ' blocks), giving cross-checks.</li>';
    bodyHtml += '</ul>';
    if (watch.length) {
      bodyHtml += '<h4 class="interp-block-h">Watch-list</h4><ul class="interp-bullets">';
      watch.forEach(w => { bodyHtml += '<li>' + esc(w) + '</li>'; });
      bodyHtml += '</ul>';
    }
    els.body.innerHTML = bodyHtml;
    els.next.textContent = band === 'High-stakes ready' ? 'Take the findings to a stakeholder review; package them in the Report Builder.' :
                           band === 'Operational' ? 'Use the findings to inform operational changes; flag the caveats in the report.' :
                           band === 'Exploratory' ? 'Treat this as a pilot; the next round of data collection should target the weakest area.' :
                           'Re-collect data with a larger sample, or address the weakest Strength Index domain before drawing conclusions.';
    return { evidence: evidence, band: band, tone: tone, watch: watch };
  }

  // ==================================================================
  // LENS: evidence_alignment
  // ==================================================================
  function renderEvidenceAlignment(blocks, dataset, els) {
    // No structured "purpose" field yet — use a placeholder and let the
    // user type one in via a textarea. We persist it to localStorage so
    // it sticks within the project.
    const purposeKey = 'relicheck.purpose.' + projectId;
    let purpose = '';
    try { purpose = window.localStorage.getItem(purposeKey) || ''; } catch (e) {}

    const items = blocks.map(b => {
      const p = b.payload || {};
      let stance = 'neutral', stanceLabel = 'related', reason = '';
      if (b.app === 'strength_index') {
        if (p.strength >= 80) { stance = 'support'; stanceLabel = 'supports'; reason = 'Strong instrument (Strength Index ' + p.strength + '). Findings rest on solid ground.'; }
        else if (p.strength < 60) { stance = 'complicate'; stanceLabel = 'complicates'; reason = 'Weak instrument (Strength Index ' + p.strength + '). Any finding is hard to defend until the scale is strengthened.'; }
        else { stance = 'neutral'; stanceLabel = 'related'; reason = 'Instrument is usable but not strong.'; }
      } else if (b.app === 'inferential' && p.result) {
        if (p.result.p < 0.05 && (p.result.effect.tone === 'ok' || p.result.effect.tone === 'strong')) {
          stance = 'support'; stanceLabel = 'supports'; reason = 'Significant ' + p.result.test + ' with meaningful effect size. Directly supports a hypothesis-driven purpose.';
        } else if (p.result.p < 0.05) {
          stance = 'neutral'; stanceLabel = 'partially supports'; reason = 'Statistically significant but modest effect size. Worth reporting; resists strong claims.';
        } else {
          stance = 'complicate'; stanceLabel = 'complicates'; reason = 'No reliable difference / relationship. Either the hypothesis is wrong or the test is underpowered.';
        }
      } else if (b.app === 'effect_size' && p.result) {
        if (p.result.tone === 'strong' || p.result.tone === 'ok') { stance = 'support'; stanceLabel = 'supports'; reason = 'Effect size in the ' + p.result.band + ' band.'; }
        else { stance = 'neutral'; stanceLabel = 'related'; reason = 'Effect size is ' + p.result.band + '.'; }
      } else if (b.app === 'open_ended_summary' && p.aggregate) {
        if (p.aggregate.responseRate >= 0.50) { stance = 'support'; stanceLabel = 'supports'; reason = 'Respondents engaged with the open-ended prompt; qualitative material is available to triangulate.'; }
        else { stance = 'neutral'; stanceLabel = 'related'; reason = 'Low open-ended engagement; qualitative triangulation is limited.'; }
      }
      return { appName: b.appName || b.app, stance: stance, stanceLabel: stanceLabel, reason: reason, summary: b.summary || '' };
    });

    const supports     = items.filter(it => it.stance === 'support').length;
    const complicates  = items.filter(it => it.stance === 'complicate').length;
    const neutral      = items.filter(it => it.stance === 'neutral').length;
    els.headline.textContent = supports + ' support · ' + neutral + ' related · ' + complicates + ' complicate';

    const purposeBlock =
      '<div class="interp-purpose">' +
        '<label class="interp-block-h" for="interpPurpose">Project purpose</label>' +
        '<textarea id="interpPurpose" class="interp-purpose-input" rows="2" placeholder="e.g., evaluate whether the new role-clarity intervention improves belonging and recommend likelihood across departments">' + esc(purpose) + '</textarea>' +
        '<span class="interp-purpose-help">Saved to localStorage on this project so the alignment view has a stated purpose to compare to.</span>' +
      '</div>';

    const tableRows = items.map(it =>
      '<tr data-stance="' + esc(it.stance) + '">' +
        '<td class="interp-align-name">' + esc(it.appName) + '</td>' +
        '<td class="interp-align-stance"><span class="interp-stance-pill" data-stance="' + esc(it.stance) + '">' + esc(it.stanceLabel) + '</span></td>' +
        '<td class="interp-align-reason">' + esc(it.reason) + '</td>' +
      '</tr>'
    ).join('');
    const table = '<table class="interp-align-table">' +
                    '<thead><tr><th>Analysis</th><th>Alignment</th><th>Why</th></tr></thead>' +
                    '<tbody>' + tableRows + '</tbody>' +
                  '</table>';
    els.body.innerHTML = purposeBlock + table;

    // Wire the purpose textarea
    const ta = document.getElementById('interpPurpose');
    if (ta) ta.addEventListener('input', () => {
      try { window.localStorage.setItem(purposeKey, ta.value); } catch (e) {}
    });

    els.next.textContent = complicates
      ? 'Where the evidence complicates the purpose, decide: revise the purpose, collect more data, or report the complication explicitly.'
      : 'The evidence is broadly aligned with the stated purpose. Carry the alignment table into the report\'s introduction.';
    return { supports: supports, complicates: complicates, neutral: neutral, items: items };
  }

  // ==================================================================
  // Helpers: per-block readers
  // ==================================================================
  function plainSummary(b) {
    const p = b.payload || {};
    if (b.app === 'strength_index')     return 'You saved a Strength Index result. The overall score is ' + p.strength + ' out of 100, in the "' + p.verdict + '" band.';
    if (b.app === 'inferential' && p.result) return 'You saved a ' + p.result.test + ' on ' + (p.variables ? p.variables.join(' and ') : 'two variables') + '. The test produced ' + p.result.statName + ' = ' + (p.result.statistic != null ? p.result.statistic.toFixed(2) : '—') + ' with p = ' + fmtP(p.result.p) + '.';
    if (b.app === 'effect_size' && p.result) return 'You saved an Effect Size result: ' + p.result.name + ' = ' + (p.result.value != null ? p.result.value.toFixed(3) : '—') + ', in the "' + p.result.band + '" band.';
    if (b.app === 'open_ended_summary' && p.aggregate) return 'You saved an Open-Ended Summary. Across ' + p.aggregate.fields + ' field(s), ' + p.aggregate.answers + ' respondent(s) wrote at an average of ' + (p.aggregate.avgWords || 0).toFixed(1) + ' words.';
    return 'You saved a result from ' + (b.appName || b.app) + '. ' + (b.summary || '');
  }
  function plainImplication(b) {
    const p = b.payload || {};
    if (b.app === 'strength_index') {
      if (p.strength >= 90) return 'This instrument is excellent: items hang together, coverage is wide, and the score will hold up to scrutiny.';
      if (p.strength >= 80) return 'The instrument is strong. You can publish findings drawn from it.';
      if (p.strength >= 70) return 'The instrument is usable. Address the weakest domain before stretching findings into strong claims.';
      if (p.strength >= 60) return 'The instrument needs strengthening. Treat any finding as exploratory.';
      return 'The instrument is weak. Several domains need work before you draw conclusions.';
    }
    if (b.app === 'inferential' && p.result) {
      const sig = p.result.p < 0.05;
      const big = p.result.effect.tone === 'ok' || p.result.effect.tone === 'strong';
      if (sig && big)  return 'The difference (or relationship) is unlikely to be chance, and the effect is meaningfully large.';
      if (sig && !big) return 'The difference reaches significance, but the effect is modest. With a large sample, even small effects light up.';
      if (!sig && big) return 'The effect size looks substantial, but the test does not clear p < .05. The data may be underpowered.';
      return 'No reliable difference (or relationship). The data are consistent with no effect.';
    }
    if (b.app === 'effect_size' && p.result) {
      const r = p.result;
      if (r.band === 'large')      return 'A large effect: in practical terms, this difference (or relationship) is visible without statistical tools.';
      if (r.band === 'medium')     return 'A medium effect: visible to a careful eye, important in most decision contexts.';
      if (r.band === 'small')      return 'A small effect: real but modest. Important when accumulated across many people or repeated measurements.';
      return 'A negligible effect: essentially no practical difference.';
    }
    if (b.app === 'open_ended_summary' && p.verdict) return 'Qualitative read: ' + p.verdict.toLowerCase() + '.';
    return '';
  }
  function plainCaveat(b) {
    const p = b.payload || {};
    if (b.app === 'inferential' && p.result && p.result.assumptions) {
      const warns = p.result.assumptions.filter(a => a.ok === 'warn' || a.ok === false);
      if (warns.length) return 'Caveat: ' + warns[0].msg;
    }
    if (b.app === 'effect_size' && p.result && p.result.detail && p.result.detail.sparseCorrected) {
      return 'Caveat: the odds ratio used a Haldane 0.5 correction because at least one cell was empty.';
    }
    return null;
  }
  function rankFinding(b) {
    const p = b.payload || {};
    if (b.app === 'strength_index' && p.strength != null) {
      const dist = Math.abs(p.strength - 75);  // distance from the middle band
      return {
        score:   dist,
        tone:    p.strength >= 80 ? 'strong' : p.strength >= 70 ? 'ok' : p.strength >= 60 ? 'warn' : 'alert',
        appName: b.appName,
        line:    'Strength Index = ' + p.strength + ' (' + p.verdict + ')',
        note:    p.strength >= 80 ? 'The instrument is publication-ready.' : 'Address the weakest domain before publishing.',
      };
    }
    if (b.app === 'inferential' && p.result) {
      const r = p.result;
      const sig = r.p < 0.05 ? 1 : 0;
      const big = (r.effect.tone === 'strong' ? 2 : r.effect.tone === 'ok' ? 1 : 0);
      const score = sig * 2 + big * 2;
      if (score === 0) return { score: 0 };
      return {
        score:   score,
        tone:    sig && big ? 'strong' : sig ? 'ok' : 'warn',
        appName: b.appName,
        line:    r.test + ' on ' + (p.variables ? p.variables.join(' and ') : '—'),
        note:    r.statName + ' = ' + (r.statistic != null ? r.statistic.toFixed(2) : '—') + ', ' + fmtP(r.p) + ', ' + r.effect.name + ' = ' + (r.effect.value != null ? r.effect.value.toFixed(2) : '—'),
      };
    }
    if (b.app === 'effect_size' && p.result) {
      const t = p.result.tone;
      const score = t === 'strong' ? 4 : t === 'ok' ? 3 : t === 'warn' ? 1 : 0;
      if (score === 0) return { score: 0 };
      return {
        score:   score,
        tone:    t,
        appName: b.appName,
        line:    p.result.name + ' = ' + (p.result.value != null ? p.result.value.toFixed(2) : '—'),
        note:    'In the "' + p.result.band + '" band.',
      };
    }
    if (b.app === 'open_ended_summary' && p.aggregate) {
      const rr = p.aggregate.responseRate || 0;
      const score = rr >= 0.70 ? 3 : rr >= 0.40 ? 2 : 0;
      if (score === 0) return { score: 0 };
      return {
        score:   score,
        tone:    rr >= 0.70 ? 'strong' : 'ok',
        appName: b.appName,
        line:    Math.round(rr * 100) + '% wrote something across ' + p.aggregate.fields + ' field(s)',
        note:    'Average ' + (p.aggregate.avgWords || 0).toFixed(1) + ' words per response. ' + (p.aggregate.substantive || 0) + ' substantive answers.',
      };
    }
    return { score: 0 };
  }
  function translateEffect(appName, eff, testName, vars, fullResult) {
    if (!eff || eff.value == null) return { appName: appName, kind: 'unknown', headline: '—', body: '—', tone: 'muted' };
    const v = eff.value;
    let headline, body, tone = eff.tone || 'muted';
    if (eff.name === "Cohen's d" || eff.name === 'd') {
      const pct = pctAboveOtherMean(v);
      headline = "Cohen's d = " + v.toFixed(2) + ' (' + eff.band + ')';
      body = 'In practical terms, about ' + Math.round(pct) + '% of one group scores above the other group\'s mean. ' + cohenInterp(v);
    } else if (eff.name === 'η²') {
      headline = 'η² = ' + v.toFixed(3) + ' (' + eff.band + ')';
      body = (v * 100).toFixed(1) + '% of the variance in the outcome is explained by group membership.';
    } else if (eff.name === "Cramer's V" || eff.name === 'V') {
      headline = "Cramer's V = " + v.toFixed(2) + ' (' + eff.band + ')';
      body = "On a 0-1 scale of association strength, the two categorical variables have a " + eff.band + " relationship.";
    } else if (eff.name === 'r' || eff.name === 'Pearson r') {
      const r2 = v * v;
      headline = 'r = ' + v.toFixed(2) + ' (' + eff.band + ')';
      body = (r2 * 100).toFixed(1) + '% of the variance in Y is shared with X. ' + (v > 0 ? 'Higher X tends to come with higher Y.' : v < 0 ? 'Higher X tends to come with lower Y.' : '');
    } else if (eff.name === 'r²') {
      headline = 'r² = ' + v.toFixed(3) + ' (' + eff.band + ')';
      body = (v * 100).toFixed(1) + '% of the variance in one variable is shared with the other.';
    } else if (eff.name === 'Odds ratio' || eff.name === 'OR') {
      const m = v >= 1 ? v : 1 / v;
      headline = 'OR = ' + v.toFixed(2) + ' (' + eff.band + ')';
      body = 'The exposed group has ' + m.toFixed(2) + '× the odds of the outcome ' + (v >= 1 ? '' : '(inverse direction)') + '.';
    } else {
      headline = eff.name + ' = ' + v.toFixed(2);
      body = 'A ' + eff.band + ' effect.';
    }
    return { appName: appName, kind: eff.name, headline: headline, body: body, tone: tone };
  }
  function cohenInterp(d) {
    const a = Math.abs(d);
    if (a >= 0.80) return 'In Cohen\'s terms, a large effect — visible without statistical tools.';
    if (a >= 0.50) return 'A medium effect — noticeable in daily comparisons.';
    if (a >= 0.20) return 'A small effect — real, but detectable mainly with adequate samples.';
    return 'A negligible effect — essentially no practical difference.';
  }
  function pctAboveOtherMean(d) {
    // Approximation: % of group A above group B's mean = Φ(d/√2) for equal variances
    // Use the simpler one-tailed form: Φ(d) at d=0 gives 50%.
    const x = d / Math.SQRT2;
    return Math.round(50 + 50 * Math.tanh(x * 0.7)); // crude but readable; good enough for interpretation copy
  }
  function interpretStrength(score, verdict) {
    if (score >= 90) return 'In a "publish-and-act-on" tier. Items hang together, coverage is wide, and reviewers will find few holes.';
    if (score >= 80) return 'Strong: defensible for reporting and decision-making.';
    if (score >= 70) return 'Usable, with caveats. Address the weakest domain before strong claims.';
    if (score >= 60) return 'Needs strengthening. Treat any finding as exploratory until the lowest domain is fixed.';
    return 'Weak. Several domains need work before findings can stand.';
  }
  function interpretQual(s) {
    if (s.response_rate >= 0.70 && s.mean_words >= 8) return 'High engagement and substantive answers. Strong base for theme analysis.';
    if (s.response_rate >= 0.40) return 'Workable engagement. Themes should be tentative until a larger sample confirms.';
    if (s.response_rate >= 0.10) return 'Light engagement. Use answers as illustrative quotes alongside numeric findings.';
    return 'Very low engagement. Open-ended material is too thin to support theme claims.';
  }
  function methodExplainer(b) {
    const p = b.payload || {};
    if (b.app === 'strength_index') return [
      'The <strong>ReliCheck Strength Index</strong> is a 0-100 composite of six weighted domains: Internal Consistency &amp; Scale Reliability (25 pts), Factor Structure (20), Item Quality (20), Response Quality (15), Open-Ended Alignment (10), and Actionability (10).',
      'Reliability is computed with both Cronbach\'s α and McDonald\'s ω. α is the classic, but it assumes every item contributes equally to the scale; ω relaxes that assumption and is the more defensible estimator when item loadings vary.',
      'Factor Structure anchors on Kaiser-Meyer-Olkin (KMO), then deducts points for items with weak loadings (|λ| &lt; 0.40). Item Quality flags ceiling/floor patterns, low-variance items, high skew or kurtosis, and item-level missingness.',
      'The result is a single, defensible number you can put on a report cover, with a six-card breakdown that points at the next thing to fix.',
    ];
    if (b.app === 'inferential' && p.result) {
      if (p.result.test.indexOf('t-test') !== -1) return [
        'A <strong>t-test</strong> compares the means of two groups and asks: is the difference larger than chance, given the noise in the data?',
        'Welch\'s correction (used by default in ReliCheck) does not assume the two groups have the same variance. It computes a corrected degrees-of-freedom that handles unequal spreads correctly.',
        'The p-value tells you how unlikely a difference this large would be if the two groups were really the same. The companion effect size (Cohen\'s d) tells you how big the difference actually is — a small d that\'s "significant" can be less meaningful than a large d that didn\'t quite clear .05.',
      ];
      if (p.result.test === 'One-way ANOVA') return [
        '<strong>One-way ANOVA</strong> extends the t-test to three or more groups. It tests whether at least one group mean differs from the others.',
        'The F-statistic is the ratio of variability <em>between</em> groups to variability <em>within</em> groups. Large F means the group means are spread out relative to the noise around each mean.',
        'η² (eta-squared) is the natural effect size: the share of total variance explained by group membership. ω² is a less biased version of the same idea — preferable to report on small samples.',
        'After a significant omnibus F, run a post-hoc comparison (Tukey HSD or Games-Howell) to see which specific pairs of groups differ.',
      ];
      if (p.result.test.indexOf('Chi-square') !== -1) return [
        'The <strong>chi-square test of independence</strong> asks whether two categorical variables are related. It compares the cell counts we actually observed against what we would expect if the two variables were independent.',
        'χ² is a sum of standardized squared differences across the cells. Large χ² means the observed pattern is far from the independence baseline.',
        'Cramer\'s V scales the strength of the association to a 0-1 range that\'s comparable across tables of different sizes. The chi-square test assumes every expected cell count is at least 5 — when it\'s not, Fisher\'s exact is the recommended alternative.',
      ];
      if (p.result.test === 'Pearson correlation') return [
        '<strong>Pearson r</strong> measures the strength and direction of a linear relationship between two numeric variables, on a -1 to +1 scale.',
        'r² (the square of r) is the share of variance in one variable that is shared with the other. A correlation of r = 0.5 means 25% of the variance is shared.',
        'Pearson r assumes a linear relationship. Always inspect a scatterplot before reporting r — a strong nonlinear relationship can show a near-zero r, and an outlier or two can pull r dramatically in either direction.',
      ];
    }
    if (b.app === 'effect_size' && p.result) return [
      'An <strong>effect size</strong> quantifies how big a difference or relationship is, independent of the sample size. Unlike a p-value, it doesn\'t shrink with smaller n.',
      'Cohen\'s d standardizes the difference between two means in pooled-SD units. r and r² express the share of variance shared between two variables. η² extends r² to ANOVA. Odds ratio expresses how the odds of an outcome change between groups.',
      'Effect sizes convert between each other under stated assumptions. The Effect Size app\'s Convert mode lets you carry a finding from one paper\'s metric into another\'s for meta-analysis.',
      'Cohen (1988) supplied the conventional bands (negligible / small / medium / large). They\'re useful priors, not laws — a "small" d that replicates is often more important than a "large" d that doesn\'t.',
    ];
    if (b.app === 'open_ended_summary') return [
      '<strong>Open-Ended Summary</strong> is the descriptive read of free-text fields: how many people wrote something, how long their answers were, what words and phrases appear most often, and a few representative answers.',
      'Word frequencies use a small stopword filter (the, and, of, in, etc.) so common-but-empty words don\'t crowd the list. Bigrams (two-word phrases) require at least two appearances to qualify — sparse phrases get filtered.',
      'This app is descriptive, not interpretive. Themes, codebooks, and rater agreement live in the MM Studio\'s Theme Analysis app. Use this lens to confirm there\'s enough material to code before going deeper.',
    ];
    return ['No explainer available for ' + (b.appName || b.app) + ' yet.'];
  }

  // ==================================================================
  // App state
  // ==================================================================
  function exposeAppState(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'interpretation',
      app_name: 'Interpretation (' + (LENSES[lens] ? LENSES[lens].name : lens) + ')',
      summary:  buildSummary(payload),
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function buildSummary(payload) {
    if (payload.empty) return 'Interpretation (' + lens + '): no saved blocks yet.';
    if (lens === 'key_findings' && payload.ranked) return 'Top finding: ' + (payload.ranked[0] ? payload.ranked[0].line : '—');
    if (lens === 'decision_readiness') return 'Decision readiness: ' + payload.band + ' (evidence score ' + (payload.evidence != null ? payload.evidence.toFixed(1) : '—') + ').';
    if (lens === 'limitations') return (payload.flags ? payload.flags.length : 0) + ' limitations flagged.';
    if (lens === 'recommended_actions') return (payload.actions ? payload.actions.length : 0) + ' next steps recommended.';
    if (lens === 'evidence_alignment') return (payload.supports || 0) + ' supports, ' + (payload.complicates || 0) + ' complicate.';
    return LENSES[lens] ? LENSES[lens].name : lens;
  }

  function fmtP(p) {
    if (p == null) return 'p = —';
    if (p < 0.001) return 'p < .001';
    if (p < 0.01)  return 'p = ' + p.toFixed(3).replace(/^0/, '');
    return 'p = ' + p.toFixed(2).replace(/^0/, '');
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
})();
