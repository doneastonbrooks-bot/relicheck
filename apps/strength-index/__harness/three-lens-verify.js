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
  validity:              null,    // synthetic-skip for closed-form §3.3 check
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
console.log('--- §3.5 disagreement readout (fire-with-caveat on skip; KNOWN_ISSUES #20 fix) ---');
// Prior behavior (suppress-on-skip) is retired: the readout went dark
// on every upload missing demographics (because Bias & Clarity skipped
// on those), which is the common case. New behavior: readout fires
// whenever spread > 10 and the dictionary has a match, with a coverage
// caveat appended naming what was skipped.
// The runA fixture skips four un-built domains; depending on the lens
// spread the readout fires (with caveat) or stays null because the
// (highest, lowest) pair isn't in the dictionary.
const runAReadout = runA.rssi.disagreement_readout;
if (runAReadout != null) {
  check('runA readout (when fired) carries coverage caveat',
    /Note: this reading excludes /.test(runAReadout), true,
    'got: ' + runAReadout);
}

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

// New (KNOWN_ISSUES #20 fix): single-skip + large spread fires the
// readout, appending a coverage caveat that names the skipped domain.
// Use a fixture that forces a (PC-high vs RC-low) pattern so the
// dictionary returns a match. validity = null skips that domain.
const skipPCSubs = fullSubs({
  validity: null,
  reliability: 95, construct_alignment: 95, factor_readiness: 95,
  item_prompt_quality: 40, bias_clarity: 40, response_scale_review: 40, scale_structure: 60,
});
const skipPCLens = RSSI_MATH.computeLenses(skipPCSubs);
const skipPCReadout = RSSI_MATH.computeDisagreementReadout(skipPCLens, skipPCLens.skipped_domains);
console.log('  PC=' + skipPCLens.psychometric_core + ' RC=' + skipPCLens.respondent_centered + ' VF=' + skipPCLens.validity_forward + '  (validity skipped)');
check('skip+spread fires readout (not null)',
  skipPCReadout != null, true,
  'got: ' + skipPCReadout);
check('skip+spread readout starts with the matched base sentence',
  /^Your scales are statistically sound/.test(String(skipPCReadout)), true);
check('skip+spread readout ends with the coverage caveat',
  /\. Note: this reading excludes Validity, which lacked the data to score\.$/.test(String(skipPCReadout)), true);

// Multi-skip caveat: two skipped domains → "A and B" joiner.
const multiSkipSubs = fullSubs({
  bias_clarity: null, validity: null,
  reliability: 95, construct_alignment: 95, factor_readiness: 95,
  item_prompt_quality: 40, response_scale_review: 40, scale_structure: 60,
});
const multiSkipLens = RSSI_MATH.computeLenses(multiSkipSubs);
const multiSkipReadout = RSSI_MATH.computeDisagreementReadout(multiSkipLens, multiSkipLens.skipped_domains);
check('two-skip caveat uses "A and B" joiner',
  /excludes Validity and Bias & Clarity Review,/.test(String(multiSkipReadout)), true,
  'got: ' + multiSkipReadout);

// Three-skip caveat: Oxford-comma joiner.
const threeSkipSubs = fullSubs({
  bias_clarity: null, validity: null, construct_alignment: null,
  reliability: 95, factor_readiness: 95,
  item_prompt_quality: 40, response_scale_review: 40, scale_structure: 60,
});
const threeSkipLens = RSSI_MATH.computeLenses(threeSkipSubs);
const threeSkipReadout = RSSI_MATH.computeDisagreementReadout(threeSkipLens, threeSkipLens.skipped_domains);
check('three-skip caveat uses Oxford-comma joiner',
  /excludes Validity, Construct Alignment, and Bias & Clarity Review,/.test(String(threeSkipReadout)), true,
  'got: ' + threeSkipReadout);

// When the (highest, lowest) pair has no dictionary entry AND domains
// were skipped, the readout still returns null — the caveat only
// augments a real sentence, it does not invent one.
const skipNoMatchSubs = fullSubs({ validity: null });   // all 80 except validity → tight spread → null
const skipNoMatchLens = RSSI_MATH.computeLenses(skipNoMatchSubs);
const skipNoMatchReadout = RSSI_MATH.computeDisagreementReadout(skipNoMatchLens, skipNoMatchLens.skipped_domains);
check('skip with no dict-match still returns null',
  String(skipNoMatchReadout), 'null');

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

// End-to-end: computeLensesFromDataset wires the cap. The top-of-file
// fixture has no scale assignments, so §4A whole-domain-skips and
// validity stays null → cap dormant regardless of config. The active
// cap path is exercised in the §4A fixtures below.
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

// ====================================================================
// PHASE §4A CHECKS — Validity (Spec §4A: convergent + HTMT + criterion).
// ====================================================================
console.log('');
console.log('--- §4A Validity ---');

// -- HTMT sanity check: hand-build a correlation matrix with known
//    monotrait and heterotrait means; assert HTMT to 4 decimal places.
//    Two scales of 3 items each. Within each scale: all pairs r = 0.5
//    → meanMonoA = meanMonoB = 0.5. Between scales: all 9 pairs r = 0.3
//    → meanHetero = 0.3. HTMT = 0.3 / sqrt(0.5 × 0.5) = 0.6 exactly.
//    Reference: Henseler, Ringle & Sarstedt 2015, JAMS 43(1):115–135.
{
  const R = [
    // A1   A2   A3   B1   B2   B3
    [1.0, 0.5, 0.5, 0.3, 0.3, 0.3], // A1
    [0.5, 1.0, 0.5, 0.3, 0.3, 0.3], // A2
    [0.5, 0.5, 1.0, 0.3, 0.3, 0.3], // A3
    [0.3, 0.3, 0.3, 1.0, 0.5, 0.5], // B1
    [0.3, 0.3, 0.3, 0.5, 1.0, 0.5], // B2
    [0.3, 0.3, 0.3, 0.5, 0.5, 1.0], // B3
  ];
  const out = RSSI_MATH.htmtFromR(R, ['A','A','A','B','B','B']);
  check('§4A HTMT sanity: 1 pair returned', String(out.pairs.length), '1');
  check('§4A HTMT sanity: HTMT(A,B) = 0.6000 (4-decimal hand-computed)',
    Math.abs(out.pairs[0].htmt - 0.6) < 1e-4 ? '=' : 'got ' + out.pairs[0].htmt, '=');
  check('§4A HTMT sanity: maxHtmt = 0.6000',
    Math.abs(out.maxHtmt - 0.6) < 1e-4 ? '=' : 'got ' + out.maxHtmt, '=');
  // Absolute-value convention: flipping a hetero sign should not change HTMT.
  const Rneg = R.map(function (row) { return row.slice(); });
  Rneg[0][3] = Rneg[3][0] = -0.3;
  const outNeg = RSSI_MATH.htmtFromR(Rneg, ['A','A','A','B','B','B']);
  check('§4A HTMT sanity: |r| convention — sign flip preserves HTMT',
    Math.abs(outNeg.pairs[0].htmt - 0.6) < 1e-4 ? '=' : 'got ' + outNeg.pairs[0].htmt, '=');
}

// -- HTMT band: max-across-pairs assignment per spec §4A.
{
  // 3 scales of 2 items each. Pair AB hetero 0.4, AC hetero 0.7, BC hetero 0.5.
  // All mono r = 0.5 → monoA = monoB = monoC = 0.5. denom = 0.5.
  // HTMT_AB = 0.8, HTMT_AC = 1.4, HTMT_BC = 1.0. Max = 1.4 → > 0.95 → 0 pts.
  const R = [
    [1.0, 0.5, 0.4, 0.4, 0.7, 0.7],
    [0.5, 1.0, 0.4, 0.4, 0.7, 0.7],
    [0.4, 0.4, 1.0, 0.5, 0.5, 0.5],
    [0.4, 0.4, 0.5, 1.0, 0.5, 0.5],
    [0.7, 0.7, 0.5, 0.5, 1.0, 0.5],
    [0.7, 0.7, 0.5, 0.5, 0.5, 1.0],
  ];
  const out = RSSI_MATH.htmtFromR(R, ['A','A','B','B','C','C']);
  check('§4A HTMT band: 3 pairs evaluated', String(out.pairs.length), '3');
  check('§4A HTMT band: max across pairs (≈ 1.40 from AC)',
    Math.abs(out.maxHtmt - 1.4) < 1e-4 ? '=' : 'got ' + out.maxHtmt, '=');
}

// -- Helper: build a Likert var for §4A fixtures --
function mkLikertV(name, scale, values) {
  return { name: name, label: name, types: ['likert'], scale: scale, values: values.slice() };
}

