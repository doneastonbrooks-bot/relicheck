<?php
// POST /api/mm/seed.php
// Creates a small sample MM Studio project so the user can exercise the
// wizard end-to-end without uploading anything. The project is real (lives
// in the user's account, deletable), but the data is generated, not real.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// Sample dataset: 30 employee-engagement responses with a 1-5 score and a
// department group, plus a free-text comment.
$samples = [
    ['Engineering', 4, 'Onboarding was thorough and my manager has been supportive.'],
    ['Engineering', 3, 'Communication from leadership is inconsistent across initiatives.'],
    ['Engineering', 2, 'Workload has been heavy for two quarters and recognition is rare.'],
    ['Engineering', 5, 'Cross-team collaboration has improved a lot this year.'],
    ['Engineering', 1, 'I feel disconnected from the direction of the company.'],
    ['Sales',       4, 'Targets are clear and the commission structure feels fair.'],
    ['Sales',       3, 'Some quarters feel achievable, others feel set up to fail.'],
    ['Sales',       2, 'Pipeline tools are clunky and slow us down on a daily basis.'],
    ['Sales',       5, 'My team lead invests in coaching and it shows in the numbers.'],
    ['Sales',       3, 'Onboarding for new reps could be a lot stronger.'],
    ['Operations',  3, 'Process changes are announced without input from the people doing the work.'],
    ['Operations',  4, 'Our weekly standups have made handoffs much cleaner.'],
    ['Operations',  2, 'Burnout is real on the night shift and nobody is addressing it.'],
    ['Operations',  4, 'Cross-training has finally been resourced this year.'],
    ['Operations',  1, 'I plan to leave within six months if nothing changes.'],
    ['Marketing',   5, 'Creative latitude here is the best I have had in my career.'],
    ['Marketing',   3, 'Campaign approval cycles take longer than they should.'],
    ['Marketing',   4, 'Analytics support has improved and decisions are more data-informed.'],
    ['Marketing',   2, 'Decisions get reversed by leadership without explanation too often.'],
    ['Marketing',   4, 'I feel my work has visible impact on the business.'],
    ['HR',          3, 'Benefits are competitive but communication about them is poor.'],
    ['HR',          5, 'The new performance review framework is a big improvement.'],
    ['HR',          2, 'We talk about culture more than we change it.'],
    ['HR',          4, 'Hiring practices have become more inclusive this year.'],
    ['HR',          3, 'Manager training would help propagate good practices we already have.'],
    ['Customer Support', 2, 'Ticket volumes are unrelenting and staffing has not kept pace.'],
    ['Customer Support', 4, 'Knowledge base improvements have cut handle times.'],
    ['Customer Support', 1, 'Morale is low and exit interviews keep flagging the same issues.'],
    ['Customer Support', 5, 'My direct manager is the reason I stay; she is excellent.'],
    ['Customer Support', 3, 'Cross-functional escalations to product still take too long.'],
];

$pdo->beginTransaction();
try {
    // Phase 156b: data_kinds and purposes are JSON arrays. Stamp the sample
    // project as already past the wizard so the user lands on the working
    // surface (Step 1 of the project) instead of being sent back through the
    // 5-step setup wizard.
    $ins = $pdo->prepare(
        'INSERT INTO mm_projects
            (user_id, title, pathway, data_kinds, purposes, design_choice, wizard_completed_at, status)
         VALUES
            (:u, :t, "comments_only", :dk, :pu, "A_explain_numbers", NOW(), "active")'
    );
    $ins->execute([
        ':u'  => $uid,
        ':t'  => 'Sample: Employee Engagement (30 responses)',
        ':dk' => '["survey_plus_open"]',
        ':pu' => '["explain_survey_results"]',
    ]);
    $projectId = (int)$pdo->lastInsertId();

    $insSrc = $pdo->prepare(
        'INSERT INTO mm_data_sources
         (project_id, source_type, format, source_ref, label, row_count)
         VALUES (:p, "paste", "paste", NULL, :l, :rc)'
    );
    $insSrc->execute([
        ':p'  => $projectId,
        ':l'  => 'Sample data',
        ':rc' => count($samples),
    ]);
    $sourceId = (int)$pdo->lastInsertId();

    foreach ($samples as $i => $row) {
        list($dept, $score, $text) = $row;
        mm_insert_text_response(
            $pdo, $projectId, $sourceId,
            'R' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
            $dept,
            (float)$score,
            $text
        );
    }

    $pdo->commit();
    json_out(['ok' => true, 'project_id' => $projectId, 'response_count' => count($samples)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_seed_failed', 'Could not seed sample data: ' . $e->getMessage(), 500);
}
