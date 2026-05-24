<?php
// api/_suites.php
// Helpers for Phase 133 Suites (workflow packages). Pure functions.
// Endpoint handlers in api/suites/ require_once this file.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

/**
 * Canonical definitions of the 7 system suites. Order here is the display
 * order requested by Donald (360 / HR / Pulse / Program Eval / Education /
 * Researcher / CX). Templates are referenced by their template_key in
 * survey_templates(); keys marked 'coming_soon' do not have a backing
 * template yet and render as disabled cards in the UI.
 */
function suites_system_definitions(): array
{
    return [
        [
            'suite_key'   => '360',
            'name'        => '360 Feedback Suite',
            'description' => 'Manager 360s, leadership development, peer feedback. Each subject is evaluated by their manager, peers, direct reports, and (optionally) themselves. ReliCheck distributes, tracks, and generates one plain-language subject report.',
            'color'       => '#1F3A8A',
            'icon'        => 'orbit',
            'entity_type' => 'survey',
            'tagline'     => 'The premium 360 workflow without the enterprise tier.',
            'templates'   => [
                ['key' => 'manager_360_lite',          'name' => 'Manager 360 lite',                'available' => true],
                ['key' => 'leadership_feedback',      'name' => 'Leadership feedback',             'available' => false],
                ['key' => 'peer_feedback',            'name' => 'Peer feedback',                   'available' => false],
                ['key' => 'team_effectiveness',       'name' => 'Team effectiveness',              'available' => false],
                ['key' => 'supervisor_evaluation',    'name' => 'Supervisor evaluation',           'available' => false],
                ['key' => 'self_vs_others_review',    'name' => 'Self vs others development review', 'available' => false],
            ],
        ],
        [
            'suite_key'   => 'hr',
            'name'        => 'HR & Teams Suite',
            'description' => 'The full HR rhythm in one place. Engagement, exit, stay, onboarding, manager 360, DEI climate, change readiness, remote/hybrid. Each template already uses the 5-point Strongly disagree to Strongly agree scale.',
            'color'       => '#0a7d35',
            'icon'        => 'people',
            'entity_type' => 'survey',
            'tagline'     => 'The People-team rhythm: engagement, exit, stay, change, climate.',
            'templates'   => [
                ['key' => 'workplace_engagement',     'name' => 'Workplace engagement',            'available' => true],
                ['key' => 'exit_interview',           'name' => 'Exit interview',                  'available' => true],
                ['key' => 'stay_interview',           'name' => 'Stay interview',                  'available' => true],
                ['key' => 'manager_360_lite',         'name' => 'Manager 360 lite',                'available' => true],
                ['key' => 'onboarding_30_day',        'name' => 'Onboarding 30-day check-in',      'available' => true],
                ['key' => 'dei_climate',              'name' => 'DEI climate',                     'available' => true],
                ['key' => 'change_readiness',         'name' => 'Change readiness pulse',          'available' => true],
                ['key' => 'remote_hybrid_pulse',      'name' => 'Remote and hybrid work pulse',    'available' => true],
                ['key' => 'customer_ops_team',        'name' => 'Customer-facing team feedback',   'available' => true],
            ],
        ],
        [
            'suite_key'   => 'pulse',
            'name'        => 'Pulse Survey Suite',
            'description' => 'Recurring waves of the same short survey to the same people, auto-tagged so Compare and Pre/Post can slice by wave. Weekly, biweekly, monthly, quarterly, or custom cadence.',
            'color'       => '#b95800',
            'icon'        => 'pulse',
            'entity_type' => 'survey',
            'tagline'     => 'Short, recurring, auto-tagged. Built for the cadence work.',
            'templates'   => [
                ['key' => 'weekly_team_pulse',        'name' => 'Weekly team pulse',               'available' => false],
                ['key' => 'monthly_engagement_pulse', 'name' => 'Monthly engagement pulse',        'available' => false],
                ['key' => 'change_readiness',         'name' => 'Change readiness pulse',          'available' => true],
                ['key' => 'burnout_general',          'name' => 'Burnout indicators',              'available' => true],
                ['key' => 'remote_hybrid_pulse',      'name' => 'Remote work pulse',               'available' => true],
                ['key' => 'training_follow_up_pulse', 'name' => 'Training follow-up pulse',        'available' => false],
            ],
        ],
        [
            'suite_key'   => 'program_eval',
            'name'        => 'Program Evaluation Suite',
            'description' => 'Pre/post designs, grant reporting, training evaluation, and stakeholder feedback. Built on the Reliable Change Index and Pre/Post analytics that already ship with ReliCheck.',
            'color'       => '#7a3aed',
            'icon'        => 'beaker',
            'entity_type' => 'survey',
            'tagline'     => 'Grant outcomes, training evaluation, pre/post designs.',
            'templates'   => [
                ['key' => 'program_evaluation',       'name' => 'Program evaluation',              'available' => true],
                ['key' => 'training_feedback',        'name' => 'Training feedback',               'available' => true],
                ['key' => 'community_needs',          'name' => 'Community needs assessment',      'available' => true],
                ['key' => 'pre_post_program',         'name' => 'Pre/post program survey',         'available' => false],
                ['key' => 'grant_outcome',            'name' => 'Grant outcome survey',            'available' => false],
                ['key' => 'participant_satisfaction', 'name' => 'Participant satisfaction',        'available' => false],
                ['key' => 'stakeholder_feedback',     'name' => 'Stakeholder feedback',            'available' => false],
                ['key' => 'implementation_feedback',  'name' => 'Implementation feedback',         'available' => false],
            ],
        ],
        [
            'suite_key'   => 'education',
            'name'        => 'Education Suite',
            'description' => 'Course evaluation, student belonging, school climate, plus the supporting student-service feedback work. Pairs with the Tests subsystem for classroom assessment analytics.',
            'color'       => '#0aa48a',
            'icon'        => 'cap',
            'entity_type' => 'survey',
            'tagline'     => 'Course evaluation, belonging, climate, and classroom test analytics.',
            'templates'   => [
                ['key' => 'course_evaluation',        'name' => 'Course evaluation',               'available' => true],
                ['key' => 'student_belonging',        'name' => 'Student belonging',               'available' => true],
                ['key' => 'climate_survey',           'name' => 'School climate',                  'available' => true],
                ['key' => 'student_engagement',       'name' => 'Student engagement',              'available' => false],
                ['key' => 'academic_advising',        'name' => 'Academic advising',               'available' => false],
                ['key' => 'faculty_feedback',         'name' => 'Faculty feedback',                'available' => false],
                ['key' => 'student_services',         'name' => 'Student services satisfaction',   'available' => false],
                ['key' => 'classroom_assessment',     'name' => 'Classroom assessment feedback',   'available' => false],
            ],
        ],
        [
            'suite_key'   => 'test_analysis',
            'name'        => 'Test & Item Analysis Suite',
            'description' => 'Classroom tests, diagnostics, and mastery checks with full item analysis. Pairs with the Tests subsystem: reliability (alpha, omega, split-half, SEM), per-item difficulty and discrimination, distractor analysis, skill rollups, and an item-health report that flags items to keep, revise, or drop.',
            'color'       => '#0aa48a',
            'icon'        => 'check',
            'entity_type' => 'test',
            'tagline'     => 'Built for teachers, departments, and assessment coordinators.',
            'templates'   => [
                ['key' => 'math_diagnostic',         'name' => 'Math diagnostic',                  'available' => false],
                ['key' => 'reading_comprehension',   'name' => 'Reading comprehension check',      'available' => false],
                ['key' => 'unit_end_quiz',           'name' => 'Unit-end quiz',                    'available' => false],
                ['key' => 'concept_check',           'name' => 'Concept check (formative)',        'available' => false],
                ['key' => 'mid_term_exam',           'name' => 'Mid-term exam',                    'available' => false],
                ['key' => 'final_exam',              'name' => 'Final exam',                       'available' => false],
                ['key' => 'skill_mastery',           'name' => 'Skill mastery assessment',         'available' => false],
                ['key' => 'ap_style_practice',       'name' => 'AP-style practice exam',           'available' => false],
            ],
        ],
        [
            'suite_key'   => 'researcher',
            'name'        => 'Researcher Suite',
            'description' => 'The methodology suite. Pilot studies, scale development, construct validation, and IRB-friendly intake. Pairs with the full methodology library: EFA, CFA, measurement invariance, IRT, regression, mediation, moderation, multilevel models.',
            'color'       => '#a01a1a',
            'icon'        => 'book',
            'entity_type' => 'survey',
            'tagline'     => 'Pilot studies, scale development, manuscript-ready methodology.',
            'templates'   => [
                ['key' => 'perceived_support',        'name' => 'Perceived social support',        'available' => true],
                ['key' => 'pilot_study',              'name' => 'Pilot study survey',              'available' => false],
                ['key' => 'construct_validation',     'name' => 'Construct validation survey',     'available' => false],
                ['key' => 'scale_development',        'name' => 'Scale development survey',        'available' => false],
                ['key' => 'mixed_methods',            'name' => 'Mixed-methods survey',            'available' => false],
                ['key' => 'demographic_intake',       'name' => 'Demographic intake survey',       'available' => false],
                ['key' => 'irb_consent',              'name' => 'IRB-friendly consent survey',     'available' => false],
            ],
        ],
        [
            'suite_key'   => 'cx',
            'name'        => 'Customer Experience Suite',
            'description' => 'NPS, CSAT, product feedback, post-event feedback, and website experience. Pairs with the AI theme extraction on open-ended responses.',
            'color'       => '#1F3A8A',
            'icon'        => 'star',
            'entity_type' => 'survey',
            'tagline'     => 'CSAT, NPS, product and service feedback with AI theme extraction.',
            'templates'   => [
                ['key' => 'customer_satisfaction',    'name' => 'Customer satisfaction',           'available' => true],
                ['key' => 'product_feedback',         'name' => 'Product feedback',                'available' => false],
                ['key' => 'service_experience',       'name' => 'Service experience',              'available' => false],
                ['key' => 'nps_style_feedback',       'name' => 'NPS-style feedback',              'available' => false],
                ['key' => 'website_feedback',         'name' => 'Website feedback',                'available' => false],
                ['key' => 'post_event_feedback',      'name' => 'Post-event feedback',             'available' => false],
            ],
        ],
    ];
}

