<?php
// POST /api/contacts/import.php
// Body: { survey_id, lines: string }
// Bulk import contacts from a pasted CSV. Each line is parsed as either
//   email
//   email,name
//   email,name,external_ref
// Blank lines and lines starting with '#' are skipped. Returns a summary
// counting how many were added, skipped (duplicate), and rejected (invalid).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('POST');
check_origin();
$user = require_auth();

$body  = read_json_body();
$sid   = (int)($body['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);
invitations_require_survey_owned_by($sid, (int)$user['id']);

$lines = (string)($body['lines'] ?? '');
if (mb_strlen($lines) > 1024 * 1024) {
    fail('too_large', 'Paste is too large (max 1 MB).', 413);
}

$pdo = db();

$ins = $pdo->prepare(
    'INSERT IGNORE INTO survey_contacts (survey_id, email, name, external_ref, added_by)
     VALUES (:sid, :em, :nm, :ext, :uid)'
);

$added = 0; $skipped = 0; $rejected = 0;
$errors = [];

foreach (preg_split('/\r?\n/', $lines) as $rawLine) {
    $line = trim($rawLine);
    if ($line === '' || $line[0] === '#') continue;

    // Tolerate quoted CSV via str_getcsv; fall back to a simple split.
    $cols = str_getcsv($line);
    $email = invitations_clean_email((string)($cols[0] ?? ''));
    if (!$email) { $rejected++; if (count($errors) < 5) $errors[] = "Invalid email: " . $line; continue; }

    $name = isset($cols[1]) ? trim((string)$cols[1]) : '';
    $ext  = isset($cols[2]) ? trim((string)$cols[2]) : '';
    if ($name === '') $name = null; elseif (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
    if ($ext  === '') $ext  = null; elseif (mb_strlen($ext)  > 120) $ext  = mb_substr($ext,  0, 120);

    try {
        $ins->execute([
            ':sid' => $sid,
            ':em'  => $email,
            ':nm'  => $name,
            ':ext' => $ext,
            ':uid' => (int)$user['id'],
        ]);
        if ($ins->rowCount() === 1) $added++;
        else $skipped++; // duplicate (matched the unique key)
    } catch (Throwable $e) {
        $rejected++;
        if (count($errors) < 5) $errors[] = "DB error on " . $email . ': ' . $e->getMessage();
    }
}

json_out([
    'added'    => $added,
    'skipped'  => $skipped,
    'rejected' => $rejected,
    'errors'   => $errors,
]);
