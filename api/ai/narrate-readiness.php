<?php
// POST /api/ai/narrate-readiness.php
// Body: {
//   "snapshot": {
//     "score":        <int 0-100>,
//     "status":       "ready" | "almost" | "needs_work" | "not_ready",
//     "status_label": <str>,
//     "domains": {
//       "items_per_construct": { "points":<int>, "max":<int> },
//       "construct_coverage":  { "points":<int>, "max":<int> },
//       "grouping_vars":       { "points":<int>, "max":<int> },
//       "purpose":             { "points":<int>, "max":<int> },
//       "length":              { "points":<int>, "max":<int> },
//       "reverse_balance":     { "points":<int>, "max":<int> }
//     },
//     "issues": [{ "severity":<str>, "title":<str>, "detail":<str> }],
//     "meta": {
//       "total_items":<int>, "likert_count":<int>, "open_count":<int>,
//       "construct_count":<int>, "grouping_var_count":<int>,
//       "purpose_set":<bool>, "purpose_length":<int>
//     }
//   }
// }
//
// Phase 144 Survey Readiness narrator. Same I/O shape as the other
// narrate-*.php endpoints (tone / tone_label / headline / paragraph /
// highlights / affected_items). Reads the survey-design snapshot and
// emits a plain-language read of whether the survey is ready to send,
// names the most pressing fix, and ties the score back to the six
// design checks.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_readiness:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};

$score  = $clampPct($snap['score'] ?? 0);
$status = (string)($snap['status'] ?? 'needs_work');
$validStatus = ['ready', 'almost', 'needs_work', 'not_ready'];
if (!in_array($status, $validStatus, true)) $status = 'needs_work';
$statusLabel = clean_string((string)($snap['status_label'] ?? ''), 32);

$domainsIn = is_array($snap['domains'] ?? null) ? $snap['domains'] : [];
$domainKeys = ['items_per_construct', 'construct_coverage', 'grouping_vars', 'purpose', 'length', 'reverse_balance'];
$domains = [];
foreach ($domainKeys as $k) {
    $d = is_array($domainsIn[$k] ?? null) ? $domainsIn[$k] : [];
    $domains[$k] = [
        'points' => max(0, (int)($d['points'] ?? 0)),
        'max'    => max(1, (int)($d['max']    ?? 1)),
    ];
}

$issuesIn = is_array($snap['issues'] ?? null) ? $snap['issues'] : [];
$issues = [];
$validSeverity = ['blocker', 'warning', 'nudge'];
foreach ($issuesIn as $it) {
    if (!is_array($it)) continue;
    $sev    = (string)($it['severity'] ?? '');
    if (!in_array($sev, $validSeverity, true)) continue;
    $title  = clean_string((string)($it['title']  ?? ''), 80);
    $detail = clean_string((string)($it['detail'] ?? ''), 240);
    if ($title === '' || $detail === '') continue;
    $issues[] = ['severity' => $sev, 'title' => $title, 'detail' => $detail];
    if (count($issues) >= 8) break;
}

$metaIn = is_array($snap['meta'] ?? null) ? $snap['meta'] : [];
$meta = [
    'total_items'        => max(0, (int)($metaIn['total_items']        ?? 0)),
    'likert_count'       => max(0, (int)($metaIn['likert_count']       ?? 0)),
    'open_count'         => max(0, (int)($metaIn['open_count']         ?? 0)),
    'construct_count'    => max(0, (int)($metaIn['construct_count']    ?? 0)),
    'grouping_var_count' => max(0, (int)($metaIn['grouping_var_count'] ?? 0)),
    'purpose_set'        => !empty($metaIn['purpose_set']),
    'purpose_length'     => max(0, (int)($metaIn['purpose_length']     ?? 0)),
];

$blockerCount = 0; $warnCount = 0; $nudgeCount = 0;
foreach ($issues as $it) {
    if      ($it['severity'] === 'blocker') $blockerCount++;
    else if ($it['severity'] === 'warning') $warnCount++;
    else if ($it['severity'] === 'nudge')   $nudgeCount++;
}

