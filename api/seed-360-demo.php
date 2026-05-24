<?php
// GET /api/seed-360-demo.php
//
// One-shot seeder for Phase 131a smoke-testing the 360 subject narrator.
// Creates a "360 Demo (Phase 131a)" panel with four subjects, each with a
// distinct narrative profile, plus synthetic responses on the panel's
// bound survey. Run once, open My Panels (360), click View report on each
// subject, and watch how the narrator card handles different patterns.
//
// PRE-REQUISITE: you must already have a survey created from the
// "Manager 360 lite (HR starter)" template. The seeder finds the most
// recent survey of yours whose title contains "Manager Feedback".
//
// SAFE TO RUN MULTIPLE TIMES: if a panel named "360 Demo (Phase 131a)"
// already exists for you, the seeder reports its id and exits without
// duplicating anything.
//
// DELETE THIS FILE AFTER SMOKE-TESTING. It's seeder tooling, not a
// production endpoint.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/_panels.php';
require_once __DIR__ . '/_invitations.php';

require_method('GET');
$user = require_auth();
$userId = (int)$user['id'];

$pdo = db();

// 1. Locate the user's Manager Feedback survey.
$sStmt = $pdo->prepare(
    "SELECT id, title, questions
       FROM surveys
      WHERE owner_id = :uid
        AND (title LIKE '%Manager Feedback%' OR title LIKE '%Manager 360%')
      ORDER BY updated_at DESC, id DESC
      LIMIT 1"
);
$sStmt->execute([':uid' => $userId]);
$survey = $sStmt->fetch();
if (!$survey) {
    fail('no_survey',
        'Could not find a "Manager Feedback" survey on your account. ' .
        'Create one from the "Manager 360 lite (HR starter)" template first, ' .
        'then re-run this seeder.',
        400
    );
}
$surveyId = (int)$survey['id'];

// Decode the questions list so we can shape response answers by question id.
$questions = json_decode((string)$survey['questions'], true);
if (!is_array($questions)) $questions = [];

$likertQs = [];   // ordered list of likert question ids
foreach ($questions as $q) {
    $qid  = (string)($q['id']   ?? '');
    $type = (string)($q['type'] ?? '');
    if ($qid === '') continue;
    if ($type === 'likert') $likertQs[] = $qid;
}
if (count($likertQs) < 6) {
    fail('bad_survey',
        'Found a Manager Feedback survey but it has fewer than 6 Likert items. ' .
        'Make sure you created it cleanly from the Manager 360 lite template.',
        400
    );
}

// 2. Reuse-or-create the demo panel.
$panelName = '360 Demo (Phase 131a)';
$pStmt = $pdo->prepare(
    'SELECT id FROM survey_360_panels
      WHERE user_id = :uid AND name = :nm LIMIT 1'
);
$pStmt->execute([':uid' => $userId, ':nm' => $panelName]);
$existing = $pStmt->fetch();
if ($existing) {
    json_out([
        'ok'         => true,
        'note'       => 'Panel already exists; nothing to do.',
        'panel_id'   => (int)$existing['id'],
        'survey_id'  => $surveyId,
        'next_step'  => 'Open My Panels (360), pick "' . $panelName . '", click View report on each subject.',
    ]);
}

