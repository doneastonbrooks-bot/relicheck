<?php
// POST /api/mm/report.php
// Body: { project_id, title? }
//
// Generates the Mixed-Methods Report. Pulls every saved analysis for the
// project and asks the AI to compose an executive-style report. Stores both
// the body_html and a structured summary_json so the front end can re-render
// for docx, pptx, xlsx exports without re-billing the AI.

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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);
    $stmt = $pdo->prepare('SELECT * FROM mm_reports WHERE project_id = :p ORDER BY id DESC LIMIT 1');
    $stmt->execute([':p' => $projectId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => true, 'report' => null]);
    json_out(['ok' => true, 'report' => [
        'id'         => (int)$r['id'],
        'title'      => (string)$r['title'],
        'body_html'  => (string)($r['body_html'] ?? ''),
        'summary'    => json_decode((string)($r['summary_json'] ?? ''), true),
        'created_at' => (string)$r['created_at'],
    ]]);
}

check_rate_limit('mm_report:user:' . $uid, 10, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$title     = clean_string((string)($body['title'] ?? 'Mixed-Methods Report'), 200);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
$project = mm_require_project($pdo, $uid, $projectId);

$inputs = [];

// Categories.
$cs = $pdo->prepare(
    'SELECT c.name, c.description, c.confidence,
            (SELECT COUNT(*) FROM mm_coded_responses cr WHERE cr.category_id = c.id) AS n
     FROM mm_theme_categories c WHERE c.project_id = :p ORDER BY n DESC LIMIT 10'
);
$cs->execute([':p' => $projectId]);
$inputs['categories'] = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Sentiment.
$ss = $pdo->prepare('SELECT sentiment, COUNT(*) AS n FROM mm_sentiment_scores WHERE project_id = :p GROUP BY sentiment');
$ss->execute([':p' => $projectId]);
$inputs['sentiment'] = $ss->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Alignment.
$as = $pdo->prepare('SELECT quant_label, quant_value, qual_evidence, alignment, interpretation FROM mm_evidence_alignment_results WHERE project_id = :p LIMIT 12');
$as->execute([':p' => $projectId]);
$inputs['alignment'] = $as->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Matrix.
$ms = $pdo->prepare('SELECT measure, quant_result, theme_evidence, sentiment, interpretation, recommended_action FROM mm_evidence_matrices WHERE project_id = :p ORDER BY position ASC LIMIT 12');
$ms->execute([':p' => $projectId]);
$inputs['matrix'] = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Response count.
$inputs['response_count'] = mm_response_count($pdo, $projectId);
$inputs['title']    = $title;
$inputs['pathway']  = (string)$project['pathway'];

if ($inputs['response_count'] === 0) {
    fail('mm_no_data', 'Add data and run at least one analysis before generating a report.');
}

$promptJson = json_encode($inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$system = <<<SYS
You are a mixed-methods analyst writing a user-ready report. The user gives you the project inputs as JSON. Produce a clear, plain-language report. Do not overclaim causality. Use phrases like "the evidence suggests", "comments point to", "associated with". Avoid em dashes; use commas, periods, parentheses, semicolons, or colons.

Return JSON only:

{
  "executive_summary": "<2-4 sentence plain-language overview>",
  "key_findings":      ["<finding>", "<finding>", "..."],
  "evidence_alignment":"<2-3 sentence read on agreement vs. divergence>",
  "group_differences": "<1-3 sentence note, or empty string>",
  "recommendations":   ["<recommendation>", "..."],
  "follow_up_questions":["<question>", "..."],
  "method_notes":      "<1-2 sentence note on limitations and assumptions>"
}

Aim for 3-6 key findings, 3-5 recommendations, 3-5 follow-up questions. Do not wrap the JSON in markdown fences.
SYS;

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => "Project inputs:\n" . $promptJson],
], 3500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed) {
    fail('ai_parse_failed', 'AI did not return a usable response. Try again.', 502);
}

// Compose HTML body.
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$bullets = function ($arr) use ($h) {
    if (!is_array($arr) || count($arr) === 0) return '';
    $html = '<ul>';
    foreach ($arr as $item) {
        $val = clean_string((string)$item, 1500);
        if ($val !== '') $html .= '<li>' . $h($val) . '</li>';
    }
    return $html . '</ul>';
};

$html  = '<h1>' . $h($title) . '</h1>';
$html .= '<p><strong>Executive summary.</strong> ' . $h(clean_string((string)($parsed['executive_summary'] ?? ''), 2000)) . '</p>';
$html .= '<h2>Key findings</h2>' . $bullets($parsed['key_findings'] ?? []);
$html .= '<h2>Evidence alignment</h2><p>' . $h(clean_string((string)($parsed['evidence_alignment'] ?? ''), 2000)) . '</p>';
$gd = clean_string((string)($parsed['group_differences'] ?? ''), 2000);
if ($gd !== '') $html .= '<h2>Group differences</h2><p>' . $h($gd) . '</p>';
$html .= '<h2>Recommendations</h2>'      . $bullets($parsed['recommendations']     ?? []);
$html .= '<h2>Follow-up questions</h2>'  . $bullets($parsed['follow_up_questions'] ?? []);
$html .= '<h2>Method notes</h2><p>'      . $h(clean_string((string)($parsed['method_notes'] ?? ''), 2000)) . '</p>';

$stmt = $pdo->prepare(
    'INSERT INTO mm_reports (project_id, title, body_html, summary_json)
     VALUES (:p, :t, :b, :s)'
);
$stmt->execute([
    ':p' => $projectId,
    ':t' => $title,
    ':b' => $html,
    ':s' => json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);

json_out([
    'ok'        => true,
    'report_id' => (int)$pdo->lastInsertId(),
    'title'     => $title,
    'body_html' => $html,
    'summary'   => $parsed,
    'model'     => ai_config()['model'],
]);
