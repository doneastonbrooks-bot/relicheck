/* SIRI Launch Check engine — behaviour tests (run: node launchcheck-engine.test.js)
   Loads siri-readiness.js first so LaunchCheck reuses SiriReadiness.aggregate. */
'use strict';
global.window = global;
require('./siri-readiness.js');
var LaunchCheck = require('./launchcheck-engine.js');

var pass = 0, fail = 0, fails = [];
function ok(cond, msg) { if (cond) { pass++; } else { fail++; fails.push(msg); } }

// Documented consent block — used to isolate OTHER behaviour from the 2D consent blocker.
var DOC = { consent: { documented: true, statement: 'Responses are voluntary and confidential; we use them only to improve onboarding.' } };
function withDoc(over) { return Object.assign({}, DOC, over || {}); }

function proj(over) {
  return Object.assign({
    purpose: 'Measure how supported new hires feel in their first 90 days so we can improve onboarding.',
    population: 'New hires in their first 90 days',
    mode: 'Online',
    constructs: [{ name: 'Support', definition: 'Perceived availability of help, resources, and guidance from managers and peers.' }],
    items: [],
    sections: [{ id: 's1' }]
  }, over || {});
}
function mkItems(n, over) {
  var arr = [];
  for (var i = 0; i < n; i++) {
    arr.push(Object.assign({
      item_ref: 'q' + (i + 1), item_no: i + 1, type: 'Likert Scale',
      prompt: 'I feel supported by my team in clear way number ' + (i + 1) + '.',
      construct: 'Support', options: [], settings: { points: 5 }
    }, over || {}));
  }
  return arr;
}
function domain(r, key) { return r.domains.filter(function (d) { return d.key === key; })[0]; }
function lens(d, key) { return d.lenses.filter(function (l) { return l.key === key; })[0]; }
function lensOf(r, dkey, lkey) { return lens(domain(r, dkey), lkey); }

console.log('SIRI Launch Check engine tests\n');

// 1. Max is 100; domain maxes are 50/35/15; per-lens weights sum correctly.
var full = LaunchCheck.assess(proj({ items: mkItems(6) }));
ok(full.maxPoints === 100, 'SIRI maxPoints is 100 (got ' + full.maxPoints + ')');
ok(domain(full, 'validity').maxPoints === 50, 'Validity domain max is 50');
ok(domain(full, 'reliability').maxPoints === 35, 'Reliability domain max is 35');
ok(domain(full, 'administration').maxPoints === 15, 'Administration domain max is 15');
['validity', 'reliability', 'administration'].forEach(function (k) {
  var d = domain(full, k);
  var w = d.lenses.reduce(function (a, l) { return a + l.weight; }, 0);
  ok(w === d.maxPoints, 'Lens weights for ' + k + ' sum to ' + d.maxPoints + ' (got ' + w + ')');
});

// 2. Empty / no-evidence survey scores LOW, not full (no default-to-full).
var empty = LaunchCheck.assess({ purpose: '', population: '', mode: '', constructs: [], items: [], sections: [] });
ok(empty.totalPoints < 30, 'Empty survey scores < 30 (got ' + empty.totalPoints + ')');
ok(domain(empty, 'administration').totalPoints <= 5, 'Empty Administration <= 5 (got ' + domain(empty, 'administration').totalPoints + ')');

// 3. A fully-specified survey scores strictly higher than the empty one.
ok(full.totalPoints > empty.totalPoints, 'Fully specified scores higher than empty (' + full.totalPoints + ' > ' + empty.totalPoints + ')');
ok(domain(full, 'validity').pct >= 70, 'Built survey Validity reaches at least moderate (got ' + domain(full, 'validity').pct + '%)');
ok(domain(full, 'administration').totalPoints <= 9, 'Administration stays modest without launch fields (got ' + domain(full, 'administration').totalPoints + ')');

// 4. ORTHOGONAL: a critical SDSI flag flips the verdict WITHOUT changing the number.
//    (consent documented so the ONLY differing blocker is the SDSI flag.)
var base = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: DOC }));
var gated = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: DOC }), { sdsiResult: { flags: [{ severity: 'critical' }] } });
ok(gated.totalPoints === base.totalPoints, 'Critical SDSI flag does not change the number (' + gated.totalPoints + ' === ' + base.totalPoints + ')');
ok(base.blocked === false, 'Base survey (consent documented) is not blocked');
ok(gated.blocked === true, 'Critical SDSI flag blocks the verdict');
ok(gated.verdict === 'Blocked for review', 'Gated verdict reads "Blocked for review"');

