<?php
// POST /api/text/analyze-themes.php
// Body: { responses: string[], cultural_context?: string }
// Returns: { ok: true, data: { summary, themes[], counter_patterns[] } }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$uid  = (int)$user['id'];

check_rate_limit('text_analyze:user:' . $uid, 20, 3600);

// Free session lock — this endpoint makes a multi-second AI call.
release_session_lock();

$body            = read_json_body();
$responses       = $body['responses'] ?? [];
$culturalContext = clean_string((string)($body['cultural_context'] ?? ''), 1000);

if (!is_array($responses) || count($responses) === 0) {
    fail('bad_input', 'Responses array is required and must not be empty.');
}

// Normalize: cast to strings, drop blanks
$responses = array_values(array_filter(array_map(function ($r) {
    return trim((string)$r);
}, $responses), function ($r) { return $r !== ''; }));

if (count($responses) === 0) {
    fail('bad_input', 'No non-empty responses provided.');
}

// Sample down if very large — keep up to 250 for theme discovery
$totalCount = count($responses);
$sampled    = false;
if ($totalCount > 250) {
    shuffle($responses);
    $responses = array_slice($responses, 0, 250);
    $sampled   = true;
}

// Build numbered response list for the prompt
$numberedList = '';
foreach ($responses as $i => $r) {
    $numberedList .= ($i + 1) . '. ' . $r . "\n";
}

$contextBlock = '';
if ($culturalContext !== '') {
    $contextBlock = "\nCULTURAL AND ORGANIZATIONAL CONTEXT:\n" . $culturalContext . "\n";
}

$system = <<<PROMPT
You are a qualitative research assistant performing a preliminary, first-pass thematic analysis of open-ended text responses. Your role is to surface emerging patterns. These are NOT validated findings — they are preliminary observations to guide further analysis. Use provisional language throughout.
{$contextBlock}
Instructions:
1. Read all responses carefully.
2. Identify 4 to 8 distinct suggested themes that emerge from the data. Prefer fewer, clearer themes over many thin ones.
3. For each theme: give a clear name, a 2-3 sentence description using provisional language ("appears to", "suggests", "may indicate"), 2-3 representative quote candidates (verbatim from the responses), a prominence level (high, moderate, or low), and a one-line prominence note.
4. Identify counter-patterns: responses or ideas that challenge, complicate, or contradict the dominant themes. List 2-4 as short statements.
5. Write a 2-3 sentence preliminary summary of the overall patterns in the data.
6. DO NOT invent quotes. Only use text that actually appears in the responses.

Return ONLY valid JSON in this exact structure. No prose before or after the JSON.
{
  "summary": "2-3 sentence overview using provisional language",
  "themes": [
    {
      "name": "Theme name",
      "description": "2-3 sentences using provisional language",
      "quotes": ["verbatim quote", "verbatim quote"],
      "prominence": "high|moderate|low",
      "prominence_note": "brief note on how many responses seem to reflect this"
    }
  ],
  "counter_patterns": ["short statement 1", "short statement 2"]
}
PROMPT;

$userMessage = "Here are the responses to analyze:\n\n" . $numberedList;

$ai = ai_complete($system, [
    ['role' => 'user', 'content' => $userMessage],
], 3000);

$parsed = ai_extract_json($ai['text']);

if (!$parsed || !isset($parsed['themes']) || !is_array($parsed['themes'])) {
    fail('ai_parse_error', 'Could not parse a valid theme structure from the AI response.');
}

// Normalize theme structure
$themes = [];
foreach ($parsed['themes'] as $t) {
    if (empty($t['name'])) continue;
    $prom = strtolower(trim((string)($t['prominence'] ?? 'moderate')));
    if (!in_array($prom, ['high', 'moderate', 'low'], true)) $prom = 'moderate';
    $themes[] = [
        'name'           => (string)($t['name'] ?? ''),
        'description'    => (string)($t['description'] ?? ''),
        'quotes'         => array_values(array_filter(array_map('strval', (array)($t['quotes'] ?? [])))),
        'prominence'     => $prom,
        'prominence_note'=> (string)($t['prominence_note'] ?? ''),
    ];
}

$counterPatterns = array_values(array_filter(array_map('strval', (array)($parsed['counter_patterns'] ?? []))));

json_out([
    'ok'      => true,
    'data'    => [
        'summary'          => (string)($parsed['summary'] ?? ''),
        'themes'           => $themes,
        'counter_patterns' => $counterPatterns,
        'response_count'   => $totalCount,
        'analyzed_count'   => count($responses),
        'sampled'          => $sampled,
    ],
]);
