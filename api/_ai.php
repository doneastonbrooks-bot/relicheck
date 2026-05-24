<?php
// AI helper: thin wrapper around the Anthropic Messages API.
// No Composer dependency - uses stream contexts so it runs on vanilla PHP.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';
const ANTHROPIC_VERSION = '2023-06-01';

/**
 * Returns ['enabled' => bool, 'key' => string, 'model' => string].
 */
function ai_config(): array
{
    $cfg   = relicheck_config();
    $key   = (string)($cfg['anthropic_api_key'] ?? '');
    $model = (string)($cfg['anthropic_model']    ?? 'claude-sonnet-4-6');
    return ['enabled' => $key !== '', 'key' => $key, 'model' => $model];
}

/**
 * Calls the Anthropic Messages API and returns the decoded response.
 *
 * @param string $system    System prompt
 * @param array  $messages  Array of ['role' => 'user'|'assistant', 'content' => string]
 * @param int    $maxTokens Max tokens in the response
 * @return array            ['text' => string, 'raw' => array]
 */
function ai_complete(string $system, array $messages, int $maxTokens = 2048, ?string $modelOverride = null): array
{
    $cfg = ai_config();
    if (!$cfg['enabled']) {
        fail('ai_disabled', 'AI features are not configured on this server.', 503);
    }

    $model = ($modelOverride !== null && $modelOverride !== '') ? $modelOverride : $cfg['model'];

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => $messages,
    ];

    $headers = [
        'x-api-key: ' . $cfg['key'],
        'anthropic-version: ' . ANTHROPIC_VERSION,
        'content-type: application/json',
    ];

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout'       => 60,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $raw = @file_get_contents(ANTHROPIC_API_URL, false, $ctx);
    if ($raw === false) {
        fail('ai_unreachable', 'Could not reach the AI service.', 502);
    }

    // Pull HTTP status from the response headers
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail('ai_bad_response', 'Unexpected response from the AI service.', 502);
    }

    if ($status < 200 || $status >= 300) {
        $msg = (string)($decoded['error']['message'] ?? ('AI API returned HTTP ' . $status));
        fail('ai_api_error', 'AI service error: ' . $msg, 502);
    }

    // Concatenate any text content blocks the model returned.
    $text = '';
    if (isset($decoded['content']) && is_array($decoded['content'])) {
        foreach ($decoded['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
    }

    return ['text' => $text, 'raw' => $decoded];
}

/**
 * Pulls the first JSON object out of a string, tolerating prose around it
 * or a ```json fenced block. Returns null if no valid JSON is found.
 */
function ai_extract_json(string $text): ?array
{
    // Strip a fenced code block if present.
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $text, $m)) {
        $candidate = $m[1];
    } else {
        // Otherwise, grab the first {...} balanced block.
        $start = strpos($text, '{');
        if ($start === false) return null;
        $depth = 0;
        $end = -1;
        $inString = false;
        $escape = false;
        for ($i = $start; $i < strlen($text); $i++) {
            $ch = $text[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\') { $escape = true; continue; }
            if ($ch === '"') { $inString = !$inString; continue; }
            if ($inString) continue;
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end < 0) return null;
        $candidate = substr($text, $start, $end - $start + 1);
    }

    $decoded = json_decode($candidate, true);
    return is_array($decoded) ? $decoded : null;
}
