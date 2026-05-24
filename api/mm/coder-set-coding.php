<?php
// Phase 181 — Row-level coder write. Lets ANY coder (owner or accepted
// second coder) set or clear their OWN coding for one response under one
// theme. Always scoped to coder_id = current user — a coder can never
// modify another coder's row.
//
// POST { project_id, response_id, category_id, action: 'set'|'clear',
//        confidence?: 'low'|'moderate'|'high',
//        intensity?:  'low'|'moderate'|'high',
//        relevance?:  'usable'|'unclear'|'noise',
//        quote_worthy?: bool }
//
// 'set'   → INSERT/UPDATE the current user's coding for this (response,
//            theme) pair. Defaults: confidence='moderate', intensity='moderate',
//            relevance='usable', quote_worthy=false.
// 'clear' → DELETE the current user's coding for this pair. Other coders'
//            codings are untouched.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();

$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body = read_json_body();
$projectId  = (int)($body['project_id']  ?? 0);
$responseId = (int)($body['response_id'] ?? 0);
$categoryId = (int)($body['category_id'] ?? 0);
$action     = (string)($body['action']    ?? '');
if ($projectId <= 0 || $responseId <= 0 || $categoryId <= 0) {
    fail('bad_input', 'project_id, response_id, and category_id are required.', 400);
}
if ($action !== 'set' && $action !== 'clear') {
    fail('bad_action', "action must be 'set' or 'clear'.", 400);
}

// Owner OR accepted coder.
$project = mm_require_project_or_coder($pdo, $uid, $projectId);

// Verify response belongs to project (defense in depth — without this a
// coder on project A could write a row for a response in project B).
$check = $pdo->prepare('SELECT 1 FROM mm_text_responses WHERE id = :r AND project_id = :p');
$check->execute([':r' => $responseId, ':p' => $projectId]);
if (!$check->fetchColumn()) {
    fail('response_not_in_project', 'That response does not belong to this project.', 404);
}
// Same check on the category.
$check2 = $pdo->prepare('SELECT 1 FROM mm_theme_categories WHERE id = :c AND project_id = :p');
$check2->execute([':c' => $categoryId, ':p' => $projectId]);
if (!$check2->fetchColumn()) {
    fail('category_not_in_project', 'That theme does not belong to this project.', 404);
}

if ($action === 'clear') {
    $del = $pdo->prepare(
        'DELETE FROM mm_coded_responses ' .
        ' WHERE project_id = :p AND response_id = :r AND category_id = :c AND coder_id = :u'
    );
    $del->execute([':p' => $projectId, ':r' => $responseId, ':c' => $categoryId, ':u' => $uid]);
    json_out(['ok' => true, 'action' => 'clear', 'deleted' => $del->rowCount()]);
}

// 'set' — upsert with sane defaults.
$validEnum = function (string $v, array $allowed, string $fallback): string {
    return in_array($v, $allowed, true) ? $v : $fallback;
};
$confidence  = $validEnum((string)($body['confidence']  ?? 'moderate'), ['low','moderate','high'],   'moderate');
$intensity   = $validEnum((string)($body['intensity']   ?? 'moderate'), ['low','moderate','high'],   'moderate');
$relevance   = $validEnum((string)($body['relevance']   ?? 'usable'),   ['usable','unclear','noise'], 'usable');
$quoteWorthy = !empty($body['quote_worthy']) ? 1 : 0;

$upsert = $pdo->prepare(
    'INSERT INTO mm_coded_responses ' .
    ' (project_id, response_id, category_id, coder_id, confidence, intensity, relevance, quote_worthy) ' .
    ' VALUES (:p, :r, :c, :u, :cf, :int, :rel, :qw) ' .
    ' ON DUPLICATE KEY UPDATE ' .
    '   confidence   = VALUES(confidence), ' .
    '   intensity    = VALUES(intensity), ' .
    '   relevance    = VALUES(relevance), ' .
    '   quote_worthy = VALUES(quote_worthy)'
);
$upsert->execute([
    ':p'   => $projectId,
    ':r'   => $responseId,
    ':c'   => $categoryId,
    ':u'   => $uid,
    ':cf'  => $confidence,
    ':int' => $intensity,
    ':rel' => $relevance,
    ':qw'  => $quoteWorthy,
]);

json_out([
    'ok'         => true,
    'action'     => 'set',
    'coder_id'   => $uid,
    'role'       => (string)$project['mm_role'],
    'confidence' => $confidence,
    'intensity'  => $intensity,
    'relevance'  => $relevance,
    'quote_worthy' => (bool)$quoteWorthy,
]);
