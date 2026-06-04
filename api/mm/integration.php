<?php
// GET  /api/mm/integration.php?project_id=N
//   Returns one row per theme with the stored paragraph (if any).
//
// POST /api/mm/integration.php
//   { project_id, action: "generate",     theme_id }
//   { project_id, action: "generate_all" }   // every theme without a user-edited paragraph
//   { project_id, action: "save_text",    theme_id, paragraph_text }
//   { project_id, action: "clear",        theme_id }

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

// This endpoint can make multi-second AI calls. Free the session lock now (we
// only read the session, never write it) so other requests in the same browser
// session (e.g. loading another studio step) don't block for the whole run.
// See release_session_lock() in _session.php.
release_session_lock();

// Detect whether the intensity column exists on mm_coded_responses (Phase 156).
$hasIntensity = false;
try {
    $r = $pdo->query("SHOW COLUMNS FROM mm_coded_responses LIKE 'intensity'");
    $hasIntensity = $r && $r->fetch() !== false;
} catch (Throwable $e) { $hasIntensity = false; }

// Detect which optional companion tables exist. The GET endpoint must keep
// working even if the user has not run later schema phases yet.
function int_table_exists(PDO $pdo, string $name): bool {
    try {
        $s = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return $s && $s->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
}
$hasAnalysisTable    = int_table_exists($pdo, 'mm_analysis_results');
$hasJointDisplay     = int_table_exists($pdo, 'mm_joint_display_rows');
$hasIntegrationTable = int_table_exists($pdo, 'mm_integration_rows');

function int_intensity_score(string $i): int {
    switch ($i) {
        case 'low':      return 1;
        case 'moderate': return 2;
        case 'high':     return 3;
        default:         return 0;
    }
}

// ----------------------------------------------------------------
// Build the stats payload that the AI sees for one theme.
// ----------------------------------------------------------------
function int_stats_for_theme(PDO $pdo, int $projectId, array $theme, int $totalResponses, bool $hasIntensity, bool $hasAnalysisTable = true, bool $hasJointDisplay = true): array {
    $tid = (int)$theme['id'];

    // Frequency
    $f = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p AND category_id = :c');
    $f->execute([':p' => $projectId, ':c' => $tid]);
    $n = (int)$f->fetchColumn();
    $percent = $totalResponses > 0 ? round(100.0 * $n / $totalResponses, 1) : 0.0;

    // Mean intensity
    $meanIntensity = null;
    if ($hasIntensity) {
        $i = $pdo->prepare('SELECT intensity FROM mm_coded_responses WHERE project_id = :p AND category_id = :c');
        $i->execute([':p' => $projectId, ':c' => $tid]);
        $vals = [];
        foreach ($i->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $score = int_intensity_score((string)$v);
            if ($score > 0) $vals[] = $score;
        }
        if (count($vals) > 0) $meanIntensity = round(array_sum($vals) / count($vals), 2);
    }

    // Sentiment breakdown across responses where this theme appears.
    $s = $pdo->prepare(
        'SELECT ss.sentiment, COUNT(*) AS n
         FROM mm_coded_responses cr
         INNER JOIN mm_sentiment_scores ss ON ss.response_id = cr.response_id AND ss.project_id = cr.project_id
         WHERE cr.project_id = :p AND cr.category_id = :c
         GROUP BY ss.sentiment'
    );
    $s->execute([':p' => $projectId, ':c' => $tid]);
    $sent = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = strtolower((string)$row['sentiment']);
        if (!isset($sent[$k])) continue;
        $sent[$k] = (int)$row['n'];
    }
    $sentTotal = array_sum($sent);
    $sentPct = [];
    foreach ($sent as $k => $v) {
        $sentPct[$k] = $sentTotal > 0 ? round(100.0 * $v / $sentTotal, 1) : 0.0;
    }

    // Strongest analysis result tied to this theme.
    // Skip entirely if mm_analysis_results does not exist (Step 14 not run).
    $analysis = null;
    if ($hasAnalysisTable) {
        try {
            $vstmt = $pdo->prepare('SELECT id FROM mm_generated_variables WHERE project_id = :p AND source_category_id = :c');
            $vstmt->execute([':p' => $projectId, ':c' => $tid]);
            $varIds = array_map('intval', $vstmt->fetchAll(PDO::FETCH_COLUMN));
            if (count($varIds) > 0) {
                $place = implode(',', array_fill(0, count($varIds), '?'));
                $args = array_merge([$projectId], $varIds, $varIds);
                $astmt = $pdo->prepare(
                    "SELECT ar.*, vp.var_name AS predictor_name, vo.var_name AS outcome_name
                     FROM mm_analysis_results ar
                     LEFT JOIN mm_generated_variables vp ON vp.id = ar.predictor_id
                     LEFT JOIN mm_generated_variables vo ON vo.id = ar.outcome_id
                     WHERE ar.project_id = ? AND (ar.predictor_id IN ($place) OR ar.outcome_id IN ($place))
                     ORDER BY (ar.p_value IS NULL), ar.p_value ASC
                     LIMIT 1"
                );
                $astmt->execute($args);
                $a = $astmt->fetch(PDO::FETCH_ASSOC);
                if ($a) {
                    $analysis = [
                        'test'         => (string)$a['test_name'],
                        'statistic'    => $a['statistic']   !== null ? (float)$a['statistic']   : null,
                        'p_value'      => $a['p_value']     !== null ? (float)$a['p_value']     : null,
                        'effect_size'  => $a['effect_size'] !== null ? (float)$a['effect_size'] : null,
                        'effect_label' => (string)($a['effect_label'] ?? ''),
                        'predictor'    => (string)($a['predictor_name'] ?? ''),
                        'outcome'      => (string)($a['outcome_name'] ?? ''),
                        'summary'      => (string)($a['summary'] ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) { $analysis = null; }
    }

    // Representative quote (from mm_joint_display_rows if Step 15 has run).
    $quote = '';
    if ($hasJointDisplay) {
        try {
            $qstmt = $pdo->prepare('SELECT quote_text FROM mm_joint_display_rows WHERE project_id = :p AND theme_id = :c');
            $qstmt->execute([':p' => $projectId, ':c' => $tid]);
            $quote = (string)($qstmt->fetchColumn() ?: '');
        } catch (Throwable $e) { $quote = ''; }
    }

    return [
        'theme_id'   => $tid,
        'theme_name' => (string)$theme['name'],
        'theme_def'  => (string)($theme['description'] ?? ''),
        'frequency'  => ['n' => $n, 'percent' => $percent, 'denom' => $totalResponses],
        'mean_intensity' => $meanIntensity,
        'sentiment'  => ['percent' => $sentPct, 'counts' => $sent, 'n' => $sentTotal],
        'analysis'   => $analysis,
        'quote'      => $quote,
    ];
}

// Build the prompt the AI sees for one theme.
function int_prompt_for_theme(array $st): string {
    $lines = [];
    $lines[] = "THEME: " . $st['theme_name'];
    if ($st['theme_def'] !== '') $lines[] = "DEFINITION: " . $st['theme_def'];
    $lines[] = "FREQUENCY: appeared in " . $st['frequency']['n'] . " of " . $st['frequency']['denom'] . " responses (" . $st['frequency']['percent'] . "%).";
    if ($st['mean_intensity'] !== null) $lines[] = "MEAN INTENSITY (0-3 scale): " . $st['mean_intensity'];
    $sp = $st['sentiment']['percent'];
    $lines[] = sprintf(
        "SENTIMENT among responses with this theme: positive %.1f%%, neutral %.1f%%, mixed %.1f%%, negative %.1f%%.",
        $sp['positive'] ?? 0, $sp['neutral'] ?? 0, $sp['mixed'] ?? 0, $sp['negative'] ?? 0
    );
    if ($st['analysis']) {
        $a = $st['analysis'];
        $lines[] = "QUANT TEST: " . $a['test']
            . ($a['predictor'] !== '' ? ' (' . $a['predictor'] . ' -> ' . $a['outcome'] . ')' : '')
            . ($a['p_value'] !== null ? '; p = ' . sprintf('%.4f', $a['p_value']) : '')
            . ($a['effect_size'] !== null ? '; ' . $a['effect_label'] . ' = ' . sprintf('%.2f', $a['effect_size']) : '');
    }
    if ($st['quote'] !== '') $lines[] = "REPRESENTATIVE QUOTE: \"" . $st['quote'] . "\"";
    return implode("\n", $lines);
}

// AI call -> returns the paragraph string (or empty on failure).
function int_generate_one(array $st): string {
    $system = <<<SYS
You are a mixed-methods research analyst. Given one theme's quantitative footprint and (optionally) a representative quote, write a single integration paragraph that weaves together the qualitative and quantitative evidence.

Rules:
- 2 to 3 sentences. No headers, no bullets, no markdown.
- Lead with what the qualitative side says about the theme.
- Bring in the frequency and sentiment numbers to confirm or qualify.
- If a quantitative test is listed, briefly note whether it supports, complicates, or expands the qualitative finding (use "supports", "complicates", "expands").
- Close with a one-clause "so what" that names the implication.
- Use plain academic prose. No filler like "In conclusion" or "This shows that".
- Do not invent numbers or quotes.
SYS;

    $user = int_prompt_for_theme($st);

    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => $user]], 400);
        $text = trim((string)($resp['text'] ?? ''));
        // Strip any leading "Integration:" or "Paragraph:" labels.
        $text = preg_replace('/^(integration|paragraph|here.{0,40}):\s*/i', '', $text);
        return $text;
    } catch (Throwable $e) {
        return '';
    }
}

