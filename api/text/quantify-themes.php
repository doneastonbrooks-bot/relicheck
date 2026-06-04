<?php
// POST /api/text/quantify-themes.php
// Body: { themes: [{name, description}], responses: string[], cultural_context?: string }
// Returns: { ok: true, data: { matrix: [{response_idx, preview, theme_presence}], theme_names[] } }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$uid  = (int)$user['id'];

check_rate_limit('text_quantify:user:' . $uid, 10, 3600);

release_session_lock();

$body            = read_json_body();
$rawThemes       = $body['themes'] ?? [];
$responses       = $body['responses'] ?? [];
$culturalContext = clean_string((string)($body['cultural_context'] ?? ''), 1000);

if (!is_array($rawThemes) || count($rawThemes) === 0) {
    fail('bad_input', 'Themes array is required.');
}
if (!is_array($responses) || count($responses) === 0) {
    fail('bad_input', 'Responses array is required.');
}

// Normalize themes
$themes = [];
foreach ($rawThemes as $t) {
    $name = trim((string)($t['name'] ?? ''));
    if ($name === '') continue;
    $themes[] = [
        'name'        => $name,
        'description' => trim((string)($t['description'] ?? '')),
    ];
}
if (count($themes) === 0) {
    fail('bad_input', 'No valid themes provided.');
}

// Normalize responses — cap at 300 for quantification
$responses = array_values(array_filter(array_map(function ($r) {
    return trim((string)$r);
}, $responses), function ($r) { return $r !== ''; }));

$totalCount = count($responses);
if ($totalCount > 300) {
    $responses = array_slice($responses, 0, 300);
}

$themeNames = array_column($themes, 'name');

$contextBlock = '';
if ($culturalContext !== '') {
    $contextBlock = "\nCULTURAL AND ORGANIZATIONAL CONTEXT:\n" . $culturalContext . "\n";
}

// Build themes description for the prompt
$themesBlock = '';
foreach ($themes as $i => $t) {
    $themesBlock .= ($i + 1) . '. ' . $t['name'];
    if ($t['description']) $themesBlock .= ': ' . $t['description'];
    $themesBlock .= "\n";
}

$matrix = [];

// Process in batches of 60
$batchSize = 60;
$batches   = array_chunk($responses, $batchSize, true);

foreach ($batches as $batchResponses) {
    $batchList = '';
    foreach ($batchResponses as $origIdx => $r) {
        $batchList .= $origIdx . '. ' . $r . "\n";
    }

    $system = <<<PROMPT
You are a research assistant creating a preliminary theme presence matrix. For each response, indicate which suggested themes appear to be present.

SUGGESTED THEMES:
{$themesBlock}{$contextBlock}
Instructions:
- For each response, determine whether each theme is present (true) or absent (false).
- Be inclusive: if a theme is even somewhat reflected, mark it true.
- A response may match zero, one, or multiple themes.
- Use ONLY the response index numbers from the input. Do not renumber.

Return ONLY valid JSON in this exact structure. No prose.
{
  "matrix": [
    {
      "response_idx": 0,
      "theme_presence": {"Theme Name": true, "Other Theme": false}
    }
  ]
}
PROMPT;

    $userMessage = "Responses to score:\n\n" . $batchList;

    $ai     = ai_complete($system, [['role' => 'user', 'content' => $userMessage]], 4000);
    $parsed = ai_extract_json($ai['text']);

    if (!$parsed || !isset($parsed['matrix']) || !is_array($parsed['matrix'])) {
        // Skip bad batches rather than failing the whole request
        continue;
    }

    foreach ($parsed['matrix'] as $row) {
        $idx = (int)($row['response_idx'] ?? -1);
        if ($idx < 0 || $idx >= $totalCount) continue;
        $presence = [];
        foreach ($themeNames as $name) {
            $val = $row['theme_presence'][$name] ?? false;
            $presence[$name] = (bool)$val;
        }
        $raw     = $responses[$idx] ?? '';
        $preview = mb_strlen($raw) > 90 ? mb_substr($raw, 0, 87) . '...' : $raw;
        $matrix[] = [
            'response_idx'   => $idx,
            'preview'        => $preview,
            'theme_presence' => $presence,
        ];
    }
}

// Sort by response_idx
usort($matrix, function ($a, $b) { return $a['response_idx'] <=> $b['response_idx']; });

// Build per-theme frequency counts
$frequencies = [];
foreach ($themeNames as $name) {
    $count = 0;
    foreach ($matrix as $row) {
        if (!empty($row['theme_presence'][$name])) $count++;
    }
    $frequencies[$name] = $count;
}

json_out([
    'ok'   => true,
    'data' => [
        'matrix'           => $matrix,
        'theme_names'      => $themeNames,
        'frequencies'      => $frequencies,
        'response_count'   => $totalCount,
        'scored_count'     => count($matrix),
    ],
]);
