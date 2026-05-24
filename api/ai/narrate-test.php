<?php
// POST /api/ai/narrate-test.php
// Body: {
//   "snapshot": {
//     "title":          <string>,
//     "n":              <int>,
//     "k":              <int>,
//     "mean_score":     <float|null>,
//     "median_score":   <float|null>,
//     "sd":             <float|null>,
//     "pass_threshold": <float>,
//     "pass_rate":      <float>,
//     "alpha":          <float|null>,
//     "omega":          <float|null>,
//     "split_half":     <float|null>,
//     "sem":            <float|null>,
//     "items": [
//       { "index": <int>, "label": <string>,
//         "p_correct": <float>, "point_bis": <float|null>,
//         "discrimination": <float|null>,
//         "difficulty_label": <string>, "quality_label": <string>, "quality_tier": <string> }
//     ],
//     "easiest": <item|null>,
//     "hardest": <item|null>,
//     "flagged_items": [<item>, ...]
//   }
// }
//
// Phase 71. AI narrator for the Test Analytics view. Teacher-friendly
// language: "this test reliably ranks students", "two items may need
// attention", "the average student scored X out of Y." Translates
// reliability, difficulty, and item quality into actions a teacher can
// take.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_narrate_test:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$snap = $body['snapshot'] ?? null;
if (!is_array($snap)) fail('bad_input', 'Missing snapshot payload.');

$title    = clean_string((string)($snap['title'] ?? ''), 255);
$n        = max(0, (int)($snap['n'] ?? 0));
$k        = max(0, (int)($snap['k'] ?? 0));
if ($n < 5 || $k < 3) fail('insufficient_data', 'Need at least 5 students and 3 items.');

$normFloat = function ($v, float $min, float $max, int $round = 3): ?float {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, $round);
};

$meanScore  = $normFloat($snap['mean_score']   ?? null, 0, 1e6, 2);
$medianScore= $normFloat($snap['median_score'] ?? null, 0, 1e6, 2);
$sd         = $normFloat($snap['sd']           ?? null, 0, 1e6, 2);
$passThresh = $normFloat($snap['pass_threshold'] ?? null, 0, 100, 1);
$passRate   = $normFloat($snap['pass_rate']    ?? null, 0, 1, 3);
$alpha      = $normFloat($snap['alpha']        ?? null, -1, 2);
$omega      = $normFloat($snap['omega']        ?? null, -1, 2);
$splitHalf  = $normFloat($snap['split_half']   ?? null, -1, 2);
$sem        = $normFloat($snap['sem']          ?? null, 0, 1e6, 2);

$readItem = function ($it) use ($normFloat) {
    if (!is_array($it)) return null;
    return [
        'index'          => (int)($it['index'] ?? 0),
        'label'          => clean_string((string)($it['label'] ?? ''), 100),
        'p_correct'      => $normFloat($it['p_correct'] ?? null, 0, 1, 3),
        'point_bis'      => $normFloat($it['point_bis'] ?? null, -1.5, 1.5, 3),
        'discrimination' => $normFloat($it['discrimination'] ?? null, -2, 2, 3),
        'difficulty_label' => clean_string((string)($it['difficulty_label'] ?? ''), 20),
        'quality_label'    => clean_string((string)($it['quality_label']    ?? ''), 20),
        'quality_tier'     => clean_string((string)($it['quality_tier']     ?? ''), 20),
    ];
};
$easiest = $readItem($snap['easiest'] ?? null);
$hardest = $readItem($snap['hardest'] ?? null);

$flagged = [];
if (is_array($snap['flagged_items'] ?? null)) {
    foreach ($snap['flagged_items'] as $it) {
        $row = $readItem($it);
        if ($row) $flagged[] = $row;
        if (count($flagged) >= 6) break;
    }
}

$fmt = function ($v) { return $v === null ? 'NA' : (string)$v; };

