<?php
// GET /api/admin/cron/process_deletions.php?key=<email_cron_key>
//
// Daily cron worker. Finds customer accounts whose grace window has ended
// and hard-deletes them. For each one:
//   1. Fires the account.deleted email FIRST (while the row still exists,
//      so the dispatcher can resolve the recipient and queue the email).
//   2. Deletes the user row. Existing FK ON DELETE CASCADE constraints take
//      care of surveys, responses, datasets, etc.
//
// The cron is auth-gated by the same email_cron_key as queue_run.php so a
// passerby can't trigger a deletion sweep.
//
// Each call processes up to 50 accounts and returns a JSON summary.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_cron.php';

require_method('GET', 'POST');

$cfg = relicheck_config();
$expected = (string)($cfg['email_cron_key'] ?? '');
if ($expected === '') {
    fail('not_configured', 'email_cron_key is not set in api/_config.php.', 500);
}
$given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
if (!hash_equals($expected, $given)) {
    fail('forbidden', 'Invalid cron key.', 403);
}

cron_heartbeat_start('process_deletions');

$pdo = db();

// Fetch up to 50 due deletions. Use NOW() in SQL to dodge timezone drift.
$rows = $pdo->query(
    "SELECT id, email, name
     FROM users
     WHERE deletion_grace_ends_at IS NOT NULL
       AND deletion_grace_ends_at <= NOW()
     ORDER BY deletion_grace_ends_at ASC
     LIMIT 50"
)->fetchAll();

$considered = count($rows);
$deleted    = 0;
$skipped    = 0;
$errors     = [];

require_once __DIR__ . '/../../_email_dispatcher.php';

foreach ($rows as $u) {
    $uid   = (int)$u['id'];
    $email = (string)$u['email'];
    $name  = (string)$u['name'];
    $first = trim(explode(' ', $name)[0] ?: 'there');

    // Step 1: queue the goodbye email BEFORE the row goes away.
    try {
        relicheck_email_dispatch('account.deleted', [
            'user_id'    => $uid,
            'account_id' => $uid,
            'idempotency_entity_id' => 'deletion-final:' . $uid,
            'payload'    => [
                'first_name' => $first,
                'email'      => $email,
                'deleted_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    } catch (Throwable $e) {
        // Log but proceed with deletion: we don't want one bad mail step
        // to keep an account alive past its grace window.
        error_log('[relicheck] account.deleted dispatch failed for uid ' . $uid . ': ' . $e->getMessage());
    }

    // Step 2: hard delete. Cascade FKs handle surveys, responses, datasets,
    // billing rows, etc. Wrap in try/catch so one stuck row doesn't abort
    // the whole batch.
    try {
        $del = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
        $del->execute([':id' => $uid]);
        if ($del->rowCount() > 0) {
            $deleted++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        $errors[] = ['user_id' => $uid, 'email' => $email, 'error' => substr($e->getMessage(), 0, 240)];
    }
}

$_cronSummary = [
    'ok'         => true,
    'considered' => $considered,
    'deleted'    => $deleted,
    'skipped'    => $skipped,
    'errors'     => $errors,
    'message'    => "Considered $considered, deleted $deleted, skipped $skipped, errors " . count($errors),
];
cron_heartbeat_done('process_deletions', $_cronSummary, count($errors) > 0 ? (count($errors) . ' deletion(s) failed') : null);
json_out($_cronSummary);
