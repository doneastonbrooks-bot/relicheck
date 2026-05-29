/* Node tests for the Administration Readiness aggregator
   (apps/sdsi/administration-readiness.js). Covers the pure aggregate() math,
   the orthogonal "Blocked for review" verdict, advisory-warning neutrality,
   the evidence roll-up, accepted vs severity_overridden vs dismissed counting,
   and the full five-lens sample assembly total (13.1 / 15). */
'use strict';

var AR = require('./administration-readiness.js');

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

/* ── Structure: five lenses, weights sum to 15. ── */
(function structure() {
  eq('five administration lenses', AR.LENS_ORDER.length, 5);
  var sum = AR.LENS_ORDER.reduce(function (a, l) { return a + l.weight; }, 0);
  eq('weights sum to 15', sum, 15);
  eq('respondent_instructions weight', AR.LENS_ORDER[0].weight, 4);
  eq('consent_privacy weight',         AR.LENS_ORDER[1].weight, 4);
  eq('fielding_plan weight',           AR.LENS_ORDER[2].weight, 3);
  eq('sensitive_safety weight',        AR.LENS_ORDER[3].weight, 2);
  eq('completion_burden weight',       AR.LENS_ORDER[4].weight, 2);
})();

/* ── Aggregator sums lens contribution points ONLY (no re-scoring). ── */
(function pureAggregate() {
  var a = AR.aggregate([
    entry('a', 4, 88, 3.5, true,  [],                    [],             2, 0),
    entry('b', 4, 85, 3.4, false, [{ reviewed: false }], [],             2, 0),
    entry('c', 3, 87, 2.6, false, [{ reviewed: false }], [],             2, 0),
    entry('d', 2, 90, 1.8, true,  [],                    [{ key: 'w' }], 2, 1)
  ]);
  eq('total points sums lens contributions', a.totalPoints, 11.3);
  eq('max points sums weights', a.maxPoints, 13);
  ok('blocked when any lens not launch-ready', a.blocked === true);
  eq('verdict is Blocked for review when blocked', a.verdict, 'Blocked for review');
  eq('evidence accepted flags', a.evidence.acceptedFlags, 8);
  eq('evidence dismissed flags', a.evidence.dismissedFlags, 1);
  eq('evidence blockers', a.evidence.blockers, 2);
  eq('evidence warnings', a.evidence.warnings, 1);
})();

/* ── Blocker orthogonality: changes the verdict, never the number. ── */
(function blockerOrthogonality() {
  var clean = AR.aggregate([entry('a', 4, 85, 3.4, true,  [], [], 1, 0)]);
  var block = AR.aggregate([entry('a', 4, 85, 3.4, false, [{ reviewed: false }], [], 1, 0)]);
  eq('blocked total equals clean total (orthogonal)', block.totalPoints, clean.totalPoints);
  ok('clean verdict is not blocked', clean.blocked === false);
  ok('blocked verdict is blocked', block.blocked === true);
})();

/* ── Advisory warning neutrality: changes neither the number nor the verdict. ── */
(function warningNeutrality() {
  var none = AR.aggregate([entry('a', 2, 90, 1.8, true, [], [],             1, 0)]);
  var warn = AR.aggregate([entry('a', 2, 90, 1.8, true, [], [{ key: 'w' }], 1, 0)]);
  eq('warned total equals unwarned total', warn.totalPoints, none.totalPoints);
  eq('warned verdict equals unwarned verdict', warn.verdict, none.verdict);
  ok('warning does not block', warn.blocked === false);
  eq('warning still tallied in evidence', warn.evidence.warnings, 1);
})();

/* ── Dismissed flags never count toward penalties, blockers, or warnings. ── */
(function dismissedFlagsDoNotCount() {
  var a = AR.aggregate([entry('a', 4, 100, 4.0, true, [], [], 0, 3)]);
  eq('full score with only dismissed flags', a.totalPoints, 4.0);
  ok('not blocked by dismissed flags', a.blocked === false);
  eq('dismissed flags tallied separately', a.evidence.dismissedFlags, 3);
  eq('no accepted flags', a.evidence.acceptedFlags, 0);
})();

