<?php
// /api/account/tokens.php - list, create, revoke API tokens.
//
// GET                  -> list current user's tokens (no raw values)
// POST  action=create  -> mint a new token, returns the raw value ONCE
// POST  action=revoke  -> revoke a token by id

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_api_auth.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function load_tokens(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, prefix, last_used_at, created_at, revoked_at
           FROM api_tokens WHERE user_id = :u
          ORDER BY created_at DESC'
    );
    $stmt->execute([':u' => $userId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'name'         => $r['name'],
            'prefix'       => $r['prefix'],
            'last_used_at' => $r['last_used_at'],
            'created_at'   => $r['created_at'],
            'revoked_at'   => $r['revoked_at'],
        ];
    }
    return $rows;
}

if ($method === 'GET') {
    $featureAllowed = tier_feature((int)$user['id'], 'api_access');
    json_out([
        'feature_allowed' => $featureAllowed,
        'tokens'          => load_tokens($pdo, (int)$user['id']),
    ]);
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if (!tier_feature((int)$user['id'], 'api_access')) {
    fail('plan_required', 'API access is included on the Business plan. Upgrade to enable this feature.', 402, [
        'feature' => 'api_access',
    ]);
}

if ($action === 'create') {
    $name = clean_string($body['name'] ?? '', 80);
    if ($name === '') $name = 'Untitled token';

    // Reasonable per-user cap to prevent runaway provisioning
    $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM api_tokens WHERE user_id = :u AND revoked_at IS NULL');
    $cnt->execute([':u' => $user['id']]);
    if ((int)$cnt->fetch()['c'] >= 25) {
        fail('too_many_tokens', 'You already have 25 active tokens. Revoke one before creating another.');
    }

    // Generate a token. Retry on the unique-index collision (extremely unlikely).
    $tries = 0;
    do {
        $raw = api_token_generate();
        $hash = api_token_hash($raw);
        $prefix = substr($raw, 0, 11);
        try {
            $pdo->prepare(
                'INSERT INTO api_tokens (user_id, name, prefix, token_hash)
                 VALUES (:u, :n, :p, :h)'
            )->execute([':u' => $user['id'], ':n' => $name, ':p' => $prefix, ':h' => $hash]);
            break;
        } catch (PDOException $e) {
            if (++$tries >= 3) throw $e;
        }
    } while (true);

    $id = (int)$pdo->lastInsertId();
    json_out([
        'ok'    => true,
        'token' => $raw,        // returned exactly once
        'id'    => $id,
        'name'  => $name,
        'prefix'=> $prefix,
        'note'  => 'Save this token now. We do not store the raw value and cannot show it again.',
    ], 201);
}

if ($action === 'revoke') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) fail('bad_id', 'Missing token id.');
    $upd = $pdo->prepare(
        'UPDATE api_tokens SET revoked_at = NOW()
          WHERE id = :id AND user_id = :u AND revoked_at IS NULL'
    );
    $upd->execute([':id' => $id, ':u' => $user['id']]);
    if ($upd->rowCount() === 0) fail('not_found', 'No active token with that id was found.', 404);
    json_out(['ok' => true, 'tokens' => load_tokens($pdo, (int)$user['id'])]);
}

fail('bad_action', 'Unknown action. Use "create" or "revoke".');