// ----------------------------------------------------------------
// GET
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    $tstmt = $pdo->prepare(
        'SELECT id, name, COALESCE(definition, description, "") AS description
         FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
    );
    $tstmt->execute([':p' => $projectId]);
    $themes = $tstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM mm_text_responses WHERE project_id = :p');
    $totalStmt->execute([':p' => $projectId]);
    $totalResponses = (int)$totalStmt->fetchColumn();

    $codedStmt = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p');
    $codedStmt->execute([':p' => $projectId]);
    $totalCoded = (int)$codedStmt->fetchColumn();

    $stored = [];
    if ($hasIntegrationTable) {
        try {
            $rowStmt = $pdo->prepare('SELECT theme_id, paragraph_text, source, generated_at FROM mm_integration_rows WHERE project_id = :p');
            $rowStmt->execute([':p' => $projectId]);
            foreach ($rowStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stored[(int)$r['theme_id']] = $r;
            }
        } catch (Throwable $e) { $stored = []; }
    }

    $rows = [];
    foreach ($themes as $theme) {
        $st = int_stats_for_theme($pdo, $projectId, $theme, $totalResponses, $hasIntensity, $hasAnalysisTable, $hasJointDisplay);
        $st['paragraph'] = isset($stored[$st['theme_id']]) ? (string)($stored[$st['theme_id']]['paragraph_text'] ?? '') : '';
        $st['source']    = isset($stored[$st['theme_id']]) ? (string)($stored[$st['theme_id']]['source'] ?? '') : '';
        $rows[] = $st;
    }

    json_out([
        'ok'              => true,
        'total_responses' => $totalResponses,
        'total_coded'     => $totalCoded,
        'theme_count'     => count($themes),
        'rows'            => $rows,
    ]);
}