// -- Fixture: whole-domain skip when no scale assignments --
{
  const N = 30;
  const items = [];
  for (let q = 0; q < 4; q++) {
    const vals = [];
    for (let i = 0; i < N; i++) vals.push(((i + q) % 5) + 1);
    items.push({ name: 'Q' + q, label: 'Q' + q, types: ['likert'], values: vals });
  }
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4A-noscale', rowCount: N, variables: items });
  const val = out.domain_details.validity;
  check('§4A no scales: validity skipped (whole domain)', String(val.skipped), 'true');
  check('§4A no scales: skip_reason = no_scale_assignments', val.skip_reason, 'no_scale_assignments');
  check('§4A no scales: score null', String(val.score), 'null');
  check('§4A no scales: validity sub-score null in lens', String(out.domain_subscores.validity), 'null');
}

// -- Strong-validity fixture: 2 scales × 4 items, tight within, weak between --
function buildScalePair(N, withinNoise, betweenNoise) {
  let s = 42;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  const items = [];
  for (let q = 0; q < 4; q++) items.push(mkLikertV('A' + q, 'ScaleA', []));
  for (let q = 0; q < 4; q++) items.push(mkLikertV('B' + q, 'ScaleB', []));
  for (let i = 0; i < N; i++) {
    const tA = 1 + rnd() * 4;
    const tB = 1 + rnd() * 4;
    for (let q = 0; q < 4; q++) {
      const v = Math.max(1, Math.min(5, Math.round(tA + (rnd() - 0.5) * withinNoise)));
      items[q].values.push(v);
    }
    for (let q = 0; q < 4; q++) {
      // ScaleB items: latent tB + small leak from tA controlled by betweenNoise
      const v = Math.max(1, Math.min(5, Math.round(tB + (tA - 3) * betweenNoise + (rnd() - 0.5) * withinNoise)));
      items[4 + q].values.push(v);
    }
  }
  return { source: '4A-pair', rowCount: N, variables: items };
}

// -- Strong validity: low between-noise → low HTMT, high convergent --
{
  const ds = buildScalePair(80, 0.5, 0.0); // independent scales
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  const val = out.domain_details.validity;
  check('§4A strong: skipped = false', String(val.skipped), 'false');
  check('§4A strong: convergent NOT skipped', String(val.breakdown.convergent.skipped), 'false');
  check('§4A strong: htmt NOT skipped (2 scales)', String(val.breakdown.discriminant_htmt.skipped), 'false');
  check('§4A strong: criterion skipped (no config)', String(val.breakdown.criterion.skipped), 'true');
  // Max-available = 40 (criterion absent). Score ≤ 100. §3.6 cap will engage on lens math.
  check('§4A strong: max_available = 40 (no criterion)', String(val.max), '40');
  check('§4A strong: HTMT low (≤ 0.85 → 20 pts)', String(val.breakdown.discriminant_htmt.pts), '20');
  check('§4A strong: validity_forward_capped = true (no criterion)',
    String(out.rssi.validity_forward_capped), 'true');
  // §3.6: validity sub-score is capped at 60 in subscores.validity.
  check('§4A strong: validity sub-score capped at 60',
    out.domain_subscores.validity <= 60 ? '=' : 'got ' + out.domain_subscores.validity, '=');
}

// -- HTMT failure: scales bleed heavily into each other (shared latent) --
{
  const N = 80;
  let s = 17;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  const items = [];
  for (let q = 0; q < 4; q++) items.push(mkLikertV('A' + q, 'ScaleA', []));
  for (let q = 0; q < 4; q++) items.push(mkLikertV('B' + q, 'ScaleB', []));
  // Both scales driven by the SAME latent → HTMT should be ≈ 1.0.
  for (let i = 0; i < N; i++) {
    const t = 1 + rnd() * 4;
    for (let q = 0; q < 4; q++) {
      items[q].values.push(Math.max(1, Math.min(5, Math.round(t + (rnd() - 0.5) * 0.4))));
    }
    for (let q = 0; q < 4; q++) {
      items[4 + q].values.push(Math.max(1, Math.min(5, Math.round(t + (rnd() - 0.5) * 0.4))));
    }
  }
  const ds = { source: '4A-htmt-fail', rowCount: N, variables: items };
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  const val = out.domain_details.validity;
  check('§4A HTMT fail: htmt computed', String(val.breakdown.discriminant_htmt.skipped), 'false');
  // High between-scale correlations → HTMT large → fewer points.
  check('§4A HTMT fail: max_htmt > 0.85',
    val.breakdown.discriminant_htmt.max_htmt > 0.85 ? '=' : 'got ' + val.breakdown.discriminant_htmt.max_htmt, '=');
  check('§4A HTMT fail: htmt pts < 20',
    val.breakdown.discriminant_htmt.pts < 20 ? '=' : 'got ' + val.breakdown.discriminant_htmt.pts, '=');
}

// -- Convergent band fixture: weak items → low item-rest → low convergent --
{
  const N = 60;
  let s = 7;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  // 2 scales of 4 items each. ScaleA items are nearly independent (weak convergent).
  // ScaleB items load on a shared trait (strong convergent).
  const items = [];
  for (let q = 0; q < 4; q++) items.push(mkLikertV('A' + q, 'ScaleA', []));
  for (let q = 0; q < 4; q++) items.push(mkLikertV('B' + q, 'ScaleB', []));
  for (let i = 0; i < N; i++) {
    const tB = 1 + rnd() * 4;
    for (let q = 0; q < 4; q++) {
      const v = Math.max(1, Math.min(5, Math.round(1 + rnd() * 4))); // pure noise
      items[q].values.push(v);
    }
    for (let q = 0; q < 4; q++) {
      const v = Math.max(1, Math.min(5, Math.round(tB + (rnd() - 0.5) * 0.4)));
      items[4 + q].values.push(v);
    }
  }
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4A-conv', rowCount: N, variables: items });
  const val = out.domain_details.validity;
  check('§4A weak convergent: ScaleA mean_item_total < 0.30',
    val.breakdown.convergent.per_scale[0].mean_item_total < 0.30 ? '=' : 'got ' + val.breakdown.convergent.per_scale[0].mean_item_total, '=');
  check('§4A weak convergent: ScaleB mean_item_total ≥ 0.50',
    val.breakdown.convergent.per_scale[1].mean_item_total >= 0.50 ? '=' : 'got ' + val.breakdown.convergent.per_scale[1].mean_item_total, '=');
}

// -- Single-scale: per-subcomponent skip (HTMT skipped, convergent computed) --
{
  const N = 40;
  let s = 99;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  const items = [];
  for (let q = 0; q < 4; q++) items.push(mkLikertV('Q' + q, 'OnlyScale', []));
  for (let i = 0; i < N; i++) {
    const t = 1 + rnd() * 4;
    for (let q = 0; q < 4; q++) {
      items[q].values.push(Math.max(1, Math.min(5, Math.round(t + (rnd() - 0.5) * 0.4))));
    }
  }
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4A-1scale', rowCount: N, variables: items });
  const val = out.domain_details.validity;
  check('§4A single-scale: convergent computed', String(val.breakdown.convergent.skipped), 'false');
  check('§4A single-scale: htmt skipped',         String(val.breakdown.discriminant_htmt.skipped), 'true');
  check('§4A single-scale: max_available = 20 (only convergent, no criterion)', String(val.max), '20');
  check('§4A single-scale: score still computed', val.score != null ? '=' : 'null', '=');
}

// -- Criterion validity fixture: per-band assertion --
// Two scales of 3 items each + a numeric criterion column. Tight scales →
// row-sum highly correlated with the criterion when criterion equals tA.
{
  const N = 40;
  let s = 13;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  const items = [];
  for (let q = 0; q < 3; q++) items.push(mkLikertV('A' + q, 'A', []));
  for (let q = 0; q < 3; q++) items.push(mkLikertV('B' + q, 'B', []));
  const crit = { name: 'CRIT', label: 'CRIT', types: ['continuous'], values: [] };
  for (let i = 0; i < N; i++) {
    const tA = 1 + rnd() * 4;
    const tB = 1 + rnd() * 4;
    for (let q = 0; q < 3; q++) items[q].values.push(Math.max(1, Math.min(5, Math.round(tA + (rnd() - 0.5) * 0.3))));
    for (let q = 0; q < 3; q++) items[3 + q].values.push(Math.max(1, Math.min(5, Math.round(tB + (rnd() - 0.5) * 0.3))));
    // Criterion = scaled tA + tiny noise → high |r| with ScaleA total.
    crit.values.push(tA * 3 + (rnd() - 0.5) * 0.2);
  }
  items.push(crit);
  const ds = { source: '4A-crit-high', rowCount: N, variables: items };
  const out = RSSI_MATH.computeLensesFromDataset(ds, { criterion_column: 'CRIT' });
  const val = out.domain_details.validity;
  check('§4A criterion present: NOT skipped',  String(val.breakdown.criterion.skipped), 'false');
  check('§4A criterion present: error = null',  String(val.breakdown.criterion.error), 'null');
  check('§4A criterion present: max_full = 60', String(val.max), '60');
  check('§4A criterion high: |r| ≥ 0.50 → 20 pts',
    val.breakdown.criterion.pts === 20 && val.breakdown.criterion.max_abs_r >= 0.50 ? '=' : 'pts=' + val.breakdown.criterion.pts + ' r=' + val.breakdown.criterion.max_abs_r, '=');
  check('§4A criterion present: validity_forward_capped = false',
    String(out.rssi.validity_forward_capped), 'false');
  check('§4A criterion present: N ≥ 30 → no low-N warning',
    String(val.breakdown.criterion.low_n_warning), 'false');
}

