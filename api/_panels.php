<?php
// api/_panels.php
// Helper library for Phase 129 (360 / multi-rater surveys). Pure functions.
// Endpoint handlers in api/panels/ require_once this file.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

/**
 * Confirm the caller owns the given 360 panel. Returns the panel row (with
 * the survey title joined in) on success; calls fail() otherwise.
 */
function panels_require_owned(int $panelId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.survey_id, p.user_id, p.name, p.status,
                p.self_assessment, p.confidentiality_mode,
                p.launched_at, p.closed_at, p.created_at, p.updated_at,
                s.title AS survey_title
           FROM survey_360_panels p
           JOIN surveys s ON s.id = p.survey_id
          WHERE p.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $panelId]);
    $row = $stmt->fetch();
    if (!$row) fail('not_found', 'Panel not found.', 404);
    if ((int)$row['user_id'] !== $userId) {
        fail('forbidden', 'You can only manage your own panels.', 403);
    }
    return $row;
}

/**
 * Normalize a relationship string to one of the enum values. Returns 'peer'
 * for anything unrecognized so a typo never blocks a launch.
 */
function panels_clean_relationship(string $raw): string
{
    $r = strtolower(trim($raw));
    $r = str_replace([' ', '-'], '_', $r);
    $map = [
        'self'          => 'self',
        'manager'       => 'manager',
        'boss'          => 'manager',
        'supervisor'    => 'manager',
        'peer'          => 'peer',
        'colleague'     => 'peer',
        'coworker'      => 'peer',
        'direct_report' => 'direct_report',
        'report'        => 'direct_report',
        'subordinate'   => 'direct_report',
        'external'      => 'external',
        'client'        => 'external',
        'customer'      => 'external',
        'vendor'        => 'external',
    ];
    return $map[$r] ?? 'peer';
}

/**
 * Channel encoding for Phase 129. Format: "360-S<subject_id>-R<rel_short>".
 * rel_short is one character (s/m/p/d/e) for self/manager/peer/direct_report/external.
 */
function panels_channel_for(int $subjectId, string $relationship): string
{
    $shortMap = [
        'self'          => 's',
        'manager'       => 'm',
        'peer'          => 'p',
        'direct_report' => 'd',
        'external'      => 'e',
    ];
    $short = $shortMap[$relationship] ?? 'p';
    return '360-S' . $subjectId . '-R' . $short;
}

/**
 * Reverse of panels_channel_for: pull (subject_id, relationship) out of a
 * channel string. Returns null when the channel does not match the format.
 */
function panels_parse_channel(string $channel): ?array
{
    if (!preg_match('/^360-S(\d+)-R([smpde])$/', $channel, $m)) return null;
    $relMap = [
        's' => 'self',
        'm' => 'manager',
        'p' => 'peer',
        'd' => 'direct_report',
        'e' => 'external',
    ];
    return [
        'subject_id'   => (int)$m[1],
        'relationship' => $relMap[$m[2]],
    ];
}
