/* Node tests for the five factory-lens validity specs + shared engine.
   Run: node apps/sdsi/validity-specs.test.js
   Verifies the shared math, per-check cap, orthogonal blocker gate,
   dismissed-flag handling, and weight→SDSI-point scaling for all five. */
'use strict';

var ValidityLens = require('./validity-lens-engine.js');
var specs = require('./validity-specs.js');
var SPECS = specs.SPECS, ORDER = specs.ORDER, LENSES = specs.LENSES;

var passed = 0, failed = 0;
function ok(name, cond) {
  if (cond) { passed++; }
  else { failed++; console.error('FAIL: ' + name); }
}
function eq(name, a, b) {
  var c = a === b;
  if (!c) console.error('  expected ' + JSON.stringify(b) + ', got ' + JSON.stringify(a));
  ok(name, c);
}

function flag(id, check, severity, decision) {
  return { flag_id: id, check: check, severity: severity, item_ref: id, quote: 'q', rationale: 'r', decision: decision };
}

// ── shared constants ────────────────────────────────────────────────────
eq('severity minor',    ValidityLens.SEVERITY_PENALTY.minor, -3);
eq('severity moderate', ValidityLens.SEVERITY_PENALTY.moderate, -6);
eq('severity major',    ValidityLens.SEVERITY_PENALTY.major, -10);
eq('severity critical', ValidityLens.SEVERITY_PENALTY.critical, -18);
eq('per-check cap', ValidityLens.PER_CHECK_CAP, 18);
eq('credit cap',   ValidityLens.CREDIT_CAP, 12);

// ── all five lenses built, correct weights ───────────────────────────────
eq('five lenses built', ORDER.length, 5);
var expectWeight = { construct_definition: 8, purpose_alignment: 7, dimension_coverage: 8, item_construct_alignment: 7, response_option_validity: 4 };
var weightSum = 0;
ORDER.forEach(function (k) {
  ok('lens ' + k + ' exists', !!LENSES[k]);
  eq('weight ' + k, LENSES[k].SDSI_WEIGHT, expectWeight[k]);
  weightSum += LENSES[k].SDSI_WEIGHT;
});
eq('five weights sum to 34 (+16 dignity/access = 50)', weightSum, 34);

// ── clean instrument → full marks per lens ────────────────────────────────
ORDER.forEach(function (k) {
  var r = LENSES[k].assess({ flags: [] });
  eq('clean ' + k + ' score', r.score, 100);
  eq('clean ' + k + ' points', r.sdsiPoints, expectWeight[k]);
  ok('clean ' + k + ' launch-ready', r.launchReady === true);
});

// ── shared math: two moderate flags on distinct checks ────────────────────
// construct_definition: definition_vague + construct_conflation, both moderate, accepted.
(function () {
  var r = LENSES.construct_definition.assess({ flags: [
    flag('f1', 'definition_vague', 'moderate', 'accepted'),
    flag('f2', 'construct_conflation', 'moderate', 'accepted')
  ] });
  eq('two-moderate cappedPenalty', r.math.cappedPenalty, -12);
  eq('two-moderate score', r.score, 88);
  eq('two-moderate points (88/100*8)', r.sdsiPoints, 7.0);
})();

// ── per-check cap: four moderate on one check caps at -18 ──────────────────
(function () {
  var r = LENSES.purpose_alignment.assess({ flags: [
    flag('a', 'item_purpose_misalignment', 'moderate', 'accepted'),
    flag('b', 'item_purpose_misalignment', 'moderate', 'accepted'),
    flag('c', 'item_purpose_misalignment', 'moderate', 'accepted'),
    flag('d', 'item_purpose_misalignment', 'moderate', 'accepted')  // raw -24, capped -18
  ] });
  eq('per-check capped at -18', r.math.cappedPenalty, -18);
  eq('per-check score', r.score, 82);
})();

// ── dismissed flag contributes 0 but stays in ledger ───────────────────────
(function () {
  var r = LENSES.purpose_alignment.assess({ flags: [
    flag('x', 'item_purpose_misalignment', 'moderate', 'dismissed')
  ] });
  eq('dismissed score', r.score, 100);
  eq('dismissed in ledger', r.ledger.length, 1);
  eq('dismissed not counted', r.ledger[0].counted, false);
  eq('dismissed penalty 0', r.ledger[0].penalty, 0);
})();

// ── severity_overridden counts ────────────────────────────────────────────
(function () {
  var r = LENSES.dimension_coverage.assess({ flags: [
    flag('o', 'dimension_thin', 'major', 'severity_overridden')  // -10
  ] });
  eq('overridden counts', r.score, 90);
})();

