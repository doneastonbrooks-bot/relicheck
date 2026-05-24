<?php
// Rippling provider.
//
// Auth:     OAuth 2.0 authorization code flow.
// Docs:     https://developer.rippling.com/docs/rippling-api
// Endpoints:
//   Authorize: https://app.rippling.com/apps/PROD/oauth/authorize
//   Token:     https://app.rippling.com/api/o/token/
//   Workers:   GET https://api.rippling.com/platform/api/employees
//              Returns array of employee records with id, work_email,
//              first_name, last_name, title, department.name,
//              manager.id, work_location, start_date, status.
//
// Production note: Rippling requires Marketplace app registration before
// production OAuth works. Test apps are self-serve via the developer
// dashboard but require email verification with a Rippling tenant admin.

declare(strict_types=1);

function hris_rippling(): array
{
    return [
        'connect_label' => fn() => 'Rippling',
        'auth_kind'     => fn() => 'oauth',

        'validate_credentials' => function (array $cred): bool {
            if (empty($cred['access_token'])) {
                throw new RuntimeException('Rippling requires an access_token from the OAuth flow.');
            }
            if (defined('HRIS_LIVE_MODE') && HRIS_LIVE_MODE) {
                $ch = curl_init('https://api.rippling.com/platform/api/me');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cred['access_token']],
                    CURLOPT_TIMEOUT => 15,
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code !== 200) throw new RuntimeException('Rippling rejected the access token (HTTP ' . $code . ').');
            }
            return true;
        },

        'fetch_employees' => function (array $cred): array {
            if (!defined('HRIS_LIVE_MODE') || !HRIS_LIVE_MODE) return [];
            $ch = curl_init('https://api.rippling.com/platform/api/employees');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . (string)($cred['access_token'] ?? '')],
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) throw new RuntimeException('Rippling returned HTTP ' . $code);
            $rows = json_decode((string)$body, true);
            if (!is_array($rows)) return [];
            $out = [];
            foreach ($rows as $r) {
                $first = $r['first_name'] ?? '';
                $last  = $r['last_name']  ?? '';
                $name  = trim($first . ' ' . $last);
                $out[] = [
                    'remote_id'         => (string)($r['id'] ?? ''),
                    'email'             => $r['work_email']     ?? null,
                    'full_name'         => $name !== '' ? $name : null,
                    'job_title'         => $r['title']          ?? null,
                    'department'        => $r['department']['name'] ?? null,
                    'manager_remote_id' => isset($r['manager']['id']) ? (string)$r['manager']['id'] : null,
                    'location'          => $r['work_location']  ?? null,
                    'start_date'        => $r['start_date']     ?? null,
                    'status'            => $r['status']         ?? null,
                    'raw'               => $r,
                ];
            }
            return $out;
        },
    ];
}
