<?php
// Assessment Evidence Intake Wizard config (TIA).
// Student-row data plus an answer key per item. Step 3 (answer key) is
// TIA-specific. Pass 1: preserves the previous data_upload_test behavior.
// Bloom's alignment, rubric upload, and selected-vs-constructed-response
// distinction land in Pass 3.

return [
  'slug'              => 'tia',
  'wizard_name'       => 'Assessment Evidence Intake Wizard',
  'short_name'        => 'Test Data Upload',
  'description'       => 'Three-step intake for test data: bring in the student-by-item table, identify each column\'s role, then enter the answer key (correct answer + max points per item).',
  'detector_kind'     => 'tia',

  'sample_label'        => 'Use sample test (8 items)',
  'sample_label_loaded' => 'sample test (8 items, 20 students)',
  'paste_placeholder'   => 'student_id, gender, grade, item_1, item_2, ...' . "\n" .
                           '001, F, 8, A, B, C, ...' . "\n" .
                           '002, M, 8, B, B, A, ...',

  'sample_csv' =>
    "student_id,gender,grade,item_1,item_2,item_3,item_4,item_5,item_6,item_7,item_8,total_score,test_date\n" .
    "001,F,8,A,B,C,1,A,D,B,2,15,2026-04-12\n" .
    "002,M,8,B,B,A,1,A,C,B,2,12,2026-04-12\n" .
    "003,F,8,A,C,C,0,A,D,A,2,14,2026-04-12\n" .
    "004,F,8,A,B,C,1,B,D,B,3,17,2026-04-12\n" .
    "005,M,8,C,B,C,1,A,D,B,2,15,2026-04-12\n" .
    "006,M,8,A,A,C,0,A,B,B,1,11,2026-04-13\n" .
    "007,F,8,A,B,B,1,A,D,B,2,14,2026-04-13\n" .
    "008,F,8,A,B,C,1,A,D,C,2,15,2026-04-13\n" .
    "009,M,8,B,B,C,1,C,D,B,2,13,2026-04-13\n" .
    "010,F,8,A,B,A,0,A,D,B,3,15,2026-04-13\n" .
    "011,M,8,A,B,C,1,A,D,A,1,13,2026-04-13\n" .
    "012,F,8,A,B,C,1,A,D,B,2,16,2026-04-14\n" .
    "013,M,8,D,B,C,1,A,D,B,2,14,2026-04-14\n" .
    "014,F,8,A,B,C,0,A,D,B,2,14,2026-04-14\n" .
    "015,M,8,A,C,C,1,A,A,B,2,13,2026-04-14\n" .
    "016,F,8,A,B,C,1,A,D,B,3,17,2026-04-14\n" .
    "017,M,8,A,B,D,1,A,D,B,2,15,2026-04-15\n" .
    "018,F,8,A,B,C,1,A,D,B,2,16,2026-04-15\n" .
    "019,F,8,B,B,C,1,A,D,B,2,14,2026-04-15\n" .
    "020,M,8,A,A,C,1,A,D,C,2,13,2026-04-15\n",

  'teaching_cards' => [
    ['tag' => 'Scoring',    'fam' => 'reliability',
     'title' => 'Why the answer key drives every item statistic',
     'sub'   => '3 minute read, methodology'],
    ['tag' => 'Item types', 'fam' => 'validity',
     'title' => 'MC vs constructed-response: scoring choices and when they matter',
     'sub'   => '4 minute read, item analysis'],
  ],

  'steps' => [
    [
      'key'              => 'upload',
      'num'              => 1,
      'title'            => 'Bring in your test data',
      'subtitle'         => 'One row per student, one column per item (plus any demographics, total scores, or test dates). CSV, TSV, or pasted tab-separated text.',
      'dropzone_primary' => 'Drop your test file here',
      'dropzone_meta'    => 'CSV, TSV, or tab-separated text  ·  up to 50 MB',
    ],
    [
      'key'            => 'map',
      'num'            => 2,
      'title'          => 'Identify each column\'s role',
      'subtitle'       => 'Tell us which columns are student identifiers, demographics, item responses, or a precomputed total. Items get scored in step three.',
      'sample_header'  => 'Sample values',
      'column_keys'    => ['id', 'categorical', 'numeric', 'item', 'total', 'date'],
      'column_labels'  => ['ID', 'Categorical', 'Numeric', 'Item Response', 'Total Score', 'Date / Time'],
      'continue_label' => 'Continue to answer key',
    ],
    [
      'key'            => 'answer_key',
      'num'            => 3,
      'title'          => 'Enter the answer key',
      'subtitle'       => 'For each item, enter the correct answer and the maximum points. We pre-fill the most common student answer as a guess; you can change it. Items can be multi-point if they\'re scored with a rubric.',
      'sample_header'  => 'Student answers',
      'continue_label' => 'Score and continue',
      'item_types'     => [
        ['key' => 'mc',  'label' => 'MC'],
        ['key' => 'tf',  'label' => 'T/F'],
        ['key' => 'cr',  'label' => 'Constructed'],
        ['key' => 'rub', 'label' => 'Rubric'],
      ],
    ],
  ],
];
