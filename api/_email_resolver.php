<?php
// ReliCheck email recipient resolver.
//
// Each event row in email_events names a resolver. The dispatcher calls
// relicheck_email_resolve_recipients($resolver, $context) to get back a list
// of [{user_id, email, name, role, account_id, audience}].
//
// Add new resolvers as small functions and wire them in the switch below.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function relicheck_email_resolve_recipients(string $resolver, array $context): array
{
    switch ($resolver) {
        case 'customer_self':                return rcv_customer_self($context);
        case 'employee_self':                return rcv_employee_self($context);
        case 'invited_employee':             return rcv_invited_employee($context);
        case 'all_customers':                return rcv_all_customers($context);
        case 'assigned_agent':               return rcv_assigned_agent($context);
        case 'assigned_agent_plus_supervisor': return rcv_assigned_agent_plus_supervisor($context);
        case 'supervisor_plus_owner':        return rcv_supervisor_plus_owner($context);
        case 'privacy_officers':             return rcv_role_holders('privacy_officer');
        case 'legal_owners':                 return rcv_role_holders('legal_owner');
        case 'sales_reps':                   return rcv_role_holders('sales_rep');
        case 'oncall':                       return rcv_role_holders('oncall');
        case 'membership_and_marketing':     return array_merge(
                                                rcv_role_holders('membership_ops'),
                                                rcv_role_holders('marketing_lead')
                                              );
        case 'assigned_services_rep':        return rcv_assigned_services_rep($context);
        case 'customer_plus_membership_ops':
        case 'customer_plus_billing_ops':
        case 'customer_plus_assigned_agent':
            // Compound resolvers: dispatcher handles both sides via separate
            // template_id lookups; here we return ONLY the customer.
            return rcv_customer_self($context);
        default:
            return [];
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function rcv_customer_self(array $ctx): array
{
    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid <= 0) return [];
    $stmt = db()->prepare('SELECT id, email, name FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $u = $stmt->fetch();
    if (!$u) return [];
    return [[
        'user_id'    => (int)$u['id'],
        'email'      => (string)$u['email'],
        'name'       => (string)$u['name'],
        'role'       => 'customer',
        'account_id' => $ctx['account_id'] ?? (int)$u['id'],
        'audience'   => 'customer',
    ]];
}

function rcv_employee_self(array $ctx): array
{
    $uid = (int)($ctx['employee_user_id'] ?? $ctx['user_id'] ?? 0);
    if ($uid <= 0) return [];
    $stmt = db()->prepare('SELECT id, email, name FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $u = $stmt->fetch();
    if (!$u) return [];
    return [[
        'user_id'    => (int)$u['id'],
        'email'      => (string)$u['email'],
        'name'       => (string)$u['name'],
        'role'       => (string)($ctx['role'] ?? 'employee'),
        'account_id' => null,
        'audience'   => 'employee',
    ]];
}

function rcv_invited_employee(array $ctx): array
{
    $email = (string)($ctx['email'] ?? '');
    if ($email === '') return [];
    return [[
        'user_id'    => null,
        'email'      => $email,
        'name'       => (string)($ctx['first_name'] ?? ''),
        'role'       => (string)($ctx['role_name'] ?? 'employee'),
        'account_id' => null,
        'audience'   => 'employee',
    ]];
}

function rcv_all_customers(array $ctx): array
{
    // Caution: large result set. Caller should paginate / batch in a worker.
    $rows = db()->query('SELECT id, email, name FROM users WHERE deleted_at IS NULL')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id'    => (int)$r['id'],
            'email'      => (string)$r['email'],
            'name'       => (string)$r['name'],
            'role'       => 'customer',
            'account_id' => (int)$r['id'],
            'audience'   => 'customer',
        ];
    }
    return $out;
}

function rcv_assigned_agent(array $ctx): array
{
    $uid = (int)($ctx['assigned_agent_user_id'] ?? 0);
    if ($uid <= 0) return [];
    return rcv_employee_self(['employee_user_id' => $uid, 'role' => 'support_agent']);
}

function rcv_assigned_agent_plus_supervisor(array $ctx): array
{
    $out = rcv_assigned_agent($ctx);
    foreach (rcv_role_holders('support_supervisor') as $s) {
        $out[] = $s;
    }
    return rcv_dedupe_by_email($out);
}

function rcv_supervisor_plus_owner(array $ctx): array
{
    return rcv_dedupe_by_email(array_merge(
        rcv_role_holders('support_supervisor'),
        rcv_role_holders('owner')
    ));
}

function rcv_assigned_services_rep(array $ctx): array
{
    $uid = (int)($ctx['assigned_rep_user_id'] ?? 0);
    if ($uid <= 0) return rcv_role_holders('services_rep');
    return rcv_employee_self(['employee_user_id' => $uid, 'role' => 'services_rep']);
}

function rcv_role_holders(string $role_code): array
{
    // Resolves all employees holding a given role. Schema assumes a join table
    // `employee_roles(user_id, role_code)`. If your project stores roles
    // differently, edit this single function.
    try {
        $stmt = db()->prepare(
            'SELECT u.id, u.email, u.name
             FROM users u
             JOIN employee_roles er ON er.user_id = u.id
             WHERE er.role_code = :r AND u.deleted_at IS NULL'
        );
        $stmt->execute([':r' => $role_code]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Fallback for installs that have not yet shipped employee_roles.
        $rows = [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id'    => (int)$r['id'],
            'email'      => (string)$r['email'],
            'name'       => (string)$r['name'],
            'role'       => $role_code,
            'account_id' => null,
            'audience'   => 'employee',
        ];
    }
    return $out;
}

function rcv_dedupe_by_email(array $list): array
{
    $seen = [];
    $out = [];
    foreach ($list as $r) {
        $k = strtolower((string)$r['email']);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $r;
    }
    return $out;
}
