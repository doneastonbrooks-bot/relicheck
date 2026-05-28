#!/usr/bin/env node
// Harness for KNOWN_ISSUES §16 v1.0 — the standalone RSSI tagging surface.
//
// Mirrors the platform-side end-to-end pattern (api/__harness/dts_e2e_*.js):
// builds a tagged dataset via the same pure functions the browser uses,
// feeds it through the canonical engine in a vm sandbox, asserts on
// skipped_domains / domain_subscores / diagnostics.
//
// Three activation scenarios per the revised plan (Phase 2 revision #5):
//
//   1. Untagged baseline — auto-detected roles only, no constructs, no
//      anchor_count, no config. §4A / §4B / §4E whole-domain skip;
//      §4G subs 1/2/4 skip; §4D normalization-fallback diagnostic
//      present. Confirms the pre-§16 standalone emit reaches the engine
//      in its current shape.
//
//   2. Constructs + anchor_count, reverse not confirmed — §4A, §4B,
//      activate; §4G subs 1/2/4 activate (anchor unlock at Likert
//      tagging, independent of the reverse gate); §4D diagnostic
//      cleared; §4E sub-2 still skipped.
//
//   3. Constructs + anchor_count + reverse_coded_confirmed: true —
//      §4E sub-2 activates. Everything else as scenario 2.
//
// M1 also adds Layer-1 unit assertions on the rssi-tag-core pure
// functions (dedupeHeaders, validateTags, inferColumnRoles).
//
// Run via:  node apps/__harness/rssi_tag_emit.test.js

'use strict';

const fs   = require('fs');
const path = require('path');
const vm   = require('vm');

const ROOT       = path.resolve(__dirname, '..', '..');
const ENGINE     = path.join(ROOT, 'apps', 'strength-index', 'strength-index.js');
const TAG_CORE   = path.join(ROOT, 'apps', 'rssi', 'rssi-tag-core.js');

const core = require(TAG_CORE);

// ── Engine sandbox (vm) ────────────────────────────────────────────────
const localStore = {};
const sandbox = {
  console,
  setTimeout, clearTimeout, setInterval, clearInterval,
  document: {
    getElementById: () => null, querySelector: () => null,
    querySelectorAll: () => [],
    createElement: () => ({ setAttribute: () => {}, style: {} }),
  },
  window: {
    localStorage: {
      getItem:    (k) => k in localStore ? localStore[k] : null,
      setItem:    (k, v) => { localStore[k] = String(v); },
      removeItem: (k) => { delete localStore[k]; },
    },
  },
};
sandbox.window.document = sandbox.document;
sandbox.localStorage    = sandbox.window.localStorage;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(ENGINE, 'utf8'), sandbox, { filename: 'strength-index.js' });

function runEngine(dataset) {
  const cfg = (dataset && dataset.config) || {};
  return sandbox.window.RSSI_MATH.computeLensesFromDataset(dataset, cfg);
}

