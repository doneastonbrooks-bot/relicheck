<?php
// Config sanity checks. Run after _config.php is loaded so we catch
// scrambled values (like the May 2026 incident where db_pass had an entire
// nested PHP file stuffed into its string value) before they reach PDO and
// cause a silent 500.
//
// Returns an array of human-readable error strings. Empty array == OK.

declare(strict_types=1);

function relicheck_validate_config(array $cfg): array
{
    $errors = [];

    // Required string keys, with their expected min length where useful.
    $required_strings = [
        'db_host'           => 1,
        'db_name'           => 1,
        'db_user'           => 1,
        'db_pass'           => 1,
        'session_name'      => 1,
        'session_samesite'  => 3,
        'site_url'          => 8,
        'mail_from'         => 5,
    ];
    foreach ($required_strings as $key => $min) {
        if (!array_key_exists($key, $cfg)) {
            $errors[] = "Missing required key: '$key'";
            continue;
        }
        if (!is_string($cfg[$key])) {
            $errors[] = "Key '$key' must be a string, got " . gettype($cfg[$key]);
            continue;
        }
        if (strlen($cfg[$key]) < $min) {
            $errors[] = "Key '$key' is too short (min $min chars)";
        }
    }

    // Required int keys
    $required_ints = ['db_port', 'session_lifetime_days', 'smtp_port'];
    foreach ($required_ints as $key) {
        if (!array_key_exists($key, $cfg)) {
            $errors[] = "Missing required key: '$key'";
            continue;
        }
        if (!is_int($cfg[$key])) {
            $errors[] = "Key '$key' must be an integer, got " . gettype($cfg[$key]);
        }
    }

    // Required bool
    if (!array_key_exists('session_secure', $cfg)) {
        $errors[] = "Missing required key: 'session_secure'";
    } elseif (!is_bool($cfg['session_secure'])) {
        $errors[] = "Key 'session_secure' must be a boolean";
    }

    // Required array
    if (!array_key_exists('admin_emails', $cfg)) {
        $errors[] = "Missing required key: 'admin_emails'";
    } elseif (!is_array($cfg['admin_emails'])) {
        $errors[] = "Key 'admin_emails' must be an array";
    }

    // Anti-corruption: no string value should contain "<?php" — that's the
    // signature of the May 2026 incident where a nested config block was
    // stuffed into the db_pass field as a string. PHP parses it fine but
    // the value is meaningless.
    foreach ($cfg as $key => $value) {
        if (is_string($value)) {
            if (strpos($value, '<?php') !== false || strpos($value, '<?=') !== false) {
                $errors[] = "Key '$key' contains PHP code in its value (likely scrambled config — check this key carefully)";
            }
            if (strlen($value) > 4096) {
                $errors[] = "Key '$key' value is suspiciously long (" . strlen($value) . " chars). Real credentials are rarely this long.";
            }
        }
    }

    // db_pass specifically: tighter cap, since real DB passwords are short
    if (isset($cfg['db_pass']) && is_string($cfg['db_pass']) && strlen($cfg['db_pass']) > 128) {
        $errors[] = "Key 'db_pass' is longer than 128 chars — that's almost certainly wrong, real DB passwords are short.";
    }

    // Format checks
    if (isset($cfg['site_url']) && is_string($cfg['site_url'])) {
        if (!filter_var($cfg['site_url'], FILTER_VALIDATE_URL)) {
            $errors[] = "Key 'site_url' is not a valid URL: '" . $cfg['site_url'] . "'";
        } elseif (substr($cfg['site_url'], -1) === '/') {
            $errors[] = "Key 'site_url' should not have a trailing slash";
        }
    }

    if (isset($cfg['admin_emails']) && is_array($cfg['admin_emails'])) {
        foreach ($cfg['admin_emails'] as $i => $email) {
            if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "admin_emails[$i] is not a valid email";
            }
        }
    }

    if (isset($cfg['session_samesite']) && is_string($cfg['session_samesite'])) {
        if (!in_array($cfg['session_samesite'], ['Lax', 'Strict', 'None'], true)) {
            $errors[] = "Key 'session_samesite' must be one of: Lax, Strict, None";
        }
    }

    // Common placeholder values that mean "not set yet" — fail loudly so
    // these don't reach production.
    $placeholders = [
        'PASTE_PASSWORD_HERE',
        'YOUR_DATABASE_NAME',
        'YOUR_MAILBOX_PASSWORD_HERE',
        'change-me-to-something-random',
    ];
    foreach ($cfg as $key => $value) {
        if (is_string($value) && in_array($value, $placeholders, true)) {
            // ip_salt placeholder is a soft warning, not blocking
            if ($key === 'ip_salt') continue;
            $errors[] = "Key '$key' still holds a placeholder value ('$value'). Fill it in before deploying.";
        }
    }

    return $errors;
}
