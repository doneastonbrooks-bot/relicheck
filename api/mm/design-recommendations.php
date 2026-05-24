<?php
// GET /api/mm/design-recommendations.php?project_id=N
//
// Returns the five mixed-methods designs (A-E) with a "recommended" flag on
// each, computed from the framing row's data_kinds and intent_purposes.
// Pure scoring logic - no AI, deterministic, fast. The front-end calls this
// when rendering Step 5 of the wizard.
//
// Scoring: each design has a profile of which data_kinds and intents push
// it up. The top 2-3 designs (by score, with a minimum threshold) get
// recommended = true. Ties surface all tied designs as recommended.
//
// Phase 170.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();
$uid  = (int)$user['id'];
$pdo  = db();

$pid = (int)($_GET['project_id'] ?? 0);
if ($pid <= 0) fail('bad_project_id', 'Missing project_id.', 400);

// Ownership guard
$own = $pdo->prepare('SELECT id FROM mm_projects WHERE id = :p AND user_id = :u LIMIT 1');
$own->execute([':p' => $pid, ':u' => $uid]);
if (!$own->fetch()) fail('not_found', 'Project not found.', 404);

// Load framing (the recommendations rely on it).
$stmt = $pdo->prepare('SELECT data_kinds, intent_purposes FROM mm_project_framing WHERE project_id = :p LIMIT 1');
$stmt->execute([':p' => $pid]);
$row = $stmt->fetch();

$dataKinds = $row && $row['data_kinds']      ? (json_decode((string)$row['data_kinds'], true) ?: []) : [];
$intents   = $row && $row['intent_purposes'] ? (json_decode((string)$row['intent_purposes'], true) ?: []) : [];

// Each design's affinity score for each slug. Higher = better fit.
// Values are tuned by what each design does well, not by guesswork:
//   A: best when survey results need a qual explanation layer
//   B: best when text needs to become numbers for a quant analysis
//   C: best when comparing groups (the joint display + group voice path)
//   D: best when text is the only quant source
//   E: the everything-included integrated report path
$DESIGNS = [
    'design_a' => [
        'name'        => 'A. Explain the numbers with comments',
        'sub'         => 'Best for surveys with open-ended follow-up questions.',
        'data_score'  => [
            'survey_plus_open'         => 3,
            'survey_plus_interviews'   => 2,
            'quant_plus_interpretation'=> 3,
            'open_only'                => 0,
            'build_from_scratch'       => 0,
        ],
        'intent_score' => [
            'explain_survey'    => 3,
            'strengthen_report' => 2,
            'find_themes'       => 1,
            'compare_groups'    => 1,
            'eval_evidence'     => 1,
        ],
    ],
    'design_b' => [
        'name'        => 'B. Turn comments into measurable support',
        'sub'         => 'Best when you have text and want quantitative support.',
        'data_score'  => [
            'survey_plus_open'         => 2,
            'open_only'                => 3,
            'survey_plus_interviews'   => 2,
            'build_from_scratch'       => 1,
            'quant_plus_interpretation'=> 1,
        ],
        'intent_score' => [
            'build_variables'   => 3,
            'find_themes'       => 2,
            'explore_patterns'  => 2,
            'compare_groups'    => 1,
        ],
    ],
    'design_c' => [
        'name'        => 'C. Compare themes across groups',
        'sub'         => 'Best for HR, education, marketing, and program evaluation.',
        'data_score'  => [
            'survey_plus_open'         => 3,
            'survey_plus_interviews'   => 3,
            'open_only'                => 2,
            'quant_plus_interpretation'=> 1,
            'build_from_scratch'       => 0,
        ],
        'intent_score' => [
            'compare_groups'    => 3,
            'find_themes'       => 2,
            'eval_evidence'     => 2,
            'explain_survey'    => 1,
            'strengthen_report' => 1,
        ],
    ],
    'design_d' => [
        'name'        => 'D. Build variables from open-ended data',
        'sub'         => 'Best when you only have qualitative responses but want statistical analysis.',
        'data_score'  => [
            'open_only'                => 3,
            'survey_plus_interviews'   => 2,
            'build_from_scratch'       => 1,
            'survey_plus_open'         => 1,
            'quant_plus_interpretation'=> 0,
        ],
        'intent_score' => [
            'build_variables'   => 3,
            'explore_patterns'  => 2,
            'find_themes'       => 2,
            'compare_groups'    => 1,
        ],
    ],
    'design_e' => [
        'name'        => 'E. Create a full integrated mixed-methods report',
        'sub'         => 'Best for research, evaluation, accreditation, or leadership reporting.',
        'data_score'  => [
            'survey_plus_open'         => 3,
            'survey_plus_interviews'   => 3,
            'open_only'                => 2,
            'quant_plus_interpretation'=> 2,
            'build_from_scratch'       => 0,
        ],
        'intent_score' => [
            'findings_section'  => 3,
            'eval_evidence'     => 3,
            'strengthen_report' => 2,
            'explain_survey'    => 2,
            'compare_groups'    => 2,
            'find_themes'       => 1,
        ],
    ],
];

// Compute a raw score for each design.
$results = [];
foreach ($DESIGNS as $slug => $def) {
    $score = 0;
    foreach ($dataKinds as $k) {
        $score += (int)($def['data_score'][$k] ?? 0);
    }
    foreach ($intents as $i) {
        $score += (int)($def['intent_score'][$i] ?? 0);
    }
    $results[$slug] = [
        'slug'        => $slug,
        'name'        => $def['name'],
        'sub'         => $def['sub'],
        'score'       => $score,
        'recommended' => false,
    ];
}

// Recommended = the top 3 designs whose score is at least 3 AND at least
// half of the top score. If everything is zero (no framing yet) we still
// surface all five but recommend none, so the UI shows the catalogue.
$top = 0;
foreach ($results as $r) { if ($r['score'] > $top) $top = $r['score']; }
$cutoff = max(3, (int)ceil($top * 0.5));
if ($top > 0) {
    // Rank, keep top 3 above cutoff.
    $sorted = array_values($results);
    usort($sorted, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $picked = 0;
    foreach ($sorted as $s) {
        if ($picked < 3 && $s['score'] >= $cutoff) {
            $results[$s['slug']]['recommended'] = true;
            $picked++;
        } else {
            break;
        }
    }
}

// Return in catalogue order (A-E) regardless of score so the layout stays
// stable as the user toggles framing options.
$ordered = [];
foreach (['design_a','design_b','design_c','design_d','design_e'] as $slug) {
    $ordered[] = $results[$slug];
}

json_out([
    'ok'              => true,
    'project_id'      => $pid,
    'designs'         => $ordered,
    'data_kinds'      => $dataKinds,
    'intent_purposes' => $intents,
]);
