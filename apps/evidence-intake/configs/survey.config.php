<?php
// Survey Evidence Intake Wizard config.
// Respondent-row data with mixed types (Likert, open-ended, demographic).
// Pass 1: preserves the previous data_upload behavior. Context + Readiness
// steps land in Pass 2.

return [
  'slug'              => 'survey',
  'wizard_name'       => 'Survey Evidence Intake Wizard',
  'short_name'        => 'Data Upload',
  'description'       => 'Two-step intake: drag, paste, or choose a file, then confirm each variable\'s role (ID, Categorical, Likert/Scale, Numeric, Open-ended, Date) via checkboxes.',
  'detector_kind'     => 'survey',

  'sample_label'        => 'Use sample data',
  'sample_label_loaded' => 'sample data (Workplace Equity Survey)',
  'paste_placeholder'   => 'respondent_id, age, gender, role_score, openended_response' . "\n" .
                           '001, 34, Female, 4, "Good support from manager."' . "\n" .
                           '002, 28, Male, 3, "Long meetings."',

  'sample_csv' =>
    "respondent_id,age,gender,department,role_score,belonging_score,recommend_likelihood,response_date,openended_response\n" .
    "001,34,Female,Engineering,4,5,9,2026-04-12,\"Good support from manager but workload is heavy.\"\n" .
    "002,28,Male,Operations,3,3,6,2026-04-12,\"Clear expectations would help.\"\n" .
    "003,45,Female,Marketing,5,5,10,2026-04-13,\"I really enjoy the team.\"\n" .
    "004,31,Non-binary,Engineering,4,4,8,2026-04-13,\"The new tooling is great. Onboarding could be tighter.\"\n" .
    "005,52,Male,Sales,2,2,4,2026-04-14,\"Communication from leadership is inconsistent.\"\n" .
    "006,29,Female,People,4,5,9,2026-04-14,\"Strong mission, good people.\"\n" .
    "007,38,Male,Engineering,5,4,8,2026-04-15,\"Pace is fast but rewarding.\"\n" .
    "008,41,Female,Operations,3,3,5,2026-04-15,\"Need more cross-team visibility.\"\n",

  'teaching_cards' => [
    ['tag' => 'Variables', 'fam' => 'reliability',
     'title' => 'Why declaring variable roles up front matters',
     'sub'   => '2 minute read, methodology'],
    ['tag' => 'Cleaning',  'fam' => 'validity',
     'title' => 'What to do when the same column carries two roles',
     'sub'   => '4 minute read, data prep'],
  ],

  'steps' => [
    [
      'key'              => 'upload',
      'num'              => 1,
      'title'            => 'Bring in your data',
      'subtitle'         => 'Drag a file, paste from a spreadsheet, or choose a file from your computer. CSV, TSV, or pasted tab-separated text.',
      'dropzone_primary' => 'Drop your data file here',
      'dropzone_meta'    => 'CSV, TSV, or tab-separated text  ·  up to 50 MB',
    ],
    [
      'key'            => 'map',
      'num'            => 2,
      'title'          => 'Confirm each variable\'s role',
      'subtitle'       => 'Check the data type for each variable. A variable can play more than one role: an ID column can also be categorical, a Likert can be both ordinal and numeric. Defaults come from a quick auto-detect.',
      'sample_header'  => 'Sample values',
      'column_keys'    => ['id', 'categorical', 'likert', 'numeric', 'open', 'date'],
      'column_labels'  => ['ID', 'Categorical', 'Likert / Scale', 'Numeric', 'Open-ended', 'Date / Time'],
      'continue_label' => 'Continue to analysis',
    ],
  ],
];
