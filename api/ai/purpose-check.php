<?php
// POST /api/ai/purpose-check.php
// (Renamed from check-purpose.php in Phase 57 to bypass a server-side
// caching glitch that served the original file as plain text.)
// Body: {
//   "purpose":   <string, ~10-400 chars, plain-language statement of intent>,
//   "questions": [
//     { "id": <string>, "prompt": <string>, "type": <string>, "construct": <string|null> }
//   ]
// }
//
// AI Survey Purpose Checker (Phase 57). Audits the current draft against
// the user's stated purpose. The model reads each item and decides how
// well it serves the purpose, identifies aspects of the purpose that
// aren't covered, and proposes 2-5 concrete additional items that
// would fill the gaps.
//
// Output: {
//   ok,
//   alignment_tier:  "strong" | "partial" | "weak" | "off",
//   alignment_label: <string>,
//   headline:        <string>,
//   paragraph:       <string>,
//   item_alignments: [
//     { id, alignment: "core"|"supporting"|"tangential"|"off-topic", note }
//   ],
//   gaps: [
//     { aspect, why }
//   ],
//   suggested_items: [
//     { prompt, type: "likert"|"open"|"single"|"multi",
//       construct, why }
//   ],
//   model
// }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_check_purpose:user:' . (int)$user['id'], 15, 3600);

$body = read_json_body();
$purpose = clean_string((string)($body['purpose'] ?? ''), 400);
if ($purpose === '' || mb_strlen($purpose) < 6) {
    fail('bad_input', 'Add a survey purpose of at least 6 characters.');
}

$qsIn = $body['questions'] ?? null;
if (!is_array($qsIn) || count($qsIn) === 0) {
    fail('bad_input', 'Survey has no questions to audit.');
}

$validTypes = ['likert', 'open', 'single', 'multi'];
$questions = [];
$seenIds = [];
foreach ($qsIn as $q) {
    if (!is_array($q)) continue;
    $id = clean_string((string)($q['id'] ?? ''), 80);
    if ($id === '' || isset($seenIds[$id])) continue;
    $prompt = clean_string((string)($q['prompt'] ?? ''), 240);
    if ($prompt === '') continue;
    $type = (string)($q['type'] ?? 'likert');
    if (!in_array($type, $validTypes, true)) $type = 'likert';
    $construct = clean_string((string)($q['construct'] ?? ''), 60);
    $questions[] = [
        'id'        => $id,
        'prompt'    => $prompt,
        'type'      => $type,
        'construct' => $construct,
    ];
    $seenIds[$id] = true;
    if (count($questions) >= 80) break;
}
if (count($questions) === 0) fail('insufficient_data', 'No usable questions to audit.');

$qLines = [];
foreach ($questions as $q) {
    $cBit = $q['construct'] !== '' ? ' [construct: ' . $q['construct'] . ']' : '';
    $qLines[] = sprintf('  [%s] (%s)%s %s', $q['id'], $q['type'], $cBit, $q['prompt']);
}
$qBlock = implode("\n", $qLines);

$system = <<<SYS
You are a measurement researcher auditing a survey against the user's stated purpose. The user is not a statistician. They have written a short purpose statement and a list of items. Your job is to tell them, in plain language, whether the items as written actually measure what the purpose says they should, and what's missing.

Alignment tiers for the overall verdict pill:
  - "strong"   : Most items (>= 70%) directly measure the purpose. Coverage of the stated aspects is balanced. The survey is ready as-is or with minor additions.
  - "partial"  : Some items map to the purpose, but several aspects are underrepresented OR a meaningful share of items are tangential. The survey is usable but would benefit from targeted additions.
  - "weak"     : Many items don't relate to the purpose, OR the purpose's main aspects are barely covered. Substantive revisions needed before this survey can credibly serve the stated purpose.
  - "off"      : The survey and the purpose don't match at all. Almost no items address what the purpose names.

Per-item alignment categories:
  - "core"        : Directly measures a central aspect of the purpose.
  - "supporting"  : Relates to the purpose but measures a peripheral or contextual aspect.
  - "tangential"  : Loosely related; the connection is indirect.
  - "off-topic"   : Doesn't measure the stated purpose at all.

Be generous when items clearly serve the purpose; be honest when they don't. Do not invent connections.

Gaps:
  - Identify 2-5 aspects of the purpose that are underrepresented or absent.
  - Each gap has: aspect (3-6 word name in Title Case, e.g. "Psychological Safety", "Voice", "Inclusion") and why (one sentence explaining why this aspect matters to the stated purpose).
  - Do not list a gap if the survey already covers it adequately.

