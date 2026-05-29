/* Node tests for the five Reliability Readiness lens specs
   (apps/sdsi/reliability-specs.js). Confirms each spec assembles through the
   shared ValidityLens factory, weights sum to 35, each sample scores
   deterministically, the three reserved blockers fire under their conditions
   and stay orthogonal (never change the score), and the alpha fence holds
   (no response-data-statistic checks in any vocabulary). */
'use strict';

var R = require('./reliability-specs.js');

var pass = 0, fail = 0;
function eq(label, got, want) {
  if (got === want) { pass++; }
  else { fail++; console.error('FAIL: ' + label + ' — got ' + JSON.stringify(got) + ', want ' + JSON.stringify(want)); }
}
function ok(label, cond) { eq(label, !!cond, true); }

// Sample flags carry no decision; settle them as accepted (mirrors the
// aggregator's seedFlags convention).
function seed(arr) {
  return (arr || []).map(function (f) {
    var c = {}; for (var k in f) c[k] = f[k];
    if (c.decision == null) c.decision = 'accepted';
    if (c.blocker_reviewed == null) c.blocker_reviewed = false;
    return c;
  });
}
function assess(key, flags, context) {
  return R.LENSES[key].assess({ flags: seed(flags), context: context || {} });
}

/* ── Structure: five lenses, weights sum to 35. ── */
(function structure() {
  eq('five reliability lenses', R.ORDER.length, 5);
  var sum = R.ORDER.reduce(function (a, k) { return a + R.SPECS[k].weight; }, 0);
  eq('weights sum to 35', sum, 35);
  eq('scale_structure weight', R.SPECS.scale_structure_readiness.weight, 8);
  eq('item_clarity weight', R.SPECS.item_clarity.weight, 8);
  eq('response_scale weight', R.SPECS.response_scale_consistency.weight, 7);
  eq('redundancy weight', R.SPECS.redundancy_balance.weight, 6);
  eq('administration weight', R.SPECS.administration_consistency.weight, 6);
  R.ORDER.forEach(function (k) { ok(k + ' assembled via factory', R.LENSES[k] && typeof R.LENSES[k].assess === 'function'); });
})();

/* ── Each sample scores deterministically. ── */
(function samples() {
  eq('scale_structure sample pts', assess('scale_structure_readiness', R.SPECS.scale_structure_readiness.sample.flags, R.SPECS.scale_structure_readiness.sample.context).sdsiPoints, 6.7);
  eq('item_clarity sample pts', assess('item_clarity', R.SPECS.item_clarity.sample.flags, R.SPECS.item_clarity.sample.context).sdsiPoints, 6.7);
  eq('response_scale sample pts', assess('response_scale_consistency', R.SPECS.response_scale_consistency.sample.flags, R.SPECS.response_scale_consistency.sample.context).sdsiPoints, 5.6);
  eq('redundancy sample pts', assess('redundancy_balance', R.SPECS.redundancy_balance.sample.flags, R.SPECS.redundancy_balance.sample.context).sdsiPoints, 5.3);
  eq('administration sample pts', assess('administration_consistency', R.SPECS.administration_consistency.sample.flags, R.SPECS.administration_consistency.sample.context).sdsiPoints, 5.0);
})();

