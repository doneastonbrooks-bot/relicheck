<?php
// MM Studio · Open-Ended Summary config.
// Language tuned for mixed-methods qualitative responses. This app is the
// descriptive layer; deeper coding lives in the MM Theme Analysis app.

return [
  'slug'              => 'mm',
  'app_name'          => 'Qualitative Response Summary',
  'short_name'        => 'Open-Ended Summary',
  'description'       => 'Descriptive read on the qualitative responses: volume, length, repeated language, and representative answers. Use this before Theme Analysis to confirm the data is rich enough to code.',

  'engine_config' => [
    'kind_label_long'    => 'Qualitative responses',
    'kind_label_short'   => 'Qualitative',
    'field_noun'         => 'qualitative item',
    'field_noun_plural'  => 'qualitative items',
    'answer_noun'        => 'response',
    'answer_noun_plural' => 'responses',
    'interp_lead_strong' => 'There is enough qualitative material to code.',
    'interp_lead_weak'   => 'The qualitative material is thin for coding.',
    'interp_lead_none'   => 'This dataset has no qualitative items.',
  ],

  'teaching_cards' => [
    ['tag' => 'Mixed Methods', 'fam' => 'reliability',
     'title' => 'When descriptive open-ends are enough for coding',
     'sub'   => '5 minute read, methodology'],
    ['tag' => 'Coding',        'fam' => 'validity',
     'title' => 'Reading frequency before reading meaning',
     'sub'   => '4 minute read, qualitative analysis'],
  ],
];
