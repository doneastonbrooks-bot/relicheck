<?php
// JSON I/O, validation, and error helpers shared by all API endpoints.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

// Baseline security headers. Sent on every endpoint that requires _helpers.php
// (effectively all of /api/*). Safe to emit before any output. The
// headers_sent() guard avoids a PHP warning if a buggy include flushed
// something early.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; connect-src 'self' https:; frame-ancestors 'none';");
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $code, string $message, int $status = 400, array $extra = []): void
{
    json_out(array_merge(['error' => $code, 'message' => $message], $extra), $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fail('bad_json', 'Request body is not valid JSON.', 400);
    }
    if (!is_array($decoded)) fail('bad_json', 'Request body must be a JSON object.', 400);
    return $decoded;
}

function require_method(string ...$allowed): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        fail('method_not_allowed', 'This endpoint only accepts: ' . implode(', ', $allowed), 405);
    }
}

function check_origin(): void
{
    // SameSite=Lax already blocks most CSRF; we additionally require the request
    // to originate from the same host on state-changing methods.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host    = $_SERVER['HTTP_HOST']    ?? '';
    $okOrigin = $origin !== '' && parse_url($origin, PHP_URL_HOST) === $host;
    $okRef    = $referer !== '' && parse_url($referer, PHP_URL_HOST) === $host;
    if (!$okOrigin && !$okRef) {
        fail('forbidden_origin', 'Cross-origin requests are blocked.', 403);
    }
}

function valid_email(string $s): bool
{
    return filter_var($s, FILTER_VALIDATE_EMAIL) !== false && strlen($s) <= 255;
}

function valid_password(string $s): bool
{
    // Match the front-end rule: at least 8 chars, includes a digit.
    return strlen($s) >= 8 && preg_match('/\d/', $s) === 1;
}

function clean_string($s, int $max): string
{
    if (!is_string($s)) return '';
    $s = trim($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function ip_hash(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') return null;
    // Hash with a salt so the IP itself is never stored in plaintext.
    $cfg = relicheck_config();
    $salt = $cfg['ip_salt'] ?? 'relicheck-default-salt';
    return hash('sha256', $salt . '|' . $ip);
}

function generate_slug(int $length = 10): string
{
    // URL-safe alphabet, no ambiguous chars (no 0/O/1/l/I)
    $alphabet = 'abcdefghjkmnpqrstuvwxyzACDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function unique_survey_slug(PDO $pdo, int $maxTries = 6): string
{
    $stmt = $pdo->prepare('SELECT 1 FROM surveys WHERE slug = :s LIMIT 1');
    for ($i = 0; $i < $maxTries; $i++) {
        $candidate = generate_slug(10);
        $stmt->execute([':s' => $candidate]);
        if (!$stmt->fetch()) return $candidate;
    }
    // Fallback: longer slug to make collisions astronomically unlikely
    return generate_slug(16);
}

function default_survey_settings(): array
{
    return [
        'likertPoints' => 5,
        'likertLow'    => 'Strongly disagree',
        'likertHigh'   => 'Strongly agree',
    ];
}