// 5. Format neutrality: a pure open-ended survey is NOT penalised on scale lenses.
var openSurvey = LaunchCheck.assess(proj({ items: mkItems(6, { type: 'Long Answer', settings: {}, options: [] }) }));
ok(lensOf(openSurvey, 'reliability', 'scale_structure_readiness').score === 100, 'Open-ended: Scale Structure not penalised');
ok(lensOf(openSurvey, 'reliability', 'response_scale_consistency').score === 100, 'Open-ended: Response Scale Consistency not penalised');
ok(lensOf(openSurvey, 'validity', 'response_option_validity').score === 100, 'Open-ended: Response-Option Validity not penalised');

// 6. CONSENT IS NOW A HARD BLOCKER (Phase 2D), orthogonal to the number.
var noConsent = LaunchCheck.assess(proj({ items: mkItems(6) }));
ok(lensOf(noConsent, 'administration', 'consent_privacy').score === 0, 'Missing consent loses all Consent points');
ok(noConsent.blocked === true, 'Missing consent now hard-blocks (2D)');
ok(lensOf(noConsent, 'administration', 'consent_privacy').launchReady === false, 'Consent lens marked not launch-ready when undocumented');
var docConsent = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: DOC }));
ok(lensOf(docConsent, 'administration', 'consent_privacy').score === 100, 'Documented consent earns full Consent points');
ok(docConsent.blocked === false, 'Documented consent clears the block');
var itemConsent = LaunchCheck.assess(proj({ items: [{ type: 'Consent', prompt: 'I agree to participate.', options: ['I agree'] }].concat(mkItems(6)) }));
ok(lensOf(itemConsent, 'administration', 'consent_privacy').score === 100, 'A Consent item also earns full credit');
ok(itemConsent.blocked === false, 'A Consent item also clears the block');
ok(lensOf(docConsent, 'administration', 'consent_privacy').points === lensOf(itemConsent, 'administration', 'consent_privacy').points, 'Documented-field and Consent-item give identical Consent lens points');

// 6b. Partial consent (thin statement): scores 50 but still blocks (strict rule).
var partialConsent = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: { consent: { documented: true, statement: 'short' } } }));
ok(lensOf(partialConsent, 'administration', 'consent_privacy').score === 50, 'Partial consent scores 50 (got ' + lensOf(partialConsent, 'administration', 'consent_privacy').score + ')');
ok(partialConsent.blocked === true, 'Partial consent does NOT clear the blocker');

// 7. Sensitive topic without a decline path blocks; with a decline option it does not.
//    (consent documented so sensitive is the only varying blocker.)
var raceNo = mkItems(6).concat([{ item_ref: 'qx', item_no: 7, type: 'Multiple Choice', prompt: 'What is your race?', construct: 'Support', options: ['A', 'B'] }]);
var raceDecline = mkItems(6).concat([{ item_ref: 'qx', item_no: 7, type: 'Multiple Choice', prompt: 'What is your race?', construct: 'Support', options: ['A', 'B', 'Prefer not to answer'] }]);
var sensitive = LaunchCheck.assess(proj({ items: raceNo, launchReadiness: DOC }));
ok(sensitive.blocked === true, 'Sensitive topic with no decline path blocks');
var sensitiveDecline = LaunchCheck.assess(proj({ items: raceDecline, launchReadiness: DOC }));
ok(sensitiveDecline.blocked === false, 'Sensitive topic WITH a decline option does not block');

// 8. No construct at all blocks the verdict.
var noConstruct = LaunchCheck.assess(proj({ constructs: [], items: mkItems(6, { construct: '' }), launchReadiness: DOC }));
ok(noConstruct.blocked === true, 'No construct defined blocks the verdict');

// 9. Determinism (with launch-readiness fields present).
var lrFull = withDoc({
  instructions: { provided: true, text: 'Please answer honestly; this takes about five minutes to complete.' },
  access: { reviewed: true, languages: 'English, Spanish', plainLanguageAlt: true, accommodationContact: 'access@org.edu' },
  fielding: { window: 'Jun 1 - Jun 14, 2026', channel: 'Email link', estMinutes: '5' },
  dignity: { reviewed: true }, sensitive: { hasSensitive: false, declineProvided: false }
});
ok(JSON.stringify(LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: lrFull }))) ===
   JSON.stringify(LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: lrFull }))), 'assess is deterministic with launch fields');

