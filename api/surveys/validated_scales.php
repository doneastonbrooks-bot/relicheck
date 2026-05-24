<?php
// GET /api/surveys/validated_scales.php
// Returns the catalog of validated-scale starter surveys available to the
// current user.
//
// IMPORTANT: every item below is an original construct-faithful prompt
// written for ReliCheck. They are NOT paraphrases of specific lines from
// the cited instruments. The cited scales are pointed at as recommended
// validated sources for researchers who plan to publish; those researchers
// should replace the starter items below with the licensed validated
// wording from the original source. ReliCheck does not redistribute the
// copyrighted item text of MBI, MSPSS, UWES, BRS, GSE, MSLQ, PSS, UCLA-3,
// or any other proprietary instrument.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_method('GET');
    require_auth();
    json_out(['scales' => array_map('validated_scale_public_view', validated_scales())]);
}

/**
 * Public-facing summary used in the catalog list. Item bodies are returned
 * only when the user instantiates a scale (in from_validated.php), mirroring
 * the templates flow.
 */
function validated_scale_public_view(array $row): array
{
    $items = $row['survey']['questions'] ?? [];
    return [
        'key'              => $row['key'],
        'name'             => $row['name'],
        'construct'        => $row['construct'],
        'description'      => $row['description'],
        'item_count'       => count($items),
        'likert_count'     => count(array_filter($items, fn($q) => ($q['type'] ?? '') === 'likert')),
        'alpha_target'     => $row['alpha_target'],
        'recommended_n'    => $row['recommended_n'],
        'citation'         => $row['citation'],
        'license_note'     => $row['license_note'],
    ];
}

/**
 * Library of construct-aligned starter surveys. Items are original prompts
 * written for ReliCheck, not reproductions of any cited instrument's items.
 * Researchers planning publication should obtain the licensed wording from
 * the cited source and replace these starter items.
 */
