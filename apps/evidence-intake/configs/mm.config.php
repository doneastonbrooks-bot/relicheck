<?php
// Mixed-Methods Evidence Intake Wizard config.
// Pass 1: mirrors the Survey config (since MM was being served by the
// same data_upload app pre-refactor). Pass 3 will add the Link Evidence
// step, codebook upload, qual transcripts, and the "generate quant from
// themes" affordance per [[relicheck-evidence-intake]].

return [
  'slug'              => 'mm',
  'wizard_name'       => 'Mixed-Methods Evidence Intake Wizard',
  'short_name'        => 'Data Upload',
  'description'       => 'Two-step intake: drag, paste, or choose a file, then confirm each variable\'s role. Pass 3 will add evidence linking, codebook upload, and quant-from-themes.',
  'detector_kind'     => 'survey',

  'sample_label'        => 'Use sample data',
  'sample_label_loaded' => 'sample data (Belonging & Retention Study)',
  'paste_placeholder'   => 'participant_id, age, role, belonging_score, themes_count, comment' . "\n" .
                           '001, 34, IC, 4, 3, "Felt supported by manager."' . "\n" .
                           '002, 28, Manager, 3, 5, "Need clearer expectations."',

  'sample_csv' =>
    "participant_id,age,role,department,belonging_score,voice_score,recommend,interview_date,interview_excerpt\n" .
    "001,34,IC,Engineering,4,5,9,2026-04-12,\"Felt supported by manager and peers when ramping in.\"\n" .
    "002,28,IC,Operations,3,3,6,2026-04-12,\"Expectations are unclear; deliverables shift mid-quarter.\"\n" .
    "003,45,Manager,Marketing,5,5,10,2026-04-13,\"The team is the strongest part of my work.\"\n" .
    "004,31,IC,Engineering,4,4,8,2026-04-13,\"Onboarding could be tighter but tooling is excellent.\"\n" .
    "005,52,Manager,Sales,2,2,4,2026-04-14,\"Leadership communication is inconsistent across regions.\"\n" .
    "006,29,IC,People,4,5,9,2026-04-14,\"Mission and people both pull me in.\"\n" .
    "007,38,IC,Engineering,5,4,8,2026-04-15,\"Pace is fast and the autonomy is what keeps me here.\"\n" .
    "008,41,Manager,Operations,3,3,5,2026-04-15,\"Cross-team visibility would help me coach better.\"\n",

  'teaching_cards' => [
    ['tag' => 'Integration', 'fam' => 'reliability',
     'title' => 'When quant and qual answer the same question, and when they don\'t',
     'sub'   => '5 minute read, mixed methods'],
    ['tag' => 'Coding',      'fam' => 'validity',
     'title' => 'Reading qual columns vs structured themes',
     'sub'   => '3 minute read, data prep'],
  ],

  'steps' => [
    [
      'key'              => 'upload',
      'num'              => 1,
      'title'            => 'Bring in your data',
      'subtitle'         => 'Drag a file, paste from a spreadsheet, or choose a file from your computer. CSV, TSV, or pasted tab-separated text. Quant and qual columns can share one file for now.',
      'dropzone_primary' => 'Drop your data file here',
      'dropzone_meta'    => 'CSV, TSV, or tab-separated text  ·  up to 50 MB',
    ],
    [
      'key'            => 'map',
      'num'            => 2,
      'title'          => 'Confirm each variable\'s role',
      'subtitle'       => 'Check the data type for each variable. A column can play more than one role: a quant scale can be ordinal and numeric, a quote column can be open-ended and categorical if it has codes attached.',
      'sample_header'  => 'Sample values',
      'column_keys'    => ['id', 'categorical', 'likert', 'numeric', 'open', 'date'],
      'column_labels'  => ['ID', 'Categorical', 'Likert / Scale', 'Numeric', 'Open-ended', 'Date / Time'],
      'continue_label' => 'Continue to analysis',
    ],
  ],
];
