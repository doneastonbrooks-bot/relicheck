<?php
// GET /api/promo/list.php   (admin only)
// Returns every code with its current usage count, sorted by newest first.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';

require_method('GET');
require_admin();

$rows = db()->query(
    'SELECT id, code, tier_key, duration_days, max_uses, uses_count, expires_at,
            notes, is_active, created_at
       FROM promo_codes
   ORDER BY created_at DESC'
)->fetchAll();

$out = array_map(function ($r) {
    return [
        'id'            => (int)$r['id'],
        'code'          => $r['code'],
        'tier_key'      => $r['tier_key'],
        'duration_days' => $r['duration_days'] === null ? null : (int)$r['duration_days'],
        'max_uses'      => $r['max_uses']      === null ? null : (int)$r['max_uses'],
        'uses_count'    => (int)$r['uses_count'],
        'expires_at'    => $r['expires_at'],
        'notes'         => $r['notes'],
        'is_active'     => (bool)$r['is_active'],
        'created_at'    => $r['created_at'],
    ];
}, $rows);

json_out(['ok' => true, 'codes' => $out]);
