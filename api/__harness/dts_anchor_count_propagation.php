<?php
// Unit-level assertion for KNOWN_ISSUES.md §4 item #4 (per-item anchor
// metadata). Two transforms in scope:
//
//   (A) relicheck_survey_build_dataset() must emit v.anchor_count on each
//       Likert variable with precedence:
//         q.likertPoints (Builder per-question override)
//           → settings.likertPoints (survey-level default)
//           → omitted (preserves pre-#4 §4G/§4D skip behavior).
//       Matrix sub-items use single-level fallback (parent q.likertPoints
//       → settings.likertPoints) — Builder UI sets points at the matrix-
//       question level, so all rows share one anchor count.
//
//   (B) dts_build_questions_and_index() must bake q.likertPoints into each
//       synthesized Likert question from the dataset's settings.likertPoints,
//       so the downstream _build_dataset.php transform picks it up via
//       precedence (A). Omit when both column_meta.likertPoints (future
//       per-column override) and settings.likertPoints are absent or
//       invalid.
//
// Run via:  php api/__harness/dts_anchor_count_propagation.php

declare(strict_types=1);
require_once __DIR__ . '/../surveys/_build_dataset.php';
require_once __DIR__ . '/../_dataset_to_survey.php';

$failures = [];
function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}
function assert_absent_key($label, array $arr, string $key) {
    global $failures;
    if (array_key_exists($key, $arr)) {
        $failures[] = $label . ' — expected key ' . $key . ' to be absent, got ' . var_export($arr[$key], true);
    }
}

$responses = [
    ['id' => 1, 'submitted_at' => '2026-05-27 12:00:00', 'answers' => ['Q1' => 4, 'Q2' => 3, 'Q3' => 5, 'Q4' => 2]],
    ['id' => 2, 'submitted_at' => '2026-05-27 12:01:00', 'answers' => ['Q1' => 3, 'Q2' => 4, 'Q3' => 4, 'Q4' => 1]],
];

// ── Case A1: per-question override wins over survey-level default ──────
$questionsA1 = [
    ['id' => 'Q1', 'type' => 'likert', 'prompt' => 'Item 1', 'likertPoints' => 7],   // override
    ['id' => 'Q2', 'type' => 'likert', 'prompt' => 'Item 2'],                          // fallback to survey-level
    ['id' => 'Q3', 'type' => 'likert', 'prompt' => 'Item 3', 'likertPoints' => 3],   // override (boundary)
    ['id' => 'Q4', 'type' => 'likert', 'prompt' => 'Item 4', 'likertPoints' => 11],  // override (boundary)
];
$dsA1 = relicheck_survey_build_dataset('A1', $questionsA1, $responses, ['likertPoints' => 5]);
$vA1 = [];
foreach ($dsA1['variables'] as $v) $vA1[$v['name']] = $v;

assert_eq('A1 Q1 per-question 7 wins',                 $vA1['q_Q1']['anchor_count'] ?? null, 7);
assert_eq('A1 Q2 falls back to survey-level 5',        $vA1['q_Q2']['anchor_count'] ?? null, 5);
assert_eq('A1 Q3 per-question lower boundary 3',       $vA1['q_Q3']['anchor_count'] ?? null, 3);
assert_eq('A1 Q4 per-question upper boundary 11',      $vA1['q_Q4']['anchor_count'] ?? null, 11);

// ── Case A2: both absent → omitted (preserves pre-#4 skip semantics) ──
$questionsA2 = [
    ['id' => 'Q1', 'type' => 'likert', 'prompt' => 'Item 1'],
    ['id' => 'Q2', 'type' => 'likert', 'prompt' => 'Item 2'],
];
$dsA2 = relicheck_survey_build_dataset('A2', $questionsA2, $responses, []); // no likertPoints anywhere
$vA2 = [];
foreach ($dsA2['variables'] as $v) $vA2[$v['name']] = $v;

assert_absent_key('A2 Q1 anchor_count omitted when no source has a value', $vA2['q_Q1'], 'anchor_count');
assert_absent_key('A2 Q2 anchor_count omitted when no source has a value', $vA2['q_Q2'], 'anchor_count');