// ----------------------------------------------------------------
// POST
// ----------------------------------------------------------------
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 32);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if ($action === 'save_text') {
    $themeId = (int)($body['theme_id'] ?? 0);
    $text    = clean_string((string)($body['paragraph_text'] ?? ''), 2000);
    if ($themeId <= 0) fail('bad_input', 'theme_id is required.');
    $ck = $pdo->prepare('SELECT id FROM mm_theme_categories WHERE id = :i AND project_id = :p');
    $ck->execute([':i' => $themeId, ':p' => $projectId]);
    if (!$ck->fetch()) fail('mm_theme_not_found', 'Theme not found.', 404);
    $up = $pdo->prepare(
        'INSERT INTO mm_integration_rows (project_id, theme_id, paragraph_text, source)
         VALUES (:p, :t, :tx, "user")
         ON DUPLICATE KEY UPDATE paragraph_text = VALUES(paragraph_text), source = "user"'
    );
    $up->execute([':p' => $projectId, ':t' => $themeId, ':tx' => $text !== '' ? $text : null]);
    json_out(['ok' => true, 'theme_id' => $themeId, 'paragraph' => $text, 'source' => 'user']);
}

if ($action === 'clear') {
    $themeId = (int)($body['theme_id'] ?? 0);
    if ($themeId <= 0) fail('bad_input', 'theme_id is required.');
    $pdo->prepare('DELETE FROM mm_integration_rows WHERE project_id = :p AND theme_id = :t')
        ->execute([':p' => $projectId, ':t' => $themeId]);
    json_out(['ok' => true]);
}

