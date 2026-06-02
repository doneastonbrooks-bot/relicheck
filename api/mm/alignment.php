<?php
// POST /api/mm/alignment.php
//   { project_id, themes: [ { name, freq_n, freq_pct, sentiment:{positive,negative,neutral,mixed}, analysis:{test,predictor,outcome,p}|null, quote } ] }
//
// Convergence & Divergence assist: for each THEME, compare its quantitative
// signal (how often it appears + any statistical result) against its
// qualitative signal (sentiment + a representative quote) and classify the
// alignment as aligned / divergent / nuanced / insufficient, with a one-sentence
// reading. This is a transient suggestion the researcher reviews and edits — it
// is NOT persisted (the researcher's own per-theme readings are saved separately
// via joint-display save_notes).
//
// (Rewritten: the previous version required per-response numeric scores, which
// MM open-ended responses do not carry, so it always failed here. It now
// consumes the same per-theme joint-display rows the Convergence step shows.)

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

check_rate_limit('mm_alignment:user:' . $uid, 30, 3600);
release_session_lock(); // AI call below — don't hold the session lock.

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

$themesIn = $body['themes'] ?? [];
if (!is_array($themesIn) || count($themesIn) === 0) {
    fail('insufficient_data', 'No themes with evidence to align yet. Build the joint display (themes + quotes) first.');
}

// Build a compact per-theme evidence block for the model.
$fmtSent = function ($s): string {
    if (!is_array($s)) return 'no sentiment data';
    $p = (int)($s['positive'] ?? 0); $n = (int)($s['negative'] ?? 0);
    $u = (int)($s['neutral'] ?? 0) + (int)($s['mixed'] ?? 0);
    if ($p === 0 && $n === 0 && $u === 0) return 'no sentiment data';
    return $p . '% positive, ' . $n . '% negative, ' . $u . '% neutral';
};
$fmtStat = function ($a): string {
    if (!is_array($a) || empty($a['test'])) return '';
    $s = (string)$a['test'];
    $pr = isset($a['predictor']) ? (string)$a['predictor'] : '';
    $oo = isset($a['outcome'])   ? (string)$a['outcome']   : '';
    if ($pr !== '' || $oo !== '') $s .= ' (' . $pr . ($pr !== '' && $oo !== '' ? ' → ' : '') . $oo . ')';
    if (isset($a['p']) && $a['p'] !== '' && $a['p'] !== null) $s .= ', p=' . (string)$a['p'];
    return $s;
};

$lines = [];
$names = [];
foreach (array_slice($themesIn, 0, 30) as $i => $t) {
    if (!is_array($t)) continue;
    $name = clean_string((string)($t['name'] ?? ''), 200);
    if ($name === '') continue;
    $names[] = $name;
    $fn   = (int)($t['freq_n'] ?? 0);
    $fp   = (float)($t['freq_pct'] ?? 0);
    $stat = $fmtStat($t['analysis'] ?? null);
    $sent = $fmtSent($t['sentiment'] ?? null);
    $q    = clean_string((string)($t['quote'] ?? ''), 320);
    $line  = (count($lines) + 1) . '. Theme: ' . $name . "\n";
    $line .= '   Quantitative: appears in ' . $fn . ' responses (' . number_format($fp, 1) . '%)' . ($stat !== '' ? '; ' . $stat : '') . "\n";
    $line .= '   Qualitative: sentiment ' . $sent . ($q !== '' ? '; example quote: "' . $q . '"' : '');
    $lines[] = $line;
}
if (count($lines) === 0) fail('insufficient_data', 'No themes with usable evidence to align.');

$prompt = "Themes with their quantitative and qualitative evidence:\n\n" . implode("\n\n", $lines)
    . "\n\nFor EACH theme, judge whether the two strands align.";

$system = <<<SYS
You are a mixed-methods analyst doing a convergence check. For each theme you are given its quantitative signal (how often it appears, plus any statistical result) and its qualitative signal (sentiment balance and an example quote). Classify how the two strands relate:
- "aligned": the numbers and the narrative point the same way (e.g. frequent + negative tone + a test showing lower scores).
- "divergent": they point in opposite or conflicting directions (e.g. rare in the numbers but strongly felt in the quotes, or positive tone against a negative result).
- "nuanced": they add to each other or only partly agree.
- "insufficient": not enough evidence on one side to judge.

Return JSON only, no markdown fences:

{
  "findings": [
    { "quant_label": "<the theme name, verbatim>", "alignment": "aligned|divergent|nuanced|insufficient", "confidence": "high|moderate|low", "interpretation": "<one sentence on how the strands relate for this theme>", "next_step": "<one short suggestion>" }
  ],
  "summary": "<one sentence across all themes>"
}

One finding per theme given, in the same order. Use the theme name exactly as provided in "quant_label".
SYS;

$resp   = ai_complete($system, [['role' => 'user', 'content' => $prompt]], 3000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['findings']) || !is_array($parsed['findings'])) {
    fail('ai_parse_failed', 'ReliCheck Intelligence did not return a usable response. Try again.', 502);
}

$findings = [];
foreach ($parsed['findings'] as $f) {
    if (!is_array($f)) continue;
    $align = strtolower(clean_string((string)($f['alignment'] ?? 'nuanced'), 16));
    if (!in_array($align, ['aligned', 'divergent', 'nuanced', 'insufficient'], true)) $align = 'nuanced';
    $conf = strtolower(clean_string((string)($f['confidence'] ?? 'moderate'), 16));
    if (!in_array($conf, ['high', 'moderate', 'low'], true)) $conf = 'moderate';
    $label = clean_string((string)($f['quant_label'] ?? ''), 200);
    if ($label === '') continue;
    $findings[] = [
        'quant_label'    => $label,
        'alignment'      => $align,
        'confidence'     => $conf,
        'interpretation' => clean_string((string)($f['interpretation'] ?? ''), 1200),
        'next_step'      => clean_string((string)($f['next_step'] ?? ''), 1200),
    ];
}

json_out([
    'ok'       => true,
    'findings' => $findings,
    'summary'  => clean_string((string)($parsed['summary'] ?? ''), 600),
    'model'    => ai_config()['model'],
]);
