/* ════════════════════════════════════════════════════════════════════════
   Administration Readiness LOCKSTEP test
   ────────────────────────────────────────────────────────────────────────
   The client spec (apps/sdsi/administration-specs.js) drives the engine + UI;
   the server mirror (api/sdsi/_administration_prompts.php) is the validation
   gate for AI proposals and the prompt content. They MUST agree on the
   load-bearing vocabulary or the proposer/validator and the scorer drift apart.

   This test dumps the PHP spec to JSON (via the `php` CLI) and compares, per
   component, the pieces that both sides depend on:
     • the component key set + display order
     • SDSI weight + noun + label
     • check keys
     • severityHints (check → default severity)
     • contextFields (key, type, and select options)
     • blocker keys      (JS spec.blockers[].key  ↔  PHP blockerConditions[])
     • warning keys       (JS spec.warnings[].key  ↔  PHP warnings[])

   Non-load-bearing prose (principle, checkDesc one-liners, severityGuidance,
   field labels, intro/reviewerPrompt) is intentionally NOT compared — those are
   allowed to read differently between the engine UI and the server prompt.
   ════════════════════════════════════════════════════════════════════════ */
'use strict';

var { execFileSync } = require('child_process');
var path = require('path');

global.window = global;
require('./validity-lens-engine.js');
var JS = require('./administration-specs.js');

var pass = 0, fail = 0;
function eq(label, got, want) {
  var g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) { pass++; }
  else { fail++; console.error('FAIL: ' + label + '\n      got  ' + g + '\n      want ' + w); }
}

/* ── Dump the PHP spec to JSON through the php CLI. ── */
var phpFile = path.resolve(__dirname, '../../api/sdsi/_administration_prompts.php');
var phpDump = '' +
  "require '" + phpFile + "';" +
  "$out = ['order' => administration_components(), 'specs' => []];" +
  "foreach (administration_components() as $k) {" +
  "  $s = administration_component_spec($k);" +
  "  $cf = [];" +
  "  foreach ($s['contextFields'] as $f) {" +
  "    $cf[] = ['key'=>$f['key'],'type'=>$f['type'],'options'=>array_values($f['options'] ?? [])];" +
  "  }" +
  "  $out['specs'][$k] = [" +
  "    'weight' => $s['weight']," +
  "    'noun'   => $s['noun']," +
  "    'label'  => $s['label']," +
  "    'checks' => array_keys($s['checks'])," +
  "    'severityHints' => $s['severityHints']," +
  "    'contextFields' => $cf," +
  "    'blockerConditions' => array_values($s['blockerConditions'])," +
  "    'warnings' => array_values($s['warnings'])," +
  "  ];" +
  "}" +
  "echo json_encode($out);";

var PHP;
try {
  var raw = execFileSync('php', ['-r', phpDump], { encoding: 'utf8' });
  PHP = JSON.parse(raw);
} catch (e) {
  console.error('FATAL: could not run the PHP dumper (is the `php` CLI on PATH?)');
  console.error(String(e && e.message || e));
  process.exit(1);
}

/* ── Order + key set parity. ── */
eq('component order matches', PHP.order, JS.ORDER);
eq('same component key set',
   Object.keys(PHP.specs).sort(),
   JS.ORDER.slice().sort());

/* ── Per-component load-bearing parity. ── */
JS.ORDER.forEach(function (k) {
  var js = JS.SPECS[k];
  var php = PHP.specs[k];
  if (!php) { fail++; console.error('FAIL: PHP missing component ' + k); return; }

  eq(k + ' · weight', php.weight, js.weight);
  eq(k + ' · noun', php.noun, js.noun);
  eq(k + ' · label', php.label, js.name);

  eq(k + ' · check keys', php.checks, Object.keys(js.checks));

  // severityHints: same keys + same default severity per check.
  eq(k + ' · severityHints', php.severityHints, js.severityHints);

  // contextFields: key + type + select options, in order.
  var jsCf = js.contextFields.map(function (f) {
    return { key: f.key, type: f.type, options: (f.options || []).map(function (o) {
      return typeof o === 'string' ? o : o.value;
    }) };
  });
  eq(k + ' · contextFields', php.contextFields, jsCf);

  // blockers: JS spec.blockers[].key  ↔  PHP blockerConditions[]
  eq(k + ' · blocker keys', php.blockerConditions, (js.blockers || []).map(function (b) { return b.key; }));

  // warnings: JS spec.warnings[].key  ↔  PHP warnings[]
  eq(k + ' · warning keys', php.warnings, (js.warnings || []).map(function (w) { return w.key; }));
});

if (fail === 0) console.log('ALL PASS — ' + pass + ' assertions, JS ↔ PHP in lockstep');
else { console.error('\n' + fail + ' FAILED (' + pass + ' passed)'); process.exit(1); }
