<?php
// POST /api/analysis/ai-inferential-report.php
// Body: { project_id:int, analyses:[ { test, variables, stats, finding } ], sample:{ n, k } }
//   analyses are the saved inferential results the client already computed
//   (the exact statistics shown on screen). No raw data is sent.
//
// Returns: { ok:true, report:{ headline, overview, synthesis, limitations:[...] }, model }
//
// Writes the narrative layer of a hybrid inferential report with ReliCheck
// Intelligence. Unlike the descriptive report, this MAY discuss statistical
// significance (the tests were run) but must use ONLY the provided numbers and
// must not imply causation beyond what the design supports.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
release_session_lock();

check_rate_limit('ai_inferential_report:user:' . (int)$user['id'], 30, 3600);

$body      = read_json_body();
// project_id may arrive in the JSON body or the query string (belt and suspenders
// against redirects that drop the POST body).
$projectId = (int)($body['project_id'] ?? ($_GET['project_id'] ?? 0));
$analyses  = $body['analyses'] ?? null;
$sample    = is_array($body['sample'] ?? null) ? $body['sample'] : [];
if ($projectId < 1)        fail('bad_input', 'project_id is required.');
if (!is_array($analyses) || !count($analyses)) fail('bad_input', 'At least one saved analysis is required.');

// Ownership check.
$pdo  = db();
$stmt = $pdo->prepare('SELECT id, title FROM analysis_projects WHERE id = :id AND user_id = :uid AND status = "active" LIMIT 1');
$stmt->execute([':id' => $projectId, ':uid' => (int)$user['id']]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) fail('not_found', 'Analysis project not found.', 404);

// Bound the payload: keep only the fields the model needs, capped.
$clean = [];
foreach (array_slice($analyses, 0, 40) as $a) {
    if (!is_array($a)) continue;
    $clean[] = [
        'test'      => clean_string((string)($a['test'] ?? ''), 120),
        'variables' => clean_string((string)($a['variables'] ?? ''), 200),
        'stats'     => clean_string((string)($a['stats'] ?? ''), 300),
        'finding'   => clean_string((string)($a['finding'] ?? ''), 600),
    ];
}
$payload = [
    'sample'   => ['n' => (int)($sample['n'] ?? 0), 'variables' => (int)($sample['k'] ?? 0)],
    'analyses' => $clean,
];
$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($payloadJson === false || strlen($payloadJson) > 20000) {
    fail('bad_input', 'Analysis payload is too large.');
}

$system =
    "You are ReliCheck Intelligence, writing the narrative layer of an inferential "
  . "statistics report for a researcher who is not a statistician. You are given the "
  . "exact results of the statistical tests they already ran (test name, variables, the "
  . "reported statistics, and a plain finding). Write the connective narrative that turns "
  . "these results into a readable report.\n\n"
  . "Strict rules:\n"
  . "- Use ONLY the numbers and findings provided. Never invent values, tests, variables, or p-values.\n"
  . "- You MAY state whether a result was statistically significant, because these tests were run. "
  . "Reflect the provided p-values faithfully (p < .05 significant). Do not upgrade a non-significant result.\n"
  . "- Do NOT imply causation. Group differences and associations are not causal unless an experiment is described, and none is.\n"
  . "- Refer to tests, variables, and statistics by their real names and quote the specific numbers.\n"
  . "- Plain language a non-expert understands. Define a term briefly the first time if needed.\n"
  . "- Do not use em dashes anywhere. Use periods or commas.\n\n"
  . "Return ONLY a JSON object, no prose around it, in exactly this shape:\n"
  . "{\n"
  . "  \"headline\": \"one sentence capturing the main finding across the analyses\",\n"
  . "  \"overview\": \"one short paragraph: the sample, what questions were tested, and the big picture\",\n"
  . "  \"synthesis\": \"one or two paragraphs connecting the results: what holds together, what stands out, what it means in plain terms\",\n"
  . "  \"limitations\": [ \"a short, honest caution about interpreting these results\" ]\n"
  . "}\n"
  . "Keep limitations to 2 or 3 items (e.g. sample size, multiple comparisons, no causation, assumptions).";

$userMsg = "Project title: " . (string)$project['title'] . "\n\nSaved inferential results (JSON):\n" . $payloadJson;

$res = ai_complete($system, [['role' => 'user', 'content' => $userMsg]], 1800);

$report = ai_extract_json($res['text'] ?? '');
if (!is_array($report) || !isset($report['headline'])) {
    fail('ai_bad_response', 'The report narrative could not be generated. Please try again.', 502);
}

$out = [
    'headline'    => clean_string((string)($report['headline'] ?? ''), 300),
    'overview'    => clean_string((string)($report['overview'] ?? ''), 2000),
    'synthesis'   => clean_string((string)($report['synthesis'] ?? ''), 4000),
    'limitations' => [],
];
if (is_array($report['limitations'] ?? null)) {
    foreach (array_slice($report['limitations'], 0, 5) as $c) {
        $c = clean_string((string)$c, 400);
        if ($c !== '') $out['limitations'][] = $c;
    }
}

json_out(['ok' => true, 'report' => $out, 'model' => $res['raw']['model'] ?? null]);
