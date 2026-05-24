<?php
// POST /api/ai/recommend-scale.php
// Body: { "aim": "<text>" }
//
// Phase 79. Takes a research aim and returns 2-4 validated-scale
// recommendations. Each recommendation includes the scale name, the
// underlying construct, the typical citation, the recommended sample
// size, a rationale, and a flag indicating whether ReliCheck's library
// already has a starter survey for the scale (with the matching
// scale_key the existing /api/surveys/from_validated.php accepts).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
check_rate_limit('ai_recommend_scale:user:' . (int)$user['id'], 30, 3600);

$body = read_json_body();
$aim  = clean_string((string)($body['aim'] ?? ''), 1000);
if ($aim === '') fail('bad_input', 'Tell us what you want to measure.');

// Library scale catalog (Phase 39). Keep in sync with surveys.from_validated.
$libraryCatalog = [
    'loneliness'              => 'UCLA Loneliness Scale (short form). Subjective social isolation.',
    'resilience'              => 'Brief Resilience Scale (BRS). Ability to bounce back from stress.',
    'self_efficacy'           => 'General Self-Efficacy Scale (GSE). Belief in one\'s capability to handle challenges.',
    'perceived_stress'        => 'Perceived Stress Scale (PSS). Subjective experience of life as stressful.',
    'academic_self_efficacy'  => 'Academic self-efficacy. Belief in one\'s ability to succeed in academic tasks.',
];
$libraryBlock = '';
foreach ($libraryCatalog as $key => $desc) {
    $libraryBlock .= '  - ' . $key . ': ' . $desc . "\n";
}

$system = <<<SYS
You are a measurement researcher recommending validated scales for the user's research aim. Return 2-4 recommendations, prioritized by fit.

For each recommendation include:
  - scale_name: short name (e.g., "Brief Resilience Scale").
  - construct: what it measures, 2-6 words.
  - citation: short author-year reference, e.g., "Smith et al., 2008".
  - typical_n: typical recommended sample size, plain language (e.g., "100-200 for reliability estimation").
  - rationale: 1-2 sentences on why this scale fits the user's aim.
  - library_match: one of the following keys if ReliCheck's library has a starter survey for this scale, otherwise null:
{$libraryBlock}

Voice: helpful, concise, like a measurement-friendly colleague. Do not invent scales. If a scale would fit but is not standard, name the closest established alternative and say it is the closest fit.

Output format: a single JSON object only, no prose around it, no markdown fences:

{
  "recommendations": [
    {
      "scale_name": "<string>",
      "construct":  "<string>",
      "citation":   "<string>",
      "typical_n":  "<string>",
      "rationale":  "<string>",
      "library_match": null | "loneliness" | "resilience" | "self_efficacy" | "perceived_stress" | "academic_self_efficacy"
    }
  ]
}
SYS;

$userPrompt = "Research aim:\n\n" . $aim . "\n\nReturn the recommendations JSON now.";

$resp = ai_complete($system, [['role' => 'user', 'content' => $userPrompt]], 1500);
$parsed = ai_extract_json($resp['text']);
if (!$parsed || !is_array($parsed['recommendations'] ?? null)) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

$validKeys = array_keys($libraryCatalog);
$recommendations = [];
foreach ($parsed['recommendations'] as $r) {
    if (!is_array($r)) continue;
    $name      = clean_string((string)($r['scale_name'] ?? ''), 120);
    if ($name === '') continue;
    $construct = clean_string((string)($r['construct'] ?? ''), 80);
    $citation  = clean_string((string)($r['citation']  ?? ''), 120);
    $typicalN  = clean_string((string)($r['typical_n'] ?? ''), 120);
    $rationale = clean_string((string)($r['rationale'] ?? ''), 400);
    $libMatch  = $r['library_match'] ?? null;
    if (!is_string($libMatch) || !in_array($libMatch, $validKeys, true)) $libMatch = null;
    $recommendations[] = [
        'scale_name'   => $name,
        'construct'    => $construct,
        'citation'     => $citation,
        'typical_n'    => $typicalN,
        'rationale'    => $rationale,
        'library_match'=> $libMatch,
    ];
    if (count($recommendations) >= 4) break;
}

if (!$recommendations) fail('ai_empty', 'No recommendations came back. Try a more specific aim.', 502);

json_out([
    'ok'              => true,
    'aim'             => $aim,
    'recommendations' => $recommendations,
    'model'           => ai_config()['model'],
]);
