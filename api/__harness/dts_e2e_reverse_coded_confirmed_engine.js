#!/usr/bin/env node
// End-to-end engine assertion for KNOWN_ISSUES.md §4 #3.
// Consumes the two datasets emitted by dts_e2e_reverse_coded_confirmed.php
// and asserts the §4E sub-component 2 behavior on each:
//   - no-flag dataset    → sub-2 SKIPS (skip-and-rescale; engine sums
//                          remaining 12 raw pts and rescales to 15)
//   - confirm-true dataset → sub-2 ACTIVATES and scores 0 (k≥4 scales
//                            with zero flagged items — the architectural
//                            finding the spec describes)
// Run via:  node api/__harness/dts_e2e_reverse_coded_confirmed_engine.js

'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ENGINE = path.resolve(__dirname, '..', '..', 'apps', 'strength-index', 'strength-index.js');
const NO_FLAG = path.resolve(__dirname, 'dts_e2e_reverse_no_flag.json');
const CONFIRM = path.resolve(__dirname, 'dts_e2e_reverse_confirm_true.json');

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
const engine = sandbox.window.RSSI_MATH.computeLensesFromDataset;

const fails = [];
function check(label, cond, ctx) {
  if (!cond) fails.push({ label, ctx });
}

// ── Case 1: no confirmation flag → sub-2 skipped ─────────────────────
const dsNoFlag = JSON.parse(fs.readFileSync(NO_FLAG, 'utf8'));
const outNoFlag = engine(dsNoFlag, dsNoFlag.config || {});
const ssNoFlag  = outNoFlag.domain_details.scale_structure;
check('No-flag: §4E not whole-domain-skipped (constructs present)',
      !outNoFlag.skipped_domains.includes('scale_structure'), ssNoFlag);
check('No-flag: sub-2 reverse_coded_balance.skipped === true',
      ssNoFlag.breakdown && ssNoFlag.breakdown.reverse_coded_balance &&
      ssNoFlag.breakdown.reverse_coded_balance.skipped === true,
      ssNoFlag.breakdown && ssNoFlag.breakdown.reverse_coded_balance);

// ── Case 2: confirmation true → sub-2 activates, scores 0 ────────────
const dsConfirm = JSON.parse(fs.readFileSync(CONFIRM, 'utf8'));
const outConfirm = engine(dsConfirm, dsConfirm.config || {});
const ssConfirm  = outConfirm.domain_details.scale_structure;
check('Confirm-true: §4E not whole-domain-skipped',
      !outConfirm.skipped_domains.includes('scale_structure'), ssConfirm);
check('Confirm-true: sub-2 NOT skipped (engine read the config flag)',
      ssConfirm.breakdown && ssConfirm.breakdown.reverse_coded_balance &&
      ssConfirm.breakdown.reverse_coded_balance.skipped !== true,
      ssConfirm.breakdown && ssConfirm.breakdown.reverse_coded_balance);
check('Confirm-true: sub-2 pts === 0 (architectural finding fired — k≥4 scales, no flagged items)',
      ssConfirm.breakdown && ssConfirm.breakdown.reverse_coded_balance &&
      ssConfirm.breakdown.reverse_coded_balance.pts === 0,
      ssConfirm.breakdown && ssConfirm.breakdown.reverse_coded_balance);

// Sanity: the two scores should differ — the confirm path scores LOWER
// than the no-flag path (sub-2 activates and zeros, vs. skip+rescale of
// the remaining sub-components). Equal would indicate the flag is inert.
check('Confirm-true score < no-flag score (sub-2 activation lowers raw)',
      typeof ssConfirm.score === 'number' && typeof ssNoFlag.score === 'number' &&
      ssConfirm.score < ssNoFlag.score,
      { confirm_score: ssConfirm.score, noflag_score: ssNoFlag.score });

if (fails.length) {
  console.log('FAIL — ' + fails.length + ' check(s):');
  fails.forEach(f => { console.log('  -', f.label); console.log('    ctx:', JSON.stringify(f.ctx, null, 2)); });
  process.exit(1);
}
console.log('OK — §4E sub-2 tri-state activates correctly end-to-end (5/5 checks)');
console.log('  no-flag    §4E score:', ssNoFlag.score, '(sub-2 skipped → rescaled from remaining sub-components)');
console.log('  confirm    §4E score:', ssConfirm.score, '(sub-2 active, scored 0 → architectural finding)');
