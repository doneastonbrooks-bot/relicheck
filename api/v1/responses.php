<?php
// GET /api/v1/responses?survey_id=<id>[&limit=N&since=<id>]
//
// Returns responses for a survey owned by the token's user.
// Pagination is cursor-based via `since` (returns rows with id > since).
// Default limit 100, max 500.
//
// Auth: Bearer token. Tier-gated to plans whose features include api_access.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_api_auth.php';

require_method('GET');
$user = require_api_token();
$pdo = db();

$surveyId = (int)($_GET['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_id', 'Missing or invalid survey_id.');

// Confirm ownership
$own = $pdo->prepare('SELECT id FROM surveys WHERE id = :id AND owner_id = :u LIMIT 1');
$own->execute([':id' => $surveyId, ':u' => $user['id']]);
if (!$own->fetch()) fail('not_found', 'No survey with that id is owned by this token.', 404);

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1)   $limit = 1;
if ($limit > 500) $limit = 500;
$since = (int)($_GET['since'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id, submitted_at, answers, arm_id
       FROM responses
      WHERE survey_id = :sid AND id > :since
      ORDER BY id ASC
      LIMIT ' . (int)$limit
);
$stmt->execute([':sid' => $surveyId, ':since' => $since]);

$rows = [];
$maxId = $since;
foreach ($stmt->fetchAll() as $r) {
    $answers = json_decode((string)$r['answers'], true);
    if (!is_array($answers)) $answers = [];
    $rid = (int)$r['id'];
    $maxId = max($maxId, $rid);
    $rows[] = [
        'id'           => $rid,
        'submitted_at' => $r['submitted_at'],
        'answers'      => $answers,
        'arm_id'       => $r['arm_id'],
    ];
}

json_out([
    'survey_id'   => $surveyId,
    'count'       => count($rows),
    'next_cursor' => $maxId, // pass this as `since` on the next call
    'responses'   => $rows,
]);
