<?php
// GET  /api/mm/joint-display.php?project_id=N
//   Returns one row per theme with:
//     - frequency  (n responses where theme appears, percent of total responses)
//     - mean_intensity  (avg intensity 0..3 across coded responses)
//     - sentiment_breakdown  ({positive, neutral, negative, mixed} percents)
//     - analysis_result  (strongest Step-14 result that involves a variable
//                          sourced from this theme, if any)
//     - quote  (one representative verbatim picked previously, blank until
//                pick_quote runs)
//
// POST /api/mm/joint-display.php
//   { project_id, action: "pick_quote", theme_id }
//     Asks the AI to choose the most illustrative quote for one theme.
//   { project_id, action: "pick_all_quotes" }
//     Runs pick_quote for every theme that doesn't already have one.
//   { project_id, action: "save_notes", theme_id, notes }
//     Persists researcher_notes for one theme row.

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

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
$hasIntensity = false;
try {
    $r = $pdo->query("SHOW COLUMNS FROM mm_coded_responses LIKE 'intensity'");
    $hasIntensity = $r && $r->fetch() !== false;
} catch (Throwable $e) { $hasIntensity = false; }

function jd_intensity_score(string $i): int {
    switch ($i) {
        case 'low':      return 1;
        case 'moderate': return 2;
        case 'high':     return 3;
        default:         return 0;
    }
}

// Build one joint-display row payload for the given theme.
function jd_build_row(PDO $pdo, int $projectId, array $theme, int $totalResponses, bool $hasIntensity): array {
    $tid = (int)$theme['id'];

    // Frequency
    $f = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p AND category_id = :c');
    $f->execute([':p' => $projectId, ':c' => $tid]);
    $n = (int)$f->fetchColumn();
    $percent = $totalResponses > 0 ? round(100.0 * $n / $totalResponses, 1) : 0.0;

    // Mean intensity (only if column exists)
    $meanIntensity = null;
    if ($hasIntensity) {
        $i = $pdo->prepare('SELECT intensity FROM mm_coded_responses WHERE project_id = :p AND category_id = :c');
        $i->execute([':p' => $projectId, ':c' => $tid]);
        $vals = [];
        foreach ($i->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $score = jd_intensity_score((string)$v);
            if ($score > 0) $vals[] = $score;
        }
        if (count($vals) > 0) $meanIntensity = round(array_sum($vals) / count($vals), 2);
    }

    // Sentiment breakdown
    $s = $pdo->prepare(
        'SELECT ss.sentiment, COUNT(*) AS n
         FROM mm_coded_responses cr
         INNER JOIN mm_sentiment_scores ss ON ss.response_id = cr.response_id AND ss.project_id = cr.project_id
         WHERE cr.project_id = :p AND cr.category_id = :c
         GROUP BY ss.sentiment'
    );
    $s->execute([':p' => $projectId, ':c' => $tid]);
    $sentRaw = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = strtolower((string)$row['sentiment']);
        if (!isset($sentRaw[$k])) continue;
        $sentRaw[$k] = (int)$row['n'];
    }
    $sentTotal = array_sum($sentRaw);
    $sentPct = [];
    foreach ($sentRaw as $k => $v) {
        $sentPct[$k] = $sentTotal > 0 ? round(100.0 * $v / $sentTotal, 1) : 0.0;
    }

    // Linked analysis result (smallest p where this theme's variable_id is
    // either predictor or outcome).
    $analysis = null;
    $vstmt = $pdo->prepare(
        'SELECT id FROM mm_generated_variables WHERE project_id = :p AND source_category_id = :c'
    );
    $vstmt->execute([':p' => $projectId, ':c' => $tid]);
    $varIds = array_map('intval', $vstmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($varIds) > 0) {
        // Build "IN (...)" with positional placeholders.
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
                'test'        => (string)$a['test_name'],
                'statistic'   => $a['statistic']   !== null ? (float)$a['statistic']   : null,
                'p_value'     => $a['p_value']     !== null ? (float)$a['p_value']     : null,
                'effect_size' => $a['effect_size'] !== null ? (float)$a['effect_size'] : null,
                'effect_label'=> (string)($a['effect_label'] ?? ''),
                'n_total'     => $a['n_total']     !== null ? (int)$a['n_total']     : null,
                'summary'     => (string)($a['summary'] ?? ''),
                'predictor'   => (string)($a['predictor_name'] ?? ''),
                'outcome'     => (string)($a['outcome_name'] ?? ''),
            ];
        }
    }

    // Stored representative quote + notes
    $jd = $pdo->prepare('SELECT quote_response_id, quote_text, researcher_notes FROM mm_joint_display_rows WHERE project_id = :p AND theme_id = :c');
    $jd->execute([':p' => $projectId, ':c' => $tid]);
    $stored = $jd->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'theme_id'        => $tid,
        'theme_name'      => (string)$theme['name'],
        'theme_definition'=> (string)($theme['definition'] ?? $theme['description'] ?? ''),
        'frequency'       => ['n' => $n, 'percent' => $percent, 'denom' => $totalResponses],
        'mean_intensity'  => $meanIntensity,
        'sentiment'       => ['counts' => $sentRaw, 'percent' => $sentPct, 'n' => $sentTotal],
        'analysis'        => $analysis,
        'quote'           => [
            'response_id' => isset($stored['quote_response_id']) && $stored['quote_response_id'] !== null ? (int)$stored['quote_response_id'] : null,
            'text'        => (string)($stored['quote_text'] ?? ''),
        ],
        'notes'           => (string)($stored['researcher_notes'] ?? ''),
    ];
}

