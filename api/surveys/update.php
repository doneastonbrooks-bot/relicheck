<?php
// PATCH /api/surveys/update.php
// Body: { id, title?, description?, settings?, questions?, is_published? }
// Owner-only. Only the fields present in the body are updated.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_webhooks.php';

require_method('PATCH', 'POST'); // POST also accepted for HTML form fallbacks
check_origin();
$user = require_auth();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid survey id.');

$pdo = db();
// Pull owner_id and the prior is_published flag so we can fire the
// survey.published webhook after the update if (and only if) this call
// flipped the flag from 0 to 1.
$stmt = $pdo->prepare('SELECT owner_id, is_published FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);
$wasPublished = (int)$row['is_published'] === 1;

$fields = [];
$params = [':id' => $id];

if (array_key_exists('title', $body)) {
    $title = clean_string($body['title'], 255);
    if ($title === '') $title = 'Untitled survey';
    $fields[] = 'title = :title';
    $params[':title'] = $title;
}
// Phase 154: editable share slug. Lowercase, dash/underscore-safe, 4-64 chars.
// Must be globally unique on the surveys table (the schema's UNIQUE KEY enforces).
if (array_key_exists('slug', $body)) {
    $slug = strtolower(trim((string)$body['slug']));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]{2,62})[a-z0-9]$/', $slug)) {
        fail('bad_slug', 'Slug must be 4-64 chars, lowercase letters, numbers, dash, or underscore. Cannot start or end with a separator.', 400);
    }
    // Uniqueness check that excludes this same row.
    $u = $pdo->prepare('SELECT id FROM surveys WHERE slug = :s AND id <> :id LIMIT 1');
    $u->execute([':s' => $slug, ':id' => $id]);
    if ($u->fetch()) fail('slug_taken', 'That URL identifier is already used by another survey. Pick another.', 409);
    $fields[] = 'slug = :slug';
    $params[':slug'] = $slug;
}
if (array_key_exists('description', $body)) {
    $fields[] = 'description = :description';
    $params[':description'] = clean_string($body['description'], 4000);
}
if (array_key_exists('settings', $body)) {
    if (!is_array($body['settings'])) fail('bad_settings', 'settings must be an object.');
    // Sanitize a few specific keys; pass the rest through
    $s = $body['settings'];
    if (isset($s['likertPoints'])) {
        $p = (int)$s['likertPoints'];
        if ($p < 2 || $p > 11) fail('bad_likert_points', 'likertPoints must be between 2 and 11.');
        $s['likertPoints'] = $p;
    }
    if (isset($s['likertLow']))  $s['likertLow']  = clean_string((string)$s['likertLow'], 80);
    if (isset($s['likertHigh'])) $s['likertHigh'] = clean_string((string)$s['likertHigh'], 80);
    if (isset($s['thankYou']))   $s['thankYou']   = clean_string((string)$s['thankYou'], 1000);
    $fields[] = 'settings = :settings';
    $params[':settings'] = json_encode($s, JSON_UNESCAPED_UNICODE);
}
if (array_key_exists('questions', $body)) {
    if (!is_array($body['questions'])) fail('bad_questions', 'questions must be an array.');
    // Light sanitation; trust the front-end shape but clamp lengths
    $clean = [];
    foreach ($body['questions'] as $q) {
        if (!is_array($q)) continue;
        $type = $q['type'] ?? null;
        if (!in_array($type, ['likert','single','multi','open'], true)) continue;
        $entry = [
            'id'       => clean_string((string)($q['id'] ?? ''), 32),
            'type'     => $type,
            'prompt'   => clean_string((string)($q['prompt'] ?? ''), 4000),
            'required' => !empty($q['required']),
        ];
        if ($type === 'likert') {
            $entry['reverse'] = !empty($q['reverse']);
            // Optional per-question Likert overrides
            if (isset($q['likertPoints'])) {
                $p = (int)$q['likertPoints'];
                if ($p >= 2 && $p <= 11) $entry['likertPoints'] = $p;
            }
            if (isset($q['likertLow']))  $entry['likertLow']  = clean_string((string)$q['likertLow'],  80);
            if (isset($q['likertHigh'])) $entry['likertHigh'] = clean_string((string)$q['likertHigh'], 80);
        }
        // Optional skip-logic rule. We accept it from any tier (so existing
        // rules survive a downgrade), but the front-end editor is hidden
        // unless the user is on Pro+.
        if (isset($q['showIf']) && is_array($q['showIf'])) {
            $si = $q['showIf'];
            $opOk = in_array($si['op'] ?? 'equals', ['equals','not_equals'], true);
            if ($opOk && !empty($si['questionId']) && array_key_exists('value', $si)) {
                $entry['showIf'] = [
                    'questionId' => clean_string((string)$si['questionId'], 32),
                    'op'         => (string)$si['op'],
                    'value'      => $si['value'],
                ];
            }
        }
        if ($type === 'single' || $type === 'multi') {
            $opts = [];
            if (isset($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $o) $opts[] = clean_string((string)$o, 500);
            }
            $entry['options'] = $opts;
        }
        $clean[] = $entry;
    }
    require_under_limit((int)$user['id'], 'max_questions_per_survey', 0, count($clean));
    $fields[] = 'questions = :questions';
    $params[':questions'] = json_encode($clean);
}
if (array_key_exists('is_published', $body)) {
    $newPub = !empty($body['is_published']) ? 1 : 0;
    $fields[] = 'is_published = :pub';
    $params[':pub'] = $newPub;
    // First-time publish: stamp published_at = NOW(). Subsequent re-publish
    // events leave the original timestamp alone. Wrapped behind a flag check
    // so installs without the published_at column (pre Phase 36) still work.
    if ($newPub === 1 && !$wasPublished) {
        try {
            $pdo->prepare(
                'UPDATE surveys SET published_at = NOW() WHERE id = :id AND published_at IS NULL'
            )->execute([':id' => $id]);
        } catch (Throwable $e) {
            // Column doesn't exist on this install; ignore.
        }
    }
}

