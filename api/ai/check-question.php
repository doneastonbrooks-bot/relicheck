<?php
// POST /api/ai/check-question.php
// Body: { "prompt": "<text>", "type": "likert"|"single"|"multi"|"open" }
//
// Lightweight per-question quality check. Designed to be called on a debounce
// from the survey builder as the user types. Returns a small array of issues,
// each with an optional one-tap rewrite.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// Higher cap because this is debounced and called on every meaningful edit.
// 200 checks per user per hour keeps a determined editor happy without
// letting a runaway loop run up the bill.
check_rate_limit('ai_check:user:' . (int)$user['id'], 200, 3600);

$body   = read_json_body();
$prompt = clean_string((string)($body['prompt'] ?? ''), 4000);
$type   = (string)($body['type'] ?? 'likert');

if ($prompt === '') {
    json_out(['ok' => true, 'issues' => []]);
}
if (!in_array($type, ['likert','single','multi','open'], true)) {
    fail('bad_type', 'type must be one of likert, single, multi, open.');
}

// Cheap pre-checks. If the prompt is very short, skip the AI entirely.
if (mb_strlen($prompt) < 8) {
    json_out(['ok' => true, 'issues' => []]);
}

$typeHint = [
    'likert' => 'A Likert item should be a clear statement (not a question) that respondents can agree or disagree with on a scale.',
    'single' => 'A single-choice question asks the respondent to pick exactly one option.',
    'multi'  => 'A multi-choice question asks the respondent to pick one or more options.',
    'open'   => 'An open-ended question invites a short written answer.',
][$type];

$system = <<<SYS
You are a survey methodology reviewer. Look at one draft survey item and flag real writing problems. Be conservative: only flag issues that would meaningfully reduce response quality. Clean items return an empty issues array.

Item type guidance: {$typeHint}

Issues you can flag (use only these "type" values):
- double_barreled: asks about two things at once (look for "and" or "or" joining concepts).
- leading: pushes the respondent toward an answer ("Don't you agree...", "Most people think...", "Wouldn't you say...").
- ambiguous_time: uses vague time words like "recently", "often", "regularly", "in the past" without anchoring.
- double_negative: contains two negatives that confuse the meaning.
- loaded_language: uses charged or biased wording (extreme adjectives, jargon, value judgments).
- reading_level: too long, too complex, or above ~10th-grade reading level for general audiences.
- type_mismatch: phrased as a question on a Likert item, or as a statement on a single/multi/open item.
- too_long: prompt is meaningfully longer than it needs to be (over 25 words for likert/single/multi).

Severity:
- "warn" for issues that will materially affect responses or reliability.
- "info" for minor stylistic suggestions.

Output format: respond with a single JSON object only, no prose around it, in exactly this shape:

{
  "issues": [
    {
      "type":     "<one of the values above>",
      "severity": "warn" | "info",
      "message":  "<one short sentence describing the issue>",
      "fix":      "<a complete rewrite of the prompt that fixes only this one issue while preserving meaning, or null if no clean fix exists>"
    }
  ]
}

Rules:
- Maximum of 3 issues per item.
- Each issue must be distinct from the others.
- Do not flag the same problem twice with different "type" values.
- The "fix" must be a full replacement for the prompt text, not a fragment.
- If the item is clean, return { "issues": [] } and nothing else.
- Do not wrap the JSON in markdown fences. Output the raw JSON object.
SYS;

$userPrompt = "Item type: " . $type . "\n";
$userPrompt .= "Item text: \"" . $prompt . "\"\n\n";
$userPrompt .= "Check this item now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 700);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['issues']) || !is_array($parsed['issues'])) {
    // Treat parse failures as "no issues found" so a flaky model never blocks
    // the user from typing.
    json_out(['ok' => true, 'issues' => []]);
}

$validTypes = ['double_barreled','leading','ambiguous_time','double_negative','loaded_language','reading_level','type_mismatch','too_long'];

$issues = [];
foreach ($parsed['issues'] as $iss) {
    if (!is_array($iss)) continue;
    $kind = (string)($iss['type'] ?? '');
    if (!in_array($kind, $validTypes, true)) continue;
    $sev  = strtolower((string)($iss['severity'] ?? 'info'));
    if (!in_array($sev, ['warn','info'], true)) $sev = 'info';
    $msg  = clean_string((string)($iss['message'] ?? ''), 280);
    if ($msg === '') continue;
    $fix  = isset($iss['fix']) && is_string($iss['fix']) ? clean_string($iss['fix'], 4000) : '';
    if ($fix === '') $fix = null;
    $issues[] = [
        'type'     => $kind,
        'severity' => $sev,
        'message'  => $msg,
        'fix'      => $fix,
    ];
    if (count($issues) === 3) break;
}

json_out([
    'ok'     => true,
    'issues' => $issues,
    'model'  => ai_config()['model'],
]);
