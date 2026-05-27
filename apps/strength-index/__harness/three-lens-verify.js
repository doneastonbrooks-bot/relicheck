#!/usr/bin/env node
/* eslint-disable no-console */
/*
 * Phase 2 verification harness (Spec §6 byte-for-byte rule applied to
 * the three-lens composite, Spec §3.2–3.3).
 *
 * Loads the canonical engine in a shimmed-window Node context, then:
 *   1. Runs the same dataset through computeLensesFromDataset twice
 *      (simulating the studio mount and the standalone surface, both
 *      of which delegate to this single function in Phase 2).
 *   2. Deep-equal-compares the two `rssi` blocks + skipped_domains.
 *   3. Hand-verifies the three lens scores against the §3.3 formula
 *      using a synthetic sub-score map (lens math closed-form check).
 *
 * Exit 0 on full match, non-zero otherwise. Stdout shows the lens
 * scores so they can be eyeballed against expectations.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ENGINE_PATH = path.resolve(__dirname, '..', 'strength-index.js');

// ---------- Minimal window shim ----------
const localStorageStore = {};
const sandbox = {
  console,
  setTimeout, clearTimeout, setInterval, clearInterval,
  document: {
    getElementById: () => null,
    querySelector: () => null,
    querySelectorAll: () => [],
    createElement: () => ({ setAttribute: () => {}, style: {} }),
  },
  window: {
    localStorage: {
      getItem: (k) => (k in localStorageStore ? localStorageStore[k] : null),
      setItem: (k, v) => { localStorageStore[k] = String(v); },
      removeItem: (k) => { delete localStorageStore[k]; },
    },
  },
};
sandbox.window.document = sandbox.document;
sandbox.window.console = console;
// strength-index.js references bare globals (window, document, console).
// The shim above sits on `window`; expose the bare references too.
sandbox.localStorage = sandbox.window.localStorage;

vm.createContext(sandbox);
const engineSrc = fs.readFileSync(ENGINE_PATH, 'utf8');
vm.runInContext(engineSrc, sandbox, { filename: 'strength-index.js' });

const RSSI_MATH = sandbox.window.RSSI_MATH;
if (!RSSI_MATH || typeof RSSI_MATH.computeLensesFromDataset !== 'function') {
  console.error('FAIL: window.RSSI_MATH.computeLensesFromDataset not exposed.');
  process.exit(1);
}

// ---------- Fixture: strong 5-item Likert scale, n=30 ----------
// Constructed so α is well above 0.80. Two items have mild noise; three
// load tightly together. No reverse coding. No open-ended. No demographics.
function buildFixture() {
  const seed = (function () {
    let s = 42;
    return function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  })();
  const N = 30;
  const items = [
    { name: 'Q1', label: 'Q1', types: ['likert'], values: [] },
    { name: 'Q2', label: 'Q2', types: ['likert'], values: [] },
    { name: 'Q3', label: 'Q3', types: ['likert'], values: [] },
    { name: 'Q4', label: 'Q4', types: ['likert'], values: [] },
    { name: 'Q5', label: 'Q5', types: ['likert'], values: [] },
  ];
  for (let i = 0; i < N; i++) {
    const trait = 1 + seed() * 4; // latent in [1,5]
    items.forEach(function (it, j) {
      const noise = (seed() - 0.5) * (j < 3 ? 0.4 : 0.9);
      const v = Math.max(1, Math.min(5, Math.round(trait + noise)));
      it.values.push(v);
    });
  }
  return {
    source: 'three-lens-verify fixture',
    rowCount: N,
    variables: items,
  };
}

// ---------- Run twice, deep-equal ----------
const dataset = buildFixture();
const runA = RSSI_MATH.computeLensesFromDataset(dataset);
const runB = RSSI_MATH.computeLensesFromDataset(dataset);

function canon(obj) { return JSON.stringify(obj); }

const aRssi = canon(runA.rssi);
const bRssi = canon(runB.rssi);
const aSkip = canon(runA.skipped_domains);
const bSkip = canon(runB.skipped_domains);
const aSubs = canon(runA.domain_subscores);
const bSubs = canon(runB.domain_subscores);

let failed = false;
function check(name, a, b) {
  if (a === b) {
    console.log('  OK   ' + name);
  } else {
    failed = true;
    console.log('  FAIL ' + name);
    console.log('    A: ' + a);
    console.log('    B: ' + b);
  }
}

console.log('--- §6 byte-for-byte verification: studio mount vs. standalone surface ---');
console.log('Both surfaces call window.RSSI_MATH.computeLensesFromDataset(dataset).');
check('rssi block identical',           aRssi, bRssi);
check('skipped_domains identical',      aSkip, bSkip);
check('domain_subscores identical',     aSubs, bSubs);

console.log('');
console.log('Three lens scores from fixture:');
console.log('  psychometric_core   = ' + runA.rssi.psychometric_core);
console.log('  respondent_centered = ' + runA.rssi.respondent_centered);
console.log('  validity_forward    = ' + runA.rssi.validity_forward);
console.log('  headline_lens       = ' + runA.rssi.headline_lens);
console.log('  skipped_domains     = ' + JSON.stringify(runA.skipped_domains));
console.log('  weights_version     = ' + runA.rssi_weights_version);
console.log('');
console.log('Domain sub-scores feeding the lenses:');
Object.keys(runA.domain_subscores).forEach(function (d) {
  console.log('  ' + d.padEnd(24, ' ') + ' ' + runA.domain_subscores[d]);
});

// ---------- Hand-verify lens math (§3.3 closed-form) ----------
console.log('');
console.log('--- §3.3 closed-form lens math check ---');
const synthetic = {
  reliability:           90,
  validity:              null,    // skipped (§4A new construction)
  construct_alignment:   null,    // skipped (§4B)
  item_prompt_quality:   75,
  bias_clarity:          null,    // skipped (§4D)
  scale_structure:       null,    // skipped (§4E)
  factor_readiness:      60,
  response_scale_review: 80,
};
const lens = RSSI_MATH.computeLenses(synthetic);

// Hand-computed expected lens scores from spec §3.3:
//   RSSI_lens = Σ (D_i × w_i) / Σ w_i   (skip+rescale per Phase 1 option 1)
// Present domains: reliability, item_prompt_quality, factor_readiness, response_scale_review.
function expected(lensName) {
  const w = RSSI_MATH.LENS_WEIGHTS[lensName];
  let num = 0, den = 0;
  ['reliability', 'item_prompt_quality', 'factor_readiness', 'response_scale_review'].forEach(function (d) {
    num += synthetic[d] * w[d];
    den += w[d];
  });
  return Math.round((num / den) * 100) / 100;
}
const expPC = expected('psychometric_core');
const expRC = expected('respondent_centered');
const expVF = expected('validity_forward');

console.log('  psychometric_core   computed=' + lens.psychometric_core + '  expected=' + expPC);
console.log('  respondent_centered computed=' + lens.respondent_centered + '  expected=' + expRC);
console.log('  validity_forward    computed=' + lens.validity_forward + '  expected=' + expVF);

function approx(a, b) { return Math.abs(a - b) < 0.005; }
check('lens psychometric_core matches §3.3',   approx(lens.psychometric_core, expPC) ? '=' : 'A:' + lens.psychometric_core + ' B:' + expPC, '=');
check('lens respondent_centered matches §3.3', approx(lens.respondent_centered, expRC) ? '=' : 'A:' + lens.respondent_centered + ' B:' + expRC, '=');
check('lens validity_forward matches §3.3',    approx(lens.validity_forward, expVF) ? '=' : 'A:' + lens.validity_forward + ' B:' + expVF, '=');
check('skipped_domains lists the 4 un-built',
  canon(lens.skipped_domains.sort()),
  canon(['bias_clarity', 'construct_alignment', 'scale_structure', 'validity'].sort()));
check('headline_lens default is respondent_centered', lens.headline_lens, 'respondent_centered');

// ---------- Spec §13 output-object shape check ----------
console.log('');
console.log('--- §10 / §13 output shape ---');
const r = runA.rssi;
check('rssi has psychometric_core',     typeof r.psychometric_core,        'number');
check('rssi has respondent_centered',   typeof r.respondent_centered,      'number');
check('rssi has validity_forward',      typeof r.validity_forward,         'number');
check('rssi has headline_lens',         r.headline_lens,                   'respondent_centered');
check('rssi has disagreement_readout',  String(r.disagreement_readout),    'null'); // Phase 3
check('rssi has validity_forward_capped', typeof r.validity_forward_capped, 'boolean');

// ====================================================================
// PHASE 3 CHECKS — disagreement readout, V-F evidence cap, ω band fix.
// ====================================================================

console.log('');
console.log('--- §3.5 disagreement readout (suppress-on-skip) ---');
// Fixture skips four un-built domains → readout must be null even if
// the lens spread exceeds 10 points (Phase 3 decision).
check('readout suppressed when domains skipped',
  String(runA.rssi.disagreement_readout), 'null');

// Full 8-domain synthetics drive each branch of the readout lookup.
function fullSubs(over) {
  const base = {
    reliability: 80, validity: 80, construct_alignment: 80, item_prompt_quality: 80,
    bias_clarity: 80, scale_structure: 80, factor_readiness: 80, response_scale_review: 80,
  };
  Object.keys(over || {}).forEach(function (k) { base[k] = over[k]; });
  return base;
}

// Pattern: PC much higher than RC. Loading reliability + validity +
// construct_alignment (heavy PC weights) high; item_prompt_quality +
// bias_clarity (heavy RC weights) low.
const pcHighSubs = fullSubs({
  reliability: 95, validity: 95, construct_alignment: 95, factor_readiness: 95,
  item_prompt_quality: 40, bias_clarity: 40, response_scale_review: 40, scale_structure: 60,
});
const pcHighLens = RSSI_MATH.computeLenses(pcHighSubs);
const pcHighReadout = RSSI_MATH.computeDisagreementReadout(pcHighLens, pcHighLens.skipped_domains);
console.log('  PC=' + pcHighLens.psychometric_core + ' RC=' + pcHighLens.respondent_centered + ' VF=' + pcHighLens.validity_forward);
check('PC-high readout matches §3.5 sentence 1',
  pcHighReadout || '(null)',
  'Your scales are statistically sound, but the items may be hard for respondents to answer clearly. Review item wording before deployment.');

// Pattern: RC much higher than PC.
const rcHighSubs = fullSubs({
  item_prompt_quality: 95, bias_clarity: 95, response_scale_review: 95, scale_structure: 95,
  reliability: 40, validity: 40, construct_alignment: 40, factor_readiness: 40,
});
const rcHighLens = RSSI_MATH.computeLenses(rcHighSubs);
const rcHighReadout = RSSI_MATH.computeDisagreementReadout(rcHighLens, rcHighLens.skipped_domains);
console.log('  PC=' + rcHighLens.psychometric_core + ' RC=' + rcHighLens.respondent_centered + ' VF=' + rcHighLens.validity_forward);
check('RC-high readout matches §3.5 sentence 2',
  rcHighReadout || '(null)',
  'Your items read well, but the underlying scales are not holding together statistically. Consider adding items or refining constructs.');

// Pattern: V-F much lower than both. The §3.6 cap drives this case in
// practice; with clean subscores the weight differentials between PC
// and V-F on validity-adjacent domains are too narrow to reproduce a
// V-F drop > 10 below BOTH other lenses from subscore tweaks alone.
// Feed synthetic lens scores directly to the lookup — that is the
// surface the cap actually exercises.
const vfLowSynth = { psychometric_core: 85, respondent_centered: 80, validity_forward: 60 };
const vfLowReadout = RSSI_MATH.computeDisagreementReadout(vfLowSynth, []);
console.log('  PC=' + vfLowSynth.psychometric_core + ' RC=' + vfLowSynth.respondent_centered + ' VF=' + vfLowSynth.validity_forward + ' (synthetic; mimics §3.6 cap effect)');
check('V-F-low readout matches §3.5 sentence 3',
  vfLowReadout || '(null)',
  'Your survey is internally consistent, but you may not yet have evidence it measures what you claim. Add criterion data or pilot against an established instrument.');

// V-F lowest but only one other lens > 10 above V-F — sentence 3
// should NOT fire (spec says "much lower than the other two").
const vfMixSynth = { psychometric_core: 65, respondent_centered: 80, validity_forward: 60 };
const vfMixReadout = RSSI_MATH.computeDisagreementReadout(vfMixSynth, []);
// Highest=RC, lowest=VF, spread=20 → fall through to RC|VF lookup,
// which has no entry → null (spec §3.5: "Add additional pairings ...").
check('V-F-low requires BOTH others > 10 above', String(vfMixReadout), 'null');

// Pattern: spread ≤ 10 → readout null.
const tightSubs = fullSubs({}); // all 80
const tightLens = RSSI_MATH.computeLenses(tightSubs);
const tightReadout = RSSI_MATH.computeDisagreementReadout(tightLens, tightLens.skipped_domains);
console.log('  PC=' + tightLens.psychometric_core + ' RC=' + tightLens.respondent_centered + ' VF=' + tightLens.validity_forward);
check('readout silent at spread ≤ 10', String(tightReadout), 'null');

// Suppression on skip even with large spread.
const skippedSubs = fullSubs({ validity: null }); // single skip
const skippedLens = RSSI_MATH.computeLenses(skippedSubs);
const skippedReadout = RSSI_MATH.computeDisagreementReadout(skippedLens, skippedLens.skipped_domains);
check('readout suppressed even on a single skip', String(skippedReadout), 'null');

console.log('');
console.log('--- §3.6 Validity-Forward evidence cap ---');
// Cap engages: validity = 92, no criterion → capped to 60.
const capOut1 = RSSI_MATH.applyValidityForwardCap(92, false);
check('cap engages when criterion absent (value)', String(capOut1.value), '60');
check('cap engages when criterion absent (flag)',  String(capOut1.capped), 'true');

// No cap: validity = 92, criterion present → unchanged.
const capOut2 = RSSI_MATH.applyValidityForwardCap(92, true);
check('cap dormant when criterion present (value)', String(capOut2.value), '92');
check('cap dormant when criterion present (flag)',  String(capOut2.capped), 'false');

// Validity already ≤ 60, no criterion → unchanged value, flag still set.
const capOut3 = RSSI_MATH.applyValidityForwardCap(45, false);
check('cap dormant on value (value)', String(capOut3.value), '45');
check('cap flag still true (limited-evidence indicator)', String(capOut3.capped), 'true');

// Null validity sub-score → no value, no flag.
const capOut4 = RSSI_MATH.applyValidityForwardCap(null, false);
check('cap dormant when validity is null (value)', String(capOut4.value), 'null');
check('cap dormant when validity is null (flag)',  String(capOut4.capped), 'false');

// End-to-end: computeLensesFromDataset wires the cap. Validity is null
// in this build (un-built), so validity_forward_capped should be false
// regardless of config — cap dormant until §4A ships.
const noCritRun = RSSI_MATH.computeLensesFromDataset(dataset, {});
check('end-to-end cap dormant when validity null (no criterion config)',
  String(noCritRun.rssi.validity_forward_capped), 'false');
const critRun = RSSI_MATH.computeLensesFromDataset(dataset, { criterion_column: 'Q5' });
check('end-to-end cap dormant when validity null (criterion configured)',
  String(critRun.rssi.validity_forward_capped), 'false');

console.log('');
console.log('--- §4.1 ω band + α-ω agreement gate fix ---');
// Verify §4.1 ω bands map correctly. Hand-checks against the table:
//   ≥0.90 → 10, 0.80–0.899 → 9, 0.70–0.799 → 7, 0.60–0.699 → 4, <0.60 → 0.
//
// The strong-Likert fixture above has α and ω both ≥ 0.90, where v1
// and §4.1 award the same points — so its post-fix lens scores are
// unchanged. To make the band shift visible, run a weak-Likert
// fixture whose ω is expected to land in 0.60–0.69 (v1 awarded 5,
// §4.1 awards 4) or <0.60 (v1 awarded 2, §4.1 awards 0).
function buildWeakFixture() {
  const seed = (function () { let s = 7; return function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; }; })();
  const N = 60;
  const items = ['Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6'].map(function (n) {
    return { name: n, label: n, types: ['likert'], values: [] };
  });
  // Latent trait contributes ~30%; rest is independent per-item noise.
  // Tuned so α and ω land near 0.65 — the §4.1 0.60–0.69 band where
  // v1 awarded 5/8 ω-pts and §4.1 awards 4/8 (band fix is visible).
  for (let i = 0; i < N; i++) {
    const trait = 1 + seed() * 4;
    items.forEach(function (it) {
      const noise = (seed() - 0.5) * 8;
      const v = Math.max(1, Math.min(5, Math.round(trait * 0.25 + 2.5 + noise * 0.45)));
      it.values.push(v);
    });
  }
  return { source: 'weak fixture', rowCount: N, variables: items };
}
const weakDataset = buildWeakFixture();
const weakRun = RSSI_MATH.computeLensesFromDataset(weakDataset);
console.log('  Strong-fixture lens scores (α & ω both ≥ 0.90 → band fix is a no-op here):');
console.log('    PC=' + runA.rssi.psychometric_core + ' RC=' + runA.rssi.respondent_centered + ' VF=' + runA.rssi.validity_forward + ' (reliability subscore=' + runA.domain_subscores.reliability + ')');
console.log('  Weak-fixture lens scores (low-ω band — exercises §4.1 floor):');
console.log('    PC=' + weakRun.rssi.psychometric_core + ' RC=' + weakRun.rssi.respondent_centered + ' VF=' + weakRun.rssi.validity_forward + ' (reliability subscore=' + weakRun.domain_subscores.reliability + ')');
console.log('  Reliability domain detail (weak fixture):');
const weakRelBd = weakRun.domain_details.reliability.breakdown;
console.log('    α=' + (weakRelBd.alpha.value == null ? '—' : weakRelBd.alpha.value.toFixed(3)) + '  α pts=' + weakRelBd.alpha.pts + '/8');
console.log('    ω=' + (weakRelBd.omega.value == null ? '—' : weakRelBd.omega.value.toFixed(3)) + '  ω pts=' + weakRelBd.omega.pts + '/10');
console.log('    agreement pts=' + weakRelBd.agreement.pts + '/3 (' + weakRelBd.agreement.text + ')');

// §4.1 band-fix assertions. ω here lands in 0.60–0.699: v1 awarded
// 5/10, §4.1 awards 4/10. α here lands < 0.60 (or whatever band the
// random data happens in): v1 awarded 2/8 in <0.60, §4.1 awards 0/8.
if (weakRelBd.omega.value != null && weakRelBd.omega.value >= 0.60 && weakRelBd.omega.value < 0.70) {
  check('ω in 0.60–0.69 band awards 4/10 (was 5/10 in v1)', String(weakRelBd.omega.pts), '4');
}
if (weakRelBd.alpha.value != null && weakRelBd.alpha.value < 0.60) {
  check('α below 0.60 awards 0/8 (was 2/8 in v1)', String(weakRelBd.alpha.pts), '0');
}

// ====================================================================
// PHASE §4E CHECKS — Scale Structure domain.
//
// Because §4E will be DORMANT in production until the platform-side
// data contract carries scale assignments, reverse_coded flags,
// reverse_coded_confirmed at the survey level, and likert_range /
// anchor_count per item (see KNOWN_ISSUES.md §4), the harness is the
// only verification layer that exercises this math today. Coverage
// must be thorough: every sub-component AND every skip path.
// ====================================================================
console.log('');
console.log('--- §4E Scale Structure ---');

// Helper: build a Likert variable with optional scale-structure metadata.
function mkLikert(name, scale, n, opts) {
  opts = opts || {};
  const v = {
    name: name, label: name, types: ['likert'],
    values: [],
  };
  if (scale != null) v.scale = scale;
  if (opts.reverse_coded != null) v.reverse_coded = opts.reverse_coded;
  if (opts.likert_range != null)  v.likert_range  = opts.likert_range;
  if (opts.anchor_count != null)  v.anchor_count  = opts.anchor_count;
  // Synthetic values: rotate 1..5 unless all-missing-for-first-m specified.
  const allMissingRows = opts.allMissingRows || 0;
  for (let i = 0; i < n; i++) {
    if (i < allMissingRows) v.values.push(null);
    else                    v.values.push(((i + name.length) % 5) + 1);
  }
  return v;
}
function mkDataset(items, n) {
  return { source: '§4E fixture', rowCount: n, variables: items };
}

// -- Skip path 1: whole-domain skip when no scale assignments --
{
  const N = 30;
  const ds = mkDataset([
    mkLikert('Q1', null, N), mkLikert('Q2', null, N),
    mkLikert('Q3', null, N), mkLikert('Q4', null, N),
  ], N);
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  const ss = out.domain_details.scale_structure;
  check('§4E no scale assignments → score null',           String(ss.score),       'null');
  check('§4E no scale assignments → skipped flag',         String(ss.skipped),     'true');
  check('§4E no scale assignments → skip_reason',          ss.skip_reason,         'no_scale_assignments');
  check('§4E no scale assignments → subscores.scale_structure null',
    String(out.domain_subscores.scale_structure), 'null');
  check('§4E no scale assignments → listed in skipped_domains',
    out.skipped_domains.indexOf('scale_structure') >= 0 ? 'in' : 'missing', 'in');
}

// -- Computation path: ideal 4-item scale, with all metadata, full credit --
{
  const N = 50;
  const items = ['A1', 'A2', 'A3', 'A4'].map(function (n, i) {
    return mkLikert(n, 'engagement', N, {
      reverse_coded: i === 0, // A1 is reverse-coded
      likert_range: [1, 5],
    });
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  console.log('  Ideal-scale fixture: ' + JSON.stringify({ raw: ss.raw, max: ss.max, score: ss.score, breakdown: ss.breakdown }));
  check('§4E ideal scale: item_count sub awards 5',    String(ss.breakdown.item_count_per_scale.pts), '5');
  check('§4E ideal scale: reverse_coded sub awards 3', String(ss.breakdown.reverse_coded_balance.pts), '3');
  check('§4E ideal scale: format uniformity awards 3', String(ss.breakdown.response_format_uniformity.pts), '3');
  check('§4E ideal scale: scale missingness awards 2', String(ss.breakdown.scale_missingness.pts), '2');
  check('§4E ideal scale: survey k=4 awards 1 (band 4/31–40)', String(ss.breakdown.survey_item_count_health.pts), '1');
  check('§4E ideal scale: no sub-components skipped', String(ss.skipped_subcomponents.length), '0');
  check('§4E ideal scale: raw 14/15, score 93', String(ss.score), '93');
}

// -- Reverse-coded path: confirmed but no items flagged → 0 for k≥4 --
{
  const N = 30;
  const items = ['B1', 'B2', 'B3', 'B4', 'B5'].map(function (n) {
    return mkLikert(n, 'commitment', N, { likert_range: [1, 7] });
    // no reverse_coded: true on any item
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E reverse-confirmed + no flagged items → sub-2 = 0 (architectural finding)',
    String(ss.breakdown.reverse_coded_balance.pts), '0');
  check('§4E reverse-confirmed + no flagged: sub-2 NOT skipped',
    String(ss.breakdown.reverse_coded_balance.skipped), 'false');
}

// -- Reverse-coded path: NOT confirmed → sub-2 skips and rescales --
{
  const N = 30;
  const items = ['C1', 'C2', 'C3', 'C4', 'C5'].map(function (n) {
    return mkLikert(n, 'satisfaction', N, { likert_range: [1, 5] });
  });
  // No reverse_coded_confirmed in config.
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), {});
  const ss = out.domain_details.scale_structure;
  check('§4E reverse-unconfirmed → sub-2 skipped',
    String(ss.breakdown.reverse_coded_balance.skipped), 'true');
  check('§4E reverse-unconfirmed → sub-2 pts null',
    String(ss.breakdown.reverse_coded_balance.pts), 'null');
  check('§4E reverse-unconfirmed → rescale base 12 (max=12)',
    String(ss.max), '12');
  // Expected: sub1=5 (k=5), sub3=3, sub4=2, sub5=2 (total=5 in 5..30) → raw=12, max=12 → score=100
  check('§4E reverse-unconfirmed: raw 12/12, score 100',
    String(ss.score), '100');
  check('§4E reverse-unconfirmed → skipped_subcomponents lists reverse_coded_balance',
    ss.skipped_subcomponents.indexOf('reverse_coded_balance') >= 0 ? 'in' : 'missing', 'in');
}

// -- Format-metadata-absent path: sub-3 skips and rescales --
{
  const N = 30;
  const items = ['D1', 'D2', 'D3', 'D4', 'D5'].map(function (n, i) {
    return mkLikert(n, 'climate', N, { reverse_coded: i === 0 });
    // no likert_range, no anchor_count
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E no format meta → sub-3 skipped',
    String(ss.breakdown.response_format_uniformity.skipped), 'true');
  check('§4E no format meta → rescale base 12 (max=12)',
    String(ss.max), '12');
  // Expected: sub1=5 (k=5), sub2=3, sub4=2, sub5=2 (total=5 in 5..30) → raw=12, max=12 → score=100
  check('§4E no format meta: raw 12/12, score 100',
    String(ss.score), '100');
}

// -- Combined skip: format absent AND reverse-confirmed absent → 9/9 base --
{
  const N = 30;
  const items = ['E1', 'E2', 'E3', 'E4', 'E5'].map(function (n) {
    return mkLikert(n, 'combo', N);
    // no likert_range, no reverse flags, no confirmation
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), {});
  const ss = out.domain_details.scale_structure;
  check('§4E combined skip: max rescaled to 9 (5+2+2)', String(ss.max), '9');
  check('§4E combined skip: both subs flagged', String(ss.skipped_subcomponents.length), '2');
  // sub1=5 (k=5) + sub4=2 + sub5=2 (total=5 in 5..30) = 9 / 9 → 100
  check('§4E combined skip: raw 9/9, score 100', String(ss.score), '100');
}

// -- Item-count band: k=3 (small but acceptable) --
{
  const N = 30;
  const items = ['F1', 'F2', 'F3'].map(function (n) {
    return mkLikert(n, 'small', N, { likert_range: [1, 5] });
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E k=3 → item_count sub awards 3', String(ss.breakdown.item_count_per_scale.pts), '3');
  // k<4 → sub-2 auto-awards 3 per spec
  check('§4E k=3 (k<4) → reverse balance auto-awards 3', String(ss.breakdown.reverse_coded_balance.pts), '3');
}

// -- Item-count band: k=1 (single-item scale) --
{
  const N = 30;
  const items = [mkLikert('G1', 'single', N, { likert_range: [1, 5] })];
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E k=1 → item_count sub awards 0', String(ss.breakdown.item_count_per_scale.pts), '0');
  // Survey-level: total Likert = 1 → sub-5 = 0 (<4)
  check('§4E k=1 total → survey item-count sub awards 0', String(ss.breakdown.survey_item_count_health.pts), '0');
}

// -- Item-count band: k>15 (over-large scale) --
{
  const N = 30;
  const items = [];
  for (let i = 0; i < 17; i++) {
    items.push(mkLikert('H' + i, 'huge', N, { likert_range: [1, 5] }));
  }
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E k=17 → item_count sub awards 0', String(ss.breakdown.item_count_per_scale.pts), '0');
  // Total Likert = 17 → sub-5 = 2 (in 5..30)
  check('§4E total=17 → survey item-count sub awards 2', String(ss.breakdown.survey_item_count_health.pts), '2');
}

// -- Item-count band: k=9 (mid-range penalty) --
{
  const N = 30;
  const items = [];
  for (let i = 0; i < 9; i++) {
    items.push(mkLikert('I' + i, 'mid', N, { likert_range: [1, 5] }));
  }
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E k=9 → item_count sub awards 2', String(ss.breakdown.item_count_per_scale.pts), '2');
}

// -- Item-count band: k=2 (Spearman-Brown territory) --
{
  const N = 30;
  const items = [mkLikert('J1', 'pair', N, { likert_range: [1, 5] }), mkLikert('J2', 'pair', N, { likert_range: [1, 5] })];
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E k=2 → item_count sub awards 1', String(ss.breakdown.item_count_per_scale.pts), '1');
  check('§4E k=2 (k<4) → reverse balance auto-awards 3', String(ss.breakdown.reverse_coded_balance.pts), '3');
}

// -- Survey-level item-count: total in 5..30 ideal --
// (covered by ideal fixture if it had 5+; the ideal-scale fixture above
// only has 4 items hitting band-2; this scenario covers the 5..30 band.)
{
  const N = 30;
  const items = [];
  for (let i = 0; i < 6; i++) {
    items.push(mkLikert('K' + i, 'hex', N, { likert_range: [1, 5] }));
  }
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E total=6 → survey item-count sub awards 2', String(ss.breakdown.survey_item_count_health.pts), '2');
}

// -- Survey-level item-count: total = 41 → 0 (>40) --
{
  const N = 30;
  const items = [];
  for (let i = 0; i < 41; i++) {
    items.push(mkLikert('L' + i, 's' + Math.floor(i / 6), N, { likert_range: [1, 5] }));
  }
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E total=41 → survey item-count sub awards 0', String(ss.breakdown.survey_item_count_health.pts), '0');
}

// -- Scale missingness bands --
{
  // 0% missing → 2 pts (already covered by ideal). Build a 1-5% rate fixture:
  // N=50, 1 respondent (=2%) has all items missing in scale "mx".
  const N = 50;
  const items = ['M1', 'M2', 'M3', 'M4'].map(function (n) {
    return mkLikert(n, 'mx', N, { likert_range: [1, 5], allMissingRows: 1 });
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E scale missingness 2% (in (0,5%]) → 1 pt', String(ss.breakdown.scale_missingness.pts), '1');
}
{
  // 10% all-missing → 0 pts.
  const N = 50;
  const items = ['N1', 'N2', 'N3', 'N4'].map(function (n) {
    return mkLikert(n, 'ny', N, { likert_range: [1, 5], allMissingRows: 5 });
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E scale missingness 10% (>5%) → 0 pt', String(ss.breakdown.scale_missingness.pts), '0');
}

// -- Mixed format within a scale → uniformity sub awards 0 --
{
  const N = 30;
  const items = [
    mkLikert('O1', 'mixed', N, { likert_range: [1, 5] }),
    mkLikert('O2', 'mixed', N, { likert_range: [1, 7] }), // different format
    mkLikert('O3', 'mixed', N, { likert_range: [1, 5] }),
    mkLikert('O4', 'mixed', N, { likert_range: [1, 5] }),
  ];
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E mixed format within scale → uniformity sub awards 0',
    String(ss.breakdown.response_format_uniformity.pts), '0');
}

// -- Rescale arithmetic check: partial credit + sub-2 skip --
// k=3 (sub1=3, sub2 auto-skipped k<4 rule N/A — but reverse not confirmed
// so sub-2 skips at the gating layer), sub-3=3, sub-4=2, sub-5=0 (total=3 < 4).
// max=12, raw = 3+3+2+0 = 8, score = round(8/12 * 100) = 67.
{
  const N = 30;
  const items = ['R1', 'R2', 'R3'].map(function (n) {
    return mkLikert(n, 'rescaler', N, { likert_range: [1, 5] });
  });
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), {});
  const ss = out.domain_details.scale_structure;
  check('§4E rescale check: max=12 (sub-2 skipped)', String(ss.max), '12');
  check('§4E rescale check: raw 8 / 12 → score 67 (NOT 53 if divided by 15)',
    String(ss.score), '67');
}

// -- Multi-scale: averaging across scales --
{
  const N = 30;
  const items = [];
  // Scale "p" with k=4 (5 pts), scale "q" with k=1 (0 pts) → mean = 2.5
  ['P1', 'P2', 'P3', 'P4'].forEach(function (n) {
    items.push(mkLikert(n, 'p', N, { likert_range: [1, 5] }));
  });
  items.push(mkLikert('Q1', 'q', N, { likert_range: [1, 5] }));
  const out = RSSI_MATH.computeLensesFromDataset(mkDataset(items, N), { reverse_coded_confirmed: true });
  const ss = out.domain_details.scale_structure;
  check('§4E multi-scale: item_count avg (5 + 0) / 2 = 2.5',
    String(ss.breakdown.item_count_per_scale.pts), '2.5');
}

// ====================================================================
// PHASE §4F CHECKS — Factor Readiness (Spec §4F: KMO + Bartlett's + det)
// ====================================================================
console.log('');
console.log('--- §4F Factor Readiness ---');

// -- Sanity-check the ported numerical primitives against a 3x3 with
//    a hand-computable determinant and Bartlett's χ². --
// R = [[1, 0.5, 0.3], [0.5, 1, 0.2], [0.3, 0.2, 1]]
// det(R) = 1·(1 - 0.04) - 0.5·(0.5 - 0.06) + 0.3·(0.1 - 0.3)
//        = 0.96 - 0.22 - 0.06 = 0.68
// With N=100, k=3:
//   χ² = -[(99) - (11/6)]·ln(0.68) = -97.16667 · -0.385662 ≈ 37.4674
//   df = 3·2/2 = 3
//   p-value at χ²=37.47, df=3 → < 0.001
{
  const R = [[1, 0.5, 0.3], [0.5, 1, 0.2], [0.3, 0.2, 1]];
  const { inv, det } = RSSI_MATH._inverseAndDet(R.map(r => r.slice()));
  check('§4F sanity: det(R) ≈ 0.68 (hand-computed)',
    Math.abs(det - 0.68) < 1e-9 ? '=' : 'got ' + det, '=');
  // Inverse sanity-check: R · R⁻¹ = I (off-diagonals zero).
  const k = R.length;
  let maxOffDiag = 0;
  for (let i = 0; i < k; i++) {
    for (let j = 0; j < k; j++) {
      let s = 0;
      for (let p = 0; p < k; p++) s += R[i][p] * inv[p][j];
      if (i !== j) maxOffDiag = Math.max(maxOffDiag, Math.abs(s));
    }
  }
  check('§4F sanity: R · R⁻¹ off-diagonals < 1e-10',
    maxOffDiag < 1e-10 ? '=' : 'maxOff=' + maxOffDiag, '=');

  // Drive the §4F adapter with this matrix at N=100.
  const fr = RSSI_MATH.factorReadinessFromR(R, 100);
  check('§4F sanity: det = 0.68 (via adapter)',
    Math.abs(fr.det - 0.68) < 1e-9 ? '=' : 'got ' + fr.det, '=');
  check('§4F sanity: bartlett χ² ≈ 37.4674',
    Math.abs(fr.bartlettChi - 37.4674) < 0.01 ? '=' : 'got ' + fr.bartlettChi, '=');
  check('§4F sanity: bartlett df = 3', String(fr.bartlettDf), '3');
  check('§4F sanity: bartlett p < .001', fr.bartlettP < 0.001 ? '=' : 'p=' + fr.bartlettP, '=');
  // Bartlett at p < .001 → 4 pts. det = 0.68 → 3 pts (≥ 1e-5).
  // KMO on this matrix is in the "middling" band (≥ 0.70 expected).
  check('§4F sanity: bartlettPts = 4 (p < .001)', String(fr.bartlettPts), '4');
  check('§4F sanity: detPts = 3 (det ≥ 1e-5)',    String(fr.detPts),       '3');
}

// -- Helper: build a correlation matrix at a target average r --
function corrAtR(k, r) {
  const M = [];
  for (let i = 0; i < k; i++) {
    M.push([]);
    for (let j = 0; j < k; j++) M[i].push(i === j ? 1 : r);
  }
  return M;
}

// -- Fixture 1: strong factor readiness (high KMO, sig Bartlett, det ok) --
{
  const R = corrAtR(5, 0.6); // strongly correlated → high KMO, low det
  const fr = RSSI_MATH.factorReadinessFromR(R, 200);
  check('§4F strong: KMO ≥ 0.80 → 8/8',  String(fr.kmoPts),      '8');
  check('§4F strong: Bartlett p < .001 → 4/4', String(fr.bartlettPts), '4');
  // det of equicorrelation r=0.6, k=5: (1-r)^(k-1) * (1 + (k-1)r) = 0.4^4 * 3.4 = 0.08704
  check('§4F strong: det = 0.08704 → 3/3 (≥ 1e-5)', String(fr.detPts), '3');
  check('§4F strong: raw 15/15 → score 100', String(fr.score), '100');
}

// -- Fixture 2: poor on KMO+Bartlett axes (near-identity correlation) --
// R ≈ I → KMO ≈ 0 / null, det ≈ 1, Bartlett p ≈ 1.
{
  const R = corrAtR(4, 0.02); // very weakly correlated
  const fr = RSSI_MATH.factorReadinessFromR(R, 200);
  // KMO will be tiny here (off-diagonal r² is small) — < 0.60.
  check('§4F orthogonal: KMO < 0.60 → 0/8', String(fr.kmoPts), '0');
  // Bartlett p will be high (sphericity holds-ish) → 0/4.
  check('§4F orthogonal: Bartlett p ≥ .05 → 0/4', String(fr.bartlettPts), '0');
  // det near 1 → well-conditioned, gets 3/3. This is the spec behavior:
  // determinant only catches multicollinearity, not orthogonality.
  check('§4F orthogonal: det near 1 → 3/3 (well-conditioned)', String(fr.detPts), '3');
  check('§4F orthogonal: raw 3/15 → score 20', String(fr.score), '20');
}

// -- Fixture 3: near-singular determinant (highly redundant items) --
{
  const R = corrAtR(5, 0.97); // extreme multicollinearity
  const fr = RSSI_MATH.factorReadinessFromR(R, 200);
  // det = (0.03)^4 * (1 + 4·0.97) = 8.1e-7 * 4.88 ≈ 3.95e-6 → 1/3
  check('§4F near-singular: detPts = 1 (det in [1e-7, 1e-5))',
    String(fr.detPts), '1');
  // KMO on equicorrelation is high → 8/8.
  check('§4F near-singular: KMO ≥ 0.80 → 8/8', String(fr.kmoPts), '8');
  check('§4F near-singular: Bartlett p < .001 → 4/4', String(fr.bartlettPts), '4');
  check('§4F near-singular: raw 13/15 → score 87', String(fr.score), '87');
}

// -- Fixture 4: deeply singular (k=5 with one column duplicating another) --
{
  // Build a true singular R: column 1 = column 0 → R has rank < k.
  const R = [
    [1.0, 1.0, 0.4, 0.4, 0.4],
    [1.0, 1.0, 0.4, 0.4, 0.4],
    [0.4, 0.4, 1.0, 0.3, 0.3],
    [0.4, 0.4, 0.3, 1.0, 0.3],
    [0.4, 0.4, 0.3, 0.3, 1.0],
  ];
  const fr = RSSI_MATH.factorReadinessFromR(R, 200);
  check('§4F singular: det = 0 / inverse null',
    fr.det === 0 ? '=' : 'det=' + fr.det, '=');
  check('§4F singular: KMO null → 0/8',          String(fr.kmoPts),      '0');
  check('§4F singular: Bartlett unavailable → 0/4', String(fr.bartlettPts), '0');
  check('§4F singular: det 0 → 0/3',             String(fr.detPts),      '0');
  check('§4F singular: score 0',                 String(fr.score),       '0');
}

// -- Fixture 5: edge bands (KMO 0.70 / 0.60 / Bartlett bands) --
// Equicorrelation with r ≈ 0.10, k=8 puts KMO in the 0.70–0.80 band.
{
  const R = corrAtR(8, 0.10);
  const fr = RSSI_MATH.factorReadinessFromR(R, 500);
  // KMO for equicorrelation with low r typically lands in the middling band
  // when k is moderate; band-specific assertion uses the actual returned val.
  check('§4F edge: KMO band assignment honored',
    fr.kmoPts === (fr.kmo >= 0.80 ? 8 : fr.kmo >= 0.70 ? 6 : fr.kmo >= 0.60 ? 3 : 0) ? '=' : 'kmo=' + fr.kmo + ' pts=' + fr.kmoPts,
    '=');
}

// -- Fixture 6: full-pipeline integration test (raw items → engine) --
// Strong 5-item Likert scale, large N → §4F should score high.
{
  function seeded(seed) {
    let s = seed;
    return function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  }
  const rnd = seeded(123);
  const N = 200;
  const items = [];
  for (let q = 0; q < 5; q++) {
    items.push({ name: 'Q' + (q + 1), label: 'Q' + (q + 1), types: ['likert'], values: [] });
  }
  for (let i = 0; i < N; i++) {
    const trait = 1 + rnd() * 4;
    items.forEach(function (it, j) {
      const noise = (rnd() - 0.5) * 0.6;
      const v = Math.max(1, Math.min(5, Math.round(trait + noise)));
      it.values.push(v);
    });
  }
  const ds = { source: '§4F integration fixture', rowCount: N, variables: items };
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  const fr = out.domain_details.factor_readiness;
  check('§4F integration: max = 15 (not 20)', String(fr.max), '15');
  check('§4F integration: breakdown.kmo present',         typeof fr.breakdown.kmo.value,         'number');
  check('§4F integration: breakdown.bartlett present',    typeof fr.breakdown.bartlett.p,        'number');
  check('§4F integration: breakdown.determinant present', typeof fr.breakdown.determinant.value, 'number');
  check('§4F integration: raw ≤ 15',
    fr.raw <= 15 ? '=' : 'raw=' + fr.raw, '=');
  check('§4F integration: score in [0, 100]',
    (fr.score >= 0 && fr.score <= 100) ? '=' : 'score=' + fr.score, '=');
  // Strong correlated scale → expect high KMO and sig Bartlett.
  check('§4F integration: KMO awards points (≥ 3)',
    fr.breakdown.kmo.pts >= 3 ? '=' : 'pts=' + fr.breakdown.kmo.pts, '=');
  check('§4F integration: Bartlett awards full 4 (p < .001 on N=200)',
    String(fr.breakdown.bartlett.pts), '4');
}

// -- Fixture 7: domain skip when too few complete rows --
{
  const items = [];
  for (let q = 0; q < 3; q++) {
    items.push({ name: 'Q' + q, label: 'Q' + q, types: ['likert'], values: [1, null] });
  }
  const ds = { source: '§4F skip fixture', rowCount: 2, variables: items };
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  const fr = out.domain_details.factor_readiness;
  check('§4F skip: insufficient rows → score null', String(fr.score), 'null');
}

// ====================================================================
// PHASE §4G CHECKS — Response Scale Review (Spec §4G).
// Two halves: Likert design (12 pts) + respondent behavior (8 pts);
// total 20 raw → ×5 rescale.
// ====================================================================
console.log('');
console.log('--- §4G Response Scale Review ---');

// Helper: build a Likert var with controlled values + optional metadata.
function mkLikertG(name, scale, values, opts) {
  opts = opts || {};
  const v = { name: name, label: name, types: ['likert'], values: values.slice() };
  if (scale != null) v.scale = scale;
  if (opts.anchor_count != null) v.anchor_count = opts.anchor_count;
  if (opts.likert_range != null) v.likert_range = opts.likert_range;
  return v;
}
function dsG(items, n) {
  return { source: '§4G fixture', rowCount: n, variables: items };
}
// Builders for synthetic value distributions
function rotateVals(n, mod, offset) {
  const out = [];
  for (let i = 0; i < n; i++) out.push(((i + (offset || 0)) % mod) + 1);
  return out;
}
function constVals(n, v) { const a = []; for (let i = 0; i < n; i++) a.push(v); return a; }

// -- Skip path: no Likert items at all --
{
  const ds = { source: 'no likert', rowCount: 10,
    variables: [{ name: 'OE', label: 'OE', types: ['open'], values: ['x','y','z','','','','','','',''] }] };
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  const rs = out.domain_details.response_scale_review;
  check('§4G no Likert → score null',          String(rs.score),       'null');
  check('§4G no Likert → skipped flag',        String(rs.skipped),     'true');
  check('§4G no Likert → skip_reason',         rs.skip_reason,         'no_likert_items');
  check('§4G no Likert → response_scale_review listed in skipped_domains',
    out.skipped_domains.indexOf('response_scale_review') >= 0 ? 'in' : 'missing', 'in');
}

// -- Raw-data-only partial-evaluation: no scale assignments, no anchor meta
//    → anchor_count / midpoint / single_format / straight_lining all skip.
//    Anchor symmetry defaults to 2 (per spec). Completion + missingness +
//    distribution all score. Max = 2+3+3+3 = 11.
{
  const N = 50;
  const items = [
    mkLikertG('Q1', null, rotateVals(N, 5, 0)),
    mkLikertG('Q2', null, rotateVals(N, 5, 1)),
    mkLikertG('Q3', null, rotateVals(N, 5, 2)),
    mkLikertG('Q4', null, rotateVals(N, 5, 3)),
  ];
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G partial-eval max rescales to 11',   String(rs.max),                                    '11');
  check('§4G anchor_count skipped',              String(rs.breakdown.anchor_count.skipped),         'true');
  check('§4G midpoint_presence skipped',         String(rs.breakdown.midpoint_presence.skipped),    'true');
  check('§4G single_format_per_scale skipped',   String(rs.breakdown.single_format_per_scale.skipped), 'true');
  check('§4G straight_lining skipped',           String(rs.breakdown.straight_lining.skipped),      'true');
  check('§4G anchor_symmetry default = 2',       String(rs.breakdown.anchor_symmetry.pts),          '2');
  check('§4G distribution full pts (no flagged)', String(rs.breakdown.response_distribution_shape.pts), '3');
  check('§4G completion full pts (100%)',        String(rs.breakdown.completion_rate.pts),          '3');
  check('§4G missingness full pts (0%)',         String(rs.breakdown.item_missingness.pts),         '3');
  check('§4G partial-eval score = 100 (raw 11/11)', String(rs.score), '100');
  check('§4G partial-eval has 4 skipped subs',   String(rs.skipped_subcomponents.length),           '4');
}

// -- Anchor-count band: 5-point scale → 3, 7-point → 3, 4-point → 2, 2-point → 0.
//    Build one scale per fixture so the spec sub-scores are unambiguous.
{
  const N = 30;
  function single(anchor, range) {
    const items = ['A1','A2','A3','A4'].map(function (n, i) {
      return mkLikertG(n, 'one', rotateVals(N, anchor, i), { anchor_count: anchor, likert_range: range });
    });
    const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
    return out.domain_details.response_scale_review;
  }
  check('§4G 5-anchor → anchor_count = 3',  String(single(5, [1, 5]).breakdown.anchor_count.pts), '3');
  check('§4G 7-anchor → anchor_count = 3',  String(single(7, [1, 7]).breakdown.anchor_count.pts), '3');
  check('§4G 4-anchor → anchor_count = 2',  String(single(4, [1, 4]).breakdown.anchor_count.pts), '2');
  check('§4G 6-anchor → anchor_count = 2',  String(single(6, [1, 6]).breakdown.anchor_count.pts), '2');
  check('§4G 3-anchor → anchor_count = 1',  String(single(3, [1, 3]).breakdown.anchor_count.pts), '1');
  check('§4G 10-anchor → anchor_count = 1', String(single(10, [1, 10]).breakdown.anchor_count.pts), '1');
  check('§4G 2-anchor → anchor_count = 0',  String(single(2, [1, 2]).breakdown.anchor_count.pts), '0');
  // Midpoint: odd anchor count → 2; even → 1.
  check('§4G 5-anchor → midpoint = 2',  String(single(5, [1, 5]).breakdown.midpoint_presence.pts), '2');
  check('§4G 4-anchor → midpoint = 1',  String(single(4, [1, 4]).breakdown.midpoint_presence.pts), '1');
  check('§4G 7-anchor → midpoint = 2',  String(single(7, [1, 7]).breakdown.midpoint_presence.pts), '2');
}

// -- Single-format-per-scale: mixed formats within one scale → 0 --
{
  const N = 30;
  const items = [
    mkLikertG('M1', 'mix', rotateVals(N, 5, 0), { likert_range: [1, 5] }),
    mkLikertG('M2', 'mix', rotateVals(N, 7, 1), { likert_range: [1, 7] }),
    mkLikertG('M3', 'mix', rotateVals(N, 5, 2), { likert_range: [1, 5] }),
    mkLikertG('M4', 'mix', rotateVals(N, 5, 3), { likert_range: [1, 5] }),
  ];
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G mixed format within scale → single_format = 0',
    String(rs.breakdown.single_format_per_scale.pts), '0');
}

// -- Response-distribution shape: items where modal anchor ≥ 60% → flagged --
{
  const N = 50;
  // Q1 is uniform (no flag). Q2 has 70% picking value 3 (flag). Q3 has 60% (flag boundary).
  const q1 = rotateVals(N, 5, 0);                     // uniform-ish
  const q2 = []; for (let i = 0; i < N; i++) q2.push(i < 35 ? 3 : ((i + 1) % 5) + 1); // 70% at 3
  const q3 = []; for (let i = 0; i < N; i++) q3.push(i < 30 ? 4 : ((i + 1) % 5) + 1); // 60% at 4
  const items = [
    mkLikertG('Q1', 'd', q1, { anchor_count: 5 }),
    mkLikertG('Q2', 'd', q2, { anchor_count: 5 }),
    mkLikertG('Q3', 'd', q3, { anchor_count: 5 }),
    mkLikertG('Q4', 'd', q1, { anchor_count: 5 }),
  ];
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G distribution: 2 items flagged (Q2 + Q3)',
    String(rs.flagged_distribution_items.length), '2');
  // 3 - 0.5 * 2 = 2
  check('§4G distribution: deduct 0.5 per flag → 2 pts',
    String(rs.breakdown.response_distribution_shape.pts), '2');
}

// -- Distribution: many flags clamp at 0 --
{
  const N = 50;
  // All 7 items are constant → all flagged.
  const items = [];
  for (let i = 0; i < 7; i++) items.push(mkLikertG('K' + i, 'k', constVals(N, 3), { anchor_count: 5 }));
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G distribution: 7 flagged items clamps to 0',
    String(rs.breakdown.response_distribution_shape.pts), '0');
}

// -- Completion rate bands: ≥95/85/70/<70 → 3/2/1/0 --
{
  const N = 100;
  function buildAtCompletion(completePct) {
    // Build a Likert col + a non-Likert "required" col so completion ≠ 100%.
    // Easiest: make some rows have a missing value on Q1.
    const missingRows = Math.round(N * (1 - completePct));
    const values = [];
    for (let i = 0; i < N; i++) values.push(i < missingRows ? null : 3);
    return RSSI_MATH.computeLensesFromDataset(dsG([
      mkLikertG('Q1', 'c', values, { anchor_count: 5 }),
      mkLikertG('Q2', 'c', rotateVals(N, 5, 0), { anchor_count: 5 }),
      mkLikertG('Q3', 'c', rotateVals(N, 5, 1), { anchor_count: 5 }),
    ], N)).domain_details.response_scale_review;
  }
  check('§4G completion 97% → 3 pts', String(buildAtCompletion(0.97).breakdown.completion_rate.pts), '3');
  check('§4G completion 88% → 2 pts', String(buildAtCompletion(0.88).breakdown.completion_rate.pts), '2');
  check('§4G completion 75% → 1 pt',  String(buildAtCompletion(0.75).breakdown.completion_rate.pts), '1');
  check('§4G completion 50% → 0 pts', String(buildAtCompletion(0.50).breakdown.completion_rate.pts), '0');
}

// -- Item missingness bands --
{
  const N = 100;
  function buildAtMiss(missPct) {
    // missPct of cells across Likert items are missing.
    // 3 items × N cells; set first floor(missPct * 3N / 3) cells of each to null.
    const perCol = Math.round(N * missPct);
    function col(off) {
      const v = [];
      for (let i = 0; i < N; i++) v.push(i < perCol ? null : (((i + off) % 5) + 1));
      return v;
    }
    return RSSI_MATH.computeLensesFromDataset(dsG([
      mkLikertG('Q1', 'm', col(0), { anchor_count: 5 }),
      mkLikertG('Q2', 'm', col(1), { anchor_count: 5 }),
      mkLikertG('Q3', 'm', col(2), { anchor_count: 5 }),
    ], N)).domain_details.response_scale_review;
  }
  check('§4G item missingness 3% → 3 pts',  String(buildAtMiss(0.03).breakdown.item_missingness.pts), '3');
  check('§4G item missingness 8% → 2 pts',  String(buildAtMiss(0.08).breakdown.item_missingness.pts), '2');
  check('§4G item missingness 15% → 1 pt',  String(buildAtMiss(0.15).breakdown.item_missingness.pts), '1');
  check('§4G item missingness 30% → 0 pts', String(buildAtMiss(0.30).breakdown.item_missingness.pts), '0');
}

// -- Straight-lining: per-scale, not full-matrix (v1 bug fix verification) --
{
  const N = 50;
  // Two scales. Scale "engagement" has straight-liners (all 5s on every row).
  // Scale "climate" has varied responses. v1 full-matrix would have computed
  // 0% straight-lining because climate items vary; v2 per-scale catches the
  // engagement straight-lining.
  const items = [
    mkLikertG('E1', 'engagement', constVals(N, 5), { anchor_count: 5 }),
    mkLikertG('E2', 'engagement', constVals(N, 5), { anchor_count: 5 }),
    mkLikertG('E3', 'engagement', constVals(N, 5), { anchor_count: 5 }),
    mkLikertG('C1', 'climate',    rotateVals(N, 5, 0), { anchor_count: 5 }),
    mkLikertG('C2', 'climate',    rotateVals(N, 5, 1), { anchor_count: 5 }),
    mkLikertG('C3', 'climate',    rotateVals(N, 5, 2), { anchor_count: 5 }),
  ];
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  // engagement scale = 100% straight-line; climate ≈ 0%; avg = 50% → > 5% → 0 pts.
  check('§4G per-scale straight-lining catches block-level pattern → 0 pts',
    String(rs.breakdown.straight_lining.pts), '0');
  // Sanity: diagnostics.straightLinerPct reflects per-scale average, not 0%.
  check('§4G straightLinerPct reflects per-scale avg (≈ 0.5)',
    String(Math.round(rs.diagnostics.straightLinerPct * 100) / 100), '0.5');
}

// -- Straight-lining: clean scale → 2 pts --
{
  const N = 50;
  const items = ['S1','S2','S3','S4'].map(function (n, i) {
    return mkLikertG(n, 's', rotateVals(N, 5, i), { anchor_count: 5 });
  });
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G no straight-lining → 2 pts', String(rs.breakdown.straight_lining.pts), '2');
}

// -- Full-metadata ideal case: all 8 sub-components score full ---
{
  const N = 60;
  const items = ['I1','I2','I3','I4'].map(function (n, i) {
    return mkLikertG(n, 'ideal', rotateVals(N, 5, i), { anchor_count: 5, likert_range: [1, 5] });
  });
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G ideal: max = 20 (no skips)',         String(rs.max),                      '20');
  check('§4G ideal: anchor_count = 3',            String(rs.breakdown.anchor_count.pts), '3');
  check('§4G ideal: midpoint = 2',                String(rs.breakdown.midpoint_presence.pts), '2');
  check('§4G ideal: anchor_symmetry default = 2', String(rs.breakdown.anchor_symmetry.pts), '2');
  check('§4G ideal: single_format = 2',           String(rs.breakdown.single_format_per_scale.pts), '2');
  check('§4G ideal: distribution = 3',            String(rs.breakdown.response_distribution_shape.pts), '3');
  check('§4G ideal: completion = 3',              String(rs.breakdown.completion_rate.pts), '3');
  check('§4G ideal: missingness = 3',             String(rs.breakdown.item_missingness.pts), '3');
  check('§4G ideal: straight-lining = 2',         String(rs.breakdown.straight_lining.pts), '2');
  check('§4G ideal: score = 100',                 String(rs.score), '100');
  check('§4G ideal: no sub-components skipped',   String(rs.skipped_subcomponents.length), '0');
}

// -- Sample size does NOT enter the score (spec §4G) --
{
  // Tiny N (5 rows) with full data → should still hit the same score
  // as the partial-eval case above (raw 11 / 11 → 100).
  const N = 5;
  const items = ['T1','T2','T3'].map(function (n, i) {
    return mkLikertG(n, null, rotateVals(N, 5, i));
  });
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G low N does NOT deduct (spec §4G: warning-only)', String(rs.score), '100');
}

// -- Scale assignments present + anchor missing → per-scale subs still skip --
{
  const N = 30;
  const items = ['U1','U2','U3','U4'].map(function (n, i) {
    return mkLikertG(n, 'u', rotateVals(N, 5, i));
    // no anchor_count, no likert_range
  });
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G scales present, no anchor meta: anchor_count skipped',
    String(rs.breakdown.anchor_count.skipped), 'true');
  check('§4G scales present, no anchor meta: midpoint skipped',
    String(rs.breakdown.midpoint_presence.skipped), 'true');
  check('§4G scales present, no anchor meta: single_format skipped',
    String(rs.breakdown.single_format_per_scale.skipped), 'true');
  // But straight-lining IS computable here because we have scales.
  check('§4G scales present, no anchor meta: straight_lining NOT skipped',
    String(rs.breakdown.straight_lining.skipped), 'false');
}

// -- No anchor labels in current data contract: symmetry always defaults to 2 --
// (Surfaced explicitly per Phase 1 Q1 decision — spec-defined default until
// platform-side anchor metadata ships per KNOWN_ISSUES.md §8.)
{
  const N = 20;
  const out = RSSI_MATH.computeLensesFromDataset(dsG([
    mkLikertG('Z1', 'z', rotateVals(N, 5, 0), { anchor_count: 5 }),
    mkLikertG('Z2', 'z', rotateVals(N, 5, 1), { anchor_count: 5 }),
  ], N));
  const rs = out.domain_details.response_scale_review;
  check('§4G symmetry never skips (spec-defined default)',
    String(rs.breakdown.anchor_symmetry.skipped), 'false');
  check('§4G symmetry default = 2 (no labels in contract)',
    String(rs.breakdown.anchor_symmetry.pts), '2');
}

// -- Diagnostics backward-compat fields preserved (downstream renderer reads these) --
{
  const N = 30;
  const items = ['B1','B2','B3'].map(function (n, i) {
    return mkLikertG(n, 'b', rotateVals(N, 5, i), { anchor_count: 5 });
  });
  const out = RSSI_MATH.computeLensesFromDataset(dsG(items, N));
  const rs = out.domain_details.response_scale_review;
  check('§4G diagnostics.completionPct present',     typeof rs.diagnostics.completionPct,     'number');
  check('§4G diagnostics.missingnessPct present',    typeof rs.diagnostics.missingnessPct,    'number');
  check('§4G diagnostics.straightLinerPct present',  typeof rs.diagnostics.straightLinerPct,  'number');
}

console.log('');
if (failed) {
  console.log('VERIFICATION FAILED — see FAIL rows above.');
  process.exit(1);
} else {
  console.log('VERIFICATION PASSED — lens output identical across surfaces; §3.3/§3.5/§3.6/§4E/§4F/§4G all check.');
}
