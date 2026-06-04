<?php
// GET /api/qual/build-report.php?project_id=N
// Aggregates all data needed for the Report Builder:
//   project meta, dataset stats, themes + claims + categories + pinned quotes,
//   member checks, audit count.
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
release_session_lock();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

$proj = qual_require_project($pdo, $uid, $projectId);

// ── Dataset stats ─────────────────────────────────────────────────────────────
$statSt = $pdo->prepare(
    "SELECT COUNT(*) AS seg_count,
            COALESCE(SUM(word_count),0)  AS total_words,
            COALESCE(AVG(word_count),0)  AS avg_words
     FROM qual_segments WHERE project_id=:p"
);
$statSt->execute([':p' => $projectId]);
$stats = $statSt->fetch(PDO::FETCH_ASSOC);

$codesSt = $pdo->prepare(
    "SELECT COUNT(*) FROM qual_codes WHERE project_id=:p AND status<>'retired'"
);
$codesSt->execute([':p' => $projectId]);
$codeCount = (int)$codesSt->fetchColumn();

// ── Themes ────────────────────────────────────────────────────────────────────
$themeSt = $pdo->prepare(
    "SELECT id, name, interpretive_claim, notes
     FROM qual_themes WHERE project_id=:p ORDER BY position, id"
);
$themeSt->execute([':p' => $projectId]);
$themes = $themeSt->fetchAll(PDO::FETCH_ASSOC);

foreach ($themes as &$theme) {
    // Supporting categories
    $catSt = $pdo->prepare(
        "SELECT c.name FROM qual_theme_categories tc
         JOIN qual_categories c ON c.id=tc.category_id
         WHERE tc.theme_id=:t ORDER BY c.name"
    );
    $catSt->execute([':t' => $theme['id']]);
    $theme['categories'] = array_column($catSt->fetchAll(PDO::FETCH_ASSOC), 'name');

    // Pinned exemplar quotes (up to 5)
    $qSt = $pdo->prepare(
        "SELECT s.raw_text, s.cleaned_text, s.participant_id, s.question_ref
         FROM qual_theme_quotes q
         JOIN qual_segments s ON s.id=q.segment_id
         WHERE q.project_id=:p AND q.theme_id=:t
         ORDER BY q.created_at LIMIT 5"
    );
    $qSt->execute([':p' => $projectId, ':t' => $theme['id']]);
    $theme['quotes'] = $qSt->fetchAll(PDO::FETCH_ASSOC);
}
unset($theme);

// ── Member checks ─────────────────────────────────────────────────────────────
$mcSt = $pdo->prepare(
    "SELECT body, created_at FROM qual_memos
     WHERE project_id=:p AND memo_type='member_check' AND object_type='project'
     ORDER BY id ASC"
);
$mcSt->execute([':p' => $projectId]);
$memberChecks = [];
foreach ($mcSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $data = json_decode((string)$row['body'], true) ?: [];
    $data['created_at'] = (string)$row['created_at'];
    $memberChecks[] = $data;
}

// ── Audit trail count ─────────────────────────────────────────────────────────
$aCountSt = $pdo->prepare("SELECT COUNT(*) FROM qual_audit_trail WHERE project_id=:p");
$aCountSt->execute([':p' => $projectId]);
$auditCount = (int)$aCountSt->fetchColumn();

json_out([
    'ok'      => true,
    'project' => $proj,
    'stats'   => [
        'seg_count'   => (int)($stats['seg_count']   ?? 0),
        'total_words' => (int)($stats['total_words']  ?? 0),
        'avg_words'   => (int)round((float)($stats['avg_words'] ?? 0)),
        'code_count'  => $codeCount,
        'theme_count' => count($themes),
    ],
    'themes'        => $themes,
    'member_checks' => $memberChecks,
    'audit_count'   => $auditCount,
]);
