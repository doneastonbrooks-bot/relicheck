<?php
// POST /api/surveys/save.php
// Upsert endpoint for the survey builder (/survey-builder.php).
// -------------------------------------------------------------------
// Body: {
//   id?:        int      — omit (or 0) to create; supply to update
//   title:      string
//   questions:  array    — builder-native format (all 8 types)
// }
//
// Storage strategy
// ----------------
// The existing `questions` column is consumed by the analysis engines and
// enforces a strict type whitelist (likert / single / multi / open).
// The builder supports 8 types, so we:
//   1. Store the full builder payload in settings.builderQuestions (no type
//      restriction — this is what the builder reloads on edit).
//   2. Write a normalised version to `questions` so the analysis engines
//      can still pick up likert and open-ended items.
//
// Returns: { ok: true, id: N, created: bool }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_auth();

$body  = read_json_body();
$id    = isset($body['id']) ? (int)$body['id'] : 0;
$title = clean_string((string)($body['title'] ?? ''), 255);
if ($title === '') $title = 'Untitled survey';

$builderQuestions = isset($body['questions']) && is_array($body['questions'])
    ? $body['questions'] : [];

// ── Normalise builder questions → standard questions column format ──
$normalised = [];
foreach ($builderQuestions as $q) {
    if (!is_array($q)) continue;
    $type   = (string)($q['type'] ?? '');
    $text   = clean_string((string)($q['text'] ?? ''), 4000);
    $qid    = clean_string((string)($q['id'] ?? uniqid('q')), 32);

    switch ($type) {
        case 'likert':
            $opts   = is_array($q['opts']) ? $q['opts'] : [];
            $entry  = [
                'id'          => $qid,
                'type'        => 'likert',
                'prompt'      => $text,
                'required'    => false,
            ];
            if (!empty($opts['points'])) {
                $p = (int)$opts['points'];
                if ($p >= 2 && $p <= 11) $entry['likertPoints'] = $p;
            }
            if (!empty($opts['minLabel'])) $entry['likertLow']  = clean_string((string)$opts['minLabel'], 80);
            if (!empty($opts['maxLabel'])) $entry['likertHigh'] = clean_string((string)$opts['maxLabel'], 80);
            $normalised[] = $entry;
            break;

        case 'open':
            $normalised[] = [
                'id'       => $qid,
                'type'     => 'open',
                'prompt'   => $text,
                'required' => false,
            ];
            break;

        case 'multiple':
            $choices = [];
            if (!empty($q['choices']) && is_array($q['choices'])) {
                foreach ($q['choices'] as $c) $choices[] = clean_string((string)$c, 500);
            }
            $normalised[] = [
                'id'       => $qid,
                'type'     => 'multi',
                'prompt'   => $text,
                'required' => false,
                'options'  => $choices,
            ];
            break;

        case 'rating':
            $opts  = is_array($q['opts'] ?? null) ? $q['opts'] : [];
            $stars = max(3, min(10, (int)($opts['stars'] ?? 5)));
            $normalised[] = [
                'id'           => $qid,
                'type'         => 'open',
                'prompt'       => $text,
                'required'     => false,
                '_builderType' => 'rating',
                'ratingStars'  => $stars,
            ];
            break;

        case 'slider':
            $opts = is_array($q['opts'] ?? null) ? $q['opts'] : [];
            $normalised[] = [
                'id'           => $qid,
                'type'         => 'open',
                'prompt'       => $text,
                'required'     => false,
                '_builderType' => 'slider',
                'sliderMin'    => isset($opts['min'])      ? (float)$opts['min']  : 0,
                'sliderMax'    => isset($opts['max'])      ? (float)$opts['max']  : 100,
                'sliderStep'   => isset($opts['step'])     ? (float)$opts['step'] : 1,
                'sliderLow'    => isset($opts['minLabel']) ? clean_string((string)$opts['minLabel'], 80) : '',
                'sliderHigh'   => isset($opts['maxLabel']) ? clean_string((string)$opts['maxLabel'], 80) : '',
            ];
            break;

        case 'matrix':
            $rows = [];
            if (!empty($q['choices']) && is_array($q['choices'])) {
                foreach ($q['choices'] as $r) $rows[] = clean_string((string)$r, 500);
            }
            $opts = is_array($q['opts'] ?? null) ? $q['opts'] : [];
            $cols = [];
            if (!empty($opts['cols']) && is_array($opts['cols'])) {
                foreach ($opts['cols'] as $c) $cols[] = clean_string((string)$c, 200);
            }
            if (empty($cols)) $cols = ['Strongly Disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly Agree'];
            $normalised[] = [
                'id'           => $qid,
                'type'         => 'open',
                'prompt'       => $text,
                'required'     => false,
                '_builderType' => 'matrix',
                'matrixRows'   => $rows,
                'matrixCols'   => $cols,
            ];
            break;

        case 'ranking':
            $items = [];
            if (!empty($q['choices']) && is_array($q['choices'])) {
                foreach ($q['choices'] as $item) $items[] = clean_string((string)$item, 500);
            }
            $normalised[] = [
                'id'            => $qid,
                'type'          => 'open',
                'prompt'        => $text,
                'required'      => false,
                '_builderType'  => 'ranking',
                'rankingItems'  => $items,
            ];
            break;

        case 'priority':
            $items = [];
            if (!empty($q['choices']) && is_array($q['choices'])) {
                foreach ($q['choices'] as $item) $items[] = clean_string((string)$item, 500);
            }
            $opts  = is_array($q['opts'] ?? null) ? $q['opts'] : [];
            $total = max(10, min(1000, (int)($opts['total'] ?? 100)));
            $normalised[] = [
                'id'            => $qid,
                'type'          => 'open',
                'prompt'        => $text,
                'required'      => false,
                '_builderType'  => 'priority',
                'priorityItems' => $items,
                'priorityTotal' => $total,
            ];
            break;

        default:
            // Unknown future type — store as plain open so item count is preserved.
            $normalised[] = [
                'id'           => $qid,
                'type'         => 'open',
                'prompt'       => $text,
                'required'     => false,
                '_builderType' => $type,
            ];
            break;
    }
}

