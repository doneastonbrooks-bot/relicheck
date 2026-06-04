<?php
// GET /api/qual/export-coded.php?project_id=N
// Streams a CSV of all segments with their applied codes.
// One row per segment; codes are semicolon-joined in a single column.
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

$user      = require_auth();
release_session_lock();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); echo 'Missing project_id.'; exit; }

$proj = qual_require_project($pdo, $uid, $projectId);

// Segments + codes (GROUP_CONCAT keeps one row per segment)
$segSt = $pdo->prepare(
    "SELECT s.id, s.participant_id, s.question_ref,
            COALESCE(s.cleaned_text, s.raw_text) AS response_text,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '; ') AS codes_applied,
            GROUP_CONCAT(DISTINCT th.name ORDER BY th.name SEPARATOR '; ')  AS themes
     FROM qual_segments s
     LEFT JOIN qual_code_applications ca
           ON ca.segment_id=s.id AND ca.project_id=s.project_id
           AND ca.coder_type='human' AND ca.action_type='applied'
     LEFT JOIN qual_codes c ON c.id=ca.code_id
     LEFT JOIN qual_theme_quotes tq ON tq.segment_id=s.id AND tq.project_id=s.project_id
     LEFT JOIN qual_themes th ON th.id=tq.theme_id
     WHERE s.project_id=:p
     GROUP BY s.id, s.participant_id, s.question_ref, s.seg_order, response_text
     ORDER BY s.seg_order, s.id"
);
$segSt->execute([':p' => $projectId]);
$rows = $segSt->fetchAll(PDO::FETCH_ASSOC);

$slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($proj['title'] ?? 'qual')));
$filename = 'qual-coded-' . $slug . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputcsv($out, ['Segment ID', 'Participant ID', 'Question', 'Response Text', 'Codes Applied', 'Themes']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        $r['participant_id'] ?? '',
        $r['question_ref']   ?? '',
        $r['response_text']  ?? '',
        $r['codes_applied']  ?? '',
        $r['themes']         ?? '',
    ]);
}
fclose($out);
exit;
