<?php
// GET /api/public/load_partial.php?slug=<slug>&inv=<token>
//
// Returns the in-progress draft for a tokenized respondent, or { ok:false }
// if no draft exists. Used by take.html on page load to resume from the
// last unanswered question.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

$slug = (string)($_GET['slug'] ?? '');
$inv  = (string)($_GET['inv']  ?? '');

if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    json_out(['ok' => false, 'reason' => 'bad_slug']);
}
if (!preg_match('/^[a-f0-9]{32}$/', $inv)) {
    json_out(['ok' => false, 'reason' => 'no_token']);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM surveys WHERE slug = :slug AND is_published = 1 LIMIT 1');
$stmt->execute([':slug' => $slug]);
$survey = $stmt->fetch();
if (!$survey) {
    json_out(['ok' => false, 'reason' => 'not_found']);
}

try {
    $d = $pdo->prepare(
        'SELECT answers, channel, last_seen_at
           FROM response_drafts
          WHERE survey_id = :sid AND inv_token = :t LIMIT 1'
    );
    $d->execute([':sid' => (int)$survey['id'], ':t' => $inv]);
    $row = $d->fetch();
    if (!$row) json_out(['ok' => false, 'reason' => 'no_draft']);
    json_out([
        'ok'           => true,
        'answers'      => json_decode((string)$row['answers'], true) ?: [],
        'channel'      => $row['channel'],
        'last_seen_at' => $row['last_seen_at'],
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'reason' => 'phase41_pending']);
}
