/* ════════════════════════════════════════════════════════════════════════
   Administration Readiness REVIEW INFRASTRUCTURE test
   ────────────────────────────────────────────────────────────────────────
   Covers the per-lens review pipeline that mirrors the validity + reliability
   infrastructure: the schema, the component key set, the save/get → aggregator
   payload shape, reviewer-decision counting (dismissed vs. accepted vs.
   severity_overridden), blocker derivation from saved accepted flags, warning
   derivation from saved accepted flags, and the aggregator assembling a domain
   score from saved reviews. No DB and no auth — the save/get endpoints persist
   exactly the shape the client engine consumes, so we exercise the engine +
   aggregator on payloads shaped like administration-get.php returns.

   SCOPE: every lens here is a PRE-LAUNCH administration-readiness lens. There is
   NO alpha fence here (that is a reliability-only constraint) — but equally there
   is NO evaluation of survey results: that belongs to RSSI, after data collection.
   ════════════════════════════════════════════════════════════════════════ */
'use strict';

var fs   = require('fs');
var path = require('path');

global.window = global;
require('./validity-lens-engine.js');
var SPECS = require('./administration-specs.js');
var AR    = require('./administration-readiness.js');

var pass = 0, fail = 0;
function eq(label, got, want) {
  var g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) { pass++; }
  else { fail++; console.error('FAIL: ' + label + ' — got ' + g + ', want ' + w); }
}
function ok(label, cond) { eq(label, !!cond, true); }

var EXPECTED_KEYS = [
  'respondent_instructions', 'consent_privacy', 'fielding_plan',
  'sensitive_safety', 'completion_burden'
];

/* Run one lens engine on a saved-review-shaped input. Mirrors what the live
   aggregator + the lens renderer do: seed the saved flags/context, assess. */
function assess(key, flags, context) {
  var lens = SPECS.LENSES[key];
  return lens.assess({ flags: flags || [], context: context || {} });
}

/* ── 1. Schema exists and is keyed to (survey_id, component). ── */
(function schemaExists() {
  var p = path.resolve(__dirname, '../../db/schema_sdsi_administration.sql');
  ok('schema file exists', fs.existsSync(p));
  var sql = fs.readFileSync(p, 'utf8');
  ok('declares sdsi_administration_reviews table', /CREATE TABLE[^;]*sdsi_administration_reviews/i.test(sql));
  ok('has a component column', /\bcomponent\b/i.test(sql));
  ok('unique on (survey_id, component)', /UNIQUE KEY[^\n]*\(\s*survey_id\s*,\s*component\s*\)/i.test(sql));
  ok('persists the settled flags column', /\bflags\b/i.test(sql));
  ok('persists the score + sdsi_points columns', /\bscore\b/i.test(sql) && /\bsdsi_points\b/i.test(sql));
})();

/* ── 2. Exactly five valid component keys, in order. ── */
(function componentKeys() {
  eq('five administration components in order', SPECS.ORDER, EXPECTED_KEYS);
  eq('aggregator LENS_ORDER keys match', AR.LENS_ORDER.map(function (l) { return l.key; }), EXPECTED_KEYS);
  EXPECTED_KEYS.forEach(function (k) { ok('engine present for ' + k, !!SPECS.LENSES[k]); });
})();

/* ── 3 + 9. The save/get payload shape feeds the aggregator. ──
   administration-get.php returns { component, context, flags, ... }; the live
   aggregator endpoint rolls those into { lenses: { key: { context, flags } } }.
   liveEntries() must consume that exact shape and aggregate() assemble 5 lenses. */
(function savedPayloadFeedsAggregator() {
  var savedPayload = { ok: true, surveyId: 1, lenses: {} };
  EXPECTED_KEYS.forEach(function (k) {
    savedPayload.lenses[k] = { context: {}, flags: [] }; // clean saved reviews
  });
  var entries = AR.liveEntries(savedPayload);
  eq('liveEntries assembles five lenses', entries.length, 5);
  var agg = AR.aggregate(entries);
  eq('clean saved reviews → full 15', agg.totalPoints, 15);
  eq('domain max is 15', agg.maxPoints, 15);
  ok('clean saved reviews not blocked', agg.blocked === false);
  // Missing lenses contribute a clean result (so the domain still reflects 15).
  var partial = AR.aggregate(AR.liveEntries({ lenses: { respondent_instructions: { context: {}, flags: [] } } }));
  eq('missing lenses still total 15 (clean fill)', partial.totalPoints, 15);
})();