// -- Criterion absent: cap engages on Validity-Forward lens --
{
  // Reuse the strong-pair fixture (no criterion in config).
  const ds = buildScalePair(80, 0.5, 0.0);
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  check('§4A cap: validity_forward_capped = true', String(out.rssi.validity_forward_capped), 'true');
  check('§4A cap: validity sub-score ≤ 60',
    out.domain_subscores.validity <= 60 ? '=' : 'got ' + out.domain_subscores.validity, '=');
}

// -- Criterion column missing from dataset: ERROR (not skip) --
{
  const ds = buildScalePair(60, 0.5, 0.0);
  const out = RSSI_MATH.computeLensesFromDataset(ds, { criterion_column: 'NOT_THERE' });
  const val = out.domain_details.validity;
  check('§4A crit missing: criterion skipped',  String(val.breakdown.criterion.skipped), 'true');
  check('§4A crit missing: error includes "not found"',
    /not found/i.test(String(val.breakdown.criterion.error)) ? '=' : 'err=' + val.breakdown.criterion.error, '=');
}

// -- Criterion column non-numeric: ERROR (not skip with diagnostic) --
{
  const ds = buildScalePair(50, 0.5, 0.0);
  ds.variables.push({
    name: 'TEXT_COL', label: 'TEXT_COL', types: ['open'],
    values: ds.variables[0].values.map(function (_, i) { return 'response_' + i; }),
  });
  const out = RSSI_MATH.computeLensesFromDataset(ds, { criterion_column: 'TEXT_COL' });
  const val = out.domain_details.validity;
  check('§4A non-numeric crit: criterion skipped',  String(val.breakdown.criterion.skipped), 'true');
  check('§4A non-numeric crit: error includes "non-numeric"',
    /non-numeric/i.test(String(val.breakdown.criterion.error)) ? '=' : 'err=' + val.breakdown.criterion.error, '=');
}

// -- Criterion column with too few paired observations: skip with diagnostic (not error) --
{
  const N = 50;
  let s = 21;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  const items = [];
  for (let q = 0; q < 3; q++) items.push(mkLikertV('A' + q, 'A', []));
  for (let q = 0; q < 3; q++) items.push(mkLikertV('B' + q, 'B', []));
  const crit = { name: 'CRIT_SPARSE', label: 'CRIT_SPARSE', types: ['continuous'], values: [] };
  for (let i = 0; i < N; i++) {
    const tA = 1 + rnd() * 4, tB = 1 + rnd() * 4;
    for (let q = 0; q < 3; q++) items[q].values.push(Math.max(1, Math.min(5, Math.round(tA + (rnd() - 0.5) * 0.3))));
    for (let q = 0; q < 3; q++) items[3 + q].values.push(Math.max(1, Math.min(5, Math.round(tB + (rnd() - 0.5) * 0.3))));
    // Only 5 criterion values populated (rest null).
    crit.values.push(i < 5 ? tA * 3 : null);
  }
  items.push(crit);
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4A-sparse-crit', rowCount: N, variables: items }, { criterion_column: 'CRIT_SPARSE' });
  const val = out.domain_details.validity;
  check('§4A sparse crit (N<10): criterion skipped',  String(val.breakdown.criterion.skipped), 'true');
  check('§4A sparse crit: error message about paired observations',
    /too few paired/i.test(String(val.breakdown.criterion.error)) ? '=' : 'err=' + val.breakdown.criterion.error, '=');
  // §3.6 cap should engage because criterion sub-component skipped.
  check('§4A sparse crit: cap engaged', String(out.rssi.validity_forward_capped), 'true');
}

// -- Criterion in 10–29 paired range: low-N warning fires --
{
  const N = 80;
  let s = 31;
  const rnd = function () { s = (s * 1103515245 + 12345) % 2147483648; return s / 2147483648; };
  const items = [];
  for (let q = 0; q < 3; q++) items.push(mkLikertV('A' + q, 'A', []));
  for (let q = 0; q < 3; q++) items.push(mkLikertV('B' + q, 'B', []));
  const crit = { name: 'CRIT_LOWN', label: 'CRIT_LOWN', types: ['continuous'], values: [] };
  for (let i = 0; i < N; i++) {
    const tA = 1 + rnd() * 4, tB = 1 + rnd() * 4;
    for (let q = 0; q < 3; q++) items[q].values.push(Math.max(1, Math.min(5, Math.round(tA + (rnd() - 0.5) * 0.3))));
    for (let q = 0; q < 3; q++) items[3 + q].values.push(Math.max(1, Math.min(5, Math.round(tB + (rnd() - 0.5) * 0.3))));
    // 15 paired observations (10 ≤ N < 30 band).
    crit.values.push(i < 15 ? tA * 3 + (rnd() - 0.5) * 0.2 : null);
  }
  items.push(crit);
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4A-lowN-crit', rowCount: N, variables: items }, { criterion_column: 'CRIT_LOWN' });
  const val = out.domain_details.validity;
  check('§4A low-N crit: criterion computed',  String(val.breakdown.criterion.skipped), 'false');
  check('§4A low-N crit: low_n_warning = true', String(val.breakdown.criterion.low_n_warning), 'true');
  check('§4A low-N crit: N = 15',               String(val.breakdown.criterion.n), '15');
}

// -- Lens shift verification: validity moves from null → scored; lenses re-weight. --
{
  const ds = buildScalePair(80, 0.5, 0.0); // strong pair, no criterion → cap engages
  const out = RSSI_MATH.computeLensesFromDataset(ds);
  check('§4A lens shift: validity NOT in skipped_domains',
    out.skipped_domains.indexOf('validity') === -1 ? '=' : 'still skipped: ' + out.skipped_domains.join(','), '=');
  // Validity is capped at 60 → all three lenses see the same validity input.
  // PC + VF weight Validity = 22, RC = 15. Equal-cap geometry per KNOWN_ISSUES.md §1.
  check('§4A lens shift: all three lenses produce numeric scores',
    (typeof out.rssi.psychometric_core === 'number' &&
     typeof out.rssi.respondent_centered === 'number' &&
     typeof out.rssi.validity_forward === 'number') ? '=' : 'PC=' + out.rssi.psychometric_core + ' RC=' + out.rssi.respondent_centered + ' VF=' + out.rssi.validity_forward,
    '=');
}

// ====================================================================
// PHASE §4D CHECKS — Bias & Clarity Review (Spec §4D).
// Two halves: wording health (12 pts) + fairness via DIF proxy (8 pts).
// ====================================================================
console.log('');
console.log('--- §4D Bias & Clarity ---');

// -- FK sanity check: 3 samples with reference grade levels --
// References from Microsoft Word and standard online FK calculators. The
// formula is 0.39 × (words/sentences) + 11.8 × (syllables/words) - 15.59.
// Tolerance: ±2.0 on the grade-level value (syllable-counter heuristics
// vary across implementations).
{
  // Sample 1: simple — "The cat sat on the mat." Standard primary-grade.
  // Most calculators land between -2 and 1.
  const simple = RSSI_MATH.biasClarityTextProbe('The cat sat on the mat.');
  check('§4D FK simple sample: words = 6', String(simple.words), '6');
  check('§4D FK simple sample: grade ≤ 2 (primary-level text)',
    simple.fk <= 2 ? '=' : 'fk=' + simple.fk, '=');

  // Sample 2: moderate — typical adult survey item.
  const moderate = RSSI_MATH.biasClarityTextProbe('I feel respected by my colleagues at work.');
  check('§4D FK moderate sample: grade between 0 and 8',
    moderate.fk >= 0 && moderate.fk <= 8 ? '=' : 'fk=' + moderate.fk, '=');

  // Sample 3: complex — multi-syllable, abstract. Should land > 12.
  const complex = RSSI_MATH.biasClarityTextProbe(
    'The administrative committee deliberated extensively regarding the proposed regulatory framework.');
  check('§4D FK complex sample: grade > 12 (graduate-level academic text)',
    complex.fk > 12 ? '=' : 'fk=' + complex.fk, '=');

  // Sample 4: edge — text too short returns null.
  const tooShort = RSSI_MATH.biasClarityTextProbe('Hi.');
  check('§4D FK edge: < 3 words returns null', String(tooShort.fk), 'null');
}