$flagBlock = '';
foreach ($flagged as $it) {
    $flagBlock .= sprintf('  - %s ("%s"): %d%% correct, point-biserial %s' . "\n",
        $it['quality_label'],
        $it['label'],
        $it['p_correct'] !== null ? (int)round($it['p_correct'] * 100) : 0,
        $fmt($it['point_bis'])
    );
}

$snapshotBlock  = "Test: " . ($title === '' ? '(untitled)' : $title) . "\n";
$snapshotBlock .= "n = " . $n . " students, k = " . $k . " items.\n";
$snapshotBlock .= "Mean score: " . $fmt($meanScore) . " / " . $k
              . ", median: " . $fmt($medianScore) . ", SD: " . $fmt($sd) . "\n";
$snapshotBlock .= "Pass rate at " . $fmt($passThresh) . "%: " . ($passRate !== null ? (int)round($passRate * 100) . '%' : 'NA') . "\n";
$snapshotBlock .= "Reliability: alpha = " . $fmt($alpha)
              . ", omega = " . $fmt($omega)
              . ", split-half = " . $fmt($splitHalf)
              . ", SEM = " . $fmt($sem) . " points\n";
if ($easiest) {
    $snapshotBlock .= 'Easiest item: "' . $easiest['label'] . '" at '
                   . ($easiest['p_correct'] !== null ? (int)round($easiest['p_correct'] * 100) . '%' : 'NA')
                   . ' correct.' . "\n";
}
if ($hardest) {
    $snapshotBlock .= 'Hardest item: "' . $hardest['label'] . '" at '
                   . ($hardest['p_correct'] !== null ? (int)round($hardest['p_correct'] * 100) . '%' : 'NA')
                   . ' correct.' . "\n";
}
$snapshotBlock .= "Flagged items (review or problem quality):\n" . ($flagBlock === '' ? '  (none)' : $flagBlock);

$system = <<<SYS
You are an experienced teacher and assessment coach narrating the Test Analytics dashboard. The user is a classroom teacher, district leader, or curriculum coordinator who knows tests but not psychometrics. Speak like a respected peer who has graded a thousand tests.

Tone tiers for the visual pill:
  - "good" : alpha (or omega) at or above 0.80 AND pass rate roughly aligned with what the teacher would expect AND no problem items. The test reliably ranks students and items are doing their job.
  - "ok"   : reliability 0.70 to 0.80, one or two review-quality items, pass rate reasonable.
  - "warn" : reliability 0.60 to 0.70, multiple flagged items, or pass rate way off expectations (e.g., almost everyone failed or almost everyone passed).
  - "bad"  : reliability below 0.60 or several problem items or a negative point-biserial signaling a possibly miskeyed item.

Voice:
  - Open with the bottom line in plain teacher language. "Students did well overall and the test gave consistent results" or "Two items are not working; here is what to check."
  - Translate reliability: alpha is "how consistently the test ranks students." 0.80+ is "solid." 0.70 is "workable." Below 0.70 is "shaky."
  - Mention the easiest and hardest items by their label, with percent correct.
  - If items are flagged for review or problem quality, call them out by label and recommend an action (review the wording, check the answer key, swap an answer choice).
  - Avoid statistical jargon. Do not say "p < 0.05", "point-biserial correlation", or "Cronbach". Say "how well the item separates strong from weak students" or "how the item lines up with the rest of the test."
  - Two to four sentences. Plain prose. No bullet lists in the paragraph.

Highlights (0 to 3): short items surfacing specific findings.
  - Each has: label (3 to 6 words), detail (one short sentence).
  - Good targets: "Reliable test overall", "Two items need attention", "Most students passed", "Possible miskeyed item", "Test was too easy".

Headline:
  - One sentence verdict.

Phase 98: Structured findings and recommendations.

