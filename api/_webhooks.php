<?php
// Outbound webhooks helper.
//
// The fire() function below loads all active webhooks for an owner+event
// pair and POSTs to each destination with an HMAC-SHA256 signature header.
// Designed to be non-blocking: short timeouts, errors swallowed and recorded
// in webhooks.last_status / .last_error so the caller (e.g. submit.php) is
// never held up by a slow or failing receiver.
//
// Two payload formats:
//   - Slack-friendly Block Kit when the destination URL is hooks.slack.com
//   - Plain JSON otherwise (works for Zapier catch-hooks, n8n, custom URLs)

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

const WEBHOOK_TIMEOUT_SECONDS = 4;     // total request timeout
const WEBHOOK_CONNECT_TIMEOUT  = 2;     // connect-only timeout
const WEBHOOK_USER_AGENT       = 'ReliCheck-Webhooks/1.0';
const WEBHOOK_KNOWN_EVENTS     = ['response.received', 'survey.published'];

/**
 * Fire all webhooks registered to (owner_id, event).
 *
 * @param string $event  e.g. "response.received"
 * @param int    $owner  workspace owner_id whose webhooks should be loaded
 * @param array  $data   event-specific payload data; merged into the envelope
 *
 * Errors are caught and recorded; this function never throws.
 */
function webhooks_fire(string $event, int $owner, array $data): void
{
    if (!in_array($event, WEBHOOK_KNOWN_EVENTS, true)) return;
    try {
        $stmt = db()->prepare(
            'SELECT id, name, url, secret, events FROM webhooks
              WHERE owner_id = :o AND active = 1'
        );
        $stmt->execute([':o' => $owner]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return; // DB error: don't break the caller
    }
    foreach ($rows as $row) {
        $events = json_decode((string)$row['events'], true) ?: [];
        if (!in_array($event, $events, true)) continue;
        webhooks_dispatch_one((int)$row['id'], (string)$row['url'], (string)$row['secret'], $event, $data);
    }
}

/**
 * Dispatch a single webhook delivery. Records status into the webhooks row.
 * Phase 152: also writes a row into webhook_deliveries and trims oldest
 * beyond 50 per webhook so the detail view can show recent history.
 *
 * @param bool $isTest  true when fired from the Test button; tagged in the
 *                      delivery log so the UI can distinguish synthetic from
 *                      real events.
 */
function webhooks_dispatch_one(int $webhookId, string $url, string $secret, string $event, array $data, bool $isTest = false): array
{
    $envelope = [
        'event'    => $event,
        'sent_at'  => gmdate('c'),
        'data'     => $data,
    ];
    $body = webhooks_format_for_url($url, $event, $envelope);
    $signature = hash_hmac('sha256', $body, $secret);

    $status = null;
    $err    = null;
    $respBody = '';
    $startMs  = (int)round(microtime(true) * 1000);
    if (!function_exists('curl_init')) {
        $err = 'curl extension unavailable';
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: ' . WEBHOOK_USER_AGENT,
                'X-ReliCheck-Event: ' . $event,
                'X-ReliCheck-Signature: sha256=' . $signature,
                'X-ReliCheck-Webhook-Id: ' . $webhookId,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => WEBHOOK_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => WEBHOOK_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch) ?: 'unknown curl error';
        } else {
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $respBody = is_string($resp) ? $resp : '';
        }
        curl_close($ch);
    }
    $durationMs = max(0, (int)round(microtime(true) * 1000) - $startMs);

    $ok = ($status !== null && $status >= 200 && $status < 300);
    try {
        $upd = db()->prepare(
            'UPDATE webhooks
                SET last_fired_at = NOW(),
                    last_status   = :st,
                    last_error    = :er,
                    total_fires   = total_fires + 1,
                    failed_fires  = failed_fires + :ff
              WHERE id = :id'
        );
        $upd->execute([
            ':st' => $status,
            ':er' => $err ? mb_substr($err, 0, 250) : null,
            ':ff' => $ok ? 0 : 1,
            ':id' => $webhookId,
        ]);
    } catch (Throwable $e) {
        // Bookkeeping failure shouldn't propagate.
    }

    // Phase 152: record one row in webhook_deliveries and trim to last 50.
    try {
        $ins = db()->prepare(
            'INSERT INTO webhook_deliveries
                (webhook_id, event, http_status, response_excerpt, error, duration_ms, payload_json, is_test)
             VALUES (:w, :e, :st, :rx, :er, :dm, :pl, :it)'
        );
        $ins->execute([
            ':w'  => $webhookId,
            ':e'  => mb_substr($event, 0, 80),
            ':st' => $status,
            ':rx' => $respBody !== '' ? mb_substr($respBody, 0, 500) : null,
            ':er' => $err ? mb_substr($err, 0, 250) : null,
            ':dm' => $durationMs,
            ':pl' => mb_substr($body, 0, 16777215),
            ':it' => $isTest ? 1 : 0,
        ]);
        // Trim: keep last 50 per webhook. Cheaper than ORDER BY DESC LIMIT
        // 50,1 -> DELETE because MySQL doesn't let you do that directly.
        $del = db()->prepare(
            'DELETE FROM webhook_deliveries
              WHERE webhook_id = :w AND id NOT IN (
                SELECT id FROM (
                  SELECT id FROM webhook_deliveries
                   WHERE webhook_id = :w2
                   ORDER BY fired_at DESC, id DESC
                   LIMIT 50
                ) AS keep
              )'
        );
        $del->execute([':w' => $webhookId, ':w2' => $webhookId]);
    } catch (Throwable $_) {
        // Migration not yet applied or transient error; non-fatal.
    }

    return [
        'status'        => $status,
        'error'         => $err,
        'response'      => $respBody,
        'duration_ms'   => $durationMs,
        'ok'            => $ok,
    ];
}