/* ── Reserved blockers fire only under their conditions. ── */
(function scaleStructureBlockers() {
  // no_declared_scale_structure: structure_undeclared + no structure from either
  // source + a composite intended.
  var undeclared = [{ check: 'structure_undeclared', item_ref: 'all', quote: 'no scales declared', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  var fires = assess('scale_structure_readiness', undeclared, { declared_scales: [], score_reporting: 'subscale' });
  ok('no_declared_scale_structure fires when nothing declared + composite intended', fires.blockers.some(function (b) { return b.key === 'no_declared_scale_structure'; }));
  ok('→ not launch ready', fires.launchReady === false);
  // Cleared when the survey declares scales.
  var declared = assess('scale_structure_readiness', undeclared, { declared_scales: ['A', 'B'], score_reporting: 'subscale' });
  ok('cleared when survey declares scales', !declared.blockers.some(function (b) { return b.key === 'no_declared_scale_structure'; }));
  // Cleared when the reviewer supplies an inferred structure (per reviewer prompt).
  var reviewer = assess('scale_structure_readiness', undeclared, { declared_scales: [], reviewer_scales: ['A'], score_reporting: 'subscale' });
  ok('cleared when reviewer infers a structure', !reviewer.blockers.some(function (b) { return b.key === 'no_declared_scale_structure'; }));
  // Cleared when no composite is intended (nothing to defend).
  var noComposite = assess('scale_structure_readiness', undeclared, { declared_scales: [], score_reporting: 'single_indicators' });
  ok('cleared when no composite intended', !noComposite.blockers.some(function (b) { return b.key === 'no_declared_scale_structure'; }));

  // scale_score_not_defensible: score_structure_mismatch + a composite planned.
  var mismatch = [{ check: 'score_structure_mismatch', item_ref: 'overall', quote: 'overall score from 3 unrelated items', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  var composite = assess('scale_structure_readiness', mismatch, { declared_scales: ['A'], score_reporting: 'overall' });
  ok('scale_score_not_defensible fires for composite plan', composite.blockers.some(function (b) { return b.key === 'scale_score_not_defensible'; }));
  var itemLevel = assess('scale_structure_readiness', mismatch, { declared_scales: ['A'], score_reporting: 'item_level' });
  ok('cleared for item-level reporting', !itemLevel.blockers.some(function (b) { return b.key === 'scale_score_not_defensible'; }));
})();

(function administrationBlocker() {
  var uneven = [{ check: 'uneven_scale_exposure', item_ref: 'safety', quote: 'branch skips 4 of 5 items', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  var composite = assess('administration_consistency', uneven, { has_branching: 'yes', score_reporting: 'subscale' });
  ok('inconsistent_required_scale_exposure fires for composite plan', composite.blockers.some(function (b) { return b.key === 'inconsistent_required_scale_exposure'; }));
  ok('→ not launch ready', composite.launchReady === false);
  var itemLevel = assess('administration_consistency', uneven, { has_branching: 'yes', score_reporting: 'item_level' });
  ok('cleared for item-level reporting', !itemLevel.blockers.some(function (b) { return b.key === 'inconsistent_required_scale_exposure'; }));
  // insufficient_common_items is the second trigger for the same blocker.
  var fewItems = [{ check: 'insufficient_common_items', item_ref: 'engagement', quote: 'most respondents get 1–2 common items', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  var fewComposite = assess('administration_consistency', fewItems, { score_reporting: 'overall' });
  ok('inconsistent_required_scale_exposure fires on insufficient_common_items + composite', fewComposite.blockers.some(function (b) { return b.key === 'inconsistent_required_scale_exposure'; }));
  var fewItemLevel = assess('administration_consistency', fewItems, { score_reporting: 'single_indicators' });
  ok('cleared for single-indicator reporting', !fewItemLevel.blockers.some(function (b) { return b.key === 'inconsistent_required_scale_exposure'; }));
})();

/* ── Blocker orthogonality: a blocker never changes the numeric score. ── */
(function orthogonality() {
  var uneven = [{ check: 'uneven_scale_exposure', item_ref: 'safety', quote: 'q', severity: 'major', rationale: 'r', suggested_revision: 'r' }];
  var blocked = assess('administration_consistency', uneven, { score_reporting: 'subscale' });
  var clean   = assess('administration_consistency', uneven, { score_reporting: 'item_level' });
  eq('blocked score equals unblocked score', blocked.score, clean.score);
  eq('blocked points equal unblocked points', blocked.sdsiPoints, clean.sdsiPoints);
  ok('only the verdict differs', blocked.launchReady === false && clean.launchReady === true);
})();

/* ── Most lenses carry NO blockers (penalties + recommendations only). ── */
(function blockerScope() {
  eq('item_clarity has no blockers', (R.SPECS.item_clarity.blockers || []).length, 0);
  eq('response_scale has no blockers', (R.SPECS.response_scale_consistency.blockers || []).length, 0);
  eq('redundancy has no blockers', (R.SPECS.redundancy_balance.blockers || []).length, 0);
  eq('scale_structure has 2 reserved blockers', R.SPECS.scale_structure_readiness.blockers.length, 2);
  eq('administration has 1 reserved blocker', R.SPECS.administration_consistency.blockers.length, 1);
})();

/* ── item_clarity advisory caution fires only above the 40% threshold. ── */
(function clarityWarning() {
  var flags = [
    { check: 'vague_wording', item_ref: 'q1', quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' },
    { check: 'unclear_referent', item_ref: 'q2', quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' },
    { check: 'confusing_negation', item_ref: 'q3', quote: 'q', severity: 'moderate', rationale: 'r', suggested_revision: 'r' }
  ];
  var hi = assess('item_clarity', flags, { item_count: 5 });   // 3/5 = 60% > 40%
  ok('widespread_clarity_risk fires above threshold', hi.warnings.some(function (w) { return w.key === 'widespread_clarity_risk'; }));
  var lo = assess('item_clarity', flags, { item_count: 20 });  // 3/20 = 15%
  ok('no caution below threshold', !lo.warnings.some(function (w) { return w.key === 'widespread_clarity_risk'; }));
  ok('caution never blocks', hi.launchReady === true);
})();

/* ── Alpha fence: no response-data-statistic vocabulary anywhere. ── */
(function alphaFence() {
  var banned = /alpha|omega|cronbach|item[-_]?total|inter[-_]?item|correlation|factor[-_]?analysis|loading/i;
  R.ORDER.forEach(function (k) {
    var spec = R.SPECS[k];
    Object.keys(spec.checks).forEach(function (c) {
      ok(k + '.' + c + ' check key is data-stat-free', !banned.test(c));
      ok(k + '.' + c + ' check label is data-stat-free', !banned.test(spec.checks[c]));
    });
  });
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' passed, 0 failed');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
