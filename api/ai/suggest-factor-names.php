<?php
// POST /api/ai/suggest-factor-names.php
// Body: { "factors": [ { "items": ["prompt1", "prompt2", ...] }, ... ] }
//
// Stateless factor naming. The client sends, for each retained factor, the
// list of item prompts that load most strongly on it. The model returns a
// short construct label for each factor in order. No respondent data is
// sent; only the question wording.
//
// Output: { ok, names: [string, string, ...], model }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// Same budget bucket as other lightweight AI labeling endpoints.
check_rate_limit('ai_factor_names:user:' . (int)$user['id'], 30, 3600);

$body    = read_json_body();
$rawFacs = $body['factors'] ?? null;

if (!is_array($rawFacs) || count($rawFacs) === 0) {
    fail('bad_input', 'Missing "factors" array.');
}

// Coerce + clamp. Max 8 factors per request, max 12 items per factor,
// each item prompt capped at 240 chars.
$factors = [];
foreach ($rawFacs as $f) {
    if (!is_array($f)) continue;
    $items = $f['items'] ?? null;
    if (!is_array($items)) continue;
    $cleanItems = [];
    foreach ($items as $it) {
        if (!is_string($it)) continue;
        $val = trim($it);
        if ($val === '') continue;
        if (strlen($val) > 240) $val = substr($val, 0, 240) . '...';
        $cleanItems[] = $val;
        if (count($cleanItems) >= 12) break;
    }
    if (count($cleanItems) === 0) continue;
    $factors[] = ['items' => $cleanItems];
    if (count($factors) >= 8) break;
}

if (count($factors) === 0) {
    fail('insufficient_data', 'No usable item prompts to label.');
}

// Build the prompt body.
$blocks = [];
foreach ($factors as $idx => $f) {
    $lines = [];
    foreach ($f['items'] as $i => $t) {
        $lines[] = '  ' . ($i + 1) . '. ' . $t;
    }
    $blocks[] = 'Factor ' . ($idx + 1) . ':' . "\n" . implode("\n", $lines);
}
$factorBlock = implode("\n\n", $blocks);

$system = <<<SYS
You are a measurement researcher. The user gives you sets of survey item prompts grouped by factor (an exploratory factor analysis has decided which items load on which factor). For each factor, propose a short construct label that names the underlying trait the items appear to measure.

Quality bar:
- A label is a noun phrase, 2-5 words, Title Case. Examples: "Work Engagement", "Workload Strain", "Academic Self-Efficacy", "Perceived Social Support".
- The label names the construct, not the items. Avoid generic words like "Factor", "Group", or "Cluster".
- If items appear to mix two constructs, pick the most prominent one and rely on the count.
- Match the survey's domain language when obvious from the items.

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "names": ["<label for factor 1>", "<label for factor 2>", ...]
}

The names array MUST contain exactly the same number of entries as the factors provided, in the same order.
SYS;

$userPrompt  = "Factors and their items:\n\n" . $factorBlock . "\n\n";
$userPrompt .= "Provide a label for each factor in order.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 600);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['names']) || !is_array($parsed['names'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$names = [];
foreach ($parsed['names'] as $n) {
    $clean = clean_string((string)$n, 64);
    // Strip any trailing period or trailing whitespace.
    $clean = rtrim($clean, ". \t\n\r");
    if ($clean === '') $clean = 'Factor';
    $names[] = $clean;
}
// Pad or trim to match request length.
while (count($names) < count($factors)) {
    $names[] = 'Factor ' . (count($names) + 1);
}
$names = array_slice($names, 0, count($factors));

json_out([
    'ok'    => true,
    'names' => $names,
    'model' => ai_config()['model'],
]);
