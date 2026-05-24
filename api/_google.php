<?php
// Google API helper: token management + thin client for Sheets and Drive.
//
// Responsibilities:
//   * Load the current user's Google OAuth tokens from google_oauth_tokens.
//   * Refresh the access token transparently if it has expired.
//   * Provide google_api_get / google_api_post / google_api_upload for the
//     Sheets and Drive endpoints to call.
//
// All HTTP calls go through stream contexts so this file has no Composer
// dependency and works on a vanilla Ionos PHP install.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_session.php';

const GOOGLE_OAUTH_AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_OAUTH_TOKEN_URL   = 'https://oauth2.googleapis.com/token';
const GOOGLE_OAUTH_REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
const GOOGLE_OAUTH_TOKENINFO   = 'https://oauth2.googleapis.com/tokeninfo';

const GOOGLE_SCOPES_FOR_API = [
    'openid',
    'email',
    'profile',
    'https://www.googleapis.com/auth/drive.file',
    'https://www.googleapis.com/auth/spreadsheets',
];

/**
 * Returns ['client_id' => ..., 'client_secret' => ..., 'redirect_uri' => ...].
 * Fails the request if Google integration is not configured.
 */
function google_oauth_app(): array
{
    $cfg = relicheck_config();
    $cid  = (string)($cfg['google_client_id']     ?? '');
    $csec = (string)($cfg['google_client_secret'] ?? '');
    if ($cid === '' || $csec === '') {
        fail('google_disabled', 'Google integration is not configured on this server.', 503);
    }
    $site = rtrim((string)($cfg['site_url'] ?? ''), '/');
    if ($site === '') {
        fail('config_missing', 'site_url is not set in _config.php.', 500);
    }
    return [
        'client_id'     => $cid,
        'client_secret' => $csec,
        'redirect_uri'  => $site . '/api/google/callback.php',
    ];
}

/**
 * Posts an x-www-form-urlencoded body to a URL and returns the decoded JSON
 * response. On HTTP errors, fails the request with the body Google returned.
 */
