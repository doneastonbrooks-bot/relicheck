<?php
// Phase 121: Slack and Microsoft Teams distribution channels.
//
// Helpers to format and deliver a survey-take card to a Slack incoming
// webhook (Block Kit) or Teams webhook (Adaptive Card). The webhook URL
// is provided by the user during channel setup, so no OAuth required.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

/**
 * Validate that a pasted webhook URL looks like one of the supported
 * destinations. Returns the kind ("slack" | "teams") or null when the
 * URL does not match a known shape.
 */
function channels_detect_kind(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) return null;
    if (!preg_match('@^https?://@i', $url)) return null;
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    if ($host === '') return null;
    $host = strtolower($host);
    if ($host === 'hooks.slack.com' || str_ends_with($host, '.hooks.slack.com')) return 'slack';
    if (preg_match('@\.webhook\.office\.com$@', $host)) return 'teams';
    if (preg_match('@outlook\.office\.com$@', $host)) return 'teams';
    return null;
}

/**
 * Build the Slack Block Kit payload for a survey share card.
 */
function channels_slack_payload(array $survey, string $shareLink, string $shareNote = ''): array
{
    $title = trim((string)($survey['title'] ?? '(untitled survey)'));
    $desc  = trim((string)($survey['description'] ?? ''));
    if ($desc !== '' && strlen($desc) > 600) $desc = substr($desc, 0, 600) . '...';

    $blocks = [];
    $blocks[] = [
        'type' => 'header',
        'text' => [
            'type' => 'plain_text',
            'text' => 'New survey: ' . channels_slack_escape($title),
            'emoji' => true,
        ],
    ];
    if ($shareNote !== '') {
        $blocks[] = [
            'type' => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => channels_slack_escape($shareNote)]],
        ];
    }
    if ($desc !== '') {
        $blocks[] = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => channels_slack_escape($desc)],
        ];
    }
    $blocks[] = [
        'type' => 'actions',
        'elements' => [[
            'type'  => 'button',
            'style' => 'primary',
            'text'  => ['type' => 'plain_text', 'text' => 'Take the survey', 'emoji' => true],
            'url'   => $shareLink,
        ]],
    ];
    return [
        'blocks'        => $blocks,
        'unfurl_links'  => false,
        'unfurl_media'  => false,
    ];
}

function channels_slack_escape(string $s): string
{
    return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
}

/**
 * Build a Microsoft Teams Adaptive Card payload (1.4) wrapped in the
 * MessageCard envelope Teams webhooks expect.
 */
function channels_teams_payload(array $survey, string $shareLink, string $shareNote = ''): array
{
    $title = trim((string)($survey['title'] ?? '(untitled survey)'));
    $desc  = trim((string)($survey['description'] ?? ''));
    if ($desc !== '' && strlen($desc) > 800) $desc = substr($desc, 0, 800) . '...';

    $body = [];
    $body[] = [
        'type'   => 'TextBlock',
        'size'   => 'Medium',
        'weight' => 'Bolder',
        'text'   => 'New survey: ' . $title,
        'wrap'   => true,
    ];
    if ($shareNote !== '') {
        $body[] = [
            'type'      => 'TextBlock',
            'isSubtle'  => true,
            'spacing'   => 'Small',
            'text'      => $shareNote,
            'wrap'      => true,
        ];
    }
    if ($desc !== '') {
        $body[] = [
            'type'    => 'TextBlock',
            'spacing' => 'Medium',
            'text'    => $desc,
            'wrap'    => true,
        ];
    }
    $card = [
        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
        'type'    => 'AdaptiveCard',
        'version' => '1.4',
        'body'    => $body,
        'actions' => [[
            'type'  => 'Action.OpenUrl',
            'title' => 'Take the survey',
            'url'   => $shareLink,
        ]],
    ];

    return [
        'type'        => 'message',
        'attachments' => [[
            'contentType' => 'application/vnd.microsoft.card.adaptive',
            'contentUrl'  => null,
            'content'     => $card,
        ]],
    ];
}

/**
 * Deliver the payload to the webhook URL and return:
 *   [ 'ok' => bool, 'http' => int, 'body' => string, 'status' => string ]
 *
 * status is a short label suitable for storing in survey_channels.last_status.
 */
function channels_post_webhook(string $url, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'http' => 0, 'body' => 'json_encode_failed', 'status' => 'failed'];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nUser-Agent: ReliCheck/1.0\r\n",
            'content'       => $json,
            'timeout'       => 6,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $http = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('@^HTTP/\S+\s+(\d{3})@', $h, $m)) { $http = (int)$m[1]; break; }
        }
    }
    $bodyTrimmed = is_string($body) ? substr($body, 0, 255) : '';
    if ($http >= 200 && $http < 300) {
        return ['ok' => true,  'http' => $http, 'body' => $bodyTrimmed, 'status' => 'ok'];
    }
    if ($http === 0)                         $statusLbl = 'unreachable';
    elseif ($http === 401 || $http === 403)  $statusLbl = 'forbidden';
    elseif ($http === 404 || $http === 410)  $statusLbl = 'gone';
    elseif ($http >= 500)                    $statusLbl = 'server_error';
    else                                     $statusLbl = 'failed';
    return ['ok' => false, 'http' => $http, 'body' => $bodyTrimmed, 'status' => $statusLbl];
}

/**
 * Convenience: build the right payload for the channel's kind, deliver,
 * and stamp survey_channels with the outcome.
 */
function channels_dispatch_to_channel(PDO $pdo, array $channel, array $survey, string $shareLink, string $shareNote = ''): array
{
    $payload = ($channel['kind'] === 'teams')
        ? channels_teams_payload($survey, $shareLink, $shareNote)
        : channels_slack_payload($survey, $shareLink, $shareNote);
    $result = channels_post_webhook((string)$channel['webhook_url'], $payload);

    $u = $pdo->prepare(
        'UPDATE survey_channels
            SET last_fired_at = NOW(),
                last_status   = :status,
                last_response = :body,
                fired_count   = fired_count + :inc
          WHERE id = :id'
    );
    $u->execute([
        ':status' => substr($result['status'], 0, 40),
        ':body'   => substr((string)$result['body'], 0, 255),
        ':inc'    => $result['ok'] ? 1 : 0,
        ':id'     => (int)$channel['id'],
    ]);
    return $result;
}
