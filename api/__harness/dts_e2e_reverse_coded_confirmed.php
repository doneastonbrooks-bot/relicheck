<?php
// End-to-end harness for KNOWN_ISSUES.md §4 #3 (reverse_coded_confirmed):
// builds a synthetic dataset with two k=4 scales, no items flagged as
// reverse-coded, and the survey-level confirmation flag set to true. The
// engine's §4E sub-component 2 should fire the architectural finding
// (score 0 for the reverse-coded balance sub-component on k≥4 scales).
//
// Companion node shim dts_e2e_reverse_coded_confirmed_engine.js consumes
// the emitted dataset.json and asserts on the engine output.
// Run via:  php api/__harness/dts_e2e_reverse_coded_confirmed.php

declare(strict_types=1);
require_once __DIR__ . '/../surveys/_build_dataset.php';

// Two 4-item Likert scales. No item has reverse_coded set (all explicit
// false from the Builder — meaning the platform has captured an opinion
// for every item, and the opinion is "none reversed").
$questions = [];
foreach (['eng1','eng2','eng3','eng4'] as $i => $name) {
    $questions[] = ['id' => $name, 'type' => 'likert', 'prompt' => $name,
                    'construct' => 'Engagement', 'reverse' => false];
}
foreach (['bel1','bel2','bel3','bel4'] as $i => $name) {
    $questions[] = ['id' => $name, 'type' => 'likert', 'prompt' => $name,
                    'construct' => 'Belonging', 'reverse' => false];
}

// 60 deterministic rows (mirrors dts_e2e_uploaded_path.php fixture).
$responses = [];
mt_srand(42);
for ($i = 0; $i < 60; $i++) {
    $eb = mt_rand(1, 5);
    $bb = mt_rand(1, 5);
    $jit = fn($b) => max(1, min(5, $b + (mt_rand(0, 2) - 1)));
    $responses[] = [
        'id' => $i + 1, 'submitted_at' => '2026-05-27 12:00:00',
        'answers' => [
            'eng1' => $eb,        'eng2' => $jit($eb), 'eng3' => $jit($eb), 'eng4' => $jit($eb),
            'bel1' => $bb,        'bel2' => $jit($bb), 'bel3' => $jit($bb), 'bel4' => $jit($bb),
        ],
    ];
}

// Two datasets emitted: one WITHOUT the confirmation flag (engine should
// skip sub-2), one WITH (engine should activate and score it zero given
// no reverse-coded items in any k≥4 scale).
$dsNoFlag      = relicheck_survey_build_dataset('no-confirm', $questions, $responses, []);
$dsConfirmTrue = relicheck_survey_build_dataset('confirm-true', $questions, $responses,
                                                 ['reverse_coded_confirmed' => true]);

// Light PHP-side asserts on shape; the engine half is the node shim.
$failures = [];
function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}
assert_eq('No-flag: config missing reverse_coded_confirmed',
          array_key_exists('reverse_coded_confirmed', $dsNoFlag['config']), false);
assert_eq('Confirm-true: config.reverse_coded_confirmed === true',
          $dsConfirmTrue['config']['reverse_coded_confirmed'] ?? null, true);
// Every Likert variable carries reverse_coded=false (Builder said so)
$allFalse = true;
foreach ($dsConfirmTrue['variables'] as $v) {
    if (!in_array('likert', $v['types'], true)) continue;
    if (($v['reverse_coded'] ?? null) !== false) { $allFalse = false; break; }
}
assert_eq('Every Likert var has reverse_coded=false', $allFalse, true);

if ($failures) {
    echo "FAIL — " . count($failures) . " assertion(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
file_put_contents(__DIR__ . '/dts_e2e_reverse_no_flag.json',
                  json_encode($dsNoFlag, JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/dts_e2e_reverse_confirm_true.json',
                  json_encode($dsConfirmTrue, JSON_UNESCAPED_UNICODE));
echo "OK — PHP transform emits both datasets correctly (3/3 assertions)\n";
echo "Datasets written to api/__harness/dts_e2e_reverse_{no_flag,confirm_true}.json\n";
echo "Next: node api/__harness/dts_e2e_reverse_coded_confirmed_engine.js\n";
