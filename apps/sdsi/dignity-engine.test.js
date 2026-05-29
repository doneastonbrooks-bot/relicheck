/* Node tests for the Dignity/Framing deterministic engine. Run:
   node apps/sdsi/dignity-engine.test.js                                       */
var E = require('./dignity-engine.js');

var pass = 0, fail = 0;
function ok(name, cond) { if (cond) { pass++; console.log('  ✓ ' + name); } else { fail++; console.log('  ✗ ' + name); } }
function eq(name, a, b) { ok(name + ' (' + a + ' === ' + b + ')', a === b); }

function flag(o) {
  return Object.assign({
    flag_id: 'f' + Math.random().toString(36).slice(2, 7),
    check: 'deficit_framing', item_ref: 'q1', quote: '', severity: 'major',
    rationale: '', suggested_revision: '', decision: 'accepted'
  }, o);
}
function mit(o) {
  return Object.assign({
    mitigation_id: 'm' + Math.random().toString(36).slice(2, 7),
    type: 'clear_purpose', item_ref: 'q1', decision: 'accepted'
  }, o);
}

console.log('\n— Worked example (must reproduce 62 → 5.0/8) —');
(function () {
  var r = E.assess({
    flags: [
      flag({ check: 'deficit_framing',       severity: 'major',    item_ref: 'q1' }),
      flag({ check: 'identity_erasure',       severity: 'major',    item_ref: 'q2', topic: 'race' }),
      flag({ check: 'extractive_disclosure',  severity: 'critical', item_ref: 'q3', topic: 'legal_status' }),
      flag({ check: 'judging_respondent',     severity: 'moderate', item_ref: 'q4' })
    ],
    mitigations: [
      mit({ type: 'clear_purpose',  item_ref: 'qDemoSection', section: 'demographics' }),
      mit({ type: 'decline_option', item_ref: 'qDemoSection', section: 'demographics' })
    ],
    population: { minors: true, peopleFacing: true, communities: ['multilingual'] }
  });
  eq('capped penalty', r.math.cappedPenalty, -44);
  eq('credit', r.math.credit, 6);
  eq('final score', r.score, 62);
  eq('sdsi points', r.sdsiPoints, 5.0);
  eq('band is significant', r.band.key, 'significant');
})();

console.log('\n— Per-check cap (-18) —');
(function () {
  var flags = [];
  for (var i = 0; i < 5; i++) flags.push(flag({ check: 'deficit_framing', severity: 'major', item_ref: 'q' + i }));
  var s = E.score(flags, []);
  eq('5 majors of one check capped at -18', s.cappedPenalty, -18);
  eq('score floored by cap, not -50', s.final, 82);
  // Two different checks are NOT capped together.
  var s2 = E.score([flag({ check: 'deficit_framing', severity: 'major' }),
                    flag({ check: 'identity_erasure', severity: 'major', topic: 'race' })], []);
  eq('two distinct checks sum to -20', s2.cappedPenalty, -20);
})();

console.log('\n— Mitigation credit cap (+12) & offset-only —');
(function () {
  var mits = ['clear_purpose','decline_option','resource_framing','multiselect_writein','community_language'].map(function (t) {
    return mit({ type: t, item_ref: 'q1' });
  }); // 3+3+3+3+2 = 14 raw
  var s = E.score([flag({ severity: 'major' })], mits);  // -10 penalty
  eq('raw credit', s.rawCredit, 14);
  eq('credit capped at 12', s.credit, 12);
  // offset-only: clean survey with credits cannot exceed 100
  var clean = E.score([], mits);
  eq('credits never raise above 100', clean.final, 100);
})();

console.log('\n— Dismissed flags: in ledger, contribute 0 —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'deficit_framing', severity: 'critical', decision: 'dismissed' }) ],
    mitigations: [], population: {}
  });
  eq('dismissed flag does not penalize', r.score, 100);
  eq('dismissed flag still in ledger', r.ledger.length, 1);
  eq('ledger marks it not counted', r.ledger[0].counted, false);
})();

console.log('\n— severity_overridden counts —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'deficit_framing', severity: 'minor', decision: 'severity_overridden' }) ],
    mitigations: [], population: {}
  });
  eq('overridden minor counts -3', r.score, 97);
})();

