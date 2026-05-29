/* Node tests for the Validity Readiness aggregator (apps/sdsi/validity-readiness.js).
   Covers the pure aggregate() math, the orthogonal "Blocked for review" verdict,
   the evidence roll-up, and the full seven-lens sample assembly totals. */
'use strict';

var VR = require('./validity-readiness.js');

var pass = 0, fail = 0;
function eq(label, got, want) {
  if (got === want) { pass++; }
  else { fail++; console.error('FAIL: ' + label + ' — got ' + JSON.stringify(got) + ', want ' + JSON.stringify(want)); }
}
function ok(label, cond) { eq(label, !!cond, true); }

/* ── Synthetic entries exercise the pure aggregate() in isolation. ── */
function entry(key, weight, score, points, launchReady, blockers, warnings, accepted, dismissed) {
  var ledger = [];
  for (var i = 0; i < accepted; i++) ledger.push({ counted: true,  decision: 'accepted' });
  for (var j = 0; j < dismissed; j++) ledger.push({ counted: false, decision: 'dismissed' });
  return {
    key: key, name: key, weight: weight,
    result: {
      score: score, sdsiPoints: points, sdsiWeight: weight,
      band: { key: 'moderate', label: 'Moderate' },
      launchReady: launchReady,
      blockers: blockers, warnings: warnings, ledger: ledger
    }
  };
}

(function pureAggregate() {
  var a = VR.aggregate([
    entry('a', 8, 84, 6.7, true,  [],                                   [],                 2, 0),
    entry('b', 7, 88, 6.2, true,  [],                                   [],                 2, 1),
    entry('c', 8, 84, 6.7, false, [{ reviewed: false }],                [],                 2, 0),
    entry('d', 4, 78, 3.1, true,  [],                                   [{ key: 'w' }],     3, 0)
  ]);
  eq('total points sums lens contributions', a.totalPoints, 22.7);
  eq('max points sums weights', a.maxPoints, 27);
  ok('blocked when any lens not launch-ready', a.blocked === true);
  eq('verdict is Blocked for review when blocked', a.verdict, 'Blocked for review');
  eq('evidence accepted flags', a.evidence.acceptedFlags, 9);
  eq('evidence dismissed flags', a.evidence.dismissedFlags, 1);
  eq('evidence blockers', a.evidence.blockers, 1);
  eq('evidence warnings', a.evidence.warnings, 1);
})();

(function blockerOrthogonality() {
  // The blocker must NOT change the numeric total — only the verdict.
  var clean = VR.aggregate([entry('a', 8, 84, 6.7, true,  [], [], 1, 0)]);
  var block = VR.aggregate([entry('a', 8, 84, 6.7, false, [{ reviewed: false }], [], 1, 0)]);
  eq('blocked total equals clean total (orthogonal)', block.totalPoints, clean.totalPoints);
  ok('clean verdict is not blocked', clean.blocked === false);
  ok('blocked verdict is blocked', block.blocked === true);
})();

(function reviewedBlockerDoesNotBlock() {
  // A blocker that has been reviewed still counts in evidence but does not
  // hold the verdict (launchReady true).
  var a = VR.aggregate([entry('a', 8, 84, 6.7, true, [{ reviewed: true }], [], 1, 0)]);
  ok('reviewed blocker → not blocked', a.blocked === false);
  eq('reviewed blocker still tallied in evidence', a.evidence.blockers, 1);
})();

(function aggregateBands() {
  eq('90%+ strong', VR.aggregateBand(92).key, 'strong');
  eq('80-89 good',  VR.aggregateBand(84).key, 'good');
  eq('70-79 moderate', VR.aggregateBand(76).key, 'moderate');
  eq('60-69 significant', VR.aggregateBand(64).key, 'significant');
  eq('<60 high', VR.aggregateBand(40).key, 'high');
})();

/* ── Full seven-lens sample assembly through the real engines. ── */
(function sampleAssembly() {
  var entries = VR.sampleEntries();
  eq('seven lenses assembled', entries.length, 7);

  var agg = VR.aggregate(entries);

  // Per-lens contribution points (each lens owns its own math):
  //   construct_definition  -16 → 84 → 6.7/8
  //   purpose_alignment     -12 → 88 → 6.2/7
  //   dimension_coverage    -16 → 84 → 6.7/8   (domain_gap blocker fires)
  //   item_construct_align  -22 → 78 → 5.5/7
  //   response_option       -22 → 78 → 3.1/4   (unanswerable_scale blocker fires)
  //   dignity_framing       -44+6 → 62 → 5.0/8 (2 blockers fire)
  //   access                -44+6 → 62 → 5.0/8 (2 blockers fire)
  //   ── total = 38.2 / 50, blocked for review ──
  var pts = {};
  entries.forEach(function (e) { pts[e.key] = e.result.sdsiPoints; });
  eq('construct_definition points',     pts.construct_definition,     6.7);
  eq('purpose_alignment points',        pts.purpose_alignment,        6.2);
  eq('dimension_coverage points',       pts.dimension_coverage,       6.7);
  eq('item_construct_alignment points', pts.item_construct_alignment, 5.5);
  eq('response_option_validity points', pts.response_option_validity, 3.1);
  eq('dignity_framing points',          pts.dignity_framing,          5.0);
  eq('access points',                   pts.access,                   5.0);

  eq('domain total is 38.2 / 50', agg.totalPoints, 38.2);
  eq('domain max is 50', agg.maxPoints, 50);
  ok('sample domain is blocked for review', agg.blocked === true);
  eq('sample verdict', agg.verdict, 'Blocked for review');

  // Four lenses fire blockers in the sample (dimension, response-option,
  // dignity ×2-as-1-lens, access). Count lenses, not raw blockers.
  var blockedLenses = agg.lenses.filter(function (l) { return !l.launchReady; }).map(function (l) { return l.key; });
  eq('four lenses blocked', blockedLenses.length, 4);
  ok('dimension_coverage blocked', blockedLenses.indexOf('dimension_coverage') >= 0);
  ok('response_option_validity blocked', blockedLenses.indexOf('response_option_validity') >= 0);
  ok('dignity_framing blocked', blockedLenses.indexOf('dignity_framing') >= 0);
  ok('access blocked', blockedLenses.indexOf('access') >= 0);

  // Evidence roll-up: 2+2+2+3+3+4+4 = 20 accepted flags, 0 dismissed.
  eq('sample accepted flags total', agg.evidence.acceptedFlags, 20);
  eq('sample dismissed flags total', agg.evidence.dismissedFlags, 0);
  // Blocker total (raw, across lenses): dimension 1 + response-option 1 +
  // dignity 3 + access 3 = 8. The minors+people-facing sample population fires
  // both the legal-status/minor-disclosure dignity blockers on the same item
  // and the reading-load-vs-minors access blocker, so each standalone lens
  // contributes 3. (Four LENSES are blocked; the raw blocker count is 8.)
  eq('sample blocker total', agg.evidence.blockers, 8);
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' passed, 0 failed');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
