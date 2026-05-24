<?php
// Workday provider.
//
// Auth:    OAuth 2.0 (client credentials grant) against the customer's
//          tenant. Customer's Workday admin must register an Integration
//          System User (ISU) and grant the "Get_Workers" web service
//          calling permission.
// Docs:    https://community.workday.com/sites/default/files/file-hosting/restapi/index.html
// Endpoint: POST https://{tenant}.workday.com/ccx/oauth2/{tenant}/token
//          GET  https://wd2-impl-services1.workday.com/ccx/api/staffing/v6/{tenant}/workers
//
// Production note: Workday integrations are weeks-to-months of partner
// enablement work on the customer side. Most ReliCheck customers will
// be better served by going through an HRIS aggregator (Merge / Finch
// / Kombo) rather than direct Workday.

declare(strict_types=1);

function hris_workday(): array
{
    return [
        'connect_label' => fn() => 'Workday',
        'auth_kind'     => fn() => 'oauth',

        'validate_credentials' => function (array $cred): bool {
            $required = ['tenant', 'client_id', 'client_secret', 'token_endpoint'];
            foreach ($required as $k) {
                if (empty($cred[$k])) {
                    throw new RuntimeException('Workday requires: ' . implode(', ', $required));
                }
            }
            // In live mode, hit the token endpoint with a client_credentials grant
            // to confirm the secret resolves to a token. Scaffolding mode just
            // checks structural presence.
            if (defined('HRIS_LIVE_MODE') && HRIS_LIVE_MODE) {
                $body = http_build_query([
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $cred['client_id'],
                    'client_secret' => $cred['client_secret'],
                ]);
                $ch = curl_init((string)$cred['token_endpoint']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                    CURLOPT_TIMEOUT => 15,
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code !== 200) throw new RuntimeException('Workday rejected client credentials (HTTP ' . $code . ').');
            }
            return true;
        },

        'fetch_employees' => function (array $cred): array {
            if (!defined('HRIS_LIVE_MODE') || !HRIS_LIVE_MODE) return [];
            // Real implementation:
            // 1. Get an access token via client_credentials.
            // 2. Page through GET {host}/ccx/api/staffing/v6/{tenant}/workers
            //    using the standard ?limit/&offset pattern.
            // 3. Each worker carries primaryWorkEmail, primaryJob.title,
            //    organization, manager.id, primaryWorkLocation, startDate,
            //    active boolean.
            // The shape below documents the mapping for a real impl.
            // For now, return an empty list so the rest of the pipeline
            // can be exercised without live Workday access.
            return [];
        },
    ];
}
