<?php
// POST /api/panels/add-subjects.php
// Body: { panel_id, subjects: [{name, email?, title?, department?, external_ref?}, ...] }
//
// Bulk insert subjects on a panel. Drops blank rows. De-dupes against the
// panel's existing subjects by (case-insensitive) email when an email is
// present, or by name when it is not.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_panels.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$pid  = (int)($body['panel_id'] ?? 0);
$raw  = is_array($body['subjects'] ?? null) ? $body['subjects'] : [];

if ($pid <= 0) fail('bad_id', 'Missing panel_id.', 400);
$panel = panels_require_owned($pid, (int)$user['id']);
if ($panel['status'] === 'closed') {
    fail('bad_state', 'Cannot add subjects to a closed panel.', 409);
}

$pdo = db();

// Existing-subject de-dupe map.
$exStmt = $pdo->prepare('SELECT id, name, email FROM survey_360_subjects WHERE panel_id = :pid');
$exStmt->execute([':pid' => $pid]);
$exRows = $exStmt->fetchAll();
$haveEmail = [];
$haveName  = [];
foreach ($exRows as $r) {
    if (!empty($r['email'])) $haveEmail[strtolower((string)$r['email'])] = (int)$r['id'];
    $haveName[strtolower(trim((string)$r['name']))] = (int)$r['id'];
}

$added = 0;
$skipped = 0;
$created = [];

$ins = $pdo->prepare(
    'INSERT INTO survey_360_subjects
        (panel_id, name, email, title, department, external_ref)
     VALUES (:pid, :nm, :em, :tt, :dp, :xr)'
);

foreach ($raw as $row) {
    if (!is_array($row)) { $skipped++; continue; }
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') { $skipped++; continue; }
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    $emailRaw = trim((string)($row['email'] ?? ''));
    $email = null;
    if ($emailRaw !== '') {
        $clean = filter_var(strtolower($emailRaw), FILTER_VALIDATE_EMAIL) ? strtolower($emailRaw) : null;
        if ($clean !== null && mb_strlen($clean) <= 255) $email = $clean;
    }

    if ($email !== null && isset($haveEmail[$email])) { $skipped++; continue; }
    if ($email === null && isset($haveName[strtolower($name)])) { $skipped++; continue; }

    $title = trim((string)($row['title'] ?? ''));      if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
    $dept  = trim((string)($row['department'] ?? '')); if (mb_strlen($dept)  > 120) $dept  = mb_substr($dept, 0, 120);
    $xref  = trim((string)($row['external_ref'] ?? '')); if (mb_strlen($xref) > 120) $xref = mb_substr($xref, 0, 120);

    try {
        $ins->execute([
            ':pid' => $pid,
            ':nm'  => $name,
            ':em'  => $email,
            ':tt'  => $title !== '' ? $title : null,
            ':dp'  => $dept  !== '' ? $dept  : null,
            ':xr'  => $xref  !== '' ? $xref  : null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        $created[] = [
            'id'    => $newId,
            'name'  => $name,
            'email' => $email,
        ];
        if ($email !== null) $haveEmail[$email] = $newId;
        $haveName[strtolower($name)] = $newId;
        $added++;
    } catch (Throwable $e) {
        $skipped++;
    }
}

json_out([
    'ok'      => true,
    'added'   => $added,
    'skipped' => $skipped,
    'created' => $created,
]);
