/* Node tests for the Access deterministic engine. Run:
   node apps/sdsi/access-engine.test.js                                        */
var E = require('./access-engine.js');

var pass = 0, fail = 0;
function ok(name, cond) { if (cond) { pass++; console.log('  ✓ ' + name); } else { fail++; console.log('  ✗ ' + name); } }
function eq(name, a, b) { ok(name + ' (' + a + ' === ' + b + ')', a === b); }

function flag(o) {
  return Object.assign({
    flag_id: 'f' + Math.random().toString(36).slice(2, 7),
    check: 'reading_load', item_ref: 'q1', quote: '', severity: 'major',
    rationale: '', suggested_revision: '', decision: 'accepted'
  }, o);
}
function mit(o) {
  return Object.assign({
    mitigation_id: 'm' + Math.random().toString(36).slice(2, 7),
    type: 'plain_language_alt', item_ref: 'q1', decision: 'accepted'
  }, o);
}

console.log('\n— Worked example (must reproduce 62 → 5.0/8) —');
(function () {
  var r = E.assess({
    flags: [
      flag({ check: 'reading_load',           severity: 'major',    item_ref: 'q1' }),
      flag({ check: 'language_barrier',        severity: 'major',    item_ref: 'q2' }),
      flag({ check: 'format_inaccessibility',  severity: 'critical', item_ref: 'q3' }),
      flag({ check: 'response_burden',         severity: 'moderate', item_ref: 'q4' })
    ],
    mitigations: [
      mit({ type: 'plain_language_alt',  item_ref: 'qIntroSection', section: 'intro' }),
      mit({ type: 'accommodation_path',  item_ref: 'qIntroSection', section: 'intro' })
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
  for (var i = 0; i < 5; i++) flags.push(flag({ check: 'reading_load', severity: 'major', item_ref: 'q' + i }));
  var s = E.score(flags, []);
  eq('5 majors of one check capped at -18', s.cappedPenalty, -18);
  eq('score floored by cap, not -50', s.final, 82);
  // Two different checks are NOT capped together.
  var s2 = E.score([flag({ check: 'reading_load', severity: 'major' }),
                    flag({ check: 'language_barrier', severity: 'major' })], []);
  eq('two distinct checks sum to -20', s2.cappedPenalty, -20);
})();

console.log('\n— Mitigation credit cap (+12) & offset-only —');
(function () {
  var mits = ['plain_language_alt','translation_provided','accommodation_path','example_or_scaffold','glossary_or_definition'].map(function (t) {
    return mit({ type: t, item_ref: 'q1' });
  }); // 3+3+3+2+2 = 13 raw
  var s = E.score([flag({ severity: 'major' })], mits);  // -10 penalty
  eq('raw credit', s.rawCredit, 13);
  eq('credit capped at 12', s.credit, 12);
  // offset-only: clean survey with credits cannot exceed 100
  var clean = E.score([], mits);
  eq('credits never raise above 100', clean.final, 100);
})();

console.log('\n— Dismissed flags: in ledger, contribute 0 —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'reading_load', severity: 'critical', decision: 'dismissed' }) ],
    mitigations: [], population: {}
  });
  eq('dismissed flag does not penalize', r.score, 100);
  eq('dismissed flag still in ledger', r.ledger.length, 1);
  eq('ledger marks it not counted', r.ledger[0].counted, false);
})();

console.log('\n— severity_overridden counts —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'reading_load', severity: 'minor', decision: 'severity_overridden' }) ],
    mitigations: [], population: {}
  });
  eq('overridden minor counts -3', r.score, 97);
})();

console.log('\n— Blocker: orthogonal gate, never moves the number —');
(function () {
  // language_barrier + people-facing + no protection → blocker fires
  var r = E.assess({
    flags: [ flag({ check: 'language_barrier', severity: 'critical', item_ref: 'q2' }) ],
    mitigations: [], population: { minors: true, peopleFacing: true }
  });
  ok('blocker fired', r.blockers.length >= 1);
  eq('launch blocked', r.launchReady, false);
  eq('score still computed normally (100-18)', r.score, 82);
})();

