<?php
// POST /api/public/save_partial.php
// Body: { slug, inv, answers: { qid: value, ... }, ch? }
//
// Upserts a row in response_drafts keyed on (survey_id, inv_token).
// The take.html JS calls this on a debounced timer whenever the respondent
// edits an answer. On final submit, submit.php deletes the matching draft.
//
// Public endpoint by design (no check_origin). A bad token or missing
// survey produces a benign no-op so the take page never crashes mid-flow.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('POST');

$body = read_json_body();
$slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';
$inv  = is_string($body['inv']  ?? null) ? $body['inv']  : '';
$ch   = is_string($body['ch']   ?? null) ? $body['ch']   : null;
$ans  = is_array($body['answers'] ?? null) ? $body['answers'] : null;

if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    json_out(['ok' => false, 'reason' => 'bad_slug']);
}
if (!preg_match('/^[a-f0-9]{32}$/', $inv)) {
    // No token = no save (anonymous share-link respondents use localStorage).
    json_out(['ok' => false, 'reason' => 'no_token']);
}
if ($ans === null) {
    json_out(['ok' => false, 'reason' => 'no_answers']);
}
if ($ch !== null && (mb_strlen($ch) > 32 || !preg_match('/^[a-z0-9_-]+$/', $ch))) {
    $ch = null;
}

$pdo = db();

// Look up the survey by slug. Must be published; drafts on unpublished
// surveys are pointless.
$stmt = $pdo->prepare('SELECT id, is_published FROM surveys WHERE slug = :slug LIMIT 1');
$stmt->execute([':slug' => $slug]);
$survey = $stmt->fetch();
if (!$survey || !(int)$survey['is_published']) {
    json_out(['ok' => false, 'reason' => 'not_available']);
}
$surveyId = (int)$survey['id'];

// Confirm the token belongs to this survey. Anyone could POST a random
// 32-hex string; without this check, drafts could be sprayed across surveys.
$invStmt = $pdo->prepare(
    'SELECT id FROM survey_invitations
      WHERE invitation_token = :t AND survey_id = :sid LIMIT 1'
);
$invStmt->execute([':t' => $inv, ':sid' => $surveyId]);
if (!$invStmt->fetch()) {
    json_out(['ok' => false, 'reason' => 'unknown_token']);
}

// Cap the JSON payload size to protect the DB from runaway drafts.
$json = json_encode($ans, JSON_UNESCAPED_UNICODE);
if ($json === false || strlen($json) > 524288) {
    json_out(['ok' => false, 'reason' => 'too_large']);
}

try {
    $upsert = $pdo->prepare(
        'INSERT INTO response_drafts (survey_id, inv_token, answers, channel)
         VALUES (:sid, :inv, :ans, :ch)
         ON DUPLICATE KEY UPDATE
            answers = VALUES(answers),
            channel = COALESCE(VALUES(channel), channel)'
    );
    $upsert->execute([
        ':sid' => $surveyId,
        ':inv' => $inv,
        ':ans' => $json,
        ':ch'  => $ch,
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'reason' => 'phase41_pending']);
}

json_out(['ok' => true]);
