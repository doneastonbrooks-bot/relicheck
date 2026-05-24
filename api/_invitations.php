<?php
// api/_invitations.php
// Helper library for Phase 38 distribution module. Pure functions, no
// HTTP-side effects beyond what each function explicitly does. Endpoint
// handlers in api/contacts/ and api/invitations/ require_once this file.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

/**
 * Generate a 32-character lowercase-hex invitation token. Collisions on a
 * 128-bit token are negligible; the table has a UNIQUE constraint as a
 * second line of defense.
 */
function invitations_generate_token(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Build the public-facing invitation URL for a token.
 */
function invitations_link_for_token(string $token): string
{
    $cfg     = relicheck_config();
    $siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');
    return $siteUrl . '/api/public/inv.php?t=' . urlencode($token);
}

/**
 * Build the public-facing unsubscribe URL for an invitation token.
 * (Phase 41) Survey contacts are emails, not users, so we key on the
 * invitation token rather than the Phase 31 unsubscribe_tokens table.
 */
function invitations_unsubscribe_link(string $token): string
{
    $cfg     = relicheck_config();
    $siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');
    return $siteUrl . '/api/public/contact_unsubscribe.php?t=' . urlencode($token);
}

/**
 * Mark an invitation as sent (status -> sent, sent_at = NOW()). Used by
 * api/invitations/send.php after the email is enqueued.
 */
function invitations_mark_sent(int $invitationId, ?int $emailLogId = null): void
{
    $sql = 'UPDATE survey_invitations
               SET status = "sent",
                   sent_at = NOW()' .
                   ($emailLogId !== null ? ', email_log_id = :elog' : '') .
            ' WHERE id = :id';
    $params = [':id' => $invitationId];
    if ($emailLogId !== null) $params[':elog'] = $emailLogId;
    db()->prepare($sql)->execute($params);
}

/**
 * Mark an invitation as opened (the personalized link was visited).
 * Idempotent: only sets opened_at on first visit.
 */
function invitations_mark_opened(string $token): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, status, opened_at FROM survey_invitations WHERE invitation_token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (empty($row['opened_at'])) {
        $upd = $pdo->prepare(
            'UPDATE survey_invitations
                SET opened_at = NOW(),
                    status = CASE WHEN status IN ("queued","sent") THEN "opened" ELSE status END
              WHERE id = :id'
        );
        $upd->execute([':id' => $row['id']]);
    }
    return $row;
}

/**
 * Mark an invitation as completed because a response was just submitted via
 * its tracked link. Called from api/public/submit.php in a try/catch so a
 * bad token never blocks submission.
 */
function invitations_mark_completed(string $token, ?int $responseId = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM survey_invitations WHERE invitation_token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    if (!$row) return;
    $pdo->prepare(
        'UPDATE survey_invitations
            SET completed_at = COALESCE(completed_at, NOW()),
                status = "completed"
          WHERE id = :id'
    )->execute([':id' => (int)$row['id']]);
}

/**
 * Returns the survey owner row (id, name, email) for a survey, or null.
 */
function invitations_survey_owner(int $surveyId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email
           FROM users u JOIN surveys s ON s.owner_id = u.id
          WHERE s.id = :sid LIMIT 1'
    );
    $stmt->execute([':sid' => $surveyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Confirm the caller owns the given survey. Throws via fail() on mismatch.
 */
function invitations_require_survey_owned_by(int $surveyId, int $userId): array
{
    $stmt = db()->prepare('SELECT id, owner_id, slug, title FROM surveys WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $surveyId]);
    $row = $stmt->fetch();
    if (!$row) fail('not_found', 'Survey not found.', 404);
    if ((int)$row['owner_id'] !== $userId) {
        fail('forbidden', 'You can only manage your own surveys.', 403);
    }
    return $row;
}

/**
 * Validate and normalize an email address.
 */
function invitations_clean_email(string $raw): ?string
{
    $e = trim(strtolower($raw));
    if ($e === '' || mb_strlen($e) > 255) return null;
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return null;
    return $e;
}

/**
 * Returns the invitations whose next reminder is due. Used by the daily
 * cron. Filters by:
 *   - reminder_count < schedule.max_reminders
 *   - status NOT IN (completed, bounced, failed)
 *   - schedule.enabled = 1
 *   - elapsed time since (sent_at, last_reminder_at) >= configured cadence
 */
function invitations_due_for_reminder(int $limit = 500): array
{
    $sql = "
      SELECT inv.id, inv.survey_id, inv.contact_id, inv.invitation_token,
             inv.reminder_count, inv.sent_at, inv.last_reminder_at,
             c.email AS contact_email, c.name AS contact_name,
             s.title AS survey_title, s.owner_id,
             sch.days_until_first, sch.days_between, sch.max_reminders
        FROM survey_invitations inv
        JOIN survey_contacts c ON c.id = inv.contact_id
        JOIN surveys s ON s.id = inv.survey_id
        JOIN survey_reminder_schedules sch ON sch.survey_id = inv.survey_id
       WHERE sch.enabled = 1
         AND inv.status NOT IN ('completed','bounced','failed')
         AND inv.reminder_count < sch.max_reminders
         AND inv.sent_at IS NOT NULL
         AND (
              (inv.reminder_count = 0 AND inv.sent_at <= DATE_SUB(NOW(), INTERVAL sch.days_until_first DAY))
              OR
              (inv.reminder_count > 0 AND inv.last_reminder_at <= DATE_SUB(NOW(), INTERVAL sch.days_between DAY))
             )
       ORDER BY inv.sent_at ASC
       LIMIT " . (int)$limit;
    return db()->query($sql)->fetchAll();
}
