/* Node tests for the RSSI v1 deterministic scoring engine. Run:
   node apps/rssi/rssi-engine.test.js

   The engine consumes a Phase 4A dataset object (api/dev/rssi-dataset.php).
   These tests build that shape synthetically with a SEEDED PRNG so every run
   is reproducible. The live #24 / #23 acceptance checks run separately in the
   browser against the real datasets.                                          */
'use strict';

var RSSI = require('./rssi-engine.js');

var pass = 0, fail = 0;
function ok(name, cond) { if (cond) { pass++; console.log('  ✓ ' + name); } else { fail++; console.log('  ✗ ' + name); } }
function eq(name, a, b) { ok(name + ' (' + JSON.stringify(a) + ' === ' + JSON.stringify(b) + ')', a === b); }

// ── seeded PRNG (LCG) + gaussian, so fixtures are deterministic ─────────────
function PRNG(seed) { this.s = seed >>> 0; }
PRNG.prototype.next = function () { this.s = (this.s * 1664525 + 1013904223) >>> 0; return this.s / 4294967296; };
PRNG.prototype.gauss = function () {
  var u = 0, v = 0; while (u === 0) u = this.next(); while (v === 0) v = this.next();
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
};
function clampInt(x, lo, hi) { return Math.max(lo, Math.min(hi, Math.round(x))); }

// ── dataset builders in the 4A shape ────────────────────────────────────────
function likertItem(id, label, constructId, construct) {
  return {
    id: id, label: label, type: 'Likert Scale', fieldType: 'numeric_scale',
    structural: false, scorable: true, constructId: constructId, construct: construct,
    options: [], scale: { points: 5, min: 1, max: 5 }, answered: 0, missing: 0, values: []
  };
}
function openItem(id, label) {
  return {
    id: id, label: label, type: 'Long Answer', fieldType: 'open_text',
    structural: false, scorable: false, constructId: null, construct: '',
    options: [], scale: null, answered: 0, missing: 0, values: []
  };
}
function categoricalItem(id, label) {
  return {
    id: id, label: label, type: 'Multiple Choice', fieldType: 'categorical',
    structural: false, scorable: false, constructId: null, construct: '',
    options: ['A', 'B', 'C'], scale: null, answered: 0, missing: 0, values: []
  };
}
function constructRec(id, name, itemIds, scorableCount) {
  var enough = scorableCount >= 3;
  return {
    id: id, name: name, definition: '', itemIds: itemIds,
    itemCount: itemIds.length, scorableCount: scorableCount,
    enoughItems: enough, note: enough ? '' : 'thin'
  };
}
function assemble(projectId, name, items, constructs, sessionIds) {
  // compute answered/missing from values
  var totalN = sessionIds.length;
  var scorableExists = items.some(function (it) { return it.scorable; });
  var hasScorableBySession = {};
  items.forEach(function (it) {
    it.answered = it.values.length;
    it.missing = totalN - it.answered;
    if (it.scorable) it.values.forEach(function (v) { hasScorableBySession[v.sessionId] = true; });
  });
  var analyzableN = sessionIds.filter(function (sid) { return hasScorableBySession[sid]; }).length;
  var minN = 30;
  return {
    ok: true, phase: '4A', projectId: projectId, projectName: name,
    responses: {
      total_n: totalN, analyzable_n: analyzableN, min_n: minN,
      too_few_responses: analyzableN < minN, fence_notes: []
    },
    counts: {},
    fieldTypeSummary: {},
    sessions: sessionIds.map(function (id) { return { id: id, submitted_at: '2026-05-30 08:00:00' }; }),
    items: items, constructs: constructs, unmappedItemIds: []
  };
}

// fill a Likert item's values from a per-session value function
function fill(item, sessionIds, fn) {
  sessionIds.forEach(function (sid, idx) {
    var v = fn(sid, idx);
    if (v === null || v === undefined) return; // unanswered
    item.values.push({ sessionId: sid, raw: String(v), resolved: String(v) });
  });
}

