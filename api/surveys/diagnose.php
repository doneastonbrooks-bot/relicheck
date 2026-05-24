<?php
// GET /api/surveys/diagnose.php?id=<survey_id>
// Owner-only read-only diagnostic. Compares the survey's question ids against
// the actual answer keys present in the responses table. Useful for finding
// "0 in the dashboard but rows in the database" mismatches.
//
// Additive endpoint. Read-only. Touches nothing.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid survey id.');

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, owner_id, slug, title, questions
       FROM surveys WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$questions = json_decode((string)$row['questions'], true);
if (!is_array($questions)) $questions = [];
$questionIds   = [];
$questionTypes = [];
$likertIds     = [];
foreach ($questions as $q) {
    if (!is_array($q)) continue;
    $qid = (string)($q['id'] ?? '');
    if ($qid === '') continue;
    $questionIds[]   = $qid;
    $questionTypes[$qid] = (string)($q['type'] ?? '');
    if (($q['type'] ?? null) === 'likert') $likertIds[] = $qid;
}

// Pull every response for the survey and tally answer keys.
$rstmt = $pdo->prepare(
    'SELECT id, submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC'
);
$rstmt->execute([':sid' => $id]);
$rows = $rstmt->fetchAll();

$totalResponses = count($rows);
$keyCounts      = []; // key -> count of responses containing that key
$sampleAnswers  = []; // first 3 raw answer keys
$completeLikert = 0;
foreach ($rows as $i => $r) {
    $a = json_decode((string)$r['answers'], true);
    if (!is_array($a)) continue;
    foreach (array_keys($a) as $k) {
        $keyCounts[$k] = ($keyCounts[$k] ?? 0) + 1;
    }
    if (count($sampleAnswers) < 3) {
        $sampleAnswers[] = [
            'response_id' => (int)$r['id'],
            'submitted_at' => $r['submitted_at'],
            'keys' => array_keys($a),
        ];
    }
    // "Complete Likert" = every Likert question id present and is numeric in this answer.
    if (count($likertIds) > 0) {
        $allOk = true;
        foreach ($likertIds as $lid) {
            if (!isset($a[$lid]) || !is_numeric($a[$lid])) { $allOk = false; break; }
        }
        if ($allOk) $completeLikert++;
    }
}

$matchedKeys   = array_values(array_intersect($questionIds, array_keys($keyCounts)));
$orphanKeys    = array_values(array_diff(array_keys($keyCounts), $questionIds));
$missingForLikert = array_values(array_diff($likertIds, array_keys($keyCounts)));

json_out([
    'survey' => [
        'id'    => (int)$row['id'],
        'slug'  => $row['slug'],
        'title' => $row['title'],
        'question_count' => count($questionIds),
        'likert_count'   => count($likertIds),
    ],
    'responses' => [
        'total_in_db'           => $totalResponses,
        'complete_likert_rows'  => $completeLikert,
        'sample'                => $sampleAnswers,
    ],
    'keys' => [
        'survey_question_ids'   => $questionIds,
        'survey_likert_ids'     => $likertIds,
        'answer_key_counts'     => $keyCounts,
        'matched_keys'          => $matchedKeys,
        'orphan_answer_keys'    => $orphanKeys,
        'likert_keys_never_present' => $missingForLikert,
    ],
    'diagnosis' => $totalResponses === 0
        ? 'No rows in the responses table for this survey_id. Check whether the responses are tied to a different survey_id.'
        : (count($matchedKeys) === 0
            ? 'Responses exist in the DB but NONE of their answer keys match the survey\'s question ids. Likely cause: the survey questions were rebuilt and got fresh ids, leaving stored answers orphaned. The dashboard would correctly show 0 because no answer keys point at any current question.'
            : (count($missingForLikert) === count($likertIds) && count($likertIds) > 0
                ? 'Responses exist but no answer keys match the survey\'s LIKERT question ids. Reliability stats need at least two Likert items, so the dashboard shows N=0.'
                : 'Responses exist and at least some keys match. If the dashboard still shows 0, the issue is likely in the front-end (refreshResponses or analytics rendering), not the data.'
            )
        ),
]);
