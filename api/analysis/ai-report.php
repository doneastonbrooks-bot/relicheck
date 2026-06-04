<?php
// POST /api/analysis/ai-report.php
// Body: { project_id:int, summary:{...} }
//   summary is the compact descriptive result the client already computed
//   (frequencies, numerics, group profile) — the same numbers shown on screen.
//
// Returns: { ok:true, report:{ headline, overview, sections:[{heading,body}], cautions:[...] }, model }
//
// Writes a plain-language descriptive-analysis report with ReliCheck Intelligence.
// The model is given ONLY the computed numbers and must not invent data, claim
// statistical significance, or imply causation (that is the Inferential Studio).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
release_session_lock();

check_rate_limit('ai_analysis_report:user:' . (int)$user['id'], 30, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$summary   = $body['summary'] ?? null;
if ($projectId < 1)        fail('bad_input', 'project_id is required.');
if (!is_array($summary))   fail('bad_input', 'summary is required.');

// Ownership check.
$pdo  = db();
$stmt = $pdo->prepare('SELECT id, title FROM analysis_projects WHERE id = :id AND user_id = :uid AND status = "active" LIMIT 1');
$stmt->execute([':id' => $projectId, ':uid' => (int)$user['id']]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) fail('not_found', 'Analysis project not found.', 404);

// Keep the payload bounded.
$summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($summaryJson === false || strlen($summaryJson) > 20000) {
    fail('bad_input', 'Summary payload is too large.');
}

$system =
    "You are ReliCheck Intelligence, writing a clear descriptive-analysis report for a "
  . "researcher who is not a statistician. You are given the descriptive statistics that "
  . "were already computed from their dataset (frequencies, means and distributions, and "
  . "group profiles). Write a readable narrative report of what the data shows.\n\n"
  . "Strict rules:\n"
  . "- Use ONLY the numbers provided. Never invent values, variables, or counts.\n"
  . "- This is DESCRIPTIVE only. Do not claim statistical significance, do not say a "
  . "difference is 'significant', and do not imply causation. Differences between groups "
  . "are observations to note, not tested effects.\n"
  . "- Refer to variables and values by their real names. Quote specific numbers.\n"
  . "- Plain language a non-expert understands. No jargon without a short gloss.\n"
  . "- Do not use em dashes anywhere. Use periods or commas.\n\n"
  . "Return ONLY a JSON object, no prose around it, in exactly this shape:\n"
  . "{\n"
  . "  \"headline\": \"one sentence summarizing the dataset's story\",\n"
  . "  \"overview\": \"one short paragraph on the sample and what is in the data\",\n"
  . "  \"sections\": [ { \"heading\": \"short title\", \"body\": \"one or two paragraphs\" } ],\n"
  . "  \"cautions\": [ \"a short caution about reading these descriptives\" ]\n"
  . "}\n"
  . "Aim for 3 to 4 sections covering distribution of key variables, notable group "
  . "differences, and what to look at next. Keep cautions to 2 or 3 items.";

$userMsg = "Project title: " . (string)$project['title'] . "\n\nComputed descriptive statistics (JSON):\n" . $summaryJson;

$res = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 1800);

$report = ai_extract_json($res['text'] ?? '');
if (!is_array($report) || !isset($report['headline'])) {
    fail('ai_bad_response', 'The report could not be generated. Please try again.', 502);
}

// Normalize / clamp shape.
$out = [
    'headline' => clean_string((string)($report['headline'] ?? ''), 300),
    'overview' => clean_string((string)($report['overview'] ?? ''), 2000),
    'sections' => [],
    'cautions' => [],
];
if (is_array($report['sections'] ?? null)) {
    foreach (array_slice($report['sections'], 0, 6) as $s) {
        if (!is_array($s)) continue;
        $out['sections'][] = [
            'heading' => clean_string((string)($s['heading'] ?? ''), 200),
            'body'    => clean_string((string)($s['body'] ?? ''), 3000),
        ];
    }
}
if (is_array($report['cautions'] ?? null)) {
    foreach (array_slice($report['cautions'], 0, 5) as $c) {
        $c = clean_string((string)$c, 400);
        if ($c !== '') $out['cautions'][] = $c;
    }
}

json_out(['ok' => true, 'report' => $out, 'model' => $res['raw']['model'] ?? null]);