// Pick the most representative quote for ONE theme via the AI.
function jd_pick_quote_for_theme(PDO $pdo, int $projectId, int $themeId): array {
    // Pull theme info
    $t = $pdo->prepare('SELECT id, name, COALESCE(definition, description, "") AS description FROM mm_theme_categories WHERE id = :i AND project_id = :p');
    $t->execute([':i' => $themeId, ':p' => $projectId]);
    $theme = $t->fetch(PDO::FETCH_ASSOC);
    if (!$theme) return ['ok' => false, 'error' => 'Theme not found in project.'];

    // Pull responses where this theme appears. Cap at 40 candidates so the
    // prompt stays small.
    $candStmt = $pdo->prepare(
        'SELECT r.id AS response_id, r.text
         FROM mm_coded_responses cr
         INNER JOIN mm_text_responses r ON r.id = cr.response_id
         WHERE cr.project_id = :p AND cr.category_id = :c AND r.text IS NOT NULL AND r.text <> ""
         ORDER BY CHAR_LENGTH(r.text) DESC
         LIMIT 40'
    );
    $candStmt->execute([':p' => $projectId, ':c' => $themeId]);
    $cands = $candStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($cands) === 0) return ['ok' => false, 'error' => 'No coded responses for this theme.'];

    // If we only have one or two candidates, just take the longest as-is to
    // save an AI call.
    if (count($cands) <= 2) {
        $best = $cands[0];
        return ['ok' => true, 'response_id' => (int)$best['response_id'], 'text' => (string)$best['text']];
    }

    $lines = [];
    foreach ($cands as $i => $row) {
        $txt = preg_replace('/\s+/', ' ', (string)$row['text']);
        if (mb_strlen($txt) > 400) $txt = mb_substr($txt, 0, 400) . '...';
        $lines[] = ($i + 1) . '. ' . $txt;
    }
    $block = implode("\n", $lines);

    $system = <<<SYS
You are a mixed-methods qualitative coder. You will be given a single theme (name and definition) and a numbered list of candidate quotes from responses that were coded with that theme. Pick the ONE quote that most clearly illustrates the theme - vivid, specific, self-contained, and on point.

Rules:
- Choose by number from the candidates only. Do not invent or rewrite the quote.
- Prefer concise quotes that name the theme directly over vague or rambling ones.
- Return a single JSON object only, no prose, no markdown fences:

{ "pick": <int>, "reason": "<one short sentence>" }
SYS;

    $prompt  = "Theme: " . (string)$theme['name'] . "\n";
    $prompt .= "Definition: " . (string)$theme['description'] . "\n\n";
    $prompt .= "Candidates (numbered):\n" . $block . "\n\n";
    $prompt .= "Pick the most representative quote.";

    $resp = ai_complete($system, [['role' => 'user', 'content' => $prompt]], 400);
    $parsed = ai_extract_json($resp['text']);
    $pick = isset($parsed['pick']) ? (int)$parsed['pick'] : 0;
    if ($pick < 1 || $pick > count($cands)) {
        // Fall back to the longest quote.
        $best = $cands[0];
        return ['ok' => true, 'response_id' => (int)$best['response_id'], 'text' => (string)$best['text']];
    }
    $best = $cands[$pick - 1];
    return ['ok' => true, 'response_id' => (int)$best['response_id'], 'text' => (string)$best['text']];
}

