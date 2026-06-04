<?php
// POST /api/dev/project-publish.php
// Body: { project_id }
// Generates a unique link_key, stores it in deployment_settings, marks the
// project status = 'published'. Returns the link_key so the client can
// surface the pending survey URL.
//
// Phase 3B: the link key is generated and persisted here. It is NOT yet
// active for respondents — that is Phase 3C. take.html will not serve
// responses until the project is explicitly opened for collection.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo  = db();
sds_ensure_schema($pdo);

$body = read_json_body();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$project = sds_require_project($pdo, (int)$user['id'], $projectId);

// "Publish anyway": the client sends override:true when the user has explicitly
// acknowledged publishing past unresolved launch blockers. It waives the SIRI gate
// below, and is recorded on the deployment so the override is never silent.
$override = !empty($body['override']);

// ReliCheck Basic (settings.tier = 'basic') is a low-stakes, free entry product
// capped at 25 responses. It shows its Basic SIRI score for guidance and as an
// upgrade hook, NOT as a hard launch gate — and it intentionally has no
// constructs/consent panel, which the full Launch Check would always block on.
// So the launch gate applies to FULL-tier projects only; Basic can publish once
// it has a score to show. Full SIRI's gate is unchanged.
$__settings = json_decode((string)($project['settings'] ?? ''), true) ?: [];
$isBasic = (($__settings['tier'] ?? '') === 'basic');

if (!$isBasic && !$override) {
    // Verify SIRI passed before generating a link (unless the user is publishing
    // anyway via an acknowledged override, handled above).
    $siriRow = $pdo->prepare('SELECT blocked FROM siri_reviews WHERE project_id = :id');
    $siriRow->execute([':id' => $projectId]);
    $siri = $siriRow->fetch(PDO::FETCH_ASSOC);

    if (!$siri) {
        fail('siri_required', 'The Launch Check (SIRI) must be run before publishing.', 422);
    }
    if ($siri['blocked']) {
        fail('siri_blocked', 'The Launch Check has unresolved blockers. Resolve them and re-run SIRI before publishing.', 422);
    }
}

// Generate a unique link key: 8 random lowercase alphanumeric characters.
// Loop until we find one not already in deployment_settings.
function generate_link_key(PDO $pdo): string {
    $chars = 'abcdefghijkmnpqrstuvwxyz23456789'; // remove ambiguous 0/o/1/l
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $key = '';
        for ($i = 0; $i < 8; $i++) $key .= $chars[random_int(0, strlen($chars) - 1)];
        // Check collision in deployment_settings JSON (simple substring check is
        // good enough for our scale; a dedicated column comes in a later migration)
        $chk = $pdo->prepare("SELECT COUNT(*) FROM deployment_settings WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.link_key')) = :k");
        $chk->execute([':k' => $key]);
        if ((int)$chk->fetchColumn() === 0) return $key;
    }
    // Extremely unlikely to reach here; widen key if it ever does.
    return bin2hex(random_bytes(6));
}

// Fetch existing deployment_settings so we don't clobber other fields.
$dsStmt = $pdo->prepare('SELECT settings FROM deployment_settings WHERE project_id = :id');
$dsStmt->execute([':id' => $projectId]);
$dsRow = $dsStmt->fetch(PDO::FETCH_ASSOC);
$ds = ($dsRow && $dsRow['settings'] !== null)
    ? json_decode((string)$dsRow['settings'], true)
    : [];

// Reuse existing link_key if already generated (idempotent).
$linkKey = (string)($ds['link_key'] ?? '');
if ($linkKey === '') {
    $linkKey = generate_link_key($pdo);
}

$ds['link_key']       = $linkKey;
$ds['published_at']   = date('Y-m-d H:i:s');
$ds['responses_open'] = false; // Phase 3C will flip this to true
if ($override) $ds['published_with_override'] = true; // acknowledged publish past blockers

$dsJson = json_encode($ds, JSON_UNESCAPED_UNICODE);

$pdo->prepare(
    'INSERT INTO deployment_settings (project_id, settings)
     VALUES (:pid, :settings)
     ON DUPLICATE KEY UPDATE settings = :settings2, updated_at = NOW()'
)->execute([':pid' => $projectId, ':settings' => $dsJson, ':settings2' => $dsJson]);

// Update project status to 'published'.
$pdo->prepare("UPDATE survey_projects SET status = 'published', updated_at = NOW() WHERE id = :id")
    ->execute([':id' => $projectId]);

$payload = sds_project_payload($pdo, $projectId);
json_out([
    'ok'       => true,
    'link_key' => $linkKey,
    'project'  => $payload['project'],
    'deployment' => ['link_key' => $linkKey, 'published_at' => $ds['published_at'], 'responses_open' => false],
]);