REQUIRED: every response MUST include at least one finding and at least one recommendation. Even for a clean, healthy test, surface the strengths as positive findings and offer at least one "consider" recommendation (item bank size, next-administration ideas, item revisions, etc.). Empty findings or empty recommendations is not acceptable.

Findings (1 to 5): specific observations about the test, ordered most-to-least important.
  - severity: "positive" (a strength worth naming), "low" (a minor heads-up), "medium" (worth attention), "high" (must address).
  - title: 4 to 8 word noun phrase. "Two items may be miskeyed", "Strong overall reliability", "Test too easy for this group".
  - body: one to two short sentences. Translate the numbers into a teacher's-eye observation. Plain language.
  - affected_items: optional array of items the finding is about. Use the item INDEX numbers (1-based, the same numbering students see). Shape: [{ "type": "item", "id": "<index>" }]. Include only when the finding is about specific items.

Recommendations (1 to 5): concrete actions a teacher can take next, ordered most-to-least urgent. Each one should mirror or extend the findings. ALWAYS return at least one; for a healthy test a single "consider" item is fine.
  - priority: "now" (do this before re-using the test), "soon" (do this within a week or so), "consider" (worth thinking about for next time).
  - action: imperative sentence, 6 to 14 words. "Review the answer key for Q5 and Q12 before re-using this test." "Replace the non-functioning distractor on Q3."
  - rationale: one short sentence connecting the action back to what the data shows. Plain language, no jargon.
  - affected_items: optional, same shape as findings.

Findings describe WHAT the data shows. Recommendations describe WHAT TO DO. If a finding is positive ("Strong overall reliability"), it does not need a paired recommendation. If a recommendation is purely forward-looking ("Consider expanding the item bank for next time"), it does not need a paired finding.

Output format: a single JSON object only, no prose around it, no markdown fences, no code fences:

{
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill label>",
  "headline": "<one plain-language sentence>",
  "paragraph": "<one paragraph, two to four sentences>",
  "highlights": [
    { "label": "<3 to 6 word label>", "detail": "<one short sentence>" }
  ],
  "findings": [
    { "severity": "positive" | "low" | "medium" | "high",
      "title": "<4 to 8 word noun phrase>",
      "body": "<one to two short sentences>",
      "affected_items": [{ "type": "item", "id": "<1-based item index as string>" }] }
  ],
  "recommendations": [
    { "priority": "now" | "soon" | "consider",
      "action": "<imperative sentence>",
      "rationale": "<one short sentence>",
      "affected_items": [{ "type": "item", "id": "<1-based item index as string>" }] }
  ]
}
SYS;

$userPrompt = "Test analytics snapshot:\n\n" . $snapshotBlock . "\n\nProduce the teacher-facing narration JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1600);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['tone'])) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validTones = ['good', 'ok', 'warn', 'bad'];
$tone = (string)($parsed['tone'] ?? 'ok');
if (!in_array($tone, $validTones, true)) $tone = 'ok';

$defaultLabels = [
    'good' => 'Reliable, well-built test',
    'ok'   => 'Workable test, minor fixes',
    'warn' => 'A few items need attention',
    'bad'  => 'Test needs revision',
];
$toneLabel = clean_string((string)($parsed['tone_label'] ?? $defaultLabels[$tone]), 48);
// Reject bare tone words - the AI sometimes echoes "warn" or "ok" verbatim.
if ($toneLabel === '' || in_array(strtolower($toneLabel), ['good','ok','warn','bad'], true)) {
    $toneLabel = $defaultLabels[$tone];
}

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

