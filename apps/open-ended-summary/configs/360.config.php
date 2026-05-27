<?php
// 360 Studio · Open-Ended Summary config.
// Language tuned for written comments by raters in a 360 feedback
// instrument. The deeper rater-pattern read (per rater group, per
// ratee) lives in the Comment Theme app; this is the universal
// descriptive layer across all comments in the dataset.

return [
  'slug'              => '360',
  'app_name'          => 'Comment Summary',
  'short_name'        => 'Comment Summary',
  'description'       => 'How raters wrote their open-ended comments: how many commented, how much, common language, and exemplar quotes. Per-rater-group and per-ratee patterns live in Comment Theme.',

  'engine_config' => [
    'kind_label_long'    => 'Open-ended comments',
    'kind_label_short'   => 'Comments',
    'field_noun'         => 'comment field',
    'field_noun_plural'  => 'comment fields',
    'answer_noun'        => 'comment',
    'answer_noun_plural' => 'comments',
    'interp_lead_strong' => 'Raters wrote substantively.',
    'interp_lead_weak'   => 'Comment volume is light.',
    'interp_lead_none'   => 'This 360 has no open-ended comment fields.',
  ],

  'teaching_cards' => [
    ['tag' => 'Confidentiality', 'fam' => 'reliability',
     'title' => 'When a comment can identify the rater',
     'sub'   => '4 minute read, methodology'],
    ['tag' => 'Comments',        'fam' => 'validity',
     'title' => 'Reading comments alongside competency scores',
     'sub'   => '5 minute read, interpretation'],
  ],
];
