<?php
// GET /api/tests/templates.php
// Returns the catalog of starter test templates for the Test & Item Analysis
// Suite. Each template seeds a Test entity (Phase 71) with a structural
// answer key, item labels (skill tags), and pass threshold. Teachers
// substitute the placeholder key with their actual answers before delivery.
//
// Same call pattern as /api/surveys/templates.php: returns the list when
// hit directly as a GET, also exposes test_templates() as a require_once
// function for /api/tests/from_template.php and the suites helper.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_method('GET');
    require_auth();
    json_out(['templates' => test_templates()]);
}

/**
 * Curated starter tests. Each template's `test` block follows the create.php
 * payload shape so /api/tests/from_template.php can pass it through with
 * zero shape translation. Answer keys are intentionally a mix of A / B / C / D
 * so the structure is realistic; teachers must replace with their own keys.
 */
function test_templates(): array
{
    return [
        [
            'key'         => 'math_diagnostic',
            'name'        => 'Math diagnostic',
            'description' => 'Twenty mixed multiple-choice items spanning arithmetic, algebra, geometry, and word problems. Use as a baseline before instruction. Replace the placeholder answer key with your own items.',
            'test' => [
                'title'          => 'Math Diagnostic',
                'description'    => 'Diagnostic covering arithmetic, algebra, geometry, and word problems. Edit each item and the answer key to match your actual assessment.',
                'pass_threshold' => 60.00,
                'answer_key'     => ['B','D','A','C','B','D','A','B','C','A','B','D','C','A','D','B','A','C','B','D'],
                'item_labels'    => [
                    'Arithmetic','Arithmetic','Arithmetic','Arithmetic','Arithmetic',
                    'Algebra','Algebra','Algebra','Algebra','Algebra',
                    'Geometry','Geometry','Geometry','Geometry','Geometry',
                    'Word problems','Word problems','Word problems','Word problems','Word problems',
                ],
            ],
        ],
        [
            'key'         => 'reading_comprehension',
            'name'        => 'Reading comprehension check',
            'description' => 'Twelve passage-anchored items with distractors aligned to typical misreadings. Items split across main idea, inference, vocabulary in context, and text structure. Pair with a short reading passage of your choice.',
            'test' => [
                'title'          => 'Reading Comprehension Check',
                'description'    => 'Twelve items covering main idea, inference, vocabulary, and structure. Replace the placeholder key with your actual passage-based items.',
                'pass_threshold' => 70.00,
                'answer_key'     => ['B','A','D','C','B','A','D','B','C','A','D','B'],
                'item_labels'    => [
                    'Main idea','Main idea','Main idea',
                    'Inference','Inference','Inference',
                    'Vocabulary','Vocabulary','Vocabulary',
                    'Text structure','Text structure','Text structure',
                ],
            ],
        ],
        [
            'key'         => 'unit_end_quiz',
            'name'        => 'Unit-end quiz',
            'description' => 'Ten-item formative quiz at the end of a unit. Generic skill tags you can rename to the actual learning objectives.',
            'test' => [
                'title'          => 'Unit-End Quiz',
                'description'    => 'Short formative quiz. Replace the answer key and the skill-tag labels with the objectives for the unit you just taught.',
                'pass_threshold' => 70.00,
                'answer_key'     => ['A','C','B','D','A','C','B','D','A','C'],
                'item_labels'    => [
                    'Objective 1','Objective 1','Objective 1',
                    'Objective 2','Objective 2','Objective 2',
                    'Objective 3','Objective 3',
                    'Synthesis','Synthesis',
                ],
            ],
        ],
        [
            'key'         => 'concept_check',
            'name'        => 'Concept check (formative)',
            'description' => 'Five low-stakes items to verify whether a single concept has landed before you move on. Designed to take under five minutes.',
            'test' => [
                'title'          => 'Concept Check',
                'description'    => 'A quick five-item formative check. Edit each item to point at the specific concept you want to verify.',
                'pass_threshold' => 80.00,
                'answer_key'     => ['B','D','A','C','B'],
                'item_labels'    => ['Recall','Recall','Application','Application','Transfer'],
            ],
        ],
        [
            'key'         => 'mid_term_exam',
            'name'        => 'Mid-term exam',
            'description' => 'Twenty-five-item cumulative mid-term grouped into five skill ranges so the analytics rollup names which chapters students mastered.',
            'test' => [
                'title'          => 'Mid-Term Exam',
                'description'    => 'Cumulative mid-term. Replace each placeholder item with your course-specific content, and rename the skill tags to your chapter or unit names.',
                'pass_threshold' => 65.00,
                'answer_key'     => [
                    'A','B','C','D','A',
                    'B','C','D','A','B',
                    'C','D','A','B','C',
                    'D','A','B','C','D',
                    'A','B','C','D','A',
                ],
                'item_labels'    => [
                    'Chapter 1','Chapter 1','Chapter 1','Chapter 1','Chapter 1',
                    'Chapter 2','Chapter 2','Chapter 2','Chapter 2','Chapter 2',
                    'Chapter 3','Chapter 3','Chapter 3','Chapter 3','Chapter 3',
                    'Chapter 4','Chapter 4','Chapter 4','Chapter 4','Chapter 4',
                    'Chapter 5','Chapter 5','Chapter 5','Chapter 5','Chapter 5',
                ],
            ],
        ],
        [
            'key'         => 'final_exam',
            'name'        => 'Final exam',
            'description' => 'Forty-item end-of-course exam spanning the full course. Skill tags rolled into ten thematic areas to keep the analytics report focused.',
            'test' => [
                'title'          => 'Final Exam',
                'description'    => 'End-of-course exam. Edit the answer key, items, and tag labels to match your course. The default thematic tags give the analytics report a sensible default rollup.',
                'pass_threshold' => 65.00,
                'answer_key'     => [
                    'A','B','C','D','A','B','C','D',
                    'A','B','C','D','A','B','C','D',
                    'A','B','C','D','A','B','C','D',
                    'A','B','C','D','A','B','C','D',
                    'A','B','C','D','A','B','C','D',
                ],
                'item_labels'    => [
                    'Theme 1','Theme 1','Theme 1','Theme 1',
                    'Theme 2','Theme 2','Theme 2','Theme 2',
                    'Theme 3','Theme 3','Theme 3','Theme 3',
                    'Theme 4','Theme 4','Theme 4','Theme 4',
                    'Theme 5','Theme 5','Theme 5','Theme 5',
                    'Theme 6','Theme 6','Theme 6','Theme 6',
                    'Theme 7','Theme 7','Theme 7','Theme 7',
                    'Theme 8','Theme 8','Theme 8','Theme 8',
                    'Theme 9','Theme 9','Theme 9','Theme 9',
                    'Theme 10','Theme 10','Theme 10','Theme 10',
                ],
            ],
        ],
        [
            'key'         => 'skill_mastery',
            'name'        => 'Skill mastery assessment',
            'description' => 'Fifteen items, three per skill across five named skills. The analytics report can show per-skill mastery percentages cleanly.',
            'test' => [
                'title'          => 'Skill Mastery Assessment',
                'description'    => 'Standards-aligned items grouped three per skill. Rename each skill tag to your actual standard codes (CCSS, NGSS, state code, etc.).',
                'pass_threshold' => 75.00,
                'answer_key'     => [
                    'A','B','C',
                    'B','C','D',
                    'C','D','A',
                    'D','A','B',
                    'A','B','C',
                ],
                'item_labels'    => [
                    'Skill 1','Skill 1','Skill 1',
                    'Skill 2','Skill 2','Skill 2',
                    'Skill 3','Skill 3','Skill 3',
                    'Skill 4','Skill 4','Skill 4',
                    'Skill 5','Skill 5','Skill 5',
                ],
            ],
        ],
        [
            'key'         => 'ap_style_practice',
            'name'        => 'AP-style practice exam',
            'description' => 'Thirty-item practice exam styled after AP and similar standardized tests. Mix of recall, application, and analysis items grouped into three sections.',
            'test' => [
                'title'          => 'AP-Style Practice Exam',
                'description'    => 'Practice exam in the AP / standardized-test multiple-choice format. Replace each item with your discipline-specific content; the section tags help students see where they need more work.',
                'pass_threshold' => 60.00,
                'answer_key'     => [
                    'A','B','C','D','A','B','C','D','A','B',
                    'C','D','A','B','C','D','A','B','C','D',
                    'A','B','C','D','A','B','C','D','A','B',
                ],
                'item_labels'    => [
                    'Section I (Recall)','Section I (Recall)','Section I (Recall)','Section I (Recall)','Section I (Recall)',
                    'Section I (Recall)','Section I (Recall)','Section I (Recall)','Section I (Recall)','Section I (Recall)',
                    'Section II (Application)','Section II (Application)','Section II (Application)','Section II (Application)','Section II (Application)',
                    'Section II (Application)','Section II (Application)','Section II (Application)','Section II (Application)','Section II (Application)',
                    'Section III (Analysis)','Section III (Analysis)','Section III (Analysis)','Section III (Analysis)','Section III (Analysis)',
                    'Section III (Analysis)','Section III (Analysis)','Section III (Analysis)','Section III (Analysis)','Section III (Analysis)',
                ],
            ],
        ],
    ];
}
