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

console.log('');
if (failed) {
  console.log('VERIFICATION FAILED — see FAIL rows above.');
  process.exit(1);
} else {
  console.log('VERIFICATION PASSED — lens output identical across surfaces, §3.3/§3.5/§3.6 all check.');
}
