<?php
// GET /api/help/search.php?q=<query>&limit=<n>
//
// Case-insensitive substring + word-token search across the help index.
// Matches against question, answer, and category title. Each match gets
// a relevance score:
//   +10  exact phrase in question
//   +6   exact phrase in answer
//   +3   per token in question (word match)
//   +1   per token in answer
//   +1   per token in category title
// Results sorted by score desc, capped at $limit (default 20, max 50).
// Snippet returns the first ~180 chars of the answer with the query
// wrapped in <mark>...</mark> for the first match.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_help_index.php';

require_method('GET');

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1) $limit = 1;
if ($limit > 50) $limit = 50;

if ($q === '' || mb_strlen($q) < 2) {
    json_out(['ok' => true, 'query' => $q, 'results' => [], 'total' => 0]);
}

$qLower = mb_strtolower($q);
$tokens = array_values(array_filter(
    preg_split('/\W+/u', $qLower) ?: [],
    static fn ($t) => mb_strlen((string)$t) >= 2
));

$out = [];
foreach (help_index() as $row) {
    $qText = mb_strtolower((string)$row['q']);
    $aText = mb_strtolower((string)$row['a']);
    $cText = mb_strtolower((string)$row['t']);

    $score = 0;
    if (mb_strpos($qText, $qLower) !== false) $score += 10;
    if (mb_strpos($aText, $qLower) !== false) $score += 6;
    foreach ($tokens as $tok) {
        if (mb_strpos($qText, $tok) !== false) $score += 3;
        if (mb_strpos($aText, $tok) !== false) $score += 1;
        if (mb_strpos($cText, $tok) !== false) $score += 1;
    }
    if ($score === 0) continue;

    // Build snippet: pick the first match position and grab a window.
    $needles = [$qLower];
    foreach ($tokens as $tok) { if ($tok !== $qLower) $needles[] = $tok; }
    $pos = false;
    $hit = '';
    foreach ($needles as $n) {
        $p = mb_strpos($aText, $n);
        if ($p !== false) { $pos = $p; $hit = $n; break; }
    }
    $snippet = '';
    if ($pos !== false) {
        $start = max(0, $pos - 60);
        $end   = min(mb_strlen($row['a']), $pos + mb_strlen($hit) + 120);
        $snippet = ($start > 0 ? '...' : '') .
            mb_substr($row['a'], $start, $end - $start) .
            ($end < mb_strlen($row['a']) ? '...' : '');
    } else {
        $snippet = mb_substr($row['a'], 0, 180) . (mb_strlen($row['a']) > 180 ? '...' : '');
    }

    $out[] = [
        'category'      => $row['t'],
        'category_slug' => $row['s'],
        'question'      => $row['q'],
        'snippet'       => $snippet,
        'url'           => '/help/' . $row['s'] . '.html#h-' . $row['h'],
        'score'         => $score,
    ];
}

usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
$total = count($out);
if ($total > $limit) $out = array_slice($out, 0, $limit);

json_out([
    'ok'      => true,
    'query'   => $q,
    'results' => $out,
    'total'   => $total,
]);
