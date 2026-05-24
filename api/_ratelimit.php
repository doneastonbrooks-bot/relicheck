<?php
// Tiny rate limiter for auth endpoints. Counts attempts inside a sliding
// window keyed on whatever identifier the caller passes (IP, email, etc.).
// Calls fail() with HTTP 429 when the window's cap is exceeded.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

/**
 * @param string $key             A short identifier ("login:email:foo@bar.com").
 * @param int    $maxAttempts     Cap before we 429.
 * @param int    $windowSeconds   How long a window lasts.
 * @param bool   $countOnly       If true, don't fail; just record the attempt.
 */
function check_rate_limit(string $key, int $maxAttempts, int $windowSeconds, bool $countOnly = false): void
{
    $key = mb_substr($key, 0, 160);
    $pdo = db();

    // Opportunistic cleanup of expired rows (cheap; runs <1% of the time).
    if (mt_rand(0, 99) === 0) {
        $pdo->prepare('DELETE FROM rate_limits WHERE last_at < (NOW() - INTERVAL 1 DAY)')->execute();
    }

    // Pull current state, reset if the window has elapsed since first_at.
    $stmt = $pdo->prepare('SELECT count, first_at FROM rate_limits WHERE bucket_key = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    $now = time();
    if ($row) {
        $firstAt = strtotime((string)$row['first_at'] . ' UTC');
        if ($firstAt === false || ($now - $firstAt) > $windowSeconds) {
            // Reset the window.
            $pdo->prepare(
                'UPDATE rate_limits SET count = 1, first_at = NOW(), last_at = NOW() WHERE bucket_key = :k'
            )->execute([':k' => $key]);
            $current = 1;
        } else {
            $current = (int)$row['count'] + 1;
            $pdo->prepare(
                'UPDATE rate_limits SET count = count + 1, last_at = NOW() WHERE bucket_key = :k'
            )->execute([':k' => $key]);
        }
    } else {
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, count) VALUES (:k, 1)'
        )->execute([':k' => $key]);
        $current = 1;
    }

    if ($countOnly) return;

    if ($current > $maxAttempts) {
        $retryAfter = max(60, $windowSeconds);
        header('Retry-After: ' . $retryAfter);
        fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again.', 429, [
            'retry_after_seconds' => $retryAfter,
        ]);
    }
}

/**
 * Convenience wrapper: hash the IP via the existing salt before using it
 * in a rate-limit key, so we never store raw IPs in the rate_limits table.
 */
function ip_bucket_key(string $action): string
{
    $h = ip_hash() ?? 'unknown';
    return $action . ':ip:' . substr($h, 0, 24);
}