/**
 * Lazy seed: ensure the user has all canonical system suites. Called on
 * the first list / get request so existing users get newly-introduced
 * suites (like the Phase 134a Test & Item Analysis suite) without a
 * separate backfill migration. INSERT IGNORE means already-seeded suites
 * are no-ops.
 */
function suites_ensure_system_for_user(int $userId): void
{
    $defs = suites_system_definitions();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM suites WHERE user_id = :uid AND is_system = 1');
    $stmt->execute([':uid' => $userId]);
    $existing = (int)$stmt->fetchColumn();
    if ($existing >= count($defs)) return;

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO suites
            (user_id, suite_key, name, description, color, icon, is_system, display_order)
         VALUES
            (:uid, :key, :nm, :desc, :col, :ic, 1, :ord)'
    );
    foreach ($defs as $i => $def) {
        $ins->execute([
            ':uid'  => $userId,
            ':key'  => $def['suite_key'],
            ':nm'   => $def['name'],
            ':desc' => $def['description'],
            ':col'  => $def['color'],
            ':ic'   => $def['icon'],
            ':ord'  => ($i + 1) * 10,
        ]);
    }
}

/**
 * Confirm the caller owns the given suite. Returns the row on success.
 */
function suites_require_owned(int $suiteId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, user_id, suite_key, name, description, color, icon,
                is_system, display_order, status, created_at, updated_at
           FROM suites WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $suiteId]);
    $row = $stmt->fetch();
    if (!$row) fail('not_found', 'Suite not found.', 404);
    if ((int)$row['user_id'] !== $userId) {
        fail('forbidden', 'You can only manage your own suites.', 403);
    }
    return $row;
}

