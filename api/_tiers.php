<?php
// Central tier definitions and limit-enforcement helpers.
// One source of truth: change limits or feature flags here, every endpoint follows.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_helpers.php';

const TIER_FREE         = 'free';
const TIER_RESEARCHER   = 'researcher';
const TIER_PROFESSIONAL = 'professional';
const TIER_BUSINESS     = 'business';

/**
 * Tier definitions. INT_MAX (PHP_INT_MAX) means "no practical limit".
 */
function tier_catalog(): array
{
    return [
        TIER_FREE => [
            'name'        => 'Free',
            'price_monthly_cents' => 0,
            'price_annual_cents'  => 0,
            'rank'        => 0,
            'limits' => [
                'max_surveys'                 => 1,
                'max_responses_per_survey'    => 50,
                'max_datasets'                => 1,
                'max_rows_per_dataset'        => 200,
                'max_questions_per_survey'    => 25,
                'max_tests'                   => 1,
                'max_test_responses_per_test' => 50,
                'max_panels'                  => 1,
            ],
            'features' => [
                'data_upload'         => true,    // available, but limited rows
                'skip_logic'          => false,
                'anonymous_mode'      => false,
                'group_rollups'       => false,
                'random_assignment'   => false,
                'manager_dashboard'   => false,
                'hris'                => false,
                'team_sharing'        => 0,
                'api_access'          => false,
                'custom_domain'       => false,
                'watermark_reports'   => true,
                'remove_branding'     => false,
                'custom_thank_you'    => false,
                'priority_support'    => false,
            ],
        ],
        TIER_RESEARCHER => [
            'name'        => 'Researcher',
            'price_monthly_cents' => 1900,
            'price_annual_cents'  => 19000,
            'rank'        => 1,
            'limits' => [
                'max_surveys'                 => PHP_INT_MAX,
                'max_responses_per_survey'    => 5000,
                'max_datasets'                => PHP_INT_MAX,
                'max_rows_per_dataset'        => 5000,
                'max_questions_per_survey'    => 100,
                'max_tests'                   => PHP_INT_MAX,
                'max_test_responses_per_test' => 5000,
                'max_panels'                  => PHP_INT_MAX,
            ],
            'features' => [
                'data_upload'         => true,
                // Skip logic / branching is foundational for any serious
                // research instrument (eligibility screening, branched
                // protocols, conditional follow-ups), so it ships on
                // the Researcher tier rather than gated to Professional.
                'skip_logic'          => true,
                // Anonymous mode (k-anonymity suppression on rollups) is
                // an IRB and HR fundamental, so it ships on Researcher.
                'anonymous_mode'      => true,
                // Per-group reliability rollups (section, instructor,
                // cohort, department) are a Researcher-tier need for
                // education evaluation and applied research.
                'group_rollups'       => true,
                // Random assignment with quotas is core research
                // experiment infrastructure, so it ships on Researcher.
                'random_assignment'   => true,
                'manager_dashboard'   => false,
                'hris'                => false,
                'team_sharing'        => 0,
                'api_access'          => false,
                'custom_domain'       => false,
                'watermark_reports'   => false,
                'remove_branding'     => false,
                'custom_thank_you'    => true,
                'priority_support'    => false,
            ],
        ],
        TIER_PROFESSIONAL => [
            'name'        => 'Professional',
            'price_monthly_cents' => 4900,
            'price_annual_cents'  => 49000,
            'rank'        => 2,
            'limits' => [
                'max_surveys'                 => PHP_INT_MAX,
                'max_responses_per_survey'    => 25000,
                'max_datasets'                => PHP_INT_MAX,
                'max_rows_per_dataset'        => 25000,
                'max_questions_per_survey'    => 200,
                'max_tests'                   => PHP_INT_MAX,
                'max_test_responses_per_test' => 25000,
                'max_panels'                  => PHP_INT_MAX,
            ],
            'features' => [
                'data_upload'         => true,
                'skip_logic'          => true,
                'anonymous_mode'      => true,
                'group_rollups'       => true,
                'random_assignment'   => true,
                'manager_dashboard'   => true,
                'hris'                => false,
                'team_sharing'        => 3,
                'api_access'          => false,
                'custom_domain'       => false,
                'watermark_reports'   => false,
                'remove_branding'     => true,
                'custom_thank_you'    => true,
                'priority_support'    => true,
            ],
        ],
        TIER_BUSINESS => [
            'name'        => 'Business',
            'price_monthly_cents' => 9900,
            'price_annual_cents'  => 99000,
            'rank'        => 3,
            'limits' => [
                'max_surveys'                 => PHP_INT_MAX,
                'max_responses_per_survey'    => 100000,
                'max_datasets'                => PHP_INT_MAX,
                'max_rows_per_dataset'        => 50000,  // also our hard schema limit
                'max_questions_per_survey'    => 500,
                'max_tests'                   => PHP_INT_MAX,
                'max_test_responses_per_test' => 100000,
                'max_panels'                  => PHP_INT_MAX,
            ],
            'features' => [
                'data_upload'         => true,
                'skip_logic'          => true,
                'anonymous_mode'      => true,
                'group_rollups'       => true,
                'random_assignment'   => true,
                'manager_dashboard'   => true,
                // HRIS integration is staged but not enabled for launch.
                // The provider scaffolding (BambooHR, Workday, Rippling)
                // returns empty when HRIS_LIVE_MODE is undefined, and there
                // is no in-app UI to manage connections yet. Keep `false`
                // here until both the live-mode wiring AND a real
                // settings panel ship. Flipping this to true silently
                // exposes a feature that looks connected but pulls no data.
                'hris'                => false,
                'team_sharing'        => 10,
                'api_access'          => true,
                'custom_domain'       => true,
                'watermark_reports'   => false,
                'remove_branding'     => true,
                'custom_thank_you'    => true,
                'priority_support'    => true,
            ],
        ],
    ];
}

