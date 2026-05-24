<?php
// Tiny SMTP client for ReliCheck.
// Authenticates with EHLO + STARTTLS + AUTH LOGIN, sends one message,
// then QUITs. No external dependencies. Throws RuntimeException on failure.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

/**
 * Send a single email. Optional $opts:
 *   - from:      override the default From address (must be authorized
 *                by the SMTP user, otherwise most providers reject).
 *   - from_name: override the default From display name.
 *   - reply_to:  optional Reply-To header, useful when the From is a
 *                no-reply alias but you still want responses routed
 *                to a real inbox.
 *   - list_unsubscribe:      optional value for the List-Unsubscribe
 *                            header. Commonly "<mailto:unsub@domain>,
 *                            <https://example.com/u?t=...>". Strongly
 *                            improves Gmail/iCloud inbox placement.
 *   - list_unsubscribe_post: if truthy, adds the
 *                            "List-Unsubscribe-Post: List-Unsubscribe=One-Click"
 *                            header so receivers can honor one-click
 *                            unsubscribe per RFC 8058.
 *   - extra_headers:         optional array of "Header: value" strings
 *                            appended verbatim. Use sparingly.
 */
function send_mail(string $to, string $subject, string $textBody, string $htmlBody = '', array $opts = []): void
{
    $cfg = relicheck_config();
    $host     = (string)($cfg['smtp_host'] ?? '');
    $port     = (int)   ($cfg['smtp_port'] ?? 587);
    $user     = (string)($cfg['smtp_user'] ?? '');
    $pass     = (string)($cfg['smtp_pass'] ?? '');
    $defaultFrom = (string)($cfg['mail_from'] ?? $user);
    $defaultFromName = (string)($cfg['mail_from_name'] ?? 'ReliCheck');
    $from     = isset($opts['from']) && $opts['from'] !== ''
        ? (string)$opts['from'] : $defaultFrom;
    $fromName = isset($opts['from_name']) && $opts['from_name'] !== ''
        ? (string)$opts['from_name'] : $defaultFromName;
    $replyTo  = isset($opts['reply_to']) && $opts['reply_to'] !== ''
        ? (string)$opts['reply_to'] : '';
    $hostHelo = parse_url((string)($cfg['site_url'] ?? 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

    if ($host === '' || $user === '' || $pass === '' || $from === '') {
        throw new RuntimeException('SMTP is not configured. Add smtp_* keys to _config.php.');
    }

    $errno = 0; $errstr = '';
    $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 15);
    if (!$sock) throw new RuntimeException("SMTP connect to $host:$port failed: $errstr ($errno)");
    stream_set_timeout($sock, 15);

    $expect = static function ($want) use (&$sock) {
        $resp = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $resp .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($resp, 0, 3);
        if ($code < 200 || $code >= 400) {
            throw new RuntimeException('SMTP unexpected reply: ' . trim($resp));
        }
        if ($want !== null && $code !== $want) {
            throw new RuntimeException("SMTP wanted $want, got: " . trim($resp));
        }
        return $resp;
    };
    $send = static function ($cmd) use (&$sock) { fwrite($sock, $cmd . "\r\n"); };

    try {
        $expect(220);
        $send('EHLO ' . $hostHelo);
        $caps = $expect(250);

        if (stripos($caps, 'STARTTLS') !== false) {
            $send('STARTTLS');
            $expect(220);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!stream_socket_enable_crypto($sock, true, $crypto)) {
                throw new RuntimeException('STARTTLS handshake failed.');
            }
            $send('EHLO ' . $hostHelo);
            $expect(250);
        }

        $send('AUTH LOGIN');
        $expect(334);
        $send(base64_encode($user));
        $expect(334);
        $send(base64_encode($pass));
        $expect(235); // auth ok

        $send('MAIL FROM:<' . $from . '>');
        $expect(250);
        $send('RCPT TO:<' . $to . '>');
        $expect(250);
        $send('DATA');
        $expect(354);

        $boundary = 'rc_' . bin2hex(random_bytes(8));
        $isMulti  = $htmlBody !== '';
        $headers  = [];
        $headers[] = 'From: ' . encode_header_phrase($fromName) . ' <' . $from . '>';
        $headers[] = 'To: <' . $to . '>';
        if ($replyTo !== '') $headers[] = 'Reply-To: <' . $replyTo . '>';
        $headers[] = 'Subject: ' . encode_header_phrase($subject);
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $hostHelo . '>';
        $headers[] = 'MIME-Version: 1.0';
        if (!empty($opts['list_unsubscribe'])) {
            $headers[] = 'List-Unsubscribe: ' . (string)$opts['list_unsubscribe'];
            if (!empty($opts['list_unsubscribe_post'])) {
                $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
            }
        }
        if (!empty($opts['extra_headers']) && is_array($opts['extra_headers'])) {
            foreach ($opts['extra_headers'] as $h) {
                if (is_string($h) && $h !== '') $headers[] = $h;
            }
        }

        $body = '';
        if ($isMulti) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= dot_stuff($textBody) . "\r\n";
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= dot_stuff($htmlBody) . "\r\n";
            $body .= "--$boundary--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body .= dot_stuff($textBody) . "\r\n";
        }

        fwrite($sock, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $expect(250);

        $send('QUIT');
        // Don't strictly require 221; some servers just close.
        @fgets($sock, 1024);
    } finally {
        @fclose($sock);
    }
}

function encode_header_phrase(string $s): string
{
    if (preg_match('/^[\x20-\x7e]*$/', $s) && strpos($s, '=?') === false) {
        return $s;
    }
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function dot_stuff(string $body): string
{
    // SMTP: lines starting with "." must be doubled to ".."
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body) ?? $body;
    return str_replace("\n", "\r\n", $body);
}
