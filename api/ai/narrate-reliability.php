<?php
// POST /api/ai/narrate-reliability.php
// Body: {
//   "snapshot": {
//     "alpha":      <float|null>,
//     "omega":      <float|null>,
//     "split_half": <float|null>,
//     "kmo":        <float|null>,
//     "n":          <int>,
//     "k":          <int>,
//     "dropped_missing": <int>,
//     "items": [
//       { "idx": <int>, "prompt": <string>,
//         "itc": <float|null>, "aid": <float|null>,
//         "reverse": <bool> }
//     ]
//   }
// }
//
// Dashboard Narrator for the Reliability tab (Phase 55 refactor, omega added Phase 60).
// Same I/O shape as the other narrate-*.php endpoints (tone / tone_label /
// headline / paragraph / highlights) so the client renders all four
// analytics-tab narrators through the same shared helper.
//
// Phase 53's explain-reliability.php remains on disk as a reference but
// is no longer referenced by the client.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_reliability:user:' . (int)$user['id'], 20, 3600);

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
$omega     = $normFloat($snap['omega']      ?? null, -1.0, 1.0);
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
    fail('no_items', 'No Likert items in the snapshot to narrate.');
}

$alphaTxt     = $alpha     === null ? 'not computable' : (string)$alpha;
$omegaTxt     = $omega     === null ? 'not computable' : (string)$omega;
$splitHalfTxt = $splitHalf === null ? 'not computable' : (string)$splitHalf;
$kmoTxt       = $kmo       === null ? 'not computable' : (string)$kmo;

// Gap diagnostic: when alpha and omega diverge meaningfully, the scale is
// not tau-equivalent. omega > alpha by 0.03 or more usually means alpha is
// underestimating reliability (items contribute unequally). omega < alpha
// by 0.03 or more is unusual and worth flagging.
$gapTxt = 'not applicable';
if ($alpha !== null && $omega !== null) {
    $gap = round($omega - $alpha, 3);
    if (abs($gap) < 0.03) {
        $gapTxt = sprintf('%+0.3f, close agreement (tau-equivalent assumption holds)', $gap);
    } elseif ($gap > 0) {
        $gapTxt = sprintf('%+0.3f, omega higher than alpha (items contribute unequally; alpha is biased low)', $gap);
    } else {
        $gapTxt = sprintf('%+0.3f, omega lower than alpha (unusual; investigate scale structure)', $gap);
    }
}

