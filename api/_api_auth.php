<?php
// Bearer-token authentication for the public REST API at /api/v1/*.
// Endpoints call require_api_token() instead of require_auth(); the
// helper validates the Authorization header, looks up the token's
// owner, applies rate limiting per token, and returns the user row.
//
// Token shape: "rk_" + 32 chars of base32-ish characters. Stored as
// SHA-256 in api_tokens.token_hash. The first 11 chars (prefix) are
// kept in cleartext for display only.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_ratelimit.php';
require_once __DIR__ . '/_tiers.php';

const API_TOKEN_PREFIX = 'rk_';
const API_TOKEN_BODY_LEN = 32;
const API_TOKEN_RATE_LIMIT_PER_MIN = 60; // 60 requests / minute / token

/** Generate a fresh raw token. Returned to the user once at creation time. */
function api_token_generate(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // omits ambiguous chars
    $body = '';
    for ($i = 0; $i < API_TOKEN_BODY_LEN; $i++) {
        $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return API_TOKEN_PREFIX . $body;
}

/** Hash a raw token for storage. We use SHA-256 (not bcrypt) because we
 *  need O(1) lookup by hash on every API request. The token entropy
 *  (~150 bits) makes brute force on the database non-viable. */
function api_token_hash(string $raw): string
{
    return hash('sha256', $raw);
}

/** Read the bearer token from the Authorization header.
 *  Returns the raw token, or null if missing/malformed.
 *
 *  IONOS shared Apache (and any environment using CGI/FastCGI) strips
 *  the Authorization header by default. We probe four locations to
 *  recover it:
 *    1. $_SERVER['HTTP_AUTHORIZATION']           - normal case
 *    2. $_SERVER['REDIRECT_HTTP_AUTHORIZATION']  - mod_rewrite forward
 *    3. apache_request_headers()                 - mod_php only
 *    4. getallheaders()                          - FPM/most others
 */
function api_token_from_header(): ?string
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '') {
        $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
    if ($hdr === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string)$v; break; }
            }
        }
    }
    if ($hdr === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = (string)$v; break; }
            }
        }
    }
    if ($hdr === '') return null;
    if (stripos($hdr, 'Bearer ') !== 0) return null;
    $tok = trim(substr($hdr, 7));
    return ($tok === '') ? null : $tok;
}

/** Validate the request's bearer token and return the owning user row.
 *  Fails with appropriate HTTP code on missing/invalid/revoked tokens or
 *  when the user's tier doesn't include API access. */
function require_api_token(): array
{
    $raw = api_token_from_header();
    if ($raw === null) {
        fail('missing_token', 'Send a bearer token in the Authorization header (e.g. "Authorization: Bearer rk_...").', 401);
    }
    if (strpos($raw, API_TOKEN_PREFIX) !== 0) {
        fail('bad_token', 'Token has the wrong shape.', 401);
    }
    $hash = api_token_hash($raw);
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.user_id, t.revoked_at, u.email, u.name, u.id AS uid
           FROM api_tokens t JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = :h LIMIT 1'
    );
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('bad_token', 'That token is not recognized.', 401);
    }
    if (!empty($row['revoked_at'])) {
        fail('revoked_token', 'That token has been revoked.', 401);
    }
    // Tier feature gate
    $info = tier_for_user((int)$row['user_id']);
    if (empty($info['features']['api_access'])) {
        fail('plan_required', 'API access is included on the Business plan. Upgrade to enable this feature.', 402, [
            'feature' => 'api_access',
        ]);
    }
    // Per-token rate limit (60 requests/minute)
    check_rate_limit('api:' . $row['token_id'], API_TOKEN_RATE_LIMIT_PER_MIN, 60);
    // Stamp last-used (best-effort; ignore failures)
    @$pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id')
       ->execute([':id' => $row['token_id']]);

    return [
        'id'    => (int)$row['uid'],
        'email' => $row['email'],
        'name'  => $row['name'],
        'token_id' => (int)$row['token_id'],
    ];
}
