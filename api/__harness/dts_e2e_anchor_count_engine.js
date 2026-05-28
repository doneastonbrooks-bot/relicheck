#!/usr/bin/env node
// Feeds the two datasets emitted by dts_e2e_anchor_count.php through the
// canonical engine and asserts the #4 unlock:
//   - Pre-#4 (control)  : §4G skips anchor_count / midpoint_presence /
//                         single_format_per_scale; §4D emits the
//                         scale_range_normalization_unavailable_using_absolute_threshold
//                         diagnostic.
//   - Post-#4 (treatment): same three §4G subs activate; the §4D diagnostic
//                          is absent.
//
// Run via:  node api/__harness/dts_e2e_anchor_count_engine.js

'use strict';
const fs   = require('fs');
const path = require('path');
const vm   = require('vm');

const ENGINE = path.resolve(__dirname, '..', '..', 'apps', 'strength-index', 'strength-index.js');
const CTL    = path.resolve(__dirname, 'dts_e2e_anchor_count_control.json');
const TRT    = path.resolve(__dirname, 'dts_e2e_anchor_count_treatment.json');

const localStore = {};
const sandbox = {
  console,
  setTimeout, clearTimeout, setInterval, clearInterval,
  document: { getElementById: () => null, querySelector: () => null,
              querySelectorAll: () => [], createElement: () => ({ setAttribute: () => {}, style: {} }) },
  window: { localStorage: {
      getItem: (k) => k in localStore ? localStore[k] : null,
      setItem: (k, v) => { localStore[k] = String(v); },
      removeItem: (k) => { delete localStore[k]; },
  } },
};
sandbox.window.document = sandbox.document;
sandbox.localStorage = sandbox.window.localStorage;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(ENGINE, 'utf8'), sandbox, { filename: 'strength-index.js' });

function run(label, dsPath) {
  const ds  = JSON.parse(fs.readFileSync(dsPath, 'utf8'));
  const cfg = (ds && ds.config) || {};
  const out = sandbox.window.RSSI_MATH.computeLensesFromDataset(ds, cfg);
  return { label, ds, out };
}

const control   = run('control',   CTL);
const treatment = run('treatment', TRT);

const fails = [];
function check(label, cond, detail) {
  if (!cond) fails.push(label + (detail !== undefined ? ' — ' + detail : ''));
}

// ── §4G assertions ────────────────────────────────────────────────────
const RSR_CTL = control.out.domain_details.response_scale_review || {};
const RSR_TRT = treatment.out.domain_details.response_scale_review || {};

const SKIP_KEYS = ['anchor_count', 'midpoint_presence', 'single_format_per_scale'];
const ctlSkipped = RSR_CTL.skipped_subcomponents || [];
const trtSkipped = RSR_TRT.skipped_subcomponents || [];

SKIP_KEYS.forEach((k) => {
  check('control §4G skips ' + k, ctlSkipped.indexOf(k) !== -1,
        'skipped_subcomponents=' + JSON.stringify(ctlSkipped));
  check('treatment §4G activates ' + k, trtSkipped.indexOf(k) === -1,
        'skipped_subcomponents=' + JSON.stringify(trtSkipped));
});

// Treatment must produce a finite score for each unlocked sub-component.
SKIP_KEYS.forEach((k) => {
  const b = (RSR_TRT.breakdown || {})[k] || {};
  check('treatment §4G ' + k + '.pts is finite', Number.isFinite(b.pts),
        'breakdown.' + k + '=' + JSON.stringify(b));
});

// ── §4D assertions ────────────────────────────────────────────────────
const BC_CTL  = control.out.domain_details.bias_clarity || {};
const BC_TRT  = treatment.out.domain_details.bias_clarity || {};
const DIAG    = 'scale_range_normalization_unavailable_using_absolute_threshold';

// Parse engine diagnostics from the interp string (engine appends them at
// the tail of interp; the field itself isn't exposed as a top-level array).
function interpHas(detail, needle) {
  return typeof detail.interp === 'string' && detail.interp.indexOf(needle) !== -1;
}

check('control §4D emits scale_range_normalization fallback diagnostic',
      interpHas(BC_CTL, DIAG),
      'interp=' + BC_CTL.interp);
check('treatment §4D does NOT emit scale_range_normalization fallback diagnostic',
      !interpHas(BC_TRT, DIAG),
      'interp=' + BC_TRT.interp);

// Sanity: §4G score should improve (or at minimum stay valid) when subs unlock.
check('control §4G score is finite',   Number.isFinite(RSR_CTL.score));
check('treatment §4G score is finite', Number.isFinite(RSR_TRT.score));

if (fails.length) {
  console.log('FAIL — ' + fails.length + ' assertion(s):');
  fails.forEach((f) => console.log('  - ' + f));
  console.log('\nControl §4G:   ', JSON.stringify({
    score: RSR_CTL.score, skipped_subcomponents: RSR_CTL.skipped_subcomponents,
  }));
  console.log('Treatment §4G: ', JSON.stringify({
    score: RSR_TRT.score, skipped_subcomponents: RSR_TRT.skipped_subcomponents,
  }));
  console.log('Control §4D interp:   ', BC_CTL.interp);
  console.log('Treatment §4D interp: ', BC_TRT.interp);
  process.exit(1);
}

console.log('OK — #4 unlock verified end-to-end:');
console.log('  §4G control skipped:    ', ctlSkipped.filter((k) => SKIP_KEYS.indexOf(k) !== -1));
console.log('  §4G treatment skipped:  ', trtSkipped.filter((k) => SKIP_KEYS.indexOf(k) !== -1));
console.log('  §4G control score:      ', RSR_CTL.score);
console.log('  §4G treatment score:    ', RSR_TRT.score);
console.log('  §4D control diagnostic: ', interpHas(BC_CTL, DIAG));
console.log('  §4D treatment diagnostic:', interpHas(BC_TRT, DIAG));
console.log('  Lens shift (PC/RC/VF): ',
            control.out.rssi.psychometric_core + '→' + treatment.out.rssi.psychometric_core,
            control.out.rssi.respondent_centered + '→' + treatment.out.rssi.respondent_centered,
            control.out.rssi.validity_forward + '→' + treatment.out.rssi.validity_forward);
