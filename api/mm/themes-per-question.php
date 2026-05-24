<?php
// POST /api/mm/themes-per-question.php
// Body: { project_id, question_column? }
//
// Step 7 of the 20-step flow: per-question theme generation.
//
// Reads responses grouped by the question column (recorded in field_map_json
// as "question_col" on the data source), or, if no question column is set,
// treats the whole project as a single question. For each question:
//   - asks Claude for 3-8 themes with name, definition, count, percentage,
//     example quotes, tone, confidence, and overlap with other themes
//   - persists themes to mm_theme_categories with question_id link
//   - registers the question in mm_open_questions if not already there
//
// Existing project-wide themes (no question_id) are deleted because this
// endpoint replaces them with per-question scoped themes.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

check_rate_limit('mm_themes_pq:user:' . $uid, 15, 3600);

$body          = read_json_body();
$projectId     = (int)($body['project_id'] ?? 0);
$qColOverride  = clean_string((string)($body['question_column'] ?? ''), 200);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// Determine the question column. Priority: body override, then the most
// recent data source's field_map_json.question_col, then NULL (single-question).
$qCol = $qColOverride;
if ($qCol === '') {
    $src = $pdo->prepare('SELECT field_map_json FROM mm_data_sources WHERE project_id = :p ORDER BY id DESC LIMIT 1');
    $src->execute([':p' => $projectId]);
    $fm = $src->fetchColumn();
    if ($fm) {
        $decoded = json_decode((string)$fm, true);
        if (is_array($decoded) && !empty($decoded['question_col'])) {
            $qCol = (string)$decoded['question_col'];
        }
    }
}

// Load responses. If we have a question column, we expect it to live in a
// generic JSON-like field; today the schema stores it in the text payload
// only when the user uploaded multi-question rows. For the 250x10 equity
// dataset, the question_id lives in the rows already because we ingest a
// flat structure. We re-derive by grouping mm_text_responses with the
// response's stored text -- but we need a real grouping field.
//
// Practical approach: we ingest the question id into the respondent_ref
// when it isn't a user-supplied id. To support both shapes, we accept
// either (a) a question column in respondent_ref / group_value / numeric
// (interpreted as string) or (b) no column, in which case all rows are
// grouped as one synthetic question.

// Pull all responses. mm_load_responses includes question_id_raw and
// question_text_raw when the Phase 157 columns are present; falls back
// gracefully otherwise.
$allRows = mm_load_responses($pdo, $projectId, 8000);
$total = count($allRows);
if ($total < 3) fail('insufficient_data', 'Need at least 3 responses.');

// Group rows by question. If the rows carry question_id_raw, use it.
// Otherwise treat the project as one big question bucket.
$groups = [];
$questionTextByKey = [];
$hasPerQuestion = false;
foreach ($allRows as $r) {
    $qkey = '__all__';
    if (array_key_exists('question_id_raw', $r) && $r['question_id_raw'] !== null && $r['question_id_raw'] !== '') {
        $qkey = (string)$r['question_id_raw'];
        $hasPerQuestion = true;
        if (!isset($questionTextByKey[$qkey])) {
            $qt = (string)($r['question_text_raw'] ?? '');
            $questionTextByKey[$qkey] = $qt;
        }
    }
    if (!isset($groups[$qkey])) $groups[$qkey] = [];
    $groups[$qkey][] = $r;
}
// If the user did not mark a question column, we still fall through with a
// single __all__ bucket and produce one set of themes for the whole project.

// Wipe existing categories for this project before re-running per-question.
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_theme_categories WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_open_questions WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_purge_failed', 'Could not clear existing themes: ' . $e->getMessage(), 500);
}

$questionsOut = [];
$position = 0;

