<?php
// Shared transform: survey questions + response rows → analysis dataset.
// Safe to require_once from both responses-dataset.php and _studio_mount.php.
// Contains NO top-level side effects — only the function definition.

if (!function_exists('relicheck_survey_build_dataset')):

function relicheck_survey_build_dataset(string $title, array $questions, array $responses, array $settings = []): array
{
    // Wizards-driven scale assignments (strength-survey-wizard.php /
    // survey-wizard.php Step 3) persist into surveys.settings.scales as a
    // map keyed by variable name → { scale, direction, reverse }. The
    // Survey Builder UI's per-question q.construct field is the primary
    // source of truth; this map is the fallback for surveys built without
    // construct tags but with the wizard's scales step completed.
    // Field name `scale` from the wizard is emitted as `construct` on the
    // variable to match the Survey Builder path's contract (engine accepts
    // either `construct` or `scale`).
    $scaleMap = [];
    if (isset($settings['scales']) && is_array($settings['scales'])) {
        foreach ($settings['scales'] as $vname => $row) {
            if (!is_array($row)) continue;
            $s = is_string($row['scale'] ?? null) ? trim($row['scale']) : '';
            if ($s !== '') $scaleMap[(string)$vname] = $s;
        }
    }

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
            $vname = 'q_' . $qid;
            $var = [
                'name'   => $vname,
                'types'  => ['likert'],
                'label'  => $prompt,
                'values' => array_map(fn($v) => $v !== null ? (int)$v : null, $raw),
            ];
            // Precedence: q.construct (Survey Builder, per-question explicit
            // tag) wins over settings.scales (wizard, post-hoc bulk
            // assignment). Reasoning: if a survey was tagged in both places,
            // the per-question tag is closer to the item's intent and was
            // chosen at authoring time; the wizard runs over an existing
            // variable list and is the fallback when the Builder path
            // wasn't used. Reconsider if a workflow emerges where the
            // wizard intentionally overrides Builder tags.
            $eff = $construct !== '' ? $construct : ($scaleMap[$vname] ?? '');
            if ($eff !== '') $var['construct'] = $eff;
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
                $vname = 'q_' . $qid . '_r' . $ri;
                $var = [
                    'name'   => $vname,
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
                // Matrix-row precedence (three-level fallback): parent
                // q.construct wins → per-row wizard assignment
                // (settings.scales['q_<qid>_r<ri>']) → parent-question wizard
                // assignment (settings.scales['q_<qid>']). Reasoning:
                //   (1) Survey Builder's q.construct is authoritative (same
                //       rule as the Likert branch above).
                //   (2) The wizard's scales table iterates variables, so
                //       matrix sub-items appear as per-row rows; a user who
                //       tagged individual rows expects that to win.
                //   (3) Fallback to the parent's wizard tag covers the
                //       common case where a user tags only the matrix
                //       question itself (treating all rows as one scale),
                //       not each sub-row — mirrors the Survey Builder
                //       matrix-inheritance rule from commit a0d51bc.
                $eff = $construct !== '' ? $construct
                     : ($scaleMap[$vname] ?? $scaleMap['q_' . $qid] ?? '');
                if ($eff !== '') $var['construct'] = $eff;
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