// -----------------------------------------------------------------------
// GET: build the full joint display payload
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    $tstmt = $pdo->prepare(
        'SELECT id, name, COALESCE(definition, description, "") AS description, COALESCE(definition, description, "") AS definition
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

    $rows = [];
    foreach ($themes as $theme) {
        $rows[] = jd_build_row($pdo, $projectId, $theme, $totalResponses, $hasIntensity);
    }

    json_out([
        'ok'              => true,
        'total_responses' => $totalResponses,
        'total_coded'     => $totalCoded,
        'theme_count'     => count($themes),
        'rows'            => $rows,
        'has_intensity'   => $hasIntensity,
    ]);
}

// -----------------------------------------------------------------------
// POST: actions
// -----------------------------------------------------------------------
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 32);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if ($action === 'save_notes') {
    $themeId = (int)($body['theme_id'] ?? 0);
    $notes   = clean_string((string)($body['notes'] ?? ''), 1200);
    if ($themeId <= 0) fail('bad_input', 'theme_id is required.');
    $ck = $pdo->prepare('SELECT id FROM mm_theme_categories WHERE id = :i AND project_id = :p');
    $ck->execute([':i' => $themeId, ':p' => $projectId]);
    if (!$ck->fetch()) fail('mm_theme_not_found', 'Theme not found.', 404);

    $up = $pdo->prepare(
        'INSERT INTO mm_joint_display_rows (project_id, theme_id, researcher_notes)
         VALUES (:p, :t, :n)
         ON DUPLICATE KEY UPDATE researcher_notes = VALUES(researcher_notes)'
    );
    $up->execute([':p' => $projectId, ':t' => $themeId, ':n' => $notes !== '' ? $notes : null]);
    json_out(['ok' => true, 'notes' => $notes]);
}

// Manual quote selection — the human-led alternative to the AI pick_quote.
// No AI, no rate limit. The response must already be coded to the theme.
if ($action === 'set_quote') {
    $themeId    = (int)($body['theme_id'] ?? 0);
    $responseId = (int)($body['response_id'] ?? 0);
    if ($themeId <= 0 || $responseId <= 0) fail('bad_input', 'theme_id and response_id are required.');
    $tk = $pdo->prepare('SELECT id FROM mm_theme_categories WHERE id = :t AND project_id = :p');
    $tk->execute([':t' => $themeId, ':p' => $projectId]);
    if (!$tk->fetch()) fail('mm_theme_not_found', 'Theme not found.', 404);
    // Any open-ended response in this project may be featured as a theme's quote
    // — the human picks what is representative; it need not be machine-coded.
    $ck = $pdo->prepare('SELECT text FROM mm_text_responses WHERE id = :r AND project_id = :p LIMIT 1');
    $ck->execute([':r' => $responseId, ':p' => $projectId]);
    $row = $ck->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('mm_response_not_found', 'Response not found.', 404);
    $up = $pdo->prepare(
        'INSERT INTO mm_joint_display_rows (project_id, theme_id, quote_response_id, quote_text)
         VALUES (:p, :t, :rid, :txt)
         ON DUPLICATE KEY UPDATE quote_response_id = VALUES(quote_response_id), quote_text = VALUES(quote_text)'
    );
    $up->execute([':p' => $projectId, ':t' => $themeId, ':rid' => $responseId, ':txt' => mb_substr((string)$row['text'], 0, 800)]);
    json_out(['ok' => true, 'theme_id' => $themeId, 'quote' => ['response_id' => $responseId, 'text' => (string)$row['text']]]);
}