// Build a "good" 2-construct Likert dataset with correlated items (decent alpha)
function buildGood(seed, n) {
  var rng = new PRNG(seed);
  var sessions = []; for (var i = 1; i <= n; i++) sessions.push(i);
  var eng = [101, 102, 103, 104, 105].map(function (id, k) { return likertItem(id, 'Eng ' + k, 9, 'Engagement'); });
  var mgr = [201, 202, 203, 204].map(function (id, k) { return likertItem(id, 'Mgr ' + k, 10, 'Manager Support'); });
  var open = openItem(301, 'What would improve your experience?');
  // latent trait per session per construct
  var engT = {}, mgrT = {};
  sessions.forEach(function (sid) {
    var e = 3 + rng.gauss() * 0.9;
    engT[sid] = e; mgrT[sid] = 3 + rng.gauss() * 0.9 + (e - 3) * 0.3;
  });
  eng.forEach(function (it, k) { fill(it, sessions, function (sid) { return clampInt(engT[sid] + rng.gauss() * 0.55 + (k - 2) * 0.1, 1, 5); }); });
  mgr.forEach(function (it, k) { fill(it, sessions, function (sid) { return clampInt(mgrT[sid] + rng.gauss() * 0.55 + (k - 1.5) * 0.1, 1, 5); }); });
  fill(open, sessions, function (sid, idx) { return idx % 2 === 0 ? 'More flexibility' : null; });
  var items = eng.concat(mgr).concat([open]);
  var constructs = [
    constructRec(9, 'Engagement', [101, 102, 103, 104, 105], 5),
    constructRec(10, 'Manager Support', [201, 202, 203, 204], 4)
  ];
  return assemble(24, 'Team Climate Pulse (RSSI seed)', items, constructs, sessions);
}

// ════════════════════════════════════════════════════════════════════════
console.log('\n─ Good Likert survey (36 resp, 2 constructs) gets a real score ─');
(function () {
  var ds = buildGood(12345, 36);
  var r = RSSI.score(ds);
  console.log('    score=' + r.score + ' band="' + r.band + '"');
  console.log('    constructs=' + r.constructs.map(function (c) { return c.name + ':' + c.alpha; }).join(', '));
  console.log('    domains=' + r.domains.map(function (d) { return d.key + ':' + d.points; }).join(', '));
  ok('score is a number', typeof r.score === 'number' && r.score > 0);
  eq('not withheld', r.fence.withheld, false);
  eq('max 100', r.max, 100);
  ok('four domains', r.domains.length === 4);
  ok('domain points sum to score', Math.abs(r.domains.reduce(function (s, d) { return s + d.points; }, 0) - r.score) < 0.05);
  ok('construct-first: both constructs scored', r.constructs.filter(function (c) { return c.scored; }).length === 2);
  ok('each scored construct has its own alpha', r.constructs.filter(function (c) { return c.scored && typeof c.alpha === 'number'; }).length === 2);
  ok('internal consistency domain present + scored', r.domains[0].key === 'internal_consistency' && r.domains[0].points > 0);
  ok('open-text item is excluded, not broken', r.excludedItems.some(function (e) { return e.itemId === 301 && /not reliability-scored/i.test(e.reason); }));
  ok('open-text raises no item warning', !r.itemWarnings.some(function (w) { return w.itemId === 301; }));
  ok('summary mentions RSSI + band', /RSSI/.test(r.summary) && r.summary.indexOf(r.band) >= 0);
})();

console.log('\n─ Fence case (#23-like: 1 response, categorical, 0 scorable) ─');
(function () {
  var sessions = [1];
  var items = [categoricalItem(401, 'Role'), categoricalItem(402, 'Department')];
  var ds = assemble(23, 'People at Work', items, [], sessions);
  var r = RSSI.score(ds);
  console.log('    score=' + r.score + ' band="' + r.band + '"');
  eq('score withheld (null)', r.score, null);
  eq('verdict insufficient', r.verdict, 'Insufficient data to judge');
  eq('fence withheld flag', r.fence.withheld, true);
  ok('every domain withheld', r.domains.every(function (d) { return d.withheld && d.points === null; }));
  ok('fence note explains withholding', r.fenceNotes.some(function (note) { return /withholds|Insufficient/i.test(note); }));
  ok('categorical items excluded, not broken', r.excludedItems.length === 2 && r.excludedItems.every(function (e) { return /not reliability-scored/i.test(e.reason); }));
})();

console.log('\n─ Thin construct (2 scorable items) is labeled not-enough-evidence ─');
(function () {
  var ds = buildGood(999, 36);
  // strip Engagement down to 2 items
  ds.items = ds.items.filter(function (it) { return [101, 102, 201, 202, 203, 204, 301].indexOf(it.id) >= 0; });
  ds.constructs[0] = constructRec(9, 'Engagement', [101, 102], 2);
  var r = RSSI.score(ds);
  var eng = r.constructs.filter(function (c) { return c.name === 'Engagement'; })[0];
  console.log('    Engagement scored=' + eng.scored + ' note="' + eng.note.slice(0, 40) + '..."');
  eq('thin construct not scored', eng.scored, false);
  ok('thin construct has not-enough-evidence note', /not enough/i.test(eng.note));
  ok('survey still scored from the healthy construct', typeof r.score === 'number');
  ok('Manager Support still scored', r.constructs.filter(function (c) { return c.name === 'Manager Support'; })[0].scored === true);
})();

