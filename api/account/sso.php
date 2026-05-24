<?php
// /api/account/sso.php  -  SSO config CRUD.
//
// Stub-level: this endpoint stores the SSO provider config but the actual
// SAML/OIDC login flow is a separate follow-up project. Saving here marks
// the workspace as "SSO configured" so the Account view can show status,
// but does not yet alter how sign-in works.
//
// GET                  -> current config (no secrets returned)
// POST  action=save    -> body { provider, display_name, issuer, audience,
//                                 sso_url, metadata_url, metadata_xml,
//                                 certificate, email_domain, enabled }
// POST  action=disable -> turns enabled off but keeps the row.
// POST  action=remove  -> deletes the row entirely.
//
// Tier-gated to Business + Enterprise plans (sso feature flag).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_tiers.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $allowed = tier_feature((int)$user['id'], 'sso');
    try {
        $stmt = $pdo->prepare('SELECT * FROM org_sso_config WHERE user_id = :u LIMIT 1');
        $stmt->execute([':u' => (int)$user['id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        fail('migration_pending', 'Phase 149 migration has not been applied yet.', 503);
    }
    if (!$row) {
        json_out(['ok' => true, 'feature_allowed' => $allowed, 'config' => null]);
    }
    json_out([
        'ok' => true,
        'feature_allowed' => $allowed,
        'config' => [
            'provider'     => (string)$row['provider'],
            'display_name' => (string)($row['display_name'] ?? ''),
            'issuer'       => (string)($row['issuer'] ?? ''),
            'audience'     => (string)($row['audience'] ?? ''),
            'sso_url'      => (string)($row['sso_url'] ?? ''),
            'metadata_url' => (string)($row['metadata_url'] ?? ''),
            'has_metadata_xml' => !empty($row['metadata_xml']),
            'has_certificate'  => !empty($row['certificate']),
            'email_domain' => (string)($row['email_domain'] ?? ''),
            'enabled'      => (int)$row['enabled'] === 1,
            'updated_at'   => (string)$row['updated_at'],
        ],
    ]);
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if (!tier_feature((int)$user['id'], 'sso')) {
    fail('plan_required', 'SSO is available on the Business and Enterprise plans.', 402, ['feature' => 'sso']);
}

if ($action === 'save') {
    $provider = clean_string((string)($body['provider'] ?? 'saml'), 32);
    if (!in_array($provider, ['google', 'okta', 'azure', 'saml', 'oidc'], true)) $provider = 'saml';
    $displayName = clean_string((string)($body['display_name'] ?? ''), 120);
    $issuer      = clean_string((string)($body['issuer']       ?? ''), 255);
    $audience    = clean_string((string)($body['audience']     ?? ''), 255);
    $ssoUrl      = clean_string((string)($body['sso_url']      ?? ''), 255);
    $metaUrl     = clean_string((string)($body['metadata_url'] ?? ''), 255);
    $metaXml     = (string)($body['metadata_xml'] ?? '');
    if (mb_strlen($metaXml) > 200000) fail('bad_input', 'Metadata XML too long.', 400);
    $cert        = (string)($body['certificate'] ?? '');
    if (mb_strlen($cert) > 16000) fail('bad_input', 'Certificate too long.', 400);
    $emailDomain = clean_string((string)($body['email_domain'] ?? ''), 120);
    $enabled     = !empty($body['enabled']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO org_sso_config
                (user_id, provider, display_name, issuer, audience, sso_url,
                 metadata_url, metadata_xml, certificate, email_domain, enabled)
             VALUES
                (:u, :p, :dn, :is, :au, :su, :mu, :mx, :ct, :ed, :en)
             ON DUPLICATE KEY UPDATE
                provider     = VALUES(provider),
                display_name = VALUES(display_name),
                issuer       = VALUES(issuer),
                audience     = VALUES(audience),
                sso_url      = VALUES(sso_url),
                metadata_url = VALUES(metadata_url),
                metadata_xml = VALUES(metadata_xml),
                certificate  = VALUES(certificate),
                email_domain = VALUES(email_domain),
                enabled      = VALUES(enabled)'
        );
        $stmt->execute([
            ':u'  => (int)$user['id'],
            ':p'  => $provider,
            ':dn' => $displayName,
            ':is' => $issuer,
            ':au' => $audience,
            ':su' => $ssoUrl,
            ':mu' => $metaUrl,
            ':mx' => $metaXml !== '' ? $metaXml : null,
            ':ct' => $cert    !== '' ? $cert    : null,
            ':ed' => $emailDomain,
            ':en' => $enabled,
        ]);
    } catch (Throwable $e) {
        fail('save_failed', 'Could not save SSO config: ' . $e->getMessage(), 500);
    }
    json_out(['ok' => true]);
}

if ($action === 'disable') {
    $pdo->prepare('UPDATE org_sso_config SET enabled = 0 WHERE user_id = :u')
        ->execute([':u' => (int)$user['id']]);
    json_out(['ok' => true]);
}

if ($action === 'remove') {
    $pdo->prepare('DELETE FROM org_sso_config WHERE user_id = :u')
        ->execute([':u' => (int)$user['id']]);
    json_out(['ok' => true]);
}

fail('bad_action', 'Unknown action. Use save, disable, or remove.');
