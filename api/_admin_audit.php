<?php
// Admin audit log helper. Single function used by every admin endpoint to
// record a sensitive action against the admin_audit table (Phase 20).
//
// The function never throws on insert failure: audit writes must not break
// the user-visible action they accompany. We log to the PHP error log
// instead so the failure is visible in the IONOS error log without
// preventing the underlying action from completing.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

function admin_audit_log(array $actor, string $action, string $category, array $opts = []): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit
             (actor_user_id, actor_email, actor_role, action, category, severity,
              target_type, target_id, target_label, before_value, after_value,
              reason, ip, user_agent)
             VALUES
             (:auid, :aem, :arole, :act, :cat, :sev,
              :ttype, :tid, :tlabel, :bef, :aft,
              :reason, :ip, :ua)'
        );

        $cap = function ($v, $n) {
            if ($v === null) return null;
            $s = (string)$v;
            if (mb_strlen($s) <= $n) return $s;
            return mb_substr($s, 0, $n - 1) . '...';
        };

        $stmt->execute([
            ':auid'   => (int)($actor['id'] ?? 0),
            ':aem'    => $cap($actor['email'] ?? '', 190),
            ':arole'  => $cap($actor['role']  ?? 'owner', 32),
            ':act'    => $cap($action,   80),
            ':cat'    => $cap($category, 32),
            ':sev'    => $cap($opts['severity'] ?? 'info', 16),
            ':ttype'  => $cap($opts['target_type']  ?? null, 32),
            ':tid'    => $cap($opts['target_id']    ?? null, 64),
            ':tlabel' => $cap($opts['target_label'] ?? null, 255),
            ':bef'    => $cap($opts['before']       ?? null, 500),
            ':aft'    => $cap($opts['after']        ?? null, 500),
            ':reason' => $cap($opts['reason']       ?? null, 500),
            ':ip'     => $cap($_SERVER['REMOTE_ADDR']     ?? null, 64),
            ':ua'     => $cap($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[relicheck] admin_audit_log failed: ' . $e->getMessage());
    }
}
