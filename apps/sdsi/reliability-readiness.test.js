/* Node tests for the Reliability Readiness aggregator
   (apps/sdsi/reliability-readiness.js). Covers the pure aggregate() math, the
   orthogonal "Blocked for review" verdict, advisory-warning neutrality, the
   evidence roll-up, and the full five-lens sample assembly total (29.3 / 35). */
'use strict';

var RR = require('./reliability-readiness.js');

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

/* ── Structure: five lenses, weights sum to 35. ── */
(function structure() {
  eq('five reliability lenses', RR.LENS_ORDER.length, 5);
  var sum = RR.LENS_ORDER.reduce(function (a, l) { return a + l.weight; }, 0);
  eq('weights sum to 35', sum, 35);
  eq('scale_structure weight', RR.LENS_ORDER[0].weight, 8);
  eq('item_clarity weight', RR.LENS_ORDER[1].weight, 8);
  eq('response_scale weight', RR.LENS_ORDER[2].weight, 7);
  eq('redundancy weight', RR.LENS_ORDER[3].weight, 6);
  eq('administration weight', RR.LENS_ORDER[4].weight, 6);
})();

(function pureAggregate() {
  var a = RR.aggregate([
    entry('a', 8, 84, 6.7, true,  [],                    [],             2, 0),
    entry('b', 8, 84, 6.7, true,  [],                    [],             2, 1),
    entry('c', 7, 80, 5.6, false, [{ reviewed: false }], [],             2, 0),
    entry('d', 6, 88, 5.3, true,  [],                    [{ key: 'w' }], 3, 0)
  ]);
  // Aggregator sums lens contribution points ONLY (no re-scoring).
  eq('total points sums lens contributions', a.totalPoints, 24.3);
  eq('max points sums weights', a.maxPoints, 29);
  ok('blocked when any lens not launch-ready', a.blocked === true);
  eq('verdict is Blocked for review when blocked', a.verdict, 'Blocked for review');
  eq('evidence accepted flags', a.evidence.acceptedFlags, 9);
  eq('evidence dismissed flags', a.evidence.dismissedFlags, 1);
  eq('evidence blockers', a.evidence.blockers, 1);
  eq('evidence warnings', a.evidence.warnings, 1);
})();

(function blockerOrthogonality() {
  // The blocker must NOT change the numeric total — only the verdict.
  var clean = RR.aggregate([entry('a', 8, 84, 6.7, true,  [], [], 1, 0)]);
  var block = RR.aggregate([entry('a', 8, 84, 6.7, false, [{ reviewed: false }], [], 1, 0)]);
  eq('blocked total equals clean total (orthogonal)', block.totalPoints, clean.totalPoints);
  ok('clean verdict is not blocked', clean.blocked === false);
  ok('blocked verdict is blocked', block.blocked === true);
})();

(function warningNeutrality() {
  // An advisory warning changes neither the number nor the verdict.
  var none = RR.aggregate([entry('a', 8, 84, 6.7, true, [], [],             1, 0)]);
  var warn = RR.aggregate([entry('a', 8, 84, 6.7, true, [], [{ key: 'w' }], 1, 0)]);
  eq('warned total equals unwarned total', warn.totalPoints, none.totalPoints);
  eq('warned verdict equals unwarned verdict', warn.verdict, none.verdict);
  ok('warning does not block', warn.blocked === false);
  eq('warning still tallied in evidence', warn.evidence.warnings, 1);
})();

(function dismissedFlagsDoNotCount() {
  // Dismissed flags never count toward penalties, blockers, or warnings — the
  // engine drops them from the score; the aggregator tallies them separately.
  var a = RR.aggregate([entry('a', 8, 100, 8.0, true, [], [], 0, 3)]);
  eq('full score with only dismissed flags', a.totalPoints, 8.0);
  ok('not blocked by dismissed flags', a.blocked === false);
  eq('dismissed flags tallied separately', a.evidence.dismissedFlags, 3);
  eq('no accepted flags', a.evidence.acceptedFlags, 0);
})();

(function reviewedBlockerDoesNotBlock() {
  var a = RR.aggregate([entry('a', 8, 84, 6.7, true, [{ reviewed: true }], [], 1, 0)]);
  ok('reviewed blocker → not blocked', a.blocked === false);
  eq('reviewed blocker still tallied in evidence', a.evidence.blockers, 1);
})();

(function aggregateBands() {
  eq('90%+ strong', RR.aggregateBand(92).key, 'strong');
  eq('80-89 good',  RR.aggregateBand(84).key, 'good');
  eq('70-79 moderate', RR.aggregateBand(76).key, 'moderate');
  eq('60-69 significant', RR.aggregateBand(64).key, 'significant');
  eq('<60 high', RR.aggregateBand(40).key, 'high');
})();

/* ── Full five-lens sample assembly through the real engines. ── */
(function sampleAssembly() {
  var entries = RR.sampleEntries();
  eq('five lenses assembled', entries.length, 5);

  var agg = RR.aggregate(entries);

  // Per-lens contribution points (each lens owns its own math):
  //   scale_structure_readiness   6.7/8
  //   item_clarity                6.7/8
  //   response_scale_consistency  5.6/7
  //   redundancy_balance          5.3/6
  //   administration_consistency  5.0/6  (inconsistent_required_scale_exposure fires)
  //   ── total = 29.3 / 35, blocked for review ──
  var pts = {};
  entries.forEach(function (e) { pts[e.key] = e.result.sdsiPoints; });
  eq('scale_structure points',     pts.scale_structure_readiness,  6.7);
  eq('item_clarity points',        pts.item_clarity,               6.7);
  eq('response_scale points',      pts.response_scale_consistency, 5.6);
  eq('redundancy points',          pts.redundancy_balance,         5.3);
  eq('administration points',      pts.administration_consistency, 5.0);

  eq('domain total is 29.3 / 35', agg.totalPoints, 29.3);
  eq('domain max is 35', agg.maxPoints, 35);
  ok('sample domain is blocked for review', agg.blocked === true);
  eq('sample verdict', agg.verdict, 'Blocked for review');

  // Only Administration Consistency fires a blocker in the sample.
  var blockedLenses = agg.lenses.filter(function (l) { return !l.launchReady; }).map(function (l) { return l.key; });
  eq('one lens blocked', blockedLenses.length, 1);
  ok('administration_consistency blocked', blockedLenses.indexOf('administration_consistency') >= 0);

  // Evidence roll-up: 2+2+2+2+2 = 10 accepted flags, 0 dismissed, 1 blocker,
  // 0 advisory warnings (each sample stays below the 40% caution threshold and
  // the administration sample supplies no item_count denominator).
  eq('sample accepted flags total', agg.evidence.acceptedFlags, 10);
  eq('sample dismissed flags total', agg.evidence.dismissedFlags, 0);
  eq('sample blocker total', agg.evidence.blockers, 1);
  eq('sample warning total', agg.evidence.warnings, 0);
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' passed, 0 failed');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
