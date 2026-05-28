<?php
// Unit-level assertion: relicheck_survey_build_dataset() must
//   (a) propagate per-item reverse_coded onto each Likert variable with
//       precedence q.reverse (Builder) > settings.scales[vname].reverse
//       (wizard), and
//   (b) emit dataset.config.reverse_coded_confirmed when the survey-level
//       flag is present on settings; omit otherwise.
//
// Activates KNOWN_ISSUES.md §4 items #2 (per-item reverse_coded) and
// #3 (survey-level reverse_coded_confirmed). Mirrors the precedence
// rule already established for construct.
// Run via:  php api/__harness/dts_reverse_coded_propagation.php

declare(strict_types=1);
require_once __DIR__ . '/../surveys/_build_dataset.php';

$failures = [];
function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}

$responses = [
    ['id' => 1, 'submitted_at' => '2026-05-27 12:00:00', 'answers' => ['Q1' => 4, 'Q2' => 3, 'Q3' => 5, 'Q4' => 2]],
    ['id' => 2, 'submitted_at' => '2026-05-27 12:01:00', 'answers' => ['Q1' => 3, 'Q2' => 4, 'Q3' => 4, 'Q4' => 1]],
];

// ── Case A: Builder q.reverse wins over wizard map ───────────────────
$questionsA = [
    ['id' => 'Q1', 'type' => 'likert', 'prompt' => 'Item 1', 'reverse' => true],   // Builder says true
    ['id' => 'Q2', 'type' => 'likert', 'prompt' => 'Item 2', 'reverse' => false],  // Builder says explicit false
    ['id' => 'Q3', 'type' => 'likert', 'prompt' => 'Item 3'],                       // Builder omits → wizard fallback
    ['id' => 'Q4', 'type' => 'likert', 'prompt' => 'Item 4'],                       // Builder omits AND wizard omits → no key
];
$settingsA = [
    'scales' => [
        'q_Q1' => ['scale' => 'S', 'reverse' => false],  // wizard says false but Builder true wins
        'q_Q2' => ['scale' => 'S', 'reverse' => true],   // wizard says true but Builder explicit false wins
        'q_Q3' => ['scale' => 'S', 'reverse' => true],   // wizard fallback → true
        // q_Q4 absent
    ],
];
$dsA = relicheck_survey_build_dataset('A', $questionsA, $responses, $settingsA);
$varA = [];
foreach ($dsA['variables'] as $v) $varA[$v['name']] = $v;

assert_eq('A Q1 Builder true overrides wizard false', $varA['q_Q1']['reverse_coded'] ?? null, true);
assert_eq('A Q2 Builder explicit false overrides wizard true', $varA['q_Q2']['reverse_coded'] ?? null, false);
assert_eq('A Q3 wizard fallback applies when Builder omits', $varA['q_Q3']['reverse_coded'] ?? null, true);
assert_eq('A Q4 key omitted when neither side set', array_key_exists('reverse_coded', $varA['q_Q4']), false);

// ── Case B: survey-level reverse_coded_confirmed propagation ─────────
$questionsB = [['id' => 'Q1', 'type' => 'likert', 'prompt' => 'X']];
$responsesB = [['id' => 1, 'submitted_at' => '2026-05-27 12:00:00', 'answers' => ['Q1' => 3]]];

$dsB_absent = relicheck_survey_build_dataset('B-absent', $questionsB, $responsesB, []);
assert_eq('B absent: dataset.config has no reverse_coded_confirmed key',
          array_key_exists('reverse_coded_confirmed', $dsB_absent['config']), false);

$dsB_true = relicheck_survey_build_dataset('B-true', $questionsB, $responsesB, ['reverse_coded_confirmed' => true]);
assert_eq('B true: config.reverse_coded_confirmed === true',
          $dsB_true['config']['reverse_coded_confirmed'] ?? null, true);

$dsB_false = relicheck_survey_build_dataset('B-false', $questionsB, $responsesB, ['reverse_coded_confirmed' => false]);
assert_eq('B false: config.reverse_coded_confirmed === false (key present, value false)',
          $dsB_false['config']['reverse_coded_confirmed'] ?? 'MISSING', false);
assert_eq('B false: key IS present even when false (tri-state contract)',
          array_key_exists('reverse_coded_confirmed', $dsB_false['config']), true);

// ── Case C: matrix sub-item three-level fallback for reverse_coded ───
$questionsC = [
    ['id' => 'M1', 'type' => 'open', '_builderType' => 'matrix', 'prompt' => 'Matrix',
     'matrixRows' => ['Row A', 'Row B', 'Row C'], 'reverse' => true],  // Builder parent → true wins
    ['id' => 'M2', 'type' => 'open', '_builderType' => 'matrix', 'prompt' => 'Matrix 2',
     'matrixRows' => ['Row A', 'Row B']],  // No Builder reverse → fall back to wizard
];
$responsesC = [
    ['id' => 1, 'submitted_at' => '2026-05-27 12:00:00', 'answers' => [
        'M1' => ['0' => 3, '1' => 2, '2' => 4],
        'M2' => ['0' => 1, '1' => 5],
    ]],
];
$settingsC = [
    'scales' => [
        'q_M2_r0' => ['scale' => 'X', 'reverse' => true],   // per-row wizard tag
        'q_M2'    => ['scale' => 'X', 'reverse' => false],  // parent-question wizard tag
    ],
];
$dsC = relicheck_survey_build_dataset('C', $questionsC, $responsesC, $settingsC);
$varC = [];
foreach ($dsC['variables'] as $v) $varC[$v['name']] = $v;

assert_eq('C M1 row 0: Builder parent q.reverse=true inherits to sub-row',
          $varC['q_M1_r0']['reverse_coded'] ?? null, true);
assert_eq('C M1 row 2: Builder parent q.reverse=true inherits to all sub-rows',
          $varC['q_M1_r2']['reverse_coded'] ?? null, true);
assert_eq('C M2 row 0: per-row wizard wins over parent wizard (no Builder)',
          $varC['q_M2_r0']['reverse_coded'] ?? null, true);
assert_eq('C M2 row 1: parent-question wizard fallback (no per-row, no Builder)',
          $varC['q_M2_r1']['reverse_coded'] ?? null, false);

if ($failures) {
    echo "FAIL — " . count($failures) . " assertion(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "OK — _build_dataset propagates reverse_coded (per-item) and reverse_coded_confirmed (survey-level) with documented precedence (12/12 assertions)\n";
