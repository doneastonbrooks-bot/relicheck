<?php
// TOTP (RFC 6238) helper. Self-contained - no external dependency.
// Compatible with Google Authenticator, Authy, 1Password, Microsoft
// Authenticator, etc.
//
// Defaults: HMAC-SHA1, 6 digits, 30-second period, 20-byte secret.

declare(strict_types=1);

/**
 * Generate a fresh secret and return it as a 32-character base32 string.
 */
function totp_generate_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

/**
 * Build the otpauth:// URL that authenticator apps consume from a QR code.
 *   otpauth://totp/<issuer>:<account>?secret=<base32>&issuer=<issuer>
 */
function totp_otpauth_url(string $secret, string $accountEmail, string $issuer = 'ReliCheck Admin'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountEmail);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => '6',
        'period'    => '30',
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}

/**
 * Returns true if $code is a valid TOTP code for $secret right now.
 * Allows +/-$window steps (default 1 = +/-30s) for clock drift.
 */
function totp_verify_code(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) return false;
    $secretBytes = totp_base32_decode($secret);
    if ($secretBytes === '') return false;
    $time = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($secretBytes, $time + $i), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Compute the 6-digit code for a given counter value.
 * Internal helper. Returns the code as a zero-padded string.
 */
function totp_code_at(string $secretBytes, int $counter): string
{
    $bin = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $bin, $secretBytes, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $value = (
        ((ord($hash[$offset])     & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        ( ord($hash[$offset + 3]) & 0xff)
    );
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/* ---------- Base32 ---------- */

function totp_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bin = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $bin .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $m = strlen($bin); $i < $m; $i += 5) {
        $chunk = substr($bin, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0');
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $s): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[\s=]/', '', $s) ?? '');
    if ($s === '') return '';
    $bin = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $pos = strpos($alphabet, $s[$i]);
        if ($pos === false) return '';
        $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $m = strlen($bin); $i + 8 <= $m; $i += 8) {
        $out .= chr(bindec(substr($bin, $i, 8)));
    }
    return $out;
}