console.log('\n— Blocker clears with item-scoped protection on same item —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'language_barrier', severity: 'critical', item_ref: 'q2' }) ],
    mitigations: [ mit({ type: 'translation_provided', item_ref: 'q2' }) ],
    population: { minors: true, peopleFacing: true }
  });
  eq('language_excludes_population cleared by item-scoped translation', r.launchReady, true);
})();

console.log('\n— Global mitigation does NOT clear an item-specific blocker —');
(function () {
  // translation attached to a DIFFERENT item cannot clear the blocker on q2
  var r = E.assess({
    flags: [ flag({ check: 'language_barrier', severity: 'critical', item_ref: 'q2' }) ],
    mitigations: [ mit({ type: 'translation_provided', item_ref: 'qOther' }) ],
    population: { minors: true, peopleFacing: true }
  });
  eq('blocker NOT cleared by protection on another item', r.launchReady, false);
})();

console.log('\n— glossary_or_definition is survey-scoped: cannot clear an item blocker —');
(function () {
  // glossary is NOT item-scoped, so even on the same item it cannot clear a blocker
  var r = E.assess({
    flags: [ flag({ check: 'language_barrier', severity: 'critical', item_ref: 'q2' }) ],
    mitigations: [ mit({ type: 'glossary_or_definition', item_ref: 'q2' }) ],
    population: { peopleFacing: true }
  });
  eq('survey-scoped glossary does not clear the blocker', r.launchReady, false);
})();

console.log('\n— Dismissing the flag clears its dependent blocker —');
(function () {
  var r = E.assess({
    flags: [ flag({ check: 'language_barrier', severity: 'critical', item_ref: 'q2', decision: 'dismissed' }) ],
    mitigations: [], population: { minors: true, peopleFacing: true }
  });
  eq('dismissed flag fires no blocker', r.blockers.length, 0);
  eq('launch ready again', r.launchReady, true);
})();

console.log('\n— reading_far_above_minors only when minors + major/critical + unprotected —');
(function () {
  var base = { check: 'reading_load', item_ref: 'qr' };
  var minorMod = E.assess({ flags: [ flag(Object.assign({}, base, { severity: 'moderate' })) ], mitigations: [], population: { minors: true } });
  eq('moderate reading load does not block', minorMod.blockers.filter(function (b){return b.key==='reading_far_above_minors';}).length, 0);
  var minorMajor = E.assess({ flags: [ flag(Object.assign({}, base, { severity: 'major' })) ], mitigations: [], population: { minors: true } });
  ok('major reading load on minors blocks', minorMajor.blockers.some(function (b){return b.key==='reading_far_above_minors';}));
  var adultMajor = E.assess({ flags: [ flag(Object.assign({}, base, { severity: 'major' })) ], mitigations: [], population: { minors: false } });
  eq('adult population does not block on reading load', adultMajor.blockers.filter(function (b){return b.key==='reading_far_above_minors';}).length, 0);
  var scaffolded = E.assess({ flags: [ flag(Object.assign({}, base, { severity: 'major' })) ], mitigations: [ mit({ type: 'example_or_scaffold', item_ref: 'qr' }) ], population: { minors: true } });
  eq('scaffold clears reading blocker', scaffolded.launchReady, true);
})();

console.log('\n— inaccessible_no_alt fires on format barrier without accommodation —');
(function () {
  var unguarded = E.assess({ flags: [ flag({ check: 'format_inaccessibility', severity: 'major', item_ref: 'qf' }) ], mitigations: [], population: { peopleFacing: true } });
  ok('format barrier blocks without accommodation', unguarded.blockers.some(function (b){return b.key==='inaccessible_no_alt';}));
  var guarded = E.assess({ flags: [ flag({ check: 'format_inaccessibility', severity: 'major', item_ref: 'qf' }) ], mitigations: [ mit({ type: 'accommodation_path', item_ref: 'qf' }) ], population: { peopleFacing: true } });
  eq('accommodation path clears format blocker', guarded.launchReady, true);
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