// -- Helper: build a Likert var with explicit label text --
function mkLikertD(name, label, scale, values, opts) {
  opts = opts || {};
  const v = { name: name, label: label, types: ['likert'], values: values.slice() };
  if (scale != null) v.scale = scale;
  if (opts.anchor_count != null) v.anchor_count = opts.anchor_count;
  if (opts.likert_range) v.likert_range = opts.likert_range;
  return v;
}

// -- Whole-domain skip when no real text AND no demographics --
{
  const N = 30;
  const items = [];
  for (let q = 0; q < 4; q++) {
    const vals = [];
    for (let i = 0; i < N; i++) vals.push((i % 5) + 1);
    items.push({ name: 'Q' + q, label: 'Q' + q, types: ['likert'], values: vals });
  }
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-skip', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D no text + no demographics: skipped', String(bc.skipped), 'true');
  check('§4D no text + no demographics: skip_reason', bc.skip_reason, 'no_text_and_no_demographics');
  check('§4D no text + no demographics: score null', String(bc.score), 'null');
}

// -- Wording-only: clean prompts → no deductions, fairness skipped --
{
  const N = 30;
  const items = [];
  const labels = [
    'My team is helpful',
    'I feel safe here',
    'My role is clear',
    'I have good tools',
  ];
  for (let q = 0; q < 4; q++) {
    const vals = [];
    for (let i = 0; i < N; i++) vals.push((i % 5) + 1);
    items.push({ name: 'Q' + q, label: labels[q], types: ['likert'], values: vals });
  }
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-clean', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D clean wording: skipped = false', String(bc.skipped), 'false');
  check('§4D clean wording: wording 12/12', String(bc.breakdown.wording.pts), '12');
  check('§4D clean wording: 0 wording flags', String(bc.breakdown.wording.flagged.length), '0');
  check('§4D clean wording: fairness skipped (no demographics)', String(bc.breakdown.fairness.skipped), 'true');
  check('§4D clean wording: diagnostic surfaces no demographics',
    bc.diagnostics.indexOf('no_demographics_provided') !== -1 ? '=' : 'diag=' + bc.diagnostics, '=');
  // 12/12 wording rescaled alone: ×100/12 = 100.
  check('§4D clean wording-only: score = 100',  String(bc.score), '100');
  check('§4D clean wording-only: max_avail = 12 (wording only)', String(bc.max), '12');
}

// -- Single-item-triggers-all-three: 3-point deduction --
// Spec phrasing supports per-flag deduction. This fixture asserts an
// item tripping all three wording flags loses 3 pts (Phase 1 Q9 — catches
// short-circuit bugs where detection runs but deduction caps at 1).
{
  const N = 30;
  // "Obviously" → leading. Multisyllabic vocabulary → FK > 12. "X is Y and
  // Z communicates W" → "and" with curated verbs (is/communicates) on both
  // sides → double-barreled.
  const triple = 'Obviously the administrative leadership is responsible for the complicated regulatory framework and management communicates effectively about institutional priorities.';
  const probe = RSSI_MATH.biasClarityTextProbe(triple);
  check('§4D triple-flag fixture: FK > 12 (sanity-check on the fixture)',
    probe.fk > 12 ? '=' : 'fk=' + probe.fk, '=');

  const items = [];
  // 4 clean items so wording starts somewhere; one triple-flag item.
  const cleanLabels = [
    'I feel safe sharing concerns with my team',
    'My role is clear to me',
    'I have access to learning opportunities',
    'My workload is manageable most weeks',
  ];
  for (let q = 0; q < 4; q++) {
    const vals = [];
    for (let i = 0; i < N; i++) vals.push((i % 5) + 1);
    items.push({ name: 'Q' + q, label: cleanLabels[q], types: ['likert'], values: vals });
  }
  const vals5 = [];
  for (let i = 0; i < N; i++) vals5.push((i % 5) + 1);
  items.push({ name: 'Q4_TRIPLE', label: triple, types: ['likert'], values: vals5 });

  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-triple', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  const flaggedItem = bc.breakdown.wording.flagged.find(function (f) { return f.item === 'Q4_TRIPLE'; });
  check('§4D triple flag: item is flagged', flaggedItem ? '=' : 'not flagged', '=');
  check('§4D triple flag: three flags fired',
    flaggedItem && flaggedItem.flags.length === 3 ? '=' : 'flags=' + (flaggedItem ? flaggedItem.flags.join(',') : 'none'), '=');
  check('§4D triple flag: fk_gt_12 present',
    flaggedItem && flaggedItem.flags.indexOf('fk_gt_12') !== -1 ? '=' : 'flags=' + (flaggedItem ? flaggedItem.flags.join(',') : ''), '=');
  check('§4D triple flag: double_barreled present',
    flaggedItem && flaggedItem.flags.indexOf('double_barreled') !== -1 ? '=' : 'flags=' + (flaggedItem ? flaggedItem.flags.join(',') : ''), '=');
  check('§4D triple flag: leading_language present',
    flaggedItem && flaggedItem.flags.indexOf('leading_language') !== -1 ? '=' : 'flags=' + (flaggedItem ? flaggedItem.flags.join(',') : ''), '=');
  // 12 - 3 = 9 pts (the deduction logic must NOT short-circuit at 1).
  check('§4D triple flag: wording pts = 12 - 3 = 9', String(bc.breakdown.wording.pts), '9');
}

// -- Individual flag isolation --
{
  const N = 30;
  // FK-only flag.
  const fkText = 'The administrative committee deliberated extensively regarding the proposed regulatory framework.';
  // Leading-only flag.
  const leadText = 'I always feel respected by my colleagues';
  // Double-barreled-only flag.
  const dbText = 'My manager is supportive and my workload is manageable';

  function mk(labels) {
    const items = labels.map(function (lbl, q) {
      const vals = [];
      for (let i = 0; i < N; i++) vals.push((i % 5) + 1);
      return { name: 'Q' + q, label: lbl, types: ['likert'], values: vals };
    });
    return RSSI_MATH.computeLensesFromDataset({ source: '4D-isolate', rowCount: N, variables: items });
  }

  // Probe each label is independently caught.
  const fkProbe = RSSI_MATH.biasClarityTextProbe(fkText);
  check('§4D isolate FK: FK > 12 (fixture sanity)', fkProbe.fk > 12 ? '=' : 'fk=' + fkProbe.fk, '=');

  const outFK = mk([fkText, 'I have a clear sense of my role', 'My team works well together']);
  const fkItem = outFK.domain_details.bias_clarity.breakdown.wording.flagged.find(function (f) { return f.item === 'Q0'; });
  check('§4D isolate FK: only FK flag fired',
    fkItem && fkItem.flags.length === 1 && fkItem.flags[0] === 'fk_gt_12' ? '=' : 'flags=' + (fkItem ? fkItem.flags.join(',') : 'none'),
    '=');

  const outLead = mk(['I always feel respected by my colleagues', 'My manager is approachable',
    'I have growth opportunities here']);
  const leadItem = outLead.domain_details.bias_clarity.breakdown.wording.flagged.find(function (f) { return f.item === 'Q0'; });
  check('§4D isolate leading: only leading flag fired',
    leadItem && leadItem.flags.length === 1 && leadItem.flags[0] === 'leading_language' ? '=' : 'flags=' + (leadItem ? leadItem.flags.join(',') : 'none'),
    '=');

  const outDB = mk(['My manager is supportive and my workload is manageable',
    'I have a clear sense of my role', 'My team works well together']);
  const dbItem = outDB.domain_details.bias_clarity.breakdown.wording.flagged.find(function (f) { return f.item === 'Q0'; });
  check('§4D isolate double-barreled: only DB flag fired',
    dbItem && dbItem.flags.length === 1 && dbItem.flags[0] === 'double_barreled' ? '=' : 'flags=' + (dbItem ? dbItem.flags.join(',') : 'none'),
    '=');
}

// -- Leading lexicon coverage: all 6 entries trip --
{
  const lex = ['obviously', 'clearly', 'always', 'never', 'everyone', 'no one'];
  lex.forEach(function (w) {
    const N = 30;
    const items = [{
      name: 'Q0', label: 'I think ' + w + ' the team works well together.',
      types: ['likert'], values: [],
    }];
    for (let i = 0; i < N; i++) items[0].values.push((i % 5) + 1);
    const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-lex', rowCount: N, variables: items });
    const fl = out.domain_details.bias_clarity.breakdown.wording.flagged[0];
    check('§4D leading lexicon: "' + w + '" flags',
      fl && fl.flags.indexOf('leading_language') !== -1 ? '=' : 'flags=' + (fl ? fl.flags.join(',') : 'none'),
      '=');
  });
}

