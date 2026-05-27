<?php
// Unit-level assertion: dts_build_questions_and_index() must propagate
// column_meta.construct onto each Likert question, with the same
// trim-and-omit-when-empty contract _build_dataset.php uses for q.construct.
//
// Activates KNOWN_ISSUES.md §4 item #1b: uploaded-datasets path. Run via:
//   php api/__harness/dts_construct_propagation.php

declare(strict_types=1);
require_once __DIR__ . '/../_dataset_to_survey.php';

$columnMeta = [
    ['name' => 'Q1_engagement',  'type' => 'likert', 'reverse' => false, 'construct' => 'Engagement'],
    ['name' => 'Q2_engagement',  'type' => 'likert', 'reverse' => true,  'construct' => '  Engagement  '], // whitespace must trim
    ['name' => 'Q3_belonging',   'type' => 'likert', 'reverse' => false, 'construct' => 'Belonging'],
    ['name' => 'Q4_untagged',    'type' => 'likert', 'reverse' => false, 'construct' => ''],               // empty must omit
    ['name' => 'Q5_whitespace',  'type' => 'likert', 'reverse' => false, 'construct' => '   '],            // whitespace-only must omit
    ['name' => 'Q6_no_field',    'type' => 'likert', 'reverse' => false],                                  // missing field must omit
    ['name' => 'Gender',         'type' => 'single', 'options' => ['F','M'], 'construct' => 'Engagement'], // non-Likert: construct ignored
    ['name' => 'Comments',       'type' => 'open',   'construct' => 'Belonging'],                          // non-Likert: construct ignored
];
$rows = [
    [4, 5, 3, 2, 4, 5, 'F', 'hello'],
    [3, 4, 5, 1, 3, 4, 'M', 'world'],
];

$built = dts_build_questions_and_index($columnMeta, $rows);
$questions = $built['questions'];

$failures = [];
function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}

// Index questions by prompt for stable lookup
$byPrompt = [];
foreach ($questions as $q) $byPrompt[$q['prompt']] = $q;

assert_eq('Q1 construct set',           $byPrompt['Q1_engagement']['construct'] ?? null, 'Engagement');
assert_eq('Q2 whitespace-trimmed',      $byPrompt['Q2_engagement']['construct'] ?? null, 'Engagement');
assert_eq('Q3 construct set',           $byPrompt['Q3_belonging']['construct']  ?? null, 'Belonging');
assert_eq('Q4 empty-string omitted',    array_key_exists('construct', $byPrompt['Q4_untagged']),   false);
assert_eq('Q5 whitespace-only omitted', array_key_exists('construct', $byPrompt['Q5_whitespace']), false);
assert_eq('Q6 missing-field omitted',   array_key_exists('construct', $byPrompt['Q6_no_field']),   false);
assert_eq('Single column not Likert (construct ignored at type level — present in questions only because non-Likert never carries construct)',
          array_key_exists('construct', $byPrompt['Gender']),   false);
assert_eq('Open column construct also not carried',
          array_key_exists('construct', $byPrompt['Comments']), false);
assert_eq('All 8 input columns produced questions', count($questions), 8);

if ($failures) {
    echo "FAIL — " . count($failures) . " assertion(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "OK — dts_build_questions_and_index propagates construct with trim-and-omit-when-empty contract (9/9 assertions)\n";
