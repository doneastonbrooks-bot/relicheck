/* ════════════════════════════════════════════════════════════════════════
   SDSI — Validity Readiness — the 50-point domain aggregator
   ────────────────────────────────────────────────────────────────────────
   Assembles the SEVEN validity lenses into one Validity Readiness domain
   score (out of 50 SDSI points) and a launch-readiness verdict:

       Construct Definition          8   (factory · validity-specs.js)
       Purpose Alignment             7   (factory)
       Dimension / Domain Coverage   8   (factory)
       Item-to-Construct Alignment   7   (factory)
       Response-Option Validity      4   (factory)
       Dignity / Framing             8   (apps/sdsi/dignity-engine.js)
       Access                        8   (apps/sdsi/access-engine.js)
       ───────────────────────────────
       Validity Readiness           50

   THIS MODULE DOES NOT RE-SCORE. Each lens owns its own 0–100 math; the
   aggregator only SUMS the SDSI contribution points each lens already
   produced and aggregates the orthogonal launch gate. A blocker on any lens
   never changes the number — it only flips the domain verdict to
   "Blocked for review".

   Two data paths feed the SAME pipeline (seed → engine.assess → aggregate):
     • sample mode — seeds each lens from its built-in sample (no DB/auth).
     • live mode   — fetches the saved, settled inputs for a survey and runs
       the identical engines client-side, so live and sample share one render
       and one math path.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  // Presentation order + SDSI weights. `kind` selects which engine scores it.
  var LENS_ORDER = [
    { key: 'construct_definition',     name: 'Construct Definition',         weight: 8, kind: 'factory' },
    { key: 'purpose_alignment',        name: 'Purpose Alignment',            weight: 7, kind: 'factory' },
    { key: 'dimension_coverage',       name: 'Dimension / Domain Coverage',  weight: 8, kind: 'factory' },
    { key: 'item_construct_alignment', name: 'Item-to-Construct Alignment',  weight: 7, kind: 'factory' },
    { key: 'response_option_validity', name: 'Response-Option Validity',     weight: 4, kind: 'factory' },
    { key: 'dignity_framing',          name: 'Dignity / Framing',            weight: 8, kind: 'dignity' },
    { key: 'access',                   name: 'Access',                       weight: 8, kind: 'access'  }
  ];

  // Default lens-page links (live mount pages). A host page may override per
  // key via window.VR_LENS_HREFS (the harness points these at its own pages).
  var DEFAULT_HREFS = {
    construct_definition:     '/construct-definition.php',
    purpose_alignment:        '/purpose-alignment.php',
    dimension_coverage:       '/dimension-coverage.php',
    item_construct_alignment: '/item-construct-alignment.php',
    response_option_validity: '/response-option-validity.php',
    dignity_framing:          '/dignity-framing.php',
    access:                   '/access.php'
  };

  // Domain-level bands on the 0–100 percentage of the 50-point domain.
  function aggregateBand(pct) {
    if (pct >= 90) return { key: 'strong',      label: 'Strong validity readiness' };
    if (pct >= 80) return { key: 'good',        label: 'Good — minor validity revisions recommended' };
    if (pct >= 70) return { key: 'moderate',    label: 'Moderate validity risk' };
    if (pct >= 60) return { key: 'significant', label: 'Significant validity risk' };
    return                { key: 'high',         label: 'High validity risk — revise before launch' };
  }

  /* ── PURE: assemble per-lens engine results into the domain summary. ──
     entries = [{ key, name, weight, result }] where `result` is any lens
     engine's assess() output (factory / dignity / access — same shape).
     Engine-agnostic; fully deterministic; never re-scores a lens. */
  function aggregate(entries) {
    var lenses = (entries || []).map(function (e) {
      var r = e.result || {};
      var ledger = r.ledger || [];
      var blockers = r.blockers || [];
      var warnings = r.warnings || [];   // dignity/access expose none → []
      return {
        key:                e.key,
        name:               e.name,
        weight:             (r.sdsiWeight != null ? r.sdsiWeight : e.weight),
        score:              (r.score != null ? r.score : 0),
        points:             (r.sdsiPoints != null ? r.sdsiPoints : 0),
        band:               r.band || { key: '', label: '' },
        launchReady:        r.launchReady !== false,
        blockerCount:       blockers.length,
        unreviewedBlockers: blockers.filter(function (b) { return !b.reviewed; }).length,
        warningCount:       warnings.length,
        acceptedFlags:      ledger.filter(function (l) { return l.counted; }).length,
        dismissedFlags:     ledger.filter(function (l) { return l.decision === 'dismissed'; }).length
      };
    });

    var totalPoints = Math.round(lenses.reduce(function (a, l) { return a + (l.points || 0); }, 0) * 10) / 10;
    var maxPoints   = lenses.reduce(function (a, l) { return a + (l.weight || 0); }, 0);
    var pct         = maxPoints > 0 ? (totalPoints / maxPoints) * 100 : 0;
    var blocked     = lenses.some(function (l) { return !l.launchReady; });
    var band        = aggregateBand(pct);

    var evidence = lenses.reduce(function (acc, l) {
      acc.acceptedFlags  += l.acceptedFlags;
      acc.dismissedFlags += l.dismissedFlags;
      acc.blockers       += l.blockerCount;
      acc.warnings       += l.warningCount;
      return acc;
    }, { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 });

    return {
      lenses:      lenses,
      totalPoints: totalPoints,
      maxPoints:   maxPoints,
      pct:         Math.round(pct * 10) / 10,
      band:        band,
      blocked:     blocked,
      verdict:     blocked ? 'Blocked for review' : band.label,
      evidence:    evidence
    };
  }

  /* ════════════════════════════════════════════════════════════════════
     SAMPLE DATA — dignity/access harness samples (data only). The five
     factory-lens samples live in SDSI_VALIDITY_SPECS[key].sample; these two
     mirror dfDefaultProposal()/acDefaultProposal() in instrument-quality.js
     so the aggregator can preview all seven lenses with no server.
     ════════════════════════════════════════════════════════════════════ */
  var DIGNITY_SAMPLE = {
    population: { minors: true, peopleFacing: true, communities: ['multilingual learners', 'special education'] },
    flags: [
      { check: 'deficit_framing', item_ref: 'q3',
        quote: 'How far behind are the at-risk students in your class?', severity: 'major',
        rationale: 'Frames students by deficiency before the respondent answers.',
        suggested_revision: 'What academic supports would help your students make progress?' },
      { check: 'identity_erasure', item_ref: 'q8', topic: 'race',
        quote: 'Race: White / Black / Other', severity: 'major',
        rationale: 'Collapses distinct identities into "Other" and forces misclassification.',
        suggested_revision: 'Offer multi-select with a write-in option matched to your community.' },
      { check: 'extractive_disclosure', item_ref: 'q14', topic: 'legal_status', section: 'demographics',
        quote: 'Is anyone in your household undocumented?', severity: 'critical',
        rationale: 'Demands legal status with no stated purpose or decline path.',
        suggested_revision: 'Remove the item, or add a clear purpose and a "Prefer not to answer" option.' },
      { check: 'judging_respondent', item_ref: 'q20',
        quote: 'How often do you neglect to read with your child?', severity: 'moderate',
        rationale: 'Moralizing wording shames the respondent before they answer.',
        suggested_revision: 'In a typical week, how often do you read with your child?' }
    ],
    mitigations: [
      { type: 'clear_purpose',  item_ref: 'qIntro', section: 'intro' },
      { type: 'decline_option', item_ref: 'qIntro', section: 'intro' }
    ]
  };

  var ACCESS_SAMPLE = {
    population: { minors: true, peopleFacing: true, communities: ['multilingual learners', 'special education'] },
    flags: [
      { check: 'reading_load', item_ref: 'q3',
        quote: 'To what extent do you perceive the pedagogical interventions to be efficacious?', severity: 'major',
        rationale: 'College-level vocabulary sits far above many family respondents.',
        suggested_revision: 'Do you think the extra help your child gets is working?' },
      { check: 'language_barrier', item_ref: 'q7', section: 'background',
        quote: 'Please describe your family’s experience with the enrollment process.', severity: 'major',
        rationale: 'Open English prose excludes non-English readers in a multilingual community.',
        suggested_revision: 'Offer the item in the family’s home language plus a plain-language version.' },
      { check: 'format_inaccessibility', item_ref: 'q12', section: 'background',
        quote: 'Drag the slider to indicate how strongly you agree.', severity: 'critical',
        rationale: 'A drag-only slider assumes a pointer device and fine motor control.',
        suggested_revision: 'Offer labeled radio buttons as the primary control, slider optional.' },
      { check: 'response_burden', item_ref: 'q18',
        quote: 'List every school event you attended this year and what you learned at each.', severity: 'moderate',
        rationale: 'Long open recall over a full year drives fatigue and dropout.',
        suggested_revision: 'About how many school events did you attend this year? (none / 1–2 / 3–5 / 6+)' }
    ],
    mitigations: [
      { type: 'plain_language_alt', item_ref: 'qIntro', section: 'intro' },
      { type: 'accommodation_path', item_ref: 'qIntro', section: 'intro' }
    ]
  };

  // Settle helpers: harness samples are pre-accepted (decision defaults), the
  // same convention instrument-quality.js uses when seeding a sample.
  function seedFlags(arr) {
    return (arr || []).map(function (f, i) {
      return Object.assign({}, f, {
        flag_id: f.flag_id || ('f' + (i + 1)),
        decision: f.decision || 'accepted',
        blocker_reviewed: !!f.blocker_reviewed
      });
    });
  }
  function seedMits(arr) {
    return (arr || []).map(function (m, i) {
      return Object.assign({}, m, {
        mitigation_id: m.mitigation_id || ('m' + (i + 1)),
        decision: m.decision || 'accepted'
      });
    });
  }

  // Engine resolvers — browser globals when present, else Node require (so the
  // pure aggregate + sample pipeline are unit-testable). One code path either way.
  var inNode = (typeof module !== 'undefined' && module.exports);
  function factorySpecs() {
    if (root.SDSI_VALIDITY_SPECS) return root.SDSI_VALIDITY_SPECS;
    return inNode ? require('./validity-specs.js').SPECS : {};
  }
  function factoryLens(key) {
    if (root.SDSI_VALIDITY_LENSES) return root.SDSI_VALIDITY_LENSES[key];
    return inNode ? require('./validity-specs.js').LENSES[key] : null;
  }
  function dignityEngine() { return root.DignityEngine || (inNode ? require('./dignity-engine.js') : null); }
  function accessEngine()  { return root.AccessEngine  || (inNode ? require('./access-engine.js')  : null); }

  // Run one lens through its engine from seeded inputs. `inputs` is whatever
  // that engine's assess() reads (factory: {flags,context}; dignity/access:
  // {flags,mitigations,population}). Returns { key, name, weight, result }.
  function assessLens(def, inputs) {
    var result;
    if (def.kind === 'factory') {
      var lens = factoryLens(def.key);
      if (!lens) throw new Error('Validity Readiness: factory lens "' + def.key + '" not loaded.');
      result = lens.assess({ flags: seedFlags(inputs.flags), context: inputs.context || {} });
    } else {
      var eng = def.kind === 'dignity' ? dignityEngine() : accessEngine();
      if (!eng) throw new Error('Validity Readiness: ' + def.kind + ' engine not loaded.');
      result = eng.assess({ flags: seedFlags(inputs.flags), mitigations: seedMits(inputs.mitigations), population: inputs.population || {} });
    }
    return { key: def.key, name: def.name, weight: def.weight, result: result };
  }

  // Build the seven lens entries from sample data (no server).
  function sampleEntries() {
    var specs = factorySpecs();
    return LENS_ORDER.map(function (def) {
      if (def.kind === 'factory') {
        var s = (specs[def.key] && specs[def.key].sample) || { context: {}, flags: [] };
        return assessLens(def, { flags: s.flags, context: s.context });
      }
      return assessLens(def, def.kind === 'dignity' ? DIGNITY_SAMPLE : ACCESS_SAMPLE);
    });
  }

  // Build the seven lens entries from a saved-review payload (live mode).
  // payload.lenses[key] = factory: {flags,context} · dignity/access:
  // {flags,mitigations,population}. Missing/unreviewed lenses contribute a
  // clean (no-flag) result so the domain total still reflects 50 points.
  function liveEntries(payload) {
    var byKey = (payload && payload.lenses) || {};
    return LENS_ORDER.map(function (def) {
      var saved = byKey[def.key] || {};
      return assessLens(def, def.kind === 'factory'
        ? { flags: saved.flags || [], context: saved.context || {} }
        : { flags: saved.flags || [], mitigations: saved.mitigations || [], population: saved.population || {} });
    });
  }

  /* ════════════════════════════════════════════════════════════════════
     RENDER — the dashboard. Reuses the Instrument Quality visual language
     (iq-df-gate states, iq-flag tones) plus an iq-vr-* layer for the grid.
     ════════════════════════════════════════════════════════════════════ */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function render(container, data, hrefs) {
    hrefs = hrefs || DEFAULT_HREFS;
    var d = data;

    var verdictState = d.blocked ? 'blocked' : (d.band.key === 'high' || d.band.key === 'significant' ? 'reviewed' : 'clear');

    var header =
      '<header class="iq-vr-head" data-state="' + verdictState + '">' +
        '<div class="iq-vr-eyebrow"><span class="pip" aria-hidden="true"></span><span>SDSI · Validity Readiness</span></div>' +
        '<h2 class="iq-vr-score">Validity Readiness: ' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + '</h2>' +
        '<p class="iq-vr-band">' + esc(d.verdict) + (d.blocked ? '' : ' · ' + d.pct.toFixed(0) + '% of the domain') + '</p>' +
        (d.blocked
          ? '<p class="iq-vr-note">One or more lenses have an unresolved launch blocker. The score is unchanged — the verdict is held at <strong>Blocked for review</strong> until each blocker is reviewed.</p>'
          : '<p class="iq-vr-note">No unresolved launch blockers across the seven lenses.</p>') +
      '</header>';

    var cards = d.lenses.map(function (l) {
      var href = hrefs[l.key] || ('#' + l.key);
      var cardState = !l.launchReady ? 'blocked' : (l.band.key === 'strong' || l.band.key === 'good' ? 'clear' : 'reviewed');
      var blockerChip = l.blockerCount > 0
        ? '<span class="iq-flag" data-tone="' + (l.unreviewedBlockers > 0 ? 'alert' : 'ok') + '">' +
            (l.unreviewedBlockers > 0 ? l.unreviewedBlockers + ' blocker' + (l.unreviewedBlockers > 1 ? 's' : '') : 'blockers reviewed') + '</span>'
        : '<span class="iq-flag" data-tone="ok">no blocker</span>';
      var warnChip = l.warningCount > 0
        ? '<span class="iq-flag" data-tone="warn">' + l.warningCount + ' caution' + (l.warningCount > 1 ? 's' : '') + '</span>'
        : '';
      return '<div class="iq-vr-card" data-state="' + cardState + '">' +
          '<div class="iq-vr-card-head">' +
            '<h3 class="iq-vr-card-name">' + esc(l.name) + '</h3>' +
            '<span class="iq-vr-card-pts">' + l.points.toFixed(1) + '<span class="iq-vr-card-max">/' + l.weight + '</span></span>' +
          '</div>' +
          '<p class="iq-vr-card-score">' + l.score + '/100 · ' + esc(l.band.label) + '</p>' +
          '<div class="iq-vr-card-chips">' + blockerChip + warnChip +
            '<span class="iq-vr-card-ev">' + l.acceptedFlags + ' kept · ' + l.dismissedFlags + ' dismissed</span>' +
          '</div>' +
          '<a class="iq-vr-card-open" href="' + esc(href) + '">Open review →</a>' +
        '</div>';
    }).join('');

    var gate =
      '<div class="iq-df-gate" data-state="' + (d.blocked ? 'blocked' : 'clear') + '">' +
        '<strong>' + (d.blocked ? 'Validity Readiness: Blocked for review' : 'Validity Readiness: launch gate clear') + '</strong>' +
        '<span class="iq-df-gate-note">The launch gate is orthogonal: it never changes the ' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + ' score.</span>' +
        (d.blocked
          ? '<ul class="iq-df-blockers">' + d.lenses.filter(function (l) { return !l.launchReady; }).map(function (l) {
              return '<li><span class="iq-flag" data-tone="alert">Blocked</span> ' + esc(l.name) +
                ' — ' + l.unreviewedBlockers + ' unreviewed blocker' + (l.unreviewedBlockers > 1 ? 's' : '') +
                ' <a href="' + esc(hrefs[l.key] || ('#' + l.key)) + '">review →</a></li>';
            }).join('') + '</ul>'
          : '') +
      '</div>';

    var ev = d.evidence;
    var summary =
      '<div class="iq-vr-evidence">' +
        '<h4 class="iq-block-h">Evidence summary</h4>' +
        '<div class="iq-vr-stats">' +
          '<div class="iq-vr-stat"><span class="iq-vr-stat-n">' + ev.acceptedFlags + '</span><span class="iq-vr-stat-l">accepted flags</span></div>' +
          '<div class="iq-vr-stat"><span class="iq-vr-stat-n">' + ev.dismissedFlags + '</span><span class="iq-vr-stat-l">dismissed flags</span></div>' +
          '<div class="iq-vr-stat" data-tone="' + (ev.blockers ? 'alert' : 'ok') + '"><span class="iq-vr-stat-n">' + ev.blockers + '</span><span class="iq-vr-stat-l">launch blockers</span></div>' +
          '<div class="iq-vr-stat" data-tone="' + (ev.warnings ? 'warn' : 'ok') + '"><span class="iq-vr-stat-n">' + ev.warnings + '</span><span class="iq-vr-stat-l">advisory cautions</span></div>' +
        '</div>' +
      '</div>';

    var math =
      '<div class="iq-vr-math">' +
        '<h4 class="iq-block-h">Score math</h4>' +
        '<p class="iq-df-math">Validity Readiness = sum of the seven lens contributions = ' +
          d.lenses.map(function (l) { return l.points.toFixed(1); }).join(' + ') +
          ' = <strong>' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + '</strong></p>' +
        '<p class="iq-rel-plain">Each lens owns its own 0–100 math; the domain score only sums the SDSI points each lens already produced. Launch blockers are orthogonal and never change this total.</p>' +
      '</div>';

    // Scope-and-lifecycle notes. Plain text only — they do not touch the
    // score, the blocker logic, or the seven lenses.
    var scope =
      '<div class="iq-vr-notes">' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">Validity Readiness is not empirical validity</h4>' +
          '<p class="iq-rel-plain">Validity Readiness is a pre-administration review of survey design. It evaluates whether the instrument is structured to support meaningful interpretation before responses are collected. It does not prove that the survey is statistically valid. Empirical validity evidence comes later through response data, factor structure, criterion relationships, and related analyses.</p>' +
        '</div>' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">SDSI vs RSSI — where this sits in the lifecycle</h4>' +
          '<p class="iq-rel-plain">SDSI evaluates survey design strength before launch.<br>RSSI evaluates survey performance after data collection.</p>' +
          '<p class="iq-rel-plain">SDSI asks: Is the survey ready to collect interpretable data?<br>RSSI asks: Did the collected data perform reliably and support interpretation?</p>' +
        '</div>' +
        '<div class="iq-vr-note-card" data-tone="admin">' +
          '<h4 class="iq-block-h">Live verification required</h4>' +
          '<p class="iq-rel-plain">Live verification required: run the SDSI schema files on the production server, connect a real survey, run each lens, save each review, and confirm the Validity Readiness aggregator reads saved results correctly.</p>' +
        '</div>' +
      '</div>';

    container.innerHTML =
      header +
      gate +
      '<h4 class="iq-block-h">Components</h4>' +
      '<div class="iq-vr-grid">' + cards + '</div>' +
      summary +
      math +
      scope;
  }

  /* ── Browser bootstrap ── */
  function init() {
    if (typeof document === 'undefined') return;
    var container = document.getElementById('validityReadiness');
    if (!container) return;
    var hrefs = root.VR_LENS_HREFS || DEFAULT_HREFS;
    var mode = root.VR_MODE || 'sample';

    function paint(entries) { render(container, aggregate(entries), hrefs); }

    if (mode === 'live') {
      var surveyId = Number(root.RELICHECK_PROJECT_ID) || 0;
      container.innerHTML = '<p class="iq-rel-plain">Loading saved validity reviews…</p>';
      fetch('/api/sdsi/validity-readiness.php?survey_id=' + surveyId, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error((j && j.error) || 'load_failed');
          paint(liveEntries(j));
        })
        .catch(function () {
          container.innerHTML = '<p class="iq-rel-plain">Could not load saved reviews for this survey yet. Complete and save the lens reviews, then reload.</p>';
        });
    } else {
      paint(sampleEntries());
    }
  }

  root.ValidityReadiness = {
    LENS_ORDER: LENS_ORDER,
    DEFAULT_HREFS: DEFAULT_HREFS,
    aggregateBand: aggregateBand,
    aggregate: aggregate,
    sampleEntries: sampleEntries,
    liveEntries: liveEntries,
    render: render,
    init: init
  };

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).ValidityReadiness;
}
