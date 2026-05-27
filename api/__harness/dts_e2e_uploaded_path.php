<?php
// End-to-end check: synthetic uploaded-dataset column_meta + rows
// → dts_build_questions_and_index() (the #1b transform)
// → relicheck_survey_build_dataset() (the #1a/#1c transform)
// → emits the variables[] shape the engine consumes.
//
// Asserts: variables[].construct is populated on Likert items whose
// column_meta carried a construct, omitted otherwise. The downstream
// engine activation of §4A/§4B/§4E on this shape is already verified
// by the strength-index harness (#1a/#1c). Run via:
//   php api/__harness/dts_e2e_uploaded_path.php

declare(strict_types=1);
require_once __DIR__ . '/../_dataset_to_survey.php';
require_once __DIR__ . '/../surveys/_build_dataset.php';

// Two 4-item Likert scales + one untagged item + one categorical.
$columnMeta = [
    ['name' => 'eng1',   'type' => 'likert', 'reverse' => false, 'construct' => 'Engagement'],
    ['name' => 'eng2',   'type' => 'likert', 'reverse' => false, 'construct' => 'Engagement'],
    ['name' => 'eng3',   'type' => 'likert', 'reverse' => false, 'construct' => 'Engagement'],
    ['name' => 'eng4',   'type' => 'likert', 'reverse' => false, 'construct' => 'Engagement'],
    ['name' => 'bel1',   'type' => 'likert', 'reverse' => false, 'construct' => 'Belonging'],
    ['name' => 'bel2',   'type' => 'likert', 'reverse' => false, 'construct' => 'Belonging'],
    ['name' => 'bel3',   'type' => 'likert', 'reverse' => false, 'construct' => 'Belonging'],
    ['name' => 'bel4',   'type' => 'likert', 'reverse' => false, 'construct' => 'Belonging'],
    ['name' => 'gender', 'type' => 'single', 'options' => ['F','M']],
];
// 60 synthetic rows. Engagement items co-vary; Belonging items co-vary.
// Deterministic mock that produces non-degenerate per-scale correlation.
$rows = [];
mt_srand(42);
for ($i = 0; $i < 60; $i++) {
    $engBase = mt_rand(1, 5);
    $belBase = mt_rand(1, 5);
    $jit = function ($b) { $v = $b + (mt_rand(0, 2) - 1); return max(1, min(5, $v)); };
    $rows[] = [
        $engBase, $jit($engBase), $jit($engBase), $jit($engBase),
        $belBase, $jit($belBase), $jit($belBase), $jit($belBase),
        ($i % 2) ? 'F' : 'M',
    ];
}

// #1b transform: column_meta → questions
$built     = dts_build_questions_and_index($columnMeta, $rows);
$questions = $built['questions'];
$colIndex  = $built['col_index'];

// Build responses in the shape _build_dataset.php expects
$responses = [];
foreach ($rows as $rowIdx => $row) {
    $answers = dts_row_to_answers($row, $colIndex);
    $responses[] = [
        'id'           => $rowIdx + 1,
        'submitted_at' => '2026-05-27 12:00:00',
        'answers'      => $answers,
    ];
}

// #1a/#1c transform: questions + responses → engine-shape dataset
$dataset = relicheck_survey_build_dataset('synthetic-#1b-e2e', $questions, $responses, []);

$failures = [];
function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}

// _build_dataset emits name = 'q_<qid>' and label = original prompt (column name).
// Look up by label so the asserts read against the source column names.
$varByName = [];
foreach ($dataset['variables'] as $v) $varByName[$v['label']] = $v;

assert_eq('eng1 construct propagated',   $varByName['eng1']['construct']  ?? null, 'Engagement');
assert_eq('eng4 construct propagated',   $varByName['eng4']['construct']  ?? null, 'Engagement');
assert_eq('bel1 construct propagated',   $varByName['bel1']['construct']  ?? null, 'Belonging');
assert_eq('bel4 construct propagated',   $varByName['bel4']['construct']  ?? null, 'Belonging');
assert_eq('gender (categorical) omits construct', array_key_exists('construct', $varByName['gender'] ?? []), false);
assert_eq('rowCount = 60', (int)$dataset['rowCount'], 60);

// Distinct tagged constructs across Likert variables (engine groups on this)
$constructs = [];
foreach ($dataset['variables'] as $v) {
    if (!empty($v['construct'])) $constructs[$v['construct']] = true;
}
assert_eq('Two distinct constructs grouped', count($constructs), 2);

if ($failures) {
    echo "FAIL — " . count($failures) . " assertion(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}

echo "OK — uploaded-datasets path #1b end-to-end: column_meta.construct → q.construct → v.construct (7/7 assertions)\n";
echo "Constructs grouped: " . implode(', ', array_keys($constructs)) . "\n";

// Emit the dataset for the node engine to consume
$out = __DIR__ . '/dts_e2e_dataset.json';
file_put_contents($out, json_encode($dataset, JSON_UNESCAPED_UNICODE));
echo "Dataset written to: $out\n";
