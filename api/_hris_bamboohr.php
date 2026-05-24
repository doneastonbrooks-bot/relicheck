<?php
// BambooHR provider.
//
// Auth:        API key (Basic auth) + subdomain.
// Docs:        https://documentation.bamboohr.com/reference
// Endpoint:    GET https://api.bamboohr.com/api/gateway.php/{subdomain}/v1/employees/directory
//              Returns { employees: [...] } with id, displayName, jobTitle,
//              department, supervisorId, workEmail, location, hireDate,
//              employmentStatus.
// Rate limit:  generous, no documented hard cap.
//
// Production note: real calls require a BambooHR account admin to mint
// an API key in Account > API Keys. The key inherits that admin's
// access; ReliCheck stores only the encrypted blob, never plaintext.

declare(strict_types=1);

function hris_bamboohr(): array
{
    return [
        'connect_label' => fn() => 'BambooHR',
        'auth_kind'     => fn() => 'api_key',

        'validate_credentials' => function (array $cred): bool {
            $sub = (string)($cred['subdomain'] ?? '');
            $key = (string)($cred['api_key'] ?? '');
            if ($sub === '' || $key === '') {
                throw new RuntimeException('BambooHR requires both subdomain and api_key.');
            }
            // In live mode, do a HEAD against /v1/employees/directory.
            // For the scaffolding we accept any non-empty pair so the
            // connection lifecycle is testable end-to-end without real
            // BambooHR credentials.
            if (defined('HRIS_LIVE_MODE') && HRIS_LIVE_MODE) {
                $url = 'https://api.bamboohr.com/api/gateway.php/' . urlencode($sub) . '/v1/employees/directory';
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD => $key . ':x',
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_TIMEOUT => 15,
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code !== 200) throw new RuntimeException('BambooHR rejected the credentials (HTTP ' . $code . ').');
            }
            return true;
        },

        'fetch_employees' => function (array $cred): array {
            if (!defined('HRIS_LIVE_MODE') || !HRIS_LIVE_MODE) {
                // Scaffolding mode: return an empty list so downstream
                // sync logic can be exercised without real credentials.
                return [];
            }
            $sub = (string)($cred['subdomain'] ?? '');
            $key = (string)($cred['api_key'] ?? '');
            $url = 'https://api.bamboohr.com/api/gateway.php/' . urlencode($sub) . '/v1/employees/directory';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $key . ':x',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) throw new RuntimeException('BambooHR returned HTTP ' . $code);
            $j = json_decode((string)$body, true);
            $rows = is_array($j['employees'] ?? null) ? $j['employees'] : [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'remote_id'         => (string)($r['id'] ?? ''),
                    'email'             => $r['workEmail']        ?? null,
                    'full_name'         => $r['displayName']      ?? null,
                    'job_title'         => $r['jobTitle']         ?? null,
                    'department'        => $r['department']       ?? null,
                    'manager_remote_id' => isset($r['supervisorId']) ? (string)$r['supervisorId'] : null,
                    'location'          => $r['location']         ?? null,
                    'start_date'        => $r['hireDate']         ?? null,
                    'status'            => isset($r['employmentStatus'])
                        ? (strtolower((string)$r['employmentStatus']) === 'active' ? 'active' : 'terminated')
                        : null,
                    'raw'               => $r,
                ];
            }
            return $out;
        },
    ];
}
