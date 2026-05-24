<?php
// GET  /api/mm/strength-check.php?project_id=N
//   Returns the latest stored check results for this project.
//
// POST /api/mm/strength-check.php
//   { project_id, include_ai?: bool }
//   Runs every check, writes mm_strength_checks rows, returns the full list.
//
// Each check returns: status (pass|fix|skip), severity (info|warn|high),
// title, message, fix_hint, details. Table-guarded so prior steps not yet
// run just produce skip rows, never 500s.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
function sc_table_exists(PDO $pdo, string $name): bool {
    try {
        $s = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return $s && $s->fetch() !== false;
    } catch (Throwable $e) { return false; }
}
function sc_column_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $s = $pdo->prepare("SHOW COLUMNS FROM " . $table . " LIKE :c");
        $s->execute([':c' => $col]);
        return $s->fetch() !== false;
    } catch (Throwable $e) { return false; }
}

function sc_check(string $key, string $title, string $status, string $severity, string $message, string $fix, array $details = []): array {
    return [
        'check_key'    => $key,
        'title'        => $title,
        'status'       => $status,         // pass | fix | skip
        'severity'     => $severity,       // info | warn | high
        'message'      => $message,
        'fix_hint'     => $fix,
        'details_json' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

// ----------------------------------------------------------------
// GET: return stored rows
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    if (!sc_table_exists($pdo, 'mm_strength_checks')) {
        json_out(['ok' => true, 'rows' => [], 'has_table' => false]);
    }
    $stmt = $pdo->prepare(
        'SELECT check_key, status, severity, title, message, fix_hint, details_json, ran_at
         FROM mm_strength_checks WHERE project_id = :p ORDER BY ran_at DESC, id DESC'
    );
    $stmt->execute([':p' => $projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_out(['ok' => true, 'rows' => $rows, 'has_table' => true]);
}

// ----------------------------------------------------------------
// POST: run all checks
// ----------------------------------------------------------------
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$includeAi = !empty($body['include_ai']);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if (!sc_table_exists($pdo, 'mm_strength_checks')) {
    fail('mm_no_strength_table', 'Phase 161 schema not yet installed. Run schema_phase161.sql first.', 500);
}

if ($includeAi) check_rate_limit('mm_strength_ai:user:' . $uid, 200, 3600);

$results = [];
$hasIntensityCol = sc_column_exists($pdo, 'mm_coded_responses', 'intensity');
$hasAnalysisTbl  = sc_table_exists($pdo, 'mm_analysis_results');
$hasJointTbl     = sc_table_exists($pdo, 'mm_joint_display_rows');
$hasIntegrationT = sc_table_exists($pdo, 'mm_integration_rows');

// ----------------------------------------------------------------
// Family 1: Sample and coverage checks
// ----------------------------------------------------------------
$totalResp = 0; $totalCoded = 0; $themeCount = 0;
try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM mm_text_responses WHERE project_id = :p');
    $s->execute([':p' => $projectId]);
    $totalResp = (int)$s->fetchColumn();
} catch (Throwable $e) {}
try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p');
    $s->execute([':p' => $projectId]);
    $totalCoded = (int)$s->fetchColumn();
} catch (Throwable $e) {}
try {
    $s = $pdo->prepare('SELECT COUNT(*) FROM mm_theme_categories WHERE project_id = :p');
    $s->execute([':p' => $projectId]);
    $themeCount = (int)$s->fetchColumn();
} catch (Throwable $e) {}

// Check: total responses N >= 30
if ($totalResp >= 30) {
    $results[] = sc_check('sample_total', 'Total response count', 'pass', 'info',
        $totalResp . ' responses on file.',
        'Larger samples support stronger generalization, but 30+ is the working floor.',
        ['total' => $totalResp]);
} else if ($totalResp > 0) {
    $results[] = sc_check('sample_total', 'Total response count', 'fix', 'warn',
        'Only ' . $totalResp . ' responses on file. Below the working floor of 30 for inferential tests.',
        'Consider this project descriptive rather than inferential; or collect more responses before publishing.',
        ['total' => $totalResp]);
} else {
    $results[] = sc_check('sample_total', 'Total response count', 'skip', 'info',
        'No responses uploaded yet.', 'Upload data on Step 1.', ['total' => 0]);
}

// Check: at least one coded response
if ($totalCoded === 0) {
    $results[] = sc_check('coding_coverage', 'Theme to response coverage', 'skip', 'info',
        'No responses are mapped to any theme yet.',
        'Run Apply themes to responses on the Categories tab.',
        ['coded_rows' => 0]);
} else {
    // Per-response coverage: how many responses got at least one usable code?
    $covered = 0; $offTopicOnly = 0; $uncoded = 0;
    try {
        $byResp = [];
        $stmt = $pdo->prepare('SELECT response_id, relevance FROM mm_coded_responses WHERE project_id = :p');
        $stmt->execute([':p' => $projectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['response_id'];
            $rel = (string)($row['relevance'] ?? 'usable');
            if (!isset($byResp[$rid])) $byResp[$rid] = ['usable' => 0, 'other' => 0];
            if ($rel === 'usable')  $byResp[$rid]['usable']++;
            else                     $byResp[$rid]['other']++;
        }
        $uncoded = max(0, $totalResp - count($byResp));
        foreach ($byResp as $rid => $cnt) {
            if ($cnt['usable'] > 0) $covered++;
            else $offTopicOnly++;
        }
    } catch (Throwable $e) { /* fall through */ }

    $coveragePct = $totalResp > 0 ? round(100.0 * $covered / $totalResp, 1) : 0.0;
    if ($coveragePct >= 70) {
        $results[] = sc_check('coding_coverage', 'Theme to response coverage', 'pass', 'info',
            $covered . ' of ' . $totalResp . ' responses (' . $coveragePct . '%) have at least one usable theme code.',
            ($uncoded > 0 ? ($uncoded . ' uncoded responses remain; review them in Step 9.') : 'All responses received at least one code.'),
            ['covered' => $covered, 'uncoded' => $uncoded, 'off_topic_only' => $offTopicOnly, 'percent' => $coveragePct]);
    } else if ($coveragePct >= 40) {
        $results[] = sc_check('coding_coverage', 'Theme to response coverage', 'fix', 'warn',
            'Only ' . $coveragePct . '% of responses received a usable theme code (' . $covered . ' of ' . $totalResp . ').',
            'Expand the theme set or rewrite theme definitions with more specific keywords. ' . $uncoded . ' responses are entirely uncoded.',
            ['covered' => $covered, 'uncoded' => $uncoded, 'off_topic_only' => $offTopicOnly, 'percent' => $coveragePct]);
    } else {
        $results[] = sc_check('coding_coverage', 'Theme to response coverage', 'fix', 'high',
            'Only ' . $coveragePct . '% of responses received a usable theme code. The theme set may not fit this data.',
            'Re-run the Builder on the Categories tab with new seeds, or regenerate per-question themes.',
            ['covered' => $covered, 'uncoded' => $uncoded, 'off_topic_only' => $offTopicOnly, 'percent' => $coveragePct]);
    }
}

// Check: every theme has at least 5 coded responses
try {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS n
         FROM mm_theme_categories c WHERE c.project_id = :p'
    );
    $stmt->execute([':p' => $projectId]);
    $thin = [];
    $thinThreshold = 5;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['n'] < $thinThreshold) $thin[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'n' => (int)$r['n']];
    }
    if ($themeCount === 0) {
        $results[] = sc_check('theme_min_coded', 'Theme support (minimum coded responses)', 'skip', 'info',
            'No themes yet.', 'Run the Builder or Generate per-question themes first.', []);
    } else if (count($thin) === 0) {
        $results[] = sc_check('theme_min_coded', 'Theme support (minimum coded responses)', 'pass', 'info',
            'All ' . $themeCount . ' themes have at least ' . $thinThreshold . ' coded responses.',
            '', ['threshold' => $thinThreshold]);
    } else {
        $names = array_slice(array_map(function($t){ return $t['name']; }, $thin), 0, 6);
        $more = count($thin) > 6 ? (' and ' . (count($thin) - 6) . ' more') : '';
        $results[] = sc_check('theme_min_coded', 'Theme support (minimum coded responses)', 'fix', 'warn',
            count($thin) . ' of ' . $themeCount . ' themes have fewer than ' . $thinThreshold . ' coded responses: ' . implode(', ', $names) . $more . '.',
            'Consider merging thin themes into broader categories, deleting them, or re-running Apply themes to responses with a more complete theme set.',
            ['thin' => $thin, 'threshold' => $thinThreshold]);
    }
} catch (Throwable $e) {
    $results[] = sc_check('theme_min_coded', 'Theme support (minimum coded responses)', 'skip', 'info',
        'Could not read themes.', '', ['error' => $e->getMessage()]);
}

