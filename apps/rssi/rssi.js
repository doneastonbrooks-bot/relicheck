/* =============================================================================
   rssi.js — ReliCheck Strength Survey Index dashboard hydrator.

   Reads the saved Strength Index block from the project's saved-blocks
   corpus at localStorage['relicheck.report.<project_id>.default'], then
   paints the RSSI template.

   Falls back to inline sample data when nothing is saved yet, with a banner
   that points the user at the Strength Index analysis page.
   ============================================================================= */
(function () {
  'use strict';

  const projectId = window.RELICHECK_PROJECT_ID ? String(window.RELICHECK_PROJECT_ID) : 'untitled-project';

  /* ──────────────────────────────────────────────────────────────
   * SAMPLE DATA — matches the template's "Employee Engagement Pulse"
   * shape. Only used when no real block has been saved yet.
   * ────────────────────────────────────────────────────────────── */
  const SAMPLE = {
    strength: 82,
    verdict: 'Strong',
    components: [
      { key: 'survey_structure',      label: 'Survey Structure',      score: 88, status: 'strong', note: 'Good item ordering and section length. Intro framing reads clearly.',  flag: '3 sub-checks passed' },
      { key: 'question_quality',      label: 'Question Quality',      score: 79, status: 'good',   note: '4 items flagged as double-barreled. Reading level mostly grade 7–9.',   flag: '4 items to revise' },
      { key: 'scale_strength',        label: 'Scale Strength',        score: 84, status: 'strong', note: 'Likert scales are consistent. Anchor labels are balanced and clear.',  flag: '6 scales reviewed' },
      { key: 'reliability_readiness', label: 'Reliability Readiness', score: 68, status: 'fair',   note: 'Engagement scale projected α = 0.68 — below the 0.70 threshold.',      flag: '2 scales need work' },
      { key: 'validity_alignment',    label: 'Validity Alignment',    score: 81, status: 'good',   note: 'Constructs map to items, but one construct is underweighted.',         flag: '1 construct underweighted' },
      { key: 'response_risk',         label: 'Response Risk',         score: 76, status: 'good',   note: 'Length is reasonable (~7 min). No reverse-scored items detected.',     flag: '1 acquiescence risk' },
    ],
    issues: [
      { sev: 'high', title: '4 double-barreled questions detected',  sub: 'Items combine two ideas, making responses ambiguous.',                   scope: 'Question Quality',  fix_route: '#dim-question_quality' },
      { sev: 'high', title: 'Engagement scale projected α = 0.68',   sub: 'Falls below the 0.70 reliability threshold. Add 1–2 items or revise.',  scope: 'Reliability',       fix_route: '#dim-reliability_readiness'  },
      { sev: 'med',  title: 'No reverse-scored items in any scale',  sub: 'Mild acquiescence risk. Add 1–2 reverse items per scale.',              scope: 'Response Quality',  fix_route: '#dim-validity_alignment' },
      { sev: 'low',  title: '"Belonging" construct has only 2 items',sub: 'Construct underweighted vs. others. Consider adding 1 more item.',      scope: 'Validity',          fix_route: '#dim-validity_alignment' },
    ],
    priorities: [
      { title: 'Revise 4 double-barreled items', sub: 'Split each into two single-idea questions.', meta: 'Est. lift +4 · ~10 min' },
      { title: 'Strengthen Engagement scale',     sub: 'Add 1–2 items so projected α clears 0.70.', meta: 'Est. lift +5 · ~15 min' },
      { title: 'Add reverse-scored items',        sub: 'One per scale reduces acquiescence bias.',  meta: 'Est. lift +2 · ~8 min'  },
    ],
    summary: 'At 82, this survey is in good shape. A few targeted fixes — mainly around reliability and double-barreled wording — would move it into the Excellent range.',
    confidence: 'High',
    item_count: 32,
    scale_count: 6,
    response_count: 250,
    isSample: true,
  };

  /* ──────────────────────────────────────────────────────────────
   * BAND / VERDICT helpers
   * ────────────────────────────────────────────────────────────── */
  function bandFor(score) {
    if (score >= 85) return { key: 'excellent', label: 'Excellent', status: 'strong' };
    if (score >= 70) return { key: 'strong',    label: 'Strong',    status: 'strong' };
    if (score >= 55) return { key: 'good',      label: 'Good',      status: 'good'   };
    if (score >= 40) return { key: 'fair',      label: 'Fair',      status: 'fair'   };
    return              { key: 'weak',      label: 'Weak',      status: 'weak'   };
  }
  function statusFor(score) { return bandFor(score).status; }

  /* ──────────────────────────────────────────────────────────────
   * LOAD: read the saved Strength Index block from localStorage.
   * Schema (from the universal save-to-report bar):
   *   relicheck.report.<pid>.default = { blocks: [{ app, payload }] }
   * The strength_index block's payload is the engine's APP_STATE, which
   * contains: strength, verdict, components, computed_at, dataset, summary.
   * ────────────────────────────────────────────────────────────── */
  function loadStrengthBlock() {
    try {
      const raw = window.localStorage.getItem('relicheck.report.' + projectId + '.default');
      if (!raw) return null;
      const r = JSON.parse(raw);
      if (!r || !Array.isArray(r.blocks)) return null;
      const b = r.blocks.find(function (x) { return x.app === 'strength_index'; });
      if (!b || !b.payload) return null;
      return b;
    } catch (e) { return null; }
  }

  /* ──────────────────────────────────────────────────────────────
   * Normalise an engine block's payload into the RSSI render shape.
   * Strength Index engine payload (from strength-index.js):
   *   { app_key, app_name, summary, strength, verdict, components: {key: {score, label, interp}}, dataset, computed_at }
   * The RSSI dashboard expects:
   *   { strength, verdict, components: [{key, label, score, status, note, flag}], summary, ... }
   * ────────────────────────────────────────────────────────────── */
  function normaliseBlock(block) {
    const p = block.payload;
    const comps = p.components || {};
    const compArray = Object.keys(comps).map(function (k) {
      const c = comps[k] || {};
      const skipped = !!c.skip || c.score == null;
      const score = skipped ? null : Math.round(Number(c.score) || 0);
      return {
        key: k,
        label: c.label || prettify(k),
        score: score,
        skipped: skipped,
        status: skipped ? 'skipped' : statusFor(score),
        note: c.interp || c.note || '',
        flag: c.flag || (skipped ? 'Not enough data' : ''),
      };
    }).sort(function (a, b) {
      // skipped → bottom; otherwise highest score first
      if (a.skipped && !b.skipped) return 1;
      if (!a.skipped && b.skipped) return -1;
      return (b.score || 0) - (a.score || 0);
    });

    // Build issues from low-scoring components.
    const issues = compArray.filter(function (c) { return c.score < 75; })
      .slice(0, 5)
      .map(function (c) {
        return {
          sev: c.score < 55 ? 'high' : (c.score < 70 ? 'med' : 'low'),
          title: c.label + ' score: ' + c.score + '/100',
          sub:   c.note,
          scope: c.label,
          fix_route: routeForComponent(c.key),
        };
      });

    // Priorities: top 3 most impactful issues.
    const priorities = issues.slice(0, 3).map(function (issue, i) {
      return {
        title: 'Improve ' + issue.scope,
        sub: issue.sub,
        meta: 'Est. lift +' + Math.max(2, Math.round((100 - parseInt(issue.title.match(/\d+/), 10)) / 8)) + ' · drill-in for details',
      };
    });

    return {
      strength: Math.round(Number(p.strength) || 0),
      verdict: p.verdict || bandFor(p.strength || 0).label,
      components: compArray,
      issues: issues,
      priorities: priorities,
      summary: p.summary || '',
      confidence: confidenceFor(p),
      item_count: (p.dataset && p.dataset.itemCount) || compArray.length || 0,
      scale_count: (p.dataset && p.dataset.scaleCount) || 0,
      response_count: (p.dataset && p.dataset.rowCount) || 0,
      computed_at: p.computed_at || block.addedAt,
      isSample: false,
    };
  }
  function prettify(k) { return k.replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }); }
  function confidenceFor(p) {
    const n = (p.dataset && p.dataset.rowCount) || 0;
    if (n >= 200) return 'High';
    if (n >= 80)  return 'Moderate';
    if (n >= 30)  return 'Low';
    return 'Pilot';
  }
  // In-app anchors only — no jumping to the Survey Studio.
  // Each dimension card's "View" and each issue's "Open" link scrolls
  // to the dimension card with id="dim-<key>".
  function routeForComponent(key) {
    return '#dim-' + key;
  }

  /* ──────────────────────────────────────────────────────────────
   * Friendly icon picker for dimension cards.
   * ────────────────────────────────────────────────────────────── */
  function iconForKey(key) {
    const icons = {
      reliability:      '<path d="M8 1.5 2.5 3.8v3.7c0 3.4 2.4 6.2 5.5 7 3.1-.8 5.5-3.6 5.5-7V3.8L8 1.5Z" stroke-linejoin="round"/>',
      validity:         '<circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/>',
      factor_structure: '<path d="M3 8a5 5 0 1 0 10 0A5 5 0 0 0 3 8Z"/><path d="M8 3v10M3 8h10" stroke-linecap="round"/>',
      item_quality:     '<rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7.5" width="12" height="2" rx="0.7"/><rect x="2" y="11" width="8" height="2" rx="0.7"/>',
      response_quality: '<path d="M8 2 1.5 13.5h13L8 2Z" stroke-linejoin="round"/><path d="M8 7v3M8 11.8v.4" stroke-linecap="round"/>',
      open_ended:       '<path d="M2.5 4.5A1.5 1.5 0 0 1 4 3h8a1.5 1.5 0 0 1 1.5 1.5v5A1.5 1.5 0 0 1 12 11H6l-3 2.5V11H4a1.5 1.5 0 0 1-1.5-1.5v-5Z" stroke-linejoin="round"/>',
      actionability:    '<path d="M8 1.5a4.5 4.5 0 0 0-2.7 8.1V11h5.4v-1.4A4.5 4.5 0 0 0 8 1.5ZM6 13h4M7 14.5h2" stroke-linecap="round" stroke-linejoin="round"/>',
      survey_structure: '<rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7.5" width="12" height="2" rx="0.7"/><rect x="2" y="11" width="8" height="2" rx="0.7"/>',
      question_quality: '<circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/>',
      scale_strength:   '<path d="M3 13V3M3 13h10M5.5 11V8M8 11V5M10.5 11V9" stroke-linecap="round"/>',
    };
    return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6">' +
      (icons[key] || icons.item_quality) + '</svg>';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
    });
  }

  /* ──────────────────────────────────────────────────────────────
   * RENDER
   * ────────────────────────────────────────────────────────────── */
  function render(d) {
    /* ─── Headline score ─── */
    document.getElementById('rssiScore').textContent = d.strength;
    document.getElementById('rssiBadge').textContent = d.verdict;
    document.getElementById('rssiVerdictPill').textContent = 'v · ' + d.verdict.toLowerCase();
    document.getElementById('rssiItemCount').textContent  = d.item_count + ' items';
    document.getElementById('rssiRespCount').textContent  = d.response_count + ' responses';
    document.getElementById('rssiHeroMetaItems').textContent = d.item_count + ' items · ' + d.scale_count + ' scales';
    document.getElementById('rssiConfidence').textContent = d.confidence;
    document.getElementById('rssiProjMetaItems').textContent = d.item_count + ' items';
    document.getElementById('rssiProjMetaResp').textContent  = d.response_count + ' responses';

    // Sidebar project card (on /rssi.php only — graceful no-op elsewhere)
    const sbName = document.getElementById('rssiSidebarSurveyName');
    const sbItems = document.getElementById('rssiSidebarMetaItems');
    const sbResp  = document.getElementById('rssiSidebarMetaResp');
    if (sbName) {
      const titleEl = document.getElementById('rssiDashTitle');
      sbName.textContent = (titleEl && titleEl.textContent) ? titleEl.textContent : 'Uploaded survey';
    }
    if (sbItems) sbItems.textContent = d.item_count + ' items';
    if (sbResp)  sbResp.textContent  = d.response_count + ' responses';
    document.getElementById('rssiHeroMetaSource').textContent = d.isSample ? 'Sample data' : 'Live data';
    if (d.computed_at) {
      const when = new Date(d.computed_at);
      document.getElementById('rssiComputedAt').textContent = 'Scored ' + when.toLocaleDateString();
    }

    /* ─── Hero copy ─── */
    const heroH2 = document.getElementById('rssiHeroH2');
    if (d.strength >= 85)      heroH2.textContent = 'This survey is in excellent shape.';
    else if (d.strength >= 70) heroH2.textContent = 'Your survey is in good shape.';
    else if (d.strength >= 55) heroH2.textContent = 'A few fixes will sharpen this survey.';
    else if (d.strength >= 40) heroH2.textContent = 'This survey needs work before fielding.';
    else                       heroH2.textContent = 'Significant revisions needed.';
    document.getElementById('rssiHeroP').textContent = d.summary;
    document.getElementById('rssiWhatThisMeans').innerHTML =
      'At <strong style="color:var(--text); font-weight:600;">' + d.strength + '</strong>, ' +
      esc(d.summary).replace(/^At \d+,\s*/i, '');

    /* ─── Ring fill ─── */
    const C = 2 * Math.PI * 52; // ≈ 326.7
    const fg = document.getElementById('rssiRingFg');
    fg.setAttribute('stroke-dasharray', C.toFixed(2));
    fg.setAttribute('stroke-dashoffset', (C * (1 - d.strength / 100)).toFixed(2));

    /* ─── Tier marker ─── */
    document.getElementById('rssiTierMarker').style.left = d.strength + '%';
    /* Highlight the current band label */
    const band = bandFor(d.strength);
    document.querySelectorAll('#rssiTierLabels span').forEach(function (s) {
      if (s.getAttribute('data-band') === band.key) {
        s.classList.add('here');
        s.textContent = band.label + ' · ' + d.strength;
      } else {
        s.classList.remove('here');
      }
    });

    /* ─── Dimensions ─── */
    const grid = document.getElementById('rssiDimGrid');
    grid.innerHTML = d.components.map(function (c) {
      // Mini graph cards: small ring + score + label. Click → detail view.
      // SVG ring math: r=22 → circumference ≈ 138.23
      const C = 138.23;
      const score = c.skipped ? 0 : (Number(c.score) || 0);
      const offset = (C * (1 - score / 100)).toFixed(2);
      const ringColor = c.skipped ? '#D8DDE3' : ({
        strong: '#34C759', good: '#007AFF', fair: '#FF9F0A', weak: '#FF3B30'
      }[c.status] || '#007AFF');
      const numTxt = c.skipped ? '—' : String(score);
      const statusLbl = c.skipped ? 'Skipped' : bandFor(score).label;
      const statusCls = c.skipped ? 'skipped' : c.status;
      return [
        '<a class="dim-mini" id="dim-', esc(c.key), '" href="', esc(routeForComponent(c.key)), '" title="', esc(c.label), ' — ', esc(c.note || ''), '">',
          '<div class="dim-mini-ring">',
            '<svg viewBox="0 0 60 60" aria-hidden="true">',
              '<circle cx="30" cy="30" r="22" fill="none" stroke="#EAEDF1" stroke-width="6"/>',
              '<circle cx="30" cy="30" r="22" fill="none" stroke="', ringColor, '" stroke-width="6" stroke-linecap="round" stroke-dasharray="', C.toFixed(2), '" stroke-dashoffset="', offset, '" transform="rotate(-90 30 30)" style="transition: stroke-dashoffset 0.5s ease, stroke 0.3s ease;"/>',
            '</svg>',
            '<span class="dim-mini-num">', numTxt, '</span>',
          '</div>',
          '<div class="dim-mini-label">', esc(c.label), '</div>',
          '<span class="dim-mini-status status-', statusCls, '">', esc(statusLbl), '</span>',
        '</a>',
      ].join('');
    }).join('');

    /* ─── Issues ─── */
    const issues = document.getElementById('rssiIssues');
    if (d.issues.length === 0) {
      issues.innerHTML = '<div class="issue"><span class="sev sev-low"></span><div><div class="issue-title">No critical issues detected</div><div class="issue-sub">Every diagnostic dimension scored at "Good" or better.</div></div><div class="issue-scope">All clear</div><span></span></div>';
    } else {
      issues.innerHTML = d.issues.map(function (i) {
        return [
          '<a class="issue" href="', esc(i.fix_route), '">',
            '<span class="sev sev-', i.sev, '"></span>',
            '<div>',
              '<div class="issue-title">', esc(i.title), '</div>',
              '<div class="issue-sub">',   esc(i.sub),   '</div>',
            '</div>',
            '<div class="issue-scope">', esc(i.scope), '</div>',
            '<span class="issue-fix">Open →</span>',
          '</a>',
        ].join('');
      }).join('');
    }

    /* ─── Priorities (right rail) ─── */
    const prio = document.getElementById('rssiPriorities');
    if (d.priorities.length === 0) {
      prio.innerHTML = '<p class="lead" style="color:var(--text-3);">No improvements needed — this survey is in excellent shape.</p>';
    } else {
      prio.innerHTML = d.priorities.map(function (p, idx) {
        return [
          '<div class="priority">',
            '<div class="pri-num">', (idx + 1), '</div>',
            '<div>',
              '<div class="pri-title">', esc(p.title), '</div>',
              '<div class="pri-sub">',   esc(p.sub),   '</div>',
              '<div class="pri-meta">',  esc(p.meta),  '</div>',
            '</div>',
          '</div>',
        ].join('');
      }).join('');
    }

    /* ─── Sample-data banner ─── */
    const banner = document.getElementById('rssiSampleBanner');
    if (banner) banner.hidden = !d.isSample;

    /* ─── Live "What Do These Numbers Mean?" explanations ─── */
    updateExplanations(d);
  }

  /* ────────────────────────────────────────────────────────────
   *  EXPLANATION TEXTS — one paragraph per dimension per band.
   *  Picked at render time based on the current score.
   * ──────────────────────────────────────────────────────────── */
  const EXPLAIN = {
    overall: {
      excellent: 'Your survey is in excellent shape. The instrument is reliable enough to publish, share with stakeholders, or use as the basis for confident decisions.',
      strong:    'Your survey is in strong shape. It is ready to field or share, and a few targeted fixes would push it into the excellent range.',
      good:      'Your survey is functional but has notable weak spots. Focused revisions to the lowest-scoring dimensions below would meaningfully lift its credibility.',
      fair:      'Your survey needs work before its results can be relied on. Several dimensions are below acceptable thresholds for publication or decision-making.',
      weak:      'Significant revision is needed before this survey produces trustworthy data. Multiple core dimensions require attention.',
    },
    survey_structure: {
      excellent: 'Ideal structure — a balanced item count, healthy mix of question types, and appropriate length for the constructs you are measuring.',
      strong:    'Structure is solid. Length and question-type mix are in healthy ranges.',
      good:      'Workable structure but room to tighten. Consider trimming, adding, or rebalancing the question types.',
      fair:      'Structural issues — too few items, too many, or an unbalanced question-type mix.',
      weak:      'Major structural issues. Length or composition needs to be reworked from the ground up.',
      skipped:   'Not enough data to evaluate structure for this dataset.',
    },
    question_quality: {
      excellent: 'Every Likert item sits in a healthy range — no ceiling/floor effects, low-variance items, or high-missing items detected.',
      strong:    'Most items perform well. A handful of minor flags but nothing that demands immediate action.',
      good:      'Some items show ceiling/floor effects, low variance, or missingness. Review the flagged items in the Reliability table.',
      fair:      'Multiple items have quality issues that compromise data integrity. Revise or remove the worst performers before relying on results.',
      weak:      'Widespread item-quality problems. Most items need revision before re-fielding.',
      skipped:   'No Likert items to evaluate.',
    },
    scale_strength: {
      excellent: 'Items within scales correlate strongly, indicating clean, coherent underlying constructs.',
      strong:    'Scale items hang together well. The constructs are being measured clearly.',
      good:      'Items correlate adequately but a few show weak relationships. A factor analysis would help confirm the structure.',
      fair:      'Items within scales show inconsistent relationships. The underlying construct structure is unclear and may need rethinking.',
      weak:      'Items do not cluster into meaningful scales. The current structure does not reflect coherent constructs.',
      skipped:   'Need at least three Likert items to evaluate scale structure.',
    },
    reliability_readiness: {
      excellent: 'Cronbach’s α is excellent. Your scales are reliable enough for high-stakes decisions and academic publication.',
      strong:    'Reliability meets the publishable threshold (α ≥ 0.70). Your scales produce consistent measurements.',
      good:      'Reliability is acceptable but below ideal. Adding one or two items per scale could push α above 0.80.',
      fair:      'α falls below 0.70 on at least one scale. Add items or revise weak performers before treating the results as reliable.',
      weak:      'Scales lack internal consistency. Major revision is required before reliability can be claimed.',
      skipped:   'Need at least two Likert items to compute reliability.',
    },
    validity_alignment: {
      excellent: 'Respondents engaged seriously. Completion is high and missingness patterns are minimal.',
      strong:    'Response quality is solid. Most respondents completed the survey carefully.',
      good:      'Acceptable response quality with some missingness patterns. Investigate sections that show higher drop-off.',
      fair:      'Response quality concerns. High missingness or signs of inattentive responses in parts of the data.',
      weak:      'Serious data-quality issues. Many respondents may not have engaged genuinely with the survey.',
      skipped:   'Not enough complete responses to evaluate.',
    },
    response_risk: {
      excellent: 'Open-ended questions are pulling their weight. Strong response rates and substantive answers.',
      strong:    'Open-ended items are well-used. Most respondents provided meaningful answers.',
      good:      'Open-ended response rates are acceptable but answers tend to be brief. Consider rewording for clarity or salience.',
      fair:      'Open-ended items have low engagement. Many respondents skip them or give one-word answers.',
      weak:      'Open-ended questions are largely ignored or unclear. Reword them or reduce their count to focus respondents.',
      skipped:   'No open-ended items detected in this dataset.',
    },
  };

  function bandKeyForScore(score) {
    if (score == null) return 'skipped';
    if (score >= 85) return 'excellent';
    if (score >= 70) return 'strong';
    if (score >= 55) return 'good';
    if (score >= 40) return 'fair';
    return 'weak';
  }
  function bandStatusClass(score) {
    if (score == null) return 'skipped';
    if (score >= 70) return 'strong';
    if (score >= 55) return 'good';
    if (score >= 40) return 'fair';
    return 'weak';
  }

  function updateExplanations(d) {
    // Container won't exist on /rssi-report.php (old shell) — guard.
    if (!document.getElementById('explain_overall')) return;

    // Overall
    const overallBand = bandKeyForScore(d.strength);
    setExplain('overall', EXPLAIN.overall[overallBand] || EXPLAIN.overall.good,
               overallBand[0].toUpperCase() + overallBand.slice(1),
               bandStatusClass(d.strength));

    // Each dimension
    (d.components || []).forEach(function (c) {
      const meta = EXPLAIN[c.key];
      if (!meta) return;
      const key = c.skipped ? 'skipped' : bandKeyForScore(c.score);
      const label = c.skipped ? 'Skipped' : (key[0].toUpperCase() + key.slice(1));
      const cls   = c.skipped ? 'skipped' : bandStatusClass(c.score);
      setExplain(c.key, meta[key] || meta.good || '', label, cls);
    });
  }
  function setExplain(key, text, bandLabel, statusClass) {
    const t = document.getElementById('explain_' + key);
    const b = document.getElementById('explainBand_' + key);
    if (t) t.textContent = text;
    if (b) {
      b.textContent = bandLabel;
      b.className = 'explain-band status-' + statusClass;
    }
  }

  /* ──────────────────────────────────────────────────────────────
   * PUBLIC: render from a pre-computed result (used by the upload
   * page after parse). Passing null falls back to sample data.
   * ────────────────────────────────────────────────────────────── */
  let lastRenderedData = null;
  window.RSSI_RENDER_FROM_RESULT = function (result) {
    if (result && typeof result === 'object') {
      lastRenderedData = normaliseBlock({ payload: result, addedAt: Date.now() });
    } else {
      lastRenderedData = SAMPLE;
    }
    render(lastRenderedData);
    // The overview view is the default after upload — mount the
    // interactive Cronbach analyzer next to the RSSI score.
    if (typeof mountOverviewAnalyzer === 'function') mountOverviewAnalyzer();
  };

  /* Lightweight repaint — re-renders the hero (score ring, badge, verdict,
     copy, tier marker, dimension cards, issues) WITHOUT re-mounting the
     analyzer. Used by the interactive Cronbach analyzer so toggling items
     updates the overall RSSI display in real time, but the analyzer
     itself stays alive with its state and scroll position. */
  window.RSSI_UPDATE_FROM_RESULT = function (result) {
    if (!result || typeof result !== 'object') return;
    lastRenderedData = normaliseBlock({ payload: result, addedAt: Date.now() });
    render(lastRenderedData);
    // intentionally NOT calling mountOverviewAnalyzer here
  };

  /* ──────────────────────────────────────────────────────────────
   * VIEW SWITCHING
   * Click any sidebar item → swap the main area to the right view
   * (overview / per-dimension detail / recommendations).
   * No navigation, no studio template — everything stays inside RSSI.
   * ────────────────────────────────────────────────────────────── */
  function showView(viewName, dimensionKey) {
    // Toggle all .rssi-view containers
    const views = document.querySelectorAll('.rssi-view');
    if (!views.length) return; // page without views (e.g. /rssi-report.php old shell)
    views.forEach(function (v) {
      v.hidden = v.dataset.view !== viewName;
    });
    if (viewName === 'detail' && dimensionKey) populateDetailView(dimensionKey);
    if (viewName === 'recommendations')        populateRecommendationsView();
    if (viewName === 'overview')               mountOverviewAnalyzer();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /* Mount the interactive Cronbach analyzer side-by-side with the hero
     score on the overview view. Safe to call repeatedly; mounts only when
     the container exists. */
  function mountOverviewAnalyzer() {
    const mount = document.getElementById('rssiOverviewAnalyzerMount');
    if (!mount || !window.RSSI_RELIABILITY) return;
    window.RSSI_RELIABILITY.mount(mount, {
      projectKey: (window.RSSI_DATASET && window.RSSI_DATASET.source) || 'rssi-upload',
    });
  }

  /* Per-dimension descriptions and rec templates. */
  const DIM_META = {
    survey_structure: {
      desc: 'Whether your survey has a healthy shape: a balanced number of items, an appropriate length, and a clean mix of question types. Long, lopsided, or thin surveys score lower here.',
      recs: [
        'Aim for 15–40 items total — long enough for reliable scales, short enough to keep respondents engaged.',
        'Use at least one categorical / demographic variable so you can break results down by subgroup.',
        'Group related items into scales of 4–6 to make reliability calculations meaningful.',
      ],
    },
    question_quality: {
      desc: 'How well each individual item behaves. Strong items show variance across respondents, low missingness, and avoid ceiling or floor effects (where everyone picks the same end of the scale).',
      recs: [
        'Investigate items flagged for ceiling or floor effects — most respondents picked the same end. Rephrase or split the construct.',
        'Items with low variance often signal a leading question. Reword to invite a fuller range of opinion.',
        'Items with high missingness may be confusing or sensitive. Reword or move them later in the survey.',
      ],
    },
    scale_strength: {
      desc: 'Whether items within each scale hang together statistically (average inter-item correlation). Strong scales have r ≥ 0.30 between items, signalling a coherent underlying construct.',
      recs: [
        'Group items that ask the same underlying question together. Items with weak correlations to the rest probably belong elsewhere.',
        'Consider running a factor analysis to formally validate your scale structure.',
        'Drop or revise items whose item-rest correlation is below 0.20 — they\'re hurting the scale.',
      ],
    },
    reliability_readiness: {
      desc: 'Cronbach\'s α across your Likert items — the gold-standard measure of internal consistency. α ≥ 0.80 is excellent, 0.70 is the publishable threshold, below 0.60 is unreliable.',
      recs: [
        'α below 0.70: add 1–2 more items to the weak scale (more items = higher reliability all else equal).',
        'Revise items with low item-rest correlations — they\'re the ones dragging α down.',
        'Reverse-coded items can sometimes inflate α artificially. Check that your reverse items are scoring consistently.',
      ],
    },
    validity_alignment: {
      desc: 'Whether respondents engaged seriously with your survey — completion rate, missingness patterns, signs of straight-lining or random clicking. Low scores here mean your data may be noisy regardless of how well-designed the instrument is.',
      recs: [
        'Investigate respondents with high item-level missingness — they may have abandoned mid-survey.',
        'Add attention-check items in your next wave to flag inattentive responders.',
        'If completion is low, your survey may be too long. Consider trimming or splitting into waves.',
      ],
    },
    response_risk: {
      desc: 'Whether your open-ended questions are pulling their weight. Low response rates or one-word answers signal questions that respondents found unclear, intrusive, or not worth answering.',
      recs: [
        'Open-ended questions with low response rates may be unclear. Pilot test the wording.',
        'Place open-ended items strategically: after a related closed-ended block, not at the end where fatigue kicks in.',
        'Limit open-ended items to 2–4 per survey to avoid burnout.',
      ],
    },
  };

  /* Sidebar items that don't yet have RSSI-native detail views.
     Each shows a clean coming-soon panel describing what the analysis
     will do — no iframes, no studio template chrome leakage. */
  /* Shared helper: configure the detail-view header (title, band pill,
     score ring, description, finding) before mounting a renderer.
     Score circle starts as "Live" / "—"; renderers call
     window.RSSI_SET_DETAIL_SCORE(value, label, tone) once they have data
     to fill in the headline number and color the ring. */
  function setupDetailHeader(title, badge, finding) {
    document.getElementById('rssiDetailTitle').textContent = title;
    const bandEl = document.getElementById('rssiDetailBand');
    bandEl.textContent = badge;
    bandEl.style.background = '#EEF3FA';
    bandEl.style.color = '#1A6FD9';
    document.getElementById('rssiDetailWeight').textContent = 'Live';
    document.getElementById('rssiDetailScore').textContent = '…';
    document.getElementById('rssiDetailBadge').textContent = 'Computing';
    const C = 2 * Math.PI * 52;
    const fg = document.getElementById('rssiDetailRingFg');
    fg.setAttribute('stroke-dasharray', C.toFixed(2));
    fg.setAttribute('stroke-dashoffset', C.toFixed(2));
    fg.style.stroke = '#D8DDE3';
    document.getElementById('rssiDetailDesc').textContent = title + ' analysis on your current dataset.';
    document.getElementById('rssiDetailFinding').textContent = finding;
  }

  /* Public: let any renderer update the big score circle and badge.
     value: number (0-100) or string. label: badge text. tone: strong/ok/warn/alert. */
  window.RSSI_SET_DETAIL_SCORE = function (value, label, tone) {
    const scoreEl = document.getElementById('rssiDetailScore');
    const badgeEl = document.getElementById('rssiDetailBadge');
    const fg      = document.getElementById('rssiDetailRingFg');
    if (!scoreEl || !badgeEl || !fg) return;
    scoreEl.textContent = (value == null) ? '—' : String(value);
    badgeEl.textContent = label || '—';
    const C = 2 * Math.PI * 52;
    fg.setAttribute('stroke-dasharray', C.toFixed(2));
    const pct = (typeof value === 'number' && isFinite(value)) ? Math.max(0, Math.min(100, value)) : 0;
    fg.setAttribute('stroke-dashoffset', (C * (1 - pct / 100)).toFixed(2));
    const colorMap = { strong: '#34C759', ok: '#007AFF', warn: '#FF9F0A', alert: '#FF3B30' };
    fg.style.stroke = colorMap[tone] || '#007AFF';
  };

  const NATIVE_COMING_SOON = {
    trustworthiness: {
      label: 'Trustworthiness',
      desc:  'Qualitative-research credibility checks on open-ended responses.',
      stats: [
        ['Requires',     'Open-ended responses (at least one open question with substantive answers)'],
        ['Will compute', 'Response rate, average word count, thick-description signal'],
        ['Output',       'Per-question trustworthiness profile with representative quotes'],
      ],
    },
    inter_rater_agreement: {
      label: 'Inter-rater Agreement',
      desc:  'When multiple raters score the same items, measures how much they agree.',
      stats: [
        ['Requires',     'Multi-rater data (multiple ratings of the same target — e.g., 360-feedback panels)'],
        ['Will compute', "Krippendorff's α, Cohen's κ, or intraclass correlation"],
        ['Output',       'Per-rater agreement matrix with disagreement flags'],
      ],
    },
  };

  function populateDetailView(key) {
    if (!lastRenderedData) return;

    // Reliability has a fully-native RSSI view: the interactive Cronbach
    // analyzer on the overview. Bounce the user there.
    if (key === 'reliability_readiness') {
      // Switch to overview view and scroll to the analyzer.
      const ovBtn = document.querySelector('.rssi-app .nav-item[data-view="overview"]');
      if (ovBtn) ovBtn.click();
      setTimeout(function () {
        const mount = document.getElementById('rssiOverviewAnalyzerMount');
        if (mount) mount.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 80);
      return;
    }

    // ─── Validity — Option 1 (iframe scrape) ───
    if (key === 'validity_alignment' && window.RSSI_ANALYSES) {
      setupDetailHeader('Validity Review', 'Option 1 · iframe scrape',
        'Pulls the narrative live from /validity.php?embed=1 via a hidden iframe.');
      window.RSSI_ANALYSES.renderValidityViaIframeScrape(document.getElementById('rssiDetailStats'));
      document.getElementById('rssiDetailRecs').innerHTML = '';
      return;
    }

    // ─── Item / Prompt Quality — Option 2 (ported narrative) ───
    if (key === 'question_quality' && window.RSSI_ANALYSES) {
      setupDetailHeader('Item / Prompt Quality', 'Option 2 · ported narrative',
        'The narrative-generating code from renderItemQuality() is ported into RSSI and runs natively.');
      window.RSSI_ANALYSES.renderItemQualityPorted(document.getElementById('rssiDetailStats'));
      document.getElementById('rssiDetailRecs').innerHTML = '';
      return;
    }

    // ─── Construct Alignment — Option 3 (engine API) ───
    if (key === 'construct_alignment' && window.RSSI_ANALYSES) {
      setupDetailHeader('Construct Alignment', 'Option 3 · engine API',
        'Calls window.IQ_ENGINE.constructAlignmentNarrative() — the engine returns a plain narrative object, RSSI renders it.');
      window.RSSI_ANALYSES.renderConstructAlignmentViaEngineAPI(document.getElementById('rssiDetailStats'));
      document.getElementById('rssiDetailRecs').innerHTML = '';
      return;
    }

    // ─── Remaining instrument-quality lenses — Option 1 (iframe scrape) ───
    const IFRAME_DISPATCH = {
      bias_clarity:    { fn: 'renderBiasClarityIframe',    label: 'Bias & Clarity Review' },
      scale_strength:  { fn: 'renderScaleStructureIframe', label: 'Scale Structure' },
      factor_readiness:{ fn: 'renderFactorReadinessIframe', label: 'Factor Readiness' },
      response_risk:   { fn: 'renderResponseScaleIframe',  label: 'Response Scale Review' },
    };
    if (IFRAME_DISPATCH[key] && window.RSSI_ANALYSES && window.RSSI_ANALYSES[IFRAME_DISPATCH[key].fn]) {
      const c = IFRAME_DISPATCH[key];
      setupDetailHeader(c.label, 'Option 1 · iframe scrape',
        'Pulls narrative live from the corresponding studio analysis page via a hidden iframe in embed mode.');
      window.RSSI_ANALYSES[c.fn](document.getElementById('rssiDetailStats'));
      document.getElementById('rssiDetailRecs').innerHTML = '';
      return;
    }

    // Native coming-soon for the remaining analyses — clean RSSI-styled
    // panel, no iframe, no foreign chrome.
    if (NATIVE_COMING_SOON[key]) {
      const cs = NATIVE_COMING_SOON[key];
      document.getElementById('rssiDetailTitle').textContent = cs.label;
      const bandEl = document.getElementById('rssiDetailBand');
      bandEl.textContent = 'In Development';
      bandEl.style.background = '#FEF3C7';
      bandEl.style.color = '#92400E';
      document.getElementById('rssiDetailWeight').textContent = 'Roadmap';
      document.getElementById('rssiDetailScore').textContent = '—';
      document.getElementById('rssiDetailBadge').textContent = 'In Development';
      const C = 2 * Math.PI * 52;
      const fg = document.getElementById('rssiDetailRingFg');
      fg.setAttribute('stroke-dasharray', C.toFixed(2));
      fg.setAttribute('stroke-dashoffset', C.toFixed(2));
      document.getElementById('rssiDetailDesc').textContent = cs.desc;
      document.getElementById('rssiDetailFinding').textContent =
        'This analysis is being built natively into RSSI. Here\'s what it will deliver:';
      document.getElementById('rssiDetailStats').innerHTML =
        '<dl style="display:grid;grid-template-columns:140px 1fr;gap:14px 24px;margin:0;font-size:14px;line-height:1.55;">' +
        cs.stats.map(function (s) {
          return '<dt style="font-weight:700;color:var(--ink-2,#15171a);margin:0;">' + esc(s[0]) + '</dt>' +
                 '<dd style="margin:0;color:var(--ink-3,#5f6368);">' + esc(s[1]) + '</dd>';
        }).join('') +
        '</dl>';
      document.getElementById('rssiDetailRecs').innerHTML =
        '<div class="issue"><span class="sev sev-low"></span><div><div class="issue-title">In the meantime</div><div class="issue-sub">' +
        'The six-dimension RSSI score on the Overview already incorporates the core signal from this analysis. Once the dedicated view ships, it will give a deeper drill-in on the same data.' +
        '</div></div><div></div><span></span></div>';
      return;
    }

    const comp = (lastRenderedData.components || []).find(function (c) { return c.key === key; });
    if (!comp) return;
    const meta = DIM_META[key] || { desc: '', recs: [] };

    // (Welcome panel lives on the upload page now — nothing to toggle here.)
    // (Interactive Cronbach analyzer now mounts on the OVERVIEW view —
    //  side-by-side with the RSSI hero score — see mountOverviewAnalyzer.)

    document.getElementById('rssiDetailTitle').textContent = comp.label;
    const bandEl = document.getElementById('rssiDetailBand');
    if (comp.skipped) {
      bandEl.textContent = 'Skipped — not enough data';
      bandEl.style.background = '#F0F1F3';
      bandEl.style.color = '#8E8E93';
    } else {
      bandEl.textContent = bandFor(comp.score).label;
      bandEl.style.background = '';
      bandEl.style.color = '';
    }
    document.getElementById('rssiDetailWeight').textContent = '15% of overall score';

    // Score ring
    const scoreEl = document.getElementById('rssiDetailScore');
    scoreEl.textContent = comp.skipped ? '—' : comp.score;
    const badgeEl = document.getElementById('rssiDetailBadge');
    badgeEl.textContent = comp.skipped ? 'Skipped' : bandFor(comp.score).label;
    const C = 2 * Math.PI * 52;
    const fg = document.getElementById('rssiDetailRingFg');
    fg.setAttribute('stroke-dasharray', C.toFixed(2));
    fg.setAttribute('stroke-dashoffset', comp.skipped ? C.toFixed(2) : (C * (1 - (comp.score / 100))).toFixed(2));

    // Description + finding
    document.getElementById('rssiDetailDesc').textContent = meta.desc;
    document.getElementById('rssiDetailFinding').textContent = comp.note || 'No diagnostic note available.';

    // Stats card — show whatever we computed for this dimension
    const statsHtml = comp.skipped
      ? '<p style="color:var(--text-2);font-size:14px;line-height:1.55;margin:0;">This component was skipped because the dataset does not contain enough relevant data. ' + esc(comp.note) + '</p>'
      : [
          '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:18px;">',
            '<div><div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Score</div><div style="font-size:24px;font-weight:600;letter-spacing:-0.02em;">', comp.score, ' / 100</div></div>',
            '<div><div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Band</div><div style="font-size:24px;font-weight:600;letter-spacing:-0.02em;">', esc(bandFor(comp.score).label), '</div></div>',
            '<div><div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Notes</div><div style="font-size:13px;color:var(--text-2);line-height:1.5;">', esc(comp.flag || comp.note || '—'), '</div></div>',
          '</div>',
        ].join('');
    document.getElementById('rssiDetailStats').innerHTML = statsHtml;

    // Recommendations
    const recsHtml = meta.recs.length
      ? meta.recs.map(function (r, i) {
          return [
            '<div class="issue">',
              '<span class="sev sev-low"></span>',
              '<div><div class="issue-title">Suggestion ', (i + 1), '</div><div class="issue-sub">', esc(r), '</div></div>',
              '<div class="issue-scope">', esc(comp.label), '</div>',
              '<span></span>',
            '</div>',
          ].join('');
        }).join('')
      : '<div class="issue"><span class="sev sev-low"></span><div><div class="issue-title">No specific recommendations</div></div><div></div><span></span></div>';
    document.getElementById('rssiDetailRecs').innerHTML = recsHtml;
  }

  function populateRecommendationsView() {
    if (!lastRenderedData) return;
    const list = document.getElementById('rssiRecsList');
    if (!list) return;
    if (!lastRenderedData.issues || !lastRenderedData.issues.length) {
      list.innerHTML = '<div class="issue"><span class="sev sev-low"></span><div><div class="issue-title">No critical issues</div><div class="issue-sub">Every diagnostic dimension scored at &ldquo;Good&rdquo; or better.</div></div><div class="issue-scope">All clear</div><span></span></div>';
      return;
    }
    list.innerHTML = lastRenderedData.issues.map(function (i) {
      return [
        '<a class="issue" href="', esc(i.fix_route), '">',
          '<span class="sev sev-', i.sev, '"></span>',
          '<div>',
            '<div class="issue-title">', esc(i.title), '</div>',
            '<div class="issue-sub">',   esc(i.sub),   '</div>',
          '</div>',
          '<div class="issue-scope">', esc(i.scope), '</div>',
          '<span class="issue-fix">Open →</span>',
        '</a>',
      ].join('');
    }).join('');
  }

  function wireSidebar() {
    document.querySelectorAll('.rssi-app .nav-item[data-view]').forEach(function (item) {
      item.addEventListener('click', function (e) {
        e.preventDefault();
        const view = item.dataset.view;
        const dim  = item.dataset.dimension;

        if (view === 'print') { window.print(); return; }

        // Update active state
        document.querySelectorAll('.rssi-app .nav-item').forEach(function (n) { n.classList.remove('active'); });
        item.classList.add('active');

        showView(view, dim);
      });
    });

    // Back-to-overview buttons inside detail / recs views
    document.querySelectorAll('.back-to-overview').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.rssi-app .nav-item').forEach(function (n) { n.classList.remove('active'); });
        const ovBtn = document.querySelector('.rssi-app .nav-item[data-view="overview"]');
        if (ovBtn) ovBtn.classList.add('active');
        showView('overview');
      });
    });
  }

  /* ──────────────────────────────────────────────────────────────
   * INIT
   * ────────────────────────────────────────────────────────────── */
  function init() {
    // Priority order:
    //   1. window.RSSI_RESULT (set by rssi-upload.js after parse)
    //   2. saved strength_index block in localStorage
    //   3. sample data
    if (window.RSSI_RESULT) {
      lastRenderedData = normaliseBlock({ payload: window.RSSI_RESULT, addedAt: Date.now() });
    } else {
      const block = loadStrengthBlock();
      if (block) lastRenderedData = normaliseBlock(block);
      else       lastRenderedData = SAMPLE;
    }
    render(lastRenderedData);

    /* New view-switching sidebar (data-view + data-dimension attributes) */
    wireSidebar();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