/* ── Accepted AND severity_overridden flags both count (counted:true). ── */
(function acceptedAndOverriddenCount() {
  // Mirror the engine ledger contract: counted === (accepted || severity_overridden).
  var e = {
    key: 'a', name: 'a', weight: 4,
    result: {
      score: 80, sdsiPoints: 3.2, sdsiWeight: 4,
      band: { key: 'good', label: 'Good' }, launchReady: true, blockers: [], warnings: [],
      ledger: [
        { counted: true,  decision: 'accepted' },
        { counted: true,  decision: 'severity_overridden' },
        { counted: false, decision: 'dismissed' }
      ]
    }
  };
  var a = AR.aggregate([e]);
  eq('accepted + severity_overridden both counted', a.evidence.acceptedFlags, 2);
  eq('dismissed still separate', a.evidence.dismissedFlags, 1);
})();

(function reviewedBlockerDoesNotBlock() {
  var a = AR.aggregate([entry('a', 4, 85, 3.4, true, [{ reviewed: true }], [], 1, 0)]);
  ok('reviewed blocker → not blocked', a.blocked === false);
  eq('reviewed blocker still tallied in evidence', a.evidence.blockers, 1);
})();

(function aggregateBands() {
  eq('90%+ strong', AR.aggregateBand(92).key, 'strong');
  eq('80-89 good',  AR.aggregateBand(84).key, 'good');
  eq('70-79 moderate', AR.aggregateBand(76).key, 'moderate');
  eq('60-69 significant', AR.aggregateBand(64).key, 'significant');
  eq('<60 high', AR.aggregateBand(40).key, 'high');
})();

/* ── Full five-lens sample assembly through the real engines. ── */
(function sampleAssembly() {
  var entries = AR.sampleEntries();
  eq('five lenses assembled', entries.length, 5);

  var agg = AR.aggregate(entries);

  // Per-lens contribution points (each lens owns its own math):
  //   respondent_instructions  3.5/4
  //   consent_privacy          3.4/4  (missing_consent_or_required_status fires)
  //   fielding_plan            2.6/3  (no_clear_target_population fires)
  //   sensitive_safety         1.8/2  (unsafe_sensitive_disclosure fires)
  //   completion_burden        1.8/2  (survey_not_reasonably_completable fires)
  //   ── total = 13.1 / 15, blocked for review ──
  var pts = {};
  entries.forEach(function (e) { pts[e.key] = e.result.sdsiPoints; });
  eq('respondent_instructions points', pts.respondent_instructions, 3.5);
  eq('consent_privacy points',         pts.consent_privacy,         3.4);
  eq('fielding_plan points',           pts.fielding_plan,           2.6);
  eq('sensitive_safety points',        pts.sensitive_safety,        1.8);
  eq('completion_burden points',       pts.completion_burden,       1.8);

  // Sample total matches the calculated Administration Readiness sample score.
  eq('domain total is 13.1 / 15', agg.totalPoints, 13.1);
  eq('domain max is 15', agg.maxPoints, 15);
  ok('sample domain is blocked for review', agg.blocked === true);
  eq('sample verdict', agg.verdict, 'Blocked for review');

  // Four lenses fire a blocker in the sample; only respondent_instructions is clear.
  var blockedLenses = agg.lenses.filter(function (l) { return !l.launchReady; }).map(function (l) { return l.key; });
  eq('four lenses blocked', blockedLenses.length, 4);
  ok('respondent_instructions is the only clear lens', blockedLenses.indexOf('respondent_instructions') === -1);

  // Evidence roll-up: 2+2+2+2+2 = 10 accepted flags, 0 dismissed, 4 blockers,
  // 0 advisory warnings (the completion_burden sample stays below the 40%
  // threshold: 2 of 8 reviewed units).
  eq('sample accepted flags total', agg.evidence.acceptedFlags, 10);
  eq('sample dismissed flags total', agg.evidence.dismissedFlags, 0);
  eq('sample blocker total', agg.evidence.blockers, 4);
  eq('sample warning total', agg.evidence.warnings, 0);
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' passed, 0 failed');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
