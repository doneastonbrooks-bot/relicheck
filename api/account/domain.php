<?php
// /api/account/domain.php - per-account custom domain management.
//
// GET                  -> returns the current state, the platform's CNAME target,
//                         and (when a domain is set) the TXT verification token.
// POST  action=set     -> set or change the custom domain. Requires the
//                         'custom_domain' tier feature. Status becomes 'pending'.
// POST  action=verify  -> re-resolve the TXT record for the configured domain
//                         and flip status to 'verified' if the token matches.
// POST  action=remove  -> clear the domain and reset status to 'disabled'.
//
// All POST actions require the user to be on a tier whose features include
// 'custom_domain'. We re-check this on every call rather than trusting the
// frontend, since tier changes happen out of band.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('GET', 'POST');
$user = require_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();

// --- Platform configuration ---------------------------------------------
// The hostname customers CNAME their domain to. Pulled from _config.php
// when present so a single deploy can serve a different brand if desired.
$cfg = function_exists('relicheck_config') ? relicheck_config() : [];
$cnameTarget = $cfg['custom_domain_cname_target'] ?? 'app.relicheck.com';
$txtRecordPrefix = '_relicheck-verify';

// --- Helpers -------------------------------------------------------------

/** Validate a public hostname. RFC 1035-ish: labels of A-Z 0-9 -, max 253 chars. */
function validate_domain(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || strlen($host) > 253) return false;
    if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) return false;
    // Reject obvious non-options.
    if (strpos($host, '..') !== false) return false;
    if (substr($host, -1) === '.') return false;
    // Block configuring the platform's own host (would cause a loop).
    $blocked = ['relicheck.com', 'www.relicheck.com', 'app.relicheck.com'];
    if (in_array($host, $blocked, true)) return false;
    return true;
}

/** Build the DNS instructions block returned to the UI. */
function dns_instructions(string $domain, string $cname, string $txtPrefix, ?string $token): array
{
    $instr = [
        'cname' => [
            'host'   => $domain,
            'type'   => 'CNAME',
            'value'  => $cname,
            'note'   => 'Routes ' . $domain . ' to the ReliCheck servers. Required for the URL to resolve.',
        ],
    ];
    if ($token !== null) {
        $instr['txt'] = [
            'host'  => $txtPrefix . '.' . $domain,
            'type'  => 'TXT',
            'value' => 'relicheck-domain-verify=' . $token,
            'note'  => 'Proves you own this domain. We check this record when you click Verify.',
        ];
    }
    return $instr;
}

/** Resolve TXT records for a host. Returns array of strings, or [] on failure. */
function resolve_txt(string $host): array
{
    if (!function_exists('dns_get_record')) return [];
    $records = @dns_get_record($host, DNS_TXT);
    if (!is_array($records)) return [];
    $out = [];
    foreach ($records as $r) {
        if (!empty($r['txt'])) $out[] = (string)$r['txt'];
        // Some hosts return entries split across multiple strings.
        if (!empty($r['entries']) && is_array($r['entries'])) {
            $out[] = implode('', $r['entries']);
        }
    }
    return $out;
}

// --- Status payload ------------------------------------------------------

function load_state(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT custom_domain, custom_domain_status, custom_domain_verification_token, custom_domain_verified_at
           FROM users WHERE id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    return [
        'domain'      => $row['custom_domain']                   ?? null,
        'status'      => $row['custom_domain_status']            ?? 'disabled',
        'token'       => $row['custom_domain_verification_token']?? null,
        'verified_at' => $row['custom_domain_verified_at']       ?? null,
    ];
}

function payload(array $state, string $cname, string $txtPrefix, bool $featureAllowed): array
{
    return [
        'feature_allowed'      => $featureAllowed,
        'cname_target'         => $cname,
        'txt_record_prefix'    => $txtPrefix,
        'domain'               => $state['domain'],
        'status'               => $state['status'],
        'verified_at'          => $state['verified_at'],
        'instructions'         => $state['domain']
            ? dns_instructions($state['domain'], $cname, $txtPrefix, $state['token'])
            : null,
    ];
}

// --- Dispatch ------------------------------------------------------------

$state = load_state($pdo, (int)$user['id']);
$featureAllowed = tier_feature((int)$user['id'], 'custom_domain');

if ($method === 'GET') {
    json_out(payload($state, $cnameTarget, $txtRecordPrefix, $featureAllowed));
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if (!$featureAllowed) {
    fail('plan_required', 'Custom domain support is included on the Business plan. Upgrade to enable this feature.', 402, [
        'feature' => 'custom_domain',
    ]);
}

if ($action === 'set') {
    $domain = strtolower(clean_string($body['domain'] ?? '', 253));
    if (!validate_domain($domain)) {
        fail('bad_domain', 'Enter a valid hostname (e.g. surveys.acme.edu).');
    }
    // Reject if another account already owns this domain.
    $check = $pdo->prepare('SELECT id FROM users WHERE custom_domain = :d AND id <> :id LIMIT 1');
    $check->execute([':d' => $domain, ':id' => $user['id']]);
    if ($check->fetch()) {
        fail('domain_taken', 'That domain is already in use on another ReliCheck account. Contact support if you believe this is an error.', 409);
    }

    // Generate a verification token. Stays stable across "set" calls if the
    // domain isn't changing, so customers don't have to re-publish a TXT
    // record after editing CNAME instructions.
    $token = $state['token'];
    if (!$token || $state['domain'] !== $domain) {
        $token = bin2hex(random_bytes(16));
    }

    $pdo->prepare(
        'UPDATE users
            SET custom_domain = :d,
                custom_domain_status = "pending",
                custom_domain_verification_token = :t,
                custom_domain_verified_at = NULL
          WHERE id = :id'
    )->execute([':d' => $domain, ':t' => $token, ':id' => $user['id']]);

    $state = load_state($pdo, (int)$user['id']);
    json_out(payload($state, $cnameTarget, $txtRecordPrefix, true) + ['ok' => true]);
}

if ($action === 'verify') {
    if (!$state['domain'] || !$state['token']) {
        fail('no_domain_set', 'Set a custom domain first.');
    }
    $expected = 'relicheck-domain-verify=' . $state['token'];
    $records = resolve_txt($txtRecordPrefix . '.' . $state['domain']);
    $found = false;
    foreach ($records as $r) {
        if (strpos($r, $expected) !== false) { $found = true; break; }
    }
    if (!$found) {
        fail('not_verified', 'Could not find the TXT verification record at ' . $txtRecordPrefix . '.' . $state['domain'] . '. DNS changes can take a few minutes to propagate. Try again in 5-10 minutes.', 409, [
            'expected' => $expected,
            'records_found' => $records,
        ]);
    }
    $pdo->prepare(
        'UPDATE users
            SET custom_domain_status = "verified",
                custom_domain_verified_at = NOW()
          WHERE id = :id'
    )->execute([':id' => $user['id']]);
    $state = load_state($pdo, (int)$user['id']);
    json_out(payload($state, $cnameTarget, $txtRecordPrefix, true) + ['ok' => true]);
}

if ($action === 'remove') {
    $pdo->prepare(
        'UPDATE users
            SET custom_domain = NULL,
                custom_domain_status = "disabled",
                custom_domain_verification_token = NULL,
                custom_domain_verified_at = NULL
          WHERE id = :id'
    )->execute([':id' => $user['id']]);
    $state = load_state($pdo, (int)$user['id']);
    json_out(payload($state, $cnameTarget, $txtRecordPrefix, true) + ['ok' => true]);
}

fail('bad_action', 'Unknown action. Use "set", "verify", or "remove".');