// ── Assertion plumbing ────────────────────────────────────────────────
const failures = [];
let assertionCount = 0;
function check(label, cond, detail) {
  assertionCount++;
  if (!cond) failures.push(label + (detail !== undefined ? ' — ' + detail : ''));
}
function eq(label, actual, expected) {
  // eq also increments via check; don't double-count here.
  check(label, actual === expected, 'expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
}

// ── Synthetic parsed input ─────────────────────────────────────────────
// 8 Likert items (2 scales × 4) + 1 demographic + 1 free-text. 120 rows
// so §4D fairness gets ≥ 30 per demographic group (engine threshold).
function makeParsed() {
  const headers = ['eng1','eng2','eng3','eng4','bel1','bel2','bel3','bel4','gender','comments'];
  let seed = 7;
  function rnd() { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; }
  function rint(lo, hi) { return Math.floor(lo + rnd() * (hi - lo + 1)); }
  const rows = [];
  for (let i = 0; i < 120; i++) {
    const engBase = rint(1, 5);
    const belBase = rint(1, 5);
    const jit = (b) => Math.max(1, Math.min(5, b + (rint(0, 2) - 1)));
    const row = {};
    row.eng1 = engBase;    row.eng2 = jit(engBase); row.eng3 = jit(engBase); row.eng4 = jit(engBase);
    row.bel1 = belBase;    row.bel2 = jit(belBase); row.bel3 = jit(belBase); row.bel4 = jit(belBase);
    row.gender = (i % 2) ? 'F' : 'M';
    row.comments = 'Row ' + (i + 1) + ' free text';
    rows.push(row);
  }
  return { headers: headers, rows: rows };
}

const parsed = makeParsed();

// ════════════════════════════════════════════════════════════════════════
// Layer 1 — pure-function unit assertions on rssi-tag-core
// ════════════════════════════════════════════════════════════════════════

// dedupeHeaders
(function () {
  const out = core.dedupeHeaders(['score', 'name', 'score', 'score']);
  eq('dedupeHeaders preserves first occurrence',  out[0], 'score');
  eq('dedupeHeaders leaves unique alone',          out[1], 'name');
  eq('dedupeHeaders suffixes 2nd duplicate as __2', out[2], 'score__2');
  eq('dedupeHeaders suffixes 3rd duplicate as __3', out[3], 'score__3');
})();

// inferColumnRoles
(function () {
  const inferred = core.inferColumnRoles(parsed);
  const byName = {};
  inferred.forEach(function (r) { byName[r.name] = r; });
  eq('inferColumnRoles eng1 → likert',          byName.eng1.autoRole, 'likert');
  eq('inferColumnRoles eng1 anchorCount = 5',   byName.eng1.autoAnchorCount, 5);
  eq('inferColumnRoles bel4 → likert',          byName.bel4.autoRole, 'likert');
  eq('inferColumnRoles gender → demographic',   byName.gender.autoRole, 'demographic');
  eq('inferColumnRoles comments → free_text',   byName.comments.autoRole, 'free_text');
  check('inferColumnRoles eng1 sample is array', Array.isArray(byName.eng1.sample));
})();

// validateTags — blocker case (no Likert)
(function () {
  const v = core.validateTags(
    [{ name: 'gender', role: 'demographic' }],
    {}
  );
  eq('validateTags blocker fires when no Likert', v.blockers.length, 1);
  eq('validateTags blocker kind is no_likert_tagged', v.blockers[0].kind, 'no_likert_tagged');
})();

// validateTags — hint: construct_too_small
(function () {
  const v = core.validateTags(
    [
      { name: 'a', role: 'likert', construct: 'Engagement' },
      { name: 'b', role: 'likert', construct: 'Engagement' },  // 2 items, < 3
    ],
    {}
  );
  const hint = v.hints.find(function (h) { return h.kind === 'construct_too_small'; });
  check('validateTags emits construct_too_small hint', !!hint);
  if (hint) {
    eq('construct_too_small construct name', hint.construct, 'Engagement');
    eq('construct_too_small count',          hint.count, 2);
  }
  eq('validateTags no blocker when Likert present', v.blockers.length, 0);
})();

// validateTags — hint: multiple_criterion
(function () {
  const v = core.validateTags(
    [
      { name: 'eng1', role: 'likert', construct: 'X' },
      { name: 'eng2', role: 'likert', construct: 'X' },
      { name: 'eng3', role: 'likert', construct: 'X' },
      { name: 'outcome_a', role: 'criterion' },
      { name: 'outcome_b', role: 'criterion' },
    ],
    {}
  );
  const hint = v.hints.find(function (h) { return h.kind === 'multiple_criterion'; });
  check('validateTags emits multiple_criterion hint', !!hint);
  if (hint) eq('multiple_criterion count', hint.count, 2);
})();

// validateTags — hint: unusual_likert_range
(function () {
  const v = core.validateTags(
    [
      { name: 'eng1', role: 'likert', construct: 'X', anchorCount: 5 },
      { name: 'eng2', role: 'likert', construct: 'X', anchorCount: 5 },
      { name: 'eng3', role: 'likert', construct: 'X', anchorCount: 5 },
      { name: 'oddball', role: 'likert', construct: 'Y', anchorCount: 10 },
    ],
    {}
  );
  const hint = v.hints.find(function (h) { return h.kind === 'unusual_likert_range'; });
  check('validateTags emits unusual_likert_range hint for 10-point', !!hint);
  if (hint) {
    eq('unusual_likert_range column',      hint.column, 'oddball');
    eq('unusual_likert_range anchorCount', hint.anchorCount, 10);
  }
})();

// ════════════════════════════════════════════════════════════════════════
// Layer 3 — engine activation seam (three scenarios)
// ════════════════════════════════════════════════════════════════════════

function buildColumnRoles(spec) {
  // spec: { tagConstructs: bool, tagAnchor: bool, tagReverse?: bool, confirmDemographics?: bool }
  // Returns columnRoles for materializeDataset.
  const autoRoles = core.inferColumnRoles(parsed);
  return autoRoles.map(function (a) {
    const cr = { name: a.name, role: a.autoRole };
    if (a.autoRole === 'likert') {
      if (spec.tagConstructs) {
        cr.construct = a.name.startsWith('eng') ? 'Engagement' : 'Belonging';
      }
      if (spec.tagAnchor && a.autoAnchorCount != null) {
        cr.anchorCount = a.autoAnchorCount;
      }
      if (spec.tagReverse !== undefined) {
        cr.reverseCoded = false;  // none of our items are reverse-coded
      }
    }
    if (a.autoRole === 'demographic' && spec.confirmDemographics) {
      cr.userConfirmed = true;
    }
    return cr;
  });
}

const NORM_DIAG = 'scale_range_normalization_unavailable_using_absolute_threshold';
function interpHas(detail, needle) {
  return detail && typeof detail.interp === 'string' && detail.interp.indexOf(needle) !== -1;
}

// ── Scenario 1: untagged baseline ─────────────────────────────────────
(function () {
  const columnRoles = buildColumnRoles({
    tagConstructs: false, tagAnchor: false, confirmDemographics: true,
  });
  // Demographics are confirmed so §4D fairness pass runs at all — without
  // that the normalization-fallback diagnostic never fires (fairness is
  // simply skipped). Confirming demographics is independent of the §4
  // anchor-metadata unlock and lets the scenario isolate the §4D fallback.
  const dataset = core.materializeDataset(parsed, columnRoles, {}, 's1');
  const out = runEngine(dataset);

  const skipped = out.skipped_domains || [];
  check('S1 §4A validity skipped',           skipped.indexOf('validity') !== -1);
  check('S1 §4B construct_alignment skipped',skipped.indexOf('construct_alignment') !== -1);
  check('S1 §4E scale_structure skipped',    skipped.indexOf('scale_structure') !== -1);

  const rsr = (out.domain_details || {}).response_scale_review || {};
  const rsrSkipped = rsr.skipped_subcomponents || [];
  check('S1 §4G anchor_count sub skipped',          rsrSkipped.indexOf('anchor_count') !== -1);
  check('S1 §4G midpoint_presence sub skipped',     rsrSkipped.indexOf('midpoint_presence') !== -1);
  check('S1 §4G single_format_per_scale sub skipped', rsrSkipped.indexOf('single_format_per_scale') !== -1);

  const bc = (out.domain_details || {}).bias_clarity || {};
  check('S1 §4D normalization-fallback diagnostic present', interpHas(bc, NORM_DIAG),
        'interp=' + bc.interp);
})();

// ── Scenario 2: constructs + anchor_count, reverse not confirmed ──────
(function () {
  const columnRoles = buildColumnRoles({
    tagConstructs: true, tagAnchor: true, confirmDemographics: true,
  });
  const dataset = core.materializeDataset(parsed, columnRoles, {}, 's2');
  const out = runEngine(dataset);

  const skipped = out.skipped_domains || [];
  check('S2 §4A activates',            skipped.indexOf('validity') === -1);
  check('S2 §4B activates',            skipped.indexOf('construct_alignment') === -1);
  // §4E activates at the domain level (item-count + missingness + survey-health),
  // but sub-2 (reverse balance) still skips because reverse_coded_confirmed is
  // absent — verified below via skipped_subcomponents.
  check('S2 §4E activates at domain level',          skipped.indexOf('scale_structure') === -1);

  const rsr = (out.domain_details || {}).response_scale_review || {};
  const rsrSkipped = rsr.skipped_subcomponents || [];
  check('S2 §4G anchor_count sub activates',          rsrSkipped.indexOf('anchor_count') === -1);
  check('S2 §4G midpoint_presence sub activates',     rsrSkipped.indexOf('midpoint_presence') === -1);
  check('S2 §4G single_format_per_scale sub activates', rsrSkipped.indexOf('single_format_per_scale') === -1);

  const ss = (out.domain_details || {}).scale_structure || {};
  const ssSkipped = (ss.breakdown && Object.keys(ss.breakdown)
                      .filter(function (k) { return ss.breakdown[k].skipped; })) || [];
  check('S2 §4E sub-2 (reverse_coded_balance) still skipped',
        ssSkipped.indexOf('reverse_coded_balance') !== -1,
        'skipped subs=' + JSON.stringify(ssSkipped));

  const bc = (out.domain_details || {}).bias_clarity || {};
  check('S2 §4D normalization-fallback diagnostic cleared', !interpHas(bc, NORM_DIAG),
        'interp=' + bc.interp);
})();

// ── Scenario 3: constructs + anchor_count + reverse_coded_confirmed ───
(function () {
  const columnRoles = buildColumnRoles({
    tagConstructs: true, tagAnchor: true, tagReverse: true, confirmDemographics: true,
  });
  const dataset = core.materializeDataset(
    parsed, columnRoles, { reverse_coded_confirmed: true }, 's3'
  );
  const out = runEngine(dataset);

  const ss = (out.domain_details || {}).scale_structure || {};
  const ssSkipped = (ss.breakdown && Object.keys(ss.breakdown)
                      .filter(function (k) { return ss.breakdown[k].skipped; })) || [];
  check('S3 §4E sub-2 (reverse_coded_balance) activates',
        ssSkipped.indexOf('reverse_coded_balance') === -1,
        'skipped subs=' + JSON.stringify(ssSkipped));

  const skipped = out.skipped_domains || [];
  check('S3 §4A still active',            skipped.indexOf('validity') === -1);
  check('S3 §4B still active',            skipped.indexOf('construct_alignment') === -1);

  const bc = (out.domain_details || {}).bias_clarity || {};
  check('S3 §4D diagnostic still cleared', !interpHas(bc, NORM_DIAG));

  eq('S3 dataset.config.reverse_coded_confirmed = true',
     dataset.config.reverse_coded_confirmed, true);
})();

// ── Materialize-shape spot checks ──────────────────────────────────────
(function () {
  const columnRoles = buildColumnRoles({
    tagConstructs: true, tagAnchor: true, confirmDemographics: true,
  });
  const dataset = core.materializeDataset(parsed, columnRoles, {}, 'shape');
  const byName = {};
  dataset.variables.forEach(function (v) { byName[v.name] = v; });

  eq('shape eng1.types[0] = likert',          byName.eng1.types[0], 'likert');
  eq('shape eng1.construct = Engagement',     byName.eng1.construct, 'Engagement');
  eq('shape eng1.anchor_count = 5',           byName.eng1.anchor_count, 5);
  eq('shape gender.types[0] = categorical',   byName.gender.types[0], 'categorical');
  eq('shape comments.types[0] = open',        byName.comments.types[0], 'open');
  check('shape dataset.config.demographic_columns includes gender',
        (dataset.config.demographic_columns || []).indexOf('gender') !== -1);
})();

// ── M2 unit assertions (KNOWN_ISSUES §16 M2) ──────────────────────────
// These don't touch the engine; they lock the tag-core surface that the
// browser tag stage relies on. Three groups:
//   (a) fileContentHash: stability, byte-sensitivity, prefix guard.
//   (b) inferColumnRoles auto-fill precedence — the *callers* are
//       responsible for clearing autoBadge on user touch, but the
//       inference proposal itself must be deterministic so the badge
//       starts true only when role === autoRole.
//   (c) validateTags hint shapes the M2 UI consumes (construct_too_small,
//       multiple_criterion, no_likert_tagged blocker).
(function () {
  // (a) FNV-1a fileContentHash. M2 implementation, replaces the M1 null stub.
  eq('M2 fileContentHash null on null input',
     core.fileContentHash(null), null);

  const h1 = core.fileContentHash('a,b,c\n1,2,3\n');
  const h2 = core.fileContentHash('a,b,c\n1,2,3\n');
  const h3 = core.fileContentHash('a,b,c\n1,2,4\n');     // one byte different
  check('M2 fileContentHash stable across calls', h1 === h2, h1 + ' vs ' + h2);
  check('M2 fileContentHash differs on one-byte change', h1 !== h3,
        h1 + ' vs ' + h3);
  check('M2 fileContentHash carries fnv1a- prefix',
        typeof h1 === 'string' && h1.indexOf('fnv1a-') === 0, h1);
  // The prefix is a forward-compat guard: M5's "sha256-…" keys must
  // never collide with M2's "fnv1a-…" keys in localStorage.
  check('M2 fileContentHash format = fnv1a-<8 hex>',
        /^fnv1a-[0-9a-f]{8}$/.test(h1), h1);

  // ArrayBuffer + Uint8Array paths produce the same hash as the string path
  // for ASCII content (the browser feeds ArrayBuffer; the harness uses string).
  const enc = new TextEncoder().encode('a,b,c\n1,2,3\n');
  const hBuf = core.fileContentHash(enc.buffer);
  const hArr = core.fileContentHash(enc);
  eq('M2 fileContentHash ArrayBuffer matches string', hBuf, h1);
  eq('M2 fileContentHash Uint8Array matches string',  hArr, h1);
})();

(function () {
  // (b) Auto-fill precedence — the proposal is deterministic, and the
  // browser layer clears the badge on user touch. Lock the proposal shape:
  // every column has { name, autoRole, autoAnchorCount, sample }. The
  // sample slices the first 4 raw values so the tag stage can render a
  // preview without re-reading parsed.rows.
  const proposals = core.inferColumnRoles(parsed);
  check('M2 inferColumnRoles returns one proposal per header',
        proposals.length === parsed.headers.length);
  proposals.forEach(function (p, i) {
    eq('M2 proposal[' + i + '].name matches header',
       p.name, parsed.headers[i]);
    check('M2 proposal[' + i + '] has autoRole string',
          typeof p.autoRole === 'string' && p.autoRole.length > 0);
    check('M2 proposal[' + i + '].sample is array of ≤ 4',
          Array.isArray(p.sample) && p.sample.length <= 4);
  });
  // Re-running gives byte-identical proposals (deterministic; the UI
  // can call this on every entry to the tag stage without flicker).
  const proposals2 = core.inferColumnRoles(parsed);
  eq('M2 inferColumnRoles is deterministic (re-run identical)',
     JSON.stringify(proposals), JSON.stringify(proposals2));
})();

(function () {
  // (c) validateTags shapes the M2 UI consumes.
  // Empty / no-Likert state must surface the no_likert_tagged blocker.
  const emptyRoles = parsed.headers.map(function (h) {
    return { name: h, role: 'ignore' };
  });
  const r0 = core.validateTags(emptyRoles, {});
  check('M2 validateTags blocks when zero Likert tagged',
        r0.blockers.some(function (b) { return b.kind === 'no_likert_tagged'; }),
        JSON.stringify(r0.blockers));

  // construct_too_small fires per construct with < 3 Likert items, and
  // carries the construct name + count for inline UI rendering.
  const twoLikertSameConstruct = [
    { name: 'a', role: 'likert', construct: 'X' },
    { name: 'b', role: 'likert', construct: 'X' },
    { name: 'c', role: 'ignore' },
  ];
  const r1 = core.validateTags(twoLikertSameConstruct, {});
  const tooSmall = r1.hints.find(function (h) { return h.kind === 'construct_too_small'; });
  check('M2 validateTags emits construct_too_small hint',
        !!tooSmall, JSON.stringify(r1.hints));
  if (tooSmall) {
    eq('M2 construct_too_small.construct = X', tooSmall.construct, 'X');
    eq('M2 construct_too_small.count = 2',     tooSmall.count, 2);
  }

  // multiple_criterion carries the list of offending column names so
  // the UI can attach a hint to each row (Phase 1 Q8 + greenlight on
  // making the skip-behavior explicit in the UI copy).
  const twoCriterion = [
    { name: 'a', role: 'likert', construct: 'X' },
    { name: 'b', role: 'likert', construct: 'X' },
    { name: 'c', role: 'likert', construct: 'X' },
    { name: 'gpa1', role: 'criterion' },
    { name: 'gpa2', role: 'criterion' },
  ];
  const r2 = core.validateTags(twoCriterion, {});
  const multi = r2.hints.find(function (h) { return h.kind === 'multiple_criterion'; });
  check('M2 validateTags emits multiple_criterion hint', !!multi);
  if (multi) {
    eq('M2 multiple_criterion.count = 2', multi.count, 2);
    check('M2 multiple_criterion.columns lists both',
          (multi.columns || []).indexOf('gpa1') !== -1 &&
          (multi.columns || []).indexOf('gpa2') !== -1,
          JSON.stringify(multi.columns));
  }

  // Sanity: a healthy 3-item construct with no criterion ambiguity has
  // zero blockers and zero construct/criterion hints.
  const healthy = [
    { name: 'a', role: 'likert', construct: 'X', anchorCount: 5 },
    { name: 'b', role: 'likert', construct: 'X', anchorCount: 5 },
    { name: 'c', role: 'likert', construct: 'X', anchorCount: 5 },
  ];
  const r3 = core.validateTags(healthy, { reverse_coded_confirmed: false });
  eq('M2 healthy state has zero blockers', r3.blockers.length, 0);
  check('M2 healthy state has no construct_too_small',
        !r3.hints.some(function (h) { return h.kind === 'construct_too_small'; }));
  check('M2 healthy state has no multiple_criterion',
        !r3.hints.some(function (h) { return h.kind === 'multiple_criterion'; }));
})();

// ── Final report ──────────────────────────────────────────────────────
if (failures.length) {
  console.log('FAIL — ' + failures.length + ' assertion(s):');
  failures.forEach(function (f) { console.log('  - ' + f); });
  process.exit(1);
}
console.log('OK — rssi_tag_emit (M1 baseline + M2 unit): tag-core assertions + 3-scenario engine activation seam (' +
            assertionCount + ' assertions)');
