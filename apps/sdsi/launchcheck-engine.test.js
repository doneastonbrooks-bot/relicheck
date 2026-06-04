/* SIRI Launch Check engine — behaviour tests (run: node launchcheck-engine.test.js)
   Tests the 50-point, five-domain whole-survey readiness model and the
   Total Survey Strength (SDSI + SIRI = 100) combination + band cap.

       SDSI = 50-pt item/question validity  (separate engine)
       SIRI = 50-pt whole-survey readiness  (this engine)
       Total = SDSI + SIRI = 100
*/
var LaunchCheck = require('./launchcheck-engine.js');

var pass = 0, fail = 0;
function ok(cond, msg) { if (cond) { pass++; } else { fail++; console.log('  FAIL: ' + msg); } }
function domain(res, key) { return (res.domains || []).filter(function (d) { return d.key === key; })[0]; }

// ── builders ──────────────────────────────────────────────────────────────
function likert(construct, prompt, extra) {
  return Object.assign({
    type: 'Likert (5-pt)',
    prompt: prompt || 'I am treated with respect at work.',
    options: ['Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree'],
    construct: construct, required: false, section: 'Main',
    settings: { points: 5, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree' }
  }, extra || {});
}
function manyLikert(n, construct) {
  var a = []; for (var i = 0; i < n; i++) a.push(likert(construct || (i % 2 ? 'Support' : 'Climate'), 'Scale item ' + i)); return a;
}
var CONSENT = { type: 'Consent', prompt: 'Your responses are voluntary and confidential and will be used only in aggregate.', options: ['I agree'] };
function goodProject(extra) {
  var items = [CONSENT];
  for (var i = 0; i < 3; i++) items.push(likert('Climate', 'Climate item ' + i));
  for (var j = 0; j < 3; j++) items.push(likert('Support', 'Support item ' + j));
  return Object.assign({
    title: 'Team Climate Pulse',
    purpose: 'Measure team climate and managerial support to inform coaching decisions next quarter.',
    population: 'All staff', planned_use: 'Identify teams needing support and track change over time.',
    items: items, constructs: [{ name: 'Climate' }, { name: 'Support' }], sections: [{ id: 1, title: 'Main' }]
  }, extra || {});
}

console.log('SIRI Launch Check engine tests (50-pt, 5-domain)\n');

// ── 1. Structure / contract ─────────────────────────────────────────────────
var good = LaunchCheck.assess(goodProject());
ok(good.max === 50, 'SIRI max is 50 (got ' + good.max + ')');
ok(good.domains.length === 5, 'Five domains (got ' + good.domains.length + ')');
ok(good.domains.every(function (d) { return d.max === 10; }), 'Each domain max is 10');
var keys = good.domains.map(function (d) { return d.key; }).sort().join(',');
ok(keys === 'coverage,deployment,purpose,scale,structure', 'Domain keys are the five expected (got ' + keys + ')');
ok(good.domains.every(function (d) { return !/reliab/i.test(d.name); }), 'No reliability domain (RSSI owns reliability)');
ok(Math.abs(good.siri - good.domains.reduce(function (a, d) { return a + d.points; }, 0)) < 0.05, 'SIRI = sum of domain points');
ok(good.siri >= 0 && good.siri <= 50, 'SIRI within 0..50 (got ' + good.siri + ')');

// ── 2. A clean, well-specified survey scores at or near the top ──────────────
ok(good.siri >= 48, 'Well-specified survey scores high (got ' + good.siri + ' / 50)');
ok((good.flags || []).filter(function (f) { return f.severity === 'critical'; }).length === 0, 'Clean survey has no critical flags');

// ── 3. Empty / blank survey scores low ───────────────────────────────────────
var empty = LaunchCheck.assess({ purpose: '', population: '', planned_use: '', items: [], constructs: [], sections: [] });
ok(empty.siri <= 30, 'Empty survey scores low (got ' + empty.siri + ' / 50)');
ok(empty.siri < good.siri, 'Empty scores lower than well-specified (' + empty.siri + ' < ' + good.siri + ')');
ok(domain(empty, 'purpose').points <= 1, 'Empty purpose domain near 0 (no purpose/audience/use)');

// ── 4. Domain behaviours ─────────────────────────────────────────────────────
var noPurpose = LaunchCheck.assess(goodProject({ purpose: '', population: '', planned_use: '' }));
ok(domain(noPurpose, 'purpose').points < domain(good, 'purpose').points, 'Missing purpose lowers the Purpose domain');

// frequency stem on an agreement scale -> scale ding
var freq = LaunchCheck.assess(goodProject({
  items: [CONSENT].concat([
    likert('Climate', 'How often does your manager give you useful feedback?'),
    likert('Climate', 'Climate b'), likert('Climate', 'Climate c'),
    likert('Support', 'Support a'), likert('Support', 'Support b'), likert('Support', 'Support c')
  ])
}));
ok(domain(freq, 'scale').points < 10, 'Frequency stem on an agreement scale dings Scale (got ' + domain(freq, 'scale').points + ')');

// mixed Likert lengths -> scale ding
var mixed = LaunchCheck.assess(goodProject({
  items: [CONSENT].concat([
    likert('Climate', 'a'), likert('Climate', 'b'), likert('Climate', 'c'),
    likert('Support', 'd', { settings: { points: 7, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree' } }),
    likert('Support', 'e'), likert('Support', 'f')
  ])
}));
ok(domain(mixed, 'scale').points < 10, 'Mixed Likert scale lengths ding the Scale domain');

// required sensitive item with no decline path -> deployment CRITICAL
var sensitive = LaunchCheck.assess(goodProject({
  items: goodProject().items.concat([{ type: 'Single Choice', prompt: 'What is your race or ethnicity?', options: ['White', 'Black', 'Asian'], required: true, section: 'Demographics' }])
}));
ok(domain(sensitive, 'deployment').points === 0, 'Required sensitive item with no decline zeros Deployment (critical)');
ok((sensitive.flags || []).some(function (f) { return f.severity === 'critical'; }), 'Required-sensitive emits a critical flag');

// remove consent -> deployment lower
var noConsent = LaunchCheck.assess(goodProject({ items: goodProject().items.slice(1) /* drop CONSENT */ }));
ok(domain(noConsent, 'deployment').points < domain(good, 'deployment').points, 'Removing consent lowers Deployment readiness');

// ── 5. Total = SDSI + SIRI, bands, and number never changes ─────────────────
var t = LaunchCheck.assess(goodProject(), { sdsiResult: { total: 45, deployment_blocker_count: 0 } });
ok(t.total_max === 100, 'Total max is 100');
ok(Math.abs(t.total - (45 + t.siri)) < 0.05, 'Total = SDSI + SIRI (got ' + t.total + ')');
ok(t.sdsi === 45, 'Total carries the SDSI sub-score');

function bandFor(sdsiTotal) { return LaunchCheck.assess(goodProject(), { sdsiResult: { total: sdsiTotal, deployment_blocker_count: 0 } }).total_band; }
ok(bandFor(45) === 'Strong', '95 -> Strong (got ' + bandFor(45) + ')');
ok(bandFor(35) === 'Good', '85 -> Good (got ' + bandFor(35) + ')');
ok(bandFor(25) === 'Caution', '75 -> Caution (got ' + bandFor(25) + ')');
ok(bandFor(15) === 'Weak', '65 -> Weak (got ' + bandFor(15) + ')');
ok(bandFor(5) === 'Not ready', '55 -> Not ready (got ' + bandFor(5) + ')');

// ── 6. Band cap: blockers cap the band, never the number ────────────────────
// One SIRI critical (sensitive item) + strong numbers -> raw high, capped Caution.
var capped1 = LaunchCheck.assess(
  goodProject({ items: goodProject().items.concat([{ type: 'Single Choice', prompt: 'What is your race?', options: ['A', 'B'], required: true, section: 'Demographics' }]) }),
  { sdsiResult: { total: 46, deployment_blocker_count: 0 } }
);
ok(capped1.deployment_blocker_count === 1, 'One SIRI critical counts as one deployment blocker');
ok(capped1.total_raw_band === 'Good' || capped1.total_raw_band === 'Strong', 'Raw band is high before the cap (got ' + capped1.total_raw_band + ')');
ok(capped1.total_band === 'Caution', '>=1 blocker caps the band to Caution (got ' + capped1.total_band + ')');
ok(capped1.total_band_was_capped === true, 'Cap is flagged');
ok(Math.abs(capped1.total - (46 + capped1.siri)) < 0.05, 'Capped band does NOT change the number');

// Three blockers on a large survey (under 20%) -> capped Weak.
var big = LaunchCheck.assess(
  { title: 'Big', purpose: 'A clear and sufficiently detailed purpose statement for testing.', population: 'Staff', planned_use: 'Tracking', items: [CONSENT].concat(manyLikert(20)), constructs: [{ name: 'Climate' }, { name: 'Support' }], sections: [{ id: 1, title: 'Main' }] },
  { sdsiResult: { total: 46, deployment_blocker_count: 3 } }
);
ok(big.deployment_blocker_count === 3, 'Three SDSI blockers carried through (got ' + big.deployment_blocker_count + ')');
ok(big.total_band === 'Weak', '>=3 blockers (under 20%) cap to Weak (got ' + big.total_band + ')');

// Two blockers on a 6-item survey -> 33% -> Not ready.
var pct20 = LaunchCheck.assess(goodProject(), { sdsiResult: { total: 46, deployment_blocker_count: 2 } });
ok(pct20.total_band === 'Not ready', '>=20% of questions blocked -> Not ready (got ' + pct20.total_band + ')');

// ── 7. SIRI-only (no SDSI) returns a SIRI score but no Total ────────────────
var siriOnly = LaunchCheck.assess(goodProject());
ok(siriOnly.total == null, 'No Total when SDSI is not supplied');
ok(siriOnly.siri != null, 'SIRI score present even without SDSI');

// ── 8. Deterministic ────────────────────────────────────────────────────────
ok(JSON.stringify(LaunchCheck.assess(goodProject(), { sdsiResult: { total: 40 } })) ===
   JSON.stringify(LaunchCheck.assess(goodProject(), { sdsiResult: { total: 40 } })), 'assess is deterministic');

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
