<?php
// POST /api/surveys/from_validated.php
// Body: { scale_key: string }
//
// Instantiates a survey from a validated-scale starter (see
// validated_scales.php). Mirrors duplicate.php's create-survey logic but
// pulls the seed from the validated catalog instead of the survey/template
// pool. Additive only: existing endpoints are untouched.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/validated_scales.php';

require_method('POST');
check_origin();
$user = require_auth();

$body    = read_json_body();
$scaleKey = (string)($body['scale_key'] ?? '');
if ($scaleKey === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $scaleKey)) {
    fail('bad_scale', 'Missing or invalid scale_key.', 400);
}

$catalog = validated_scales();
$scale = null;
foreach ($catalog as $row) {
    if (($row['key'] ?? '') === $scaleKey) { $scale = $row; break; }
}
if (!$scale) fail('not_found', 'Unknown scale_key.', 404);

$pdo = db();

// Tier checks.
$current = (int)$pdo->query(
    'SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . (int)$user['id']
)->fetch()['c'];
require_under_limit((int)$user['id'], 'max_surveys', $current);

$src       = $scale['survey'];
$title     = (string)($src['title']       ?? $scale['name']);
$desc      = (string)($src['description'] ?? '');
$settings  = is_array($src['settings']  ?? null) ? $src['settings']  : default_survey_settings();
$questions = is_array($src['questions'] ?? null) ? $src['questions'] : [];

// Stamp every question with a stable id, mirroring duplicate.php behavior.
foreach ($questions as &$q) {
    if (!isset($q['id']) || !is_string($q['id']) || $q['id'] === '') {
        $q['id'] = bin2hex(random_bytes(4));
    }
}
unset($q);

require_under_limit((int)$user['id'], 'max_questions_per_survey', 0, count($questions));

$slug = unique_survey_slug($pdo);

// Stash the validated-scale provenance inside settings so the survey
// "remembers" where it came from. Renderers can show the citation and the
// alpha target as a banner on the dashboard later.
$settings = array_merge($settings, [
    'validated_scale' => [
        'key'           => $scale['key'],
        'name'          => $scale['name'],
        'construct'     => $scale['construct'],
        'alpha_target'  => $scale['alpha_target'],
        'recommended_n' => $scale['recommended_n'],
        'citation'      => $scale['citation'],
        'license_note'  => $scale['license_note'],
    ],
]);

$stmt = $pdo->prepare(
    'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
     VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
);
$stmt->execute([
    ':uid'       => $user['id'],
    ':slug'      => $slug,
    ':title'     => clean_string($title, 255) ?: 'Untitled survey',
    ':desc'      => clean_string($desc, 4000),
    ':settings'  => json_encode($settings,  JSON_UNESCAPED_UNICODE),
    ':questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
]);
$id = (int)$pdo->lastInsertId();

json_out([
    'survey' => [
        'id'             => $id,
        'slug'           => $slug,
        'title'          => $title,
        'description'    => $desc,
        'is_published'   => false,
        'settings'       => $settings,
        'questions'      => $questions,
        'item_count'     => count($questions),
        'likert_count'   => count(array_filter($questions, fn($q) => ($q['type'] ?? '') === 'likert')),
        'response_count' => 0,
    ],
], 201);
