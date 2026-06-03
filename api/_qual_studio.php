<?php
// Shared helpers for the Qualitative Analysis Studio.
// Include after _helpers.php + _session.php.

declare(strict_types=1);

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
 * Load a qual_project row and verify it belongs to $uid.
 * Exits with a 403/404 fail() if not found or not owned.
 */
function qual_require_project(PDO $pdo, int $uid, int $projectId): array
{
    qual_ensure_schema($pdo);
    $s = $pdo->prepare(
        "SELECT * FROM qual_projects WHERE id = :id AND user_id = :uid AND status <> 'archived' LIMIT 1"
    );
    $s->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('not_found', 'Project not found.', 404);
    return $row;
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

    // Identify open-text columns using same heuristic as MM link-dataset.php
    $columns = array_values($colMeta);
    $openIdx = [];
    foreach ($columns as $i => $col) {
        $type = $col['analysis_type'] ?? $col['type'] ?? '';
        if ($type !== 'open') continue;
        // Heuristic: avg length > 20 AND distinct values > 12 = free text
        $texts    = array_column($rawRows, $i);
        $nonEmpty = array_filter($texts, fn($v) => trim((string)$v) !== '');
        if (!count($nonEmpty)) continue;
        $avgLen   = array_sum(array_map('strlen', $nonEmpty)) / count($nonEmpty);
        $distinct = count(array_unique($nonEmpty));
        if ($avgLen > 20 && $distinct > 12) {
            $openIdx[] = ['idx' => $i, 'name' => $col['name'] ?? ('col_' . $i)];
        }
    }
    if (!$openIdx) return 0;

    // Find participant_id column (first column named id/respondent/participant)
    $pidIdx = null;
    foreach ($columns as $i => $col) {
        $name = strtolower($col['name'] ?? '');
        if (in_array($name, ['id', 'respondent_id', 'participant_id', 'respondent', 'response_id'], true)) {
            $pidIdx = $i;
            break;
        }
    }

    // Build metadata column list (non-open, non-id columns = demographics)
    $metaCols = [];
    foreach ($columns as $i => $col) {
        if ($i === $pidIdx) continue;
        $type = $col['analysis_type'] ?? $col['type'] ?? '';
        if ($type === 'open') continue;
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
