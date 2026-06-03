<?php
// POST /api/qual/concept-scan.php
// Body: { project_id, sample_limit? }
// Sends a sample of segments to Claude and asks it to identify key concepts
// organized by evidence type. Stores the result in qual_memos so it persists.
// Returns: { ok, concepts: [...], segments_scanned, from_cache }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';
require_once __DIR__ . '/../_ai.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// Release session lock early — this endpoint makes a multi-second AI call.
release_session_lock();

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
qual_require_project($pdo, $uid, $projectId);

$sampleLimit = min(100, max(10, (int)($body['sample_limit'] ?? 80)));
$force       = !empty($body['force']);

// Return cached result unless force-refresh requested
if (!$force) {
    $cached = $pdo->prepare(
        "SELECT body FROM qual_memos
         WHERE project_id = :p AND memo_type = 'concept_scan' AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    $cached->execute([':p' => $projectId]);
    $row = $cached->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $data = json_decode((string)$row['body'], true);
        if (is_array($data) && !empty($data['concepts'])) {
            $data['ok'] = true;
            $data['from_cache'] = true;
            json_out($data);
        }
    }
}

// Load a sample of segments
$stmt = $pdo->prepare(
    "SELECT raw_text FROM qual_segments
     WHERE project_id = :p AND status = 'active'
     ORDER BY RAND() LIMIT :lim"
);
$stmt->bindValue(':p',   $projectId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $sampleLimit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($rows) === 0) fail('no_data', 'No segments found for this project.');

$corpus = implode("\n---\n", array_map(
    fn($i, $t) => ($i + 1) . '. ' . trim((string)$t),
    array_keys($rows), $rows
));

$system = <<<SYS
You are a qualitative research analyst. Analyze this corpus and identify 6 to 12 key concepts present in the data.

For each concept return:
- concept: short descriptive phrase (3-7 words)
- evidence_type: one of "lexical" (specific recurring words), "phrase" (multi-word patterns), or "semantic" (underlying meaning not tied to specific words)
- frequency: approximate count of responses that touch on this concept
- example_quotes: array of 1-2 direct quotes (verbatim, under 20 words each)

Sort concepts by descending frequency.

Return ONLY valid JSON with this exact structure:
{"concepts":[{"concept":"...","evidence_type":"lexical|phrase|semantic","frequency":0,"example_quotes":["..."]}],"segments_scanned":0}
SYS;

$userMsg = "Corpus ({$sampleLimit} responses sampled):\n\n" . $corpus;

$ai = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 2048);

$parsed = null;
try {
    // Strip any markdown fences the model might add
    $text = trim((string)$ai['text']);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $parsed = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fail('ai_parse_error', 'Could not parse AI response as JSON.');
}

if (!isset($parsed['concepts']) || !is_array($parsed['concepts'])) {
    fail('ai_parse_error', 'AI response missing expected concepts array.');
}

$parsed['segments_scanned'] = (int)($parsed['segments_scanned'] ?? count($rows));

// Persist to qual_memos so it survives page reload
$memoBody = json_encode($parsed);

// Replace any existing concept_scan memo
$pdo->prepare(
    "UPDATE qual_memos SET status='archived' WHERE project_id=:p AND memo_type='concept_scan'"
)->execute([':p' => $projectId]);

$pdo->prepare(
    "INSERT INTO qual_memos (project_id, user_id, object_type, object_id, memo_type, title, body)
     VALUES (:p, :u, 'project', :oid, 'concept_scan', 'Concept Scan', :b)"
)->execute([':p' => $projectId, ':u' => $uid, ':oid' => $projectId, ':b' => $memoBody]);

qual_audit($pdo, $projectId, $uid, 'concept_scan_run', 'project', $projectId, '',
           '', "segments_scanned:{$parsed['segments_scanned']},concepts:" . count($parsed['concepts']));

json_out(array_merge(['ok' => true, 'from_cache' => false], $parsed));