/**
 * Returns the canonical entity_type ('survey' or 'test') for a suite_key.
 * Defaults to 'survey' for any custom (non-system) suite or unknown key.
 */
function suites_entity_type_for_key(string $suiteKey): string
{
    foreach (suites_system_definitions() as $def) {
        if ($def['suite_key'] === $suiteKey) {
            return (string)($def['entity_type'] ?? 'survey');
        }
    }
    return 'survey';
}

/**
 * Build the templates payload for one suite key, merging the canonical
 * definition with whatever survey_templates() currently provides. Adds
 * 'available' (template_key matches a real template) and 'coming_soon'
 * (the opposite) flags so the UI can render disabled cards correctly.
 */
function suites_templates_for_key(string $suiteKey): array
{
    $entityType = suites_entity_type_for_key($suiteKey);

    // Build a lookup of real template keys keyed by entity_type.
    $real = [];
    if ($entityType === 'test') {
        require_once __DIR__ . '/tests/templates.php';
        if (function_exists('test_templates')) {
            foreach (test_templates() as $t) {
                $real[(string)$t['key']] = $t;
            }
        }
    } else {
        require_once __DIR__ . '/surveys/templates.php';
        if (function_exists('survey_templates')) {
            foreach (survey_templates() as $t) {
                $real[(string)$t['key']] = $t;
            }
        }
    }

    foreach (suites_system_definitions() as $def) {
        if ($def['suite_key'] !== $suiteKey) continue;
        $out = [];
        foreach ($def['templates'] as $t) {
            $tk = (string)$t['key'];
            $available = isset($real[$tk]);
            $name = $t['name'];
            $description = '';
            if ($available) {
                $description = (string)($real[$tk]['description'] ?? '');
            }
            $out[] = [
                'key'         => $tk,
                'name'        => $name,
                'description' => $description,
                'available'   => $available,
                'coming_soon' => !$available,
            ];
        }
        return $out;
    }
    return [];
}
