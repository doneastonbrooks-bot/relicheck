<?php
// POST /api/ai/draft-report.php
// Body: { "snapshot": { ... }, "tone": "researcher"|"hr_lead"|"teacher" }
//
// Phase 79. Takes the survey's analytics snapshot (n, k, alpha, omega,
// KMO, factor count, top items, weak items, pass rate where applicable)
// and writes a 2-3 paragraph draft report the user can copy into a
// write-up. Three tones: researcher (methods + results, formal), hr_lead
// (action-oriented summary for leadership), teacher (department-friendly
// language with next steps).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_draft_report:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$tone = (string)($body['tone'] ?? 'researcher');
if (!in_array($tone, ['researcher', 'hr_lead', 'teacher'], true)) $tone = 'researcher';

$n = max(0, (int)($snap['n'] ?? 0));
$k = max(0, (int)($snap['k'] ?? 0));
if ($n < 5 || $k < 2) fail('insufficient_data', 'Need at least 5 respondents and 2 items.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$title    = clean_string((string)($snap['title'] ?? ''), 200);
$alpha    = $normFloat($snap['alpha']    ?? null, -1, 2);
$omega    = $normFloat($snap['omega']    ?? null, -1, 2);
$splitH   = $normFloat($snap['split_half'] ?? null, -1, 2);
$kmo      = $normFloat($snap['kmo']      ?? null, 0, 1);
$nFactors = max(0, (int)($snap['n_factors'] ?? 0));
$cumVar   = $normFloat($snap['cumulative_variance_pct'] ?? null, 0, 100);
$ssi      = is_numeric($snap['ssi_total'] ?? null) ? max(0, min(100, (int)round((float)$snap['ssi_total']))) : null;
$ssiStatus = clean_string((string)($snap['ssi_status'] ?? ''), 40);

$topItems  = [];
if (is_array($snap['top_items'] ?? null)) {
    foreach ($snap['top_items'] as $it) {
        if (!is_string($it)) continue;
        $s = clean_string($it, 200);
        if ($s !== '') $topItems[] = $s;
        if (count($topItems) >= 5) break;
    }
}
$weakItems = [];
if (is_array($snap['weak_items'] ?? null)) {
    foreach ($snap['weak_items'] as $it) {
        if (!is_string($it)) continue;
        $s = clean_string($it, 200);
        if ($s !== '') $weakItems[] = $s;
        if (count($weakItems) >= 5) break;
    }
}

$fmt = function ($v) { return $v === null ? 'NA' : (string)$v; };

$snapshotBlock  = "Title: " . ($title === '' ? '(untitled)' : $title) . "\n";
$snapshotBlock .= "n = " . $n . ", items = " . $k . "\n";
$snapshotBlock .= "Cronbach's alpha = " . $fmt($alpha) . "\n";
$snapshotBlock .= "McDonald's omega = " . $fmt($omega) . "\n";
$snapshotBlock .= "Split-half = " . $fmt($splitH) . "\n";
$snapshotBlock .= "KMO = " . $fmt($kmo) . "\n";
$snapshotBlock .= "Factors retained = " . $nFactors . "\n";
$snapshotBlock .= "Cumulative variance explained = " . ($cumVar === null ? 'NA' : $cumVar . '%') . "\n";
if ($ssi !== null) $snapshotBlock .= "Survey Strength Index = " . $ssi . " / 100 (" . ($ssiStatus ?: 'no label') . ")\n";
if ($topItems)  $snapshotBlock .= "Strongest items:\n  - " . implode("\n  - ", $topItems) . "\n";
if ($weakItems) $snapshotBlock .= "Items needing review:\n  - " . implode("\n  - ", $weakItems) . "\n";

$toneInstruction = [
    'researcher' => 'You are a measurement researcher writing the Methods and Results paragraph of a manuscript. Use third-person, past-tense, formal scholarly voice. Cite numbers precisely. Mention the reliability coefficient(s) by name (Cronbach\'s alpha, omega) and report n and k explicitly. Two paragraphs: one for methods + reliability, one for factor structure + interpretation. Keep each paragraph 4-6 sentences.',
    'hr_lead'    => 'You are writing a leadership-facing summary for a People team. Plain English. Action-oriented. Lead with the headline finding, then the supporting numbers in parentheses. Three short paragraphs: what the survey measured, how strong the data is, what to do next.',
    'teacher'    => 'You are a department lead writing for the principal or a curriculum coordinator. Plain English. Quote the key reliability number in passing rather than as a formal statistic. Recommend a specific action a teacher could take in the next class period. Two short paragraphs: what the results show, what to do next.',
][$tone];

$system = <<<SYS
{$toneInstruction}

Do not invent numbers. If a number is NA in the snapshot, say so or skip it rather than making something up. Round to 2 decimal places where appropriate. Do not use the phrase "p less than 0.05" or "statistically significant" unless the snapshot includes a p-value.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "researcher" | "hr_lead" | "teacher",
  "paragraphs": ["<paragraph 1>", "<paragraph 2>", "<paragraph 3 if applicable>"]
}
SYS;

$userPrompt = "Analytics snapshot:\n\n" . $snapshotBlock . "\n\nWrite the draft report paragraphs in the tone above.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1500);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !is_array($parsed['paragraphs'] ?? null)) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$paragraphs = [];
foreach ($parsed['paragraphs'] as $p) {
    if (!is_string($p)) continue;
    $cl = clean_string($p, 2000);
    if ($cl !== '') $paragraphs[] = $cl;
    if (count($paragraphs) >= 4) break;
}
if (!$paragraphs) fail('ai_empty', 'No draft came back. Try again.', 502);

json_out([
    'ok'         => true,
    'tone'       => $tone,
    'paragraphs' => $paragraphs,
    'model'      => ai_config()['model'],
]);
