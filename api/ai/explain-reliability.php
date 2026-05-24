<?php
// POST /api/ai/explain-reliability.php
// Body: {
//   "snapshot": {
//     "alpha":      <float|null>,
//     "split_half": <float|null>,
//     "kmo":        <float|null>,
//     "n":          <int>,
//     "k":          <int>,
//     "dropped_missing": <int>,
//     "items": [
//       { "idx": <int>, "prompt": <string, truncated client-side>,
//         "itc":   <float|null>,
//         "aid":   <float|null>,
//         "reverse": <bool> },
//       ...
//     ]
//   }
// }
//
// AI Reliability Explainer (Phase 53). The client sends the same numbers
// it already displays on the Reliability tab; the model returns a
// plain-language paragraph plus per-item callouts that reference items by
// the (truncated) prompt text rather than by Q-number.
//
// Output: {
//   ok, severity, severity_label, headline, paragraph,
//   item_callouts [{ idx, prompt, severity, label, detail }],
//   recommendation, model
// }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

check_rate_limit('ai_explain_reliability:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$normFloat = function ($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, 3);
};

$alpha     = $normFloat($snap['alpha']      ?? null, -1.0, 1.0);
$splitHalf = $normFloat($snap['split_half'] ?? null, -1.0, 1.0);
$kmo       = $normFloat($snap['kmo']        ?? null,  0.0, 1.0);
$n         = max(0, (int)($snap['n'] ?? 0));
$k         = max(0, (int)($snap['k'] ?? 0));
$dropped   = max(0, (int)($snap['dropped_missing'] ?? 0));

if ($n < 2 || $k < 2) {
    fail('insufficient_data', 'Reliability statistics need at least two complete Likert responses and two Likert items.');
}

$itemsIn = is_array($snap['items'] ?? null) ? $snap['items'] : [];
$items = [];
foreach ($itemsIn as $i => $row) {
    if (!is_array($row)) continue;
    $idx = (int)($row['idx'] ?? $i);
    $prompt = clean_string((string)($row['prompt'] ?? ''), 80);
    if ($prompt === '') $prompt = 'Item ' . ($idx + 1);
    $itc = $normFloat($row['itc'] ?? null, -1.0, 1.0);
    $aid = $normFloat($row['aid'] ?? null, -1.0, 1.0);
    $items[] = [
        'idx'     => $idx,
        'prompt'  => $prompt,
        'itc'     => $itc,
        'aid'     => $aid,
        'reverse' => !empty($row['reverse']),
    ];
    if (count($items) >= 40) break;
}

if (count($items) === 0) {
    fail('no_items', 'No Likert items in the snapshot to explain.');
}

// Build the snapshot block. We give the model the actual numbers so it
// can quote them in the paragraph rather than inventing.
$alphaTxt     = $alpha     === null ? 'not computable' : (string)$alpha;
$splitHalfTxt = $splitHalf === null ? 'not computable' : (string)$splitHalf;
$kmoTxt       = $kmo       === null ? 'not computable' : (string)$kmo;

$itemLines = [];
foreach ($items as $it) {
    $itc = $it['itc'] === null ? 'n/a' : (string)$it['itc'];
    $aid = $it['aid'] === null ? 'n/a' : (string)$it['aid'];
    $rev = $it['reverse'] ? ' [reverse-coded]' : '';
    $itemLines[] = sprintf(
        '  %d. "%s" - item-total r = %s, alpha-if-deleted = %s%s',
        $it['idx'] + 1,
        $it['prompt'],
        $itc,
        $aid,
        $rev
    );
}
$itemsBlock = implode("\n", $itemLines);

$snapshotBlock  = "Scale-level statistics:\n";
$snapshotBlock .= "  - Cronbach's alpha: " . $alphaTxt . "\n";
$snapshotBlock .= "  - Split-half (Spearman-Brown): " . $splitHalfTxt . "\n";
$snapshotBlock .= "  - KMO sampling adequacy: " . $kmoTxt . "\n";
$snapshotBlock .= "  - Complete Likert responses: " . $n . "\n";
$snapshotBlock .= "  - Likert items: " . $k . "\n";
$snapshotBlock .= "  - Dropped (incomplete Likert rows): " . $dropped . "\n\n";
$snapshotBlock .= "Per-item statistics:\n" . $itemsBlock;

$system = <<<SYS
You are a measurement researcher producing a plain-language reliability explainer for a survey owner who is not a statistician. You receive the scale's reliability statistics and per-item numbers (item-total correlation = ITC, alpha-if-deleted = AID). You translate them into one paragraph and a short list of per-item callouts.

