/* ════════════════════════════════════════════════════════════════════════
   SDSI — Reliability Readiness — the 35-point domain aggregator
   ────────────────────────────────────────────────────────────────────────
   Assembles the FIVE reliability lenses into one Reliability Readiness domain
   score (out of 35 SDSI points) and a launch-readiness verdict:

       Scale Structure Readiness               8   (factory · reliability-specs.js)
       Item Clarity / Wording Consistency      8   (factory)
       Response Scale Consistency              7   (factory)
       Redundancy Balance                      6   (factory)
       Administration Consistency (Reliability) 6   (factory)
       ───────────────────────────────────────────
       Reliability Readiness                  35

   All five lenses are FACTORY lenses sharing validity-lens-engine.js — the
   construct-agnostic locked spine that scores validity and reliability lenses
   identically. Each reads { flags, context }.

   THIS MODULE DOES NOT RE-SCORE. Each lens owns its own 0–100 math; the
   aggregator only SUMS the SDSI contribution points each lens already produced
   and aggregates the orthogonal launch gate. A blocker on any lens never
   changes the number — it only flips the domain verdict to "Blocked for
   review". Advisory warnings never change the number OR the verdict.

   ALPHA FENCE: this is a PRE-DATA design review. No Cronbach's alpha, omega,
   item-total / inter-item correlations, factor analysis, or any response-data
   statistic appears here. Those belong to RSSI after data collection.

   Two data paths feed the SAME pipeline (seed → engine.assess → aggregate):
     • sample mode — seeds each lens from its built-in sample (no DB/auth).
     • live mode   — fetches the saved, settled inputs for a survey and runs
       the identical engines client-side, so live and sample share one render
       and one math path.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  // Presentation order + SDSI weights. All five are factory lenses.
  var LENS_ORDER = [
    { key: 'scale_structure_readiness',  name: 'Scale Structure Readiness',                weight: 8, kind: 'factory' },
    { key: 'item_clarity',               name: 'Item Clarity / Wording Consistency',       weight: 8, kind: 'factory' },
    { key: 'response_scale_consistency', name: 'Response Scale Consistency',               weight: 7, kind: 'factory' },
    { key: 'redundancy_balance',         name: 'Redundancy Balance',                       weight: 6, kind: 'factory' },
    { key: 'administration_consistency', name: 'Administration Consistency for Reliability', weight: 6, kind: 'factory' }
  ];

  // Default lens-page links (live mount pages). A host page may override per
  // key via window.RR_LENS_HREFS (the harness points these at its own pages).
  var DEFAULT_HREFS = {
    scale_structure_readiness:  '/scale-structure-readiness.php',
    item_clarity:               '/item-clarity-readiness.php',
    response_scale_consistency: '/response-scale-consistency.php',
    redundancy_balance:         '/redundancy-balance.php',
    administration_consistency: '/administration-consistency-reliability.php'
  };

  // Domain-level bands on the 0–100 percentage of the 35-point domain.
  function aggregateBand(pct) {
    if (pct >= 90) return { key: 'strong',      label: 'Strong reliability readiness' };
    if (pct >= 80) return { key: 'good',        label: 'Good — minor reliability revisions recommended' };
    if (pct >= 70) return { key: 'moderate',    label: 'Moderate reliability risk' };
    if (pct >= 60) return { key: 'significant', label: 'Significant reliability risk' };
    return                { key: 'high',         label: 'High reliability risk — revise before launch' };
  }

  /* ── PURE: assemble per-lens engine results into the domain summary. ──
     entries = [{ key, name, weight, result }] where `result` is any lens
     engine's assess() output. Engine-agnostic; fully deterministic; never
     re-scores a lens. Identical shape to the Validity Readiness aggregator. */
  function aggregate(entries) {
    var lenses = (entries || []).map(function (e) {
      var r = e.result || {};
      var ledger = r.ledger || [];
      var blockers = r.blockers || [];
      var warnings = r.warnings || [];
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

  // Settle helper: harness samples are pre-accepted (decision defaults), the
  // same convention the reliability test + aggregators use when seeding.
  function seedFlags(arr) {
    return (arr || []).map(function (f, i) {
      return Object.assign({}, f, {
        flag_id: f.flag_id || ('f' + (i + 1)),
        decision: f.decision || 'accepted',
        blocker_reviewed: !!f.blocker_reviewed
      });
    });
  }

  // Engine resolvers — browser globals when present, else Node require (so the
  // pure aggregate + sample pipeline are unit-testable). One code path either way.
  var inNode = (typeof module !== 'undefined' && module.exports);
  function reliabilitySpecs() {
    if (root.SDSI_RELIABILITY_SPECS) return root.SDSI_RELIABILITY_SPECS;
    return inNode ? require('./reliability-specs.js').SPECS : {};
  }
  function reliabilityLens(key) {
    if (root.SDSI_RELIABILITY_LENSES) return root.SDSI_RELIABILITY_LENSES[key];
    return inNode ? require('./reliability-specs.js').LENSES[key] : null;
  }

  // Run one lens through its engine from seeded inputs. All five are factory
  // lenses reading { flags, context }. Returns { key, name, weight, result }.
  function assessLens(def, inputs) {
    var lens = reliabilityLens(def.key);
    if (!lens) throw new Error('Reliability Readiness: factory lens "' + def.key + '" not loaded.');
    var result = lens.assess({ flags: seedFlags(inputs.flags), context: inputs.context || {} });
    return { key: def.key, name: def.name, weight: def.weight, result: result };
  }

  // Build the five lens entries from sample data (no server).
  function sampleEntries() {
    var specs = reliabilitySpecs();
    return LENS_ORDER.map(function (def) {
      var s = (specs[def.key] && specs[def.key].sample) || { context: {}, flags: [] };
      return assessLens(def, { flags: s.flags, context: s.context });
    });
  }

  // Build the five lens entries from a saved-review payload (live mode).
  // payload.lenses[key] = { flags, context }. Missing/unreviewed lenses
  // contribute a clean (no-flag) result so the domain total still reflects 35.
  function liveEntries(payload) {
    var byKey = (payload && payload.lenses) || {};
    return LENS_ORDER.map(function (def) {
      var saved = byKey[def.key] || {};
      return assessLens(def, { flags: saved.flags || [], context: saved.context || {} });
    });
  }

  /* ════════════════════════════════════════════════════════════════════
     RENDER — the dashboard. Reuses the Instrument Quality visual language
     (iq-df-gate states, iq-flag tones) plus the iq-vr-* dashboard layer, so
     this page is the reliability companion to Validity Readiness.
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
        '<div class="iq-vr-eyebrow"><span class="pip" aria-hidden="true"></span><span>SDSI · Reliability Readiness</span></div>' +
        '<h2 class="iq-vr-score">Reliability Readiness: ' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + '</h2>' +
        '<p class="iq-vr-band">' + esc(d.verdict) + (d.blocked ? '' : ' · ' + d.pct.toFixed(0) + '% of the domain') + '</p>' +
        (d.blocked
          ? '<p class="iq-vr-note">One or more lenses have an unresolved launch blocker. The score is unchanged — the verdict is held at <strong>Blocked for review</strong> until each blocker is reviewed.</p>'
          : '<p class="iq-vr-note">No unresolved launch blockers across the five lenses.</p>') +
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
        '<strong>' + (d.blocked ? 'Reliability Readiness: Blocked for review' : 'Reliability Readiness: launch gate clear') + '</strong>' +
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
        '<p class="iq-df-math">Reliability Readiness = sum of the five lens contributions = ' +
          d.lenses.map(function (l) { return l.points.toFixed(1); }).join(' + ') +
          ' = <strong>' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + '</strong></p>' +
        '<p class="iq-rel-plain">Each lens owns its own 0–100 math; the domain score only sums the SDSI points each lens already produced. Launch blockers are orthogonal and never change this total. Advisory cautions are advisory only and change neither the total nor the verdict.</p>' +
      '</div>';

    // Scope-and-lifecycle notes. Plain text only — they do not touch the
    // score, the blocker logic, or the five lenses.
    var scope =
      '<div class="iq-vr-notes">' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">Reliability Readiness is not empirical reliability</h4>' +
          '<p class="iq-rel-plain">Reliability Readiness is a pre-administration review of survey design. It evaluates whether the instrument is structured to support consistent responses before data are collected. It does not prove that the survey is statistically reliable. Empirical reliability evidence comes later through response data, including alpha, omega, item-total relationships, stability evidence, and related post-administration analyses.</p>' +
        '</div>' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">SDSI vs RSSI — where this sits in the lifecycle</h4>' +
          '<p class="iq-rel-plain">SDSI evaluates survey design strength before launch.<br>RSSI evaluates survey performance after data collection.</p>' +
          '<p class="iq-rel-plain">SDSI asks: Is the survey ready to collect interpretable data?<br>RSSI asks: Did the collected data perform reliably and support interpretation?</p>' +
        '</div>' +
        '<div class="iq-vr-note-card" data-tone="admin">' +
          '<h4 class="iq-block-h">Live verification required</h4>' +
          '<p class="iq-rel-plain">Live verification required: run db/schema_sdsi_reliability.sql on the production server, connect a real survey, run and save all five Reliability Readiness reviews, then confirm the Reliability Readiness aggregator reads saved results correctly.</p>' +
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
    var container = document.getElementById('reliabilityReadiness');
    if (!container) return;
    var hrefs = root.RR_LENS_HREFS || DEFAULT_HREFS;
    var mode = root.RR_MODE || 'sample';

    function paint(entries) { render(container, aggregate(entries), hrefs); }

    if (mode === 'live') {
      var surveyId = Number(root.RELICHECK_PROJECT_ID) || 0;
      container.innerHTML = '<p class="iq-rel-plain">Loading saved reliability reviews…</p>';
      fetch('/api/sdsi/reliability-readiness.php?survey_id=' + surveyId, { credentials: 'same-origin' })
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

  root.ReliabilityReadiness = {
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
  module.exports = (typeof window !== 'undefined' ? window : this).ReliabilityReadiness;
}
