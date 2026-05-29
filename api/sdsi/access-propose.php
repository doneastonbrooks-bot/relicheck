<?php
// POST /api/sdsi/access-propose.php
// Body: {
//   survey_id:  int
//   population:  { minors: bool, peopleFacing: bool, communities: string[] }
// }
//
// Access Readiness — AI PROPOSER (not scorer).
// Reads the survey's item text server-side, asks the model to propose access
// barriers + mitigations against the six checks, validates the shape, and
// returns a proposal the client seeds into the human-settled reviewer workflow.
// The deterministic AccessEngine (client) computes the score from the settled
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
check_rate_limit('access_propose:user:' . (int)$user['id'], 40, 3600);

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
        'notes'       => 'This survey has no item text to review yet. Add questions, then run the access review.',
        'items'       => [],
        'model'       => ai_config()['model'],
    ]);
}

// ── System prompt (mirrors apps/sdsi/access-ai-prompt.md) ──
$system = <<<SYS
You review survey instruments BEFORE any data is collected, for Access
Readiness — whether the intended respondents can actually reach, read,
understand, and complete the items. You are advisory: you PROPOSE barriers with
evidence; a human makes the final call. You never compute the score.

CORE PRINCIPLE — judge REACHABILITY, not topic.
A hard topic is not an access barrier; hard WORDING for the stated population is.
Calibrate every judgment to the population facts you are given (especially
whether respondents are minors and what languages/communities are present).
Reading level that is fine for staff may exclude children; English-only wording
may exclude a multilingual community. Evaluate only whether THIS population can
reach THIS item. When a protective feature is genuinely present (a plain-language
alternative, a translation, a worked example, an accommodation path), propose it
as a mitigation — it is not a flag.

THE SIX CHECKS (use these exact keys):
 - reading_load          : sentence/vocabulary complexity above the population's reading level for the people who must answer.
 - unglossed_jargon      : technical terms, acronyms, or insider language used without a definition the respondent would know.
 - language_barrier      : the item is in a language part of the population may not read, with no translation or plain-language path.
 - response_burden       : the item (or run of items) demands disproportionate effort — long essays, long recall, many required sub-parts — likely to cause fatigue or dropout.
 - format_inaccessibility: the response format excludes respondents (assumes a device, fine motor control, sight/hearing, or a UI the population may not have) with no alternative.
 - assumed_context       : the item presumes resources, experiences, or knowledge the population may not share (home internet, a two-parent household, prior schooling, a bank account).

SEVERITY (propose one; the human may override): minor, moderate, major, critical.
 - minor: small friction; most still answer accurately.
 - moderate: meaningfully harder for some; raises error/nonresponse.
 - major: a real subset of the population cannot answer accurately.
 - critical: effectively unreachable for the population as written.
Do NOT output penalty numbers — severity alone drives the penalty downstream.

MITIGATIONS (propose only when a protective design feature is genuinely present):
 - plain_language_alt, translation_provided, accommodation_path, example_or_scaffold, skip_or_progress_support : ITEM-SCOPED (set item_ref, and section if it defends a whole section).
 - glossary_or_definition : SURVEY-SCOPED (global) unless tied to a single item.
A global glossary does NOT count as protection for a specific unreachable item — only an item/section-scoped alternative does.

BLOCKER CANDIDATES (orthogonal — flag them; they never change a score). Mark blocker_candidate=true when ALL parts hold:
 - language_barrier + people-facing + no item-scoped translation AND no item-scoped plain-language alternative on that item. (blocker_condition="language_excludes_population")
 - reading_load + minors + severity major/critical + no item-scoped plain-language alternative AND no item-scoped example/scaffold on that item. (blocker_condition="reading_far_above_minors")
 - format_inaccessibility + people-facing + no item-scoped accommodation path AND no item-scoped plain-language alternative on that item. (blocker_condition="inaccessible_no_alt")

POPULATION FACTS you are given (use them; do not invent others): minors, peopleFacing, communities.

For EVERY proposed flag output: check, item_ref, quote (verbatim evidence), severity, rationale (one line: why it fires AND why it matters for measurement), suggested_revision (a concrete reachable rewrite), section (if it belongs to one), blocker_candidate (+ blocker_condition).

Be conservative: only flag what the text actually supports, and always quote the exact words. If nothing fires, return an empty flags array. Output STRICT JSON only, no prose outside the JSON, in this shape:
{ "flags": [ { "check": "...", "item_ref": "...", "quote": "...", "severity": "...", "rationale": "...", "suggested_revision": "...", "section": "...", "blocker_candidate": false, "blocker_condition": "..." } ], "mitigations": [ { "type": "...", "item_ref": "...", "section": "...", "evidence": "..." } ], "notes": "" }
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
$validChecks      = ['reading_load','unglossed_jargon','language_barrier','response_burden','format_inaccessibility','assumed_context'];
$validSeverities  = ['minor','moderate','major','critical'];
$validMitigations = ['plain_language_alt','translation_provided','accommodation_path','example_or_scaffold','skip_or_progress_support','glossary_or_definition'];
$validItemRefs    = array_column($items, 'id');

$flags = [];
foreach (($parsed['flags'] ?? []) as $f) {
    if (!is_array($f)) continue;
    $check = (string)($f['check'] ?? '');
    if (!in_array($check, $validChecks, true)) continue;
    $sev = strtolower((string)($f['severity'] ?? ''));
    if (!in_array($sev, $validSeverities, true)) $sev = 'moderate';
    $ref = clean_string((string)($f['item_ref'] ?? ''), 40);
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
    if (!empty($f['section'])) $flag['section'] = clean_string((string)$f['section'], 80);
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
