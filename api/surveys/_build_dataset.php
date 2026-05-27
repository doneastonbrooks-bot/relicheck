<?php
// Shared transform: survey questions + response rows → analysis dataset.
// Safe to require_once from both responses-dataset.php and _studio_mount.php.
// Contains NO top-level side effects — only the function definition.

if (!function_exists('relicheck_survey_build_dataset')):

function relicheck_survey_build_dataset(string $title, array $questions, array $responses): array
{
    $variables = [];

    foreach ($questions as $q) {
        $qid         = (string)($q['id']          ?? '');
        $type        = (string)($q['type']         ?? 'open');
        $builderType = (string)($q['_builderType'] ?? '');
        $prompt      = (string)($q['prompt']       ?? '');
        if ($qid === '') continue;

        // Construct/scale assignment from the Survey Builder. Likert items
        // (including matrix sub-items, which inherit the parent question's
        // construct) carry this through so the engine can group them into
        // scales for §4A Validity, §4B Construct Alignment, and §4E Scale
        // Structure. Field name `construct` matches q.construct verbatim;
        // engine accepts either `construct` or `scale`.
        $construct = is_string($q['construct'] ?? null) ? trim($q['construct']) : '';

        // Collect one raw answer per response (null if missing)
        $raw = array_map(fn($r) => $r['answers'][$qid] ?? null, $responses);

        if ($type === 'likert') {
            $var = [
                'name'   => 'q_' . $qid,
                'types'  => ['likert'],
                'label'  => $prompt,
                'values' => array_map(fn($v) => $v !== null ? (int)$v : null, $raw),
            ];
            if ($construct !== '') $var['construct'] = $construct;
            $variables[] = $var;

        } elseif ($type === 'single') {
            $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
            $variables[] = [
                'name'   => 'q_' . $qid,
                'types'  => ['categorical'],
                'label'  => $prompt,
                'values' => array_map(function ($v) use ($opts) {
                    if ($v === null) return null;
                    $idx = (int)$v;
                    return isset($opts[$idx]) ? (string)$opts[$idx] : null;
                }, $raw),
            ];

        } elseif ($type === 'multi') {
            $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
            foreach ($opts as $idx => $optLabel) {
                $variables[] = [
                    'name'   => 'q_' . $qid . '_opt' . $idx,
                    'types'  => ['categorical'],
                    'label'  => $prompt . ' — ' . $optLabel,
                    'values' => array_map(function ($v) use ($idx) {
                        if ($v === null) return null;
                        $arr = is_array($v) ? $v
                             : (is_string($v) ? (json_decode($v, true) ?: []) : []);
                        return (is_array($arr) && in_array($idx, $arr, false)) ? 1 : 0;
                    }, $raw),
                ];
            }

        } elseif ($type === 'open' && $builderType === 'rating') {
            $variables[] = [
                'name'   => 'q_' . $qid,
                'types'  => ['numeric'],
                'label'  => $prompt,
                'values' => array_map(fn($v) => ($v !== null && $v !== '') ? (float)$v : null, $raw),
            ];

        } elseif ($type === 'open' && $builderType === 'slider') {
            $variables[] = [
                'name'   => 'q_' . $qid,
                'types'  => ['numeric'],
                'label'  => $prompt,
                'values' => array_map(fn($v) => ($v !== null && $v !== '') ? (float)$v : null, $raw),
            ];

        } elseif ($type === 'open' && $builderType === 'matrix') {
            $rows = is_array($q['matrixRows'] ?? null) ? $q['matrixRows'] : [];
            foreach ($rows as $ri => $rowLabel) {
                $var = [
                    'name'   => 'q_' . $qid . '_r' . $ri,
                    'types'  => ['likert'],
                    'label'  => $prompt . ' — ' . $rowLabel,
                    'values' => array_map(function ($v) use ($ri) {
                        if ($v === null || $v === '') return null;
                        $p = is_array($v) ? $v : json_decode((string)$v, true);
                        if (!is_array($p)) return null;
                        $col = $p[(string)$ri] ?? $p[$ri] ?? null;
                        return $col !== null ? (int)$col + 1 : null; // 1-based for likert engines
                    }, $raw),
                ];
                if ($construct !== '') $var['construct'] = $construct;
                $variables[] = $var;
            }

        } elseif ($type === 'open' && $builderType === 'ranking') {
            $items = is_array($q['rankingItems'] ?? null) ? $q['rankingItems'] : [];
            foreach ($items as $ii => $itemLabel) {
                $variables[] = [
                    'name'   => 'q_' . $qid . '_item' . $ii,
                    'types'  => ['numeric'],
                    'label'  => $prompt . ' — ' . $itemLabel . ' (rank)',
                    'values' => array_map(function ($v) use ($ii) {
                        if ($v === null || $v === '') return null;
                        $p = is_array($v) ? $v : json_decode((string)$v, true);
                        if (!is_array($p)) return null;
                        $pos = array_search($ii, $p, false);
                        return $pos !== false ? (int)$pos + 1 : null; // 1-based rank
                    }, $raw),
                ];
            }

        } elseif ($type === 'open' && $builderType === 'priority') {
            $items = is_array($q['priorityItems'] ?? null) ? $q['priorityItems'] : [];
            foreach ($items as $ii => $itemLabel) {
                $variables[] = [
                    'name'   => 'q_' . $qid . '_item' . $ii,
                    'types'  => ['numeric'],
                    'label'  => $prompt . ' — ' . $itemLabel . ' (points)',
                    'values' => array_map(function ($v) use ($ii) {
                        if ($v === null || $v === '') return null;
                        $p = is_array($v) ? $v : json_decode((string)$v, true);
                        if (!is_array($p)) return null;
                        return isset($p[(string)$ii]) ? (int)$p[(string)$ii]
                             : (isset($p[$ii])        ? (int)$p[$ii] : 0);
                    }, $raw),
                ];
            }

        } else {
            // Plain open-ended (or unknown future _builderType)
            $variables[] = [
                'name'   => 'q_' . $qid,
                'types'  => ['open'],
                'label'  => $prompt,
                'values' => array_map(fn($v) => $v !== null ? (string)$v : '', $raw),
            ];
        }
    }

    // Timestamp variable for time-series views
    if (!empty($responses)) {
        $variables[] = [
            'name'   => '_submitted_at',
            'types'  => ['datetime'],
            'label'  => 'Submitted at',
            'values' => array_map(fn($r) => $r['submitted_at'], $responses),
        ];
    }

    return [
        'source'    => $title,
        'variables' => $variables,
        'rowCount'  => count($responses),
    ];
}

endif;