// ── Case A3: invalid survey-level values are rejected (omitted) ────────
$dsA3a = relicheck_survey_build_dataset('A3a', [['id' => 'Q1', 'type' => 'likert', 'prompt' => 'I']], $responses, ['likertPoints' => 1]);    // below 2
$dsA3b = relicheck_survey_build_dataset('A3b', [['id' => 'Q1', 'type' => 'likert', 'prompt' => 'I']], $responses, ['likertPoints' => 12]);   // above 11
$dsA3c = relicheck_survey_build_dataset('A3c', [['id' => 'Q1', 'type' => 'likert', 'prompt' => 'I']], $responses, ['likertPoints' => 'foo']); // non-numeric

assert_absent_key('A3a survey-level likertPoints=1 rejected',       $dsA3a['variables'][0], 'anchor_count');
assert_absent_key('A3b survey-level likertPoints=12 rejected',      $dsA3b['variables'][0], 'anchor_count');
assert_absent_key('A3c survey-level likertPoints non-numeric',      $dsA3c['variables'][0], 'anchor_count');

// ── Case A4: invalid per-question override → fall back to survey-level ─
$questionsA4 = [
    ['id' => 'Q1', 'type' => 'likert', 'prompt' => 'I', 'likertPoints' => 0],     // invalid → fallback
    ['id' => 'Q2', 'type' => 'likert', 'prompt' => 'I', 'likertPoints' => 99],    // invalid → fallback
    ['id' => 'Q3', 'type' => 'likert', 'prompt' => 'I', 'likertPoints' => null],  // invalid → fallback
];
$dsA4 = relicheck_survey_build_dataset('A4', $questionsA4, $responses, ['likertPoints' => 5]);
$vA4 = [];
foreach ($dsA4['variables'] as $v) $vA4[$v['name']] = $v;

assert_eq('A4 Q1 invalid override 0 falls back to 5',    $vA4['q_Q1']['anchor_count'] ?? null, 5);
assert_eq('A4 Q2 invalid override 99 falls back to 5',   $vA4['q_Q2']['anchor_count'] ?? null, 5);
assert_eq('A4 Q3 invalid override null falls back to 5', $vA4['q_Q3']['anchor_count'] ?? null, 5);

// ── Case A5: matrix sub-items inherit parent q.likertPoints ────────────
$responsesA5 = [
    ['id' => 1, 'submitted_at' => '2026-05-27 12:00:00', 'answers' => ['M1' => ['0' => 3, '1' => 2, '2' => 4]]],
    ['id' => 2, 'submitted_at' => '2026-05-27 12:01:00', 'answers' => ['M1' => ['0' => 2, '1' => 3, '2' => 5]]],
];
$questionsA5 = [
    [
        'id'           => 'M1',
        'type'         => 'open',
        '_builderType' => 'matrix',
        'prompt'       => 'Matrix Q',
        'matrixRows'   => ['Row A', 'Row B', 'Row C'],
        'likertPoints' => 7,
    ],
];
$dsA5 = relicheck_survey_build_dataset('A5', $questionsA5, $responsesA5, ['likertPoints' => 5]);
$vA5 = [];
foreach ($dsA5['variables'] as $v) $vA5[$v['name']] = $v;

assert_eq('A5 matrix row 0 inherits parent 7', $vA5['q_M1_r0']['anchor_count'] ?? null, 7);
assert_eq('A5 matrix row 1 inherits parent 7', $vA5['q_M1_r1']['anchor_count'] ?? null, 7);
assert_eq('A5 matrix row 2 inherits parent 7', $vA5['q_M1_r2']['anchor_count'] ?? null, 7);

// ── Case A6: matrix sub-items fall back to survey-level default ────────
$questionsA6 = [
    [
        'id'           => 'M1',
        'type'         => 'open',
        '_builderType' => 'matrix',
        'prompt'       => 'Matrix Q',
        'matrixRows'   => ['Row A', 'Row B'],
        // no q.likertPoints
    ],
];
$dsA6 = relicheck_survey_build_dataset('A6', $questionsA6, $responsesA5, ['likertPoints' => 5]);
$vA6 = [];
foreach ($dsA6['variables'] as $v) $vA6[$v['name']] = $v;

assert_eq('A6 matrix row 0 falls back to survey-level 5', $vA6['q_M1_r0']['anchor_count'] ?? null, 5);
assert_eq('A6 matrix row 1 falls back to survey-level 5', $vA6['q_M1_r1']['anchor_count'] ?? null, 5);

// ── Case A7: matrix sub-items omit when both absent ────────────────────
$dsA7 = relicheck_survey_build_dataset('A7', $questionsA6, $responsesA5, []); // no settings.likertPoints
assert_absent_key('A7 matrix row 0 omits when both absent', $dsA7['variables'][0], 'anchor_count');
assert_absent_key('A7 matrix row 1 omits when both absent', $dsA7['variables'][1], 'anchor_count');

