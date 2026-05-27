<?php
// TIA Studio · Open-Ended Summary config.
// Language tuned for constructed-response items on tests. Scoring
// against an answer key is a separate app (Open-Ended Scoring); this
// app is the descriptive layer (how many wrote, length, common words).

return [
  'slug'              => 'tia',
  'app_name'          => 'Constructed-Response Summary',
  'short_name'        => 'Constructed-Response Summary',
  'description'       => 'How students wrote their constructed responses: how many attempted each item, response length, common language, and exemplar answers. Scoring against an answer key lives in Open-Ended Scoring.',

  'engine_config' => [
    'kind_label_long'    => 'Constructed responses',
    'kind_label_short'   => 'Constructed',
    'field_noun'         => 'constructed-response item',
    'field_noun_plural'  => 'constructed-response items',
    'answer_noun'        => 'response',
    'answer_noun_plural' => 'responses',
    'interp_lead_strong' => 'Students wrote substantive responses across items.',
    'interp_lead_weak'   => 'Few students attempted the constructed-response items.',
    'interp_lead_none'   => 'This test has no constructed-response items.',
  ],

  'teaching_cards' => [
    ['tag' => 'Test Items', 'fam' => 'reliability',
     'title' => 'What a non-response tells you about an item',
     'sub'   => '4 minute read, methodology'],
    ['tag' => 'Scoring',    'fam' => 'validity',
     'title' => 'Length vs quality in constructed responses',
     'sub'   => '5 minute read, item analysis'],
  ],
];
