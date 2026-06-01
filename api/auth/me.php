<?php
// GET /api/auth/me - returns the current signed-in user, or 401.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');

$user = current_user();
if (!$user) {
    json_out(['authenticated' => false], 200);
}

// Pull the user's verified custom domain (if any) so the frontend can
// generate share URLs from the customer's vanity hostname instead of the
// platform's. Only surfaces a domain whose status is 'verified'.
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT custom_domain, custom_domain_status FROM users WHERE id = :id'
);
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();
$verifiedDomain = ($row && $row['custom_domain_status'] === 'verified')
    ? $row['custom_domain']
    : null;

// Beta-cohort entitlement. True when the user has redeemed any promo code
// flagged is_beta_cohort = 1 (e.g., BETA-MMSTUDIO-2026A). The front-end
// gate (MM.draftFlag) honours this to auto-unlock invited testers so they
// do not have to keep visiting with ?draft=1. Degrades safely to false if
// the schema has not been migrated on this database.
$betaAccessMm = false;
try {
    $tblPromo = (bool)$pdo->query("SHOW TABLES LIKE 'promo_codes'")->fetchColumn();
    $tblRedem = (bool)$pdo->query("SHOW TABLES LIKE 'promo_redemptions'")->fetchColumn();
    if ($tblPromo && $tblRedem) {
        $hasFlag = (bool)$pdo->query("SHOW COLUMNS FROM promo_codes LIKE 'is_beta_cohort'")->fetchColumn();
        if ($hasFlag) {
            $bStmt = $pdo->prepare(
                'SELECT 1 FROM promo_redemptions pr
                 INNER JOIN promo_codes pc ON pc.id = pr.code_id
                 WHERE pr.user_id = :uid AND pc.is_beta_cohort = 1
                 LIMIT 1'
            );
            $bStmt->execute([':uid' => (int)$user['id']]);
            $betaAccessMm = (bool)$bStmt->fetchColumn();
        }
    }
} catch (\Throwable $e) {
    // Schema not yet migrated on this database — leave $betaAccessMm = false.
}

json_out([
    'authenticated' => true,
    'user' => [
        'id'                   => (int)$user['id'],
        'email'                => $user['email'],
        'name'                 => $user['name'],
        'custom_domain'        => $verifiedDomain,
        'custom_domain_status' => $row['custom_domain_status'] ?? 'disabled',
        'beta_access_mm'       => $betaAccessMm,
    ],
]);
