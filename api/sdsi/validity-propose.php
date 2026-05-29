<?php
// POST /api/sdsi/validity-propose.php
// Body: {
//   survey_id: int,
//   component: string (one of the five factory validity components),
//   context:   { ...reviewer-declared fields for this component... }
// }
//
// Validity Readiness (factory lenses) — AI PROPOSER (not scorer).
// Reads the survey's item text server-side, asks the model to propose validity
// issues against the component's check vocabulary, validates the shape, and
// returns a proposal the client seeds into the human-settled reviewer workflow.
// The deterministic factory lens (client) computes the score from the settled
// flags — this endpoint never asserts a number.
//
// Returns: { ok, component, title, context, flags[], notes, items[], model }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/_validity_prompts.php';

require_method('POST');
check_origin();
$user = require_auth();

check_rate_limit('validity_propose:user:' . (int)$user['id'], 60, 3600);

$body      = read_json_body();
$surveyId  = isset($body['survey_id']) ? (int)$body['survey_id'] : 0;
$component = clean_string((string)($body['component'] ?? ''), 32);
if ($surveyId <= 0) fail('bad_survey', 'A survey_id is required.');

$spec = validity_component_spec($component);
if (!$spec) fail('bad_component', 'Unknown validity component.');

// ── Normalise the reviewer-declared context against the spec's fields ──
$ctxIn   = is_array($body['context'] ?? null) ? $body['context'] : [];
$context = [];
foreach ($spec['contextFields'] as $cf) {
    $key = $cf['key'];
    if ($cf['type'] === 'list') {
        $vals = [];
        $raw  = $ctxIn[$key] ?? [];
        if (is_string($raw)) $raw = preg_split('/[\r\n,]+/', $raw);
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $v = clean_string((string)$v, 120);
                if ($v !== '') $vals[] = $v;
                if (count($vals) >= 24) break;
            }
        }
        $context[$key] = $vals;
    } else {
        $context[$key] = clean_string((string)($ctxIn[$key] ?? ''), 2000);
    }
}

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
    if (count($items) >= 200) break;
}

if (empty($items)) {
    json_out([
        'ok'        => true,
        'component' => $component,
        'title'     => $title,
        'context'   => $context,
        'flags'     => [],
        'notes'     => 'This survey has no item text to review yet. Add questions, then run the review.',
        'items'     => [],
        'model'     => ai_config()['model'],
    ]);
}

$system = validity_system_prompt($spec);

// ── User message: declared context + the instrument items ──
$lines   = [];
$lines[] = 'Survey title: ' . $title;
$lines[] = 'Reviewer-declared context for this component:';
foreach ($spec['contextFields'] as $cf) {
    $val = $context[$cf['key']] ?? '';
    if (is_array($val)) $val = $val ? implode('; ', $val) : '(none specified)';
    if ($val === '') $val = '(not provided)';
    $lines[] = '  ' . $cf['label'] . ': ' . $val;
}
if (empty($spec['contextFields'])) {
    $lines[] = '  (this component reads the items directly — no declared context)';
}
$lines[] = '';
$lines[] = 'Instrument items (item_ref = id):';
foreach ($items as $it) {
    $meta = $it['type'] . ($it['required'] ? ', required' : '');
    $line = '  [' . $it['id'] . '] (' . $meta . ') ' . $it['prompt'];
    if (!empty($it['options'])) $line .= ' | options: ' . implode(' / ', $it['options']);
    $lines[] = $line;
}
$lines[] = '';
$lines[] = 'Review these items now. Use item_ref values that match the ids in brackets, a dimension name, or a context field key (e.g. "definition", "purpose") when the issue is in the declared context.';
$userMsg = implode("\n", $lines);

$resp   = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 3000);
$parsed = ai_extract_json($resp['text']);
if (!$parsed) {
    fail('ai_parse_failed', 'The AI returned a response we could not parse. Try again.', 502);
}

// ── Validate + normalise the proposal against the component's vocabulary ──
$validChecks     = array_keys($spec['checks']);
$validSeverities = ['minor', 'moderate', 'major', 'critical'];
$itemRefs        = array_column($items, 'id');
// Authoritative required-item map (server-derived; never trusts AI). Used to
// stamp `required` on each flag so launch blockers that gate on required items
// (e.g. response_option_validity's unanswerable_scale) can fire deterministically.
$requiredMap = [];
foreach ($items as $it) { $requiredMap[$it['id']] = !empty($it['required']); }

$flags = [];
foreach (($parsed['flags'] ?? []) as $f) {
    if (!is_array($f)) continue;
    $check = (string)($f['check'] ?? '');
    if (!in_array($check, $validChecks, true)) continue;
    $sev = strtolower((string)($f['severity'] ?? ''));
    if (!in_array($sev, $validSeverities, true)) $sev = $spec['severityHints'][$check] ?? 'moderate';
    $ref       = clean_string((string)($f['item_ref'] ?? ''), 60);
    $quote     = clean_string((string)($f['quote'] ?? ''), 1000);
    $rationale = clean_string((string)($f['rationale'] ?? ''), 600);
    if ($quote === '' || $rationale === '') continue;
    $refResolved = $ref !== '' ? $ref : ($itemRefs[0] ?? 'q1');
    $flags[] = [
        'check'              => $check,
        'item_ref'           => $refResolved,
        'required'           => !empty($requiredMap[$refResolved]),
        'quote'              => $quote,
        'severity'           => $sev,
        'rationale'          => $rationale,
        'suggested_revision' => clean_string((string)($f['suggested_revision'] ?? ''), 1000),
    ];
    if (count($flags) >= 60) break;
}

$notes = clean_string((string)($parsed['notes'] ?? ''), 1200);

json_out([
    'ok'        => true,
    'component' => $component,
    'title'     => $title,
    'context'   => $context,
    'flags'     => $flags,
    'notes'     => $notes,
    'items'     => $items,
    'model'     => ai_config()['model'],
]);