// 10. SIRI is /100 with a 50-pt validity domain (own rubric, separate from SDSI's 50).
ok(full.maxPoints === 100 && domain(full, 'validity').maxPoints === 50, 'SIRI is /100 with a 50-pt validity domain');

// ── Phase 2D additions ──────────────────────────────────────────────────────

// 11. Explicit instructions field beats item-based inference.
var instrInferred = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: DOC }));
var instrExplicit = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: withDoc({ instructions: { provided: true, text: 'Answer each item honestly; there are no right or wrong answers.' } }) }));
ok(lensOf(instrInferred, 'administration', 'respondent_instructions').score < 100, 'Without the field, instructions lens is below full (got ' + lensOf(instrInferred, 'administration', 'respondent_instructions').score + ')');
ok(lensOf(instrExplicit, 'administration', 'respondent_instructions').score === 100, 'Documented instructions earn full credit');

// 12. Access cap lifts only when reviewed.
var accOff = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: DOC }));
var accOn = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: withDoc({ access: { reviewed: true, languages: 'English, Spanish', plainLanguageAlt: true, accommodationContact: 'access@org.edu' } }) }));
var accOffScore = lensOf(accOff, 'validity', 'access').score, accOnScore = lensOf(accOn, 'validity', 'access').score;
ok(accOffScore <= 55, 'Access stays capped (<=55) when not reviewed (got ' + accOffScore + ')');
ok(accOnScore > accOffScore && accOnScore <= 100, 'Access cap lifts when reviewed (' + accOnScore + ' > ' + accOffScore + ')');

// 13. Dignity cap lifts only when reviewed.
var digOff = lensOf(LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: DOC })), 'validity', 'dignity_framing').score;
var digOn = lensOf(LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: withDoc({ dignity: { reviewed: true } }) })), 'validity', 'dignity_framing').score;
ok(digOff <= 60, 'Dignity capped (<=60) when not reviewed (got ' + digOff + ')');
ok(digOn > digOff, 'Dignity cap lifts when reviewed (' + digOn + ' > ' + digOff + ')');

// 14. Fielding gradation: 3 docs > 2 > 1 > mode-only.
function fScore(f) { return lensOf(LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: withDoc({ fielding: f }) })), 'administration', 'fielding_plan').score; }
var f3 = fScore({ window: 'Jun 1-14', channel: 'Email', estMinutes: '5' });
var f2 = fScore({ window: 'Jun 1-14', channel: 'Email' });
var f1 = fScore({ window: 'Jun 1-14' });
var f0 = fScore({});
ok(f3 === 100 && f2 === 70 && f1 === 50 && f0 === 40, 'Fielding gradation 100/70/50/40 (got ' + [f3, f2, f1, f0].join('/') + ')');

// 15. Sensitive DECLARATION (no auto-detected prompts) triggers the blocker; decline clears it.
var declNo = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: withDoc({ sensitive: { hasSensitive: true, declineProvided: false } }) }));
ok(declNo.blocked === true, 'Declaring sensitive topics with no decline path blocks');
ok(lensOf(declNo, 'administration', 'sensitive_safety').launchReady === false, 'Sensitive lens not launch-ready when declared without decline');
var declYes = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: withDoc({ sensitive: { hasSensitive: true, declineProvided: true } }) }));
ok(declYes.blocked === false, 'Providing a decline path clears the sensitive block');
ok(lensOf(declYes, 'administration', 'sensitive_safety').score === 100, 'Sensitive lens full when decline provided');

// 16. Fully documented launch readiness raises Administration substantially vs none.
var fullyDoc = LaunchCheck.assess(proj({ items: mkItems(6), launchReadiness: lrFull }));
ok(domain(fullyDoc, 'administration').totalPoints > domain(full, 'administration').totalPoints, 'Documented launch fields raise Administration (' + domain(fullyDoc, 'administration').totalPoints + ' > ' + domain(full, 'administration').totalPoints + ')');
ok(fullyDoc.blocked === false, 'A fully documented survey is not blocked');

console.log(pass + ' passed, ' + fail + ' failed');
if (fail) { console.log('\nFailures:'); fails.forEach(function (f) { console.log('  ✗ ' + f); }); process.exit(1); }
console.log('All SIRI Launch Check tests passed.');