function tier_known(string $tier): bool
{
    return array_key_exists($tier, tier_catalog());
}

function tier_for_user(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $stmt = db()->prepare('SELECT tier, tier_expires_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    $tier = ($row && tier_known((string)$row['tier'])) ? (string)$row['tier'] : TIER_FREE;
    // If a paid tier has expired, fall back to free.
    if ($tier !== TIER_FREE && !empty($row['tier_expires_at'])) {
        if (strtotime((string)$row['tier_expires_at'] . ' UTC') < time()) {
            $tier = TIER_FREE;
        }
    }
    $catalog = tier_catalog();
    $cache[$userId] = [
        'tier'             => $tier,
        'tier_label'       => $catalog[$tier]['name'],
        'tier_expires_at'  => $row['tier_expires_at'] ?? null,
        'limits'           => $catalog[$tier]['limits'],
        'features'         => $catalog[$tier]['features'],
        'rank'             => $catalog[$tier]['rank'],
    ];
    return $cache[$userId];
}

function tier_user_usage(int $userId): array
{
    $pdo = db();
    $surveys = (int)$pdo->query(
        'SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . $userId
    )->fetch()['c'];
    $datasets = (int)$pdo->query(
        'SELECT COUNT(*) AS c FROM datasets WHERE owner_id = ' . $userId
    )->fetch()['c'];
    return [
        'surveys'  => $surveys,
        'datasets' => $datasets,
    ];
}

/**
 * Throw a 402 with an upgrade-flavored message if the user has hit the limit.
 */
function require_under_limit(int $userId, string $limit, int $current, ?int $about_to_add = 1): void
{
    $info = tier_for_user($userId);
    $cap = $info['limits'][$limit] ?? PHP_INT_MAX;
    if ($current + ($about_to_add ?? 1) > $cap) {
        require_once __DIR__ . '/_helpers.php';
        $msgs = [
            'max_surveys'              => "You've reached your {$info['tier_label']} plan's survey limit ({$cap}).",
            'max_responses_per_survey' => "This survey has reached your {$info['tier_label']} plan's response limit ({$cap}).",
            'max_datasets'             => "You've reached your {$info['tier_label']} plan's dataset limit ({$cap}).",
            'max_rows_per_dataset'     => "This dataset exceeds your {$info['tier_label']} plan's row limit ({$cap}).",
            'max_questions_per_survey' => "This survey would exceed your {$info['tier_label']} plan's question limit ({$cap}).",
            'max_tests'                => "You've reached your {$info['tier_label']} plan's test limit ({$cap}).",
            'max_test_responses_per_test' => "This test has reached your {$info['tier_label']} plan's response limit ({$cap}).",
            'max_panels'               => "You've reached your {$info['tier_label']} plan's panel limit ({$cap}).",
        ];
        $message = $msgs[$limit] ?? "You've reached a plan limit ({$limit} <= {$cap}).";
        fail('plan_limit', $message . ' Upgrade your plan to continue.', 402, [
            'limit_name' => $limit,
            'limit'      => $cap,
            'current'    => $current,
            'tier'       => $info['tier'],
        ]);
    }
}

function tier_feature(int $userId, string $flag): bool
{
    $info = tier_for_user($userId);
    return !empty($info['features'][$flag]);
}

function set_user_tier(int $userId, string $tier, ?string $expiresAt = null, ?string $reason = 'admin', ?string $sourceRef = null): void
{
    if (!tier_known($tier)) throw new InvalidArgumentException("Unknown tier: $tier");
    $pdo = db();

    // Be safe to call from inside a caller-managed transaction (e.g. promo
    // redemption opens its own FOR UPDATE transaction and then calls us).
    // Nested beginTransaction() throws on PDO MySQL by default, so we only
    // own the transaction lifecycle when no transaction is active.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        // Snapshot previous tier for the audit row
        $prev = $pdo->prepare('SELECT tier FROM users WHERE id = :id');
        $prev->execute([':id' => $userId]);
        $row = $prev->fetch();
        $fromTier = $row ? (string)$row['tier'] : null;

        $pdo->prepare('UPDATE users SET tier = :t, tier_expires_at = :e, tier_changed_at = NOW() WHERE id = :id')
            ->execute([':t' => $tier, ':e' => $expiresAt, ':id' => $userId]);

        $pdo->prepare(
            'INSERT INTO tier_changes (user_id, from_tier, to_tier, expires_at, reason, source_ref)
             VALUES (:u, :f, :t, :e, :r, :s)'
        )->execute([
            ':u' => $userId, ':f' => $fromTier, ':t' => $tier, ':e' => $expiresAt,
            ':r' => $reason, ':s' => $sourceRef,
        ]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
