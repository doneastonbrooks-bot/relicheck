<?php
// Shared dataset -> survey transformation. Mirrors the front-end
// datasetToSurveyShape() in app.html so persisted surveys render the same
// analytics as the in-memory dataset view.
//
// The transformation is intentionally additive: it never modifies an
// existing survey's questions array (the import endpoint requires a target
// survey whose questions are already shaped to match), and it never reaches
// into existing list/get/update endpoints.

declare(strict_types=1);

/**
 * Slugify a column name into a question id that is unique within a survey,
 * deterministic, and short enough to fit the 32-char clean_string limit
 * used by api/surveys/update.php. Mirrors the front-end pattern.
 */
function dts_question_id(int $index, string $rawName): string
{
    $base = preg_replace('/\s+/u', '_', trim($rawName));
    $base = preg_replace('/[^A-Za-z0-9_]/', '', (string)$base);
    if ($base === '' || $base === null) $base = 'c' . $index;
    if (strlen($base) > 24) $base = substr($base, 0, 24);
    return 'col_' . $index . '_' . $base;
}

/**
 * Build a fresh survey { questions } array from a dataset's column_meta.
 * Returns:
 *   [
 *     'questions' => [...survey questions...],
 *     'col_index' => [
 *       [ 'i' => int, 'qid' => string, 'type' => string, 'optionMap' => [value => index] ],
 *       ...
 *     ],
 *   ]
 *
 * `col_index` lets the caller iterate dataset rows and build correct answers.
 */
function dts_build_questions_and_index(array $columnMeta, array $rows, array $dsSettings = []): array
{
    $questions = [];
    $colIndex  = [];

    // Dataset-wide Likert anchor count (KNOWN_ISSUES.md §4 #4b). Baked onto
    // each synthesized Likert question as q.likertPoints so the downstream
    // _build_dataset.php transform emits v.anchor_count via the same
    // per-question→survey-default fallback the Survey Builder path uses.
    // Omit when absent/invalid — preserves the pre-#4 §4G/§4D skip behavior
    // for legacy uploads with no recorded likertPoints rather than guessing
    // a default. Future per-column override slot: column_meta.likertPoints
    // (not read today; placeholder for the heterogeneous-Likert ceiling
    // documented under §4b in KNOWN_ISSUES.md).
    $dsLikertPoints = null;
    if (array_key_exists('likertPoints', $dsSettings)) {
        $kp = $dsSettings['likertPoints'];
        if (is_numeric($kp)) {
            $kpInt = (int)$kp;
            if ($kpInt >= 2 && $kpInt <= 11) $dsLikertPoints = $kpInt;
        }
    }

    foreach ($columnMeta as $i => $c) {
        if (!is_array($c)) continue;
        $type = $c['type'] ?? 'ignore';
        if (!in_array($type, ['likert','single','open'], true)) continue; // skip ignore + multi
        $qid    = dts_question_id((int)$i, (string)($c['name'] ?? ''));
        $prompt = (string)($c['name'] ?? ('Column ' . ($i + 1)));

        if ($type === 'likert') {
            $q = [
                'id'       => $qid,
                'type'     => 'likert',
                'prompt'   => $prompt,
                'required' => false,
                'reverse'  => !empty($c['reverse']),
            ];
            // Propagate column_meta.construct into the survey question so the
            // downstream _build_dataset.php transform emits v.construct on the
            // engine's variables[] shape. Same trim-and-omit-when-empty contract
            // _build_dataset.php uses for q.construct. KNOWN_ISSUES.md §4 #1b.
            $construct = isset($c['construct']) ? trim((string)$c['construct']) : '';
            if ($construct !== '') $q['construct'] = $construct;
            // Anchor count: future per-column override (column_meta.likertPoints)
            // wins; falls back to dataset-wide settings.likertPoints. Omit when
            // both are absent.
            $colLikertPoints = null;
            if (array_key_exists('likertPoints', $c) && is_numeric($c['likertPoints'])) {
                $kpInt = (int)$c['likertPoints'];
                if ($kpInt >= 2 && $kpInt <= 11) $colLikertPoints = $kpInt;
            }
            $effLikertPoints = $colLikertPoints ?? $dsLikertPoints;
            if ($effLikertPoints !== null) $q['likertPoints'] = $effLikertPoints;
            $questions[] = $q;
            $colIndex[] = ['i' => (int)$i, 'qid' => $qid, 'type' => 'likert'];
        } elseif ($type === 'single') {
            // Auto-derive option list from unique values in the column.
            $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $v = $row[$i] ?? null;
                if ($v === null) continue;
                $s = trim((string)$v);
                if ($s === '') continue;
                if (!array_key_exists($s, $seen)) $seen[$s] = count($seen);
            }
            $questions[] = [
                'id'       => $qid,
                'type'     => 'single',
                'prompt'   => $prompt,
                'required' => false,
                'options'  => array_keys($seen),
            ];
            $colIndex[] = ['i' => (int)$i, 'qid' => $qid, 'type' => 'single', 'optionMap' => $seen];
        } else { // open
            $questions[] = [
                'id'       => $qid,
                'type'     => 'open',
                'prompt'   => $prompt,
                'required' => false,
            ];
            $colIndex[] = ['i' => (int)$i, 'qid' => $qid, 'type' => 'open'];
        }
    }

    return ['questions' => $questions, 'col_index' => $colIndex];
}

