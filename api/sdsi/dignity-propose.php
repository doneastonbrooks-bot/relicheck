<?php
// POST /api/sdsi/dignity-propose.php
// Body: {
//   survey_id:  int
//   population:  { minors: bool, peopleFacing: bool, communities: string[] }
// }
//
// Dignity / Framing Readiness — AI PROPOSER (not scorer).
// Reads the survey's item text server-side, asks the model to propose dignity
// flags + mitigations against the six checks, validates the shape, and returns
// a proposal the client seeds into the human-settled reviewer workflow. The
// deterministic DignityEngine (client) computes the score from the settled
// flags — this endpoint never asserts a number.
//
// Returns: { ok, title, population, flags[], mitigations[], notes, items[], model }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// A full instrument review is a heavier call than the per-item check; keep the
// cap modest so a reviewer can iterate without running up the bill.
check_rate_limit('dignity_propose:user:' . (int)$user['id'], 40, 3600);

$body     = read_json_body();
$surveyId = isset($body['survey_id']) ? (int)$body['survey_id'] : 0;
if ($surveyId <= 0) fail('bad_survey', 'A survey_id is required.');

$popIn        = is_array($body['population'] ?? null) ? $body['population'] : [];
$minors       = !empty($popIn['minors']);
$peopleFacing = array_key_exists('peopleFacing', $popIn) ? !empty($popIn['peopleFacing']) : true;
$communities  = [];
if (!empty($popIn['communities']) && is_array($popIn['communities'])) {
    foreach ($popIn['communities'] as $c) {
        $c = clean_string((string)$c, 80);
        if ($c !== '') $communities[] = $c;
        if (count($communities) >= 12) break;
    }
}
$population = ['minors' => $minors, 'peopleFacing' => $peopleFacing, 'communities' => $communities];

// ── Load the instrument text (server-side; never trusts client item text) ──
$pdo  = db();
$stmt = $pdo->prepare('SELECT id, owner_id, title, questions FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row)                                       fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$title     = (string)$row['title'];
$questions = json_decode((string)$row['questions'], true);
if (!is_array($questions)) $questions = [];

// Build the item list the model reviews + the client renders as evidence.
$items = [];
foreach ($questions as $q) {
    if (!is_array($q)) continue;
    $prompt = clean_string((string)($q['prompt'] ?? ''), 4000);
    if ($prompt === '') continue;
    $item = [
        'id'       => clean_string((string)($q['id'] ?? ('q' . (count($items) + 1))), 40),
        'type'     => clean_string((string)($q['type'] ?? 'open'), 24),
        'required' => !empty($q['required']),
        'prompt'   => $prompt,
    ];
    if (!empty($q['options']) && is_array($q['options'])) {
        $opts = [];
        foreach ($q['options'] as $o) { $opts[] = clean_string((string)$o, 200); }
        $item['options'] = $opts;
    }
    $items[] = $item;
    if (count($items) >= 200) break; // generous cap; keeps the prompt bounded
}

if (empty($items)) {
    json_out([
        'ok'          => true,
        'title'       => $title,
        'population'  => $population,
        'flags'       => [],
        'mitigations' => [],
        'notes'       => 'This survey has no item text to review yet. Add questions, then run the dignity review.',
        'items'       => [],
        'model'       => ai_config()['model'],
    ]);
}

// ── System prompt (mirrors apps/sdsi/dignity-ai-prompt.md) ──
$system = <<<SYS
You review survey instruments BEFORE any data is collected, for Dignity /
Framing Readiness — whether the instrument handles people, identity, risk, and
difference with care. You are advisory: you PROPOSE flags with evidence; a human
makes the final call. You never compute the score.

CORE PRINCIPLE — judge HOW, not WHETHER.
Do not penalize a survey for asking about race, ethnicity, disability, income,
trauma, language, immigration/legal status, family structure, housing, or
special-education status. Sensitive topics are legitimate. Evaluate only:
 - whether the wording is respectful and asset-based rather than deficit-based,
 - whether the item is necessary and not extractive,
 - whether respondents have a safe way to answer or to decline,
 - whether response options let people represent themselves accurately.
A well-built sensitive question earns mitigation credit; it is not a flag.

THE SIX CHECKS (use these exact keys):
 - deficit_framing       : frames a person/group by what they LACK ("at-risk", "behind", "low-performing").
 - othering_labeling     : makes a group an out-group or defines people by a label ("those kids", "the SPED kids", "normal students").
 - identity_erasure      : response options force misrepresentation or omission (binary-only gender, collapsed race into "Other", no write-in for a community present).
 - extractive_disclosure : demands sensitive personal/family info with unclear purpose and/or no safe decline path.
 - embedded_stereotype   : presumes a trait/behavior/circumstance from group membership.
 - judging_respondent    : loaded, leading, or moralizing wording that shames or pressures an honest answer.

SEVERITY (propose one; the human may override): minor, moderate, major, critical.
Do NOT output penalty numbers — severity alone drives the penalty downstream.

MITIGATIONS (propose only when a protective design feature is genuinely present):
 - clear_purpose, decline_option, resource_framing, multiselect_writein : ITEM-SCOPED (set item_ref, and section if it defends a whole section).
 - community_language, neutral_wording : SURVEY-SCOPED (global only).
A global skip option does NOT count as protection for a specific sensitive item — only an item/section-scoped one does.

BLOCKER CANDIDATES (orthogonal — flag them; they never change a score). Mark blocker_candidate=true when ALL parts hold:
 - extractive_disclosure + minors + no item-scoped clear_purpose AND no item-scoped decline_option on that item.
 - extractive_disclosure on immigration/legal status + missing clear_purpose OR decline_option on that item. (topic="legal_status")
 - identity_erasure on a REQUIRED gender item + people-facing + no write-in/self-describe/multi-select and no decline option. (topic="gender", required=true)
 - identity_erasure on race/ethnicity with no multi-select/write-in. (topic="race" or "ethnicity")
 - deficit_framing OR embedded_stereotype on a disability item. (topic="disability")
 - embedded_stereotype tied to: race, ethnicity, income, language, disability, gender, legal_status, family_structure, housing, special_education. (set topic)

POPULATION FACTS you are given (use them; do not invent others): minors, peopleFacing, communities.

For EVERY proposed flag output: check, item_ref, quote (verbatim evidence), severity, rationale (one line: why it fires AND why it matters for measurement), suggested_revision (a concrete respectful rewrite), topic (when relevant), required (for gender), section (if it belongs to one), blocker_candidate (+ blocker_condition).

Be conservative: only flag what the text actually supports, and always quote the exact words. If nothing fires, return an empty flags array. Output STRICT JSON only, no prose outside the JSON, in this shape:
{ "flags": [ { "check": "...", "item_ref": "...", "quote": "...", "severity": "...", "rationale": "...", "suggested_revision": "...", "topic": "...", "required": false, "section": "...", "blocker_candidate": false, "blocker_condition": "..." } ], "mitigations": [ { "type": "...", "item_ref": "...", "section": "...", "evidence": "..." } ], "notes": "" }
SYS;

// ── User message: population facts + the instrument items ──
$lines = [];
$lines[] = 'Survey title: ' . $title;
$lines[] = 'Population facts:';
$lines[] = '  minors: ' . ($minors ? 'yes' : 'no');
$lines[] = '  peopleFacing: ' . ($peopleFacing ? 'yes (asked of people about themselves/their families)' : 'no (staff/admin-only instrument)');
$lines[] = '  communities: ' . ($communities ? implode(', ', $communities) : '(none specified)');
$lines[] = '';
$lines[] = 'Instrument items (item_ref = id):';
foreach ($items as $it) {
    $meta = $it['type'] . ($it['required'] ? ', required' : '');
    $line = '  [' . $it['id'] . '] (' . $meta . ') ' . $it['prompt'];
    if (!empty($it['options'])) $line .= ' | options: ' . implode(' / ', $it['options']);
    $lines[] = $line;
}
$lines[] = '';
$lines[] = 'Review these items now. Use item_ref values that match the ids in brackets.';
$userMsg = implode("\n", $lines);

$resp   = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 3000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed) {
    fail('ai_parse_failed', 'The AI returned a response we could not parse. Try again.', 502);
}