// ----------------------------------------------------------------
// Family 2: Theme saturation
// ----------------------------------------------------------------
// Heuristic: a theme that only appears in the last 20% of responses (by id)
// hasn't been tested against the earlier data and may be under-saturated.
try {
    $rs = $pdo->prepare('SELECT id FROM mm_text_responses WHERE project_id = :p ORDER BY id ASC');
    $rs->execute([':p' => $projectId]);
    $respIds = array_map('intval', $rs->fetchAll(PDO::FETCH_COLUMN));
    if (count($respIds) < 20 || $totalCoded === 0) {
        $results[] = sc_check('saturation_late', 'Theme saturation', 'skip', 'info',
            'Not enough coded data to assess saturation.', '', []);
    } else {
        $cutoffIdx = (int)floor(count($respIds) * 0.8);
        $lateIds = array_slice($respIds, $cutoffIdx);
        if (count($lateIds) === 0) {
            $results[] = sc_check('saturation_late', 'Theme saturation', 'skip', 'info',
                'Not enough late responses to assess saturation.', '', []);
        } else {
            $place = implode(',', array_fill(0, count($lateIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT c.id, c.name,
                        (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS total_n,
                        (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id AND cr.response_id IN ($place)) AS late_n
                 FROM mm_theme_categories c WHERE c.project_id = ?"
            );
            $args = array_merge($lateIds, $lateIds, [$projectId]);
            $stmt->execute($args);
            $lateOnly = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tn = (int)$r['total_n']; $ln = (int)$r['late_n'];
                if ($tn === 0) continue;
                // Theme is "late-only" if 100% of its codes come from the last 20%.
                if ($tn === $ln && $tn >= 2) $lateOnly[] = ['name' => (string)$r['name'], 'n' => $tn];
            }
            if (count($lateOnly) === 0) {
                $results[] = sc_check('saturation_late', 'Theme saturation', 'pass', 'info',
                    'No themes appear only in the last 20% of responses. The theme set was stable by then.',
                    '', []);
            } else {
                $names = array_slice(array_map(function($t){ return $t['name']; }, $lateOnly), 0, 5);
                $more = count($lateOnly) > 5 ? (' and ' . (count($lateOnly) - 5) . ' more') : '';
                $results[] = sc_check('saturation_late', 'Theme saturation', 'fix', 'warn',
                    count($lateOnly) . ' theme(s) appear only in the last 20% of coded responses: ' . implode(', ', $names) . $more . '.',
                    'These themes may be under-saturated. If you collected more data, they could appear more broadly; consider whether they hold up.',
                    ['late_only' => $lateOnly]);
            }
        }
    }
} catch (Throwable $e) {
    $results[] = sc_check('saturation_late', 'Theme saturation', 'skip', 'info',
        'Could not assess saturation.', '', ['error' => $e->getMessage()]);
}

// ----------------------------------------------------------------
// Family 3: Effect size sanity
// ----------------------------------------------------------------
if (!$hasAnalysisTbl) {
    $results[] = sc_check('effect_negligible', 'Effect size sanity', 'skip', 'info',
        'No analysis results table yet.',
        'Run analyses on the Analysis tab to enable this check.', []);
} else {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, test_name, statistic, p_value, effect_size, effect_label, n_total, summary
             FROM mm_analysis_results WHERE project_id = :p'
        );
        $stmt->execute([':p' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 0) {
            $results[] = sc_check('effect_negligible', 'Effect size sanity', 'skip', 'info',
                'No analysis tests have been run for this project.',
                'Click Load analysis pairs and Run on at least one pair.', []);
        } else {
            $negligible = [];
            foreach ($rows as $r) {
                $p  = $r['p_value']     !== null ? (float)$r['p_value']     : null;
                $es = $r['effect_size'] !== null ? (float)$r['effect_size'] : null;
                $lbl = (string)($r['effect_label'] ?? '');
                if ($p === null || $es === null) continue;
                if ($p < 0.05) {
                    $tiny = false;
                    if ($lbl === 'cramers_v'   && abs($es) < 0.10) $tiny = true;
                    if ($lbl === 'cohens_d'    && abs($es) < 0.20) $tiny = true;
                    if ($lbl === 'r_squared'   && abs($es) < 0.01) $tiny = true;
                    if ($lbl === 'eta_squared' && abs($es) < 0.01) $tiny = true;
                    if ($tiny) $negligible[] = ['id' => (int)$r['id'], 'test' => (string)$r['test_name'], 'p' => $p, 'effect' => $es, 'label' => $lbl, 'summary' => (string)$r['summary']];
                }
            }
            if (count($negligible) === 0) {
                $results[] = sc_check('effect_negligible', 'Effect size sanity', 'pass', 'info',
                    'No significant tests with negligible effect sizes.',
                    'All p < .05 tests cross conventional minimums for practical importance.', []);
            } else {
                $results[] = sc_check('effect_negligible', 'Effect size sanity', 'fix', 'warn',
                    count($negligible) . ' significant test(s) have negligible effect sizes. Statistical significance without practical importance.',
                    'In the Report, soften language for these results; statistical significance with a tiny effect can mislead readers.',
                    ['negligible' => $negligible]);
            }
        }
    } catch (Throwable $e) {
        $results[] = sc_check('effect_negligible', 'Effect size sanity', 'skip', 'info',
            'Could not read analysis results.', '', ['error' => $e->getMessage()]);
    }
}

// ----------------------------------------------------------------
// Family 4: AI quality checks (quotes + integration paragraphs)
// ----------------------------------------------------------------
if (!$includeAi) {
    $results[] = sc_check('ai_quote_quality', 'AI quote-theme alignment', 'skip', 'info',
        'AI checks not requested for this run.',
        'Re-run Strength Check with Include AI checks enabled.', []);
    $results[] = sc_check('ai_integration_quality', 'AI integration paragraph quality', 'skip', 'info',
        'AI checks not requested for this run.',
        'Re-run Strength Check with Include AI checks enabled.', []);
} else {
    // ---- Quote alignment ----
    if (!$hasJointTbl) {
        $results[] = sc_check('ai_quote_quality', 'AI quote-theme alignment', 'skip', 'info',
            'No joint display rows yet.', 'Pick representative quotes on the Joint Display tab.', []);
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT j.theme_id, j.quote_text, c.name AS theme_name, COALESCE(c.description, "") AS theme_def
                 FROM mm_joint_display_rows j
                 INNER JOIN mm_theme_categories c ON c.id = j.theme_id
                 WHERE j.project_id = :p AND j.quote_text IS NOT NULL AND j.quote_text <> ""'
            );
            $stmt->execute([':p' => $projectId]);
            $picked = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($picked) === 0) {
                $results[] = sc_check('ai_quote_quality', 'AI quote-theme alignment', 'skip', 'info',
                    'No representative quotes picked yet.',
                    'Click Pick all quotes on the Joint Display tab.', []);
            } else {
                $low = [];
                foreach ($picked as $row) {
                    $rating = sc_ai_rate_quote((string)$row['theme_name'], (string)$row['theme_def'], (string)$row['quote_text']);
                    if ($rating !== null && $rating['score'] <= 2) {
                        $low[] = ['theme' => (string)$row['theme_name'], 'score' => $rating['score'], 'reason' => $rating['reason']];
                    }
                }
                if (count($low) === 0) {
                    $results[] = sc_check('ai_quote_quality', 'AI quote-theme alignment', 'pass', 'info',
                        'All ' . count($picked) . ' picked quotes are rated 3+ for theme alignment by the AI reviewer.',
                        '', ['reviewed' => count($picked)]);
                } else {
                    $names = array_slice(array_map(function($t){ return $t['theme']; }, $low), 0, 5);
                    $more = count($low) > 5 ? (' and ' . (count($low) - 5) . ' more') : '';
                    $results[] = sc_check('ai_quote_quality', 'AI quote-theme alignment', 'fix', 'warn',
                        count($low) . ' picked quote(s) scored low for theme alignment: ' . implode(', ', $names) . $more . '.',
                        'On the Joint Display tab, click Pick quote (or Re-pick) on those rows to try again.',
                        ['low' => $low]);
                }
            }
        } catch (Throwable $e) {
            $results[] = sc_check('ai_quote_quality', 'AI quote-theme alignment', 'skip', 'info',
                'Could not run quote review.', '', ['error' => $e->getMessage()]);
        }
    }
    // ---- Integration paragraph quality ----
    if (!$hasIntegrationT) {
        $results[] = sc_check('ai_integration_quality', 'AI integration paragraph quality', 'skip', 'info',
            'No integration paragraphs yet.', 'Generate paragraphs on the Integration tab.', []);
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT i.theme_id, i.paragraph_text, i.source, c.name AS theme_name
                 FROM mm_integration_rows i
                 INNER JOIN mm_theme_categories c ON c.id = i.theme_id
                 WHERE i.project_id = :p AND i.paragraph_text IS NOT NULL AND i.paragraph_text <> ""'
            );
            $stmt->execute([':p' => $projectId]);
            $paras = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($paras) === 0) {
                $results[] = sc_check('ai_integration_quality', 'AI integration paragraph quality', 'skip', 'info',
                    'No integration paragraphs saved yet.',
                    'Use Generate all on the Integration tab.', []);
            } else {
                $weak = [];
                foreach ($paras as $row) {
                    $rating = sc_ai_rate_integration((string)$row['theme_name'], (string)$row['paragraph_text']);
                    if ($rating !== null && $rating['score'] <= 2) {
                        $weak[] = ['theme' => (string)$row['theme_name'], 'score' => $rating['score'], 'reason' => $rating['reason'], 'source' => (string)($row['source'] ?? '')];
                    }
                }
                if (count($weak) === 0) {
                    $results[] = sc_check('ai_integration_quality', 'AI integration paragraph quality', 'pass', 'info',
                        'All ' . count($paras) . ' integration paragraphs are rated 3+ for quality.',
                        '', ['reviewed' => count($paras)]);
                } else {
                    $names = array_slice(array_map(function($t){ return $t['theme']; }, $weak), 0, 5);
                    $more = count($weak) > 5 ? (' and ' . (count($weak) - 5) . ' more') : '';
                    $results[] = sc_check('ai_integration_quality', 'AI integration paragraph quality', 'fix', 'warn',
                        count($weak) . ' paragraph(s) scored low: ' . implode(', ', $names) . $more . '.',
                        'On the Integration tab, click Regenerate on those rows, or edit them by hand.',
                        ['weak' => $weak]);
                }
            }
        } catch (Throwable $e) {
            $results[] = sc_check('ai_integration_quality', 'AI integration paragraph quality', 'skip', 'info',
                'Could not run paragraph review.', '', ['error' => $e->getMessage()]);
        }
    }
}

