<?php
// GET /api/surveys/list.php[?include_archived=1]
// Returns the current user's surveys with metadata and response counts.
//
// Phase 37 added is_favorite, archived_at, folder_id, health_alpha_min,
// health_last_response_at. The new SELECT is the preferred path. If it throws
// (the migration has not run yet), we fall back to the original simple query
// so the dashboard never goes empty. Per the no-cross-cutting-changes rule.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$includeArchived = !empty($_GET['include_archived']);

$pdo = db();

// ---- Phase 37 path -------------------------------------------------------
try {
    $sql = 'SELECT s.id, s.slug, s.title, s.description, s.is_published,
                   s.settings, s.questions, s.created_at, s.updated_at,
                   s.is_favorite, s.archived_at, s.folder_id,
                   s.health_alpha_min, s.health_last_response_at,
                   (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS response_count
              FROM surveys s
             WHERE s.owner_id = :uid';
    if (!$includeArchived) {
        $sql .= ' AND s.archived_at IS NULL';
    }
    $sql .= ' ORDER BY s.is_favorite DESC, s.updated_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => (int)$user['id']]);

    $rows = [];
    while ($r = $stmt->fetch()) {
        $settings  = json_decode((string)$r['settings'], true) ?: [];
        $questions = json_decode((string)$r['questions'], true) ?: [];
        $rows[] = [
            'id'                      => (int)$r['id'],
            'slug'                    => $r['slug'],
            'title'                   => $r['title'],
            'description'             => $r['description'],
            'is_published'            => (bool)$r['is_published'],
            'settings'                => $settings,
            'questions'               => $questions,
            'item_count'              => is_array($questions) ? count($questions) : 0,
            'likert_count'            => is_array($questions) ? count(array_filter($questions, fn($q) => ($q['type'] ?? null) === 'likert')) : 0,
            'response_count'          => (int)$r['response_count'],
            'created_at'              => $r['created_at'],
            'updated_at'              => $r['updated_at'],
            'is_favorite'             => !!$r['is_favorite'],
            'archived_at'             => $r['archived_at'],
            'folder_id'               => $r['folder_id'] !== null ? (int)$r['folder_id'] : null,
            'health_alpha_min'        => $r['health_alpha_min'] !== null ? (float)$r['health_alpha_min'] : null,
            'health_last_response_at' => $r['health_last_response_at'],
        ];
    }
    json_out(['surveys' => $rows]);

} catch (Throwable $e) {
    // ---- Pre-migration fallback (the original query, unchanged) ---------
    $stmt = $pdo->prepare(
        'SELECT s.id, s.slug, s.title, s.description, s.is_published,
                s.settings, s.questions, s.created_at, s.updated_at,
                (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS response_count
           FROM surveys s
          WHERE s.owner_id = :uid
          ORDER BY s.updated_at DESC'
    );
    $stmt->execute([':uid' => $user['id']]);

    $rows = [];
    while ($r = $stmt->fetch()) {
        $settings  = json_decode((string)$r['settings'], true) ?: [];
        $questions = json_decode((string)$r['questions'], true) ?: [];
        $rows[] = [
            'id'             => (int)$r['id'],
            'slug'           => $r['slug'],
            'title'          => $r['title'],
            'description'    => $r['description'],
            'is_published'   => (bool)$r['is_published'],
            'settings'       => $settings,
            'questions'      => $questions,
            'item_count'     => is_array($questions) ? count($questions) : 0,
            'likert_count'   => is_array($questions) ? count(array_filter($questions, fn($q) => ($q['type'] ?? null) === 'likert')) : 0,
            'response_count' => (int)$r['response_count'],
            'created_at'     => $r['created_at'],
            'updated_at'     => $r['updated_at'],
        ];
    }

    json_out(['surveys' => $rows, 'note' => 'phase37_pending']);
}
