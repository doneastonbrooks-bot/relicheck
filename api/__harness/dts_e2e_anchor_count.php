<?php
// End-to-end check for KNOWN_ISSUES.md §4 item #4 (per-item anchor metadata).
//
// Demonstrates the unlock: same dataset, two emit modes.
//   Pre-#4 (control):  no settings.likertPoints anywhere → no v.anchor_count.
//     Engine should skip §4G subs 1/2/4 and emit §4D's
//     `scale_range_normalization_unavailable_using_absolute_threshold`
//     diagnostic.
//   Post-#4 (activate): settings.likertPoints=5 propagated through the same
//     transform chain → v.anchor_count=5 on every Likert. Engine should
//     activate the same three §4G subs and stop emitting the §4D
//     normalization-fallback diagnostic.
//
// Demographic columns are surfaced via config.demographic_columns (so the
// §4D fairness half runs at all — item #7 is out of scope here; we inject
// the columns at the harness boundary to exercise the normalization seam).
//
// Run via:  php api/__harness/dts_e2e_anchor_count.php
//           then: node api/__harness/dts_e2e_anchor_count_engine.js

declare(strict_types=1);
require_once __DIR__ . '/../_dataset_to_survey.php';
require_once __DIR__ . '/../surveys/_build_dataset.php';

// Two 4-item Likert scales + a gender demographic column.
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

// 120 rows so the §4D fairness pass gets ≥30 per demographic group.
$rows = [];
mt_srand(7);
for ($i = 0; $i < 120; $i++) {
    $engBase = mt_rand(1, 5);
    $belBase = mt_rand(1, 5);
    $jit = function ($b) { $v = $b + (mt_rand(0, 2) - 1); return max(1, min(5, $v)); };
    $rows[] = [
        $engBase, $jit($engBase), $jit($engBase), $jit($engBase),
        $belBase, $jit($belBase), $jit($belBase), $jit($belBase),
        ($i % 2) ? 'F' : 'M',
    ];
}

function build_dataset(array $columnMeta, array $rows, array $dsSettings, ?int $surveyLikertPoints): array
{
    $built     = dts_build_questions_and_index($columnMeta, $rows, $dsSettings);
    $questions = $built['questions'];
    $colIndex  = $built['col_index'];

    $responses = [];
    foreach ($rows as $rowIdx => $row) {
        $answers = dts_row_to_answers($row, $colIndex);
        $responses[] = [
            'id'           => $rowIdx + 1,
            'submitted_at' => '2026-05-27 12:00:00',
            'answers'      => $answers,
        ];
    }

    $settings = [];
    if ($surveyLikertPoints !== null) $settings['likertPoints'] = $surveyLikertPoints;

    return relicheck_survey_build_dataset('anchor-count-e2e', $questions, $responses, $settings);
}

// ── Control: pre-#4 emit (no likertPoints anywhere) ────────────────────
$dsControl = build_dataset($columnMeta, $rows, [], null);

// ── Treatment: post-#4 emit (settings.likertPoints=5) ──────────────────
$dsTreatment = build_dataset($columnMeta, $rows, ['likertPoints' => 5], 5);

// ── Sanity-check: assert emit shape before handing to the engine ───────
$failures = [];
function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual !== $expected) {
        $failures[] = $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}

$controlLikert   = array_filter($dsControl['variables'],   fn($v) => in_array('likert', $v['types'] ?? [], true));
$treatmentLikert = array_filter($dsTreatment['variables'], fn($v) => in_array('likert', $v['types'] ?? [], true));

foreach ($controlLikert as $v) {
    assert_eq('Control ' . $v['name'] . ' omits anchor_count', array_key_exists('anchor_count', $v), false);
}
foreach ($treatmentLikert as $v) {
    assert_eq('Treatment ' . $v['name'] . ' carries anchor_count=5', $v['anchor_count'] ?? null, 5);
}

if ($failures) {
    echo "FAIL — pre-flight emit checks:\n";
    foreach ($failures as $f) echo "  - " . $f . "\n";
    exit(1);
}

// Inject demographic-column config (out-of-scope #7 surrogate — we want to
// run §4D's normalization path, which only fires when fairness isn't
// skipped).
$genderVar = null;
foreach ($dsControl['variables'] as $v) if ($v['label'] === 'gender') { $genderVar = $v['name']; break; }
if ($genderVar === null) { echo "FAIL — could not locate gender variable in dataset\n"; exit(1); }
$dsControl['config']['demographic_columns']   = [$genderVar];
$dsTreatment['config']['demographic_columns'] = [$genderVar];

$outCtl = __DIR__ . '/dts_e2e_anchor_count_control.json';
$outTrt = __DIR__ . '/dts_e2e_anchor_count_treatment.json';
file_put_contents($outCtl, json_encode($dsControl,   JSON_UNESCAPED_UNICODE));
file_put_contents($outTrt, json_encode($dsTreatment, JSON_UNESCAPED_UNICODE));

echo "OK — emit shapes verified (control omits anchor_count on " . count($controlLikert)
   . " Likert items; treatment carries anchor_count=5 on all)\n";
echo "Control dataset:   $outCtl\n";
echo "Treatment dataset: $outTrt\n";
echo "Next: node api/__harness/dts_e2e_anchor_count_engine.js\n";
