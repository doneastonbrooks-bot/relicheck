<?php
// POST /api/mm/report-rewrite.php
// Body: {
//   project_id,
//   action: "concise" | "strengthen" | "tone_down",
//   selected_text,
//   context?: "optional surrounding paragraph or section type for grounding"
// }
//
// Returns the rewritten snippet only - no quotes, no preamble, no markdown.
// The caller replaces the selection with the returned text.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

check_rate_limit('mm_rewrite:user:' . $uid, 200, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 24);
$selected  = (string)($body['selected_text'] ?? '');
$context   = clean_string((string)($body['context'] ?? ''), 1200);

if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);
if (!in_array($action, ['concise', 'strengthen', 'tone_down'], true)) {
    fail('bad_input', 'action must be concise, strengthen, or tone_down.');
}
$selected = trim($selected);
if ($selected === '') fail('bad_input', 'selected_text is required.');
if (mb_strlen($selected) < 4) fail('bad_input', 'Highlight more than a couple of characters.');
if (mb_strlen($selected) > 2000) fail('bad_input', 'Selection too long (max 2000 chars).');

// Action-specific system prompt.
$systems = [
    'concise' => <<<SYS
You are a research-writing editor. Rewrite the snippet to be more concise without losing any factual claim or named entity. Preserve numbers, percentages, theme names, and quoted material exactly. Cut hedging, filler, and redundancy.

Hard rules:
- Output the rewrite ONLY. No preamble like "Here is...", no markdown, no quotes around the text.
- Do not change any number, percentage, or theme name.
- Match the original tone (academic, plain prose).
- Output should be shorter than the input by at least 15%.
SYS,
    'strengthen' => <<<SYS
You are a research-writing editor. Rewrite the snippet so its claims are stronger and tied more clearly to the data evidence already mentioned. Keep every number, percentage, and theme name exactly. If the snippet hedges a claim that the data actually support, replace the hedge with a precise statement that names the metric.

Hard rules:
- Output the rewrite ONLY. No preamble, no markdown, no quotes around the text.
- Never invent new numbers, themes, p-values, or effect sizes.
- Keep length within +/- 25% of the input.
- Academic prose, not marketing copy.
SYS,
    'tone_down' => <<<SYS
You are a research-writing editor. Rewrite the snippet to soften any overclaims while keeping every factual element intact. Use cautious academic phrasing where the original is too definitive. Preserve all numbers, percentages, and theme names exactly.

Hard rules:
- Output the rewrite ONLY. No preamble, no markdown, no quotes around the text.
- Use hedging language only where the evidence is thin (small sample, large p-value, small effect size). Do not weaken statements that have strong support.
- Keep length within +/- 25% of the input.
SYS,
];

$system = $systems[$action];

$userMsg = "SNIPPET TO REWRITE:\n" . $selected;
if ($context !== '') $userMsg .= "\n\nSURROUNDING CONTEXT (do not rewrite this, just for grounding):\n" . $context;
$userMsg .= "\n\nReturn only the rewritten snippet.";

try {
    $resp = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 600);
    $text = trim((string)($resp['text'] ?? ''));
    // Strip common preamble accidents the model occasionally outputs.
    $text = preg_replace('/^(here(?:\s+is|\'s)?\s+(?:the\s+)?(?:rewritten|revised|edited|concise|stronger|softened)[^:]*:)\s*/i', '', $text);
    $text = preg_replace('/^["“”\']+|["“”\']+$/u', '', $text);
    $text = trim($text);
    if ($text === '') fail('ai_empty', 'AI returned no text. Try a different selection.', 502);
    if (mb_strlen($text) > mb_strlen($selected) * 3) {
        fail('ai_overlong', 'AI returned a much longer text than expected. Try again.', 502);
    }
    json_out([
        'ok' => true,
        'action' => $action,
        'rewritten_text' => $text,
        'original_length' => mb_strlen($selected),
        'rewritten_length' => mb_strlen($text),
    ]);
} catch (Throwable $e) {
    fail('ai_failed', 'AI rewrite failed: ' . $e->getMessage(), 502);
}