function validated_scales(): array
{
    $note = 'Items are original prompts written for ReliCheck and aim at the same construct as the cited source. They are NOT the validated items. For publication, obtain the licensed wording from the cited source and replace these starter items.';

    return [
        // 1. Loneliness scaffold (informed by UCLA-3 short form)
        [
            'key'           => 'loneliness_scaffold',
            'name'          => 'Loneliness scaffold (UCLA-3 informed)',
            'construct'     => 'Loneliness',
            'description'   => 'Three-item short scaffold for loneliness on a 4-point frequency scale, sized for time-constrained surveys.',
            'alpha_target'  => '0.72 to 0.84',
            'recommended_n' => 100,
            'citation'      => 'Hughes, Waite, Hawkley, & Cacioppo (2004). A short scale for measuring loneliness in large surveys. Research on Aging, 26(6), 655 to 672.',
            'license_note'  => $note,
            'survey' => [
                'title'       => 'Loneliness Short Survey',
                'description' => 'Pick the option that best matches your experience over the past few weeks.',
                'settings'    => [
                    'likertPoints' => 4,
                    'likertLow'    => 'Hardly ever',
                    'likertHigh'   => 'Often',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Lately I have wished I had someone close I could talk to.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'In recent social settings I have felt like an outsider.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Even when other people are around, I have felt disconnected from them.',   'required' => true, 'reverse' => false],
                ],
            ],
        ],

        // 2. Bounce-back scaffold (informed by Brief Resilience Scale)
        [
            'key'           => 'resilience_scaffold',
            'name'          => 'Bounce-back scaffold (BRS informed)',
            'construct'     => 'Resilience',
            'description'   => 'Six-item scaffold capturing recovery from stressful events on a 5-point Likert scale, with three positively and three negatively worded items.',
            'alpha_target'  => '0.80 to 0.91',
            'recommended_n' => 100,
            'citation'      => 'Smith, Dalen, Wiggins, Tooley, Christopher, & Bernard (2008). The brief resilience scale: assessing the ability to bounce back. International Journal of Behavioral Medicine, 15(3), 194 to 200.',
            'license_note'  => $note,
            'survey' => [
                'title'       => 'Bounce-Back Survey',
                'description' => 'For each statement, indicate how strongly you agree or disagree right now.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'After something stressful, I usually find my footing pretty quickly.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Major setbacks tend to throw me off balance for a long time.',                        'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'A few days after a tough event I am usually back to my normal self.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When life hands me something difficult, moving past it is a struggle for me.',         'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Getting through hard periods has not been a major obstacle for me historically.',      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Bad experiences tend to weigh on me well after the event itself is over.',             'required' => true, 'reverse' => true ],
                ],
            ],
        ],

        // 3. General self-efficacy scaffold
        [
            'key'           => 'self_efficacy_scaffold',
            'name'          => 'General self-efficacy scaffold (GSE informed)',
            'construct'     => 'Self-efficacy',
            'description'   => 'Ten-item scaffold for general self-efficacy on a 4-point agreement scale.',
            'alpha_target'  => '0.75 to 0.90',
            'recommended_n' => 100,
            'citation'      => 'Schwarzer & Jerusalem (1995). Generalized Self-Efficacy scale. In Weinman, Wright, & Johnston (Eds.), Measures in health psychology: A user\'s portfolio (pp. 35 to 37).',
            'license_note'  => $note,
            'survey' => [
                'title'       => 'General Self-Efficacy Survey',
                'description' => 'For each statement, indicate how true it is of you in general.',
                'settings'    => [
                    'likertPoints' => 4,
                    'likertLow'    => 'Not at all true',
                    'likertHigh'   => 'Exactly true',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Hard problems generally yield when I put enough effort into them.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Even when others stand in my way, I can usually find a route to my goals.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Sticking with my plans and seeing them through tends to come naturally to me.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Surprises and unexpected events do not tend to throw me off.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When I land in a brand new situation, the skills I have usually carry me through.',          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Most problems I run into can be solved as long as I keep at them.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I trust myself to stay composed when things get rough.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When I am stuck, I can usually generate several different approaches to try.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Trouble does not prevent me from finding a way out.',                                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Whatever the day throws at me, I tend to manage it.',                                         'required' => true, 'reverse' => false],
                ],
            ],
        ],

        // 4. Perceived stress scaffold (PSS-10 informed)
        [
            'key'           => 'perceived_stress_scaffold',
            'name'          => 'Perceived stress scaffold (PSS-10 informed)',
            'construct'     => 'Perceived stress',
            'description'   => 'Ten-item scaffold for perceived stress on a 5-point frequency scale, mixing positively and negatively worded items.',
            'alpha_target'  => '0.78 to 0.91',
            'recommended_n' => 100,
            'citation'      => 'Cohen, Kamarck, & Mermelstein (1983). A global measure of perceived stress. Journal of Health and Social Behavior, 24(4), 385 to 396.',
            'license_note'  => $note,
            'survey' => [
                'title'       => 'Perceived Stress Survey',
                'description' => 'Pick the option that best matches your experience over the past month.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Never',
                    'likertHigh'   => 'Very often',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'How frequently lately have unexpected events caught you off guard and bothered you?',           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have important parts of life felt beyond your control?',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have stress and worry been on your mind?',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have you trusted yourself to handle a personal problem?',                  'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have you felt that things were unfolding the way you wanted?',             'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have you felt overwhelmed by everything you had to handle?',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have you kept small irritations from getting to you?',                     'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have you felt in control of your day to day life?',                        'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'How frequently lately have you been frustrated by things outside your influence?',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'How frequently lately has the load of responsibilities felt like just too much?',                'required' => true, 'reverse' => false],
                ],
            ],
        ],

        // 5. Academic self-efficacy scaffold (MSLQ informed)
        [
            'key'           => 'academic_self_efficacy_scaffold',
            'name'          => 'Academic self-efficacy scaffold (MSLQ informed)',
            'construct'     => 'Academic self-efficacy',
            'description'   => 'Eight-item scaffold for course-level academic self-efficacy on a 7-point Likert scale.',
            'alpha_target'  => '0.85 to 0.93',
            'recommended_n' => 100,
            'citation'      => 'Pintrich, Smith, Garcia, & McKeachie (1991). A manual for the use of the Motivated Strategies for Learning Questionnaire (MSLQ). National Center for Research to Improve Postsecondary Teaching and Learning.',
            'license_note'  => $note,
            'survey' => [
                'title'       => 'Academic Self-Efficacy Survey',
                'description' => 'For each statement, indicate how true it is for you in this course right now.',
                'settings'    => [
                    'likertPoints' => 7,
                    'likertLow'    => 'Not at all true of me',
                    'likertHigh'   => 'Very true of me',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I expect to earn a top grade by the end of this course.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Even the toughest material in this course is within my reach to learn.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Strong work on tests and assignments here is something I can deliver.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The skills this course is teaching are skills I can fully master.',                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Given how hard this course is, I still expect a strong outcome from my work.',         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Grasping the foundational ideas in this course is not a problem for me.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Picking up the material in this course is well within my capability.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The way I am studying for this course should produce good results.',                   'required' => true, 'reverse' => false],
                ],
            ],
        ],
    ];
}
