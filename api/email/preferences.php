<?php
// GET /api/email/preferences.php   -> returns current preferences for the
//                                     signed-in customer + which groups are
//                                     required and cannot be disabled.
// POST /api/email/preferences.php  -> body: { group: "marketing_newsletter", enabled: false, digest_mode: "daily" }
//
// Required groups (account, billing, privacy, terms, support, required
// service-delivery, required membership) cannot be disabled and the endpoint
// will refuse such requests with 400.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('GET', 'POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in to manage email preferences.', 401);
$uid = (int)$user['id'];

// The full set of preference groups we expose to customers, with their
// required/optional status. Required groups always send.
$groups = [
    // required (no toggle)
    'account_security'        => ['label' => 'Account and security emails', 'required' => true],
    'billing'                 => ['label' => 'Billing emails',              'required' => true],
    'membership'              => ['label' => 'Membership emails',           'required' => true],
    'privacy_security'        => ['label' => 'Privacy and security alerts', 'required' => true],
    'terms_legal'             => ['label' => 'Terms and legal notices',     'required' => true],
    'support_ticket'          => ['label' => 'Support ticket emails',       'required' => true],
    'required_service'        => ['label' => 'Required service delivery',   'required' => true],
    // optional (toggle)
    'survey_activity'         => ['label' => 'Survey activity notifications','required' => false],
    'report_followup'         => ['label' => 'Report and insight follow-ups','required' => false],
    'sales_followup'          => ['label' => 'Sales and demo follow-ups',    'required' => false],
    'product_updates'         => ['label' => 'Product updates',              'required' => false],
    'marketing_newsletter'    => ['label' => 'Newsletter',                   'required' => false],
    'promotional_campaigns'   => ['label' => 'Promotional campaigns',        'required' => false],
    'educational_content'     => ['label' => 'Educational content',          'required' => false],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $g = clean_string((string)($body['group'] ?? ''), 64);
    if (!isset($groups[$g])) fail('bad_group', 'Unknown preference group.');
    if ($groups[$g]['required']) fail('group_required', 'This preference is required and cannot be disabled.');

    $enabled = !empty($body['enabled']) ? 1 : 0;
    $digest  = (string)($body['digest_mode'] ?? 'immediate');
    if (!in_array($digest, ['immediate', 'daily', 'weekly'], true)) $digest = 'immediate';

    $sql = 'INSERT INTO email_preferences (user_id, preference_group, is_enabled, digest_mode, updated_by)
            VALUES (:u, :g, :e, :d, "user")
            ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled),
                                    digest_mode = VALUES(digest_mode),
                                    updated_by = "user"';
    db()->prepare($sql)->execute([':u' => $uid, ':g' => $g, ':e' => $enabled, ':d' => $digest]);

    relicheck_email_audit($uid, 'preference.update', 'email_preferences', null,
        null, ['group' => $g, 'enabled' => (bool)$enabled, 'digest_mode' => $digest]);

    json_out(['ok' => true, 'group' => $g, 'enabled' => (bool)$enabled, 'digest_mode' => $digest]);
}

// GET
$st = db()->prepare('SELECT preference_group, is_enabled, digest_mode FROM email_preferences WHERE user_id = :u');
$st->execute([':u' => $uid]);
$rows = $st->fetchAll();
$by_group = [];
foreach ($rows as $r) {
    $by_group[(string)$r['preference_group']] = [
        'enabled'     => (int)$r['is_enabled'] === 1,
        'digest_mode' => (string)$r['digest_mode'],
    ];
}

$out = [];
foreach ($groups as $key => $meta) {
    $out[] = [
        'group'       => $key,
        'label'       => $meta['label'],
        'required'    => $meta['required'],
        'enabled'     => $by_group[$key]['enabled']     ?? true,
        'digest_mode' => $by_group[$key]['digest_mode'] ?? 'immediate',
    ];
}

json_out(['ok' => true, 'preferences' => $out]);
