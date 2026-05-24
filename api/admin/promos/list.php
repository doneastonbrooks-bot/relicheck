<?php
// GET /api/admin/promos/list.php
//   ?active_only=1  (default: 1; pass 0 to include inactive/expired)
//
// Returns the promo codes for the admin panel's apply-code modal and the
// Promo Codes view. Mirrors the existing /api/promo/list.php but lives
// under the admin namespace and reshapes the rows to match the JS.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$activeOnly = !isset($_GET['active_only']) || $_GET['active_only'] !== '0';

$sql = 'SELECT id, code, tier_key, duration_days, max_uses, uses_count,
               expires_at, notes, is_active, created_at
          FROM promo_codes';
if ($activeOnly) {
    $sql .= " WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
}
$sql .= ' ORDER BY created_at DESC';

$rows = db()->query($sql)->fetchAll();

$out = [];
foreach ($rows as $r) {
    $expired = !empty($r['expires_at']) && strtotime((string)$r['expires_at']) < time();
    // Map promo type into the same vocab the admin.html JS understands.
    // duration_days NULL = permanent membership. duration_days > 0 = trial-like extension.
    $type = $r['duration_days'] === null ? 'custom_access' : 'free_months';
    $out[] = [
        'id'           => (int)$r['id'],
        'code'         => $r['code'],
        'type'         => $type,
        'value'        => $r['duration_days'] === null ? 0 : (int)round(((int)$r['duration_days']) / 30), // approx months for label
        'duration_days'=> $r['duration_days'] === null ? null : (int)$r['duration_days'],
        'tier_key'     => $r['tier_key'],
        'usageLimit'   => $r['max_uses'] === null ? 0 : (int)$r['max_uses'],
        'used'         => (int)$r['uses_count'],
        'starts'       => substr((string)$r['created_at'], 0, 10),
        'expires'      => $r['expires_at'] ? substr((string)$r['expires_at'], 0, 10) : '-',
        'restrictPlans'=> [], // Phase 14 codes don't model plan restrictions; treated as all plans.
        'newOnly'      => false,
        'active'       => (bool)$r['is_active'] && !$expired,
        'notes'        => $r['notes'] ?? '',
    ];
}

json_out(['ok' => true, 'rows' => $out, 'count' => count($out)]);