// Phase 98: normalize affected_items. AI uses 1-based indices (what teachers
// see in the dashboard); the client expects 0-based ids on the
// data-narrator-target attribute. Whitelist type, validate id is within range
// [0, k-1] after conversion, dedupe per call.
$normAffected = function ($arr) use ($k) {
    if (!is_array($arr)) return [];
    $out = [];
    $seen = [];
    foreach ($arr as $a) {
        if (!is_array($a)) continue;
        $type = clean_string((string)($a['type'] ?? ''), 20);
        if ($type !== 'item') continue;
        $rawId = $a['id'] ?? null;
        if (!is_numeric($rawId)) continue;
        $oneBased = (int)$rawId;
        $zeroBased = $oneBased - 1;
        if ($zeroBased < 0 || $zeroBased >= $k) continue;
        $key = $type . ':' . $zeroBased;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['type' => $type, 'id' => (string)$zeroBased];
        if (count($out) >= 10) break;
    }
    return $out;
};

$validSeverities = ['positive', 'low', 'medium', 'high'];
$findings = [];
if (is_array($parsed['findings'] ?? null)) {
    foreach ($parsed['findings'] as $f) {
        if (!is_array($f)) continue;
        $sev   = (string)($f['severity'] ?? 'medium');
        if (!in_array($sev, $validSeverities, true)) $sev = 'medium';
        $title = clean_string((string)($f['title'] ?? ''), 80);
        $body  = clean_string((string)($f['body']  ?? ''), 320);
        if ($title === '' || $body === '') continue;
        $aff = $normAffected($f['affected_items'] ?? null);
        $findings[] = [
            'severity'       => $sev,
            'title'          => $title,
            'body'           => $body,
            'affected_items' => $aff,
        ];
        if (count($findings) >= 5) break;
    }
}

$validPriorities = ['now', 'soon', 'consider'];
$recommendations = [];
if (is_array($parsed['recommendations'] ?? null)) {
    foreach ($parsed['recommendations'] as $r) {
        if (!is_array($r)) continue;
        $pri   = (string)($r['priority'] ?? 'soon');
        if (!in_array($pri, $validPriorities, true)) $pri = 'soon';
        $action    = clean_string((string)($r['action']    ?? ''), 160);
        $rationale = clean_string((string)($r['rationale'] ?? ''), 320);
        if ($action === '') continue;
        $aff = $normAffected($r['affected_items'] ?? null);
        $recommendations[] = [
            'priority'       => $pri,
            'action'         => $action,
            'rationale'      => $rationale,
            'affected_items' => $aff,
        ];
        if (count($recommendations) >= 5) break;
    }
}

// Phase 98 fallback. If the AI returns no recommendations (older AI model
// not following the new prompt, or a JSON shape that failed validation),
// synthesize at least one deterministic recommendation from the snapshot so
// the Recommendations for Teachers card always has actionable content.
if (empty($recommendations)) {
    $defaults = [];
    $flaggedCount = count($flagged);
    if ($flaggedCount >= 2) {
        $defaults[] = [
            'priority'       => 'now',
            'action'         => 'Review the ' . $flaggedCount . ' flagged items before reusing this test.',
            'rationale'      => 'Their quality signal is weaker than the rest of the test, so they may be misaligned, miskeyed, or carrying ineffective distractors.',
            'affected_items' => [],
        ];
    } elseif ($flaggedCount === 1) {
        $defaults[] = [
            'priority'       => 'soon',
            'action'         => 'Review the one item flagged for quality before reusing this test.',
            'rationale'      => 'Its alignment with the rest of the test is weaker than expected; revising it should lift overall reliability.',
            'affected_items' => [],
        ];
    }
    if ($alpha !== null && $alpha < 0.70) {
        $defaults[] = [
            'priority'       => 'soon',
            'action'         => 'Add more items or revise weak items to lift reliability above 0.70.',
            'rationale'      => 'A reliability below 0.70 means student scores are not consistent enough to support high-stakes decisions.',
            'affected_items' => [],
        ];
    }
    if ($passRate !== null && $passRate <= 0.20) {
        $defaults[] = [
            'priority'       => 'soon',
            'action'         => 'Check whether the curriculum covered everything this test is asking.',
            'rationale'      => 'Fewer than 1 in 5 students cleared the pass threshold, which usually points to a coverage gap or an overly hard form.',
            'affected_items' => [],
        ];
    } elseif ($passRate !== null && $passRate >= 0.95) {
        $defaults[] = [
            'priority'       => 'consider',
            'action'         => 'Add a few harder items if you want this test to separate stronger from average students.',
            'rationale'      => 'Almost every student passed, which is fine for mastery checks but leaves no room to show growth at the top end.',
            'affected_items' => [],
        ];
    }
    if (empty($defaults)) {
        $defaults[] = [
            'priority'       => 'consider',
            'action'         => 'Save this test in your bank as a strong reusable assessment.',
            'rationale'      => 'Reliability and item quality look solid; this test is a good candidate to reuse with future sections.',
            'affected_items' => [],
        ];
    }
    $recommendations = array_slice($defaults, 0, 5);
}

