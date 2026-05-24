<?php
// POST /api/mm/score-to-theme.php
// Body: { project_id, score_label?, score_field? }
//
// Pathway A analysis: pulls the project's numeric_value + text and asks the AI
// to summarize what comments explain about the score. Reads from
// mm_text_responses where numeric_value is present.
//
// Output rows feed the Score-to-Theme Analysis card in the Studio UI.

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

check_rate_limit('mm_score_theme:user:' . $uid, 30, 3600);

$body       = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$scoreLabel = clean_string((string)($body['score_label'] ?? 'Score'), 120);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

$stmt = $pdo->prepare(
    'SELECT id, numeric_value, text, group_value
     FROM mm_text_responses
     WHERE project_id = :p AND numeric_value IS NOT NULL
     ORDER BY id ASC LIMIT 400'
);
$stmt->execute([':p' => $projectId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($rows) < 5) {
    fail('insufficient_data', 'This analysis needs at least 5 responses with a numeric score.');
}

// Quick quant summary in PHP so the AI focuses on language patterns.
$values = array_map(fn($r) => (float)$r['numeric_value'], $rows);
sort($values);
$n = count($values);
$mean = array_sum($values) / $n;
$min  = $values[0];
$max  = $values[$n - 1];
$median = $n % 2 === 1 ? $values[(int)floor($n / 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;

// Split rows into thirds by score so the AI can speak about high vs. low.
$lowCut  = $values[(int)floor($n / 3)];
$highCut = $values[(int)floor(2 * $n / 3)];
$buckets = ['low' => [], 'mid' => [], 'high' => []];
foreach ($rows as $r) {
    $v = (float)$r['numeric_value'];
    $txt = (string)$r['text'];
    if (strlen($txt) > 500) $txt = substr($txt, 0, 500) . '...';
    if ($v <= $lowCut)        $buckets['low'][]  = $txt;
    elseif ($v >= $highCut)   $buckets['high'][] = $txt;
    else                       $buckets['mid'][]  = $txt;
}

$prompt  = "Score area: " . $scoreLabel . "\n";
$prompt .= "Numeric summary: n=" . $n . ", mean=" . number_format($mean, 2) . ", median=" . number_format($median, 2) . ", range " . number_format($min, 2) . " to " . number_format($max, 2) . ".\n\n";
foreach (['low', 'mid', 'high'] as $b) {
    $prompt .= "Score-" . $b . " comments (n=" . count($buckets[$b]) . "):\n";
    foreach (array_slice($buckets[$b], 0, 40) as $i => $t) $prompt .= ($i + 1) . '. ' . $t . "\n";
    $prompt .= "\n";
}

$system = <<<SYS
You are a mixed-methods analyst. The user gives you a quantitative summary and three groups of open-ended comments tied to low, middle, and high scores on one measure. Identify what the comments help explain about the score.

Return JSON only, in this shape:

{
  "score_summary": "<one sentence on the numeric story>",
  "themes": [
    {
      "name":           "<short theme name>",
      "appears_in":     "low" | "mid" | "high" | "all",
      "count_estimate": <integer>,
      "example_quotes": ["<verbatim quote>", "<verbatim quote>"],
      "interpretation": "<one sentence>",
      "suggested_action": "<one sentence>"
    }
  ],
  "headline": "<one sentence: what do the comments help explain about the score>"
}

Rules: 2-5 word theme names, never invent quotes, focus on what the comments add to the numeric story. Do not wrap the JSON in markdown fences.
SYS;

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $prompt],
], 3500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['themes']) || !is_array($parsed['themes'])) {
    fail('ai_parse_failed', 'AI did not return a usable response. Try again.', 502);
}

$themes = [];
foreach ($parsed['themes'] as $t) {
    if (!is_array($t)) continue;
    $name = clean_string((string)($t['name'] ?? ''), 200);
    if ($name === '') continue;
    $appears = strtolower(clean_string((string)($t['appears_in'] ?? 'all'), 16));
    if (!in_array($appears, ['low','mid','high','all'], true)) $appears = 'all';
    $examples = [];
    if (isset($t['example_quotes']) && is_array($t['example_quotes'])) {
        foreach ($t['example_quotes'] as $ex) {
            $exClean = clean_string((string)$ex, 600);
            if ($exClean !== '') $examples[] = $exClean;
            if (count($examples) === 3) break;
        }
    }
    $themes[] = [
        'name'             => $name,
        'appears_in'       => $appears,
        'count_estimate'   => (int)($t['count_estimate'] ?? 0),
        'example_quotes'   => $examples,
        'interpretation'   => clean_string((string)($t['interpretation']   ?? ''), 600),
        'suggested_action' => clean_string((string)($t['suggested_action'] ?? ''), 600),
    ];
    if (count($themes) >= 10) break;
}

json_out([
    'ok' => true,
    'score' => [
        'label'  => $scoreLabel,
        'n'      => $n,
        'mean'   => round($mean, 2),
        'median' => round($median, 2),
        'min'    => $min,
        'max'    => $max,
    ],
    'score_summary' => clean_string((string)($parsed['score_summary'] ?? ''), 600),
    'headline'      => clean_string((string)($parsed['headline'] ?? ''), 600),
    'themes'        => $themes,
    'model'         => ai_config()['model'],
]);
