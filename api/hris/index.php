<?php
// /api/hris/index.php - manage HRIS connections.
//
// GET                     -> list connection states for all providers
// POST  action=connect    -> save credentials and run validate
// POST  action=sync       -> trigger a directory sync now
// POST  action=disconnect -> wipe credentials for this provider

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_hris.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo = db();

$ownerId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function load_state(PDO $pdo, int $ownerId): array
{
    $stmt = $pdo->prepare(
        'SELECT provider, status, metadata, last_sync_at, last_error
           FROM hris_connections WHERE owner_id = :o'
    );
    $stmt->execute([':o' => $ownerId]);
    $byProvider = [];
    foreach ($stmt->fetchAll() as $r) {
        $byProvider[(string)$r['provider']] = [
            'provider'     => $r['provider'],
            'status'       => $r['status'],
            'metadata'     => $r['metadata'] ? json_decode((string)$r['metadata'], true) : null,
            'last_sync_at' => $r['last_sync_at'],
            'last_error'   => $r['last_error'],
        ];
    }
    $providers = [];
    foreach (HRIS_PROVIDERS as $name) {
        $providers[] = $byProvider[$name] ?? [
            'provider' => $name, 'status' => 'disconnected',
            'metadata' => null, 'last_sync_at' => null, 'last_error' => null,
        ];
    }
    // Directory size
    $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM hris_employees WHERE owner_id = :o');
    $cnt->execute([':o' => $ownerId]);
    return [
        'providers' => $providers,
        'directory_count' => (int)$cnt->fetch()['c'],
    ];
}

if ($method === 'GET') {
    $featureAllowed = tier_feature($ownerId, 'hris');
    json_out(['feature_allowed' => $featureAllowed] + load_state($pdo, $ownerId));
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if (!tier_feature($ownerId, 'hris')) {
    fail('plan_required', 'HRIS integration is included on the Business plan. Upgrade to enable this feature.', 402, ['feature' => 'hris']);
}

$providerName = (string)($body['provider'] ?? '');
if (!in_array($providerName, HRIS_PROVIDERS, true)) {
    fail('bad_provider', 'provider must be one of: ' . implode(', ', HRIS_PROVIDERS));
}
$provider = hris_provider($providerName);

if ($action === 'connect') {
    $cred = is_array($body['credentials'] ?? null) ? $body['credentials'] : [];
    try {
        ($provider['validate_credentials'])($cred);
    } catch (Throwable $e) {
        fail('bad_credentials', $e->getMessage(), 400);
    }
    $blob = hris_encrypt($cred);
    $meta = is_array($body['metadata'] ?? null) ? $body['metadata'] : null;
    $pdo->prepare(
        'INSERT INTO hris_connections (owner_id, provider, status, credentials, metadata)
         VALUES (:o, :p, "connected", :c, :m)
         ON DUPLICATE KEY UPDATE status="connected", credentials=VALUES(credentials), metadata=VALUES(metadata), last_error=NULL'
    )->execute([':o' => $ownerId, ':p' => $providerName, ':c' => $blob, ':m' => $meta ? json_encode($meta) : null]);
    json_out(['ok' => true] + load_state($pdo, $ownerId));
}

if ($action === 'sync') {
    try {
        $result = hris_sync($ownerId, $providerName);
        json_out(['ok' => true, 'sync' => $result] + load_state($pdo, $ownerId));
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE hris_connections SET status="error", last_error=:msg WHERE owner_id=:o AND provider=:p')
            ->execute([':msg' => mb_substr($e->getMessage(), 0, 480), ':o' => $ownerId, ':p' => $providerName]);
        fail('sync_failed', $e->getMessage(), 500);
    }
}

if ($action === 'disconnect') {
    $pdo->prepare(
        'UPDATE hris_connections SET status="disconnected", credentials=NULL, last_error=NULL
          WHERE owner_id = :o AND provider = :p'
    )->execute([':o' => $ownerId, ':p' => $providerName]);
    // Optionally clear the directory rows on disconnect.
    if (!empty($body['wipe_directory'])) {
        $pdo->prepare('DELETE FROM hris_employees WHERE owner_id = :o AND provider = :p')
            ->execute([':o' => $ownerId, ':p' => $providerName]);
    }
    json_out(['ok' => true] + load_state($pdo, $ownerId));
}

fail('bad_action', 'Use connect, sync, or disconnect.');