Vocabulary the user sees on the same screen, so match it:
  - Alpha thresholds: >= 0.90 excellent; 0.80-0.89 good; 0.70-0.79 acceptable; 0.60-0.69 questionable; 0.50-0.59 poor; < 0.50 unacceptable.
  - ITC bands: >= 0.50 strong (healthy); 0.30-0.49 moderate (review); < 0.30 weak.
  - Alpha-if-deleted: flag an item when AID exceeds the current alpha by at least 0.01 - removing it would raise the scale's reliability.

Severity tiers (use the worst-case across the inputs):
  - "strong"   - alpha >= 0.80 AND no items below 0.30 ITC AND no items where AID would meaningfully raise alpha.
  - "solid"    - alpha 0.70-0.79, or alpha >= 0.80 with one item slightly under 0.30 ITC, or one item with AID > alpha.
  - "review"   - alpha 0.60-0.69, OR multiple items below 0.30 ITC, OR a substantial AID gain on a single item.
  - "weak"     - alpha < 0.60, OR most items below 0.30 ITC, OR multiple AID gains.

Paragraph guidance (one paragraph, 2-4 sentences):
  - Lead with the alpha interpretation in plain language ("acceptable internal consistency", "good internal consistency").
  - State whether the items are generally working together.
  - If there is a clear weakest item, name it by its prompt text (use the exact prompt from the snapshot, quoted) and say why it's weak.
  - If alpha-if-deleted would meaningfully raise the score, say so and name the item.
  - Avoid: numeric jargon ("AID = 0.81"), greek letters, percent signs, hedging ("might possibly").

Item callouts (return 0-3 entries, ordered worst-first; omit if everything is healthy):
  - Each entry references one item by its prompt text (use exactly the prompt from the snapshot).
  - Each callout has: idx (the item index from the snapshot), prompt (the prompt text echoed back), severity (use the same vocabulary as the overall tier), label (3-6 word headline e.g. "Weakens the scale", "Consider revising", "Drop or rewrite"), detail (one short sentence with the actionable suggestion).
  - Do not invent items that are not in the snapshot.

Recommendation (one sentence):
  - "strong": confirm the scale is ready for use as-is.
  - "solid": suggest a light review of the one or two flagged items before publishing.
  - "review": suggest revising or dropping the weak items and recomputing.
  - "weak": suggest a substantive rewrite of the weakest items before relying on the numbers.

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "severity": "strong" | "solid" | "review" | "weak",
  "severity_label": "<short phrase, e.g. 'Solid scale'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences, plain prose>",
  "item_callouts": [
    {
      "idx": <int>,
      "prompt": "<echoed prompt>",
      "severity": "strong" | "solid" | "review" | "weak",
      "label": "<3-6 word headline>",
      "detail": "<one short sentence>"
    }
  ],
  "recommendation": "<one sentence>"
}
SYS;

$userPrompt  = "Reliability snapshot:\n\n" . $snapshotBlock . "\n\n";
$userPrompt .= "Produce the reliability explainer JSON now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 1100);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['severity'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validSeverities = ['strong', 'solid', 'review', 'weak'];
$defaultLabels = [
    'strong' => 'Strong scale',
    'solid'  => 'Solid scale',
    'review' => 'Needs review',
    'weak'   => 'Weak scale',
];

$severity = (string)($parsed['severity'] ?? 'solid');
if (!in_array($severity, $validSeverities, true)) $severity = 'solid';

$severityLabel = clean_string((string)($parsed['severity_label'] ?? $defaultLabels[$severity]), 48);
if ($severityLabel === '') $severityLabel = $defaultLabels[$severity];

$headline = clean_string((string)($parsed['headline'] ?? ''), 220);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 800);
$recommendation = clean_string((string)($parsed['recommendation'] ?? ''), 280);

$validIdxs = array_column($items, 'idx');
$itemCallouts = [];
if (is_array($parsed['item_callouts'] ?? null)) {
    foreach ($parsed['item_callouts'] as $c) {
        if (!is_array($c)) continue;
        $idx = (int)($c['idx'] ?? -1);
        if (!in_array($idx, $validIdxs, true)) continue;
        $sev = (string)($c['severity'] ?? $severity);
        if (!in_array($sev, $validSeverities, true)) $sev = $severity;
        $prompt = clean_string((string)($c['prompt'] ?? ''), 80);
        $label  = clean_string((string)($c['label']  ?? ''), 60);
        $detail = clean_string((string)($c['detail'] ?? ''), 240);
        if ($label === '' || $detail === '') continue;
        $itemCallouts[] = [
            'idx'      => $idx,
            'prompt'   => $prompt,
            'severity' => $sev,
            'label'    => $label,
            'detail'   => $detail,
        ];
        if (count($itemCallouts) >= 3) break;
    }
}

json_out([
    'ok'             => true,
    'severity'       => $severity,
    'severity_label' => $severityLabel,
    'headline'       => $headline,
    'paragraph'      => $paragraph,
    'item_callouts'  => $itemCallouts,
    'recommendation' => $recommendation,
    'model'          => ai_config()['model'],
]);