require_under_limit((int)$user['id'], 'max_questions_per_survey', 0, count($normalised));

$pdo = db();
$created = false;

if ($id > 0) {
    // ── UPDATE existing survey (owner check) ──────────────────────
    $stmt = $pdo->prepare('SELECT id, owner_id, settings FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)                                          fail('not_found',  'Survey not found.',           404);
    if ((int)$row['owner_id'] !== (int)$user['id'])    fail('forbidden',  'You do not own this survey.', 403);

    // Merge builderQuestions into existing settings
    $settings = json_decode((string)$row['settings'], true) ?: [];
    $settings['builderQuestions'] = $builderQuestions;

    $pdo->prepare(
        'UPDATE surveys SET title = :title, questions = :questions, settings = :settings, updated_at = NOW()
          WHERE id = :id'
    )->execute([
        ':title'     => $title,
        ':questions' => json_encode($normalised),
        ':settings'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ':id'        => $id,
    ]);

} else {
    // ── CREATE new survey ─────────────────────────────────────────
    $current = (int)$pdo->query(
        'SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . (int)$user['id']
    )->fetch()['c'];
    require_under_limit((int)$user['id'], 'max_surveys', $current);

    $slug     = unique_survey_slug($pdo);
    $settings = default_survey_settings();
    $settings['builderQuestions'] = $builderQuestions;

    $pdo->prepare(
        'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
         VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
    )->execute([
        ':uid'       => $user['id'],
        ':slug'      => $slug,
        ':title'     => $title,
        ':desc'      => '',
        ':settings'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ':questions' => json_encode($normalised),
    ]);
    $id      = (int)$pdo->lastInsertId();
    $created = true;

    // Fire first-survey email if applicable
    if ($current === 0) {
        try {
            if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
                require_once __DIR__ . '/../_email_dispatcher.php';
                relicheck_email_dispatch('survey.first_created', [
                    'user_id'               => (int)$user['id'],
                    'account_id'            => (int)$user['id'],
                    'idempotency_entity_id' => 'first-survey:' . (int)$user['id'],
                    'payload'               => [
                        'first_name'  => trim(explode(' ', (string)($user['name'] ?? ''))[0] ?: 'there'),
                        'survey_name' => $title,
                        'survey_id'   => (string)$id,
                    ],
                ]);
            }
        } catch (Throwable $e) {
            error_log('[relicheck] survey.first_created dispatch failed: ' . $e->getMessage());
        }
    }
}

json_out(['ok' => true, 'id' => $id, 'created' => $created], $created ? 201 : 200);