// Mirror the same guarantee for findings: at least one descriptive
// observation so the narrator card never looks empty under the paragraph.
if (empty($findings)) {
    $defaults = [];
    $flaggedCount = count($flagged);
    if ($flaggedCount > 0) {
        $defaults[] = [
            'severity'       => $flaggedCount >= 3 ? 'high' : 'medium',
            'title'          => $flaggedCount . ' item' . ($flaggedCount === 1 ? '' : 's') . ' flagged for review',
            'body'           => 'These items either misalign with the rest of the test or have answer-choice issues worth a second look before this test is reused.',
            'affected_items' => [],
        ];
    }
    if ($alpha !== null) {
        if ($alpha >= 0.80) {
            $defaults[] = [
                'severity'       => 'positive',
                'title'          => 'Strong overall reliability',
                'body'           => 'Reliability around ' . number_format($alpha, 2) . ' means the test ranks students consistently. Two retakes would land in roughly the same place.',
                'affected_items' => [],
            ];
        } elseif ($alpha < 0.70) {
            $defaults[] = [
                'severity'       => 'medium',
                'title'          => 'Reliability below the comfort zone',
                'body'           => 'Reliability around ' . number_format($alpha, 2) . ' is shaky; small score differences may not reflect real differences between students.',
                'affected_items' => [],
            ];
        }
    }
    if (empty($defaults)) {
        $defaults[] = [
            'severity'       => 'low',
            'title'          => 'Test analyzed',
            'body'           => 'The dashboard cards below show reliability, difficulty, and item quality in detail.',
            'affected_items' => [],
        ];
    }
    $findings = array_slice($defaults, 0, 5);
}