foreach ($groups as $qid => $rows) {
    $rowCount = count($rows);
    if ($rowCount < 3) continue; // skip tiny groups; not enough signal

    // Insert question row (one per group). Use the captured question text
    // if available, otherwise fall back to the id or a friendly default.
    $qText = $questionTextByKey[$qid] ?? '';
    if ($qid === '__all__') {
        $qLabel = 'All responses';
    } elseif ($qText !== '') {
        $qLabel = $qid . ' - ' . $qText;
    } else {
        $qLabel = $qid;
    }
    $insQ = $pdo->prepare(
        'INSERT INTO mm_open_questions (project_id, qid, question_text, position)
         VALUES (:p, :q, :t, :pos)'
    );
    $insQ->execute([
        ':p'   => $projectId,
        ':q'   => mb_substr($qid, 0, 60),
        ':t'   => mb_substr($qLabel, 0, 2000),
        ':pos' => $position++,
    ]);
    $questionId = (int)$pdo->lastInsertId();

    // Sample up to 200 responses per question (the AI's prompt budget).
    $sample = $rows;
    if (count($rows) > 200) {
        $step = (int)floor(count($rows) / 200);
        if ($step < 1) $step = 1;
        $picked = [];
        for ($i = 0; $i < count($rows) && count($picked) < 200; $i += $step) {
            $picked[] = $rows[$i];
        }
        $sample = $picked;
    }
    $lines = [];
    foreach ($sample as $i => $r) {
        $t = (string)$r['text'];
        if (mb_strlen($t) > 500) $t = mb_substr($t, 0, 500) . '...';
        $lines[] = ($i + 1) . '. ' . $t;
    }

    $system = <<<SYS
You are a mixed-methods qualitative coder. The user gives you a numbered list of open-ended responses to ONE question. Produce a theme set of 3 to 8 themes describing the recurring patterns.

Rules:
- Themes are short noun phrases, 2-5 words, sentence case.
- A theme is a recurring pattern across multiple responses, not a paraphrase of one response.
- For each theme include:
    * "name" (short)
    * "definition" (one to two sentences)
    * "count" (rough integer estimate of how many responses fit)
    * "percent" (count divided by total, integer 0-100)
    * "tone" (positive, neutral, negative, mixed)
    * "confidence" (high, moderate, low)
    * "example_quotes" (2-3 verbatim short quotes from the responses)
    * "overlap_with" (list of other theme names this theme commonly co-occurs with, can be empty)
- Never invent quotes. Use verbatim short excerpts.
- 3 to 8 themes total. No more.

Output a single JSON object with this exact shape:

{
  "themes": [
    {
      "name":            "<short>",
      "definition":      "<1-2 sentences>",
      "count":           <int>,
      "percent":         <int 0-100>,
      "tone":            "positive|neutral|negative|mixed",
      "confidence":      "high|moderate|low",
      "example_quotes":  ["<quote>", "<quote>"],
      "overlap_with":    ["<theme name>"]
    }
  ],
  "summary": "<one sentence summary across all themes for this question>"
}

Output raw JSON only. No markdown fences. No prose around it.
SYS;

    $prompt  = "Question: " . $qLabel . "\n";
    $prompt .= "Total responses in this question: " . $rowCount . "\n";
    $prompt .= "Sample size shown: " . count($sample) . "\n\n";
    $prompt .= "Responses:\n" . implode("\n", $lines) . "\n\n";
    $prompt .= "Produce 3 to 8 themes for this question now.";

    try {
        $resp = ai_complete($system, [['role' => 'user', 'content' => $prompt]], 4000);
    } catch (Throwable $e) {
        // Skip this question on AI failure; keep the question row but no themes.
        $questionsOut[] = [
            'id' => $questionId, 'qid' => $qid, 'label' => $qLabel,
            'response_count' => $rowCount, 'themes' => [], 'summary' => '',
            'error' => 'AI call failed: ' . $e->getMessage(),
        ];
        continue;
    }

    $parsed = ai_extract_json($resp['text']);
    if (!$parsed || !isset($parsed['themes']) || !is_array($parsed['themes'])) {
        $questionsOut[] = [
            'id' => $questionId, 'qid' => $qid, 'label' => $qLabel,
            'response_count' => $rowCount, 'themes' => [], 'summary' => '',
            'error' => 'AI did not return usable JSON',
        ];
        continue;
    }

    $insC = $pdo->prepare(
        'INSERT INTO mm_theme_categories
         (project_id, question_id, name, description, definition, source_mode, confidence, tone, overlap_json, position)
         VALUES (:p, :qi, :n, :d, :df, :sm, :c, :t, :ov, :pos)'
    );

    $themesOut = [];
    $themePos = 0;
    foreach ($parsed['themes'] as $th) {
        if (!is_array($th)) continue;
        $name = clean_string((string)($th['name'] ?? ''), 200);
        if ($name === '') continue;
        $def  = clean_string((string)($th['definition'] ?? ''), 1000);
        $tone = strtolower(clean_string((string)($th['tone'] ?? 'neutral'), 16));
        if (!in_array($tone, ['positive','neutral','negative','mixed'], true)) $tone = 'neutral';
        $conf = strtolower(clean_string((string)($th['confidence'] ?? 'moderate'), 16));
        if (!in_array($conf, ['high','moderate','low'], true)) $conf = 'moderate';
        $count = (int)($th['count'] ?? 0);
        $percent = (int)($th['percent'] ?? 0);
        $examples = [];
        if (isset($th['example_quotes']) && is_array($th['example_quotes'])) {
            foreach ($th['example_quotes'] as $ex) {
                $exClean = clean_string((string)$ex, 600);
                if ($exClean !== '') $examples[] = $exClean;
                if (count($examples) === 3) break;
            }
        }
        $overlap = [];
        if (isset($th['overlap_with']) && is_array($th['overlap_with'])) {
            foreach ($th['overlap_with'] as $ow) {
                $ow = clean_string((string)$ow, 200);
                if ($ow !== '') $overlap[] = $ow;
            }
        }
        $insC->execute([
            ':p'  => $projectId,
            ':qi' => $questionId,
            ':n'  => $name,
            ':d'  => mb_substr($def, 0, 600),
            ':df' => $def,
            ':sm' => 'hybrid',
            ':c'  => $conf,
            ':t'  => $tone,
            ':ov' => json_encode($overlap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':pos'=> ++$themePos,
        ]);
        $themesOut[] = [
            'id'             => (int)$pdo->lastInsertId(),
            'name'           => $name,
            'definition'     => $def,
            'count'          => $count,
            'percent'        => $percent,
            'tone'           => $tone,
            'confidence'     => $conf,
            'example_quotes' => $examples,
            'overlap_with'   => $overlap,
        ];
    }

    $questionsOut[] = [
        'id'             => $questionId,
        'qid'            => $qid,
        'label'          => $qLabel,
        'response_count' => $rowCount,
        'sample_size'    => count($sample),
        'themes'         => $themesOut,
        'summary'        => clean_string((string)($parsed['summary'] ?? ''), 600),
    ];
}

json_out([
    'ok'              => true,
    'total_responses' => $total,
    'question_count'  => count($questionsOut),
    'questions'       => $questionsOut,
    'model'           => ai_config()['model'],
]);