function google_http_post_form(string $url, array $fields, ?int &$status = null): array
{
    $body = http_build_query($fields, '', '&');
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content'       => $body,
            'timeout'       => 12,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = google_status_from_headers($http_response_header ?? []);
    if ($raw === false) {
        fail('google_unreachable', 'Could not reach Google.', 502);
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['_raw' => $raw];
}

function google_status_from_headers(array $headers): int
{
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

/**
 * Exchanges an authorization code for tokens, persists them, and returns
 * the freshly stored row.
 */
function google_exchange_code_and_store(int $userId, string $code): array
{
    $app = google_oauth_app();
    $resp = google_http_post_form(GOOGLE_OAUTH_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => $app['client_id'],
        'client_secret' => $app['client_secret'],
        'redirect_uri'  => $app['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ], $status);

    if ($status !== 200 || !isset($resp['access_token'])) {
        $msg = (string)($resp['error_description'] ?? $resp['error'] ?? 'Unknown error from Google.');
        fail('google_token_exchange_failed', 'Google rejected the authorization code: ' . $msg, 400);
    }

    $accessToken  = (string)$resp['access_token'];
    $refreshToken = isset($resp['refresh_token']) ? (string)$resp['refresh_token'] : null;
    $scopes       = (string)($resp['scope'] ?? '');
    $tokenType    = (string)($resp['token_type'] ?? 'Bearer');
    $expiresIn    = (int)($resp['expires_in'] ?? 3600);
    $expiresAt    = (new DateTime('now'))->modify('+' . max(60, $expiresIn - 60) . ' seconds')->format('Y-m-d H:i:s');

    // Try to enrich with email + sub from the id_token (cheap parse, no signature
    // check; Google already validated the token by issuing it).
    $googleEmail = null;
    $googleSub   = null;
    if (isset($resp['id_token']) && is_string($resp['id_token'])) {
        $parts = explode('.', $resp['id_token']);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '{}', true);
            if (is_array($payload)) {
                if (isset($payload['email'])) $googleEmail = strtolower((string)$payload['email']);
                if (isset($payload['sub']))   $googleSub   = (string)$payload['sub'];
            }
        }
    }

    $pdo = db();

    // If there's no refresh_token in the response and there's an existing one,
    // keep the old one (Google only returns refresh_token on first consent).
    if ($refreshToken === null) {
        $stmt = $pdo->prepare('SELECT refresh_token FROM google_oauth_tokens WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $existing = $stmt->fetch();
        if ($existing && !empty($existing['refresh_token'])) {
            $refreshToken = (string)$existing['refresh_token'];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO google_oauth_tokens
            (user_id, access_token, refresh_token, scopes, token_type, expires_at, google_email, google_sub)
         VALUES
            (:uid, :at, :rt, :sc, :tt, :ex, :em, :sub)
         ON DUPLICATE KEY UPDATE
            access_token  = VALUES(access_token),
            refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
            scopes        = VALUES(scopes),
            token_type    = VALUES(token_type),
            expires_at    = VALUES(expires_at),
            google_email  = COALESCE(VALUES(google_email), google_email),
            google_sub    = COALESCE(VALUES(google_sub), google_sub)'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':at'  => $accessToken,
        ':rt'  => $refreshToken,
        ':sc'  => $scopes,
        ':tt'  => $tokenType,
        ':ex'  => $expiresAt,
        ':em'  => $googleEmail,
        ':sub' => $googleSub,
    ]);

    return google_load_tokens_for_user($userId);
}

function google_load_tokens_for_user(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM google_oauth_tokens WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Returns a usable access token for the current user, refreshing if needed.
 * Fails the request with a clear error if the user has not connected Google.
 */
function google_access_token_or_fail(int $userId): string
{
    $row = google_load_tokens_for_user($userId);
    if (!$row) {
        fail('google_not_connected', 'Connect your Google account to use this feature.', 412);
    }

    $expiresAt = strtotime((string)$row['expires_at']) ?: 0;
    if ($expiresAt > time() + 30) {
        return (string)$row['access_token'];
    }

    // Need to refresh.
    if (empty($row['refresh_token'])) {
        fail('google_refresh_required', 'Your Google session has expired. Reconnect Google in account settings.', 412);
    }

    $app = google_oauth_app();
    $resp = google_http_post_form(GOOGLE_OAUTH_TOKEN_URL, [
        'client_id'     => $app['client_id'],
        'client_secret' => $app['client_secret'],
        'refresh_token' => (string)$row['refresh_token'],
        'grant_type'    => 'refresh_token',
    ], $status);

    if ($status !== 200 || !isset($resp['access_token'])) {
        $msg = (string)($resp['error_description'] ?? $resp['error'] ?? 'Unknown error.');
        fail('google_refresh_failed', 'Could not refresh your Google session: ' . $msg, 502);
    }

    $newAccess = (string)$resp['access_token'];
    $newExpiresIn = (int)($resp['expires_in'] ?? 3600);
    $newExpiresAt = (new DateTime('now'))->modify('+' . max(60, $newExpiresIn - 60) . ' seconds')->format('Y-m-d H:i:s');
    $newScopes   = (string)($resp['scope'] ?? $row['scopes']);

    db()->prepare(
        'UPDATE google_oauth_tokens SET access_token = :at, scopes = :sc, expires_at = :ex WHERE user_id = :uid'
    )->execute([':at' => $newAccess, ':sc' => $newScopes, ':ex' => $newExpiresAt, ':uid' => $userId]);

    return $newAccess;
}

/**
 * Disconnect: revoke the refresh token at Google, then delete the row.
 */
function google_disconnect_user(int $userId): void
{
    $row = google_load_tokens_for_user($userId);
    if (!$row) return;

    $tokenToRevoke = !empty($row['refresh_token']) ? $row['refresh_token'] : ($row['access_token'] ?? '');
    if ($tokenToRevoke !== '') {
        // Best-effort revoke. Ignore errors; we still want to remove the local row.
        google_http_post_form(GOOGLE_OAUTH_REVOKE_URL, ['token' => $tokenToRevoke]);
    }

    db()->prepare('DELETE FROM google_oauth_tokens WHERE user_id = :uid')->execute([':uid' => $userId]);
}

/* ---------------- Generic Google API client ---------------- */

function google_api_request(string $method, string $url, ?int $userId, ?string $body = null, array $headers = []): array
{
    $userId = $userId ?? require_auth()['id'];
    $token = google_access_token_or_fail((int)$userId);

    $hdrLines = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdrLines[] = $k . ': ' . $v;
    }

    $opts = [
        'http' => [
            'method'        => strtoupper($method),
            'header'        => implode("\r\n", $hdrLines) . "\r\n",
            'timeout'       => 25,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    if ($body !== null) $opts['http']['content'] = $body;

    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    $status = google_status_from_headers($http_response_header ?? []);

    if ($raw === false) {
        fail('google_unreachable', 'Could not reach Google API.', 502);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) $decoded = ['_raw' => $raw];

    if ($status < 200 || $status >= 300) {
        $err = $decoded['error']['message'] ?? ($decoded['error_description'] ?? ('HTTP ' . $status));
        fail('google_api_error', 'Google API error: ' . $err, $status >= 400 && $status < 500 ? 400 : 502, ['google_status' => $status]);
    }
    return $decoded;
}

function google_api_get(string $url, ?int $userId = null): array
{
    return google_api_request('GET', $url, $userId, null, []);
}

function google_api_post_json(string $url, array $payload, ?int $userId = null): array
{
    return google_api_request('POST', $url, $userId, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
        'Content-Type' => 'application/json; charset=utf-8',
    ]);
}

function google_api_put_json(string $url, array $payload, ?int $userId = null): array
{
    return google_api_request('PUT', $url, $userId, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
        'Content-Type' => 'application/json; charset=utf-8',
    ]);
}

/**
 * Drive multipart upload (metadata + file body in one request).
 * $metadata is the file metadata (e.g., name, mimeType, parents).
 * $bodyBytes is the raw file content.
 * $bodyMime is the file's MIME type.
 */
function google_drive_multipart_upload(array $metadata, string $bodyBytes, string $bodyMime, ?int $userId = null): array
{
    $boundary = 'relicheck_' . bin2hex(random_bytes(8));
    $metaJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metaJson . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$bodyMime}\r\n\r\n";
    $body .= $bodyBytes . "\r\n";
    $body .= "--{$boundary}--";

    return google_api_request(
        'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,mimeType',
        $userId,
        $body,
        ['Content-Type' => 'multipart/related; boundary="' . $boundary . '"']
    );
}
