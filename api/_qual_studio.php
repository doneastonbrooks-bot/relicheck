<?php
// Shared helpers for the Qualitative Analysis Studio.
// Include after _helpers.php + _session.php.

declare(strict_types=1);

/**
 * Add Contextual Lens columns to qual tables if they don't exist yet.
 * Idempotent — checks SHOW COLUMNS before each ALTER.
 */
function qual_ensure_cl_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $additions = [
        'qual_projects' => [
            'cl_analysis_purpose'      => 'TEXT NULL',
            'cl_population_context'    => 'TEXT NULL',
            'cl_analyst_positionality' => 'TEXT NULL',
            'cl_potential_misuse'      => 'TEXT NULL',
        ],
        'qual_codes' => [
            'cl_context'               => 'TEXT NULL',
            'cl_structural_framing'    => 'TEXT NULL',
            'cl_misinterpretation_risk'=> 'TEXT NULL',
        ],
        'qual_themes' => [
            'cl_context'               => 'TEXT NULL',
            'cl_group_variation'       => 'TEXT NULL',
            'cl_pattern_type'          => 'TEXT NULL',
            'cl_counter_story'         => 'TEXT NULL',
            'cl_structural_framing'    => 'TEXT NULL',
            'cl_action_caution'        => 'TEXT NULL',
        ],
    ];

    foreach ($additions as $table => $cols) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            continue; // table may not exist yet
        }
        foreach ($cols as $col => $def) {
            if (!in_array($col, $existing, true)) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                } catch (Throwable $e) {
                    error_log("qual_ensure_cl_columns [{$table}.{$col}]: " . $e->getMessage());
                }
            }
        }
    }
}

/**
 * Create Qual Studio tables if they don't exist yet.
 * Idempotent — safe to call on every request.
 */
function qual_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $sql = file_get_contents(__DIR__ . '/../db/schema_qual_studio.sql');
    // Execute each statement individually (PDO doesn't support multi-statement exec)
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') {
            try { $pdo->exec($stmt . ';'); } catch (Throwable $e) {
                error_log('qual_ensure_schema: ' . $e->getMessage());
            }
        }
    }
    $done = true;
}

/**
 * Load a qual_project row and verify it belongs to $uid (ownership check).
 * Exits with a 403/404 fail() if not found or not owned.
 */
function qual_require_project(PDO $pdo, int $uid, int $projectId): array
{
    qual_ensure_schema($pdo);
    qual_ensure_cl_columns($pdo);
    $s = $pdo->prepare(
        "SELECT * FROM qual_projects WHERE id = :id AND user_id = :uid AND status <> 'archived' LIMIT 1"
    );
    $s->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('not_found', 'Project not found.', 404);
    return $row;
}

/**
 * Load a qual_project row for $uid as either the project owner OR an accepted
 * second coder (has a row in qual_coder_invites with status='accepted' and
 * accepted_by=$uid). Use this in endpoints that second coders need to reach
 * (get-segments, get-codes, apply-code, remove-code).
 */
function qual_check_access(PDO $pdo, int $uid, int $projectId): array
{
    qual_ensure_schema($pdo);
    qual_ensure_cl_columns($pdo);

    // Owner path (fast, most common)
    $s = $pdo->prepare(
        "SELECT * FROM qual_projects WHERE id = :id AND user_id = :uid AND status <> 'archived' LIMIT 1"
    );
    $s->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // Accepted-coder path
    $s2 = $pdo->prepare(
        "SELECT qp.* FROM qual_coder_invites qi
         JOIN qual_projects qp ON qp.id = qi.project_id
         WHERE qi.project_id = :pid AND qi.accepted_by = :uid
           AND qi.status = 'accepted' AND qp.status <> 'archived'
         LIMIT 1"
    );
    $s2->execute([':pid' => $projectId, ':uid' => $uid]);
    $row2 = $s2->fetch(PDO::FETCH_ASSOC);
    if ($row2) return $row2;

    fail('not_found', 'Project not found.', 404);
}

/**
 * Log an action to qual_audit_trail (non-fatal).
 */