Suggested items:
  - 2-5 concrete item prompts the user could add to fill the gaps.
  - Each suggestion has: prompt (the actual item text, 8-22 words, written in the survey's voice), type ("likert" default for attitudinal measurement; "open" for narrative), construct (proposed construct name from the gaps), why (one sentence on what it measures and why it matters).
  - Likert items must read as agreement-style statements ("I feel...", "My team..."), not questions.
  - Open-ended items must be open invitations, not yes/no questions.
  - Do not duplicate existing items.

Headline + paragraph:
  - Headline: one sentence summarizing the alignment picture.
  - Paragraph: 2-4 sentences. Lead with the alignment tier in plain language ("The survey strongly matches your stated purpose..."), name the strongest aspect that IS covered, name the most important aspect that ISN'T, and reference suggestion count if gaps exist.

Tone: confident, plain-language, non-technical. No hedging like "might possibly". No jargon like "operationalization" or "construct validity".

Output format: respond with a single JSON object only, no prose around it, no markdown fences:

{
  "alignment_tier": "strong" | "partial" | "weak" | "off",
  "alignment_label": "<short pill label, e.g. 'Partial alignment'>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<2-4 sentences>",
  "item_alignments": [
    {
      "id": "<item id from input>",
      "alignment": "core" | "supporting" | "tangential" | "off-topic",
      "note": "<one short sentence on why this category>"
    }
  ],
  "gaps": [
    { "aspect": "<3-6 word Title Case>", "why": "<one sentence>" }
  ],
  "suggested_items": [
    {
      "prompt": "<8-22 word item prompt>",
      "type": "likert" | "open" | "single" | "multi",
      "construct": "<construct name, often the gap aspect>",
      "why": "<one sentence>"
    }
  ]
}
SYS;

$userPrompt  = "Stated purpose:\n  " . $purpose . "\n\n";
$userPrompt .= "Current items:\n" . $qBlock . "\n\n";
$userPrompt .= "Produce the purpose audit JSON now. item_alignments MUST list every id provided.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1600);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['alignment_tier'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTiers = ['strong', 'partial', 'weak', 'off'];
$tier = (string)($parsed['alignment_tier'] ?? 'partial');
if (!in_array($tier, $validTiers, true)) $tier = 'partial';

$defaultLabels = [
    'strong'  => 'Strong alignment',
    'partial' => 'Partial alignment',
    'weak'    => 'Weak alignment',
    'off'     => 'Off purpose',
];
$tierLabel = clean_string((string)($parsed['alignment_label'] ?? $defaultLabels[$tier]), 48);
if ($tierLabel === '') $tierLabel = $defaultLabels[$tier];

$headline  = clean_string((string)($parsed['headline']  ?? ''), 220);
$paragraph = clean_string((string)($parsed['paragraph'] ?? ''), 800);

$validAlignments = ['core', 'supporting', 'tangential', 'off-topic'];
$validIdSet = [];
foreach ($questions as $q) { $validIdSet[$q['id']] = true; }

$itemAlignments = [];
$seenAlignment = [];
if (is_array($parsed['item_alignments'] ?? null)) {
    foreach ($parsed['item_alignments'] as $a) {
        if (!is_array($a)) continue;
        $id = clean_string((string)($a['id'] ?? ''), 80);
        if (!isset($validIdSet[$id]) || isset($seenAlignment[$id])) continue;
        $al = (string)($a['alignment'] ?? 'supporting');
        if (!in_array($al, $validAlignments, true)) $al = 'supporting';
        $note = clean_string((string)($a['note'] ?? ''), 240);
        $itemAlignments[] = ['id' => $id, 'alignment' => $al, 'note' => $note];
        $seenAlignment[$id] = true;
    }
}
// Ensure every input id has an entry (default to "tangential" with empty note).
foreach ($questions as $q) {
    if (!isset($seenAlignment[$q['id']])) {
        $itemAlignments[] = ['id' => $q['id'], 'alignment' => 'tangential', 'note' => ''];
    }
}

$gaps = [];
if (is_array($parsed['gaps'] ?? null)) {
    foreach ($parsed['gaps'] as $g) {
        if (!is_array($g)) continue;
        $aspect = clean_string((string)($g['aspect'] ?? ''), 60);
        $why    = clean_string((string)($g['why']    ?? ''), 240);
        if ($aspect === '' || $why === '') continue;
        $gaps[] = ['aspect' => $aspect, 'why' => $why];
        if (count($gaps) >= 6) break;
    }
}

$suggestedItems = [];
if (is_array($parsed['suggested_items'] ?? null)) {
    foreach ($parsed['suggested_items'] as $s) {
        if (!is_array($s)) continue;
        $sPrompt = clean_string((string)($s['prompt'] ?? ''), 240);
        if ($sPrompt === '' || mb_strlen($sPrompt) < 8) continue;
        $sType = (string)($s['type'] ?? 'likert');
        if (!in_array($sType, $validTypes, true)) $sType = 'likert';
        $sConstruct = clean_string((string)($s['construct'] ?? ''), 60);
        $sWhy = clean_string((string)($s['why'] ?? ''), 240);
        $suggestedItems[] = [
            'prompt'    => $sPrompt,
            'type'      => $sType,
            'construct' => $sConstruct,
            'why'       => $sWhy,
        ];
        if (count($suggestedItems) >= 6) break;
    }
}

json_out([
    'ok'              => true,
    'alignment_tier'  => $tier,
    'alignment_label' => $tierLabel,
    'headline'        => $headline,
    'paragraph'       => $paragraph,
    'item_alignments' => $itemAlignments,
    'gaps'            => $gaps,
    'suggested_items' => $suggestedItems,
    'model'           => ai_config()['model'],
]);