// ── construct_definition blocker: needs BOTH construct_unnamed AND
//    definition_absent accepted AND no reviewer-declared construct. Orthogonal. ──
(function () {
  var both = [
    flag('b1', 'construct_unnamed', 'major', 'accepted'),
    flag('b2', 'definition_absent', 'major', 'accepted')
  ];
  var blocked = LENSES.construct_definition.assess({ flags: both, context: {} });
  eq('both-flags blocker fires', blocked.blockers.length, 1);
  eq('blocker key', blocked.blockers[0].key, 'no_defined_construct');
  ok('blocked → not launch-ready', blocked.launchReady === false);
  eq('blocked score (two major, distinct checks)', blocked.score, 80); // gate orthogonal

  // Reviewer-declared construct clears the blocker; score is unchanged.
  var declared = LENSES.construct_definition.assess({ flags: both, context: { construct: 'Student engagement' } });
  eq('declared construct clears blocker', declared.blockers.length, 0);
  ok('declared → launch-ready', declared.launchReady === true);
  eq('declared score unchanged (orthogonal)', declared.score, 80);

  // Only one of the two checks → no blocker.
  var oneOnly = LENSES.construct_definition.assess({ flags: [
    flag('b1', 'construct_unnamed', 'major', 'accepted')
  ], context: {} });
  eq('single check → no blocker', oneOnly.blockers.length, 0);
  ok('single check → launch-ready', oneOnly.launchReady === true);

  // Dismissing definition_absent clears the blocker.
  var dismissed = LENSES.construct_definition.assess({ flags: [
    flag('b1', 'construct_unnamed', 'major', 'accepted'),
    flag('b2', 'definition_absent', 'major', 'dismissed')
  ], context: {} });
  eq('dismiss one clears blocker', dismissed.blockers.length, 0);
  ok('dismiss one → launch-ready', dismissed.launchReady === true);

  // definition_vague / construct_too_broad never block on their own.
  var vague = LENSES.construct_definition.assess({ flags: [
    flag('v', 'definition_vague', 'moderate', 'accepted'),
    flag('tb', 'construct_too_broad', 'moderate', 'accepted')
  ], context: {} });
  eq('vague+broad → no blocker', vague.blockers.length, 0);
  ok('vague+broad → launch-ready', vague.launchReady === true);
})();

// ── purpose_alignment blockers: no_stated_purpose (narrowed) + high_stakes ──
(function () {
  // purpose_unstated accepted AND no declared purpose → blocker fires.
  var noPurpose = LENSES.purpose_alignment.assess({ flags: [
    flag('p', 'purpose_unstated', 'major', 'accepted')
  ], context: {} });
  eq('no_stated_purpose fires', noPurpose.blockers.length, 1);
  eq('no_stated_purpose key', noPurpose.blockers[0].key, 'no_stated_purpose');
  ok('no purpose → not launch-ready', noPurpose.launchReady === false);

  // A reviewer-declared purpose clears it; declared via context.purpose.
  var declaredPurpose = LENSES.purpose_alignment.assess({ flags: [
    flag('p', 'purpose_unstated', 'major', 'accepted')
  ], context: { purpose: 'Improve the after-school program.' } });
  eq('declared purpose clears blocker', declaredPurpose.blockers.length, 0);
  ok('declared purpose → launch-ready', declaredPurpose.launchReady === true);

  // purpose_declared also clears it.
  var declaredAlt = LENSES.purpose_alignment.assess({ flags: [
    flag('p', 'purpose_unstated', 'major', 'accepted')
  ], context: { purpose_declared: 'Reviewer-inferred purpose.' } });
  eq('purpose_declared clears blocker', declaredAlt.blockers.length, 0);

  // high_stakes_use_mismatch: use_mismatch accepted AND stakes high → fires.
  var highStakes = LENSES.purpose_alignment.assess({ flags: [
    flag('u', 'use_mismatch', 'major', 'accepted')
  ], context: { purpose: 'p', stakes: 'high' } });
  eq('high_stakes blocker fires', highStakes.blockers.length, 1);
  eq('high_stakes key', highStakes.blockers[0].key, 'high_stakes_use_mismatch');
  ok('high stakes → not launch-ready', highStakes.launchReady === false);

  // Same use_mismatch but moderate stakes → no blocker.
  var modStakes = LENSES.purpose_alignment.assess({ flags: [
    flag('u', 'use_mismatch', 'major', 'accepted')
  ], context: { purpose: 'p', stakes: 'moderate' } });
  eq('moderate stakes → no blocker', modStakes.blockers.length, 0);
  ok('moderate stakes → launch-ready', modStakes.launchReady === true);

  // scope_creep never blocks on its own.
  var creep = LENSES.purpose_alignment.assess({ flags: [
    flag('s', 'scope_creep', 'moderate', 'accepted')
  ], context: { purpose: 'p', stakes: 'high' } });
  eq('scope_creep → no blocker', creep.blockers.length, 0);
  ok('scope_creep → launch-ready', creep.launchReady === true);
})();

