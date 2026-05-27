<?php
// Multi-Rater Evidence Intake Wizard config (360).
// Pass 1: mirrors the Survey config (since 360 was being served by the
// same data_upload app pre-refactor). Pass 3 adds the Rater & Competency
// Structure step (the collapsed Map Raters + Map Competencies +
// Confidentiality threshold gate) per [[relicheck-evidence-intake]].

return [
  'slug'              => '360',
  'wizard_name'       => 'Multi-Rater Evidence Intake Wizard',
  'short_name'        => 'Data Upload',
  'description'       => 'Two-step intake: drag, paste, or choose a file, then confirm each variable\'s role. Pass 3 will add the Rater & Competency Structure step with the confidentiality threshold gate.',
  'detector_kind'     => 'survey',

  'sample_label'        => 'Use sample data',
  'sample_label_loaded' => 'sample data (Leadership 360, Cohort 4)',
  'paste_placeholder'   => 'ratee_id, rater_id, rater_group, competency_1, competency_2, comment' . "\n" .
                           'R001, X023, peer, 4, 5, "Clear communicator."' . "\n" .
                           'R001, X024, self, 3, 4, "I could be more decisive."',

  'sample_csv' =>
    "ratee_id,rater_id,rater_group,decision_making,communication,coaching,strategy,execution,comment,response_date\n" .
    "R001,X011,self,3,4,3,4,4,\"Could be more decisive in cross-team calls.\",2026-04-12\n" .
    "R001,X023,peer,4,5,4,4,4,\"Clear communicator, brings calm to debate.\",2026-04-12\n" .
    "R001,X045,manager,4,4,5,4,5,\"Great coach; could push back more in strategy.\",2026-04-13\n" .
    "R001,X067,direct_report,5,5,5,3,4,\"Best manager I have had at the company.\",2026-04-13\n" .
    "R002,X012,self,4,3,4,5,3,\"Strong on strategy, working on execution.\",2026-04-14\n" .
    "R002,X024,peer,3,3,3,4,3,\"Strategy is solid; execution lags at the team level.\",2026-04-14\n" .
    "R002,X046,manager,4,4,4,5,3,\"Excellent strategic thinker.\",2026-04-15\n" .
    "R002,X068,direct_report,4,4,5,4,3,\"Sets vision well; details sometimes get dropped.\",2026-04-15\n",

  'teaching_cards' => [
    ['tag' => 'Rater groups',  'fam' => 'reliability',
     'title' => 'Why rater identity has to be locked before any group report',
     'sub'   => '4 minute read, methodology'],
    ['tag' => 'Confidentiality', 'fam' => 'validity',
     'title' => 'How a minimum-rater threshold protects feedback',
     'sub'   => '3 minute read, ethics'],
  ],

  'steps' => [
    [
      'key'              => 'upload',
      'num'              => 1,
      'title'            => 'Bring in your data',
      'subtitle'         => 'Drag a file, paste from a spreadsheet, or choose a file from your computer. One row per rating (ratee + rater + competency scores).',
      'dropzone_primary' => 'Drop your data file here',
      'dropzone_meta'    => 'CSV, TSV, or tab-separated text  ·  up to 50 MB',
    ],
    [
      'key'            => 'map',
      'num'            => 2,
      'title'          => 'Confirm each variable\'s role',
      'subtitle'       => 'Check the data type for each variable. Pass 3 will add a dedicated Rater & Competency Structure step with a confidentiality threshold gate before any group report renders.',
      'sample_header'  => 'Sample values',
      'column_keys'    => ['id', 'categorical', 'likert', 'numeric', 'open', 'date'],
      'column_labels'  => ['ID', 'Categorical', 'Likert / Scale', 'Numeric', 'Open-ended', 'Date / Time'],
      'continue_label' => 'Continue to analysis',
    ],
  ],
];