// Manual free-text quote — the fallback when there are no coded responses to
// pick from (incomplete tagging). The researcher types/pastes any quote and an
// optional participant attribution; stored with quote_response_id = NULL. No AI,
// no rate limit. Uses the existing quote_text column, so no schema change.
if ($action === 'set_quote_manual') {
    $themeId = (int)($body['theme_id'] ?? 0);
    $quote   = clean_string((string)($body['quote_text'] ?? ''), 760);
    $attrib  = clean_string((string)($body['attribution'] ?? ''), 120);
    if ($themeId <= 0) fail('bad_input', 'theme_id is required.');
    if ($quote === '') fail('bad_input', 'quote_text is required.');
    $tk = $pdo->prepare('SELECT id FROM mm_theme_categories WHERE id = :t AND project_id = :p');
    $tk->execute([':t' => $themeId, ':p' => $projectId]);
    if (!$tk->fetch()) fail('mm_theme_not_found', 'Theme not found.', 404);
    // No attribution column on mm_joint_display_rows; fold the optional
    // attribution into the stored text so it survives without a migration.
    $stored = $attrib !== '' ? mb_substr($quote . ' — ' . $attrib, 0, 800) : $quote;
    $up = $pdo->prepare(
        'INSERT INTO mm_joint_display_rows (project_id, theme_id, quote_response_id, quote_text)
         VALUES (:p, :t, NULL, :txt)
         ON DUPLICATE KEY UPDATE quote_response_id = NULL, quote_text = VALUES(quote_text)'
    );
    $up->execute([':p' => $projectId, ':t' => $themeId, ':txt' => $stored]);
    json_out(['ok' => true, 'theme_id' => $themeId, 'quote' => ['response_id' => null, 'text' => $stored, 'manual' => true]]);
}

check_rate_limit('mm_joint_display:user:' . $uid, 60, 3600);

if ($action === 'pick_quote') {
    $themeId = (int)($body['theme_id'] ?? 0);
    if ($themeId <= 0) fail('bad_input', 'theme_id is required.');
    $r = jd_pick_quote_for_theme($pdo, $projectId, $themeId);
    if (empty($r['ok'])) fail('mm_pick_quote_failed', $r['error'] ?? 'Could not pick a quote.', 400);

    $up = $pdo->prepare(
        'INSERT INTO mm_joint_display_rows (project_id, theme_id, quote_response_id, quote_text)
         VALUES (:p, :t, :rid, :txt)
         ON DUPLICATE KEY UPDATE quote_response_id = VALUES(quote_response_id), quote_text = VALUES(quote_text)'
    );
    $up->execute([
        ':p'   => $projectId,
        ':t'   => $themeId,
        ':rid' => (int)$r['response_id'],
        ':txt' => mb_substr((string)$r['text'], 0, 800),
    ]);
    json_out(['ok' => true, 'theme_id' => $themeId, 'quote' => ['response_id' => (int)$r['response_id'], 'text' => (string)$r['text']]]);
}

if ($action === 'pick_all_quotes') {
    $tstmt = $pdo->prepare(
        'SELECT c.id
         FROM mm_theme_categories c
         LEFT JOIN mm_joint_display_rows j
              ON j.project_id = c.project_id AND j.theme_id = c.id
         WHERE c.project_id = :p AND (j.id IS NULL OR j.quote_text IS NULL OR j.quote_text = "")
         ORDER BY c.position ASC, c.id ASC'
    );
    $tstmt->execute([':p' => $projectId]);
    $missing = array_map('intval', $tstmt->fetchAll(PDO::FETCH_COLUMN));

    $picked = 0;
    $skipped = [];
    foreach ($missing as $tid) {
        $r = jd_pick_quote_for_theme($pdo, $projectId, $tid);
        if (empty($r['ok'])) { $skipped[] = ['theme_id' => $tid, 'reason' => $r['error'] ?? 'unknown']; continue; }
        $up = $pdo->prepare(
            'INSERT INTO mm_joint_display_rows (project_id, theme_id, quote_response_id, quote_text)
             VALUES (:p, :t, :rid, :txt)
             ON DUPLICATE KEY UPDATE quote_response_id = VALUES(quote_response_id), quote_text = VALUES(quote_text)'
        );
        $up->execute([
            ':p'   => $projectId,
            ':t'   => $tid,
            ':rid' => (int)$r['response_id'],
            ':txt' => mb_substr((string)$r['text'], 0, 800),
        ]);
        $picked++;
    }
    json_out(['ok' => true, 'picked' => $picked, 'skipped' => $skipped, 'total_missing' => count($missing)]);
}

fail('bad_input', 'Unknown action.');
