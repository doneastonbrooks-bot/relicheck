#!/usr/bin/env node
// Loads the engine and runs the dataset emitted by dts_e2e_uploaded_path.php
// through computeLensesFromDataset. Asserts §4A/§4B/§4E activate (not skipped).
// Run via:  node api/__harness/dts_e2e_engine_shim.js

'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ENGINE = path.resolve(__dirname, '..', '..', 'apps', 'strength-index', 'strength-index.js');
const DATA   = path.resolve(__dirname, 'dts_e2e_dataset.json');

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

const dataset = JSON.parse(fs.readFileSync(DATA, 'utf8'));
const out = sandbox.window.RSSI_MATH.computeLensesFromDataset(dataset);

const skipped = out.skipped_domains || [];
const fails = [];
function check(label, cond) { if (!cond) fails.push(label); }

check('§4A validity NOT skipped',           !skipped.includes('validity'));
check('§4B construct_alignment NOT skipped',!skipped.includes('construct_alignment'));
check('§4E scale_structure NOT skipped',    !skipped.includes('scale_structure'));
check('§4A score is finite',  Number.isFinite(out.domain_subscores?.validity));
check('§4B score is finite',  Number.isFinite(out.domain_subscores?.construct_alignment));
check('§4E score is finite',  Number.isFinite(out.domain_subscores?.scale_structure));

if (fails.length) {
  console.log('FAIL — skipped_domains:', skipped);
  console.log('domain_subscores:', out.domain_subscores);
  console.log('validity skip_reason:', out.domain_details?.validity?.skip_reason);
  console.log('construct_alignment skip_reason:', out.domain_details?.construct_alignment?.skip_reason);
  console.log('scale_structure skip_reason:', out.domain_details?.scale_structure?.skip_reason);
  console.log('first var sample:', dataset.variables[0]);
  fails.forEach(f => console.log('  -', f));
  process.exit(1);
}

console.log('OK — §4A/§4B/§4E all activate on uploaded-datasets path #1b end-to-end');
console.log('  validity:           ', out.domain_subscores.validity);
console.log('  construct_alignment:', out.domain_subscores.construct_alignment);
console.log('  scale_structure:    ', out.domain_subscores.scale_structure);
console.log('  lens scores: PC=' + out.rssi.psychometric_core +
            '  RC=' + out.rssi.respondent_centered +
            '  VF=' + out.rssi.validity_forward);
