<?php
// POST /api/qual/suggest-codes.php
// Body: { project_id, segment_id }
// Asks Claude to suggest 1-4 codes for a single segment, referencing the
// existing codebook. Returns suggestions classified by evidence type.
// Returns: { ok, suggestions: [{name, rationale, evidence_type, confidence, is_existing}] }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';
require_once __DIR__ . '/../_ai.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// Release session lock early — AI call ahead.
release_session_lock();

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$segmentId = (int)($body['segment_id'] ?? 0);
if ($projectId <= 0 || $segmentId <= 0) fail('bad_input', 'project_id and segment_id required.');
qual_require_project($pdo, $uid, $projectId);

// Load the segment
$stmt = $pdo->prepare(
    "SELECT raw_text, question_ref, participant_id FROM qual_segments
     WHERE id = :s AND project_id = :p AND status = 'active' LIMIT 1"
);
$stmt->execute([':s' => $segmentId, ':p' => $projectId]);
$seg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$seg) fail('not_found', 'Segment not found.', 404);

// Load existing codes (with definitions where available)
$codeStmt = $pdo->prepare(
    "SELECT name, definition FROM qual_codes
     WHERE project_id = :p AND status <> 'retired'
     ORDER BY name ASC LIMIT 60"
);
$codeStmt->execute([':p' => $projectId]);
$codes = $codeStmt->fetchAll(PDO::FETCH_ASSOC);

$codebookText = '';
if (count($codes) > 0) {
    $lines = array_map(function ($c) {
        $def = trim((string)$c['definition']);
        return '- ' . $c['name'] . ($def !== '' ? ': ' . $def : '');
    }, $codes);
    $codebookText = "Existing codebook:\n" . implode("\n", $lines);
}

$context = '';
if (!empty($seg['question_ref'])) $context .= "Question: {$seg['question_ref']}\n";
if (!empty($seg['participant_id'])) $context .= "Participant: {$seg['participant_id']}\n";

$system = <<<SYS
You are a qualitative coding assistant. Your job is to suggest 1 to 4 codes that apply to the response segment.

For each suggestion return:
- name: short code name, 2-6 words. Prefer names from the existing codebook if they apply.
- rationale: one sentence explaining why this code fits this specific text.
- evidence_type: one of:
  "lexical"   — the code is supported by specific recurring words in the text
  "phrase"    — the code is supported by a multi-word phrase or expression
  "semantic"  — the code captures an underlying meaning not tied to specific words
  "syntactic" — the code is supported by a grammatical pattern (e.g. passive voice, hedging)
- confidence: "high", "medium", or "low"
- is_existing: true if the name matches an existing codebook entry, false if new

Return ONLY valid JSON:
{"suggestions":[{"name":"...","rationale":"...","evidence_type":"...","confidence":"...","is_existing":false}]}
SYS;

$userMsg = ($context !== '' ? $context . "\n" : '')
    . "Response segment:\n\"{$seg['raw_text']}\"\n\n"
    . ($codebookText !== '' ? $codebookText : 'No codes in codebook yet.');

$ai = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 1024);

$parsed = null;
try {
    $text = trim((string)$ai['text']);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $parsed = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fail('ai_parse_error', 'Could not parse AI response as JSON.');
}

if (!isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
    fail('ai_parse_error', 'AI response missing expected suggestions array.');
}

// Clamp to 4 suggestions, validate evidence_type values
$validEt   = ['lexical', 'phrase', 'semantic', 'syntactic'];
$validConf = ['high', 'medium', 'low'];
$existingNames = array_map(fn($c) => strtolower(trim((string)$c['name'])), $codes);

$suggestions = array_slice(array_map(function ($s) use ($validEt, $validConf, $existingNames) {
    return [
        'name'          => substr(trim((string)($s['name'] ?? '')), 0, 80),
        'rationale'     => substr(trim((string)($s['rationale'] ?? '')), 0, 300),
        'evidence_type' => in_array($s['evidence_type'] ?? '', $validEt, true) ? $s['evidence_type'] : 'semantic',
        'confidence'    => in_array($s['confidence'] ?? '', $validConf, true) ? $s['confidence'] : 'medium',
        'is_existing'   => in_array(strtolower(trim((string)($s['name'] ?? ''))), $existingNames, true),
    ];
}, $parsed['suggestions']), 0, 4);

json_out(['ok' => true, 'suggestions' => $suggestions]);
