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
   * SAMPLE DATA — v2 shape (Spec §3.2, §3.5, §3.6).
   *
   * Picked to exercise the disagreement readout AND the validity-
   * forward cap pill on first render:
   *   PC = 78, RC = 82, VF = 65
   *   spread = 17 > 10 → readout fires (Spec §3.5)
   *   VF is the lowest by > 10 vs next-lowest → "validity_forward_low"
   *   sentence selected (Spec §3.6 cap pattern)
   *   validity_forward_capped = true → "Limited evidence" pill on VF
   *
   * Domain sub-scores are illustrative; the renderer does not
   * recompute lens scores from them. They're used to paint the
   * eight dim cards and to colour the explain-panel bands.
   * ────────────────────────────────────────────────────────────── */
  const SAMPLE = {
    strength: 82,
    verdict: 'Strong',
    rssi: {
      psychometric_core:       78,
      respondent_centered:     82,
      validity_forward:        65,
      headline_lens:           'respondent_centered',
      disagreement_readout:    'Your survey is internally consistent, but you may not yet have evidence it measures what you claim. Add criterion data or pilot against an established instrument.',
      validity_forward_capped: true,
    },
    domains: {
      reliability:           { score: 86, note: 'α = 0.83 across two scales; ω = 0.85.' },
      validity:              { score: 60, note: 'Criterion sub-component skipped (no criterion column tagged). Cap engaged per §3.6.' },
      construct_alignment:   { score: 78, note: 'Items load cleanly on their assigned constructs; modest cross-loadings on two items.' },
      item_prompt_quality:   { score: 84, note: '4 items flagged as double-barreled. Reading level mostly grade 7–9.' },
      bias_clarity:          { score: 72, note: 'Wording is generally clear; fairness sub-component skipped (no demographic columns).' },
      scale_structure:       { score: 79, note: '6 scales reviewed; reverse-coded balance unconfirmed on two scales.' },
      factor_readiness:      { score: 81, note: 'KMO = 0.82; Bartlett significant; correlation determinant healthy.' },
      response_scale_review: { score: 88, note: 'Length reasonable (~7 min). Anchor counts consistent across scales.' },
    },
    issues: [
      { sev: 'high', title: 'Validity-Forward capped — no criterion data',     sub: 'Tag a criterion column in your dataset to remove the cap and score criterion validity.',   scope: 'Validity',            fix_route: '#dim-validity' },
      { sev: 'high', title: '4 double-barreled items detected',                sub: 'Items combine two ideas, making responses ambiguous.',                                  scope: 'Item / Prompt Quality', fix_route: '#dim-item_prompt_quality' },
      { sev: 'med',  title: 'Reverse-coded balance unconfirmed on 2 scales',   sub: 'Tick the survey-level reverse-coding confirmation to activate §4E sub-2.',              scope: 'Scale Structure',     fix_route: '#dim-scale_structure' },
      { sev: 'low',  title: '"Belonging" construct has only 2 items',          sub: 'Construct underweighted vs. others. Consider adding 1 more item.',                      scope: 'Construct Alignment', fix_route: '#dim-construct_alignment' },
    ],
    priorities: [
      { title: 'Add criterion data',          sub: 'Tag a numeric outcome column to remove the Validity-Forward cap.', meta: 'Est. lift +10 on VF · ~5 min' },
      { title: 'Revise 4 double-barreled items', sub: 'Split each into two single-idea questions.',                       meta: 'Est. lift +4 · ~10 min' },
      { title: 'Confirm reverse-coding',         sub: 'Tick the survey-level confirmation to activate §4E sub-2.',        meta: 'Est. lift +2 · ~3 min'  },
    ],
    summary: 'At 82, this survey is in strong shape on the respondent-centered lens, but the validity-forward lens lags — pilot data or criterion evidence would lift it.',
    confidence: 'High',
    item_count: 32,
    scale_count: 6,
    response_count: 250,
    methods_paragraph: 'Internal consistency of the instrument was evaluated using the ReliCheck Strength Survey Index (v2.0). The analysis comprised 24 Likert items grouped into 6 scales, with responses from 250 participants. Composite internal consistency was Cronbach’s α = 0.85 and McDonald’s ω = 0.87. Convergent validity assessed via mean corrected item-total correlations averaged 0.42 across scales; discriminant validity assessed via the Heterotrait-Monotrait ratio (Henseler, Ringle, & Sarstedt, 2015) reached a maximum of 0.62 across scale pairs. Confirmatory factor analysis per scale produced a mean CFI of 0.95 and a mean RMSEA of 0.052 across 6 scoreable scales. Factorability was supported by a Kaiser-Meyer-Olkin sampling adequacy of 0.82 and Bartlett’s test of sphericity, χ²(36) = 234.50, p < .001. Analysis was performed on 2026-05-28.',
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
   * Canonical 8-domain taxonomy (Spec §2). The order here drives the
   * 4×2 dim-grid render order: row 1 = core psychometrics, row 2 =
   * instrument design. The labels are the spec-mandated strings; v1
   * synonyms ("Reliability Readiness", "Scale Strength", "Validity
   * Alignment", "Response Risk", "Survey Structure", "Question
   * Quality") are not used anywhere downstream.
   * ────────────────────────────────────────────────────────────── */
  const V2_DOMAINS = [
    { key: 'reliability',           label: 'Reliability' },
    { key: 'validity',              label: 'Validity' },
    { key: 'construct_alignment',   label: 'Construct Alignment' },
    { key: 'factor_readiness',      label: 'Factor Readiness' },
    { key: 'item_prompt_quality',   label: 'Item / Prompt Quality' },
    { key: 'bias_clarity',          label: 'Bias & Clarity Review' },
    { key: 'scale_structure',       label: 'Scale Structure' },
    { key: 'response_scale_review', label: 'Response Scale Review' },
  ];

  /* The sidebar's data-dimension attributes are a mix of v1 and v2 keys
     (rssi-upload.php carries them as-is; Q7 directive keeps the sidebar
     markup unchanged). Normalize at the click handler / route boundary. */
  const SIDEBAR_KEY_TO_V2 = {
    reliability_readiness: 'reliability',
    validity_alignment:    'validity',
    construct_alignment:   'construct_alignment',
    question_quality:      'item_prompt_quality',
    bias_clarity:          'bias_clarity',
    scale_strength:        'scale_structure',     // sidebar label is "Scale Structure"
    factor_readiness:      'factor_readiness',
    response_risk:         'response_scale_review',
  };

  /* ──────────────────────────────────────────────────────────────
   * Normalise an engine block's payload into the RSSI render shape.
   *
   * v2 input shape (from rssi-upload.js computeRSSI):
   *   { strength, verdict, summary, rssi:{three lenses + readout + cap},
   *     domain_subscores:{8 canonical keys → number},
   *     domain_details:{8 canonical keys → { score, note, interp, … }},
   *     dataset, computed_at }
   *
   * SAMPLE shorthand also accepted — uses `domains` (not
   * `domain_details`) and skips `domain_subscores`.
   *
   * Returns a render-ready object:
   *   { strength, verdict, rssi, components:[{key,label,score,…}],
   *     issues, priorities, summary, confidence, … }
   *
   * `components` is an ordered 8-item array in V2_DOMAINS order — not
   * sorted by score (the 4×2 grid is positional). The v1 components
   * map is gone; nothing downstream of this function knows about v1
   * keys.
   * ────────────────────────────────────────────────────────────── */
  function normaliseBlock(block) {
    const p = block.payload || block;
    const details   = p.domain_details   || p.domains || {};
    const subscores = p.domain_subscores || {};
    const rssi      = p.rssi             || {};

    const components = V2_DOMAINS.map(function (d) {
      const det = details[d.key] || {};
      const subscore = (typeof det.score === 'number') ? det.score
                     : (typeof subscores[d.key] === 'number') ? subscores[d.key]
                     : null;
      const skipped = subscore == null;
      const score = skipped ? null : Math.round(Number(subscore) || 0);
      return {
        key:     d.key,
        label:   d.label,                              // always canonical
        score:   score,
        skipped: skipped,
        status:  skipped ? 'skipped' : statusFor(score),
        note:    det.interp || det.note || (skipped ? 'Not enough data to score this domain.' : ''),
        flag:    det.flag   || (skipped ? 'Not enough data' : ''),
      };
    });

    // Build issues from low-scoring canonical domains + add the cap
    // as a top-priority issue when engaged. Cap is a structural finding,
    // not a score band — surface it with high severity regardless of
    // VF's number.
    const issues = p.issues && Array.isArray(p.issues) ? p.issues.slice(0, 5) : (function () {
      const out = [];
      if (rssi.validity_forward_capped) {
        out.push({
          sev:       'high',
          title:     'Validity-Forward capped — no criterion evidence',
          sub:       'Tag a criterion column in your dataset to remove the cap and score criterion validity.',
          scope:     'Validity',
          fix_route: '#dim-validity',
        });
      }
      components.filter(function (c) { return !c.skipped && c.score < 75; })
        .sort(function (a, b) { return a.score - b.score; })
        .slice(0, 5 - out.length)
        .forEach(function (c) {
          out.push({
            sev:       c.score < 55 ? 'high' : (c.score < 70 ? 'med' : 'low'),
            title:     c.label + ' score: ' + c.score + '/100',
            sub:       c.note,
            scope:     c.label,
            fix_route: '#dim-' + c.key,
          });
        });
      return out;
    })();

    const priorities = p.priorities && Array.isArray(p.priorities) ? p.priorities.slice(0, 3) :
      issues.slice(0, 3).map(function (issue) {
        return {
          title: 'Improve ' + issue.scope,
          sub:   issue.sub,
          meta:  'Drill in for details',
        };
      });

    return {
      strength:       Math.round(Number(p.strength) || 0),
      verdict:        p.verdict || bandFor(p.strength || 0).label,
      rssi:           {
        psychometric_core:       (typeof rssi.psychometric_core   === 'number') ? Math.round(rssi.psychometric_core)   : null,
        respondent_centered:     (typeof rssi.respondent_centered === 'number') ? Math.round(rssi.respondent_centered) : null,
        validity_forward:        (typeof rssi.validity_forward    === 'number') ? Math.round(rssi.validity_forward)    : null,
        headline_lens:           rssi.headline_lens || 'respondent_centered',
        disagreement_readout:    rssi.disagreement_readout || null,
        validity_forward_capped: !!rssi.validity_forward_capped,
      },
      components:     components,
      issues:         issues,
      priorities:     priorities,
      summary:        p.summary || '',
      confidence:     confidenceFor(p),
      item_count:     (p.dataset && p.dataset.itemCount)  || components.length,
      scale_count:    (p.dataset && p.dataset.scaleCount) || 0,
      response_count: (p.dataset && p.dataset.rowCount)   || 0,
      computed_at:    p.computed_at || block.addedAt,
      isSample:       false,
      // Spec §8.1 — engine-composed research methods paragraph
      // (KNOWN_ISSUES #21 fix). Null when minimum data missing.
      methods_paragraph: (typeof p.methods_paragraph === 'string' && p.methods_paragraph.trim() !== '')
        ? p.methods_paragraph
        : null,
    };
  }
  function confidenceFor(p) {
    const n = (p.dataset && p.dataset.rowCount) || 0;
    if (n >= 200) return 'High';
    if (n >= 80)  return 'Moderate';
    if (n >= 30)  return 'Low';
    return 'Pilot';
  }
  /* ──────────────────────────────────────────────────────────────
   * Friendly icon picker for dimension cards. Keyed on canonical
   * 8-domain taxonomy (Spec §2).
   * ────────────────────────────────────────────────────────────── */
  function iconForKey(key) {
    const icons = {
      reliability:           '<path d="M8 1.5 2.5 3.8v3.7c0 3.4 2.4 6.2 5.5 7 3.1-.8 5.5-3.6 5.5-7V3.8L8 1.5Z" stroke-linejoin="round"/>',
      validity:              '<circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/>',
      construct_alignment:   '<rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7" width="9" height="3" rx="1"/><rect x="2" y="11" width="6" height="3" rx="1"/>',
      factor_readiness:      '<circle cx="5" cy="5" r="2"/><circle cx="11" cy="5" r="2"/><circle cx="5" cy="11" r="2"/><circle cx="11" cy="11" r="2"/><path d="M5 7v2M11 7v2M7 5h2M7 11h2" stroke-linecap="round"/>',
      item_prompt_quality:   '<circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/>',
      bias_clarity:          '<circle cx="11" cy="7" r="4"/><path d="M2 14l5-5M14 14l-2-2"/>',
      scale_structure:       '<path d="M3 13V3M3 13h10M5.5 11V8M8 11V5M10.5 11V9" stroke-linecap="round"/>',
      response_scale_review: '<path d="M3 8h10M3 4h10M3 12h6"/><circle cx="6" cy="8" r="1" fill="currentColor"/><circle cx="10" cy="4" r="1" fill="currentColor"/>',
    };
    return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6">' +
      (icons[key] || icons.item_prompt_quality) + '</svg>';
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

    /* ─── v2 lens triplet + float + lens-row bands + pulse-on-change ───
       _paintScoreSummary writes the inline triplet AND the floating
       sticky bar AND the lens explain-row band pills in the same
       tick. Single source of truth; surfaces cannot drift. */
    _paintScoreSummary(d);
    /* ─── v2 disagreement readout (Spec §3.5) ─── */
    renderDisagreementReadout(d.rssi || {});
    /* ─── §8.1 methods paragraph (KNOWN_ISSUES #21 fix) ─── */
    renderMethodsParagraph(d.methods_paragraph);
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

    /* Print-only cover block (KNOWN_ISSUES #22 polish). Mirror the
       on-screen survey title + scored date into the .rssi-print-cover
       elements so the exec-board PDF reads them from the same source
       of truth. The title is filename-derived and often arrives as
       e.g. "MM Test Emotional_Intelligence_QuanHeavy" — strip the
       file extension and replace underscores with spaces for the
       cover treatment. Hidden on screen via CSS. */
    const coverTitleEl = document.getElementById('rssiPrintCoverTitle');
    if (coverTitleEl) {
      const sourceTitle = document.getElementById('rssiDashTitle');
      let title = (sourceTitle && sourceTitle.textContent && sourceTitle.textContent.trim())
        ? sourceTitle.textContent.trim()
        : 'Survey report';
      // Strip any lingering extension (handoff usually already does
      // this, but Survey-Studio-loaded paths may not) and convert
      // underscores to spaces for cover-quality display.
      title = title.replace(/\.(csv|xlsx?|tsv|txt)$/i, '').replace(/_/g, ' ');
      coverTitleEl.textContent = title;
    }
    const coverDateEl = document.getElementById('rssiPrintCoverDate');
    if (coverDateEl && d.computed_at) {
      const w = new Date(d.computed_at);
      coverDateEl.textContent = w.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
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
        '<a class="dim-mini" id="dim-', esc(c.key), '" href="#dim-', esc(c.key), '" title="', esc(c.label), ' — ', esc(c.note || ''), '">',
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
   *  Single source of truth for every score-summary surface.
   *
   *  Writes both the inline triplet (next to the ring) AND the
   *  floating sticky bar (Tier 1 ring + lens chips + Tier 2 eight
   *  domain dots) AND the three lens explain-row band pills in the
   *  same tick. Inline + float read from one `d` object, so they
   *  cannot drift between renders. Pulse-on-change diff against
   *  `_prevPaint` so toggles produce a soft 300ms tint on values
   *  that actually moved; first render has no `_prevPaint` so no
   *  initial-render pulse.
   *
   *  Called from render(). The old name `renderLensTriplet` is kept
   *  as a synonym so the render() call site continues to read clean.
   * ──────────────────────────────────────────────────────────── */
  let _prevPaint = null;

  function renderLensTriplet(rssi) { _paintScoreSummary(lastRenderedData || { rssi: rssi, strength: null, components: [] }); }

  function _paintScoreSummary(d) {
    const rssi = d.rssi || {};
    const components = d.components || [];

    // Build a flat snapshot of every paintable value. Comparing this
    // against `_prevPaint` produces the pulse-targets.
    const snap = {
      strength: d.strength,
      lens_psychometric_core:   rssi.psychometric_core,
      lens_respondent_centered: rssi.respondent_centered,
      lens_validity_forward:    rssi.validity_forward,
      cap: !!rssi.validity_forward_capped,
    };
    components.forEach(function (c) { snap['dom_' + c.key] = c.skipped ? null : c.score; });

    const hadPrev = _prevPaint !== null;
    function changed(k) { return hadPrev && _prevPaint[k] !== snap[k]; }
    function pulseEl(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('pulse-update');
      // Force reflow so the animation restarts on rapid re-triggers.
      void el.offsetWidth;
      el.classList.add('pulse-update');
    }
    function pulseChanged(key, ids) {
      if (!changed(key)) return;
      ids.forEach(pulseEl);
    }

    /* ── Inline lens chips ─────────────────────────────────────── */
    const LENS_KEYS = ['psychometric_core', 'respondent_centered', 'validity_forward'];
    LENS_KEYS.forEach(function (k) {
      const v = rssi[k];
      const inlineEl = document.getElementById('rssiLens_' + k);
      const floatEl  = document.getElementById('rssiFloatLens_' + k);
      const txt = (typeof v === 'number') ? String(v) : '—';
      if (inlineEl) inlineEl.textContent = txt;
      if (floatEl)  floatEl.textContent  = txt;
    });

    /* ── Cap pill (inline triplet + float + lens explain row) ──── */
    const capPills = [
      document.getElementById('rssiLensCapPill'),
      document.getElementById('rssiFloatCapPill'),
      document.getElementById('explainCapPill_validity_forward'),
    ];
    capPills.forEach(function (cap) {
      if (!cap) return;
      if (rssi.validity_forward_capped) {
        cap.hidden = false;
        cap.setAttribute('title', 'Criterion validity sub-component was skipped (no criterion column tagged). This is a data-availability cap, not a validity failure. Spec §3.6.');
      } else {
        cap.hidden = true;
        cap.removeAttribute('title');
      }
    });

    /* No float ring: the Respondent-Centered chip carries that number,
       so a ring in the float would be redundant. The inline hero
       keeps the full ring. */

    /* ── Float domain dots ─────────────────────────────────────── */
    components.forEach(function (c) {
      const scoreEl = document.getElementById('rssiFloatDot_' + c.key);
      if (scoreEl) scoreEl.textContent = c.skipped ? '—' : String(c.score);
      // The mark dot color tracks status — query by the surrounding row.
      const wrapEl = scoreEl ? scoreEl.closest('.rssi-float-dot') : null;
      if (wrapEl) {
        wrapEl.className = 'rssi-float-dot status-' + (c.skipped ? 'skipped' : c.status);
      }
    });

    /* ── Lens explain-row band pills (static body lives in PHP) ── */
    LENS_KEYS.forEach(function (k) {
      const v = rssi[k];
      const bandEl = document.getElementById('explainBand_lens_' + k);
      if (!bandEl) return;
      if (typeof v !== 'number') {
        bandEl.textContent = '—';
        bandEl.className = 'explain-band';
        return;
      }
      const b = bandFor(v);
      bandEl.textContent = b.label;
      bandEl.className = 'explain-band status-' + b.status;
    });

    /* ── Pulse changed values (both surfaces in sync) ──────────── */
    /* The float has no ring; only the inline ring pulses on `strength`. */
    pulseChanged('strength', ['rssiScore']);
    LENS_KEYS.forEach(function (k) {
      pulseChanged('lens_' + k, ['rssiLens_' + k, 'rssiFloatLens_' + k]);
    });
    components.forEach(function (c) {
      // Inline dim card score lives inside .dim-mini; the float dot.
      const inlineDim = document.getElementById('dim-' + c.key);
      const inlineNum = inlineDim ? inlineDim.querySelector('.dim-mini-num') : null;
      if (changed('dom_' + c.key)) {
        if (inlineNum) {
          inlineNum.classList.remove('pulse-update');
          void inlineNum.offsetWidth;
          inlineNum.classList.add('pulse-update');
        }
        pulseEl('rssiFloatDot_' + c.key);
      }
    });

    _prevPaint = snap;
  }

  /* Reset the paint cache so the next render() does not pulse. Used
     when a fresh dataset arrives (upload → score → handoff) and the
     "change" semantics no longer apply against the prior dataset. */
  function _resetPaintCache() { _prevPaint = null; }

  /* ────────────────────────────────────────────────────────────
   *  v2 disagreement readout (Spec §3.5).
   *
   *  Build to the engine's null contract: when rssi.disagreement_readout
   *  is null, no row exists in the DOM. No empty container, no
   *  "lenses agree" filler.
   * ──────────────────────────────────────────────────────────── */
  function renderDisagreementReadout(rssi) {
    const slot = document.getElementById('rssiDisagreementSlot');
    if (!slot) return;
    const sentence = rssi.disagreement_readout;
    if (!sentence) {
      slot.innerHTML = '';
      return;
    }
    slot.innerHTML =
      '<div class="explain-row explain-disagreement" data-explain="disagreement">' +
        '<div class="explain-head">' +
          '<span class="explain-label">Lens disagreement</span>' +
          '<span class="explain-band status-fair">Spread &gt; 10</span>' +
        '</div>' +
        '<p class="explain-text"><strong>What the spread tells you:</strong> ' + esc(sentence) + '</p>' +
      '</div>';
  }

  /* ────────────────────────────────────────────────────────────
   *  §8.1 methods paragraph (KNOWN_ISSUES #21).
   *
   *  Engine-composed research methods paragraph the user can paste
   *  into a paper. Hidden when null (no minimum reliability data).
   *  Two surfaces share the same DOM ids (rssi-upload.php dashboard
   *  and apps/rssi/render.php report viewer) so this helper paints
   *  both unconditionally.
   * ──────────────────────────────────────────────────────────── */
  function renderMethodsParagraph(text) {
    const card = document.getElementById('rssiMethodsParagraph');
    if (!card) return;
    const body = document.getElementById('rssiMethodsBody');
    if (!text || typeof text !== 'string' || !text.trim()) {
      card.hidden = true;
      card.setAttribute('aria-hidden', 'true');
      if (body) body.textContent = '';
      return;
    }
    if (body) body.textContent = text;
    card.hidden = false;
    card.setAttribute('aria-hidden', 'false');
  }

  /* ────────────────────────────────────────────────────────────
   *  EXPLANATION TEXTS — one paragraph per canonical v2 domain per band.
   *  Copy describes what each domain actually measures (Spec §4–§4G).
   *  Picked at render time based on the current score.
   * ──────────────────────────────────────────────────────────── */
  const EXPLAIN = {
    /* The Overall row interprets the headline lens (Respondent-Centered,
       Spec §3.4) — "how well does this survey work for the people taking
       it?" Wording is band-specific. */
    overall: {
      excellent: 'Your survey is in excellent shape on the respondent-centered lens. It is reliable enough to publish, share with stakeholders, or use as the basis for confident decisions.',
      strong:    'Your survey is in strong shape on the respondent-centered lens. It is ready to field or share, and a few targeted fixes would push it into the excellent range.',
      good:      'Your survey is functional but has notable weak spots. Focused revisions to the lowest-scoring domains below would meaningfully lift its credibility.',
      fair:      'Your survey needs work before its results can be relied on. Several domains are below acceptable thresholds for publication or decision-making.',
      weak:      'Significant revision is needed before this survey produces trustworthy data. Multiple core domains require attention.',
    },

    /* §4 Reliability — internal consistency: Cronbach\'s α, McDonald\'s ω,
       α–ω agreement, item-rest correlations, redundancy check. */
    reliability: {
      excellent: 'Internal consistency is excellent. Cronbach\'s α and McDonald\'s ω both clear 0.85, item-rest correlations are healthy, and no redundancy is flagged. Scales are reliable enough for high-stakes decisions and publication.',
      strong:    'Internal consistency meets the publishable threshold (α and ω ≥ 0.70 on every scale). Item-rest correlations are mostly healthy.',
      good:      'Reliability is acceptable but below ideal on at least one scale. Adding one or two items to weaker scales, or revising items with weak item-rest correlations, would push α and ω above 0.80.',
      fair:      'α or ω falls below 0.70 on at least one scale. Revise items with weak item-rest correlations and confirm reverse-coding before treating the results as reliable.',
      weak:      'Scales lack internal consistency (α or ω below 0.60). Major revision is required before reliability can be claimed.',
      skipped:   'Need at least one scale of two or more Likert items, tagged with a construct, to compute reliability.',
    },

    /* §4A Validity — convergent (avg item-total correlation), discriminant
       (HTMT ratio), criterion (correlation with criterion column).
       Validity-Forward cap engages when criterion is skipped (§3.6). */
    validity: {
      excellent: 'Validity evidence is excellent. Convergent (within-scale item-total correlations) is strong, discriminant (HTMT) clears 0.85 on every scale pair, and criterion correlations are meaningful.',
      strong:    'Validity evidence is solid. Convergent and discriminant criteria are met. The criterion sub-component contributes when a criterion column is tagged.',
      good:      'Validity evidence is mixed. HTMT may be elevated on one scale pair, or criterion correlations are modest. Investigate scales with high cross-correlations.',
      fair:      'Validity evidence is thin. Multiple scales show weak convergent correlations or HTMT above 0.90. The Validity-Forward lens is likely lower than the other two.',
      weak:      'Validity evidence is insufficient. Convergent correlations are weak, or HTMT exceeds 0.95 (scales are not distinct), or criterion correlations are absent or near zero.',
      skipped:   'Need at least two tagged constructs to evaluate convergent and discriminant validity. Tag constructs in the tag stage.',
    },

    /* §4B Construct Alignment — per-scale CFA loadings, fit indices,
       cross-loadings. "Do items load on the construct they should?" */
    construct_alignment: {
      excellent: 'Items load cleanly on their assigned constructs. Per-scale factor loadings are strong (≥ 0.70), and cross-loadings on other constructs are minimal.',
      strong:    'Construct alignment is solid. Most items load on their assigned construct with no problematic cross-loadings.',
      good:      'Some items show modest cross-loadings on other constructs. Review whether they belong on a different scale or need rewording.',
      fair:      'Multiple items load weakly on their assigned construct or load more strongly on a different one. Construct assignments may need revision.',
      weak:      'The factor structure does not match the assigned construct labels. Run an exploratory factor analysis before re-fielding.',
      skipped:   'Need at least two constructs with three or more items each to evaluate construct alignment. Tag constructs in the tag stage.',
    },

    /* §4F Factor Readiness — KMO sampling adequacy, Bartlett's sphericity,
       correlation-matrix determinant. "Is there factor structure to find?"
       (Note: NOT internal consistency — that's §4 Reliability.) */
    factor_readiness: {
      excellent: 'Your item correlations are factorable. KMO sampling adequacy is excellent (≥ 0.80), Bartlett\'s test of sphericity is significant, and the correlation determinant indicates no harmful multicollinearity. The data is well-suited for factor analysis.',
      strong:    'Item correlations are factorable. KMO clears 0.70 and Bartlett\'s is significant. Factor analysis will produce stable, interpretable results.',
      good:      'Factorability is adequate but not strong. KMO sits in the 0.60–0.69 "mediocre" band, or near-orthogonality reduces shared variance. Factor analysis is workable but expect modest fit.',
      fair:      'Factorability is questionable. KMO is below 0.60 or items are too near-orthogonal to share variance. Factor analysis may produce unstable results.',
      weak:      'Items do not share enough variance to be factor-analyzable — they may be measuring unrelated things. This is a structural finding about the data, not a quality failure of any one scale.',
      skipped:   'Need at least three Likert items and a sample size sufficient for the correlation matrix to invert. Tag more Likert items in the tag stage.',
    },

    /* §4C Item / Prompt Quality — per-item statistical health for Likert
       (variance, missingness, ceiling/floor) + open-ended response health. */
    item_prompt_quality: {
      excellent: 'Every Likert item sits in a healthy range — no ceiling/floor effects, no low-variance items, no high-missingness items. Open-ended items (if present) have strong response rates and substantive answers.',
      strong:    'Most items perform well. A handful of minor flags but nothing that demands immediate action.',
      good:      'Some items show ceiling/floor effects, low variance, or elevated missingness. Review the flagged items in the Item-Rest table; consider rewording.',
      fair:      'Multiple items have statistical quality issues that compromise the scales they belong to. Revise or remove the worst performers before relying on results.',
      weak:      'Widespread item-quality problems. Most items need revision before re-fielding.',
      skipped:   'No Likert items to evaluate.',
    },

    /* §4D Bias & Clarity — wording (reading grade, double-barreled, leading/
       loaded language) + fairness (DIF proxy across demographic slices). */
    bias_clarity: {
      excellent: 'Item wording is clear (reading grade appropriate, no double-barreled items, no leading or loaded language detected), and fairness analysis across demographic slices shows no differential item functioning.',
      strong:    'Wording is generally clear; a handful of items could be tightened but none compromise interpretation. Fairness analysis is clean where demographic columns are tagged.',
      good:      'Some items are double-barreled, written above an appropriate reading grade, or carry leading wording. Fairness analysis flags one or two items for review.',
      fair:      'Multiple items have wording issues that may confuse respondents or steer answers. Revise before re-fielding.',
      weak:      'Widespread wording problems or substantial differential item functioning across demographic groups. Wording revisions and fairness review are required.',
      skipped:   'No Likert items to evaluate. (Fairness analysis additionally requires demographic columns tagged in the tag stage.)',
    },

    /* §4E Scale Structure — item count per scale, reverse-coded balance,
       response-format uniformity, scale-level missingness pattern. */
    scale_structure: {
      excellent: 'Every scale has enough items (≥ 4), reverse-coded items are balanced and confirmed, response formats are uniform within each scale, and missingness patterns are clean.',
      strong:    'Scale structure is solid. Item counts are healthy, reverse-coded balance is confirmed, and no scale shows problematic missingness.',
      good:      'Scale structure is workable but has flags. One or more scales may be light on items, or reverse-coded items may be unconfirmed at the survey level.',
      fair:      'Multiple structural issues — light item counts on at least one scale, mixed response formats within a scale, or unconfirmed reverse-coding.',
      weak:      'Scale structure needs fundamental revision. Item counts are too low to estimate reliability, or response formats are mixed within scales.',
      skipped:   'Need at least one scale of tagged Likert items to evaluate scale structure.',
    },

    /* §4G Response Scale Review — Likert design (anchor count, midpoint,
       symmetry, single-format-per-scale) + respondent behavior (completion,
       missingness, straight-lining). */
    response_scale_review: {
      excellent: 'Likert design is well-chosen (consistent anchor count, midpoint present, balanced endpoint labels), and respondent behavior is healthy (high completion, low missingness, minimal straight-lining).',
      strong:    'Likert design choices are sound. Respondents engaged seriously — completion is high and missingness patterns are minimal.',
      good:      'Likert design is workable but has flags (mixed anchor counts across scales, or no midpoint where one would help). Respondent behavior is acceptable with some patches of higher missingness.',
      fair:      'Likert-design or respondent-behavior issues. Anchor counts may differ across scales, or straight-lining is detectable in a meaningful share of responses.',
      weak:      'Serious response-scale or respondent-behavior issues. Anchor choices are inconsistent, or straight-lining is widespread enough to undermine the data.',
      skipped:   'Need at least one tagged Likert item to evaluate response-scale design.',
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
    // SAMPLE is in v2 input shape; normaliseBlock turns it into the
    // render-ready shape with `components` populated from V2_DOMAINS.
    const src = (result && typeof result === 'object') ? result : SAMPLE;
    lastRenderedData = normaliseBlock({ payload: src, addedAt: Date.now() });
    if (!result || typeof result !== 'object') lastRenderedData.isSample = true;
    // Fresh dataset → reset pulse cache so the first paint does not
    // diff against the prior dataset's values.
    _resetPaintCache();
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

  /* Per-domain descriptions + rec templates for the detail view.
     Keyed on canonical 8-domain taxonomy (Spec §2). */
  const DIM_META = {
    reliability: {
      desc: 'Internal consistency of your scales: Cronbach\'s α, McDonald\'s ω, α–ω agreement, item-rest correlations, and redundancy detection. The gold-standard "do items in this scale measure the same thing?" question.',
      recs: [
        'α or ω below 0.70: add 1–2 more items to the weak scale (more items lift reliability all else equal).',
        'Revise items with weak item-rest correlations — they\'re dragging the scale down.',
        'Confirm reverse-coding at the survey level — unconfirmed reverse items can artificially deflate α.',
      ],
    },
    validity: {
      desc: 'Three sub-components: convergent (within-scale item-total correlations), discriminant via HTMT (do scales measure distinct things?), and criterion (do scales correlate with an outcome?). The Validity-Forward lens is capped when criterion evidence is absent (Spec §3.6).',
      recs: [
        'Tag a numeric outcome column as Criterion in the tag stage to remove the Validity-Forward cap.',
        'High HTMT between two scales (> 0.90) suggests they are not actually distinct constructs — consider merging or revising one.',
        'Weak convergent correlations point at items that don\'t belong on the construct they were assigned to.',
      ],
    },
    construct_alignment: {
      desc: 'Per-scale CFA loadings and cross-loadings: do items load on the construct they were assigned to, and do they avoid loading on other constructs? Cross-loadings often indicate items that need rewording or reassignment.',
      recs: [
        'Items with cross-loadings on a different construct often belong on that other construct — try reassigning and re-scoring.',
        'Items with weak loadings on their assigned construct may be measuring something else; consider dropping them.',
        'If multiple items load on a single "reverse-coded" cluster across scales, your reverse-coding direction is the problem, not the constructs.',
      ],
    },
    factor_readiness: {
      desc: 'Factorability of your item correlations: KMO sampling adequacy, Bartlett\'s test of sphericity, and correlation-matrix determinant. Answers the structural question "is there a factor structure to find?" — separate from how well any one scale is performing.',
      recs: [
        'KMO < 0.60 means items don\'t share enough variance — factor analysis won\'t produce stable results on this dataset.',
        'A correlation determinant near zero indicates multicollinearity — at least two items are near-duplicates.',
        'Near-orthogonal items (low KMO with significant Bartlett) signal items that may not belong on the same instrument.',
      ],
    },
    item_prompt_quality: {
      desc: 'Per-item statistical health: variance across respondents, missingness rate, ceiling/floor effects for Likert items. For open-ended items: response rate, average length, placeholder detection, duplicate-text rate.',
      recs: [
        'Items with ceiling or floor effects (≥ 80% picked one end): rephrase or split the underlying construct.',
        'Low-variance items often signal leading wording. Reword to invite a fuller range of opinion.',
        'High-missingness items may be confusing or sensitive — reword or move them later in the survey.',
      ],
    },
    bias_clarity: {
      desc: 'Two halves: wording health (reading grade, double-barreled items, leading or loaded language) and fairness (DIF proxy — does an item behave differently across demographic groups?). The fairness half requires tagged demographic columns.',
      recs: [
        'Double-barreled items combine two ideas in one prompt — split each into two single-idea questions.',
        'Reading-grade-high items lose respondents below that level; aim for grade 7–9 for general audiences.',
        'DIF flags mean an item is interpreted differently by different demographic groups — revise wording or consider dropping.',
      ],
    },
    scale_structure: {
      desc: 'Structural health of each scale: item count, reverse-coded balance, response-format uniformity within the scale, and missingness pattern. Healthy scales have ≥ 4 items, confirmed reverse-coding, and a single consistent anchor count.',
      recs: [
        'Scales with fewer than 4 items can\'t be reliably estimated. Add 1–2 items per light scale.',
        'Tick the survey-level "I\'ve reviewed every item for reverse-coding" confirmation to activate §4E sub-2.',
        'Mixed response formats within one scale (e.g., a 5-point block and a 7-point block) need to be split into separate scales.',
      ],
    },
    response_scale_review: {
      desc: 'Two halves: Likert design (anchor count, midpoint presence, anchor symmetry, single-format-per-scale) and respondent behavior (completion rate, missingness rate, straight-lining rate). Captures both the design choices and how respondents actually used them.',
      recs: [
        'High straight-lining rate (respondents picking the same answer for every item) suggests survey fatigue — consider trimming.',
        'Inconsistent anchor counts across scales make comparison harder — pick one anchor count and apply it survey-wide.',
        'Low completion may signal the survey is too long. Aim for under 10 minutes for a general-audience instrument.',
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

  function populateDetailView(rawKey) {
    if (!lastRenderedData) return;
    // The sidebar's data-dimension attributes are a mix of v1 and v2
    // keys (Q7 directive — sidebar markup unchanged). Normalize at this
    // boundary so every downstream branch sees canonical v2 keys.
    const key = SIDEBAR_KEY_TO_V2[rawKey] || rawKey;

    // Reliability has a fully-native RSSI view: the interactive Cronbach
    // analyzer on the overview. Bounce the user there.
    if (key === 'reliability') {
      const ovBtn = document.querySelector('.rssi-app .nav-item[data-view="overview"]');
      if (ovBtn) ovBtn.click();
      setTimeout(function () {
        const mount = document.getElementById('rssiOverviewAnalyzerMount');
        if (mount) mount.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 80);
      return;
    }

    // ─── Validity — Option 1 (iframe scrape) ───
    if (key === 'validity' && window.RSSI_ANALYSES) {
      setupDetailHeader('Validity', 'Option 1 · iframe scrape',
        'Pulls the narrative live from /validity.php?embed=1 via a hidden iframe.');
      window.RSSI_ANALYSES.renderValidityViaIframeScrape(document.getElementById('rssiDetailStats'));
      document.getElementById('rssiDetailRecs').innerHTML = '';
      return;
    }

    // ─── Item / Prompt Quality — Option 2 (ported narrative) ───
    if (key === 'item_prompt_quality' && window.RSSI_ANALYSES) {
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

    // ─── Remaining instrument-quality domains — Option 1 (iframe scrape) ───
    const IFRAME_DISPATCH = {
      bias_clarity:          { fn: 'renderBiasClarityIframe',    label: 'Bias & Clarity Review' },
      scale_structure:       { fn: 'renderScaleStructureIframe', label: 'Scale Structure' },
      factor_readiness:      { fn: 'renderFactorReadinessIframe', label: 'Factor Readiness' },
      response_scale_review: { fn: 'renderResponseScaleIframe',  label: 'Response Scale Review' },
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
      if (block) {
        // Defensive: a stale v1-shaped saved block (legacy `components`
        // map, no `rssi` or `domain_details`) can't be normalised into
        // the v2 render shape. Detect by absence of the v2 lens block
        // and fall through to SAMPLE rather than throw. Per the Q9 (a)
        // decision: stale blocks fall back cleanly to a working render
        // (sample), not a white screen.
        const v2ish = block.payload && (block.payload.rssi || block.payload.domain_details || block.payload.domains);
        if (v2ish) lastRenderedData = normaliseBlock(block);
        else       lastRenderedData = normaliseBlock({ payload: SAMPLE, addedAt: Date.now() });
      } else {
        lastRenderedData = normaliseBlock({ payload: SAMPLE, addedAt: Date.now() });
      }
      if (!lastRenderedData.rssi || lastRenderedData.rssi.respondent_centered == null || (block && !block.payload.rssi)) {
        // Loud-but-non-fatal: if we fell back to SAMPLE because the
        // saved block was stale-v1, mark the surface as sample-mode so
        // the meta strip says "Sample data" instead of pretending the
        // pre-migration numbers are live.
        if (block && !(block.payload && (block.payload.rssi || block.payload.domain_details || block.payload.domains))) {
          lastRenderedData.isSample = true;
        }
      }
    }
    render(lastRenderedData);

    /* New view-switching sidebar (data-view + data-dimension attributes) */
    wireSidebar();

    /* Lens-help "?" popover toggle (Spec §3.5 mandate). */
    wireLensHelp();
    /* Per-chip info icons — single shared popover, custom (not native title). */
    wireLensInfoIcons();
    /* Floating score display: intersection observer + collapse toggle. */
    wireScoreFloat();
    /* Print-report handlers (beforeprint + afterprint). */
    wirePrintReport();
    /* Methods-paragraph copy-to-clipboard button (Spec §8.1). */
    wireMethodsCopy();
  }

  function wireMethodsCopy() {
    const btn = document.getElementById('rssiMethodsCopyBtn');
    const body = document.getElementById('rssiMethodsBody');
    if (!btn || !body || btn._rssiWired) return;
    btn._rssiWired = true;
    btn.addEventListener('click', function () {
      const text = (body.textContent || '').trim();
      if (!text) return;
      const settle = function (ok) {
        const orig = btn._rssiOrigText || (btn._rssiOrigText = btn.innerHTML);
        btn.innerHTML = ok ? 'Copied ✓' : 'Press Ctrl+C';
        btn.classList.add('is-copied');
        setTimeout(function () {
          btn.innerHTML = orig;
          btn.classList.remove('is-copied');
        }, 1500);
      };
      // Prefer the async clipboard API; fall back to execCommand which
      // works on older browsers + when clipboard permissions deny.
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { settle(true); }, function () { settle(false); });
        return;
      }
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        settle(!!ok);
      } catch (e) { settle(false); }
    });
  }

  /* ────────────────────────────────────────────────────────────
   *  Print-report wiring (Phase 2 of the handout-PDF feature).
   *
   *  The existing "Print / Save PDF" button is a window.print() call.
   *  This handler reacts to the browser's beforeprint event to:
   *   1. Populate the print-only refined-scale block from the analyzer's
   *      getRefinedScale() accessor, but ONLY when items have been
   *      excluded. Untouched scales → block stays hidden, no section
   *      appears in the printed PDF.
   *   2. Swap document.title to "RSSI Report — <surveyName>" so the
   *      browser's "Save as PDF" filename reads as a handout artifact.
   *
   *  afterprint restores the title and re-hides the block so the
   *  on-screen DOM returns to its idle state. The window.print() call
   *  itself stays where it is on the button — no new entry points.
   *
   *  This is wired exactly once on init. The handlers read the live
   *  dataset/analyzer/result globals at print time, so they always
   *  reflect the user's current state (refined or not).
   * ──────────────────────────────────────────────────────────── */
  let _prePrintTitle = null;

  function wirePrintReport() {
    if (window._rssiPrintWired) return;
    window._rssiPrintWired = true;
    window.addEventListener('beforeprint', _onBeforePrint);
    window.addEventListener('afterprint',  _onAfterPrint);
  }

  function _surveyNameForFilename() {
    const title = document.getElementById('rssiDashTitle');
    if (title && title.textContent && title.textContent.trim()) {
      return title.textContent.trim();
    }
    return 'Survey';
  }

  function _onBeforePrint() {
    // Title swap → drives the browser's "Save as PDF" filename suggestion.
    if (_prePrintTitle === null) _prePrintTitle = document.title;
    document.title = 'RSSI Report — ' + _surveyNameForFilename();

    // Refined-scale block: populate only when the analyzer reports
    // excluded items (the user actually used the table to drop items).
    const block = document.getElementById('rssiRefinedScalePrint');
    if (!block) return;
    const rel = (window.RSSI_RELIABILITY && typeof window.RSSI_RELIABILITY.getRefinedScale === 'function')
      ? window.RSSI_RELIABILITY.getRefinedScale()
      : null;
    if (!rel || !rel.items_excluded || rel.items_excluded.length === 0) {
      block.hidden = true;
      block.setAttribute('aria-hidden', 'true');
      return;
    }
    _populateRefinedScale(rel);
    block.hidden = false;
    block.setAttribute('aria-hidden', 'false');
  }

  function _onAfterPrint() {
    if (_prePrintTitle !== null) {
      document.title = _prePrintTitle;
      _prePrintTitle = null;
    }
    const block = document.getElementById('rssiRefinedScalePrint');
    if (block) {
      block.hidden = true;
      block.setAttribute('aria-hidden', 'true');
    }
  }

  function _populateRefinedScale(rel) {
    function setText(id, txt) {
      const el = document.getElementById(id);
      if (el) el.textContent = txt;
    }
    setText('rsp_item_count',     String(rel.item_count));
    setText('rsp_original_count', String(rel.original_item_count));
    setText('rsp_alpha',          rel.cronbach_alpha == null ? '—' : rel.cronbach_alpha.toFixed(2));
    setText('rsp_alpha_band',     rel.alpha_band || '');
    setText('rsp_orig_alpha',     rel.original_alpha == null ? '—' : rel.original_alpha.toFixed(2));
    setText('rsp_n',              String(rel.complete_responses == null ? '—' : rel.complete_responses));
    const dEl = document.getElementById('rsp_delta');
    if (dEl) {
      if (rel.delta == null) {
        dEl.textContent = '—';
      } else {
        const sign = rel.delta >= 0 ? '+' : '';
        dEl.textContent = sign + rel.delta.toFixed(3);
      }
    }
    const excList = document.getElementById('rsp_excluded');
    if (excList) {
      excList.innerHTML = rel.items_excluded.map(function (r) {
        return '<li>' + esc(r.label || r.name) + '</li>';
      }).join('');
    }
    const incList = document.getElementById('rsp_included');
    if (incList) {
      incList.innerHTML = rel.items_included.map(function (r) {
        return '<li>' + esc(r.label || r.name) + '</li>';
      }).join('');
    }
  }

  /* Per-lens info popover. Reuses the same custom-popover pattern as the
     shared "?" affordance: click toggles, ESC + outside-click dismiss.
     A single popover element gets repositioned + populated per click —
     no native title (1s delay, no touch, no styling). Copy is locked
     to the spec §3.2 weight-vector descriptions in short form. */
  const LENS_INFO_COPY = {
    psychometric_core:
      'Weights the heavyweight statistics most: reliability, validity, factor structure. Captures how statistically sound the instrument is.',
    respondent_centered:
      'Weights item quality, bias and clarity, and response design most. Captures how well the survey works for the people taking it.',
    validity_forward:
      'Treats validity as the most important property, weighting validity, construct alignment, and bias. Captures whether there is evidence the survey measures what it claims.',
  };
  function wireLensInfoIcons() {
    const pop = document.getElementById('rssiLensInfoPopover');
    const triplet = document.getElementById('rssiLensTriplet');
    if (!pop || !triplet || pop._rssiWired) return;
    pop._rssiWired = true;
    let activeBtn = null;
    function close() {
      pop.hidden = true;
      if (activeBtn) activeBtn.setAttribute('aria-expanded', 'false');
      activeBtn = null;
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('click', onOutside, true);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    function onOutside(e) {
      if (e.target === activeBtn || pop.contains(e.target)) return;
      if (e.target.classList && e.target.classList.contains('lens-info-icon')) return;
      close();
    }
    triplet.querySelectorAll('.lens-info-icon').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const lens = btn.getAttribute('data-lens-info');
        if (activeBtn === btn) { close(); return; }
        // Close any prior open popover first.
        if (activeBtn) activeBtn.setAttribute('aria-expanded', 'false');
        activeBtn = btn;
        pop.textContent = LENS_INFO_COPY[lens] || '';
        pop.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        // Position relative to the triplet container, just below the
        // clicked icon. Bounds-check against the viewport so the popover
        // never spills past the right edge — shift left if needed. CSS
        // handles the visual styling; we set only position offsets.
        const ir = btn.getBoundingClientRect();
        const tr = triplet.getBoundingClientRect();
        // Measure popover width by briefly painting it offscreen.
        pop.style.visibility = 'hidden';
        pop.style.left = '0px';
        pop.style.top  = '0px';
        const popW = pop.offsetWidth || 280;
        pop.style.visibility = '';
        let desiredLeft = (ir.left - tr.left) - 8;
        // Don't let the popover's right edge spill past the viewport's
        // right edge (with an 8px gutter). Convert to viewport coords
        // by adding tr.left back, comparing to window.innerWidth, then
        // converting the clamped value back to container-relative.
        const popViewportLeft = desiredLeft + tr.left;
        const overflow = (popViewportLeft + popW) - (window.innerWidth - 8);
        if (overflow > 0) desiredLeft -= overflow;
        if (desiredLeft < -tr.left) desiredLeft = -tr.left + 8;
        pop.style.left = desiredLeft + 'px';
        pop.style.top  = (ir.bottom - tr.top + 8) + 'px';
        document.addEventListener('keydown', onKey);
        document.addEventListener('click', onOutside, true);
      });
    });
  }

  /* Floating score display. Engages when the inline hero scrolls out of
     view (intersection observer with top-< 0 check so we only show when
     the user has scrolled BELOW the hero, not above it). The float
     itself is `position: fixed` in the CSS; we only flip `hidden`. */
  function wireScoreFloat() {
    const float = document.getElementById('rssiScoreFloat');
    const hero  = document.querySelector('.rssi-hero-score');
    const collapseBtn = document.getElementById('rssiFloatCollapse');
    const tier2 = document.getElementById('rssiFloatTier2');
    if (!float || !hero) return;

    if ('IntersectionObserver' in window && !float._rssiObs) {
      float._rssiObs = new IntersectionObserver(function (entries) {
        const e = entries[0];
        if (!e) return;
        // Show the float only when the hero has scrolled UPWARD out of
        // view (top above the viewport). On initial page load the hero
        // is visible (isIntersecting = true) so the float stays hidden.
        const heroAbove = e.boundingClientRect.top < 0 && !e.isIntersecting;
        const shouldShow = heroAbove
          && document.getElementById('rssiAppRoot') &&
             document.getElementById('rssiAppRoot').getAttribute('data-stage') === 'dashboard';
        float.hidden = !shouldShow;
        float.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
      }, { threshold: 0 });
      float._rssiObs.observe(hero);
    }

    if (collapseBtn && !collapseBtn._rssiWired) {
      collapseBtn._rssiWired = true;
      collapseBtn.addEventListener('click', function () {
        const expanded = collapseBtn.getAttribute('aria-expanded') === 'true';
        const next = !expanded;
        collapseBtn.setAttribute('aria-expanded', String(next));
        if (tier2) tier2.hidden = !next;
        collapseBtn.textContent = next ? '▴' : '▾';
        collapseBtn.setAttribute('title', next ? 'Collapse domain cards' : 'Show domain cards');
      });
    }
  }

  function wireLensHelp() {
    const btn = document.getElementById('rssiLensHelp');
    const pop = document.getElementById('rssiLensHelpPopover');
    if (!btn || !pop || btn._rssiWired) return;
    btn._rssiWired = true;
    function close() {
      pop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('click', onOutside, true);
    }
    function open() {
      pop.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      document.addEventListener('keydown', onKey);
      document.addEventListener('click', onOutside, true);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    function onOutside(e) {
      if (e.target === btn || pop.contains(e.target)) return;
      close();
    }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (pop.hidden) open(); else close();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
