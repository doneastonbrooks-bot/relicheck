<?php
// GET /api/webhooks/log.php?id=<webhook_id>
// Returns the last 50 deliveries for a webhook (newest first).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_webhooks.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'id is required.', 400);

$pdo = db();
$check = $pdo->prepare('SELECT id FROM webhooks WHERE id = :id AND owner_id = :o LIMIT 1');
$check->execute([':id' => $id, ':o' => (int)$user['id']]);
if (!$check->fetch()) fail('not_found', 'Webhook not found.', 404);

try {
    $stmt = $pdo->prepare(
        'SELECT id, event, http_status, response_excerpt, error, duration_ms, is_test, fired_at
           FROM webhook_deliveries
          WHERE webhook_id = :w
          ORDER BY fired_at DESC, id DESC
          LIMIT 50'
    );
    $stmt->execute([':w' => $id]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 152 migration has not been applied yet.', 503);
}

$out = [];
foreach ($rows as $r) {
    $status = $r['http_status'] !== null ? (int)$r['http_status'] : null;
    $out[] = [
        'id'               => (int)$r['id'],
        'event'            => (string)$r['event'],
        'http_status'      => $status,
        'ok'               => $status !== null && $status >= 200 && $status < 300,
        'response_excerpt' => (string)($r['response_excerpt'] ?? ''),
        'error'            => $r['error'] ?? null,
        'duration_ms'      => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
        'is_test'          => (int)$r['is_test'] === 1,
        'fired_at'         => (string)$r['fired_at'],
    ];
}

json_out(['ok' => true, 'deliveries' => $out]);