// ----------------------------------------------------------------
// Persist all results (upsert by check_key)
// ----------------------------------------------------------------
$ins = $pdo->prepare(
    'INSERT INTO mm_strength_checks
       (project_id, check_key, status, severity, title, message, fix_hint, details_json, ran_at)
     VALUES
       (:p, :k, :s, :sv, :t, :m, :f, :d, NOW())
     ON DUPLICATE KEY UPDATE
       status   = VALUES(status),
       severity = VALUES(severity),
       title    = VALUES(title),
       message  = VALUES(message),
       fix_hint = VALUES(fix_hint),
       details_json = VALUES(details_json),
       ran_at   = NOW()'
);
foreach ($results as $r) {
    $ins->execute([
        ':p' => $projectId,
        ':k' => $r['check_key'],
        ':s' => $r['status'],
        ':sv' => $r['severity'],
        ':t' => $r['title'],
        ':m' => $r['message'],
        ':f' => $r['fix_hint'],
        ':d' => $r['details_json'],
    ]);
}

// Summary counts.
$pass = 0; $fix = 0; $skip = 0;
foreach ($results as $r) {
    if ($r['status'] === 'pass') $pass++;
    elseif ($r['status'] === 'fix') $fix++;
    else $skip++;
}

json_out([
    'ok'      => true,
    'rows'    => $results,
    'pass'    => $pass,
    'fix'     => $fix,
    'skip'    => $skip,
]);

