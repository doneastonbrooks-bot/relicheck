<?php
// GET /api/account/tier.php
// Returns the current user's tier, limits, feature flags, and current usage.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('GET');
$user = require_auth();

$info  = tier_for_user((int)$user['id']);
$usage = tier_user_usage((int)$user['id']);

// Public-facing catalog (so the front-end can render Upgrade options without
// hard-coding prices in JS).
$pub = [];
foreach (tier_catalog() as $key => $t) {
    $pub[$key] = [
        'key'             => $key,
        'name'            => $t['name'],
        'rank'            => $t['rank'],
        'price_monthly'   => $t['price_monthly_cents'] / 100,
        'price_annual'    => $t['price_annual_cents']  / 100,
        'limits'          => $t['limits'],
        'features'        => $t['features'],
    ];
}

json_out([
    'tier'            => $info['tier'],
    'tier_label'      => $info['tier_label'],
    'tier_expires_at' => $info['tier_expires_at'],
    'limits'          => $info['limits'],
    'features'        => $info['features'],
    'usage'           => $usage,
    'catalog'         => $pub,
]);