// Phase 98 deterministic fallback: if the AI returned no findings or no
// recommendations, synthesize a baseline from the snapshot data. The
// dashboard Recommendations card should never sit in an empty state when we
// already have signal in the analytics.
if (empty($findings) || empty($recommendations)) {
    $defFindings = [];
    $defRecos = [];

    $flagCount = count($flagged);
    $flagItems = [];
    foreach ($flagged as $it) {
        $flagItems[] = ['type' => 'item', 'id' => (string)max(0, ((int)$it['index']) - 1)];
    }

    if ($flagCount >= 2) {
        $defFindings[] = [
            'severity' => 'high',
            'title'    => $flagCount . ' items flagged for quality',
            'body'     => 'The dashboard flagged ' . $flagCount . ' items where difficulty, discrimination, or answer-choice performance is off. Review these before reusing the test.',
            'affected_items' => $flagItems,
        ];
        $defRecos[] = [
            'priority'       => 'now',
            'action'         => 'Review the ' . $flagCount . ' flagged items before reusing this test.',
            'rationale'      => 'These items either misalign with the rest of the test or have answer-choice issues that may be confusing students.',
            'affected_items' => $flagItems,
        ];
    } elseif ($flagCount === 1) {
        $defFindings[] = [
            'severity' => 'medium',
            'title'    => 'One item flagged for review',
            'body'     => 'A single item is showing signs of weak quality. Worth a quick look before the next administration.',
            'affected_items' => $flagItems,
        ];
        $defRecos[] = [
            'priority'       => 'soon',
            'action'         => 'Take a look at the one flagged item and decide whether to keep, revise, or drop it.',
            'rationale'      => 'Its quality signal is weaker than the rest of the test.',
            'affected_items' => $flagItems,
        ];
    }

    if ($alpha !== null && $alpha < 0.70) {
        $defFindings[] = [
            'severity' => 'medium',
            'title'    => 'Reliability below the workable threshold',
            'body'     => 'Alpha of ' . number_format($alpha, 2) . ' means the test ranks students less consistently than you would want for a high-stakes use.',
            'affected_items' => [],
        ];
        $defRecos[] = [
            'priority'       => 'soon',
            'action'         => 'Add more items or revise weak ones to lift reliability above 0.70.',
            'rationale'      => 'A short or noisy test puts students near a pass cut where one item can flip the result.',
            'affected_items' => [],
        ];
    }

    if ($passRate !== null && $passRate >= 0.95) {
        $defFindings[] = [
            'severity' => 'low',
            'title'    => 'Almost everyone passed',
            'body'     => 'A pass rate of ' . (int)round($passRate * 100) . '% suggests the test was easy for this group.',
            'affected_items' => [],
        ];
        $defRecos[] = [
            'priority'       => 'consider',
            'action'         => 'Add a few harder items for the next administration to spread students out more.',
            'rationale'      => 'The test is currently not separating top performers from the rest.',
            'affected_items' => [],
        ];
    } elseif ($passRate !== null && $passRate <= 0.30) {
        $defFindings[] = [
            'severity' => 'medium',
            'title'    => 'Pass rate is low',
            'body'     => 'Only ' . (int)round($passRate * 100) . '% of students cleared the pass threshold. That could be the bar, the items, or the instruction.',
            'affected_items' => [],
        ];
        $defRecos[] = [
            'priority'       => 'soon',
            'action'         => 'Compare the hardest items to what was covered in class before assuming the bar is right.',
            'rationale'      => 'A low pass rate can come from items that ran ahead of instruction, not just from rigor.',
            'affected_items' => [],
        ];
    }

    if (empty($defFindings)) {
        $defFindings[] = [
            'severity' => 'positive',
            'title'    => 'A solid test worth saving',
            'body'     => 'Reliability and item quality look healthy. This is a reusable assessment.',
            'affected_items' => [],
        ];
    }
    if (empty($defRecos)) {
        $defRecos[] = [
            'priority'       => 'consider',
            'action'         => 'Save this test in your bank as a strong reusable assessment.',
            'rationale'      => 'Reliability and item quality are both in good shape; this is a candidate for re-use.',
            'affected_items' => [],
        ];
    }

    if (empty($findings))        $findings = $defFindings;
    if (empty($recommendations)) $recommendations = $defRecos;
}

// Roll up all unique affected items from findings + recommendations into a
// single top-level array so the existing Phase 93 "Show affected items"
// button still works alongside the per-finding / per-recommendation links.
$rolledUp = [];
$rolledSeen = [];
foreach (array_merge($findings, $recommendations) as $row) {
    foreach (($row['affected_items'] ?? []) as $a) {
        $key = $a['type'] . ':' . $a['id'];
        if (isset($rolledSeen[$key])) continue;
        $rolledSeen[$key] = true;
        $rolledUp[] = $a;
        if (count($rolledUp) >= 12) break 2;
    }
}

json_out([
    'ok'              => true,
    'tone'            => $tone,
    'tone_label'      => $toneLabel,
    'headline'        => $headline,
    'paragraph'       => $paragraph,
    'highlights'      => $highlights,
    'findings'        => $findings,
    'recommendations' => $recommendations,
    'affected_items'  => $rolledUp,
    'model'           => ai_config()['model'],
]);
