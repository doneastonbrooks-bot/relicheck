<?php
// GET /api/surveys/templates.php
// Returns the catalog of starter templates available to the current user.
// Templates are built into the API for now (no DB rows). New users on the
// free tier can use them too; templates are not paywalled.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

// Only handle the HTTP request when this file is the entry point.
// Other endpoints (e.g. duplicate.php) require_once this file just to
// pull in survey_templates(); they should NOT trigger the GET handler.
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_method('GET');
    require_auth();
    json_out(['templates' => survey_templates()]);
}

/**
 * Generic, research-friendly starter templates. Items are paraphrased
 * generic statements covering each construct - they are not the
 * copyrighted original instruments (MBI, MSPSS, UWES). Researchers should
 * substitute validated items from their preferred source for publication.
 */
function survey_templates(): array
{
    return [
        [
            'key'         => 'workplace_engagement',
            'name'        => 'Workplace engagement (starter)',
            'description' => 'Six items capturing energy, dedication, and absorption at work on a 5-point agreement scale.',
            'survey' => [
                'title'       => 'Workplace Engagement Survey',
                'description' => 'Please rate how strongly you agree with each statement about your work right now.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I feel energized when starting a new task at work.',                         'required' => true,  'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I find my work meaningful.',                                                'required' => true,  'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I lose track of time when fully absorbed in my work.',                       'required' => true,  'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My job leaves me feeling drained.',                                          'required' => true,  'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'I look forward to coming to work most days.',                                'required' => true,  'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would describe myself as enthusiastic about my work.',                     'required' => true,  'reverse' => false],
                    ['type' => 'single', 'prompt' => 'How long have you been in your current role?', 'required' => false,
                        'options' => ['Less than 1 year', '1-3 years', '3-5 years', 'More than 5 years']],
                    ['type' => 'open',   'prompt' => 'In a sentence, what would make your work more engaging?', 'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'burnout_general',
            'name'        => 'Burnout indicators (starter)',
            'description' => 'Nine items spanning emotional exhaustion, cynicism, and professional efficacy on a 7-point frequency scale. Adjust items to match validated instruments before publishing.',
            'survey' => [
                'title'       => 'Burnout Indicators Survey',
                'description' => 'For each statement, indicate how often you have felt this way over the last month.',
                'settings'    => [
                    'likertPoints' => 7,
                    'likertLow'    => 'Never',
                    'likertHigh'   => 'Daily',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I feel emotionally drained from my work.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel used up at the end of a working day.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Working all day is really a strain for me.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have become less interested in my work since I started this job.',         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I doubt the significance of my work.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have become more cynical about whether my work contributes anything.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can effectively solve the problems that arise in my work.',                'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'In my opinion, I am good at my job.',                                        'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'I feel I make an effective contribution to my organization.',                'required' => true, 'reverse' => true ],
                    ['type' => 'open',   'prompt' => 'Anything else you want us to know?',                                         'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'perceived_support',
            'name'        => 'Perceived social support (starter)',
            'description' => 'Twelve items covering support from family, friends, and a significant other on a 7-point scale.',
            'survey' => [
                'title'       => 'Perceived Social Support Survey',
                'description' => 'For each statement, indicate how strongly you agree or disagree right now.',
                'settings'    => [
                    'likertPoints' => 7,
                    'likertLow'    => 'Very strongly disagree',
                    'likertHigh'   => 'Very strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'There is a special person who is around when I am in need.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'There is a special person with whom I can share joys and sorrows.',           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have a special person who is a real source of comfort to me.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'There is a special person in my life who cares about my feelings.',           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My family really tries to help me.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I get the emotional help and support I need from my family.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My family is willing to help me make decisions.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can talk about my problems with my family.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My friends really try to help me.',                                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can count on my friends when things go wrong.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have friends with whom I can share my joys and sorrows.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can talk about my problems with my friends.',                               'required' => true, 'reverse' => false],
                ],
            ],
        ],
        [
            'key'         => 'course_evaluation',
            'name'        => 'Course evaluation (starter)',
            'description' => 'Eight items covering instructor preparation, clarity, feedback, pacing, and overall quality on a 5-point agreement scale. Designed for end-of-term student feedback.',
            'survey' => [
                'title'       => 'Course Evaluation Survey',
                'description' => 'Please rate how strongly you agree with each statement about this course.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The instructor was clearly prepared for class.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The course objectives were clearly explained.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Assignments helped me learn the material.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The instructor provided helpful feedback on my work.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The pace of the course was appropriate for me.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I learned what I expected to learn in this course.',                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this course to others.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, this was a high-quality course.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What was the most valuable part of this course?',                            'required' => false],
                    ['type' => 'open',   'prompt' => 'What could be improved about this course?',                                  'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'student_belonging',
            'name'        => 'Student belonging (starter)',
            'description' => 'Seven items capturing sense of belonging, social connection, and identity safety in a school or university setting on a 5-point agreement scale.',
            'survey' => [
                'title'       => 'Student Belonging Survey',
                'description' => 'Please rate how strongly you agree with each statement about your experience here.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I feel like I belong at this institution.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'There are people here I can talk to about things that matter to me.',        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel respected by my instructors and staff.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel out of place in classes here.',                                       'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'My contributions are valued by my classmates.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can be myself here without feeling I have to hide parts of who I am.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel connected to a community at this institution.',                       'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'When have you felt most like you belonged here?',                            'required' => false],
                    ['type' => 'open',   'prompt' => 'What would make you feel more connected to this community?',                 'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'program_evaluation',
            'name'        => 'Program evaluation (starter)',
            'description' => 'Eight items covering goal achievement, relevance, quality, support, and overall satisfaction with a program or initiative on a 5-point agreement scale.',
            'survey' => [
                'title'       => 'Program Evaluation Survey',
                'description' => 'Please rate how strongly you agree with each statement about the program.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The program met the goals it set out to address.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The program was relevant to my needs.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The activities and materials were of high quality.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The program staff were responsive and supportive.',                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The time required to participate was reasonable.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am applying what I gained from the program in practice.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this program to others in similar circumstances.',          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, the program was a worthwhile use of my time.',                       'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What was the most valuable part of the program for you?',                     'required' => false],
                    ['type' => 'open',   'prompt' => 'What changes would make this program more effective?',                        'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'customer_satisfaction',
            'name'        => 'Customer satisfaction (starter)',
            'description' => 'Six items measuring ease, value, support quality, and recommendation likelihood on a 5-point agreement scale, plus an NPS-style item and an open-ended follow-up.',
            'survey' => [
                'title'       => 'Customer Satisfaction Survey',
                'description' => 'Please rate how strongly you agree with each statement about your experience.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The product was easy to use.',                                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The product delivered value for what I paid.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When I needed help, support responded quickly and clearly.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The product met my expectations.',                                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am satisfied with my overall experience.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this product to a colleague or friend.',                    'required' => true, 'reverse' => false],
                    ['type' => 'single', 'prompt' => 'How likely are you to recommend us on a 0 to 10 scale?',                      'required' => false,
                        'options' => ['0','1','2','3','4','5','6','7','8','9','10']],
                    ['type' => 'open',   'prompt' => 'What is one thing we could do to improve your experience?',                   'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'training_feedback',
            'name'        => 'Training feedback (starter)',
            'description' => 'Seven items covering relevance, instructor effectiveness, format, applicability, and overall satisfaction with a training session on a 5-point agreement scale.',
            'survey' => [
                'title'       => 'Training Feedback Survey',
                'description' => 'Please rate how strongly you agree with each statement about the training you just completed.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The training content was relevant to my role.',                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The instructor explained concepts clearly.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The pace of the training was about right for me.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The format (mix of lecture, practice, discussion) worked well.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I left with skills I can apply in my work.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am confident I can use what I learned this week.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, the training was a good use of my time.',                            'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What was the most useful thing you took away from this training?',           'required' => false],
                    ['type' => 'open',   'prompt' => 'What would you change about the training?',                                   'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'community_needs',
            'name'        => 'Community needs assessment (starter)',
            'description' => 'Seven items rating how important various community services are, on a 5-point importance scale, plus open-ended follow-ups. Pair with a separate adequacy run for gap analysis.',
            'survey' => [
                'title'       => 'Community Needs Assessment',
                'description' => 'For each area below, indicate how important it is to you and your household.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Not at all important',
                    'likertHigh'   => 'Extremely important',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Affordable housing options for my household.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Reliable public transportation in my area.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Access to quality health care services close to home.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Affordable child care or after-school programs.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Safe and well-maintained public spaces (parks, libraries, community centers).','required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Workforce training and job placement support.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Mental-health and substance-use support services.',                            'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What unmet need in your community concerns you the most right now?',           'required' => false],
                    ['type' => 'open',   'prompt' => 'What service or resource would have the biggest impact on your household?',    'required' => false],
                ],
            ],
        ],
        [
            'key'         => 'climate_survey',
            'name'        => 'Climate survey (starter)',
            'description' => 'Eight items covering psychological safety, fairness, voice, trust, and inclusion in an organization on a 5-point agreement scale.',
            'survey' => [
                'title'       => 'Workplace Climate Survey',
                'description' => 'Please rate how strongly you agree with each statement about working here right now.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'It is safe to take a measured risk on this team.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When I raise a concern, it is taken seriously.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Decisions about people are made fairly here.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I trust the people I work with most often.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel I have to hide parts of who I am at work.',                            'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'My input has a real influence on team decisions.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Communication from leadership is honest and timely.',                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, this is a good place to work right now.',                            'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing leadership could change that would meaningfully improve the work environment?', 'required' => false],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // Phase 130: HR template expansion (8 new HR-aimed starters).
        // ------------------------------------------------------------------

        [
            'key'         => 'exit_interview',
            'name'        => 'Exit interview (HR starter)',
            'description' => 'Ten items capturing reasons for leaving, role fit, manager and team experience, and what could have prevented the departure. Use for departing employees in the final week.',
            'survey' => [
                'title'       => 'Exit Interview',
                'description' => 'Your honest answers help us understand what to keep doing and what to change. Responses are confidential and reported only in aggregate.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'My day-to-day work matched what was described when I was hired.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I had the resources and tools I needed to do my job well.',                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My manager supported my growth and development.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I felt my contributions were recognized.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Compensation and benefits were fair for the work I did.',                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I could see a future for myself here.',                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this organization as a place to work.',                       'required' => true, 'reverse' => false],
                    ['type' => 'single', 'prompt' => 'What is the primary reason you are leaving?',                                   'required' => true,
                        'options' => ['Career growth opportunity', 'Compensation', 'Manager or leadership', 'Team or culture', 'Workload or burnout', 'Personal or family reasons', 'Relocation', 'Role mismatch', 'Other']],
                    ['type' => 'single', 'prompt' => 'How long were you in your most recent role here?',                              'required' => false,
                        'options' => ['Less than 6 months', '6-12 months', '1-2 years', '2-5 years', 'More than 5 years']],
                    ['type' => 'single', 'prompt' => 'Would you consider returning in the future?',                                   'required' => false,
                        'options' => ['Yes', 'Maybe', 'No']],
                    ['type' => 'open',   'prompt' => 'What is the single biggest thing we could have done to keep you?',              'required' => false],
                    ['type' => 'open',   'prompt' => 'What advice would you give the person filling your role next?',                 'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'manager_360_lite',
            'name'        => 'Manager 360 lite (HR starter)',
            'description' => 'Twelve items designed for a 360 panel evaluating a manager. Covers communication, fairness, coaching, vision, and inclusivity on a 5-point agreement scale. Pair with the My Panels (360) view for evaluator-subject distribution.',
            'survey' => [
                'title'       => 'Manager Feedback',
                'description' => 'Please rate how strongly you agree with each statement about this manager based on your experience.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'This manager communicates expectations clearly.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager gives me useful, timely feedback.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager treats team members fairly.',                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager makes time to coach and develop the team.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can disagree with this manager without fear of retaliation.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager removes blockers so the team can do its best work.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager sets a clear direction for the team.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager recognizes good work.',                                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager invites and considers diverse viewpoints.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This manager holds themselves accountable when things go wrong.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would want to work for this manager again.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, this manager is effective in the role.',                               'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific thing this manager should keep doing?',                    'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific thing this manager could change to be more effective?',    'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'onboarding_30_day',
            'name'        => 'Onboarding 30-day check-in (HR starter)',
            'description' => 'Nine items capturing ramp-up, support, role clarity, and connection during the first month. Designed to fire 30 days after start date.',
            'survey' => [
                'title'       => 'Onboarding 30-Day Check-In',
                'description' => 'You are about a month in. Please rate how strongly you agree with each statement about your experience so far.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I have a clear picture of what success in this role looks like.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the tools, access, and information I need to do my job.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My manager has been a useful resource in my first month.',                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel connected to the people on my team.',                                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My onboarding training has prepared me for the work ahead.',                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I know who to ask when I have a question.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The pace of ramp-up has felt manageable.',                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'So far, this role matches what was described during hiring.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am glad I joined this organization.',                                         'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is going well in your onboarding that we should keep doing?',              'required' => false],
                    ['type' => 'open',   'prompt' => 'What would have made your first month easier?',                                  'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'dei_climate',
            'name'        => 'DEI climate (HR starter)',
            'description' => 'Ten items covering belonging, fair treatment, voice, identity safety, and equitable advancement on a 5-point agreement scale. Suitable as a stand-alone climate pulse or paired with the standard engagement template.',
            'survey' => [
                'title'       => 'Diversity, Equity, and Inclusion Climate',
                'description' => 'Please rate how strongly you agree with each statement about your experience here. Responses are confidential and reported only in aggregate.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I feel I belong at this organization.',                                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'People here are treated fairly regardless of their background.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can be open about who I am at work.',                                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My ideas are taken seriously in team discussions.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Promotions and stretch opportunities are distributed fairly here.',             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Leadership takes inclusion concerns seriously.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have witnessed or experienced bias here that went unaddressed.',              'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'I see people from a range of backgrounds in leadership roles.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would feel safe reporting unfair treatment.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Our DEI commitments are matched by real action.',                               'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one DEI-related practice you would keep, change, or stop?',             'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'change_readiness',
            'name'        => 'Change readiness (HR starter)',
            'description' => 'Nine items assessing communication, leadership credibility, perceived benefit, and confidence before, during, or after an organizational change initiative.',
            'survey' => [
                'title'       => 'Change Readiness Pulse',
                'description' => 'Please rate how strongly you agree with each statement about the upcoming change.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I understand why this change is happening.',                                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I understand what will be different for me when the change takes effect.',      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Leadership has been transparent about the trade-offs of this change.',          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I trust leadership to manage this change responsibly.',                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I expect this change to improve the way we work.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the skills I need to adapt to the change.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have a clear point of contact for questions about the change.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel anxious or unprepared about the change.',                                'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Overall, I am ready for this change to move forward.',                          'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is your single biggest open question about this change?',                  'required' => false],
                    ['type' => 'open',   'prompt' => 'What support would make this change easier for you?',                            'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'stay_interview',
            'name'        => 'Stay interview (HR starter)',
            'description' => 'Eight items used proactively with current employees to surface what makes them stay and what would make them leave. Designed for one-on-one conversations or pulse use.',
            'survey' => [
                'title'       => 'Stay Interview',
                'description' => 'We want to keep building a workplace you want to stay in. Please rate how strongly you agree with each statement.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'My current role uses my strengths well.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am learning and growing in this role.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I see a credible path for advancement here.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My manager actively invests in my career.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My compensation and benefits feel fair for the work I do.',                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My workload is sustainable.',                                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would describe my job satisfaction as high right now.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I plan to be here a year from now.',                                            'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is the single biggest reason you stay?',                                   'required' => false],
                    ['type' => 'open',   'prompt' => 'What would tempt you to leave if a recruiter called tomorrow?',                  'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing your manager could do differently to make this a better place to work for you?', 'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'remote_hybrid_pulse',
            'name'        => 'Remote and hybrid work pulse (HR starter)',
            'description' => 'Eight items measuring focus, connection, equity across locations, and overall fit with the current remote or hybrid arrangement.',
            'survey' => [
                'title'       => 'Remote and Hybrid Work Pulse',
                'description' => 'Please rate how strongly you agree with each statement about how your current work arrangement is going.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'single', 'prompt' => 'What is your typical work arrangement right now?',                              'required' => true,
                        'options' => ['Fully in-office', 'Mostly in-office, sometimes remote', 'Balanced hybrid', 'Mostly remote, sometimes in-office', 'Fully remote']],
                    ['type' => 'likert', 'prompt' => 'I can focus and get deep work done in my current setup.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I stay connected to my team across our different locations.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Meetings feel productive in my current arrangement.',                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Information reaches me regardless of where I work.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel equally visible to leadership compared with colleagues in other locations.', 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can set healthy boundaries between work and personal time.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My current arrangement fits the work I need to do.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, the current remote or hybrid policy is working for me.',                'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one practice that helps you do your best work remotely or in-office?',  'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing we could change to make the current arrangement work better?',  'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'customer_ops_team',
            'name'        => 'Customer-facing team feedback (HR starter)',
            'description' => 'Nine items for customer support, success, and service teams covering staffing, tooling, escalation, autonomy, and burnout risk.',
            'survey' => [
                'title'       => 'Customer-Facing Team Feedback',
                'description' => 'Please rate how strongly you agree with each statement about your work on the front line.',
                'settings'    => [
                    'likertPoints' => 5,
                    'likertLow'    => 'Strongly disagree',
                    'likertHigh'   => 'Strongly agree',
                ],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Our staffing levels match the volume of work we see day-to-day.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The tools I use let me solve customer problems efficiently.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the authority I need to resolve common issues without escalating.',      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When I escalate, I get a fast and useful response.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Customer feedback is shared back with the team in a useful way.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My manager protects the team from unreasonable customer demands.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel emotionally drained by my work most weeks.',                             'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'I have time during the day to recover between difficult interactions.',         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, this is a sustainable role for me right now.',                          'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one tool, process, or policy change that would make your day easier?',  'required' => false],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // Phase 134c: Suite backfill (29 starters). Five for the 360 suite,
        // three for Pulse, five for Program Evaluation, five for Education,
        // six for the Researcher suite, and five for Customer Experience.
        // ------------------------------------------------------------------

        // ===== 360 FEEDBACK SUITE =====

        [
            'key'         => 'leadership_feedback',
            'name'        => 'Leadership feedback (360 starter)',
            'description' => 'Ten items for evaluating senior leaders. Extended coverage of vision, strategy, accountability, and executive presence in addition to the core manager items.',
            'survey' => [
                'title'       => 'Leadership Feedback',
                'description' => 'Please rate how strongly you agree with each statement about this leader.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'This leader sets a clear and compelling vision for the team.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader translates strategy into priorities I understand.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader makes decisions with the right balance of speed and rigor.',        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader holds themselves accountable for outcomes.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader develops the people around them.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader communicates honestly, including the hard messages.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader builds trust across the organization.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader models the values the company says it cares about.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This leader makes me more effective at my work.',                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, this leader is effective in the role.',                                'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific thing this leader should keep doing?',                    'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific thing this leader should start, stop, or change?',         'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'peer_feedback',
            'name'        => 'Peer feedback (360 starter)',
            'description' => 'Eight items designed for lateral peer-to-peer review inside a cohort or working group. Collaboration, reliability, and influence without authority.',
            'survey' => [
                'title'       => 'Peer Feedback',
                'description' => 'Please rate how strongly you agree with each statement about this peer.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'This peer delivers on the commitments they make to the team.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This peer is generous with their time and expertise.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This peer communicates clearly in writing and in meetings.',                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This peer raises issues constructively rather than letting them fester.',       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This peer makes the people around them better.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This peer handles disagreement well.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I trust this peer to follow through without being chased.',                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would want this peer on my next project.',                                    'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one strength of this peer that the team relies on?',                    'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific change that would make this peer even more effective?',    'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'team_effectiveness',
            'name'        => 'Team effectiveness (360 starter)',
            'description' => 'Eight items for group-level feedback. Trust, collaboration, accountability, and shared direction across the team rather than any one individual.',
            'survey' => [
                'title'       => 'Team Effectiveness',
                'description' => 'Please rate how strongly you agree with each statement about your team.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Our team has a shared understanding of what we are trying to achieve.',         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Trust runs in both directions between team members.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'We hold each other accountable for the commitments we make.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Disagreement on this team produces better outcomes, not lasting damage.',       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'We get the right people in the room when a decision needs to be made.',         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'We surface and address problems early.',                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The team operates with too many meetings and not enough doing.',                'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Overall, our team is performing at a high level.',                              'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is the team doing well that we should keep doing?',                        'required' => false],
                    ['type' => 'open',   'prompt' => 'What is the one thing slowing the team down most?',                              'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'supervisor_evaluation',
            'name'        => 'Supervisor evaluation (360 starter)',
            'description' => 'Eight items specifically for direct-report input on a supervisor. Designed to be the "direct reports only" complement to a Manager 360 panel.',
            'survey' => [
                'title'       => 'Supervisor Evaluation',
                'description' => 'Please rate how strongly you agree with each statement based on your experience as a direct report.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'My supervisor sets clear expectations for my work.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My supervisor gives me feedback I can act on.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My supervisor is fair in how they treat the team.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My supervisor protects my time from low-value work.',                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My supervisor recognizes good work specifically and publicly.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My supervisor invests in my development.',                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I can raise a hard topic with my supervisor without fear of retaliation.',      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, my supervisor is effective in the role.',                              'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific behavior your supervisor should keep doing?',              'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific behavior your supervisor should change?',                  'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'self_vs_others_review',
            'name'        => 'Self vs others development review (360 starter)',
            'description' => 'Seven items focused on common blind-spot domains in self vs other ratings. Pair with the 360 subject narrator that flags meaningful gaps.',
            'survey' => [
                'title'       => 'Self vs Others Development Review',
                'description' => 'Please rate how strongly you agree with each statement based on your view of this person.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'This person listens to understand before responding.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This person handles stress without affecting the team negatively.',             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This person owns mistakes and learns from them.',                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This person seeks out feedback proactively.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This person delegates appropriately rather than micromanaging.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This person makes decisions in a timely way under uncertainty.',                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'This person communicates with the right level of detail for the audience.',     'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is the most important strength this person should leverage?',              'required' => false],
                    ['type' => 'open',   'prompt' => 'What is the most important development area this person should work on?',        'required' => false],
                ],
            ],
        ],

        // ===== PULSE SURVEY SUITE =====

        [
            'key'         => 'weekly_team_pulse',
            'name'        => 'Weekly team pulse (Pulse starter)',
            'description' => 'Five short items for a sustainable weekly cadence. Designed to be answered in under two minutes so response rates stay high over many weeks.',
            'survey' => [
                'title'       => 'Weekly Team Pulse',
                'description' => 'A quick five-item check-in. Please rate this past week.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I felt productive at work this week.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I knew what my top priorities were this week.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My workload this week was sustainable.',                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I had the information I needed to do my work.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I felt connected to my teammates this week.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'One thing that went well this week?',                                            'required' => false],
                    ['type' => 'open',   'prompt' => 'One thing that got in your way?',                                                'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'monthly_engagement_pulse',
            'name'        => 'Monthly engagement pulse (Pulse starter)',
            'description' => 'Seven items covering the engagement composite at a monthly cadence. Shorter than the full Workplace Engagement survey; built to track trend over many months.',
            'survey' => [
                'title'       => 'Monthly Engagement Pulse',
                'description' => 'Please rate how strongly you agree with each statement about your work over the past month.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I look forward to my work most days.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have a clear sense of how my work contributes to the bigger picture.',        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have growth opportunities I am pursuing.',                                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel valued for the work I do.',                                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My workload over the past month was sustainable.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this organization to a friend looking for work.',             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, my engagement at work is high right now.',                             'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing that would lift your engagement next month?',                  'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'training_follow_up_pulse',
            'name'        => 'Training follow-up pulse (Pulse starter)',
            'description' => 'Seven items asked 30, 60, or 90 days after a training to check whether the skills are actually being used. Pairs with the Training Feedback survey as the post-end-of-program companion.',
            'survey' => [
                'title'       => 'Training Follow-Up Pulse',
                'description' => 'Some time has passed since the training. Please rate how strongly you agree with each statement.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I have used what I learned in the training in my actual work.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My manager has supported me in applying what I learned.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The skills from the training have stuck with me.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have noticed a change in how I approach the work.',                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would benefit from a refresher on at least part of the content.',             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Other people have noticed a change in how I work.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, the training has had a lasting impact on my work.',                    'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What specifically are you doing differently because of the training?',          'required' => false],
                ],
            ],
        ],

        // ===== PROGRAM EVALUATION SUITE =====

        [
            'key'         => 'pre_post_program',
            'name'        => 'Pre/post program survey (Program Eval starter)',
            'description' => 'Seven items fired before and after a program to measure change. Pairs with the Pre/Post analytics tab and the Reliable Change Index for per-participant outcomes.',
            'survey' => [
                'title'       => 'Pre/Post Program Survey',
                'description' => 'Please rate how strongly you agree with each statement right now.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I have the knowledge I need to do this work well.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the skills I need to do this work well.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am confident in my ability to handle the challenges that come up.',           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I know where to go for help when I get stuck.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have a clear sense of what success looks like.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am motivated to apply what I am learning.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I expect to see real change in my work because of this program.',               'required' => true, 'reverse' => false],
                    ['type' => 'single', 'prompt' => 'When is this survey being completed?',                                          'required' => true,
                        'options' => ['Before the program (baseline)', 'After the program (follow-up)']],
                    ['type' => 'open',   'prompt' => 'What is the most important thing you hope to gain (or have gained) from this program?', 'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'grant_outcome',
            'name'        => 'Grant outcome survey (Program Eval starter)',
            'description' => 'Eight items framed for funder reporting. Outcome indicators, beneficiary perspective, alignment to grant goals, and qualitative impact.',
            'survey' => [
                'title'       => 'Grant Outcome Survey',
                'description' => 'Please rate how strongly you agree with each statement about the program funded under this grant.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The program addressed a real need in my community.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The program achieved the outcomes it set out to achieve.',                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am better off because of the program.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The program reached the people it was designed to reach.',                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The program staff were responsive and professional.',                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The benefits of the program will outlast its funding period.',                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this program to others in similar situations.',               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, the program was a wise use of grant resources.',                       'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'In one or two sentences, what specifically changed for you because of this program?', 'required' => false],
                    ['type' => 'open',   'prompt' => 'What would make a future version of this program more effective?',              'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'participant_satisfaction',
            'name'        => 'Participant satisfaction (Program Eval starter)',
            'description' => 'Seven items capturing end-of-program satisfaction with structure, content, staff, and overall experience.',
            'survey' => [
                'title'       => 'Participant Satisfaction',
                'description' => 'You just completed the program. Please rate how strongly you agree with each statement.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The program was a good use of my time.',                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The structure of the program made sense.',                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The content was high quality.',                                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The staff were knowledgeable and helpful.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The pace was appropriate for me.',                                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I will recommend this program to others.',                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, I am satisfied with my experience.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What was the most valuable part of the program for you?',                       'required' => false],
                    ['type' => 'open',   'prompt' => 'What would you change about the program?',                                      'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'stakeholder_feedback',
            'name'        => 'Stakeholder feedback (Program Eval starter)',
            'description' => 'Seven items framed for funders, board members, partners, and other organizational stakeholders to give structured perspective on an initiative.',
            'survey' => [
                'title'       => 'Stakeholder Feedback',
                'description' => 'Please rate how strongly you agree with each statement based on your view of the initiative.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The initiative is addressing a problem that matters.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The approach being taken is sound.',                                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Progress so far is on track.',                                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The team running the initiative has the right capabilities.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Communication from the initiative team has been timely and useful.',            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My input as a stakeholder has been incorporated.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I am confident the initiative will achieve its stated outcomes.',               'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What concerns or risks should the initiative team be paying more attention to?', 'required' => false],
                    ['type' => 'open',   'prompt' => 'What is the most important thing the initiative team should keep doing?',       'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'implementation_feedback',
            'name'        => 'Implementation feedback (Program Eval starter)',
            'description' => 'Eight items for implementers (front-line staff actually delivering the program). Fidelity, adoption, barriers, fit with existing practice.',
            'survey' => [
                'title'       => 'Implementation Feedback',
                'description' => 'You are part of the team delivering this program. Please rate how strongly you agree with each statement.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I have a clear understanding of how the program is supposed to be delivered.', 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The program fits well with how my unit already works.',                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the training I need to deliver the program with fidelity.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the tools and resources I need to deliver the program.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Leadership is visibly behind this implementation.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The pace of rollout is realistic for the work involved.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have had to make local adaptations to the program to make it work.',          'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Overall, the implementation is going well.',                                    'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is the biggest barrier to delivering the program as designed?',            'required' => false],
                ],
            ],
        ],

        // ===== EDUCATION SUITE =====

        [
            'key'         => 'student_engagement',
            'name'        => 'Student engagement (Education starter)',
            'description' => 'Nine items covering the three classic engagement domains: behavioral (effort and participation), cognitive (investment in learning), and emotional (interest and belonging).',
            'survey' => [
                'title'       => 'Student Engagement Survey',
                'description' => 'Please rate how strongly you agree with each statement about this class.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I pay attention in class most of the time.',                                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I put real effort into the work in this class.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I think about the material outside of class.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'When the work is hard, I keep going rather than giving up.',                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I find the material in this class interesting.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel like I belong in this class.',                                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I look forward to coming to this class most days.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I feel bored or disengaged in this class often.',                               'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Overall, I am engaged in this class.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing about this class that keeps you engaged?',                    'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'academic_advising',
            'name'        => 'Academic advising (Education starter)',
            'description' => 'Seven items measuring advisor accessibility, quality of guidance, accuracy of information, and impact on the student\'s academic path.',
            'survey' => [
                'title'       => 'Academic Advising Survey',
                'description' => 'Please rate how strongly you agree with each statement about your academic advising experience.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'My advisor was accessible when I needed help.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My advisor took the time to understand my goals.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The information my advisor gave me was accurate.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My advisor helped me plan a path that fits my goals.',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My advisor connected me to other campus resources when relevant.',              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I left advising sessions with clear next steps.',                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, my advising experience has been useful.',                              'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What was the most useful thing your advisor did for you?',                      'required' => false],
                    ['type' => 'open',   'prompt' => 'What could academic advising do better for students like you?',                  'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'faculty_feedback',
            'name'        => 'Faculty feedback (Education starter)',
            'description' => 'Eight items capturing the faculty experience: workload, autonomy, resources, leadership, collegial support, and career support.',
            'survey' => [
                'title'       => 'Faculty Feedback',
                'description' => 'Please rate how strongly you agree with each statement about your experience as a faculty member here.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'My teaching load is reasonable given the rest of my responsibilities.',         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have the resources I need to teach effectively.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I have meaningful autonomy in my work.',                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Department leadership is responsive when I raise an issue.',                    'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My colleagues are supportive of one another.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The promotion and tenure process is transparent and fair.',                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Service expectations have grown without a corresponding reduction elsewhere.',  'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Overall, this is a good institution to be a faculty member at right now.',      'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one specific change that would improve faculty experience here?',       'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'student_services',
            'name'        => 'Student services satisfaction (Education starter)',
            'description' => 'Eight items rating common student-services areas (library, IT, registrar, financial aid, counseling, dining, housing) on a single 5-point scale.',
            'survey' => [
                'title'       => 'Student Services Satisfaction',
                'description' => 'Please rate how strongly you agree with each statement about your experience with student services this term.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The library has the resources I need for my coursework.',                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'IT services and the campus network work reliably.',                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The registrar handled my requests accurately and on time.',                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Financial aid staff explained my situation clearly.',                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Counseling and mental-health services were accessible when I needed them.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Dining options on campus meet my needs.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Housing has been a positive part of my experience.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, student services here meet my expectations.',                          'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Which student service has helped you the most this term?',                      'required' => false],
                    ['type' => 'open',   'prompt' => 'Which student service most needs to improve?',                                   'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'classroom_assessment',
            'name'        => 'Classroom assessment feedback (Education starter)',
            'description' => 'Seven items for students to reflect on the assessment itself (a test, quiz, or project) right after they complete it. Useful for pairing with the Tests subsystem.',
            'survey' => [
                'title'       => 'Classroom Assessment Feedback',
                'description' => 'You just completed the assessment. Please rate how strongly you agree with each statement.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The instructions for the assessment were clear.',                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The assessment covered what we learned in class.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The questions were written clearly.',                                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The amount of time given was appropriate.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The assessment let me show what I actually know.',                              'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The difficulty matched what I expected.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, this was a fair assessment.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Which question or section gave you the most trouble, and why?',                 'required' => false],
                ],
            ],
        ],

        // ===== RESEARCHER SUITE =====

        [
            'key'         => 'pilot_study',
            'name'        => 'Pilot study survey (Researcher starter)',
            'description' => 'Eight items in an initial-item-pool format. Designed to be edited heavily during scale development; structure illustrates how to mix positively and negatively worded items.',
            'survey' => [
                'title'       => 'Pilot Study Survey',
                'description' => 'Please rate how strongly you agree with each statement. Some items are intentionally reversed to test for response bias.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Item 1 (positive wording): replace with your construct-relevant statement.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Item 2 (positive wording): replace with your construct-relevant statement.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Item 3 (reverse-coded): replace with your construct-relevant statement.',        'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Item 4 (positive wording): replace with your construct-relevant statement.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Item 5 (reverse-coded): replace with your construct-relevant statement.',        'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Item 6 (positive wording): replace with your construct-relevant statement.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Item 7 (positive wording): replace with your construct-relevant statement.',     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Item 8 (positive wording): replace with your construct-relevant statement.',     'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Any items that felt confusing or did not apply to you?',                         'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'construct_validation',
            'name'        => 'Construct validation survey (Researcher starter)',
            'description' => 'Twelve items scaffolded into two constructs for convergent and discriminant validity testing. Replace items with your target and contrast measures.',
            'survey' => [
                'title'       => 'Construct Validation Survey',
                'description' => 'Please rate how strongly you agree with each statement. Items are grouped to test whether the target construct is distinct from a related but different construct.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Target construct, item 1 (replace with your wording).',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Target construct, item 2 (replace with your wording).',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Target construct, item 3 (replace with your wording).',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Target construct, item 4 (replace with your wording).',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Target construct, item 5 (replace with your wording).',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Target construct, item 6 (replace with your wording).',                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Contrast construct, item 1 (replace with your wording).',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Contrast construct, item 2 (replace with your wording).',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Contrast construct, item 3 (replace with your wording).',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Contrast construct, item 4 (replace with your wording).',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Contrast construct, item 5 (replace with your wording).',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Contrast construct, item 6 (replace with your wording).',                        'required' => true, 'reverse' => false],
                ],
            ],
        ],

        [
            'key'         => 'scale_development',
            'name'        => 'Scale development survey (Researcher starter)',
            'description' => 'Ten-item pool with mixed positively and negatively worded items, set up for an EFA pass. Replace items with your candidate scale; then inspect the loadings and item-total correlations in ReliCheck.',
            'survey' => [
                'title'       => 'Scale Development Item Pool',
                'description' => 'Please rate how strongly you agree with each statement. The wording is deliberately mixed to detect acquiescence bias.',
                'settings'    => ['likertPoints' => 7, 'likertLow' => 'Very strongly disagree', 'likertHigh' => 'Very strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Candidate item 1 (positive): replace with your wording.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Candidate item 2 (positive): replace with your wording.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Candidate item 3 (reverse): replace with your wording.',                         'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Candidate item 4 (positive): replace with your wording.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Candidate item 5 (reverse): replace with your wording.',                         'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Candidate item 6 (positive): replace with your wording.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Candidate item 7 (positive): replace with your wording.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Candidate item 8 (reverse): replace with your wording.',                         'required' => true, 'reverse' => true ],
                    ['type' => 'likert', 'prompt' => 'Candidate item 9 (positive): replace with your wording.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Candidate item 10 (positive): replace with your wording.',                       'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Any item that felt unclear or did not apply to you?',                            'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'mixed_methods',
            'name'        => 'Mixed-methods survey (Researcher starter)',
            'description' => 'Six Likert items paired with four open-ended probes designed to give the open responses enough structure to theme later.',
            'survey' => [
                'title'       => 'Mixed-Methods Survey',
                'description' => 'A short set of Likert items followed by structured open-ended questions.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'Quantitative item 1: replace with your wording.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Quantitative item 2: replace with your wording.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Quantitative item 3: replace with your wording.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Quantitative item 4: replace with your wording.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Quantitative item 5: replace with your wording.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Quantitative item 6: replace with your wording.',                                'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Describe a specific situation where the topic of this survey came up for you recently.', 'required' => false],
                    ['type' => 'open',   'prompt' => 'What aspect of this topic matters most to you, and why?',                        'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing you wish someone had asked you about this topic that we have not?', 'required' => false],
                    ['type' => 'open',   'prompt' => 'Anything else you want us to know?',                                              'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'demographic_intake',
            'name'        => 'Demographic intake survey (Researcher starter)',
            'description' => 'Eleven single-choice and open items covering common demographic variables in IRB-friendly format. Every item is optional. Replace, reorder, or remove items to match your protocol.',
            'survey' => [
                'title'       => 'Demographic Intake',
                'description' => 'These questions are optional. You can skip any item that does not apply to you or that you would prefer not to answer.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'single', 'prompt' => 'Age range',          'required' => false,
                        'options' => ['Under 18', '18-24', '25-34', '35-44', '45-54', '55-64', '65 or older', 'Prefer not to say']],
                    ['type' => 'single', 'prompt' => 'Gender identity',    'required' => false,
                        'options' => ['Woman', 'Man', 'Non-binary', 'Self-describe', 'Prefer not to say']],
                    ['type' => 'open',   'prompt' => 'If you selected Self-describe above, please describe.',                          'required' => false],
                    ['type' => 'single', 'prompt' => 'Highest level of education completed',                                          'required' => false,
                        'options' => ['Less than high school', 'High school or equivalent', 'Some college', 'Associate degree', 'Bachelor degree', 'Master degree', 'Doctorate or professional degree', 'Prefer not to say']],
                    ['type' => 'single', 'prompt' => 'Current employment status', 'required' => false,
                        'options' => ['Full-time', 'Part-time', 'Self-employed', 'Student', 'Unemployed', 'Retired', 'Other', 'Prefer not to say']],
                    ['type' => 'open',   'prompt' => 'If employed, what is your job title or role?',                                   'required' => false],
                    ['type' => 'open',   'prompt' => 'What industry or field do you work in?',                                          'required' => false],
                    ['type' => 'single', 'prompt' => 'Country of residence',                                                          'required' => false,
                        'options' => ['United States', 'Canada', 'United Kingdom', 'Other', 'Prefer not to say']],
                    ['type' => 'open',   'prompt' => 'If Other above, please specify your country.',                                   'required' => false],
                    ['type' => 'single', 'prompt' => 'Do you live in an urban, suburban, or rural area?',                              'required' => false,
                        'options' => ['Urban', 'Suburban', 'Rural', 'Prefer not to say']],
                    ['type' => 'single', 'prompt' => 'Primary language spoken at home',                                                'required' => false,
                        'options' => ['English', 'Spanish', 'Mandarin', 'French', 'Other', 'Prefer not to say']],
                ],
            ],
        ],

        [
            'key'         => 'irb_consent',
            'name'        => 'IRB-friendly consent survey (Researcher starter)',
            'description' => 'Consent-first format. The first question requires explicit consent before any other items appear. Pair with skip logic in the builder so non-consenters do not see the substantive items.',
            'survey' => [
                'title'       => 'Informed Consent and Survey',
                'description' => 'This survey is part of an IRB-approved research study. Please read the consent information below carefully before deciding whether to participate.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'open',   'prompt' => 'Consent statement (replace this with your IRB-approved consent text). Your participation is voluntary. You may stop at any time. Your responses will be kept confidential per the protocol.', 'required' => false],
                    ['type' => 'single', 'prompt' => 'Do you consent to participate in this research?',                                'required' => true,
                        'options' => ['Yes, I consent and want to continue', 'No, I do not consent (I will exit now)']],
                    ['type' => 'likert', 'prompt' => 'Substantive item 1: replace with your wording.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Substantive item 2: replace with your wording.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Substantive item 3: replace with your wording.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Substantive item 4: replace with your wording.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Substantive item 5: replace with your wording.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Any comments or context you want the research team to know?',                    'required' => false],
                ],
            ],
        ],

        // ===== CUSTOMER EXPERIENCE SUITE =====

        [
            'key'         => 'product_feedback',
            'name'        => 'Product feedback (CX starter)',
            'description' => 'Eight items capturing satisfaction with a specific product, including feature priorities and most-wanted improvements.',
            'survey' => [
                'title'       => 'Product Feedback Survey',
                'description' => 'Please rate how strongly you agree with each statement about your experience with the product.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The product is easy to use day to day.',                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The product does what I expected when I bought it.',                            'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The product is reliable.',                                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The product is worth what I paid for it.',                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The product has improved over the time I have used it.',                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would buy this product again.',                                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I would recommend this product to a colleague or friend.',                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, I am satisfied with the product.',                                     'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is the single most useful feature of the product for you?',                'required' => false],
                    ['type' => 'open',   'prompt' => 'What is the single most important improvement we should make?',                  'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'service_experience',
            'name'        => 'Service experience (CX starter)',
            'description' => 'Seven items capturing service interaction quality, plus a follow-up on whether the issue was resolved on the first contact.',
            'survey' => [
                'title'       => 'Service Experience Survey',
                'description' => 'Please rate how strongly you agree with each statement about the service interaction you just had.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'It was easy to get in touch when I needed help.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The person who helped me was knowledgeable.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The person who helped me was respectful and professional.',                     'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My issue was understood without me having to repeat myself.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The response time was reasonable for the kind of issue I had.',                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'My issue was resolved.',                                                        'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, I am satisfied with the service I received.',                          'required' => true, 'reverse' => false],
                    ['type' => 'single', 'prompt' => 'Was your issue resolved on the first contact?',                                 'required' => false,
                        'options' => ['Yes', 'No, I had to follow up', 'My issue is still open']],
                    ['type' => 'open',   'prompt' => 'Anything we could have done to make this interaction smoother?',                'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'nps_style_feedback',
            'name'        => 'NPS-style feedback (CX starter)',
            'description' => 'Recommend question on an 11-point 0 to 10 scale paired with structured reason-why follow-ups. The classic Net Promoter Score format with enough context to act on.',
            'survey' => [
                'title'       => 'Recommendation Feedback',
                'description' => 'A short two-minute survey about your overall experience.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'single', 'prompt' => 'How likely are you to recommend us to a colleague or friend?',                  'required' => true,
                        'options' => ['0','1','2','3','4','5','6','7','8','9','10']],
                    ['type' => 'open',   'prompt' => 'What is the main reason for your score?',                                        'required' => false],
                    ['type' => 'likert', 'prompt' => 'Our product or service met my needs.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'It was easy to get value out of what we offer.',                                'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Support was helpful when I needed it.',                                         'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I trust this company.',                                                         'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing we could do that would make you a stronger promoter?',         'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'website_feedback',
            'name'        => 'Website feedback (CX starter)',
            'description' => 'Seven items rating findability, clarity, trust, and task completion on the website.',
            'survey' => [
                'title'       => 'Website Feedback',
                'description' => 'Please rate how strongly you agree with each statement about your visit to our website.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'I found what I was looking for.',                                               'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The site loaded quickly.',                                                      'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The content was clear and easy to understand.',                                 'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I trusted the information I read.',                                             'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Navigating to where I needed to go was easy.',                                  'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I completed the task I came here to do.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'Overall, my experience on the site was positive.',                              'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'Was there something you were looking for that you could not find?',             'required' => false],
                    ['type' => 'open',   'prompt' => 'Anything confusing or frustrating about the site?',                              'required' => false],
                ],
            ],
        ],

        [
            'key'         => 'post_event_feedback',
            'name'        => 'Post-event feedback (CX starter)',
            'description' => 'Seven items capturing logistics, content quality, networking value, and likelihood to attend again. Designed for conferences, workshops, and webinars.',
            'survey' => [
                'title'       => 'Post-Event Feedback',
                'description' => 'Thanks for attending. Please rate how strongly you agree with each statement about the event.',
                'settings'    => ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'],
                'questions' => [
                    ['type' => 'likert', 'prompt' => 'The event content was high quality.',                                           'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The speakers and presenters were effective.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The logistics (registration, venue, schedule) ran smoothly.',                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I made useful connections at the event.',                                       'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'The event was a good use of my time.',                                          'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I will recommend this event to a colleague.',                                   'required' => true, 'reverse' => false],
                    ['type' => 'likert', 'prompt' => 'I plan to attend again next time.',                                             'required' => true, 'reverse' => false],
                    ['type' => 'open',   'prompt' => 'What was the most valuable part of the event for you?',                         'required' => false],
                    ['type' => 'open',   'prompt' => 'What is one thing we should change next time?',                                 'required' => false],
                ],
            ],
        ],
    ];
}
