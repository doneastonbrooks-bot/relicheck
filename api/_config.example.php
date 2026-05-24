<?php
// ReliCheck - database credentials.
//
// SETUP (one-time, on the Ionos server):
//   1. Copy this file to "_config.php" in the same folder.
//   2. Fill in the four values below from your Ionos database overview page.
//   3. Save. The .htaccess in this folder blocks any underscore-prefixed file
//      from being served directly, so credentials stay private.
//
// DO NOT commit _config.php to source control.

return [
    'db_host' => 'db5020390340.hosting-data.io',
    'db_port' => 3306,
    'db_name' => 'YOUR_DATABASE_NAME', // e.g. dbs1274347 - see Ionos database overview
    'db_user' => 'dbu1274347',
    'db_pass' => 'PASTE_PASSWORD_HERE',

    // App settings - adjust if you ever move the app under a subdirectory.
    'session_name'   => 'relicheck_sid',
    'session_secure' => true,   // Ionos serves https, leave true in production
    'session_samesite' => 'Lax',
    'session_lifetime_days' => 30,

    // Public site URL - used to build email links. No trailing slash.
    'site_url' => 'https://relichecksurvey.com',

    // SMTP settings - used for password reset emails.
    // Most Ionos mailbox setups: smtp.ionos.com on port 587 with STARTTLS.
    'smtp_host'     => 'smtp.ionos.com',
    'smtp_port'     => 587,
    'smtp_user'     => 'noreply@relichecksurvey.com',
    'smtp_pass'     => 'YOUR_MAILBOX_PASSWORD_HERE',
    'mail_from'     => 'noreply@relichecksurvey.com',
    'mail_from_name' => 'ReliCheck',

    // Optional: salt for IP hashing (so the same IP yields the same hash).
    // Pick any random string and keep it; do not change it later.
    'ip_salt' => 'change-me-to-something-random',

    // Google Sign-In - see Google Cloud Console > APIs & Services > Credentials.
    // Leave both blank to keep the Google button as a no-op stub.
    'google_client_id'     => '',
    'google_client_secret' => '',

    // Anthropic API key - used by the "Generate with AI" survey feature.
    // Get one from https://console.anthropic.com > Settings > API Keys.
    // Leave blank to hide the AI button entirely.
    'anthropic_api_key' => '',
    'anthropic_model'   => 'claude-sonnet-4-6',

    // Admin emails - users with these addresses can create and manage
    // promotional codes from inside Account settings. Anyone not on the
    // list sees only the "Redeem code" input.
    'admin_emails' => [
        'don.eastonbrooks@gmail.com',
    ],

    // Stripe billing - see Stripe Dashboard > Developers > API keys, plus
    // Products > [your products] for price IDs. Use test keys (sk_test_...,
    // pk_test_...) until you're ready to charge real money.
    'stripe_secret_key'      => '',
    'stripe_publishable_key' => '',
    'stripe_webhook_secret'  => '',  // Dashboard > Developers > Webhooks > [your endpoint] > Signing secret

    // Price IDs from each Product. Each tier has a monthly and an annual price.
    // (Free has no price; it's the default plan everyone starts on.)
    'stripe_price_researcher_monthly'   => '',
    'stripe_price_researcher_annual'    => '',
    'stripe_price_professional_monthly' => '',
    'stripe_price_professional_annual'  => '',
    'stripe_price_business_monthly'     => '',
    'stripe_price_business_annual'      => '',
];