// ── dimension_coverage: narrowed major_coverage_gap + domain_gap rename ─────
(function () {
  // domain_gap accepted, overall reporting → blocker fires.
  var gapOverall = LENSES.dimension_coverage.assess({ flags: [
    flag('g', 'domain_gap', 'major', 'accepted')
  ], context: { score_reporting: 'overall' } });
  eq('domain_gap+overall fires', gapOverall.blockers.length, 1);
  eq('major_coverage_gap key', gapOverall.blockers[0].key, 'major_coverage_gap');
  ok('domain_gap+overall → not launch-ready', gapOverall.launchReady === false);

  // dimension_missing accepted, overall reporting → blocker fires too.
  var missingOverall = LENSES.dimension_coverage.assess({ flags: [
    flag('m', 'dimension_missing', 'major', 'accepted')
  ], context: { score_reporting: 'overall' } });
  eq('dimension_missing+overall fires', missingOverall.blockers.length, 1);

  // Same gap but subscale-only reporting → no blocker.
  var gapSubscale = LENSES.dimension_coverage.assess({ flags: [
    flag('g', 'domain_gap', 'major', 'accepted')
  ], context: { score_reporting: 'subscale' } });
  eq('domain_gap+subscale → no blocker', gapSubscale.blockers.length, 0);
  ok('domain_gap+subscale → launch-ready', gapSubscale.launchReady === true);

  // dimension_thin / overrepresentation / irrelevant_dimension / dimension_overlap
  // never block on their own, even with overall reporting.
  var others = LENSES.dimension_coverage.assess({ flags: [
    flag('t', 'dimension_thin', 'moderate', 'accepted'),
    flag('o', 'overrepresentation', 'moderate', 'accepted'),
    flag('i', 'irrelevant_dimension', 'moderate', 'accepted'),
    flag('v', 'dimension_overlap', 'moderate', 'accepted')
  ], context: { score_reporting: 'overall' } });
  eq('non-gap checks → no blocker', others.blockers.length, 0);
  ok('non-gap checks → launch-ready', others.launchReady === true);
})();

// ── reviewed blocker → launch-ready true, blocker still listed ─────────────
(function () {
  var f = flag('rb', 'scale_mismatch', 'major', 'accepted');
  f.required = true;          // blocker now gates on the item being required
  f.blocker_reviewed = true;
  var r = LENSES.response_option_validity.assess({ flags: [f] });
  eq('reviewed blocker still listed', r.blockers.length, 1);
  ok('reviewed blocker → launch-ready', r.launchReady === true);
})();

// ── response_option_validity: revised vocabulary + narrowed unanswerable_scale ─
(function () {
  var RCHECKS = LENSES.response_option_validity.CHECKS;
  ok('missing_applicable_escape present', !!RCHECKS.missing_applicable_escape);
  ok('unclear_scale_labels present', !!RCHECKS.unclear_scale_labels);
  ok('inconsistent_scale_direction present', !!RCHECKS.inconsistent_scale_direction);
  ok('forced_choice_risk present', !!RCHECKS.forced_choice_risk);
  ok('missing_neutral_or_na removed', !RCHECKS.missing_neutral_or_na);

  function rf(id, check, required) {
    var f = flag(id, check, 'major', 'accepted');
    f.required = required;
    return f;
  }

  // Each answerability check on a REQUIRED item fires the blocker.
  ['scale_mismatch', 'options_not_exhaustive', 'forced_choice_risk', 'missing_applicable_escape'].forEach(function (c) {
    var r = LENSES.response_option_validity.assess({ flags: [rf('b', c, true)] });
    eq('required ' + c + ' fires blocker', r.blockers.length, 1);
    eq('blocker key ' + c, r.blockers[0].key, 'unanswerable_scale');
    ok('required ' + c + ' → not launch-ready', r.launchReady === false);
  });

  // Same checks on an OPTIONAL item → no blocker.
  var optional = LENSES.response_option_validity.assess({ flags: [rf('o', 'scale_mismatch', false)] });
  eq('optional item → no blocker', optional.blockers.length, 0);
  ok('optional item → launch-ready', optional.launchReady === true);

  // A required item with a non-answerability check (unbalanced_scale) → no blocker.
  var balanced = LENSES.response_option_validity.assess({ flags: [rf('u', 'unbalanced_scale', true)] });
  eq('unbalanced_scale never blocks', balanced.blockers.length, 0);
  ok('unbalanced_scale → launch-ready', balanced.launchReady === true);
})();

