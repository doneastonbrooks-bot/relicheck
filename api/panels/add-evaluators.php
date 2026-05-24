<?php
// POST /api/panels/add-evaluators.php
// Body: {
//   panel_id,
//   evaluators: [
//     { subject_id?, subject_email?, evaluator_email, evaluator_name?, relationship? },
//     ...
//   ]
// }
//
// Bulk add evaluators tied to subjects on a panel. Each row resolves a
// subject by id (preferred) or by matching email. Drops rows whose subject
// or evaluator email is invalid. The UNIQUE (subject_id, evaluator_email)
// constraint on the table prevents duplicates; we count them as skipped.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_panels.php';
require_once __DIR__ . '/../_invitations.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$pid  = (int)($body['panel_id'] ?? 0);
$raw  = is_array($body['evaluators'] ?? null) ? $body['evaluators'] : [];

if ($pid <= 0) fail('bad_id', 'Missing panel_id.', 400);
$panel = panels_require_owned($pid, (int)$user['id']);
if ($panel['status'] === 'closed') {
    fail('bad_state', 'Cannot add evaluators to a closed panel.', 409);
}

$pdo = db();

// Subject id + email map for resolution.
$sStmt = $pdo->prepare('SELECT id, name, email FROM survey_360_subjects WHERE panel_id = :pid');
$sStmt->execute([':pid' => $pid]);
$sRows = $sStmt->fetchAll();
$subjectById = [];
$subjectByEmail = [];
foreach ($sRows as $r) {
    $subjectById[(int)$r['id']] = $r;
    if (!empty($r['email'])) $subjectByEmail[strtolower((string)$r['email'])] = (int)$r['id'];
}

$added = 0;
$skipped = 0;
$invalid = 0;

$ins = $pdo->prepare(
    'INSERT INTO survey_360_evaluators
        (panel_id, subject_id, evaluator_email, evaluator_name, relationship, status)
     VALUES (:pid, :sid, :em, :nm, :rel, "pending")'
);

foreach ($raw as $row) {
    if (!is_array($row)) { $invalid++; continue; }

    $subId    = (int)($row['subject_id'] ?? 0);
    $subEmail = strtolower(trim((string)($row['subject_email'] ?? '')));
    if ($subId <= 0 && $subEmail !== '') {
        $subId = $subjectByEmail[$subEmail] ?? 0;
    }
    if ($subId <= 0 || !isset($subjectById[$subId])) { $invalid++; continue; }

    $em = invitations_clean_email((string)($row['evaluator_email'] ?? ''));
    if ($em === null) { $invalid++; continue; }

    $nm = trim((string)($row['evaluator_name'] ?? ''));
    if (mb_strlen($nm) > 120) $nm = mb_substr($nm, 0, 120);

    $rel = panels_clean_relationship((string)($row['relationship'] ?? 'peer'));

    try {
        $ins->execute([
            ':pid' => $pid,
            ':sid' => $subId,
            ':em'  => $em,
            ':nm'  => $nm !== '' ? $nm : null,
            ':rel' => $rel,
        ]);
        $added++;
    } catch (Throwable $e) {
        // Almost always the UNIQUE (subject_id, evaluator_email) collision.
        $skipped++;
    }
}

json_out([
    'ok'      => true,
    'added'   => $added,
    'skipped' => $skipped,
    'invalid' => $invalid,
]);