function qual_audit(
    PDO    $pdo,
    int    $projectId,
    int    $userId,
    string $action,
    string $objectType  = '',
    int    $objectId    = 0,
    string $objectName  = '',
    string $prevValue   = '',
    string $newValue    = '',
    string $memo        = ''
): void {
    try {
        $s = $pdo->prepare(
            'INSERT INTO qual_audit_trail
             (project_id,user_id,action,object_type,object_id,object_name,prev_value,new_value,memo)
             VALUES (:p,:u,:a,:ot,:oi,:on,:pv,:nv,:m)'
        );
        $s->execute([
            ':p'  => $projectId,
            ':u'  => $userId,
            ':a'  => $action,
            ':ot' => $objectType ?: null,
            ':oi' => $objectId   ?: null,
            ':on' => $objectName ?: null,
            ':pv' => $prevValue  ?: null,
            ':nv' => $newValue   ?: null,
            ':m'  => $memo       ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('qual_audit: ' . $e->getMessage());
    }
}

/**
 * Materialize open-ended columns from a linked dataset into qual_segments.
 * Called when a dataset is linked to a qual_document.
 * Idempotent: clears prior segments for this document before inserting.
 */
function qual_materialize_segments(PDO $pdo, int $projectId, int $documentId, int $datasetId): int
{
    // Load dataset rows and column_meta
    $ds = $pdo->prepare('SELECT column_meta, data FROM datasets WHERE id = :id LIMIT 1');
    $ds->execute([':id' => $datasetId]);
    $dsRow = $ds->fetch(PDO::FETCH_ASSOC);
    if (!$dsRow) return 0;

    $colMeta = $dsRow['column_meta'];
    $rawRows = $dsRow['data'];
    if (is_string($colMeta)) $colMeta = json_decode($colMeta, true);
    if (is_string($rawRows)) $rawRows = json_decode($rawRows, true);
    if (!is_array($colMeta) || !is_array($rawRows)) return 0;

    // Identify open-text columns.
    // Explicit qual_role (set by Column Setup in Qual Studio) takes precedence.
    // Fallback: analysis_type='open_ended'|'narrative' from the upload widget.
    // Legacy datasets only carry type='open'; apply the avgLen/distinct heuristic.
    $columns = array_values($colMeta);
    $openIdx = [];
    foreach ($columns as $i => $col) {
        $qualRole     = $col['qual_role'] ?? null;
        if ($qualRole === 'skip' || $qualRole === 'participant_id' || $qualRole === 'participant_info') continue;

        $analysisType = $col['analysis_type'] ?? '';
        $legacyType   = $col['type'] ?? '';
        $isExplicit   = $qualRole === 'open_ended';
        $isCanonical  = in_array($analysisType, ['open_ended', 'narrative'], true);
        $isLegacy     = !$isExplicit && !$isCanonical && $legacyType === 'open';
        if (!$isExplicit && !$isCanonical && !$isLegacy) continue;

        $texts    = array_column($rawRows, $i);
        $nonEmpty = array_filter($texts, fn($v) => trim((string)$v) !== '');
        if (!count($nonEmpty)) continue;

        if ($isLegacy) {
            // Heuristic guard: avg length > 20 AND distinct values > 12 = free text.
            $avgLen   = array_sum(array_map('strlen', $nonEmpty)) / count($nonEmpty);
            $distinct = count(array_unique($nonEmpty));
            if (!($avgLen > 20 && $distinct > 12)) continue;
        }

        $openIdx[] = ['idx' => $i, 'name' => $col['name'] ?? ('col_' . $i)];
    }
    if (!$openIdx) return 0;

    // Find participant_id column: explicit qual_role first, then name heuristic.
    $pidIdx = null;
    foreach ($columns as $i => $col) {
        if (($col['qual_role'] ?? '') === 'participant_id') { $pidIdx = $i; break; }
    }
    if ($pidIdx === null) {
        foreach ($columns as $i => $col) {
            $name = strtolower($col['name'] ?? '');
            if (in_array($name, ['id', 'respondent_id', 'participant_id', 'respondent', 'response_id'], true)) {
                $pidIdx = $i;
                break;
            }
        }
    }

    // Build metadata column list: participant_info role, or any non-open non-id non-skipped column.
    $metaCols = [];
    foreach ($columns as $i => $col) {
        if ($i === $pidIdx) continue;
        $qualRole = $col['qual_role'] ?? null;
        if ($qualRole === 'skip' || $qualRole === 'open_ended') continue;
        $type = $col['analysis_type'] ?? $col['type'] ?? '';
        if ($qualRole === null && in_array($type, ['open_ended', 'narrative', 'open'], true)) continue;
        $metaCols[$i] = $col['name'] ?? ('col_' . $i);
    }

    // Clear prior segments for this document
    $pdo->prepare('DELETE FROM qual_segments WHERE document_id = :d')->execute([':d' => $documentId]);

    $ins = $pdo->prepare(
        'INSERT INTO qual_segments
         (project_id,document_id,participant_id,question_ref,raw_text,word_count,seg_order,metadata_json)
         VALUES (:p,:d,:pid,:q,:t,:w,:o,:m)'
    );

    $order = 0;
    foreach ($rawRows as $row) {
        $row = array_values((array)$row);
        $pid = $pidIdx !== null ? (string)($row[$pidIdx] ?? '') : '';
        foreach ($openIdx as $col) {
            $text = trim((string)($row[$col['idx']] ?? ''));
            if ($text === '') continue;
            $meta = [];
            foreach ($metaCols as $mi => $mname) {
                $v = $row[$mi] ?? null;
                if ($v !== null && $v !== '') $meta[$mname] = $v;
            }
            $ins->execute([
                ':p'   => $projectId,
                ':d'   => $documentId,
                ':pid' => $pid !== '' ? $pid : null,
                ':q'   => $col['name'],
                ':t'   => $text,
                ':w'   => str_word_count($text),
                ':o'   => $order++,
                ':m'   => $meta ? json_encode($meta) : null,
            ]);
        }
    }
    return $order;
}