console.log('\n— Blocker: orthogonal gate, never moves the number —');
(function () {
  // minor + extractive_disclosure + no protection → blocker fires
  var r = E.assess({
    flags: [ flag({ check: 'extractive_disclosure', severity: 'critical', item_ref: 'q3', topic: 'legal_status' }) ],
    mitigations: [], population: { minors: true, peopleFacing: true }
  });
  ok('blocker fired', r.blockers.length >= 1);
  eq('launch blocked', r.launchReady, false);
  eq('score still computed normally (100-18)', r.score, 82);
})();

console.log('\n— Blocker clears with item-scoped protection on same item —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'extractive_disclosure', severity: 'critical', item_ref: 'q3', topic: 'legal_status' }) ],
    mitigations: [ mit({ type: 'clear_purpose', item_ref: 'q3' }), mit({ type: 'decline_option', item_ref: 'q3' }) ],
    population: { minors: true, peopleFacing: true }
  });
  eq('minor_sensitive_disclosure cleared by item-scoped protection', r.launchReady, true);
})();

console.log('\n— Global mitigation does NOT clear an item-specific blocker —');
(function () {
  // decline_option attached to a DIFFERENT item cannot clear the blocker on q3
  var r = E.assess({
    flags: [ flag({ check: 'extractive_disclosure', severity: 'critical', item_ref: 'q3', topic: 'legal_status' }) ],
    mitigations: [ mit({ type: 'clear_purpose', item_ref: 'qOther' }), mit({ type: 'decline_option', item_ref: 'qOther' }) ],
    population: { minors: true, peopleFacing: true }
  });
  eq('blocker NOT cleared by protection on another item', r.launchReady, false);
})();

console.log('\n— Dismissing the flag clears its dependent blocker —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'extractive_disclosure', severity: 'critical', item_ref: 'q3', topic: 'legal_status', decision: 'dismissed' }) ],
    mitigations: [], population: { minors: true, peopleFacing: true }
  });
  eq('dismissed flag fires no blocker', r.blockers.length, 0);
  eq('launch ready again', r.launchReady, true);
})();

console.log('\n— forced_binary_gender only when required + people-facing + unprotected —');
(function () {
  var base = { check: 'identity_erasure', severity: 'major', item_ref: 'qg', topic: 'gender' };
  var optional = E.assess({ flags: [ flag(Object.assign({}, base, { required: false })) ], mitigations: [], population: { peopleFacing: true } });
  eq('optional gender does not block', optional.blockers.filter(function (b){return b.key==='forced_binary_gender';}).length, 0);
  var required = E.assess({ flags: [ flag(Object.assign({}, base, { required: true })) ], mitigations: [], population: { peopleFacing: true } });
  ok('required+people-facing+unprotected blocks', required.blockers.some(function (b){return b.key==='forced_binary_gender';}));
  var staffOnly = E.assess({ flags: [ flag(Object.assign({}, base, { required: true })) ], mitigations: [], population: { peopleFacing: false } });
  eq('staff-only does not block', staffOnly.blockers.filter(function (b){return b.key==='forced_binary_gender';}).length, 0);
})();

console.log('\n— group_stereotype topics include K-12 additions —');
(function () {
  ['ethnicity','legal_status','housing','special_education'].forEach(function (t) {
    var r = E.assess({ flags: [ flag({ check: 'embedded_stereotype', severity: 'major', topic: t }) ], mitigations: [], population: {} });
    ok('group_stereotype fires for ' + t, r.blockers.some(function (b){return b.key==='group_stereotype';}));
  });
})();

console.log('\n— Empty: no flags → 100, launch ready —');
(function () {
  var r = E.assess({ flags: [], mitigations: [], population: {} });
  eq('clean instrument scores 100', r.score, 100);
  eq('clean → 8.0/8', r.sdsiPoints, 8.0);
  eq('clean launch ready', r.launchReady, true);
})();

console.log('\n— Unknown severity / check throws —');
(function () {
  var threwSev = false, threwCheck = false;
  try { E.score([flag({ severity: 'huge' })], []); } catch (e) { threwSev = true; }
  try { E.score([flag({ check: 'made_up' })], []); } catch (e) { threwCheck = true; }
  ok('unknown severity throws', threwSev);
  ok('unknown check throws', threwCheck);
})();

console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ' — ' + pass + ' passed, ' + fail + ' failed\n');
process.exit(fail === 0 ? 0 : 1);
