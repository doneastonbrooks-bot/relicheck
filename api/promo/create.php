<?php
// POST /api/promo/create.php   (admin only)
// Body: {
//   "code":         "<string, will be uppercased>",         (required)
//   "tier_key":     "free|researcher|professional|business" (required)
//   "duration_days": <int> | null,    null = permanent
//   "max_uses":     <int> | null,     null = unlimited total redemptions
//   "expires_at":   "YYYY-MM-DD" | null,  code itself stops working after this date
//   "notes":        "<optional admin note>"
// }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_admin();

$body = read_json_body();

$code = strtoupper(trim((string)($body['code'] ?? '')));
$code = preg_replace('/[^A-Z0-9_-]/', '', $code) ?? '';
if ($code === '' || strlen($code) < 3 || strlen($code) > 40) {
    fail('bad_code', 'Code must be 3-40 letters, digits, hyphens, or underscores.');
}

$tierKey = (string)($body['tier_key'] ?? 'researcher');
if (!tier_known($tierKey)) fail('bad_tier', 'Unknown tier.');

$durationDays = isset($body['duration_days']) && $body['duration_days'] !== null && $body['duration_days'] !== ''
    ? (int)$body['duration_days'] : null;
if ($durationDays !== null && ($durationDays < 1 || $durationDays > 36500)) {
    fail('bad_duration', 'duration_days must be between 1 and 36500, or null for permanent.');
}

$maxUses = isset($body['max_uses']) && $body['max_uses'] !== null && $body['max_uses'] !== ''
    ? (int)$body['max_uses'] : null;
if ($maxUses !== null && ($maxUses < 1 || $maxUses > 1000000)) {
    fail('bad_max_uses', 'max_uses must be between 1 and 1,000,000, or null for unlimited.');
}

$expiresAt = null;
if (!empty($body['expires_at'])) {
    $ts = strtotime((string)$body['expires_at']);
    if ($ts === false || $ts < time()) fail('bad_expires_at', 'expires_at must be a future date.');
    $expiresAt = date('Y-m-d H:i:s', $ts);
}

$notes = clean_string((string)($body['notes'] ?? ''), 500);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO promo_codes (code, tier_key, duration_days, max_uses, expires_at, notes, created_by)
     VALUES (:code, :tier, :dur, :mx, :exp, :notes, :by)'
);
try {
    $stmt->execute([
        ':code'  => $code,
        ':tier'  => $tierKey,
        ':dur'   => $durationDays,
        ':mx'    => $maxUses,
        ':exp'   => $expiresAt,
        ':notes' => $notes,
        ':by'    => $user['id'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('duplicate_code', 'That code already exists. Pick a different one.', 409);
    }
    throw $e;
}

$id = (int)$pdo->lastInsertId();

json_out([
    'ok'   => true,
    'code' => [
        'id'            => $id,
        'code'          => $code,
        'tier_key'      => $tierKey,
        'duration_days' => $durationDays,
        'max_uses'      => $maxUses,
        'uses_count'    => 0,
        'expires_at'    => $expiresAt,
        'notes'         => $notes,
        'is_active'     => true,
    ],
], 201);
