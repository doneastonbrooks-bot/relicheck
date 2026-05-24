<?php
// POST /api/ai/narrate-trends.php
// Body: {
//   "snapshot": {
//     "wave_source": "channel" | "quarter",
//     "wave_count":  <int>,
//     "trend_score": <int 0-100>,
//     "waves":       [{ label:<str>, n:<int>, composite_mean:<float|null>, alpha:<float|null> }],
//     "delta_test":  { delta:<float|null>, t:<float|null>, df:<float|null>, p:<float|null>, significant:<bool> },
//     "construct_trends": [{ name:<str>, first:<float|null>, last:<float|null> }]
//   }
// }
//
// Phase 142 Trends narrator. Same I/O shape as the other narrate-*.php
// endpoints (tone / tone_label / headline / paragraph / highlights /
// affected_items). Reads the wave-over-wave snapshot and emits a
// plain-language read of the direction (up, steady, down), names the
// most-moved construct, and calls out significance.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_trends:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$clampPct = function ($v): int {
    $n = (int)$v;
    if ($n < 0)   $n = 0;
    if ($n > 100) $n = 100;
    return $n;
};
$numOrNull = function ($v) {
    if ($v === null) return null;
    if (!is_numeric($v)) return null;
    return round((float)$v, 3);
};

$waveSource = (string)($snap['wave_source'] ?? 'none');
if (!in_array($waveSource, ['channel', 'quarter', 'none'], true)) $waveSource = 'none';
$waveCount  = max(0, (int)($snap['wave_count']  ?? 0));
$trendScore = $clampPct($snap['trend_score']    ?? 50);

if ($waveCount < 2) {
    fail('insufficient_waves', 'Trend analysis needs at least two waves of responses.');
}

$wavesIn = is_array($snap['waves'] ?? null) ? $snap['waves'] : [];
$waves = [];
foreach ($wavesIn as $w) {
    if (!is_array($w)) continue;
    $label = clean_string((string)($w['label'] ?? ''), 32);
    if ($label === '') continue;
    $waves[] = [
        'label'          => $label,
        'n'              => max(0, (int)($w['n'] ?? 0)),
        'composite_mean' => $numOrNull($w['composite_mean'] ?? null),
        'alpha'          => $numOrNull($w['alpha'] ?? null),
    ];
    if (count($waves) >= 24) break;
}

$dtIn = is_array($snap['delta_test'] ?? null) ? $snap['delta_test'] : [];
$deltaTest = [
    'delta'       => $numOrNull($dtIn['delta'] ?? null),
    't'           => $numOrNull($dtIn['t']     ?? null),
    'df'          => $numOrNull($dtIn['df']    ?? null),
    'p'           => $numOrNull($dtIn['p']     ?? null),
    'significant' => !empty($dtIn['significant']),
];

$ctIn = is_array($snap['construct_trends'] ?? null) ? $snap['construct_trends'] : [];
$constructTrends = [];
foreach ($ctIn as $c) {
    if (!is_array($c)) continue;
    $name = clean_string((string)($c['name'] ?? ''), 60);
    if ($name === '') continue;
    $first = $numOrNull($c['first'] ?? null);
    $last  = $numOrNull($c['last']  ?? null);
    $deltaC = ($first !== null && $last !== null) ? round($last - $first, 3) : null;
    $constructTrends[] = ['name' => $name, 'first' => $first, 'last' => $last, 'delta' => $deltaC];
    if (count($constructTrends) >= 8) break;
}

$lines = [];
$lines[] = "Trend snapshot:";
$lines[] = "  - Trend Score (0-100, 50 = steady): " . $trendScore;
$lines[] = "  - Wave detection source: " . $waveSource;
$lines[] = "  - Wave count: " . $waveCount;
$lines[] = "";
$lines[] = "Per-wave composite means:";
foreach ($waves as $w) {
    $cm = $w['composite_mean'] === null ? 'n/a' : (string)$w['composite_mean'];
    $al = $w['alpha']          === null ? 'n/a' : (string)$w['alpha'];
    $lines[] = "  - " . $w['label'] . " (n=" . $w['n'] . "): mean=" . $cm . ", alpha=" . $al;
}
$lines[] = "";
$lines[] = "Current vs previous wave delta:";
$lines[] = "  - Delta: "       . ($deltaTest['delta'] === null ? 'n/a' : (string)$deltaTest['delta']);
$lines[] = "  - Welch t: "     . ($deltaTest['t']     === null ? 'n/a' : (string)$deltaTest['t']);
$lines[] = "  - Approx df: "   . ($deltaTest['df']    === null ? 'n/a' : (string)$deltaTest['df']);
$lines[] = "  - Two-tailed p: " . ($deltaTest['p']    === null ? 'n/a' : (string)$deltaTest['p']);
$lines[] = "  - Significant at p<.05: " . ($deltaTest['significant'] ? 'yes' : 'no');
if (count($constructTrends)) {
    $lines[] = "";
    $lines[] = "Construct trends (first wave to last):";
    foreach ($constructTrends as $c) {
        $f = $c['first'] === null ? 'n/a' : (string)$c['first'];
        $l = $c['last']  === null ? 'n/a' : (string)$c['last'];
        $d = $c['delta'] === null ? 'n/a' : (string)$c['delta'];
        $lines[] = "  - \"" . $c['name'] . "\": " . $f . " -> " . $l . " (delta " . $d . ")";
    }
}

$snapshotBlock = implode("\n", $lines);

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card pinned to the top of the Trends analytics tab. The audience is the survey owner (HR partner, evaluator, researcher) tracking whether scores are moving wave-over-wave. The user is not a statistician.

Tone tiers for the visual pill (use the dominant signal):
  - "good" : Clear improvement. Composite delta >= +0.20 on the Likert scale OR Trend Score >= 65, AND no construct dropping meaningfully.
  - "ok"   : Steady. Composite delta within plus or minus 0.15 OR Trend Score 45-64. Most analyses are still fine; flag any single construct moving against the rest.
  - "warn" : Notable slip. Composite delta -0.16 to -0.40 OR Trend Score 30-44 OR a single construct dropping by 0.30+.
  - "bad"  : Substantial slip. Composite delta worse than -0.40 OR Trend Score below 30 OR multiple constructs dropping.

Voice:
  - Lead with the practical answer ("Engagement is trending up across the last four waves", "The composite is essentially flat wave over wave", "Engagement is slipping; the latest wave dropped 0.32 from the prior wave").
  - When a construct trend stands out (moving against the composite, or moving most among constructs), name it by its actual label and quote the first-wave-to-last-wave change.
  - Reference the significance flag in plain language when present: "the drop is statistically significant" / "the change is within sampling noise" / "the sample is too small to call this significant yet".
  - 3 to 5 sentences. Plain prose. Avoid the words "t-statistic", "p-value", "Welch", "delta" in the narration (use "drop", "change", "significant").

Highlights (0 to 3): short items naming specific findings.
  - Each has: label (3-6 words), detail (one short sentence with numbers).
  - One should call out the wave-over-wave composite move.
  - One can name the most-moved construct by its actual label.
  - One can call out the participation change (latest wave responses vs previous wave).

Headline:
  - One sentence summarizing the direction.

Affected items: empty array for now.

Output format: single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Trending up'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 3-5 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence with numbers>" }
  ],
  "affected_items": []
}
SYS;

$userPrompt = "Trend snapshot:\n\n" . $snapshotBlock . "\n\nProduce the trend narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 900);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Trending up',
    'ok'   => 'Steady',
    'warn' => 'Slipping to watch',
    'bad'  => 'Trending down',
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
