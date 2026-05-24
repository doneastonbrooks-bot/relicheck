<?php
// Mixed-Methods Studio shared helpers (DRAFT, Phase 155).
// Owns project lookup, ownership checks, and common JSON serializers used by
// every endpoint under api/mm/. Underscore-prefixed so it cannot be hit over
// HTTP (the .htaccess block in api/ rejects underscore files).

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_session.php';

/**
 * Load a Mixed-Methods project the current user owns, or fail with 404.
 */
function mm_require_project(PDO $pdo, int $userId, int $projectId): array
{
    $stmt = $pdo->prepare('SELECT * FROM mm_projects WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $projectId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail('mm_project_not_found', 'Mixed-Methods project not found.', 404);
    }
    return $row;
}

/**
 * Phase 181 — Multi-coder access. Load a project the current user EITHER
 * owns OR has been granted accepted coder access to via mm_project_coders.
 * Returns the project row plus a 'mm_role' key ('owner' or 'coder').
 *
 * Use for read-and-code endpoints (codebook viewing, response coding).
 * Keep mm_require_project for owner-only ops (delete, framing edits).
 */
function mm_require_project_or_coder(PDO $pdo, int $userId, int $projectId): array
{
    // Owner path first — cheap and gives us the full row.
    $stmt = $pdo->prepare('SELECT * FROM mm_projects WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $projectId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['mm_role'] = 'owner';
        return $row;
    }
    // Coder path — must have an accepted, non-revoked membership row.
    $stmt = $pdo->prepare(
        'SELECT p.* FROM mm_projects p ' .
        ' JOIN mm_project_coders c ON c.project_id = p.id ' .
        ' WHERE p.id = :id AND c.user_id = :uid AND c.revoked_at IS NULL ' .
        ' LIMIT 1'
    );
    $stmt->execute([':id' => $projectId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail('mm_project_not_found', 'Mixed-Methods project not found.', 404);
    }
    $row['mm_role'] = 'coder';
    return $row;
}

/**
 * Phase 181 — Random token generator for shareable-link invitations.
 * 32 bytes CSPRNG → 64-char hex. Matches mm_coder_invites.token VARCHAR(64).
 */
function mm_generate_invite_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Project shape for the front end. Stable across every list/get response.
 */
function mm_project_out(array $row): array
{
    // data_kinds / purposes are JSON arrays in the DB (phase 156b). Decode for
    // the front end. Tolerate the legacy single-value columns if they're still
    // around (e.g., during a partial migration).
    $dkRaw = $row['data_kinds'] ?? ($row['data_kind'] ?? null);
    $puRaw = $row['purposes']   ?? ($row['purpose']   ?? null);
    $dk = [];
    if (is_string($dkRaw) && $dkRaw !== '') {
        $dec = json_decode($dkRaw, true);
        if (is_array($dec)) $dk = $dec;
        elseif ($dkRaw !== '') $dk = [$dkRaw]; // legacy single value
    }
    $pu = [];
    if (is_string($puRaw) && $puRaw !== '') {
        $dec = json_decode($puRaw, true);
        if (is_array($dec)) $pu = $dec;
        elseif ($puRaw !== '') $pu = [$puRaw];
    }
    return [
        'id'                  => (int)$row['id'],
        'title'               => (string)$row['title'],
        'pathway'             => (string)$row['pathway'],
        'data_kinds'          => $dk,
        'purposes'            => $pu,
        'design_choice'       => isset($row['design_choice']) ? (string)($row['design_choice'] ?? '') : '',
        'wizard_completed_at' => isset($row['wizard_completed_at']) && $row['wizard_completed_at'] !== null ? (string)$row['wizard_completed_at'] : null,
        'survey_id'           => $row['survey_id']  !== null ? (string)$row['survey_id']  : null,
        'dataset_id'          => $row['dataset_id'] !== null ? (int)$row['dataset_id']   : null,
        'status'              => (string)$row['status'],
        'notes'               => $row['notes'] !== null ? (string)$row['notes'] : '',
        'created_at'          => (string)$row['created_at'],
        'updated_at'          => (string)$row['updated_at'],
    ];
}

/**
 * Insert a row into mm_text_responses and return the new id.
 *
 * As of Phase 157 the row optionally carries question_id_raw and
 * question_text_raw so per-question theme generation can group by question.
 * If the question columns are missing on the table (server hasn't run the
 * migration yet) we fall back to the original 6-column insert.
 */
function mm_insert_text_response(PDO $pdo, int $projectId, int $sourceId, ?string $respondentRef, ?string $groupValue, ?float $numericValue, string $text, ?string $questionIdRaw = null, ?string $questionTextRaw = null): int
{
    static $hasQuestionCols = null;
    if ($hasQuestionCols === null) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM mm_text_responses LIKE 'question_id_raw'");
            $hasQuestionCols = $col && $col->fetch();
        } catch (Throwable $e) { $hasQuestionCols = false; }
    }

    if ($hasQuestionCols) {
        $stmt = $pdo->prepare(
            'INSERT INTO mm_text_responses
             (project_id, source_id, respondent_ref, question_id_raw, question_text_raw, group_value, numeric_value, text)
             VALUES (:p, :s, :r, :qi, :qt, :g, :n, :t)'
        );
        $stmt->execute([
            ':p'  => $projectId,
            ':s'  => $sourceId,
            ':r'  => $respondentRef,
            ':qi' => $questionIdRaw,
            ':qt' => $questionTextRaw,
            ':g'  => $groupValue,
            ':n'  => $numericValue,
            ':t'  => $text,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO mm_text_responses (project_id, source_id, respondent_ref, group_value, numeric_value, text)
             VALUES (:p, :s, :r, :g, :n, :t)'
        );
        $stmt->execute([
            ':p' => $projectId,
            ':s' => $sourceId,
            ':r' => $respondentRef,
            ':g' => $groupValue,
            ':n' => $numericValue,
            ':t' => $text,
        ]);
    }
    return (int)$pdo->lastInsertId();
}

/**
 * Convenience: total response count for a project.
 */
function mm_response_count(PDO $pdo, int $projectId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mm_text_responses WHERE project_id = :p');
    $stmt->execute([':p' => $projectId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch up to $limit response rows for a project (most recent first).
 */
function mm_load_responses(PDO $pdo, int $projectId, int $limit = 500): array
{
    $limit = max(1, min(8000, $limit));
    // Try to include the Phase 157 question columns. Fall back gracefully.
    try {
        $stmt = $pdo->prepare(
            'SELECT id, respondent_ref, question_id_raw, question_text_raw, group_value, numeric_value, text
             FROM mm_text_responses WHERE project_id = :p ORDER BY id ASC LIMIT ' . $limit
        );
        $stmt->execute([':p' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            'SELECT id, respondent_ref, group_value, numeric_value, text
             FROM mm_text_responses WHERE project_id = :p ORDER BY id ASC LIMIT ' . $limit
        );
        $stmt->execute([':p' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
