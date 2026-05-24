<?php
// Helpers for team seats / multi-user access.
//
// Glossary:
//   owner_id   The original user account that "owns" a workspace.
//   member_id  Another user who has been granted access.
//   role       'owner' | 'editor' | 'viewer'.
//
// The owner is implicit: every user is the owner of their own workspace
// without needing a row in account_members. Members get rows.
//
// Endpoints that previously did `WHERE owner_id = :u` should switch to
// `WHERE owner_id IN (accessible_owner_ids(...))` so a member sees the
// shared workspace's resources alongside their own.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

const ROLE_OWNER  = 'owner';
const ROLE_EDITOR = 'editor';
const ROLE_VIEWER = 'viewer';

const ROLE_RANK = [
    ROLE_VIEWER => 1,
    ROLE_EDITOR => 2,
    ROLE_OWNER  => 3,
];

/** All workspace-owner ids the given user can access (their own + memberships). */
function accessible_owner_ids(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $stmt = db()->prepare(
        'SELECT owner_id FROM account_members WHERE member_id = :u'
    );
    $stmt->execute([':u' => $userId]);
    $ids = [$userId];
    foreach ($stmt->fetchAll() as $r) $ids[] = (int)$r['owner_id'];
    $ids = array_values(array_unique($ids));
    $cache[$userId] = $ids;
    return $ids;
}

/** Build a SQL placeholder list and named-bind params for an IN clause.
 *  Returns ['(:o0,:o1,...)', [':o0' => 1, ...]]. Empty input becomes
 *  '(NULL)' which matches no rows (safe default). */
function in_clause_owner_ids(array $ids): array
{
    if (!$ids) return ['(NULL)', []];
    $placeholders = [];
    $params = [];
    foreach (array_values($ids) as $i => $id) {
        $key = ':o' . $i;
        $placeholders[] = $key;
        $params[$key] = (int)$id;
    }
    return ['(' . implode(',', $placeholders) . ')', $params];
}

/** Return the role $userId has on $ownerId's workspace, or null if none. */
function member_role_for(int $userId, int $ownerId): ?string
{
    if ($userId === $ownerId) return ROLE_OWNER;
    $stmt = db()->prepare(
        'SELECT role FROM account_members WHERE owner_id = :o AND member_id = :m LIMIT 1'
    );
    $stmt->execute([':o' => $ownerId, ':m' => $userId]);
    $row = $stmt->fetch();
    return $row ? (string)$row['role'] : null;
}

/** Fail with 403 unless $userId has at least $minRole on $ownerId. */
function require_role(int $userId, int $ownerId, string $minRole): string
{
    $have = member_role_for($userId, $ownerId);
    if ($have === null) fail('forbidden', 'You do not have access to this workspace.', 403);
    $needRank = ROLE_RANK[$minRole] ?? 999;
    $haveRank = ROLE_RANK[$have]   ?? 0;
    if ($haveRank < $needRank) {
        fail('insufficient_role', 'Your role does not allow this action. Required: ' . $minRole . ', you have: ' . $have . '.', 403);
    }
    return $have;
}

/** Count active members + pending invitations for an owner's workspace.
 *  Used to enforce the team_sharing tier cap. */
function count_seats_used(int $ownerId): int
{
    $pdo = db();
    $m = (int)$pdo->prepare('SELECT COUNT(*) AS c FROM account_members WHERE owner_id = :o')
                  ->execute([':o' => $ownerId]) ?: 0;
    // The above pattern won't return - fix:
    $stmt1 = $pdo->prepare('SELECT COUNT(*) AS c FROM account_members WHERE owner_id = :o');
    $stmt1->execute([':o' => $ownerId]);
    $members = (int)$stmt1->fetch()['c'];
    $stmt2 = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM account_invitations
          WHERE owner_id = :o AND accepted_at IS NULL AND declined_at IS NULL AND expires_at > NOW()'
    );
    $stmt2->execute([':o' => $ownerId]);
    $pending = (int)$stmt2->fetch()['c'];
    return $members + $pending;
}
