/* ════════════════════════════════════════════════════════════════════════
   SIRI — Survey Instrument Readiness Index — top-level dashboard test
   ────────────────────────────────────────────────────────────────────────
   SIRI assembles the THREE domain aggregators into the 100-point pre-launch
   readiness score. This test verifies the contract:

     • domain weights sum to 100 (50 + 35 + 15)
     • the sample total equals 80.6 / 100 (38.2 + 29.3 + 13.1)
     • SIRI sums DOMAIN contribution points only
     • SIRI does NOT recompute lens scores (it reads each domain's totalPoints)
     • launch blockers are orthogonal — they never change the numeric score
     • advisory warnings are advisory — they change neither the score nor verdict
     • dismissed flags do not count; accepted + severity-overridden flags count
       (verified end-to-end through a live payload + the real engines)
     • a domain blocker rolls up to the SIRI "Blocked for review" verdict
     • the live payload shape matches what liveDomains() consumes

   No DB, no auth, no AI: the domain aggregators run their real engines on sample
   and live-shaped inputs, and SIRI sums their results.
   ════════════════════════════════════════════════════════════════════════ */
'use strict';

global.window = global;
require('./validity-lens-engine.js');
require('./validity-specs.js');
require('./dignity-engine.js');
require('./access-engine.js');
require('./reliability-specs.js');
require('./administration-specs.js');
var VR = require('./validity-readiness.js');
var RR = require('./reliability-readiness.js');
var AR = require('./administration-readiness.js');
var SIRI = require('./siri-readiness.js');

var pass = 0, fail = 0;
function eq(label, got, want) {
  var g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) { pass++; }
  else { fail++; console.error('FAIL: ' + label + ' — got ' + g + ', want ' + w); }
}
function ok(label, cond) { eq(label, !!cond, true); }
function approx(label, got, want) { eq(label, Math.round(got * 10) / 10, want); }

/* ── 1. Domain weights sum to 100. ── */
(function weightsSumTo100() {
  var total = SIRI.DOMAIN_ORDER.reduce(function (a, d) { return a + d.max; }, 0);
  eq('three domains', SIRI.DOMAIN_ORDER.length, 3);
  eq('domain keys + order', SIRI.DOMAIN_ORDER.map(function (d) { return d.key; }),
     ['validity', 'reliability', 'administration']);
  eq('domain weights', SIRI.DOMAIN_ORDER.map(function (d) { return d.max; }), [50, 35, 15]);
  eq('domain weights sum to 100', total, 100);
})();

/* ── 2. Sample total = 80.6 / 100, all three domains blocked. ── */
(function sampleTotal() {
  var d = SIRI.aggregate(SIRI.sampleDomains());
  approx('sample SIRI total', d.totalPoints, 80.6);
  eq('sample SIRI max', d.maxPoints, 100);
  // Confirm the three component domain scores feed the headline.
  var byKey = {};
  d.domains.forEach(function (x) { byKey[x.key] = x.totalPoints; });
  approx('validity domain sample', byKey.validity, 38.2);
  approx('reliability domain sample', byKey.reliability, 29.3);
  approx('administration domain sample', byKey.administration, 13.1);
  eq('38.2 + 29.3 + 13.1 = 80.6', Math.round((byKey.validity + byKey.reliability + byKey.administration) * 10) / 10, 80.6);
  // All three sample domains carry a blocker → SIRI Blocked for review.
  ok('sample SIRI blocked', d.blocked === true);
  eq('sample SIRI verdict', d.verdict, 'Blocked for review');
  eq('sample domains blocked', d.domainsBlocked, 3);
  ok('sample lenses blocked > 0', d.lensesBlocked > 0);
})();

/* ── 3. SIRI sums DOMAIN contribution points only; never recomputes a lens. ──
   Feed synthetic domain summaries whose per-lens `points` are deliberately
   inconsistent with the domain totalPoints. SIRI must use totalPoints, ignoring
   the lens numbers entirely. */