if (!$fields) {
    fail('nothing_to_update', 'No updatable fields were sent.');
}

$sql = 'UPDATE surveys SET ' . implode(', ', $fields) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

// Return the fresh row
$stmt = $pdo->prepare(
    'SELECT id, slug, title, description, settings, questions, is_published, updated_at
       FROM surveys WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$r = $stmt->fetch();

/* Fire the survey.published webhook if this call took the survey from
   unpublished to published. Wrapped so any failure is invisible to the API
   response shape. */
$nowPublished = (bool)$r['is_published'];
if ($nowPublished && !$wasPublished) {
    try {
        webhooks_fire('survey.published', (int)$user['id'], [
            'survey_id'    => (int)$r['id'],
            'survey_title' => (string)$r['title'],
            'survey_slug'  => (string)$r['slug'],
            'survey_url'   => 'https://relichecksurvey.com/app.html#survey/' . (int)$r['id'],
            'share_url'    => 'https://relichecksurvey.com/s/' . (string)$r['slug'],
            'published_at' => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        // Swallow.
    }
    // Fire the dispatcher event so the customer also gets a "Survey Is Live"
    // email through the new email system. Wrapped so a mailer hiccup never
    // affects the publish action or the outbound webhook above.
    try {
        if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
            require_once __DIR__ . '/../_email_dispatcher.php';
            relicheck_email_dispatch('survey.published', [
                'user_id'    => (int)$user['id'],
                'account_id' => (int)$user['id'],
                'idempotency_entity_id' => 'survey-published:' . (int)$r['id'],
                'payload'    => [
                    'first_name'         => trim(explode(' ', (string)($user['name'] ?? ''))[0] ?: 'there'),
                    'survey_name'        => (string)$r['title'],
                    'survey_id'          => (string)$r['id'],
                    'public_survey_link' => 'https://relichecksurvey.com/s/' . (string)$r['slug'],
                ],
            ]);
        }
    } catch (Throwable $e) {
        error_log('[relicheck] survey.published dispatch failed: ' . $e->getMessage());
    }
}

json_out([
    'survey' => [
        'id'           => (int)$r['id'],
        'slug'         => $r['slug'],
        'title'        => $r['title'],
        'description'  => $r['description'],
        'is_published' => (bool)$r['is_published'],
        'settings'     => json_decode((string)$r['settings'], true) ?: [],
        'questions'    => json_decode((string)$r['questions'], true) ?: [],
        'updated_at'   => $r['updated_at'],
    ],
]);
