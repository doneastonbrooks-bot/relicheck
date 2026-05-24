<?php
// GET /api/v1/me - returns the user that owns the bearer token.
// Useful for clients to verify their token is valid before doing real work.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_api_auth.php';
require_once __DIR__ . '/../_tiers.php';

require_method('GET');
$user = require_api_token();

$tier = tier_for_user((int)$user['id']);

json_out([
    'user' => [
        'id'    => $user['id'],
        'email' => $user['email'],
        'name'  => $user['name'],
    ],
    'tier' => [
        'tier'     => $tier['tier'],
        'label'    => $tier['tier_label'],
        'features' => $tier['features'],
    ],
    'rate_limit' => [
        'requests_per_minute' => API_TOKEN_RATE_LIMIT_PER_MIN,
    ],
]);