$pdo->beginTransaction();
try {
    // Create the panel (active, self_assessment on, anonymous mode).
    $ins = $pdo->prepare(
        'INSERT INTO survey_360_panels
            (survey_id, user_id, name, status, self_assessment, confidentiality_mode, launched_at)
         VALUES
            (:sid, :uid, :nm, "active", 1, "anonymous", NOW())'
    );
    $ins->execute([
        ':sid' => $surveyId,
        ':uid' => $userId,
        ':nm'  => $panelName,
    ]);
    $panelId = (int)$pdo->lastInsertId();

    // 3. Four subjects with distinct narrative profiles.
    $subjectsToSeed = [
        [
            'name'       => 'Alex Rivera',
            'email'      => 'alex.demo@example.com',
            'title'      => 'Engineering Lead',
            'department' => 'Engineering',
            'profile'    => 'strong',
        ],
        [
            'name'       => 'Sam Patel',
            'email'      => 'sam.demo@example.com',
            'title'      => 'Senior Product Manager',
            'department' => 'Product',
            'profile'    => 'mostly_strong_one_weak',
        ],
        [
            'name'       => 'Jordan Lee',
            'email'      => 'jordan.demo@example.com',
            'title'      => 'Director, Operations',
            'department' => 'Operations',
            'profile'    => 'big_blind_spot',
        ],
        [
            'name'       => 'Casey Hu',
            'email'      => 'casey.demo@example.com',
            'title'      => 'Customer Success Manager',
            'department' => 'Customer Success',
            'profile'    => 'significant_gaps',
        ],
    ];

    $subIns = $pdo->prepare(
        'INSERT INTO survey_360_subjects (panel_id, name, email, title, department)
         VALUES (:pid, :nm, :em, :tt, :dp)'
    );
    $evIns = $pdo->prepare(
        'INSERT INTO survey_360_evaluators
            (panel_id, subject_id, evaluator_email, evaluator_name, relationship, status)
         VALUES (:pid, :sid, :em, :nm, :rel, "completed")'
    );

    // Evaluator pool: deterministic emails that the panel UI shows.
    $evaluatorsByRelationship = [
        'self'          => [['Self', null]],  // email substituted from subject
        'manager'       => [['Pat Morgan',   'pat.demo@example.com']],
        'peer'          => [
            ['Riley Chen',  'riley.demo@example.com'],
            ['Taylor Park', 'taylor.demo@example.com'],
        ],
        'direct_report' => [
            ['Drew Adams',  'drew.demo@example.com'],
            ['Casey Brooks','caseyb.demo@example.com'],
        ],
        'external'      => [['Morgan Reyes', 'morgan.demo@example.com']],
    ];

    $rIns = $pdo->prepare(
        'INSERT INTO responses (survey_id, submitted_at, answers, channel)
         VALUES (:sid, NOW(), :ans, :ch)'
    );

    foreach ($subjectsToSeed as $sub) {
        $subIns->execute([
            ':pid' => $panelId,
            ':nm'  => $sub['name'],
            ':em'  => $sub['email'],
            ':tt'  => $sub['title'],
            ':dp'  => $sub['department'],
        ]);
        $subjectId = (int)$pdo->lastInsertId();

        // Pick the rater set per profile.
        $raters = [];
        if ($sub['profile'] === 'strong') {
            $raters = [
                ['self',          $sub['name'],   $sub['email']],
                ['manager',       'Pat Morgan',   'pat.demo@example.com'],
                ['peer',          'Riley Chen',   'riley.demo@example.com'],
                ['peer',          'Taylor Park',  'taylor.demo@example.com'],
                ['direct_report', 'Drew Adams',   'drew.demo@example.com'],
            ];
        } elseif ($sub['profile'] === 'mostly_strong_one_weak') {
            $raters = [
                ['self',          $sub['name'],   $sub['email']],
                ['manager',       'Pat Morgan',   'pat.demo@example.com'],
                ['peer',          'Riley Chen',   'riley.demo@example.com'],
                ['peer',          'Taylor Park',  'taylor.demo@example.com'],
                ['direct_report', 'Drew Adams',   'drew.demo@example.com'],
                ['direct_report', 'Casey Brooks', 'caseyb.demo@example.com'],
            ];
        } elseif ($sub['profile'] === 'big_blind_spot') {
            $raters = [
                ['self',          $sub['name'],   $sub['email']],
                ['manager',       'Pat Morgan',   'pat.demo@example.com'],
                ['peer',          'Riley Chen',   'riley.demo@example.com'],
                ['peer',          'Taylor Park',  'taylor.demo@example.com'],
                ['direct_report', 'Drew Adams',   'drew.demo@example.com'],
                ['direct_report', 'Casey Brooks', 'caseyb.demo@example.com'],
                ['external',      'Morgan Reyes', 'morgan.demo@example.com'],
            ];
        } else { // significant_gaps
            $raters = [
                ['manager',       'Pat Morgan',   'pat.demo@example.com'],
                ['peer',          'Riley Chen',   'riley.demo@example.com'],
                ['peer',          'Taylor Park',  'taylor.demo@example.com'],
                ['direct_report', 'Drew Adams',   'drew.demo@example.com'],
                ['direct_report', 'Casey Brooks', 'caseyb.demo@example.com'],
            ];
        }

        foreach ($raters as $rt) {
            list($rel, $evName, $evEmail) = $rt;

            // Create the evaluator row marked completed (we're back-filling).
            $evIns->execute([
                ':pid' => $panelId,
                ':sid' => $subjectId,
                ':em'  => $evEmail,
                ':nm'  => $evName,
                ':rel' => $rel,
            ]);

            // Build the answers map keyed by question id.
            $answers = _seed_answers_for($likertQs, $sub['profile'], $rel);

            // Channel encoded per Phase 129.
            $relShort = ['self'=>'s','manager'=>'m','peer'=>'p','direct_report'=>'d','external'=>'e'][$rel] ?? 'p';
            $channel = '360-S' . $subjectId . '-R' . $relShort;

            $rIns->execute([
                ':sid' => $surveyId,
                ':ans' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                ':ch'  => $channel,
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('seed_failed', 'Seeder failed: ' . $e->getMessage(), 500);
}

json_out([
    'ok'         => true,
    'panel_id'   => $panelId,
    'panel_name' => $panelName,
    'survey_id'  => $surveyId,
    'survey'     => (string)$survey['title'],
    'subjects'   => count($subjectsToSeed),
    'profiles'   => [
        'Alex Rivera'  => 'Strong picture - high across the board, narrator should land "good".',
        'Sam Patel'    => 'Solid with one weak item (feedback) - narrator should land "ok".',
        'Jordan Lee'   => 'Big self-vs-others blind spot - narrator should land "warn" and call out the gap.',
        'Casey Hu'     => 'Multiple low areas - narrator should land "bad" or "warn".',
    ],
    'next_step'  => 'Open My Panels (360), pick "' . $panelName . '", click View report on each subject and inspect the AI summary card at the top.',
    'cleanup'    => 'When you are done, delete the panel from My Panels (360) (CASCADE removes subjects + evaluators) and DELETE FROM responses WHERE channel LIKE "360-S%-R%" if you also want the synthetic responses gone. Then delete this seeder file from /api/seed-360-demo.php.',
]);


// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a synthetic answers map keyed by question id, using a profile + the
 * rater's relationship to the subject to shape the ratings. Returns ints
 * 1..5 for every likert question id passed in.
 */
function _seed_answers_for(array $likertQs, string $profile, string $relationship): array {
    $out = [];

    // Profile presets: target mean + per-position bump array. Position is the
    // index of the question in the likertQs list. A small position bias
    // creates within-survey variance so the top/bottom slots actually
    // differ across items.
    foreach ($likertQs as $i => $qid) {
        $val = 4; // baseline
        switch ($profile) {

            case 'strong':
                // Everyone rates 4 or 5, slight bump up for self.
                $val = ($relationship === 'self') ? 5 : (mt_rand(0, 9) < 6 ? 5 : 4);
                if ($i === 8 && $relationship !== 'self') $val = 5; // "invites diverse viewpoints"
                break;

            case 'mostly_strong_one_weak':
                // Item 1 (index 1) is the weak one ("gives useful, timely
                // feedback") - everyone rates it 2-3 regardless of role.
                if ($i === 1) {
                    $val = mt_rand(2, 3);
                } else {
                    $val = ($relationship === 'self') ? 5 : (mt_rand(0, 9) < 5 ? 4 : 5);
                }
                break;

            case 'big_blind_spot':
                // Self rates 5 on everything. Others rate 3 on most items
                // and 2 on a couple key ones (communication, fairness).
                if ($relationship === 'self') {
                    $val = (mt_rand(0, 9) < 8) ? 5 : 4;
                } else {
                    if ($i === 0)            $val = mt_rand(2, 3); // communicates expectations clearly
                    elseif ($i === 2)        $val = mt_rand(2, 3); // treats team fairly
                    elseif ($i === 4)        $val = mt_rand(2, 3); // safe to disagree
                    else                     $val = mt_rand(3, 4);
                }
                break;

            case 'significant_gaps':
            default:
                // Multiple low ratings, especially from direct reports.
                if ($relationship === 'direct_report') {
                    $val = mt_rand(1, 3);
                } elseif ($relationship === 'peer') {
                    $val = mt_rand(2, 3);
                } elseif ($relationship === 'manager') {
                    $val = mt_rand(2, 4);
                } else {
                    $val = mt_rand(2, 4);
                }
                break;
        }

        // Clamp 1..5 just in case.
        if ($val < 1) $val = 1;
        if ($val > 5) $val = 5;
        $out[$qid] = $val;
    }

    return $out;
}