/* ── 4. Dismissed flags reload but do NOT count. ── */
(function dismissedDoNotCount() {
  var flags = [{ flag_id: 'f1', check: 'required_item_burden', item_ref: 'q1',
    quote: 'x', severity: 'moderate', rationale: 'r', decision: 'dismissed' }];
  var r = assess('completion_burden', flags, {});
  eq('dismissed → score stays 100', r.score, 100);
  eq('dismissed flag still present in ledger', r.ledger.length, 1);
  ok('dismissed flag not counted', r.ledger[0].counted === false);
  eq('dismissed flag penalty is 0', r.ledger[0].penalty, 0);
})();

/* ── 5. Accepted flags reload AND count. ── */
(function acceptedCounts() {
  var flags = [{ flag_id: 'f1', check: 'required_item_burden', item_ref: 'q1',
    quote: 'x', severity: 'moderate', rationale: 'r', decision: 'accepted' }];
  var r = assess('completion_burden', flags, {});
  ok('accepted moderate lowers the score', r.score < 100);
  eq('accepted moderate penalty is -6', r.ledger[0].penalty, -6);
  ok('accepted flag counted', r.ledger[0].counted === true);
})();

/* ── 6. Severity-overridden flags reload AND count at the overridden severity. ── */
(function severityOverriddenCounts() {
  var flags = [{ flag_id: 'f1', check: 'required_item_burden', item_ref: 'q1',
    quote: 'x', severity: 'major', rationale: 'r', decision: 'severity_overridden' }];
  var r = assess('completion_burden', flags, {});
  ok('severity_overridden counted', r.ledger[0].counted === true);
  eq('severity_overridden penalty uses overridden severity (-10)', r.ledger[0].penalty, -10);
})();

/* ── 7. Blockers derive from SAVED accepted flags (orthogonal to score). ── */
(function blockersFromAcceptedFlags() {
  // consent_privacy: a required survey with no participation statement is a
  // launch-readiness blocker.
  var ctx = { participation_status: 'required' };
  var triggering = [{ flag_id: 'f1', check: 'missing_participation_statement', item_ref: 'intro',
    quote: 'no participation statement', severity: 'major', rationale: 'r', decision: 'accepted' }];
  var r = assess('consent_privacy', triggering, ctx);
  ok('accepted missing_participation_statement + required → blocked', r.launchReady === false);
  ok('one blocker fired', r.blockers.length === 1);
  eq('blocker key', r.blockers[0].key, 'missing_consent_or_required_status');

  // Dismissing the same flag clears the blocker (dismissed never count).
  var dismissed = [Object.assign({}, triggering[0], { decision: 'dismissed' })];
  var r2 = assess('consent_privacy', dismissed, ctx);
  ok('dismissed missing_participation_statement → not blocked', r2.launchReady === true);
  eq('no blocker fires from dismissed flag', r2.blockers.length, 0);

  // Voluntary, low-stakes, consent elsewhere → accepted flag does not block.
  var r3 = assess('consent_privacy', triggering,
    { participation_status: 'voluntary', stakes: 'low' });
  ok('voluntary low-stakes clears the blocker', r3.launchReady === true);
})();

/* ── 8. Warnings derive from SAVED accepted flags (advisory; need denominator). ── */
(function warningsFromAcceptedFlags() {
  // 3 distinct flagged units out of 4 reviewed = 75% > 40% threshold.
  var flags = [
    { flag_id: 'f1', check: 'excessive_completion_burden', item_ref: 'p1', quote: 'a', severity: 'moderate', rationale: 'r', decision: 'accepted' },
    { flag_id: 'f2', check: 'completion_friction_risk',    item_ref: 'p2', quote: 'b', severity: 'moderate', rationale: 'r', decision: 'accepted' },
    { flag_id: 'f3', check: 'required_item_burden',        item_ref: 'p3', quote: 'c', severity: 'moderate', rationale: 'r', decision: 'accepted' }
  ];
  var r = assess('completion_burden', flags, { denominator_reviewed: 4 });
  ok('widespread completion-burden warning fires above 40%', r.warnings.length === 1);
  eq('warning key', r.warnings[0].key, 'widespread_completion_burden_risk');
  ok('advisory warning never blocks', r.launchReady === true);

  // Silent without a denominator.
  var rNoN = assess('completion_burden', flags, {});
  eq('no denominator → warning silent', rNoN.warnings.length, 0);

  // Dismissed flags do not push past the threshold.
  var dismissed = flags.map(function (f) { return Object.assign({}, f, { decision: 'dismissed' }); });
  var rDismissed = assess('completion_burden', dismissed, { denominator_reviewed: 4 });
  eq('dismissed flags do not raise a warning', rDismissed.warnings.length, 0);
})();

if (fail === 0) console.log('ALL PASS — ' + pass + ' assertions, administration review infrastructure verified');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