/**
 * Build the request body. Slack URLs get Slack Block Kit; everything else
 * gets the generic envelope.
 */
function webhooks_format_for_url(string $url, string $event, array $envelope): string
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === 'hooks.slack.com') {
        return json_encode(webhooks_slack_payload($event, $envelope), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Slack-friendly Block Kit payload. Renders a clean message with an event
 * line and an optional context line. Receivers see proper formatting in
 * any Slack channel that the incoming webhook is wired to.
 */
function webhooks_slack_payload(string $event, array $envelope): array
{
    $data = $envelope['data'] ?? [];
    $title = '';
    $context = '';
    if ($event === 'response.received') {
        $title   = ':bar_chart: New response on *' . webhooks_slack_escape((string)($data['survey_title'] ?? 'a survey')) . '*';
        $context = 'Total responses: *' . (int)($data['response_count'] ?? 0) . '*';
        if (!empty($data['survey_url'])) {
            $context .= '  *  <' . $data['survey_url'] . '|Open in ReliCheck>';
        }
    } elseif ($event === 'survey.published') {
        $title   = ':rocket: Survey published: *' . webhooks_slack_escape((string)($data['survey_title'] ?? 'a survey')) . '*';
        $context = 'Share link ready';
        if (!empty($data['share_url'])) {
            $context .= '  *  <' . $data['share_url'] . '|Take the survey>';
        }
        if (!empty($data['survey_url'])) {
            $context .= '  *  <' . $data['survey_url'] . '|Open in ReliCheck>';
        }
    } else {
        $title = 'ReliCheck event: *' . $event . '*';
    }
    return [
        'text'   => $title,
        'blocks' => [
            ['type' => 'section',  'text'  => ['type' => 'mrkdwn', 'text' => $title]],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $context !== '' ? $context : ' ']]],
        ],
    ];
}

function webhooks_slack_escape(string $s): string
{
    // Slack's mrkdwn requires < > & to be entity-escaped.
    return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
}

/**
 * Generate a fresh webhook secret (32 bytes hex).
 */
function webhooks_generate_secret(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Validate a list of event names against the known set. Returns the cleaned
 * list (only known events, deduplicated) or null if every input was unknown.
 */
function webhooks_validate_events(array $input): ?array
{
    $clean = [];
    foreach ($input as $e) {
        if (is_string($e) && in_array($e, WEBHOOK_KNOWN_EVENTS, true) && !in_array($e, $clean, true)) {
            $clean[] = $e;
        }
    }
    return $clean ?: null;
}