// ── Validate + normalise the proposal against the known vocabulary ──
$validChecks      = ['deficit_framing','othering_labeling','identity_erasure','extractive_disclosure','embedded_stereotype','judging_respondent'];
$validSeverities  = ['minor','moderate','major','critical'];
$validMitigations = ['clear_purpose','decline_option','resource_framing','multiselect_writein','community_language','neutral_wording'];
$validItemRefs    = array_column($items, 'id');

$flags = [];
foreach (($parsed['flags'] ?? []) as $f) {
    if (!is_array($f)) continue;
    $check = (string)($f['check'] ?? '');
    if (!in_array($check, $validChecks, true)) continue;
    $sev = strtolower((string)($f['severity'] ?? ''));
    if (!in_array($sev, $validSeverities, true)) $sev = 'moderate';
    $ref = clean_string((string)($f['item_ref'] ?? ''), 40);
    // Keep flags whose item_ref matches a real item; tolerate section-level refs.
    $quote = clean_string((string)($f['quote'] ?? ''), 1000);
    $rationale = clean_string((string)($f['rationale'] ?? ''), 600);
    if ($quote === '' || $rationale === '') continue;
    $flag = [
        'check'              => $check,
        'item_ref'           => $ref !== '' ? $ref : ($validItemRefs[0] ?? 'q1'),
        'quote'              => $quote,
        'severity'           => $sev,
        'rationale'          => $rationale,
        'suggested_revision' => clean_string((string)($f['suggested_revision'] ?? ''), 1000),
    ];
    if (!empty($f['topic']))   $flag['topic']   = clean_string((string)$f['topic'], 40);
    if (!empty($f['section'])) $flag['section'] = clean_string((string)$f['section'], 80);
    if (array_key_exists('required', $f)) $flag['required'] = !empty($f['required']);
    if (!empty($f['blocker_candidate'])) {
        $flag['blocker_candidate'] = true;
        if (!empty($f['blocker_condition'])) $flag['blocker_condition'] = clean_string((string)$f['blocker_condition'], 60);
    }
    $flags[] = $flag;
    if (count($flags) >= 60) break;
}

$mitigations = [];
foreach (($parsed['mitigations'] ?? []) as $m) {
    if (!is_array($m)) continue;
    $type = (string)($m['type'] ?? '');
    if (!in_array($type, $validMitigations, true)) continue;
    $mit = [
        'type'     => $type,
        'evidence' => clean_string((string)($m['evidence'] ?? ''), 600),
    ];
    if (!empty($m['item_ref'])) $mit['item_ref'] = clean_string((string)$m['item_ref'], 40);
    if (!empty($m['section']))  $mit['section']  = clean_string((string)$m['section'], 80);
    $mitigations[] = $mit;
    if (count($mitigations) >= 40) break;
}

$notes = clean_string((string)($parsed['notes'] ?? ''), 1200);

json_out([
    'ok'          => true,
    'title'       => $title,
    'population'  => $population,
    'flags'       => $flags,
    'mitigations' => $mitigations,
    'notes'       => $notes,
    'items'       => $items,
    'model'       => ai_config()['model'],
]);