// ── Case B1: dts_build_questions_and_index bakes likertPoints from dsSettings
$columnMetaB1 = [
    ['name' => 'item_a', 'type' => 'likert'],
    ['name' => 'item_b', 'type' => 'likert', 'construct' => 'Belonging'],
];
$rowsB1 = [[3, 4], [2, 5]];
$builtB1 = dts_build_questions_and_index($columnMetaB1, $rowsB1, ['likertPoints' => 7]);
assert_eq('B1 synthesized q[0].likertPoints = 7',  $builtB1['questions'][0]['likertPoints'] ?? null, 7);
assert_eq('B1 synthesized q[1].likertPoints = 7',  $builtB1['questions'][1]['likertPoints'] ?? null, 7);

// ── Case B2: column_meta.likertPoints (future per-column override) wins ─
$columnMetaB2 = [
    ['name' => 'item_a', 'type' => 'likert', 'likertPoints' => 5],
    ['name' => 'item_b', 'type' => 'likert'],
];
$builtB2 = dts_build_questions_and_index($columnMetaB2, $rowsB1, ['likertPoints' => 7]);
assert_eq('B2 per-column override 5 wins',         $builtB2['questions'][0]['likertPoints'] ?? null, 5);
assert_eq('B2 fallback to dataset-wide 7',         $builtB2['questions'][1]['likertPoints'] ?? null, 7);

// ── Case B3: no dsSettings.likertPoints → key omitted on synthesized q ──
$builtB3 = dts_build_questions_and_index($columnMetaB1, $rowsB1, []); // no likertPoints
assert_absent_key('B3 synthesized q[0] omits likertPoints when dsSettings absent', $builtB3['questions'][0], 'likertPoints');
assert_absent_key('B3 synthesized q[1] omits likertPoints when dsSettings absent', $builtB3['questions'][1], 'likertPoints');

// ── Case B4: invalid dsSettings.likertPoints → omitted ─────────────────
$builtB4a = dts_build_questions_and_index($columnMetaB1, $rowsB1, ['likertPoints' => 1]);
$builtB4b = dts_build_questions_and_index($columnMetaB1, $rowsB1, ['likertPoints' => 99]);
$builtB4c = dts_build_questions_and_index($columnMetaB1, $rowsB1, ['likertPoints' => 'bad']);
assert_absent_key('B4a invalid dsSettings=1',     $builtB4a['questions'][0], 'likertPoints');
assert_absent_key('B4b invalid dsSettings=99',    $builtB4b['questions'][0], 'likertPoints');
assert_absent_key('B4c invalid dsSettings=bad',   $builtB4c['questions'][0], 'likertPoints');

// ── Case B5: backwards-compat — 2-arg call still works ────────────────
$builtB5 = dts_build_questions_and_index($columnMetaB1, $rowsB1);
assert_absent_key('B5 2-arg call omits likertPoints (no dsSettings supplied)', $builtB5['questions'][0], 'likertPoints');

// ── Case C: end-to-end seam — synthesized question (via dts) → _build_dataset
$builtC = dts_build_questions_and_index($columnMetaB1, $rowsB1, ['likertPoints' => 5]);
$responsesC = [
    ['id' => 1, 'submitted_at' => '2026-05-27 12:00:00', 'answers' => [
        $builtC['questions'][0]['id'] => 3,
        $builtC['questions'][1]['id'] => 4,
    ]],
    ['id' => 2, 'submitted_at' => '2026-05-27 12:01:00', 'answers' => [
        $builtC['questions'][0]['id'] => 2,
        $builtC['questions'][1]['id'] => 5,
    ]],
];
$dsC = relicheck_survey_build_dataset('C', $builtC['questions'], $responsesC, ['likertPoints' => 5]);
$likertVarsC = array_filter($dsC['variables'], fn($v) => in_array('likert', $v['types'] ?? [], true));
foreach ($likertVarsC as $v) {
    assert_eq('C uploaded-path seam — anchor_count on ' . $v['name'], $v['anchor_count'] ?? null, 5);
}

if ($failures) {
    echo "FAIL\n";
    foreach ($failures as $f) echo "  - " . $f . "\n";
    exit(1);
}
echo "OK — dts_anchor_count_propagation: per-question override > survey default > omitted; matrix single-level fallback; dataset-import bakes likertPoints; invalid values rejected; uploaded-path seam end-to-end\n";