// ── item_construct_alignment has NO blockers (per spec) ────────────────────
(function () {
  var r = LENSES.item_construct_alignment.assess({ flags: [
    flag('z', 'item_off_construct', 'major', 'accepted')
  ] });
  eq('item-alignment no blockers', r.blockers.length, 0);
  ok('item-alignment launch-ready despite major', r.launchReady === true);
  eq('item-alignment score', r.score, 90);

  // Revised vocabulary present; removed checks gone.
  var ICHECKS = LENSES.item_construct_alignment.CHECKS;
  ok('construct_contamination present', !!ICHECKS.construct_contamination);
  ok('item_too_broad present', !!ICHECKS.item_too_broad);
  ok('item_too_context_dependent present', !!ICHECKS.item_too_context_dependent);
  ok('item_assumption_loaded present', !!ICHECKS.item_assumption_loaded);
  ok('item_measures_other removed', !ICHECKS.item_measures_other);
  ok('construct_irrelevant_content removed', !ICHECKS.construct_irrelevant_content);

  // construct_contamination (major) scores like any major flag.
  var contam = LENSES.item_construct_alignment.assess({ flags: [
    flag('c', 'construct_contamination', 'major', 'accepted')
  ] });
  eq('contamination score', contam.score, 90);
  ok('contamination no blocker', contam.blockers.length === 0);
})();

// ── item_construct_alignment report-level warning (advisory, orthogonal) ────
(function () {
  // >40% of reviewed items accepted as off-construct/contamination → caution.
  var bad = [
    flag('a', 'item_off_construct', 'major', 'accepted'),
    flag('b', 'construct_contamination', 'major', 'accepted'),
    flag('c', 'item_off_construct', 'major', 'accepted')  // 3 of 5 = 0.6 > 0.4
  ];
  var fired = LENSES.item_construct_alignment.assess({ flags: bad, context: { item_count: 5 } });
  eq('warning fires', fired.warnings.length, 1);
  eq('warning key', fired.warnings[0].key, 'scale_interpretation_risk');
  // Advisory only: never blocks launch, never changes the score.
  ok('warning does not block launch', fired.launchReady === true);
  eq('warning blockers still 0', fired.blockers.length, 0);
  // item_off_construct ×2 = -20 capped to -18; construct_contamination -10 → 72.
  eq('warning score unaffected by caution', fired.score, 72);

  // At/under 40% → no warning.
  var under = LENSES.item_construct_alignment.assess({ flags: [
    flag('a', 'item_off_construct', 'major', 'accepted'),
    flag('b', 'construct_contamination', 'major', 'accepted')  // 2 of 5 = 0.4, not > 0.4
  ], context: { item_count: 5 } });
  eq('40% exactly → no warning', under.warnings.length, 0);

  // No denominator → silent.
  var noDenom = LENSES.item_construct_alignment.assess({ flags: bad });
  eq('no item_count → no warning', noDenom.warnings.length, 0);

  // Dismissed flags do not count toward the warning.
  var dismissed = LENSES.item_construct_alignment.assess({ flags: [
    flag('a', 'item_off_construct', 'major', 'dismissed'),
    flag('b', 'construct_contamination', 'major', 'dismissed'),
    flag('c', 'item_off_construct', 'major', 'dismissed')
  ], context: { item_count: 5 } });
  eq('dismissed flags → no warning', dismissed.warnings.length, 0);

  // Other lenses expose warnings as an empty array (spine parity).
  var clean = LENSES.construct_definition.assess({ flags: [] });
  ok('warnings array present on all lenses', Array.isArray(clean.warnings));
  eq('no warnings defined → empty', clean.warnings.length, 0);
})();

// ── each sample (decisions=accepted) scores deterministically ──────────────
ORDER.forEach(function (k) {
  var sample = SPECS[k].sample;
  if (!sample || !sample.flags) return;
  var flags = sample.flags.map(function (f, i) {
    return Object.assign({}, f, { flag_id: k + '_s' + i, decision: 'accepted' });
  });
  var r = LENSES[k].assess({ flags: flags });
  ok('sample ' + k + ' scores in range', r.score >= 0 && r.score <= 100);
  ok('sample ' + k + ' points ≤ weight', r.sdsiPoints <= expectWeight[k] + 1e-9);
});

// ── unknown check / severity throw (guardrails) ────────────────────────────
(function () {
  var threw = false;
  try { LENSES.construct_definition.assess({ flags: [flag('u', 'bogus_check', 'major', 'accepted')] }); }
  catch (e) { threw = true; }
  ok('unknown check throws', threw);

  threw = false;
  try { LENSES.construct_definition.assess({ flags: [flag('u', 'definition_vague', 'catastrophic', 'accepted')] }); }
  catch (e) { threw = true; }
  ok('unknown severity throws', threw);
})();

console.log((failed === 0 ? 'ALL PASS' : 'FAILURES') + ' — ' + passed + ' passed, ' + failed + ' failed');
process.exit(failed === 0 ? 0 : 1);