(function sumsDomainPointsOnly() {
  var domains = [
    { key: 'validity', name: 'V', subtitle: 'Validity Readiness', max: 50, totalPoints: 40.0, maxPoints: 50, pct: 80, band: { key: 'good', label: 'good' }, blocked: false, verdict: 'good',
      lenses: [{ name: 'L1', points: 999, weight: 8, launchReady: true, band: { key: 'good' } }], evidence: { acceptedFlags: 2, dismissedFlags: 1, blockers: 0, warnings: 0 } },
    { key: 'reliability', name: 'R', subtitle: '', max: 35, totalPoints: 30.0, maxPoints: 35, pct: 85.7, band: { key: 'good', label: 'good' }, blocked: false, verdict: 'good',
      lenses: [{ name: 'L2', points: -50, weight: 8, launchReady: true, band: { key: 'good' } }], evidence: { acceptedFlags: 1, dismissedFlags: 0, blockers: 0, warnings: 0 } },
    { key: 'administration', name: 'A', subtitle: '', max: 15, totalPoints: 10.0, maxPoints: 15, pct: 66.7, band: { key: 'significant', label: 'sig' }, blocked: false, verdict: 'sig',
      lenses: [{ name: 'L3', points: 7, weight: 4, launchReady: true, band: { key: 'moderate' } }], evidence: { acceptedFlags: 0, dismissedFlags: 3, blockers: 0, warnings: 0 } }
  ];
  var d = SIRI.aggregate(domains);
  eq('SIRI = 40 + 30 + 10 = 80 (uses domain totalPoints, not lens points)', d.totalPoints, 80);
  eq('SIRI max = 100', d.maxPoints, 100);
  // Evidence rolls up from the domain summaries, not recomputed from lenses.
  eq('accepted rolls up', d.evidence.acceptedFlags, 3);
  eq('dismissed rolls up', d.evidence.dismissedFlags, 4);
})();

/* ── 4. Blockers are orthogonal — never change the numeric score. ── */
(function blockersOrthogonal() {
  var base = [
    { key: 'validity', max: 50, totalPoints: 40.0, maxPoints: 50, pct: 80, band: { key: 'good', label: 'good' }, blocked: false, lenses: [], evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 } },
    { key: 'reliability', max: 35, totalPoints: 30.0, maxPoints: 35, pct: 85, band: { key: 'good', label: 'good' }, blocked: false, lenses: [], evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 } },
    { key: 'administration', max: 15, totalPoints: 10.0, maxPoints: 15, pct: 66.7, band: { key: 'significant', label: 'sig' }, blocked: false, lenses: [], evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 } }
  ];
  var clean = SIRI.aggregate(base);
  ok('clean → not blocked', clean.blocked === false);
  eq('clean verdict is the band label', clean.verdict, clean.band.label);

  // Flip one domain to blocked + give it a blocked lens. Score must NOT move.
  var blockedSet = base.map(function (d) { return Object.assign({}, d); });
  blockedSet[1] = Object.assign({}, blockedSet[1], {
    blocked: true, lenses: [{ name: 'X', points: 0, weight: 8, launchReady: false, band: { key: 'high' } }],
    evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 1, warnings: 0 }
  });
  var d = SIRI.aggregate(blockedSet);
  eq('blocked total unchanged', d.totalPoints, clean.totalPoints);
  ok('blocked → verdict held', d.blocked === true);
  eq('blocked verdict', d.verdict, 'Blocked for review');
  eq('domains blocked rolls up', d.domainsBlocked, 1);
  eq('lenses blocked rolls up', d.lensesBlocked, 1);
})();

/* ── 5. Warnings are advisory — change neither the score nor the verdict. ── */
(function warningsAdvisory() {
  var noWarn = [
    { key: 'validity', max: 50, totalPoints: 45.0, maxPoints: 50, pct: 90, band: { key: 'strong', label: 'strong' }, blocked: false, lenses: [], evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 } },
    { key: 'reliability', max: 35, totalPoints: 33.0, maxPoints: 35, pct: 94, band: { key: 'strong', label: 'strong' }, blocked: false, lenses: [], evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 } },
    { key: 'administration', max: 15, totalPoints: 14.0, maxPoints: 15, pct: 93, band: { key: 'strong', label: 'strong' }, blocked: false, lenses: [], evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 } }
  ];
  var a = SIRI.aggregate(noWarn);
  var withWarn = noWarn.map(function (d) { return Object.assign({}, d); });
  withWarn[2] = Object.assign({}, withWarn[2], { evidence: { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 3 } });
  var b = SIRI.aggregate(withWarn);
  eq('warning does not change total', b.totalPoints, a.totalPoints);
  eq('warning does not change verdict', b.verdict, a.verdict);
  ok('warning does not block', b.blocked === false);
  eq('warnings roll up to evidence', b.evidence.warnings, 3);
})();