// ================================================================
// Local AI raters
// ================================================================
function sc_ai_rate_quote(string $themeName, string $themeDef, string $quote): ?array {
    if (trim($quote) === '') return null;
    $system = <<<SYS
You are a qualitative methods reviewer. Rate how well a quote illustrates a theme.

Return ONLY a JSON object:
{ "score": <integer 1-5>, "reason": "<one short sentence>" }

Scoring guide:
5 = clearly and specifically illustrates the theme
4 = mostly on point but slightly indirect
3 = touches the theme but not as the central focus
2 = only weakly related
1 = does not illustrate this theme
SYS;
    $user = "Theme: " . $themeName . "\n"
          . ($themeDef !== '' ? "Definition: " . $themeDef . "\n" : '')
          . "Quote: \"" . $quote . "\"\n\nRate it.";
    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => $user]], 200);
        $p = ai_extract_json((string)($resp['text'] ?? ''));
        if (!$p || !isset($p['score'])) return null;
        $score = (int)$p['score'];
        if ($score < 1 || $score > 5) return null;
        return ['score' => $score, 'reason' => (string)($p['reason'] ?? '')];
    } catch (Throwable $e) { return null; }
}

function sc_ai_rate_integration(string $themeName, string $para): ?array {
    if (trim($para) === '') return null;
    $system = <<<SYS
You are a mixed-methods reviewer. Rate the quality of a 2-3 sentence integration paragraph that pairs qualitative and quantitative evidence for one theme.

Return ONLY a JSON object:
{ "score": <integer 1-5>, "reason": "<one short sentence>" }

Scoring guide:
5 = paragraph cites both qualitative and quantitative evidence, names the implication, and does not overclaim
4 = solid but missing one of the elements above
3 = present but vague, generic, or hedges too much
2 = imbalanced, overclaims, or contradicts the typical pattern
1 = unrelated or unusable
SYS;
    $user = "Theme: " . $themeName . "\nParagraph: " . $para . "\n\nRate it.";
    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => $user]], 200);
        $p = ai_extract_json((string)($resp['text'] ?? ''));
        if (!$p || !isset($p['score'])) return null;
        $score = (int)$p['score'];
        if ($score < 1 || $score > 5) return null;
        return ['score' => $score, 'reason' => (string)($p['reason'] ?? '')];
    } catch (Throwable $e) { return null; }
}
