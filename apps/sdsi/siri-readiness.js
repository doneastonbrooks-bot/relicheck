/* ════════════════════════════════════════════════════════════════════════
   SIRI — Survey Instrument Readiness Index — the 100-point top-level dashboard
   ────────────────────────────────────────────────────────────────────────
   Assembles the THREE completed domain aggregators into the single pre-launch
   readiness score:

       Survey Design Strength Index / SDSI (Validity Readiness)   50   (validity-readiness.js)
       Reliability Readiness                                      35   (reliability-readiness.js)
       Administration Readiness                                   15   (administration-readiness.js)
       ────────────────────────────────────────────────────────────
       SIRI                                                      100

   SIRI is the user-facing pre-launch instrument readiness score; the
   implementation namespace stays `sdsi`. SIRI answers: "Is this survey
   instrument ready to collect interpretable data?"

   THIS MODULE DOES NOT RE-SCORE ANYTHING. Each lens owns its own 0–100 math.
   Each DOMAIN aggregator owns its own domain score. SIRI only SUMS the three
   domain contribution points already produced:

       SIRI = Validity Readiness points
            + Reliability Readiness points
            + Administration Readiness points

   The launch gate is orthogonal exactly as it is in each domain: a blocker on
   any domain (or any lens within it) never changes the number — it only flips
   the SIRI verdict to "Blocked for review". Advisory warnings never change the
   number OR the verdict.

   Two data paths feed the SAME pipeline (domain.aggregate → siri.aggregate):
     • sample mode — each domain aggregator seeds its lenses from sample data.
     • live mode   — a SIRI payload carries each domain's saved inputs; each
       domain aggregator re-runs its identical engines client-side, so live and
       sample share one render and one math path.

   PRE-LAUNCH ONLY: SIRI evaluates whether the instrument is ready to launch. It
   does not evaluate survey results — that is RSSI, after data collection.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  // The three domains, in presentation order, with their point weights and the
  // global / Node module that scores each. `subtitle` is shown under the card
  // name (only SDSI carries one). `href` is the default top-level dashboard link.
  var DOMAIN_ORDER = [
    { key: 'validity',       name: 'Survey Design Strength Index / SDSI', subtitle: 'Validity Readiness', max: 50,
      global: 'ValidityReadiness',       module: './validity-readiness.js',       href: '/validity-readiness.php' },
    { key: 'reliability',    name: 'Reliability Readiness',               subtitle: '',                   max: 35,
      global: 'ReliabilityReadiness',    module: './reliability-readiness.js',    href: '/reliability-readiness.php' },
    { key: 'administration', name: 'Administration Readiness',            subtitle: '',                   max: 15,
      global: 'AdministrationReadiness', module: './administration-readiness.js', href: '/administration-readiness.php' }
  ];

  // Default domain dashboard links. A host page may override per key via
  // window.SIRI_DOMAIN_HREFS (the harness points these at its own pages).
  var DEFAULT_HREFS = {
    validity:       '/validity-readiness.php',
    reliability:    '/reliability-readiness.php',
    administration: '/administration-readiness.php'
  };

  // SIRI-level bands on the 0–100 percentage of the 100-point index.
  function siriBand(pct) {
    if (pct >= 90) return { key: 'strong',      label: 'Strong instrument readiness' };
    if (pct >= 80) return { key: 'good',        label: 'Good — minor revisions recommended before launch' };
    if (pct >= 70) return { key: 'moderate',    label: 'Moderate readiness risk' };
    if (pct >= 60) return { key: 'significant', label: 'Significant readiness risk' };
    return                { key: 'high',         label: 'High readiness risk — revise before launch' };
  }

  /* ── PURE: assemble the three domain summaries into the SIRI summary. ──
     domains = [{ key, name, subtitle, max, href, ...domainSummary }] where each
     domainSummary is the output of a domain aggregator's aggregate(): it already
     carries totalPoints / maxPoints / pct / band / blocked / verdict / evidence /
     lenses[]. SIRI reads those numbers — it NEVER recomputes a lens or a domain.
     Fully deterministic. */
  function aggregate(domains) {
    var doms = (domains || []).map(function (d) {
      var lenses = d.lenses || [];
      var lensesBlocked = lenses.filter(function (l) { return l.launchReady === false; }).length;
      var ev = d.evidence || { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 };
      return {
        key:           d.key,
        name:          d.name,
        subtitle:      d.subtitle || '',
        href:          d.href || '#',
        totalPoints:   (d.totalPoints != null ? d.totalPoints : 0),
        maxPoints:     (d.maxPoints != null ? d.maxPoints : (d.max || 0)),
        pct:           (d.pct != null ? d.pct : 0),
        band:          d.band || { key: '', label: '' },
        blocked:       d.blocked === true,
        verdict:       d.verdict || '',
        lenses:        lenses,
        lensesBlocked: lensesBlocked,
        evidence:      ev
      };
    });

    var totalPoints = Math.round(doms.reduce(function (a, d) { return a + (d.totalPoints || 0); }, 0) * 10) / 10;
    var maxPoints   = doms.reduce(function (a, d) { return a + (d.maxPoints || 0); }, 0);
    var pct         = maxPoints > 0 ? (totalPoints / maxPoints) * 100 : 0;
    var blocked     = doms.some(function (d) { return d.blocked; });
    var band        = siriBand(pct);

    var evidence = doms.reduce(function (acc, d) {
      acc.acceptedFlags  += d.evidence.acceptedFlags  || 0;
      acc.dismissedFlags += d.evidence.dismissedFlags || 0;
      acc.blockers       += d.evidence.blockers       || 0;
      acc.warnings       += d.evidence.warnings       || 0;
      return acc;
    }, { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 });

    return {
      domains:        doms,
      totalPoints:    totalPoints,
      maxPoints:      maxPoints,
      pct:            Math.round(pct * 10) / 10,
      band:           band,
      blocked:        blocked,
      verdict:        blocked ? 'Blocked for review' : band.label,
      evidence:       evidence,
      domainsBlocked: doms.filter(function (d) { return d.blocked; }).length,
      lensesBlocked:  doms.reduce(function (a, d) { return a + d.lensesBlocked; }, 0)
    };
  }

  // Domain-aggregator resolvers — browser globals when present, else Node
  // require (so the pure aggregate + sample pipeline are unit-testable).
  var inNode = (typeof module !== 'undefined' && module.exports);
  function domainAgg(def) {
    if (root[def.global]) return root[def.global];
    return inNode ? require(def.module) : null;
  }

  // Decorate a raw domain summary with the SIRI-level meta (name/subtitle/href).
  function decorate(def, summary) {
    var s = summary || {};
    return {
      key:         def.key,
      name:        def.name,
      subtitle:    def.subtitle,
      max:         def.max,
      href:        (root.SIRI_DOMAIN_HREFS && root.SIRI_DOMAIN_HREFS[def.key]) || def.href,
      totalPoints: s.totalPoints,
      maxPoints:   s.maxPoints,
      pct:         s.pct,
      band:        s.band,
      blocked:     s.blocked,
      verdict:     s.verdict,
      lenses:      s.lenses,
      evidence:    s.evidence
    };
  }

  // Build the three domain summaries from sample data (no server). Each domain
  // aggregator owns its own sample assembly; SIRI just collects the results.
  function sampleDomains() {
    return DOMAIN_ORDER.map(function (def) {
      var agg = domainAgg(def);
      if (!agg) throw new Error('SIRI: domain aggregator "' + def.global + '" not loaded.');
      return decorate(def, agg.aggregate(agg.sampleEntries()));
    });
  }

  // Build the three domain summaries from a SIRI live payload. payload carries
  // each domain's saved inputs under its key:
  //   { validity: { lenses }, reliability: { lenses }, administration: { lenses } }
  // Each domain aggregator re-runs its identical engines on those saved inputs;
  // a domain with no saved reviews contributes a clean (full-points) result.
  function liveDomains(payload) {
    var by = payload || {};
    return DOMAIN_ORDER.map(function (def) {
      var agg = domainAgg(def);
      if (!agg) throw new Error('SIRI: domain aggregator "' + def.global + '" not loaded.');
      var dp = by[def.key] || { lenses: {} };
      return decorate(def, agg.aggregate(agg.liveEntries(dp)));
    });
  }

  /* ════════════════════════════════════════════════════════════════════
     RENDER — the top-level dashboard. Reuses the Instrument Quality visual
     language (iq-df-gate states, iq-flag tones, iq-vr-* dashboard layer) so it
     reads as the dashboard ABOVE Validity, Reliability & Administration.
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
        '<div class="iq-vr-eyebrow"><span class="pip" aria-hidden="true"></span><span>SIRI · Survey Instrument Readiness Index</span></div>' +
        '<h2 class="iq-vr-score">SIRI Score: ' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + '</h2>' +
        '<p class="iq-vr-band">' + esc(d.verdict) + (d.blocked ? '' : ' · ' + d.pct.toFixed(0) + '% ready') + '</p>' +
        (d.blocked
          ? '<p class="iq-vr-note">One or more domains have an unresolved launch blocker. The score is unchanged — the verdict is held at <strong>Blocked for review</strong> until each blocker is reviewed.</p>'
          : '<p class="iq-vr-note">No unresolved launch blockers across the three readiness domains.</p>') +
      '</header>';

    var cards = d.domains.map(function (dom) {
      var href = (hrefs[dom.key]) || dom.href || ('#' + dom.key);
      var cardState = dom.blocked ? 'blocked' : (dom.band.key === 'strong' || dom.band.key === 'good' ? 'clear' : 'reviewed');
      var ev = dom.evidence || { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 };
      var blockerChip = ev.blockers > 0
        ? '<span class="iq-flag" data-tone="' + (dom.blocked ? 'alert' : 'ok') + '">' +
            (dom.blocked ? dom.lensesBlocked + ' lens blocker' + (dom.lensesBlocked > 1 ? 's' : '') : 'blockers reviewed') + '</span>'
        : '<span class="iq-flag" data-tone="ok">no blocker</span>';
      var warnChip = ev.warnings > 0
        ? '<span class="iq-flag" data-tone="warn">' + ev.warnings + ' caution' + (ev.warnings > 1 ? 's' : '') + '</span>'
        : '';

      var drill = '<ul class="iq-siri-drill">' + (dom.lenses || []).map(function (l) {
        var tone = l.launchReady === false ? 'blocked' : (l.band && (l.band.key === 'strong' || l.band.key === 'good') ? 'clear' : 'reviewed');
        return '<li data-state="' + tone + '">' +
            '<span class="iq-siri-drill-name">' + esc(l.name) + (l.launchReady === false ? ' <span class="iq-flag" data-tone="alert">blocked</span>' : '') + '</span>' +
            '<span class="iq-siri-drill-pts">' + (l.points != null ? l.points.toFixed(1) : '0.0') + '<span class="iq-vr-card-max">/' + l.weight + '</span></span>' +
          '</li>';
      }).join('') + '</ul>';

      return '<div class="iq-vr-card iq-siri-card" data-state="' + cardState + '">' +
          '<div class="iq-vr-card-head">' +
            '<h3 class="iq-vr-card-name">' + esc(dom.name) + (dom.subtitle ? '<span class="iq-siri-card-sub">' + esc(dom.subtitle) + '</span>' : '') + '</h3>' +
            '<span class="iq-vr-card-pts">' + dom.totalPoints.toFixed(1) + '<span class="iq-vr-card-max">/' + dom.maxPoints + '</span></span>' +
          '</div>' +
          '<p class="iq-vr-card-score">' + dom.pct.toFixed(0) + '% · ' + esc(dom.band.label) + '</p>' +
          '<div class="iq-vr-card-chips">' + blockerChip + warnChip +
            '<span class="iq-vr-card-ev">' + ev.acceptedFlags + ' kept · ' + ev.dismissedFlags + ' dismissed</span>' +
          '</div>' +
          drill +
          '<a class="iq-vr-card-open" href="' + esc(href) + '">Open ' + esc(dom.subtitle || dom.name) + ' →</a>' +
        '</div>';
    }).join('');

    var gate =
      '<div class="iq-df-gate" data-state="' + (d.blocked ? 'blocked' : 'clear') + '">' +
        '<strong>' + (d.blocked ? 'SIRI: Blocked for review' : 'SIRI: launch gate clear') + '</strong>' +
        '<span class="iq-df-gate-note">The launch gate is orthogonal: it never changes the ' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + ' score.</span>' +
        (d.blocked
          ? '<ul class="iq-df-blockers">' + d.domains.filter(function (dom) { return dom.blocked; }).map(function (dom) {
              return '<li><span class="iq-flag" data-tone="alert">Blocked</span> ' + esc(dom.subtitle || dom.name) +
                ' — ' + dom.lensesBlocked + ' lens blocker' + (dom.lensesBlocked > 1 ? 's' : '') +
                ' <a href="' + esc((hrefs[dom.key]) || dom.href || ('#' + dom.key)) + '">review →</a></li>';
            }).join('') + '</ul>'
          : '') +
      '</div>';

    var ev = d.evidence;
    var summary =
      '<div class="iq-vr-evidence">' +
        '<h4 class="iq-block-h">Evidence summary — across all domains</h4>' +
        '<div class="iq-vr-stats">' +
          '<div class="iq-vr-stat"><span class="iq-vr-stat-n">' + ev.acceptedFlags + '</span><span class="iq-vr-stat-l">accepted flags</span></div>' +
          '<div class="iq-vr-stat"><span class="iq-vr-stat-n">' + ev.dismissedFlags + '</span><span class="iq-vr-stat-l">dismissed flags</span></div>' +
          '<div class="iq-vr-stat" data-tone="' + (ev.blockers ? 'alert' : 'ok') + '"><span class="iq-vr-stat-n">' + ev.blockers + '</span><span class="iq-vr-stat-l">launch blockers</span></div>' +
          '<div class="iq-vr-stat" data-tone="' + (ev.warnings ? 'warn' : 'ok') + '"><span class="iq-vr-stat-n">' + ev.warnings + '</span><span class="iq-vr-stat-l">advisory cautions</span></div>' +
          '<div class="iq-vr-stat" data-tone="' + (d.domainsBlocked ? 'alert' : 'ok') + '"><span class="iq-vr-stat-n">' + d.domainsBlocked + '</span><span class="iq-vr-stat-l">domains blocked</span></div>' +
          '<div class="iq-vr-stat" data-tone="' + (d.lensesBlocked ? 'alert' : 'ok') + '"><span class="iq-vr-stat-n">' + d.lensesBlocked + '</span><span class="iq-vr-stat-l">lenses blocked</span></div>' +
        '</div>' +
      '</div>';

    var math =
      '<div class="iq-vr-math">' +
        '<h4 class="iq-block-h">Score math</h4>' +
        '<p class="iq-df-math">SIRI = ' +
          d.domains.map(function (dom) { return dom.totalPoints.toFixed(1); }).join(' + ') +
          ' = <strong>' + d.totalPoints.toFixed(1) + ' / ' + d.maxPoints + '</strong></p>' +
        '<p class="iq-df-math">Domain weights: ' +
          d.domains.map(function (dom) { return esc(dom.subtitle || dom.name) + ' = ' + dom.maxPoints; }).join(' · ') +
          ' · <strong>Total SIRI = 100</strong></p>' +
        '<p class="iq-rel-plain">Each lens owns its own 0–100 math; each domain aggregator owns its own domain score. SIRI only sums the three domain contribution points. Launch blockers are orthogonal and never change this total. Advisory cautions are advisory only and change neither the total nor the verdict.</p>' +
      '</div>';

    // Scope-and-lifecycle notes. Plain text only — they do not touch the score,
    // the blocker logic, or any domain.
    var scope =
      '<div class="iq-vr-notes">' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">What SIRI means</h4>' +
          '<p class="iq-rel-plain">The Survey Instrument Readiness Index, or SIRI, is a pre-launch review of whether a survey instrument is ready to collect interpretable data. It combines design strength, reliability readiness, and administration readiness before responses are collected. SIRI does not evaluate survey results. It evaluates whether the instrument is ready to launch.</p>' +
        '</div>' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">SIRI vs RSSI — where this sits in the lifecycle</h4>' +
          '<p class="iq-rel-plain">SIRI evaluates survey instrument readiness before launch.<br>RSSI evaluates survey performance after data collection.</p>' +
          '<p class="iq-rel-plain">SIRI asks: Is the survey ready to collect interpretable data?<br>RSSI asks: Did the collected data perform reliably and support interpretation?</p>' +
        '</div>' +
        '<div class="iq-vr-note-card">' +
          '<h4 class="iq-block-h">SIRI does not prove validity or reliability</h4>' +
          '<p class="iq-rel-plain">SIRI does not prove that a survey is statistically valid or reliable. It reviews pre-administration evidence that the instrument is designed, structured, and fielded in ways that can support meaningful interpretation. Empirical evidence comes later through response data, including reliability analysis, factor structure, response quality, and other post-administration analyses.</p>' +
        '</div>' +
        '<div class="iq-vr-note-card" data-tone="admin">' +
          '<h4 class="iq-block-h">Live verification required</h4>' +
          '<p class="iq-rel-plain">Live verification required: run all SIRI/SDSI schema files on the production server, connect a real survey, run and save all Validity, Reliability, and Administration Readiness reviews, then confirm the final SIRI dashboard reads saved domain results correctly.</p>' +
          '<p class="iq-rel-plain">Production schema step: Run either the five individual schema files or the combined db/schema_sdsi_all.sql file after confirming the base surveys table exists.</p>' +
        '</div>' +
      '</div>';

    container.innerHTML =
      header +
      gate +
      '<h4 class="iq-block-h">Readiness domains</h4>' +
      '<div class="iq-vr-grid iq-siri-grid">' + cards + '</div>' +
      summary +
      math +
      scope;
  }

  /* ── Browser bootstrap ── */
  function init() {
    if (typeof document === 'undefined') return;
    var container = document.getElementById('siriReadiness');
    if (!container) return;
    var hrefs = root.SIRI_DOMAIN_HREFS || DEFAULT_HREFS;
    var mode = root.SIRI_MODE || 'sample';

    function paint(domains) { render(container, aggregate(domains), hrefs); }

    if (mode === 'live') {
      var surveyId = Number(root.RELICHECK_PROJECT_ID) || 0;
      container.innerHTML = '<p class="iq-rel-plain">Loading saved readiness reviews…</p>';
      fetch('/api/sdsi/siri-readiness.php?survey_id=' + surveyId, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) throw new Error((j && j.error) || 'load_failed');
          paint(liveDomains(j.domains || {}));
        })
        .catch(function () {
          container.innerHTML = '<p class="iq-rel-plain">Could not load saved reviews for this survey yet. Complete and save the Validity, Reliability, and Administration Readiness reviews, then reload.</p>';
        });
    } else {
      paint(sampleDomains());
    }
  }

  root.SiriReadiness = {
    DOMAIN_ORDER:  DOMAIN_ORDER,
    DEFAULT_HREFS: DEFAULT_HREFS,
    siriBand:      siriBand,
    aggregate:     aggregate,
    sampleDomains: sampleDomains,
    liveDomains:   liveDomains,
    render:        render,
    init:          init
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
  module.exports = (typeof window !== 'undefined' ? window : this).SiriReadiness;
}