$itemLines = [];
foreach ($items as $it) {
    $itc = $it['itc'] === null ? 'n/a' : (string)$it['itc'];
    $aid = $it['aid'] === null ? 'n/a' : (string)$it['aid'];
    $rev = $it['reverse'] ? ' [reverse-coded]' : '';
    $itemLines[] = sprintf(
        '  %d. "%s", item-total r = %s, alpha-if-deleted = %s%s',
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
$snapshotBlock .= "  - McDonald's omega: " . $omegaTxt . "\n";
$snapshotBlock .= "  - omega - alpha gap: " . $gapTxt . "\n";
$snapshotBlock .= "  - Split-half (Spearman-Brown): " . $splitHalfTxt . "\n";
$snapshotBlock .= "  - KMO sampling adequacy: " . $kmoTxt . "\n";
$snapshotBlock .= "  - Complete Likert responses: " . $n . "\n";
$snapshotBlock .= "  - Likert items: " . $k . "\n";
$snapshotBlock .= "  - Dropped (incomplete Likert rows): " . $dropped . "\n\n";
$snapshotBlock .= "Per-item statistics:\n" . $itemsBlock;

$system = <<<SYS
You are a measurement researcher producing a one-paragraph narration card for the Reliability tab of a survey app. The user is not a statistician. You explain how the scale is holding together and which items are pulling their weight.

Vocabulary the user sees on the same screen, so match it:
  - Alpha thresholds: >= 0.90 excellent; 0.80-0.89 good; 0.70-0.79 acceptable; 0.60-0.69 questionable; 0.50-0.59 poor; < 0.50 unacceptable. Same tiers apply to omega.
  - ITC bands: >= 0.50 strong (healthy); 0.30-0.49 moderate (review); < 0.30 weak.
  - Alpha-if-deleted: flag an item when AID exceeds the current alpha by at least 0.01, removing it would raise the scale's reliability.

Alpha and omega together:
  - Cronbach's alpha and McDonald's omega both estimate internal consistency. Alpha assumes every item contributes equally (tau-equivalence). Omega relaxes that assumption and is the modern preferred estimate when items differ in how strongly they relate to the underlying construct.
  - When the omega-alpha gap is within +/- 0.03 the assumption roughly holds and the two numbers can be treated as agreeing.
  - When omega is meaningfully higher than alpha (gap >= 0.03), the items contribute unequally and alpha is biased low. Lead with omega in that case; mention that alpha under-estimates the true reliability.
  - When omega is meaningfully lower than alpha (gap <= -0.03), that is unusual and usually points to a scale structure issue. Flag it.
  - When omega is not computable (k < 3 or singular matrix), proceed with alpha alone and say so.

Tone tiers for the visual pill (use the BETTER of alpha and omega when both are computed, since omega is the methodologically preferred estimate):
  - "good" : best of alpha/omega >= 0.80 AND no items below 0.30 ITC AND no items where AID would meaningfully raise alpha.
  - "ok"   : best of alpha/omega 0.70-0.79, or >= 0.80 with one item slightly under 0.30 ITC, or one item with AID > alpha.
  - "warn" : best of alpha/omega 0.60-0.69, OR multiple items below 0.30 ITC, OR a substantial AID gain on a single item.
  - "bad"  : best of alpha/omega < 0.60, OR most items below 0.30 ITC, OR multiple AID gains.

Voice:
  - Lead with the plain-language interpretation ("acceptable internal consistency", "good internal consistency"). When both alpha and omega are computed, quote the omega value if it differs from alpha by 0.03 or more, otherwise speak as if the two agree.
  - State whether the items are generally working together.
  - If the omega-alpha gap is meaningful (>= 0.03 in absolute value), mention it in one sentence in plain language: "Items contribute unequally, so the modern estimate (omega) is the more accurate read" or similar. Do not lecture.
  - If there is a clear weakest item, name it inside the paragraph by quoting its prompt (use the exact prompt from the snapshot).
  - If alpha-if-deleted would meaningfully raise the score, mention which item.
  - Avoid jargon: do not say "ITC", "AID", "tau-equivalence", percentage signs, or greek letters. You may say "Cronbach's alpha" and "McDonald's omega" because those terms appear in the dashboard's own labels.
  - 2-4 sentences. Plain prose. No bullets inside the paragraph.

Highlights (0-3): short items that surface the specific actions the user should consider.
  - Each has: label (3-6 words, e.g. "Weakens the scale", "Consider revising", "Drop or rewrite"), detail (one short sentence that quotes the item prompt and gives the actionable suggestion).
  - Worst items first.
  - Do not invent items that are not in the snapshot.
  - When the scale is healthy, omit highlights entirely or include one positive highlight ("Items hold together well").
  - If the alpha-omega gap is meaningful, you may include one highlight noting that omega is the more accurate estimate.

Headline:
  - One sentence that summarizes the reliability picture in plain language.

Affected items (Phase 93):
  - When the paragraph or any highlight names specific items by prompt text or by item number, also list them in an affected_items array.
  - Each entry has shape { "type": "item", "id": "<0-based index as string>" }.
  - The id must match the idx field shown in the per-item snapshot block (so "Item 4" in the snapshot becomes id "3" because idx starts at 0).
  - Empty array is fine when the narration does not call out specific items.

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label, e.g. 'Solid scale'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, 2-4 sentences>",
  "highlights": [
    { "label": "<3-6 word label>", "detail": "<one short sentence quoting the item prompt where relevant>" }
  ],
  "affected_items": [
    { "type": "item", "id": "<0-based index of an item called out above>" }
  ]
}
SYS;

$userPrompt = "Reliability snapshot:\n\n" . $snapshotBlock . "\n\nProduce the reliability narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1100);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = ['good' => 'Strong scale', 'ok' => 'Solid scale', 'warn' => 'Needs review', 'bad' => 'Weak scale'];
$toneLabel = clean_string((string)($parsed['tone_label'] ?? $defaultLabels[$tone]), 48);
if ($toneLabel === '') $toneLabel = $defaultLabels[$tone];

$headline  = clean_string((string)($parsed['headline']  ?? ''), 220);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 800);

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

// Phase 93: normalize affected_items. Whitelist type='item' and validate the
// id maps to a known item index in this snapshot. Drop anything else so the
// client never receives untrusted target selectors.
$affectedItems = [];
$validIds = [];
foreach ($items as $it) { $validIds[(string)$it['idx']] = true; }
if (is_array($parsed['affected_items'] ?? null)) {
    foreach ($parsed['affected_items'] as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 24);
        $id   = clean_string((string)($a['id']   ?? ''), 24);
        if ($type !== 'item' || $id === '') continue;
        if (!isset($validIds[$id])) continue;
        $affectedItems[] = ['type' => $type, 'id' => $id];
        if (count($affectedItems) >= 10) break;
    }
}

json_out([
    'ok'             => true,
    'tone'           => $tone,
    'tone_label'     => $toneLabel,
    'headline'       => $headline,
    'paragraph'      => $paragraph,
    'highlights'     => $highlights,
    'affected_items' => $affectedItems,
    'model'          => ai_config()['model'],
]);