$lines = [];
$lines[] = "Readiness snapshot:";
$lines[] = "  - Readiness Score (0-100): " . $score;
$lines[] = "  - Status: " . $status . " (" . ($statusLabel ?: 'no label') . ")";
$lines[] = "  - Issue counts: blockers=" . $blockerCount . ", warnings=" . $warnCount . ", nudges=" . $nudgeCount;
$lines[] = "";
$lines[] = "Survey design:";
$lines[] = "  - Total items: " . $meta['total_items'] . " (" . $meta['likert_count'] . " Likert, " . $meta['open_count'] . " open)";
$lines[] = "  - Constructs tagged: " . $meta['construct_count'];
$lines[] = "  - Grouping variables: " . $meta['grouping_var_count'];
$lines[] = "  - Purpose: " . ($meta['purpose_set'] ? ($meta['purpose_length'] . ' chars') : 'not set');
$lines[] = "";
$lines[] = "Domain points (out of max):";
$lines[] = "  - Items per construct:    " . $domains['items_per_construct']['points'] . "/" . $domains['items_per_construct']['max'];
$lines[] = "  - Construct coverage:     " . $domains['construct_coverage']['points']  . "/" . $domains['construct_coverage']['max'];
$lines[] = "  - Grouping variables:     " . $domains['grouping_vars']['points']       . "/" . $domains['grouping_vars']['max'];
$lines[] = "  - Survey purpose:         " . $domains['purpose']['points']             . "/" . $domains['purpose']['max'];
$lines[] = "  - Length sanity:          " . $domains['length']['points']              . "/" . $domains['length']['max'];
$lines[] = "  - Reverse-scoring balance: " . $domains['reverse_balance']['points']    . "/" . $domains['reverse_balance']['max'];
if (count($issues)) {
    $lines[] = "";
    $lines[] = "Top issues (severity, title, detail):";
    foreach ($issues as $it) {
        $lines[] = "  - [" . $it['severity'] . "] " . $it['title'] . ": " . $it['detail'];
    }
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card pinned to the top of the Survey Readiness analytics tab. The audience is the survey owner (HR partner, evaluator, researcher) about to send this survey out, or already collecting responses. The user is not a statistician.

The Readiness Score grades the survey design itself (not the response data). Six weighted domains: items per construct (25), construct coverage (20), grouping variables (15), survey purpose (15), length sanity (15), reverse-scoring balance (10).

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Score 85+, no blockers. Survey is solid; minor nudges allowed.
  - "ok"   : Score 70-84, no blockers. Almost ready; a couple of warnings worth addressing.
  - "warn" : Score 50-69 OR exactly one blocker. Workable but real gaps.
  - "bad"  : Score below 50 OR two or more blockers. Not ready to send yet.

Voice:
  - Lead with the practical answer ("This survey is ready to send", "Almost ready, with two small fixes", "Workable but the design has a few real gaps", "Not ready yet, two blockers need attention before this goes out").
  - Name the single most pressing fix by its issue title or domain (use phrases like "purpose statement", "construct tags", "grouping variable", "reverse-scored items"). Reference what unlocks if it is fixed (omega, Compare, per-construct reliability, etc.).
  - When the score is 85+, name what the survey does well and skip the fix-first framing.
  - 3 to 5 sentences. Plain prose. Do not list every issue; focus on what most moves the score.

Highlights (0 to 3): short items naming specific findings.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - One should call out the dominant issue or strength (the highest-impact lever).
  - One can name a domain that is fully scored (positive signal) or one with the largest gap to its max (the biggest opportunity).
  - One can flag a coverage stat (construct count, grouping var count, item count) that grounds the read.

Headline:
  - One sentence summarizing whether the survey is ready and the single most important next step.

Affected items: empty array for now.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Ready to send'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 3-5 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": []
}
SYS;

$userPrompt = "Readiness snapshot:\n\n" . $snapshotBlock . "\n\nProduce the readiness narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Ready to send',
    'ok'   => 'Almost ready',
    'warn' => 'Needs work',
    'bad'  => 'Not ready',
];
$toneLabel = clean_string((string)($parsed['tone_label'] ?? $defaultLabels[$tone]), 48);
if ($toneLabel === '') $toneLabel = $defaultLabels[$tone];

$headline  = clean_string((string)($parsed['headline']  ?? ''), 220);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 900);

$highlights = [];
if (is_array($parsed['highlights'] ?? null)) {
    foreach ($parsed['highlights'] as $h) {
        if (!is_array($h)) continue;
        $label  = clean_string((string)($h['label']  ?? ''), 60);
        $detail = clean_string((string)($h['detail'] ?? ''), 240);
        if ($label === '' || $detail === '') continue;
        $highlights[] = ['label' => $label, 'detail' => $detail];
        if (count($highlights) >= 3) break;
    }
}

json_out([
    'ok'             => true,
    'tone'           => $tone,
    'tone_label'     => $toneLabel,
    'headline'       => $headline,
    'paragraph'      => $paragraph,
    'highlights'     => $highlights,
    'affected_items' => [],
    'model'          => ai_config()['model'],
]);
