<?php
// POST /api/qual/mask-pii.php
// Body: { project_id, segment_id }
// Applies all detected PII masks to a single segment using the same regex patterns
// as scan-pii.php. Updates qual_segments.raw_text and writes an audit entry.
// Returns: { ok, masked_text, replacement_count }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$segmentId = (int)($body['segment_id'] ?? 0);
if ($projectId <= 0 || $segmentId <= 0) fail('bad_input', 'project_id and segment_id required.');
qual_require_project($pdo, $uid, $projectId);

$stmt = $pdo->prepare(
    "SELECT id, raw_text FROM qual_segments
     WHERE id = :s AND project_id = :p AND status = 'active' LIMIT 1"
);
$stmt->execute([':s' => $segmentId, ':p' => $projectId]);
$seg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$seg) fail('not_found', 'Segment not found.', 404);

$replacements = [
    '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/' => '[EMAIL]',
    '/\b(?:\+?1[-.\s]?)?\(?[2-9]\d{2}\)?[-.\s]\d{3}[-.\s]\d{4}\b/' => '[PHONE]',
    '/\b\d{3}-\d{2}-\d{4}\b/' => '[ID NUMBER]',
    '/\b(?:my name is|i am|i\'m)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i' => fn($m) => substr($m[0], 0, strlen($m[0]) - strlen($m[1])) . '[NAME]',
];

$text  = (string)$seg['raw_text'];
$count = 0;
foreach ($replacements as $pattern => $replacement) {
    $text = preg_replace_callback($pattern, function ($m) use ($replacement, &$count) {
        $count++;
        return is_callable($replacement) ? $replacement($m) : $replacement;
    }, $text) ?? $text;
}

$pdo->prepare("UPDATE qual_segments SET raw_text = :t WHERE id = :s")
    ->execute([':t' => $text, ':s' => $segmentId]);

qual_audit($pdo, $projectId, $uid, 'pii_masked', 'segment', $segmentId, '',
           (string)$seg['raw_text'], "replacements:{$count}");

json_out(['ok' => true, 'masked_text' => $text, 'replacement_count' => $count]);