console.log('\n─ Dead item (zero variance) raises an err warning ─');
(function () {
  var ds = buildGood(2024, 36);
  var dead = ds.items.filter(function (it) { return it.id === 103; })[0];
  dead.values.forEach(function (v) { v.raw = '4'; v.resolved = '4'; });
  var r = RSSI.score(ds);
  var w = r.itemWarnings.filter(function (x) { return x.itemId === 103; })[0];
  console.log('    warning=' + (w ? w.type + '/' + w.severity : 'none'));
  ok('dead item flagged', !!w && w.type === 'dead_item' && w.severity === 'err');
  ok('summary flags items needing attention', /attention/i.test(r.summary));
})();

console.log('\n─ Reverse-keyed item raises negative-discrimination err ─');
(function () {
  var ds = buildGood(7, 36);
  var rev = ds.items.filter(function (it) { return it.id === 104; })[0];
  rev.values.forEach(function (v) { v.raw = String(6 - parseInt(v.raw, 10)); v.resolved = v.raw; });
  var r = RSSI.score(ds);
  var w = r.itemWarnings.filter(function (x) { return x.itemId === 104; })[0];
  console.log('    warning=' + (w ? w.type + '/' + w.severity : 'none'));
  ok('reverse item flagged negative', !!w && w.type === 'negative_discrimination' && w.severity === 'err');
})();

console.log('\n─ Enough responses but no scorable structure → withheld (no_structure) ─');
(function () {
  var sessions = []; for (var i = 1; i <= 36; i++) sessions.push(i);
  var items = [categoricalItem(501, 'A'), categoricalItem(502, 'B'), openItem(503, 'C')];
  // make them "answered" so analyzable_n would be high if they were scorable —
  // but they are not scorable, so analyzable_n stays 0 and the fence trips.
  items[0].values = sessions.map(function (s) { return { sessionId: s, raw: '0', resolved: 'A' }; });
  var ds = assemble(99, 'No structure', items, [], sessions);
  var r = RSSI.score(ds);
  console.log('    analyzableN=' + r.fence.analyzableN + ' score=' + r.score + ' level=' + r.fence.level);
  eq('score withheld', r.score, null);
  eq('verdict insufficient', r.verdict, 'Insufficient data to judge');
})();

console.log('\n─ Straight-lining drags Response Quality down ─');
(function () {
  var clean = RSSI.score(buildGood(555, 36));
  var ds = buildGood(555, 36);
  // force the first 12 sessions to straight-line every scorable item at 3
  ds.items.filter(function (it) { return it.scorable; }).forEach(function (it) {
    it.values.forEach(function (v) { if (v.sessionId <= 12) { v.raw = '3'; v.resolved = '3'; } });
  });
  var dirty = RSSI.score(ds);
  var cleanRq = clean.domains.filter(function (d) { return d.key === 'response_quality'; })[0].points;
  var dirtyRq = dirty.domains.filter(function (d) { return d.key === 'response_quality'; })[0].points;
  console.log('    clean RQ=' + cleanRq + ' dirty RQ=' + dirtyRq);
  ok('straight-lining lowers Response Quality', dirtyRq < cleanRq);
})();

console.log('\n─ Cronbach alpha sanity ─');
(function () {
  // textbook: perfectly parallel items → alpha = 1; independent noise → low.
  var same = [[1, 1], [2, 2], [3, 3], [4, 4], [5, 5]];
  var a = RSSI.cronbachAlpha(same, 2);
  console.log('    alpha(parallel)=' + a);
  ok('parallel items alpha ~ 1', a > 0.99);
  ok('zero-variance returns null', RSSI.cronbachAlpha([[3, 3], [3, 3], [3, 3]], 2) === null);
})();

console.log('\n─ Bands map correctly ─');
(function () {
  eq('85 confident', RSSI.bandFor(85).key, 'confident');
  eq('70 minor', RSSI.bandFor(70).key, 'minor');
  eq('55 caution', RSSI.bandFor(55).key, 'caution');
  eq('54.9 not yet', RSSI.bandFor(54.9).key, 'not_yet');
})();

console.log('\n' + (fail === 0 ? '✓ ALL PASS' : '✗ ' + fail + ' FAILED') + '  (' + pass + ' passed, ' + fail + ' failed)\n');
process.exit(fail === 0 ? 0 : 1);
