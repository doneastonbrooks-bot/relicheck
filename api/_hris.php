<?php
// HRIS provider abstraction. Three providers stub out the actual API
// calls but document the request/response shape so a real
// implementation slots in cleanly later.
//
// Production note: each provider requires partner enablement before
// real API calls work. Workday in particular needs a tenant admin to
// configure an Integration System User and grant scopes - that's
// weeks of customer-side work. The pragmatic alternative for shipping
// in the next 30 days is an HRIS aggregator (Merge / Finch / Kombo).
// One integration on the ReliCheck side, 50+ HR systems out the back.
// See SETUP.md for the aggregator-vs-direct decision.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

const HRIS_PROVIDERS = ['bamboohr', 'workday', 'rippling'];

/** Encrypt a JSON-serializable credentials object for storage. Uses the
 *  hris_encryption_key from _config.php (32-byte hex). NEVER writes the
 *  key into the database; only the ciphertext. */
function hris_encrypt(array $cred): string
{
    $cfg = relicheck_config();
    $hex = (string)($cfg['hris_encryption_key'] ?? '');
    if (strlen($hex) !== 64) {
        throw new RuntimeException('Configure hris_encryption_key (32 random bytes hex-encoded) in _config.php before storing HRIS credentials.');
    }
    $key = hex2bin($hex);
    $iv  = random_bytes(12);
    $plain = json_encode($cred, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('HRIS credential encryption failed.');
    return base64_encode($iv . $tag . $ct);
}

function hris_decrypt(string $blob): array
{
    $cfg = relicheck_config();
    $hex = (string)($cfg['hris_encryption_key'] ?? '');
    if (strlen($hex) !== 64) throw new RuntimeException('hris_encryption_key not configured.');
    $key = hex2bin($hex);
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Malformed HRIS credentials blob.');
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('HRIS credential decryption failed.');
    $arr = json_decode($plain, true);
    return is_array($arr) ? $arr : [];
}

/** Provider abstraction. Each provider must define:
 *    connect_label()       -> human label
 *    auth_kind()           -> 'oauth' | 'api_key'
 *    validate_credentials($cred) -> true on success, throws on bad creds
 *    fetch_employees($cred) -> normalized employee rows
 *
 * Normalized employee row shape:
 *   [
 *     'remote_id'         => string,
 *     'email'             => string|null,
 *     'full_name'         => string|null,
 *     'job_title'         => string|null,
 *     'department'        => string|null,
 *     'manager_remote_id' => string|null,
 *     'location'          => string|null,
 *     'start_date'        => 'YYYY-MM-DD'|null,
 *     'status'            => 'active'|'terminated'|'on_leave'|null,
 *     'raw'               => array (whole provider record),
 *   ]
 */
function hris_provider(string $name): array
{
    if (!in_array($name, HRIS_PROVIDERS, true)) {
        fail('bad_provider', 'Unknown HRIS provider.', 400);
    }
    require_once __DIR__ . '/_hris_' . $name . '.php';
    $factory = 'hris_' . $name;
    return $factory();
}

/** Run a sync against the connected provider and upsert the results.
 *  Returns ['inserted'=>n, 'updated'=>n, 'removed'=>n]. */
function hris_sync(int $ownerId, string $providerName): array
{
    $pdo = db();
    $row = $pdo->prepare(
        'SELECT credentials, status FROM hris_connections WHERE owner_id = :o AND provider = :p LIMIT 1'
    );
    $row->execute([':o' => $ownerId, ':p' => $providerName]);
    $conn = $row->fetch();
    if (!$conn || $conn['status'] !== 'connected') {
        fail('not_connected', 'HRIS provider is not connected.', 409);
    }
    $cred = hris_decrypt((string)$conn['credentials']);
    $provider = hris_provider($providerName);
    $rows = ($provider['fetch_employees'])($cred);

    $existing = [];
    $exStmt = $pdo->prepare('SELECT remote_id FROM hris_employees WHERE owner_id = :o AND provider = :p');
    $exStmt->execute([':o' => $ownerId, ':p' => $providerName]);
    foreach ($exStmt->fetchAll() as $r) $existing[(string)$r['remote_id']] = true;

    $upsert = $pdo->prepare(
        'INSERT INTO hris_employees (owner_id, provider, remote_id, email, full_name, job_title, department, manager_remote_id, location, start_date, status, raw)
         VALUES (:o, :p, :rid, :em, :fn, :jt, :dp, :mg, :lc, :sd, :st, :raw)
         ON DUPLICATE KEY UPDATE
           email=VALUES(email), full_name=VALUES(full_name), job_title=VALUES(job_title),
           department=VALUES(department), manager_remote_id=VALUES(manager_remote_id),
           location=VALUES(location), start_date=VALUES(start_date), status=VALUES(status),
           raw=VALUES(raw)'
    );

    $inserted = 0; $updated = 0; $seen = [];
    foreach ($rows as $emp) {
        $rid = (string)($emp['remote_id'] ?? '');
        if ($rid === '') continue;
        $seen[$rid] = true;
        $upsert->execute([
            ':o' => $ownerId, ':p' => $providerName,
            ':rid' => $rid,
            ':em' => $emp['email']             ?? null,
            ':fn' => $emp['full_name']         ?? null,
            ':jt' => $emp['job_title']         ?? null,
            ':dp' => $emp['department']        ?? null,
            ':mg' => $emp['manager_remote_id'] ?? null,
            ':lc' => $emp['location']          ?? null,
            ':sd' => $emp['start_date']        ?? null,
            ':st' => $emp['status']            ?? null,
            ':raw' => json_encode($emp['raw'] ?? $emp, JSON_UNESCAPED_UNICODE),
        ]);
        if (isset($existing[$rid])) $updated++; else $inserted++;
    }

    // Remove rows that the provider no longer reports (handles terminations).
    $removed = 0;
    if (!empty($existing)) {
        $stale = array_diff_key($existing, $seen);
        if ($stale) {
            $del = $pdo->prepare('DELETE FROM hris_employees WHERE owner_id = :o AND provider = :p AND remote_id = :r');
            foreach (array_keys($stale) as $rid) {
                $del->execute([':o' => $ownerId, ':p' => $providerName, ':r' => $rid]);
                $removed++;
            }
        }
    }

    $pdo->prepare('UPDATE hris_connections SET last_sync_at = NOW(), last_error = NULL WHERE owner_id = :o AND provider = :p')
        ->execute([':o' => $ownerId, ':p' => $providerName]);

    return ['inserted' => $inserted, 'updated' => $updated, 'removed' => $removed];
}
