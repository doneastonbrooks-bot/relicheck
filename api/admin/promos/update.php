<?php
// POST /api/admin/promos/update.php
// Body: { id: int,
//         duration_days?: int|null,
//         max_uses?: int|null,
//         expires_at?: 'YYYY-MM-DD'|null,
//         notes?: string,
//         is_active?: bool,
//         reason?: string }
//
// Updates an existing promo code's mutable fields. Code text and tier_key
// are NOT editable here (those would invalidate prior redemptions; create
// a new code instead). Audit row is written with the changed fields.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid promo id.');

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, code, tier_key, duration_days, max_uses, expires_at, notes, is_active
       FROM promo_codes WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$promo = $stmt->fetch();
if (!$promo) fail('not_found', 'Promo code not found.', 404);

// Build SET clauses only for fields the caller actually supplied.
$set    = [];
$params = [':id' => $id];
$diffs  = [];

if (array_key_exists('duration_days', $body)) {
    $val = $body['duration_days'];
    if ($val === '' || $val === null) {
        $newVal = null;
    } else {
        $newVal = (int)$val;
        if ($newVal < 1 || $newVal > 36500) fail('bad_duration', 'duration_days must be between 1 and 36500, or null.');
    }
    if ((int)($promo['duration_days'] ?? 0) !== (int)($newVal ?? 0) || ($promo['duration_days'] === null) !== ($newVal === null)) {
        $set[] = 'duration_days = :dur';
        $params[':dur'] = $newVal;
        $diffs[] = 'duration_days: ' . ($promo['duration_days'] ?? 'null') . ' -> ' . ($newVal ?? 'null');
    }
}

if (array_key_exists('max_uses', $body)) {
    $val = $body['max_uses'];
    if ($val === '' || $val === null) {
        $newVal = null;
    } else {
        $newVal = (int)$val;
        if ($newVal < 1 || $newVal > 1000000) fail('bad_max_uses', 'max_uses must be between 1 and 1,000,000, or null.');
    }
    if ((int)($promo['max_uses'] ?? 0) !== (int)($newVal ?? 0) || ($promo['max_uses'] === null) !== ($newVal === null)) {
        $set[] = 'max_uses = :mx';
        $params[':mx'] = $newVal;
        $diffs[] = 'max_uses: ' . ($promo['max_uses'] ?? 'null') . ' -> ' . ($newVal ?? 'null');
    }
}

if (array_key_exists('expires_at', $body)) {
    $val = $body['expires_at'];
    if ($val === '' || $val === null) {
        $newVal = null;
    } else {
        $ts = strtotime((string)$val);
        if ($ts === false) fail('bad_expires_at', 'expires_at must be a valid date.');
        $newVal = date('Y-m-d H:i:s', $ts);
    }
    $oldNorm = $promo['expires_at'] ? date('Y-m-d H:i:s', strtotime($promo['expires_at'])) : null;
    if ($oldNorm !== $newVal) {
        $set[] = 'expires_at = :exp';
        $params[':exp'] = $newVal;
        $diffs[] = 'expires_at: ' . ($oldNorm ?? 'null') . ' -> ' . ($newVal ?? 'null');
    }
}

if (array_key_exists('notes', $body)) {
    $newVal = clean_string((string)($body['notes'] ?? ''), 500);
    if ((string)($promo['notes'] ?? '') !== $newVal) {
        $set[] = 'notes = :nt';
        $params[':nt'] = $newVal;
        $diffs[] = 'notes updated';
    }
}

if (array_key_exists('is_active', $body)) {
    $newVal = !empty($body['is_active']) ? 1 : 0;
    if ((int)$promo['is_active'] !== $newVal) {
        $set[] = 'is_active = :ia';
        $params[':ia'] = $newVal;
        $diffs[] = 'is_active: ' . ((int)$promo['is_active'] ? 'on' : 'off') . ' -> ' . ($newVal ? 'on' : 'off');
    }
}

if (!$set) fail('no_change', 'No editable fields supplied or no values changed.');

$sql = 'UPDATE promo_codes SET ' . implode(', ', $set) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Edited promo code',
    'promo',
    [
        'severity'     => 'info',
        'target_type'  => 'promo',
        'target_id'    => (string)$promo['code'],
        'target_label' => (string)$promo['code'],
        'before'       => '-',
        'after'        => implode(' * ', $diffs),
        'reason'       => clean_string((string)($body['reason'] ?? ''), 500) ?: null,
    ]
);

json_out([
    'ok'      => true,
    'changes' => $diffs,
    'message' => 'Updated ' . $promo['code'] . ': ' . implode(', ', $diffs),
]);
