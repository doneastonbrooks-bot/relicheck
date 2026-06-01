<?php
// POST /api/surveys/deploy-config.php
// Owner-only. Persists the deployment workspace configuration blob to
// surveys.settings.deployment so it survives page refreshes and can be
// enforced server-side (close date, one-per-person, etc.).
//
// Body: {
//   id: int,
//   config: {
//     audience:  { type, segments[] },
//     access:    { type, onePerPerson },
//     identity:  string|null,
//     channels:  string[],
//     schedule:  { launchNow, launchDate, closeDate, timezone, targetResponses, gracePeriod, reopenable },
//     reminder:  string|null,
//     branding:  { title, orgName, intro, estimatedTime, contact, thankYou, showBranding }
//   }
// }
//
// Returns: { ok: true, savedAt: <unix> }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body   = read_json_body();
$id     = isset($body['id']) ? (int)$body['id'] : 0;
$config = isset($body['config']) && is_array($body['config']) ? $body['config'] : null;

if ($id <= 0)          fail('bad_id',     'Missing or invalid id.',     400);
if ($config === null)  fail('bad_config', 'Missing config object.',     400);

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, owner_id, settings FROM surveys WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row  = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row)                                       fail('not_found', 'Survey not found.',           404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

// ── Normalise the config blob ─────────────────────────────────
$audienceTypes = ['open', 'private', 'roster', 'domain', 'panel'];
$accessTypes   = ['open', 'private', 'password', 'domain', 'roster'];
$identityModes = ['anonymous', 'confidential', 'identified', 'completion'];
$channelKeys   = ['link', 'email', 'embed', 'qr', 'lms', 'api'];
$reminderKeys  = ['none', 'manual', 'scheduled', 'nonrespondent', 'partial', 'final'];

$cfgIn  = $config;
$audIn  = is_array($cfgIn['audience'] ?? null) ? $cfgIn['audience'] : [];
$accIn  = is_array($cfgIn['access']   ?? null) ? $cfgIn['access']   : [];
$schIn  = is_array($cfgIn['schedule'] ?? null) ? $cfgIn['schedule'] : [];
$brIn   = is_array($cfgIn['branding'] ?? null) ? $cfgIn['branding'] : [];

$audType   = is_string($audIn['type']    ?? null) && in_array($audIn['type'], $audienceTypes, true) ? $audIn['type'] : null;
$accType   = is_string($accIn['type']    ?? null) && in_array($accIn['type'], $accessTypes,   true) ? $accIn['type'] : null;
$identity  = is_string($cfgIn['identity'] ?? null) && in_array($cfgIn['identity'], $identityModes, true) ? $cfgIn['identity'] : null;
$reminder  = is_string($cfgIn['reminder'] ?? null) && in_array($cfgIn['reminder'], $reminderKeys, true) ? $cfgIn['reminder'] : null;

$segs = [];
if (is_array($audIn['segments'] ?? null)) {
    foreach ($audIn['segments'] as $s) if (is_string($s)) $segs[] = clean_string($s, 40);
}
$channels = [];
if (is_array($cfgIn['channels'] ?? null)) {
    foreach ($cfgIn['channels'] as $c) if (in_array($c, $channelKeys, true)) $channels[] = $c;
}
$channels = array_values(array_unique($channels));

$clean = [
    'audience' => [
        'type'     => $audType,
        'segments' => array_values(array_unique($segs)),
    ],
    'access' => [
        'type'         => $accType,
        'onePerPerson' => !empty($accIn['onePerPerson']),
    ],
    'identity' => $identity,
    'channels' => $channels,
    'schedule' => [
        'launchNow'        => !empty($schIn['launchNow']),
        'launchDate'       => is_string($schIn['launchDate'] ?? null) ? clean_string($schIn['launchDate'], 32) : '',
        'closeDate'        => is_string($schIn['closeDate']  ?? null) ? clean_string($schIn['closeDate'],  32) : '',
        'timezone'         => is_string($schIn['timezone']   ?? null) ? clean_string($schIn['timezone'],   64) : '',
        'targetResponses'  => isset($schIn['targetResponses']) && $schIn['targetResponses'] !== '' ? (int)$schIn['targetResponses'] : null,
        'gracePeriod'      => !empty($schIn['gracePeriod']),
        'reopenable'       => !empty($schIn['reopenable']),
    ],
    'reminder' => $reminder,
    'branding' => [
        'title'         => is_string($brIn['title']         ?? null) ? clean_string($brIn['title'],         200)  : '',
        'orgName'       => is_string($brIn['orgName']       ?? null) ? clean_string($brIn['orgName'],       200)  : '',
        'intro'         => is_string($brIn['intro']         ?? null) ? clean_string($brIn['intro'],         4000) : '',
        'estimatedTime' => is_string($brIn['estimatedTime'] ?? null) ? clean_string($brIn['estimatedTime'], 60)   : '',
        'contact'       => is_string($brIn['contact']       ?? null) ? clean_string($brIn['contact'],       200)  : '',
        'thankYou'      => is_string($brIn['thankYou']      ?? null) ? clean_string($brIn['thankYou'],      2000) : '',
        'showBranding'  => !array_key_exists('showBranding', $brIn) ? true : (bool)$brIn['showBranding'],
    ],
    'savedAt' => time(),
];

// ── Merge into settings ───────────────────────────────────────
$settings = json_decode((string)$row['settings'], true) ?: [];
$settings['deployment'] = $clean;

$upd = $pdo->prepare('UPDATE surveys SET settings = :s, updated_at = NOW() WHERE id = :id');
$upd->execute([
    ':s'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
    ':id' => $id,
]);

json_out(['ok' => true, 'savedAt' => $clean['savedAt']]);
