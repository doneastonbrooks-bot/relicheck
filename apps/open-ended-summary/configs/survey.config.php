<?php
// Survey Studio · Open-Ended Summary config.
// Language tuned for survey open-ended feedback questions.

return [
  'slug'              => 'survey',
  'app_name'          => 'Open-Ended Feedback Summary',
  'short_name'        => 'Open-Ended Summary',
  'description'       => 'How respondents wrote in their open-ended answers: how many wrote something, how much, what they said most, and which responses stand in for the rest.',

  // Strings injected as window.OPEN_ENDED_CONFIG and read by the engine.
  'engine_config' => [
    'kind_label_long'    => 'Open-ended responses',
    'kind_label_short'   => 'Open-ended',
    'field_noun'         => 'open-ended field',
    'field_noun_plural'  => 'open-ended fields',
    'answer_noun'        => 'response',
    'answer_noun_plural' => 'responses',
    'interp_lead_strong' => 'Respondents wrote freely.',
    'interp_lead_weak'   => 'Open-ended response is light.',
    'interp_lead_none'   => 'This survey has no open-ended fields.',
  ],

  'teaching_cards' => [
    ['tag' => 'Qualitative', 'fam' => 'reliability',
     'title' => 'Reading short open-ends: what is signal, what is noise',
     'sub'   => '4 minute read, methodology'],
    ['tag' => 'Themes',      'fam' => 'validity',
     'title' => 'When response volume can carry a theme',
     'sub'   => '3 minute read, qualitative coding'],
    ['tag' => 'Reporting',   'fam' => 'reporting',
     'title' => 'Quoting respondents without overclaiming',
     'sub'   => '5 minute read, publication'],
  ],
];
