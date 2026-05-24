<?php
// POST /api/mm/alignment.php
// Body: { project_id, score_label? }
//
// Evidence Alignment Check. Sends the AI the numeric distribution plus a
// random-ish sample of comments and asks whether the numbers and the
// comments agree, conflict, are nuanced, or are insufficient. Stores result
// rows in mm_evidence_alignment_results so the front end can reload them.

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

check_rate_limit('mm_alignment:user:' . $uid, 30, 3600);

$body       = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$scoreLabel = clean_string((string)($body['score_label'] ?? 'Score'), 120);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

$stmt = $pdo->prepare(
    'SELECT numeric_value, text FROM mm_text_responses
     WHERE project_id = :p AND numeric_value IS NOT NULL ORDER BY id ASC LIMIT 300'
);
$stmt->execute([':p' => $projectId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($rows) < 5) fail('insufficient_data', 'Need at least 5 responses with a score.');

$values = array_map(fn($r) => (float)$r['numeric_value'], $rows);
sort($values);
$n = count($values);
$mean = array_sum($values) / $n;
$median = $n % 2 === 1 ? $values[(int)floor($n / 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;

$texts = [];
foreach ($rows as $r) {
    $t = (string)$r['text'];
    if (strlen($t) > 400) $t = substr($t, 0, 400) . '...';
    $texts[] = '(' . number_format((float)$r['numeric_value'], 2) . ') ' . $t;
}

$prompt  = "Measure: " . $scoreLabel . "\n";
$prompt .= "n=" . $n . ", mean=" . number_format($mean, 2) . ", median=" . number_format($median, 2) . ".\n\n";
$prompt .= "Comments with score in parentheses:\n" . implode("\n", array_slice($texts, 0, 120)) . "\n";

$system = <<<SYS
You are a mixed-methods analyst doing an evidence alignment check. Classify each finding as one of:
- "aligned":     score and comments point the same direction
- "divergent":   they appear to conflict
- "nuanced":     comments add context the score alone hides
- "insufficient": not enough evidence to call

Return JSON only:

{
  "findings": [
    {
      "quant_label":   "<short label>",
      "quant_value":   "<short number or pattern>",
      "qual_evidence": "<short theme or pattern>",
      "alignment":     "aligned" | "divergent" | "nuanced" | "insufficient",
      "confidence":    "high" | "moderate" | "low",
      "interpretation":"<one sentence>",
      "next_step":     "<one sentence>"
    }
  ],
  "summary": "<one sentence overall>"
}

Aim for 3-6 findings. Do not wrap the JSON in markdown fences.
SYS;

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $prompt],
], 2500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !isset($parsed['findings']) || !is_array($parsed['findings'])) {
    fail('ai_parse_failed', 'AI did not return a usable response. Try again.', 502);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM mm_evidence_alignment_results WHERE project_id = :p')->execute([':p' => $projectId]);
    $ins = $pdo->prepare(
        'INSERT INTO mm_evidence_alignment_results
         (project_id, quant_label, quant_value, qual_evidence, alignment, confidence, interpretation, next_step)
         VALUES (:p, :ql, :qv, :qe, :a, :c, :i, :ns)'
    );

    $findings = [];
    foreach ($parsed['findings'] as $f) {
        if (!is_array($f)) continue;
        $align = strtolower(clean_string((string)($f['alignment'] ?? 'nuanced'), 16));
        if (!in_array($align, ['aligned','divergent','nuanced','insufficient'], true)) $align = 'nuanced';
        $conf = strtolower(clean_string((string)($f['confidence'] ?? 'moderate'), 16));
        if (!in_array($conf, ['high','moderate','low'], true)) $conf = 'moderate';

        $row = [
            'quant_label'    => clean_string((string)($f['quant_label']    ?? ''), 200),
            'quant_value'    => clean_string((string)($f['quant_value']    ?? ''), 120),
            'qual_evidence'  => clean_string((string)($f['qual_evidence']  ?? ''), 600),
            'alignment'      => $align,
            'confidence'     => $conf,
            'interpretation' => clean_string((string)($f['interpretation'] ?? ''), 1200),
            'next_step'      => clean_string((string)($f['next_step']      ?? ''), 1200),
        ];
        if ($row['quant_label'] === '') continue;
        $ins->execute([
            ':p'  => $projectId,
            ':ql' => $row['quant_label'],
            ':qv' => $row['quant_value'],
            ':qe' => $row['qual_evidence'],
            ':a'  => $row['alignment'],
            ':c'  => $row['confidence'],
            ':i'  => $row['interpretation'],
            ':ns' => $row['next_step'],
        ]);
        $findings[] = $row;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_alignment_failed', 'Could not save alignment results: ' . $e->getMessage(), 500);
}

json_out([
    'ok'       => true,
    'findings' => $findings,
    'summary'  => clean_string((string)($parsed['summary'] ?? ''), 600),
    'model'    => ai_config()['model'],
]);