/* ── 6. dismissed don't count; accepted + severity_overridden count — end to end. ──
   Run a live-shaped payload through liveDomains() so the REAL engines settle the
   flags inside the administration domain, then confirm SIRI's total reflects it. */
(function decisionsEndToEnd() {
  function siriTotalFor(adminFlags) {
    var payload = {
      validity:       { lenses: {} },
      reliability:    { lenses: {} },
      administration: { lenses: { completion_burden: { context: {}, flags: adminFlags } } }
    };
    return SIRI.aggregate(SIRI.liveDomains(payload)).totalPoints;
  }
  // Clean reference: all domains full → 100.
  var clean = siriTotalFor([]);
  eq('clean live → SIRI 100', clean, 100);

  // Dismissed flag does not count → still 100.
  var dismissed = siriTotalFor([{ flag_id: 'f1', check: 'required_item_burden', item_ref: 'q1', quote: 'x', severity: 'moderate', rationale: 'r', decision: 'dismissed' }]);
  eq('dismissed flag → SIRI unchanged at 100', dismissed, 100);

  // Accepted moderate counts → SIRI drops below 100.
  var accepted = siriTotalFor([{ flag_id: 'f1', check: 'required_item_burden', item_ref: 'q1', quote: 'x', severity: 'moderate', rationale: 'r', decision: 'accepted' }]);
  ok('accepted flag lowers SIRI below 100', accepted < 100);

  // Severity-overridden (major > moderate) counts harder → drops further.
  var overridden = siriTotalFor([{ flag_id: 'f1', check: 'required_item_burden', item_ref: 'q1', quote: 'x', severity: 'major', rationale: 'r', decision: 'severity_overridden' }]);
  ok('severity_overridden lowers SIRI more than accepted-moderate', overridden < accepted);
})();

/* ── 7. Live payload shape matches liveDomains() expectations. ── */
(function livePayloadShape() {
  // Empty / missing domains contribute clean (full-point) results → SIRI 100.
  var empty = SIRI.aggregate(SIRI.liveDomains({ validity: { lenses: {} }, reliability: { lenses: {} }, administration: { lenses: {} } }));
  eq('empty live payload → SIRI 100 / 100', empty.totalPoints, 100);
  ok('empty live payload not blocked', empty.blocked === false);

  // Wholly absent payload still assembles three clean domains → 100.
  var absent = SIRI.aggregate(SIRI.liveDomains({}));
  eq('absent live payload → SIRI 100', absent.totalPoints, 100);
  eq('three domains assembled', absent.domains.length, 3);
})();

/* ── 8. Render produces the required headline + notes (smoke). ── */
(function renderSmoke() {
  var calls = { html: '' };
  var fakeContainer = { set innerHTML(v) { calls.html = v; }, get innerHTML() { return calls.html; } };
  SIRI.render(fakeContainer, SIRI.aggregate(SIRI.sampleDomains()), SIRI.DEFAULT_HREFS);
  ok('headline "SIRI Score" present', /SIRI Score: 80\.6 \/ 100/.test(calls.html));
  ok('score math line present', /SIRI = 38\.1?\.?\d? ?\+/.test(calls.html) || /SIRI = 38\.2 \+ 29\.3 \+ 13\.1 = 80\.6 \/ 100/.test(calls.html));
  ok('domain weights line present', /Total SIRI = 100/.test(calls.html));
  ok('What SIRI means note present', /What SIRI means/.test(calls.html));
  ok('SIRI vs RSSI note present', /SIRI vs RSSI/.test(calls.html));
  ok('does-not-prove note present', /SIRI does not prove validity or reliability/.test(calls.html));
  ok('live-verification note present', /Live verification required/.test(calls.html));
  ok('blocked verdict shown', /Blocked for review/.test(calls.html));
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' assertions, SIRI 100-point dashboard verified');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