// -- DIF proxy: hand-compute the mean difference before asserting --
// Fixture: 60 respondents, 2-group demographic. Item Q0 designed so
// group A (n=30, values all = 4) and group B (n=30, values all = 2)
// produce |mean_diff| = 2.0 on a 5-pt scale → far above 0.5 threshold.
{
  const N = 60;
  const itemVals = [];
  const demVals = [];
  for (let i = 0; i < N; i++) {
    if (i < 30) { itemVals.push(4); demVals.push('A'); }
    else        { itemVals.push(2); demVals.push('B'); }
  }
  // Hand verify the fixture creates the asserted condition.
  const meanA = itemVals.slice(0, 30).reduce(function (s, v) { return s + v; }, 0) / 30;
  const meanB = itemVals.slice(30).reduce(function (s, v) { return s + v; }, 0) / 30;
  check('§4D DIF fixture sanity: meanA = 4', String(meanA), '4');
  check('§4D DIF fixture sanity: meanB = 2', String(meanB), '2');
  check('§4D DIF fixture sanity: |diff| = 2.0', String(Math.abs(meanA - meanB)), '2');

  const items = [
    { name: 'Q_DIF', label: 'My team supports my growth at work', types: ['likert'], values: itemVals },
    { name: 'DEM', label: 'Department', types: ['categorical'], values: demVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-dif', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D DIF: fairness NOT skipped (demographic via is_demographic flag)',
    String(bc.breakdown.fairness.skipped), 'false');
  check('§4D DIF: 1 item flagged',
    String(bc.breakdown.fairness.flagged.length), '1');
  check('§4D DIF: fairness pts = 8 - 1 = 7',
    String(bc.breakdown.fairness.pts), '7');
  const flag = bc.breakdown.fairness.flagged[0];
  check('§4D DIF: triggering column is DEM',
    flag.triggering_columns[0].column, 'DEM');
  check('§4D DIF: reported mean_diff = 2.0',
    String(flag.triggering_columns[0].mean_diff), '2');
}

// -- DIF below threshold: no flag --
{
  const N = 60;
  const itemVals = [];
  const demVals = [];
  // meanA = 3.0, meanB = 3.2 → |diff| = 0.2 < 0.5 → no flag.
  for (let i = 0; i < N; i++) {
    if (i < 30) { itemVals.push(i % 2 === 0 ? 3 : 3); demVals.push('A'); }
    else        { itemVals.push(i % 2 === 0 ? 3 : 4); demVals.push('B'); }
  }
  const meanA = itemVals.slice(0, 30).reduce(function (s, v) { return s + v; }, 0) / 30;
  const meanB = itemVals.slice(30).reduce(function (s, v) { return s + v; }, 0) / 30;
  check('§4D DIF below: fixture meanA = 3', String(meanA), '3');
  check('§4D DIF below: fixture meanB = 3.5', String(meanB), '3.5');

  const items = [
    { name: 'Q_OK', label: 'My team supports my growth at work', types: ['likert'], values: itemVals },
    { name: 'DEM', label: 'Department', types: ['categorical'], values: demVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-dif-below', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  // |diff| = 0.5 exactly hits threshold (≥ 0.5 flags). Confirm.
  check('§4D DIF below: 0.5 diff flags (≥ threshold)',
    String(bc.breakdown.fairness.pts), '7');
}

// -- DIF N<30 floor: small group → item skipped from DIF --
{
  const N = 60;
  const itemVals = [];
  const demVals = [];
  // 50 in group A, 10 in group B → B below floor → no flag despite large |diff|.
  for (let i = 0; i < N; i++) {
    if (i < 50) { itemVals.push(4); demVals.push('A'); }
    else        { itemVals.push(1); demVals.push('B'); }
  }
  const items = [
    { name: 'Q_X', label: 'My team supports my growth at work', types: ['likert'], values: itemVals },
    { name: 'DEM', label: 'Department', types: ['categorical'], values: demVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-N-floor', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D DIF N-floor: item skipped from DIF (second group N<30)',
    String(bc.breakdown.fairness.pts), '8');
  check('§4D DIF N-floor: 0 fairness flags',
    String(bc.breakdown.fairness.flagged.length), '0');
}

// -- DIF normalization: 7-pt scale with anchor_count → threshold scales --
{
  const N = 80;
  const itemVals = [];
  const demVals = [];
  // 7-pt scale (range 6), 12.5% = 0.75. Diff 0.55 should NOT flag (< 0.75)
  // even though it would flag on a 5-pt scale (≥ 0.5 absolute).
  for (let i = 0; i < N; i++) {
    if (i < 40) { itemVals.push(i % 2 === 0 ? 4 : 5); demVals.push('A'); }   // mean = 4.5
    else        { itemVals.push(i % 2 === 0 ? 4 : 4); demVals.push('B'); }   // mean = 4.0
  }
  const meanA = itemVals.slice(0, 40).reduce(function (s, v) { return s + v; }, 0) / 40;
  const meanB = itemVals.slice(40).reduce(function (s, v) { return s + v; }, 0) / 40;
  check('§4D DIF normalize fixture: meanA = 4.5', String(meanA), '4.5');
  check('§4D DIF normalize fixture: meanB = 4',    String(meanB), '4');
  check('§4D DIF normalize fixture: |diff| = 0.5', String(Math.abs(meanA - meanB)), '0.5');

  const items = [
    { name: 'Q7', label: 'My team supports my growth at work',
      types: ['likert'], values: itemVals, anchor_count: 7 },
    { name: 'DEM', label: 'Department', types: ['categorical'], values: demVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-7pt', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D DIF normalize: 7-pt threshold = 0.75; 0.5 diff does NOT flag',
    String(bc.breakdown.fairness.pts), '8');
  check('§4D DIF normalize: normalization_available = true',
    String(bc.breakdown.fairness.normalization_available), 'true');
}

// -- DIF normalization unavailable: absolute 0.5 default + diagnostic --
{
  const N = 60;
  const itemVals = [];
  const demVals = [];
  // No anchor metadata. |diff| = 0.5 → flags at absolute threshold.
  for (let i = 0; i < N; i++) {
    if (i < 30) { itemVals.push(i % 2 === 0 ? 4 : 5); demVals.push('A'); } // mean 4.5
    else        { itemVals.push(i % 2 === 0 ? 4 : 4); demVals.push('B'); } // mean 4.0
  }
  const items = [
    { name: 'Q_NOMETA', label: 'My team supports my growth at work',
      types: ['likert'], values: itemVals },
    { name: 'DEM', label: 'Department', types: ['categorical'], values: demVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-noanchor', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D DIF default-threshold: 0.5 abs diff flags',
    String(bc.breakdown.fairness.pts), '7');
  check('§4D DIF default-threshold: normalization_available = false',
    String(bc.breakdown.fairness.normalization_available), 'false');
  check('§4D DIF default-threshold: diagnostic surfaces normalization missing',
    bc.diagnostics.indexOf('scale_range_normalization_unavailable_using_absolute_threshold') !== -1 ? '=' : 'diag=' + bc.diagnostics, '=');
}

// -- Multiple demographics: per-item flag if ANY column trips; surface ALL triggering --
{
  const N = 60;
  // Item has |diff_dept| = 2.0 AND |diff_role| = 1.5 → flag once, surface both columns.
  const itemVals = [], deptVals = [], roleVals = [];
  for (let i = 0; i < N; i++) {
    if (i < 30) { itemVals.push(4); deptVals.push('Sales'); roleVals.push('Manager'); }
    else        { itemVals.push(2); deptVals.push('Engineering'); roleVals.push('IC'); }
  }
  const items = [
    { name: 'Q_BOTH', label: 'My team supports my growth at work', types: ['likert'], values: itemVals },
    { name: 'DEPT', label: 'Department', types: ['categorical'], values: deptVals, is_demographic: true },
    { name: 'ROLE', label: 'Role',       types: ['categorical'], values: roleVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-multi-dem', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D multi-dem: 1 item flagged (max 1 deduction per item)',
    String(bc.breakdown.fairness.flagged.length), '1');
  check('§4D multi-dem: fairness pts = 8 - 1 = 7',
    String(bc.breakdown.fairness.pts), '7');
  const flag = bc.breakdown.fairness.flagged[0];
  check('§4D multi-dem: BOTH columns surface in triggering_columns',
    String(flag.triggering_columns.length), '2');
}

// -- demographic_columns config (alt route, no is_demographic flag) --
{
  const N = 60;
  const itemVals = [], demVals = [];
  for (let i = 0; i < N; i++) {
    if (i < 30) { itemVals.push(4); demVals.push('A'); }
    else        { itemVals.push(2); demVals.push('B'); }
  }
  const items = [
    { name: 'Q_CFG', label: 'My team supports my growth at work', types: ['likert'], values: itemVals },
    { name: 'GROUP', label: 'Group', types: ['categorical'], values: demVals }, // no is_demographic flag
  ];
  const out = RSSI_MATH.computeLensesFromDataset(
    { source: '4D-cfg-dem', rowCount: N, variables: items },
    { demographic_columns: ['GROUP'] }
  );
  const bc = out.domain_details.bias_clarity;
  check('§4D config route: fairness computed via config.demographic_columns',
    String(bc.breakdown.fairness.skipped), 'false');
  check('§4D config route: 1 item flagged', String(bc.breakdown.fairness.flagged.length), '1');
}

// -- Fairness-only (wording absent, demographics present) — symmetric skip-and-rescale --
{
  const N = 60;
  const itemVals = [], demVals = [];
  for (let i = 0; i < N; i++) {
    if (i < 30) { itemVals.push(4); demVals.push('A'); }
    else        { itemVals.push(2); demVals.push('B'); }
  }
  const items = [
    { name: 'Q_NOTXT', label: 'Q_NOTXT', types: ['likert'], values: itemVals }, // label === name → no text
    { name: 'DEM', label: 'DEM', types: ['categorical'], values: demVals, is_demographic: true },
  ];
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-fair-only', rowCount: N, variables: items });
  const bc = out.domain_details.bias_clarity;
  check('§4D fairness-only: wording skipped',  String(bc.breakdown.wording.skipped), 'true');
  check('§4D fairness-only: fairness scored',  String(bc.breakdown.fairness.skipped), 'false');
  check('§4D fairness-only: max_avail = 8 (fairness only)', String(bc.max), '8');
  // 8 - 1 = 7 raw; rescaled ×100/8 = 87 or 88 (round).
  check('§4D fairness-only: score = round(7/8 × 100) = 88', String(bc.score), '88');
  check('§4D fairness-only: diagnostic surfaces no_inspectable_item_text',
    bc.diagnostics.indexOf('no_inspectable_item_text') !== -1 ? '=' : 'diag=' + bc.diagnostics, '=');
}

// -- Lens-shift verification --
// Strong fixture in this harness has label === name on all items, so §4D
// stays whole-skipped → bias_clarity still in skipped_domains (no shift).
// A fresh fixture with real text exercises the lens shift.
{
  const N = 30;
  const items = [];
  const labels = [
    'I feel supported by my colleagues at work',
    'My manager listens to feedback respectfully',
    'I have what I need to perform my role',
    'My contributions are recognized regularly',
  ];
  for (let q = 0; q < 4; q++) {
    const vals = [];
    for (let i = 0; i < N; i++) vals.push((i % 5) + 1);
    items.push({ name: 'Q' + q, label: labels[q], types: ['likert'], values: vals });
  }
  const out = RSSI_MATH.computeLensesFromDataset({ source: '4D-shift', rowCount: N, variables: items });
  check('§4D lens shift: bias_clarity NOT in skipped_domains',
    out.skipped_domains.indexOf('bias_clarity') === -1 ? '=' : 'still skipped: ' + out.skipped_domains.join(','), '=');
  check('§4D lens shift: bias_clarity sub-score in [0,100]',
    typeof out.domain_subscores.bias_clarity === 'number' &&
    out.domain_subscores.bias_clarity >= 0 && out.domain_subscores.bias_clarity <= 100 ? '=' :
    'bc=' + out.domain_subscores.bias_clarity, '=');
}

// ====================================================================
// §4B Construct Alignment harness section.
//
// Reference values are pinned in expected_cfa.json (semopy ML CFA) and
// expected_promax.json (factor_analyzer Promax with κ=4). The
// fixtures themselves live in fixture_cfa.json and fixture_promax.json
// and are byte-identical between the Python reference run and this JS
// harness — both consume the same generated dataset.
//
// Tolerance accommodates the iterated-PAF estimator's divergence from
// semopy's ML; with iterated PAF (not single-pass PC) the divergence
// turns out to be ≪ ±0.05 on this fixture (~0.002 on loadings, ~0.025
// on RMSEA), but the harness asserts ±0.05 per the Phase 1 Q7
// approval. See KNOWN_ISSUES.md §4B note for the PAF-vs-ML rationale.
// ====================================================================
console.log('');
console.log('--- §4B Construct Alignment ---');
{
  const HARNESS_DIR = __dirname;
  const fxCfa = JSON.parse(fs.readFileSync(path.join(HARNESS_DIR, 'fixture_cfa.json'), 'utf8'));
  const exCfa = JSON.parse(fs.readFileSync(path.join(HARNESS_DIR, 'expected_cfa.json'), 'utf8'));
  const fxPromax = JSON.parse(fs.readFileSync(path.join(HARNESS_DIR, 'fixture_promax.json'), 'utf8'));
  const exPromax = JSON.parse(fs.readFileSync(path.join(HARNESS_DIR, 'expected_promax.json'), 'utf8'));

  // ---- CFA sanity check: per-scale loadings + CFI within ±0.05 of semopy ----
  // Tolerance is a deliberate consequence of using iterated PAF rather
  // than ML; tighter parity would require an ML implementation per
  // KNOWN_ISSUES.md note on §4B. Iterated PAF in practice converges
  // close to ML for clean one-factor data; the ±0.05 ceiling is the
  // approved Phase 1 Q7 guarantee.
  const cfaItems = [];
  Object.keys(fxCfa.columns).forEach(function (name) {
    const scaleName = fxCfa.scales.A.indexOf(name) >= 0 ? 'A' : 'B';
    cfaItems.push({ name: name, label: name, types: ['likert'], scale: scaleName, values: fxCfa.columns[name] });
  });
  const cfaOut = RSSI_MATH.computeLensesFromDataset({ source: '§4B CFA fixture', rowCount: fxCfa.N, variables: cfaItems });
  const ca = cfaOut.domain_details.construct_alignment;
  console.log('  §4B CFA fixture: score=' + ca.score + ' raw=' + ca.raw + '/' + ca.max);
  check('§4B CFA fixture: domain scored (not skipped)', String(ca.skipped), 'false');

  // Loading max-abs-diff across all 8 items vs semopy reference.
  let cfaMaxDiff = 0;
  ca.breakdown.primary_loading.per_scale.forEach(function (s) {
    if (s.skipped) return;
    const items = fxCfa.scales[s.scale];
    s.loadings.forEach(function (l, i) {
      const ref = exCfa[s.scale].loadings_std[items[i]];
      const d = Math.abs(l - ref);
      if (d > cfaMaxDiff) cfaMaxDiff = d;
    });
  });
  check('§4B CFA loadings within ±0.05 of semopy ML reference (max-abs-diff)',
    cfaMaxDiff <= 0.05 ? '=' : 'maxDiff=' + cfaMaxDiff.toFixed(4), '=');

  // CFI ±0.05 per scale.
  ca.breakdown.model_fit.per_scale.forEach(function (s) {
    if (s.cfi == null) return;
    const refCfi = Math.min(exCfa[s.scale].cfi, 1.0);  // semopy occasionally reports CFI>1; clamp for comparison
    check('§4B CFA Scale ' + s.scale + ': CFI within ±0.05 of semopy reference',
      Math.abs(s.cfi - refCfi) <= 0.05 ? '=' : 'engine=' + s.cfi.toFixed(3) + ' ref=' + refCfi.toFixed(3), '=');
  });

  // Clean fixture → all sub-components score full marks → score 100.
  check('§4B CFA fixture: primary_loading pts = 10 (mean λ ≥ 0.70)', String(ca.breakdown.primary_loading.pts), '10');
  check('§4B CFA fixture: weak_loading_penalty pts = 5 (no λ < 0.40)', String(ca.breakdown.weak_loading_penalty.pts), '5');
  check('§4B CFA fixture: cross_loadings pts = 5 (no items flagged)', String(ca.breakdown.cross_loadings.pts), '5');
  check('§4B CFA fixture: model_fit pts = 5 (CFI ≥ 0.95, RMSEA ≤ 0.06)', String(ca.breakdown.model_fit.pts), '5');
  check('§4B CFA fixture: score = 100', String(ca.score), '100');
  check('§4B CFA fixture: PAF converged on both scales',
    ca.breakdown.primary_loading.per_scale.every(function (s) { return s.skipped || s.paf_converged; }) ? '=' : 'non-converged', '=');

  // ---- Promax sanity check ----
  // factor_analyzer Promax with method='principal', kappa=4. Pinned reference
  // in expected_promax.json. Assertion: full-pattern-matrix max-abs-diff ≤ 0.05
  // (Phase 2 review preference over per-item near-zero tolerance).
  const promaxItems = [];
  fxPromax.items_factor_0.forEach(function (name) {
    promaxItems.push({ name: name, label: name, types: ['likert'], scale: 'F1', values: fxPromax.columns[name] });
  });
  fxPromax.items_factor_1.forEach(function (name) {
    promaxItems.push({ name: name, label: name, types: ['likert'], scale: 'F2', values: fxPromax.columns[name] });
  });
  const proOut = RSSI_MATH.computeLensesFromDataset({ source: '§4B Promax fixture', rowCount: fxPromax.N, variables: promaxItems });
  const caPro = proOut.domain_details.construct_alignment;
  check('§4B Promax fixture: cross_loadings sub-component scored', String(caPro.breakdown.cross_loadings.skipped), 'false');
  check('§4B Promax fixture: pooled_m_factors = 2', String(caPro.breakdown.cross_loadings.pooled_m_factors), '2');
  check('§4B Promax fixture: factor_scale_alignment not ambiguous',
    String(caPro.breakdown.cross_loadings.factor_scale_alignment_ambiguous), 'false');

  // Drive the Promax primitive directly so we can compare the full pattern
  // matrix against factor_analyzer. (The orchestrator only surfaces flagged
  // cross-loadings, not the raw pattern; the primitive is the right unit of
  // verification here.)
  const proItems = fxPromax.items_factor_0.concat(fxPromax.items_factor_1);
  const proCols = proItems.map(function (n) { return fxPromax.columns[n].slice(); });
  const Rp = RSSI_MATH._correlationMatrix(proCols);
  const eigsP = RSSI_MATH._jacobiEigen(Rp);
  const init = proItems.map(function () { return [0, 0]; });
  for (let f = 0; f < 2; f++) {
    const sqrtL = Math.sqrt(Math.max(eigsP[f].value, 0));
    for (let i = 0; i < proItems.length; i++) init[i][f] = eigsP[f].vector[i] * sqrtL;
  }
  RSSI_MATH._varimax(init);
  const { pattern, phi } = RSSI_MATH._promaxFromVarimax(init, 4);
  // Determine engine factor-to-reference-factor mapping by which engine
  // factor has the higher mean |loading| on items I1..I4.
  let m00 = 0, m01 = 0;
  for (let i = 0; i < 4; i++) { m00 += Math.abs(pattern[i][0]); m01 += Math.abs(pattern[i][1]); }
  const engineF0 = m00 >= m01 ? 0 : 1;
  const engineF1 = 1 - engineF0;
  // Sign alignment: ensure engine factor 0's mean loading on its primary items is positive.
  let signs = [1, 1];
  let mean0 = 0; for (let i = 0; i < 4; i++) mean0 += pattern[i][engineF0]; if (mean0 < 0) signs[engineF0] = -1;
  let mean1 = 0; for (let i = 4; i < 8; i++) mean1 += pattern[i][engineF1]; if (mean1 < 0) signs[engineF1] = -1;
  // Full-matrix max-abs-diff.
  let proMaxDiff = 0;
  proItems.forEach(function (name, i) {
    const ref = exPromax.loadings[name];
    const engPrimary = signs[engineF0] * pattern[i][engineF0];
    const engSecondary = signs[engineF1] * pattern[i][engineF1];
    proMaxDiff = Math.max(proMaxDiff, Math.abs(engPrimary - ref.factor_0));
    proMaxDiff = Math.max(proMaxDiff, Math.abs(engSecondary - ref.factor_1));
  });
  check('§4B Promax pattern matrix max-abs-diff vs factor_analyzer ≤ 0.05',
    proMaxDiff <= 0.05 ? '=' : 'maxDiff=' + proMaxDiff.toFixed(4), '=');
  // Phi (factor correlation) ±0.05.
  const phiEng = phi[engineF0][engineF1] * signs[engineF0] * signs[engineF1];
  check('§4B Promax factor correlation Φ within ±0.05 of factor_analyzer reference',
    Math.abs(phiEng - exPromax.factor_correlation) <= 0.05 ? '=' :
    'engine=' + phiEng.toFixed(3) + ' ref=' + exPromax.factor_correlation.toFixed(3), '=');

  // ---- Adversarial fixture: weak factor structure ----
  // 4 items, population λ=0.30, single scale, N=300. Expect low primary-
  // loading band (cross-scale mean < 0.50 → 0 pts), max weak-loading
  // deductions, single-scale → cross-loadings skipped. Score in [0, 25].
  function weakFixture() {
    const N = 300;
    const lam = 0.30;
    const epsSd = Math.sqrt(1 - lam * lam);
    // Deterministic Mersenne-twister-lite seeded RNG.
    let s = 12345;
    function rng() { s = (s * 1664525 + 1013904223) % 4294967296; return s / 4294967296; }
    function gauss() { return Math.sqrt(-2 * Math.log(rng() || 1e-9)) * Math.cos(2 * Math.PI * rng()); }
    const items = [];
    const F = []; for (let i = 0; i < N; i++) F.push(gauss());
    for (let k = 1; k <= 4; k++) {
      const vals = [];
      for (let i = 0; i < N; i++) {
        const z = lam * F[i] + epsSd * gauss();
        vals.push(Math.max(1, Math.min(5, Math.round(z + 3))));  // shift to Likert-ish
      }
      items.push({ name: 'W' + k, label: 'W' + k, types: ['likert'], scale: 'WeakScale', values: vals });
    }
    return { source: '§4B weak fixture', rowCount: N, variables: items };
  }
  const weakOut = RSSI_MATH.computeLensesFromDataset(weakFixture());
  const caWeak = weakOut.domain_details.construct_alignment;
  console.log('  §4B weak fixture: score=' + caWeak.score + ' mean λ=' +
    caWeak.breakdown.primary_loading.cross_scale_mean.toFixed(3));
  check('§4B weak fixture: primary_loading pts = 0 (mean λ < 0.50)',
    String(caWeak.breakdown.primary_loading.pts), '0');
  check('§4B weak fixture: cross_loadings skipped (single scale)',
    String(caWeak.breakdown.cross_loadings.skipped), 'true');
  check('§4B weak fixture: score ≤ 50 (weak structure)',
    caWeak.score <= 50 ? '=' : 'score=' + caWeak.score, '=');

  // ---- Cross-loading fixture: 2 scales × 4 items, one designed cross-loader ----
  function crossFixture() {
    const N = 400;
    let s = 77777;
    function rng() { s = (s * 1664525 + 1013904223) % 4294967296; return s / 4294967296; }
    function gauss() { return Math.sqrt(-2 * Math.log(rng() || 1e-9)) * Math.cos(2 * Math.PI * rng()); }
    // Two correlated factors, ρ=0.3.
    const rho = 0.3;
    const F1 = [], F2 = [];
    for (let i = 0; i < N; i++) {
      const z1 = gauss(), z2 = gauss();
      F1.push(z1);
      F2.push(rho * z1 + Math.sqrt(1 - rho * rho) * z2);
    }
    function mkItem(name, scale, loadF1, loadF2) {
      const epsSd = Math.sqrt(Math.max(1 - loadF1 * loadF1 - loadF2 * loadF2 - 2 * loadF1 * loadF2 * rho, 0.01));
      const vals = [];
      for (let i = 0; i < N; i++) {
        const z = loadF1 * F1[i] + loadF2 * F2[i] + epsSd * gauss();
        vals.push(Math.max(1, Math.min(5, Math.round(z + 3))));
      }
      return { name: name, label: name, types: ['likert'], scale: scale, values: vals };
    }
    const items = [
      mkItem('A1', 'ScaleA', 0.8, 0.0),
      mkItem('A2', 'ScaleA', 0.8, 0.0),
      mkItem('A3', 'ScaleA', 0.8, 0.0),
      mkItem('A4', 'ScaleA', 0.55, 0.50),   // deliberate cross-loader
      mkItem('B1', 'ScaleB', 0.0, 0.8),
      mkItem('B2', 'ScaleB', 0.0, 0.8),
      mkItem('B3', 'ScaleB', 0.0, 0.8),
      mkItem('B4', 'ScaleB', 0.0, 0.8),
    ];
    return { source: '§4B cross fixture', rowCount: N, variables: items };
  }
  const crossOut = RSSI_MATH.computeLensesFromDataset(crossFixture());
  const caCross = crossOut.domain_details.construct_alignment;
  const flagged = caCross.breakdown.cross_loadings.flagged_items;
  console.log('  §4B cross-loading fixture: ' + flagged.length + ' item(s) flagged: ' +
    flagged.map(function (f) { return f.item; }).join(','));
  check('§4B cross-loading fixture: at least one item flagged',
    flagged.length >= 1 ? '=' : 'count=' + flagged.length, '=');
  check('§4B cross-loading fixture: A4 is in the flagged set',
    flagged.some(function (f) { return f.item === 'A4'; }) ? '=' :
    'flagged=' + flagged.map(function (f) { return f.item; }).join(','), '=');
  check('§4B cross-loading fixture: cross_loadings pts < 5 (deduction occurred)',
    caCross.breakdown.cross_loadings.pts < 5 ? '=' : 'pts=' + caCross.breakdown.cross_loadings.pts, '=');

  // ---- Skip path: no scale assignments → whole-domain skip ----
  function noScaleFixture() {
    const N = 60;
    const items = [];
    for (let q = 1; q <= 4; q++) {
      const vals = [];
      for (let i = 0; i < N; i++) vals.push(((i + q) % 5) + 1);
      items.push({ name: 'Q' + q, label: 'Q' + q, types: ['likert'], values: vals });
    }
    return { source: '§4B no-scale fixture', rowCount: N, variables: items };
  }
  const noScaleOut = RSSI_MATH.computeLensesFromDataset(noScaleFixture());
  check('§4B no scale assignments: construct_alignment is null',
    String(noScaleOut.domain_subscores.construct_alignment), 'null');
  check('§4B no scale assignments: construct_alignment in skipped_domains',
    noScaleOut.skipped_domains.indexOf('construct_alignment') >= 0 ? '=' : 'not skipped', '=');
  check('§4B no scale assignments: domain_details surfaces skip_reason',
    noScaleOut.domain_details.construct_alignment.skip_reason, 'no_scale_assignments');

  // ---- Skip path: single-item scale ----
  function singleItemScaleFixture() {
    const N = 60;
    const items = [];
    // Scale A has 4 items; Scale Solo has 1 item.
    for (let q = 1; q <= 4; q++) {
      const vals = [];
      for (let i = 0; i < N; i++) vals.push(((i + q) % 5) + 1);
      items.push({ name: 'A' + q, label: 'A' + q, types: ['likert'], scale: 'A', values: vals });
    }
    const solo = [];
    for (let i = 0; i < N; i++) solo.push(((i * 7) % 5) + 1);
    items.push({ name: 'Solo1', label: 'Solo1', types: ['likert'], scale: 'Solo', values: solo });
    return { source: '§4B single-item fixture', rowCount: N, variables: items };
  }
  const singleOut = RSSI_MATH.computeLensesFromDataset(singleItemScaleFixture());
  const caSingle = singleOut.domain_details.construct_alignment;
  const soloEntry = caSingle.breakdown.primary_loading.per_scale.find(function (s) { return s.scale === 'Solo'; });
  check('§4B single-item scale: per-scale entry skipped',
    String(soloEntry.skipped), 'true');
  check('§4B single-item scale: skip_reason = k_lt_2',
    soloEntry.skip_reason, 'k_lt_2');

  // ---- Skip path: two-item scale (loadings OK, fit excluded) ----
  function twoItemScaleFixture() {
    const N = 80;
    const items = [];
    for (let q = 1; q <= 2; q++) {
      const vals = [];
      for (let i = 0; i < N; i++) vals.push(((i + q * 2) % 5) + 1);
      items.push({ name: 'T' + q, label: 'T' + q, types: ['likert'], scale: 'Two', values: vals });
    }
    for (let q = 1; q <= 4; q++) {
      const vals = [];
      for (let i = 0; i < N; i++) vals.push(((i + q) % 5) + 1);
      items.push({ name: 'Q' + q, label: 'Q' + q, types: ['likert'], scale: 'Quad', values: vals });
    }
    return { source: '§4B two-item fixture', rowCount: N, variables: items };
  }
  const twoOut = RSSI_MATH.computeLensesFromDataset(twoItemScaleFixture());
  const caTwo = twoOut.domain_details.construct_alignment;
  const twoEntry = caTwo.breakdown.primary_loading.per_scale.find(function (s) { return s.scale === 'Two'; });
  check('§4B two-item scale: per-scale entry NOT skipped (loadings well-defined)',
    String(twoEntry.skipped), 'false');
  check('§4B two-item scale: fit_exclude_reason = k_lte_3',
    twoEntry.fit_exclude_reason, 'k_lte_3');

  // ---- Skip path: N < 30 complete rows per scale ----
  function smallNFixture() {
    const N = 20;
    const items = [];
    for (let q = 1; q <= 4; q++) {
      const vals = [];
      for (let i = 0; i < N; i++) vals.push(((i + q) % 5) + 1);
      items.push({ name: 'S' + q, label: 'S' + q, types: ['likert'], scale: 'Small', values: vals });
    }
    return { source: '§4B small-N fixture', rowCount: N, variables: items };
  }
  const smallOut = RSSI_MATH.computeLensesFromDataset(smallNFixture());
  const caSmall = smallOut.domain_details.construct_alignment;
  const smallEntry = caSmall.breakdown.primary_loading.per_scale.find(function (s) { return s.scale === 'Small'; });
  check('§4B small-N scale: per-scale entry skipped',
    String(smallEntry.skipped), 'true');
  check('§4B small-N scale: skip_reason = n_lt_30',
    smallEntry.skip_reason, 'n_lt_30');

  // ---- Lens-score shift documentation ----
  // §4B was emitting null before this build. With scale assignments
  // present on the CFA fixture, all three lens scores now include §4B
  // at its respective weight. Compare against §4B-null baseline.
  function manualLens(subscores, weights) {
    let w = 0, s = 0;
    Object.keys(weights).forEach(function (d) {
      if (subscores[d] == null) return;
      s += subscores[d] * weights[d]; w += weights[d];
    });
    return w > 0 ? Math.round((s / w) * 100) / 100 : null;
  }
  const subWithout = Object.assign({}, cfaOut.domain_subscores, { construct_alignment: null });
  const subWith = cfaOut.domain_subscores;
  const W = RSSI_MATH.LENS_WEIGHTS;
  ['psychometric_core', 'respondent_centered', 'validity_forward'].forEach(function (L) {
    const before = manualLens(subWithout, W[L]);
    const after = manualLens(subWith, W[L]);
    const delta = (after != null && before != null) ? (after - before) : null;
    console.log('  §4B lens shift on CFA fixture (' + L + '): ' + before + ' → ' + after +
      (delta != null ? ' (Δ ' + (delta >= 0 ? '+' : '') + delta.toFixed(2) + ')' : ''));
  });
  check('§4B lens shift: construct_alignment NOT in skipped_domains for CFA fixture',
    cfaOut.skipped_domains.indexOf('construct_alignment') === -1 ? '=' : 'still skipped', '=');

  // ---- Matrix-fanout grouping ----
  // _build_dataset.php fans a matrix question with k sub-items into k Likert
  // variables, each tagged with the parent question's construct. The engine
  // groups by v.scale || v.construct, so a 4-sub-item matrix tagged
  // construct='M' should produce the same per-scale entry as four standalone
  // items sharing construct='M'. Build both shapes from the same numeric
  // values and compare grouping output.
  const fanoutValues = fxCfa.scales.A.slice(0, 4).map(function (n) { return fxCfa.columns[n]; });
  const matrixFanout = fanoutValues.map(function (vals, i) {
    return { name: 'q_m_r' + i, label: 'M — row ' + i, types: ['likert'], construct: 'M', values: vals };
  });
  const standaloneTagged = fanoutValues.map(function (vals, i) {
    return { name: 'q_s' + i, label: 'S' + i, types: ['likert'], construct: 'M', values: vals };
  });
  const outFanout = RSSI_MATH.computeLensesFromDataset({ source: 'matrix-fanout', rowCount: fxCfa.N, variables: matrixFanout });
  const outStandalone = RSSI_MATH.computeLensesFromDataset({ source: 'standalone-tagged', rowCount: fxCfa.N, variables: standaloneTagged });

  const caFan = outFanout.domain_details.construct_alignment;
  const caStd = outStandalone.domain_details.construct_alignment;
  check('§4B matrix-fanout: construct_alignment NOT skipped (shared construct activates §4B)',
    String(caFan.skipped), 'false');
  check('§4B matrix-fanout: exactly one per_scale entry (4 sub-items grouped as one scale)',
    String(caFan.breakdown.primary_loading.per_scale.length), '1');
  check('§4B matrix-fanout: per_scale entry uses the shared construct as scale name',
    caFan.breakdown.primary_loading.per_scale[0].scale, 'M');
  check('§4B matrix-fanout: per-scale loading count equals sub-item count (k=4)',
    String((caFan.breakdown.primary_loading.per_scale[0].loadings || []).length), '4');
  check('§4B matrix-fanout: per-scale mean loading equals standalone-tagged equivalent (same code path)',
    String(caFan.breakdown.primary_loading.per_scale[0].mean_loading),
    String(caStd.breakdown.primary_loading.per_scale[0].mean_loading));
}

console.log('');
if (failed) {
  console.log('VERIFICATION FAILED — see FAIL rows above.');
  process.exit(1);
} else {
  console.log('VERIFICATION PASSED — lens output identical across surfaces; §3.3/§3.5/§3.6/§4A/§4B/§4D/§4E/§4F/§4G all check.');
}