check_rate_limit('mm_integration:user:' . $uid, 200, 3600);

// Load theme + totals once for use by either AI action.
$tstmt = $pdo->prepare(
    'SELECT id, name, COALESCE(definition, description, "") AS description
     FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$tstmt->execute([':p' => $projectId]);
$themes = $tstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$themeById = [];
foreach ($themes as $t) $themeById[(int)$t['id']] = $t;

$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM mm_text_responses WHERE project_id = :p');
$totalStmt->execute([':p' => $projectId]);
$totalResponses = (int)$totalStmt->fetchColumn();

if ($action === 'generate') {
    $themeId = (int)($body['theme_id'] ?? 0);
    if ($themeId <= 0) fail('bad_input', 'theme_id is required.');
    if (!isset($themeById[$themeId])) fail('mm_theme_not_found', 'Theme not found.', 404);

    $st = int_stats_for_theme($pdo, $projectId, $themeById[$themeId], $totalResponses, $hasIntensity);
    if ($st['frequency']['n'] === 0) {
        fail('mm_theme_uncoded', 'This theme has no coded responses yet. Run Apply themes to responses on the Categories tab first.');
    }
    $para = int_generate_one($st);
    if ($para === '') fail('ai_failed', 'AI did not produce a paragraph for this theme. Try again.', 502);

    $up = $pdo->prepare(
        'INSERT INTO mm_integration_rows (project_id, theme_id, paragraph_text, source, generated_at)
         VALUES (:p, :t, :tx, "ai", NOW())
         ON DUPLICATE KEY UPDATE paragraph_text = VALUES(paragraph_text), source = "ai", generated_at = NOW()'
    );
    $up->execute([':p' => $projectId, ':t' => $themeId, ':tx' => $para]);
    json_out(['ok' => true, 'theme_id' => $themeId, 'paragraph' => $para, 'source' => 'ai']);
}

if ($action === 'generate_all') {
    // Skip themes that already have a user-edited paragraph.
    $existing = $pdo->prepare('SELECT theme_id, source FROM mm_integration_rows WHERE project_id = :p');
    $existing->execute([':p' => $projectId]);
    $userEdited = [];
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (((string)$r['source']) === 'user') $userEdited[(int)$r['theme_id']] = true;
    }

    $up = $pdo->prepare(
        'INSERT INTO mm_integration_rows (project_id, theme_id, paragraph_text, source, generated_at)
         VALUES (:p, :t, :tx, "ai", NOW())
         ON DUPLICATE KEY UPDATE paragraph_text = VALUES(paragraph_text), source = "ai", generated_at = NOW()'
    );

    $generated = 0;
    $skipped   = [];
    foreach ($themes as $theme) {
        $tid = (int)$theme['id'];
        if (isset($userEdited[$tid])) { $skipped[] = ['theme_id' => $tid, 'reason' => 'user_edited']; continue; }
        $st = int_stats_for_theme($pdo, $projectId, $theme, $totalResponses, $hasIntensity);
        if ($st['frequency']['n'] === 0) { $skipped[] = ['theme_id' => $tid, 'reason' => 'no_coded_responses']; continue; }
        $para = int_generate_one($st);
        if ($para === '') { $skipped[] = ['theme_id' => $tid, 'reason' => 'ai_empty']; continue; }
        $up->execute([':p' => $projectId, ':t' => $tid, ':tx' => $para]);
        $generated++;
    }

    json_out([
        'ok'        => true,
        'generated' => $generated,
        'skipped'   => $skipped,
        'total'     => count($themes),
    ]);
}

fail('bad_input', 'Unknown action.');