/**
 * Build a single answers map for one dataset row using a precomputed
 * column index. Returns [qid => value] in the same shape submit.php
 * persists for live submissions.
 */
function dts_row_to_answers(array $row, array $colIndex): array
{
    $answers = [];
    foreach ($colIndex as $ci) {
        $raw = $row[$ci['i']] ?? null;
        if ($ci['type'] === 'likert') {
            if ($raw === '' || $raw === null) continue;
            if (!is_numeric($raw)) continue;
            $answers[$ci['qid']] = (int)$raw;
        } elseif ($ci['type'] === 'single') {
            if ($raw === null) continue;
            $s = trim((string)$raw);
            if ($s === '') continue;
            if (array_key_exists($s, $ci['optionMap'])) {
                $answers[$ci['qid']] = (int)$ci['optionMap'][$s];
            }
        } elseif ($ci['type'] === 'open') {
            if ($raw === null) continue;
            $s = (string)$raw;
            if (trim($s) === '') continue;
            if (mb_strlen($s) > 5000) $s = mb_substr($s, 0, 5000);
            $answers[$ci['qid']] = $s;
        }
    }
    return $answers;
}

/**
 * For an existing survey (whose questions were originally generated from a
 * dataset), build a column index that maps dataset columns to the survey's
 * existing question ids. Matches by exact column.name == question.id, then
 * falls back to column.name == question.prompt.
 *
 * Returns the same col_index shape as dts_build_questions_and_index.
 */
function dts_match_existing_questions(array $columnMeta, array $rows, array $questions): array
{
    $byId     = [];
    $byPrompt = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $qid = (string)($q['id'] ?? '');
        if ($qid === '') continue;
        $byId[$qid] = $q;
        $prompt = (string)($q['prompt'] ?? '');
        if ($prompt !== '' && !isset($byPrompt[$prompt])) $byPrompt[$prompt] = $q;
    }

    $colIndex = [];
    foreach ($columnMeta as $i => $c) {
        if (!is_array($c)) continue;
        $type = $c['type'] ?? 'ignore';
        if (!in_array($type, ['likert','single','open'], true)) continue;
        $name = (string)($c['name'] ?? '');
        if ($name === '') continue;

        $autoQid = dts_question_id((int)$i, $name);
        $match   = $byId[$autoQid]
                ?? $byId[$name]
                ?? $byPrompt[$name]
                ?? null;
        if (!$match) continue;
        if (($match['type'] ?? '') !== $type) continue;

        $entry = ['i' => (int)$i, 'qid' => (string)$match['id'], 'type' => $type];
        if ($type === 'single') {
            // Build the option map from the question's existing options so
            // the index is consistent with what take.html submits.
            $opts = is_array($match['options'] ?? null) ? $match['options'] : [];
            $map = [];
            foreach ($opts as $idx => $opt) $map[(string)$opt] = (int)$idx;
            $entry['optionMap'] = $map;
        }
        $colIndex[] = $entry;
    }
    return $colIndex;
}
