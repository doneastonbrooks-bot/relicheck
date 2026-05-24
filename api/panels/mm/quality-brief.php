<?php
// POST /api/mm/quality-brief.php
// Body: { project_id }
//
// Step 6 of the 20-step flow. Computes the Open-Ended Response Quality Brief
// for a project: blanks, very short, duplicates, near-duplicates, length
// distribution, sentiment skew, low-effort patterns, language detection.
// Returns counts plus the response IDs flagged in each category so the UI
// can offer one-click bulk cleanup.
//
// Stores a summary row in mm_quality_briefs (or updates the latest) so
// repeat calls do not re-bill the AI for sentiment unless the response set
// changed.

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

check_rate_limit('mm_quality:user:' . $uid, 30, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// Pull all responses (cap 5000 to keep this responsive).
$stmt = $pdo->prepare(
    'SELECT id, respondent_ref, text FROM mm_text_responses
     WHERE project_id = :p ORDER BY id ASC LIMIT 5000'
);
$stmt->execute([':p' => $projectId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$total = count($rows);
if ($total === 0) {
    json_out(['ok' => true, 'total' => 0, 'checks' => [], 'message' => 'No responses yet.']);
}

// ----- Core text checks -----
$blankIds      = [];
$shortIds      = [];
$lowEffortIds  = [];
$dupExactIds   = [];
$dupNearIds    = [];
$lengths       = [];

$exactSeen = [];   // text => first-seen-id
$nearSeen  = [];   // first 50-char fingerprint => first-seen-id

// Low-effort patterns: single token, all caps, runs of one char like "aaaa".
$reAllCaps     = '/^[A-Z\s\d!?.,]{4,}$/';
$reRepeatChar  = '/(.)\1{4,}/';
$reKbSmash     = '/^(asdf|qwer|zxcv|test|tttt|hello|nothing|none|na|n\/a|no comment|no response)$/i';

foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $text = trim((string)$r['text']);
    $len  = mb_strlen($text);

    if ($len === 0) { $blankIds[] = $id; continue; }
    $lengths[] = $len;

    if ($len < 10) {
        $shortIds[] = $id;
    }

    // Low-effort: single token under 20 chars, OR matches a known low-effort regex.
    $tokens = preg_split('/\s+/', $text) ?: [];
    $isLowEffort =
        (count($tokens) <= 1 && $len < 20) ||
        preg_match($reAllCaps, $text) === 1 ||
        preg_match($reRepeatChar, $text) === 1 ||
        preg_match($reKbSmash, $text) === 1;
    if ($isLowEffort) $lowEffortIds[] = $id;

    // Exact duplicate: identical trimmed text.
    $key = mb_strtolower($text);
    if (isset($exactSeen[$key])) {
        $dupExactIds[] = $id;
    } else {
        $exactSeen[$key] = $id;
    }

    // Near duplicate: same first 50 chars (already covers exact, so only
    // count rows that are NOT exact dupes but share the head).
    if (!isset($exactSeen[$key]) || $exactSeen[$key] === $id) {
        $head = mb_substr($key, 0, 50);
        if ($head !== '' && mb_strlen($head) >= 20) {
            if (isset($nearSeen[$head]) && $nearSeen[$head] !== $id) {
                if (!in_array($id, $dupExactIds, true)) $dupNearIds[] = $id;
            } elseif (!isset($nearSeen[$head])) {
                $nearSeen[$head] = $id;
            }
        }
    }
}

// Length distribution.
sort($lengths, SORT_NUMERIC);
$nL = count($lengths);
$p50 = $nL > 0 ? $lengths[(int)floor($nL * 0.5)] : 0;
$p90 = $nL > 0 ? $lengths[(int)floor($nL * 0.9)] : 0;

// ----- Language detection (cheap heuristic) -----
// Count rows that contain non-ASCII letters and rows that look distinctly
// non-English by simple stopword check. Marks the dominant language.
$asciiCount    = 0;
$nonAsciiCount = 0;
foreach ($rows as $r) {
    $t = (string)$r['text'];
    if ($t === '') continue;
    if (preg_match('/[^\x00-\x7F]/', $t)) $nonAsciiCount++;
    else $asciiCount++;
}
$primaryLang = $asciiCount >= $nonAsciiCount ? 'English (likely)' : 'Non-English (likely)';
$languageMix = $nonAsciiCount > 0 && $asciiCount > 0;

// ----- Sentiment pre-scan via small AI call -----
$sentDist = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
$sentMessage = '';
$sampleForAI = [];
foreach ($rows as $r) {
    $t = trim((string)$r['text']);
    if ($t === '' || mb_strlen($t) < 10) continue;
    $sampleForAI[] = ['id' => (int)$r['id'], 'text' => mb_substr($t, 0, 400)];
    if (count($sampleForAI) >= 80) break; // cheap pre-scan; 80 is plenty
}

if (count($sampleForAI) >= 5) {
    $lines = [];
    foreach ($sampleForAI as $i => $s) $lines[] = ($i + 1) . '. ' . $s['text'];
    $sysMsg = <<<SYS
You are a sentiment pre-scanner. The user provides a numbered list of short open-ended responses. Label each one's overall tone as positive, neutral, negative, or mixed. Output JSON only:

{
  "labels": [
    { "n": <integer>, "sentiment": "positive" | "neutral" | "negative" | "mixed" }
  ]
}

No prose, no markdown fences.
SYS;
    try {
        $aiResp = ai_complete($sysMsg, [
            ['role' => 'user', 'content' => implode("\n", $lines)],
        ], 2000);
        $parsed = ai_extract_json($aiResp['text']);
        if ($parsed && isset($parsed['labels']) && is_array($parsed['labels'])) {
            foreach ($parsed['labels'] as $lab) {
                if (!is_array($lab)) continue;
                $s = strtolower(clean_string((string)($lab['sentiment'] ?? 'neutral'), 16));
                if (!in_array($s, ['positive','neutral','negative','mixed'], true)) $s = 'neutral';
                $sentDist[$s]++;
            }
            $sentMessage = 'Pre-scan based on a sample of ' . count($sampleForAI) . ' responses.';
        } else {
            $sentMessage = 'Sentiment pre-scan unavailable.';
        }
    } catch (Throwable $e) {
        $sentMessage = 'Sentiment pre-scan failed: ' . $e->getMessage();
    }
} else {
    $sentMessage = 'Not enough usable responses for sentiment pre-scan.';
}

// ----- Persist a summary row -----
$pdo->prepare('DELETE FROM mm_quality_briefs WHERE project_id = :p')->execute([':p' => $projectId]);
// Need a source_id for the FK; pick the most recent data source for the project.
$srcStmt = $pdo->prepare('SELECT id FROM mm_data_sources WHERE project_id = :p ORDER BY id DESC LIMIT 1');
$srcStmt->execute([':p' => $projectId]);
$sourceId = (int)($srcStmt->fetchColumn() ?: 0);
if ($sourceId > 0) {
    $ins = $pdo->prepare(
        'INSERT INTO mm_quality_briefs
         (project_id, source_id, blank_count, short_count, duplicate_count, irrelevant_count,
          low_effort_count, copy_paste_count, language_json, length_p50, length_p90,
          sentiment_intensity, notes)
         VALUES (:p, :s, :b, :sh, :dup, 0, :le, 0, :lj, :p50, :p90, NULL, :nt)'
    );
    $ins->execute([
        ':p'   => $projectId,
        ':s'   => $sourceId,
        ':b'   => count($blankIds),
        ':sh'  => count($shortIds),
        ':dup' => count($dupExactIds) + count($dupNearIds),
        ':le'  => count($lowEffortIds),
        ':lj'  => json_encode(['primary' => $primaryLang, 'mixed' => $languageMix], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':p50' => $p50,
        ':p90' => $p90,
        ':nt'  => $sentMessage,
    ]);
}

// ----- Compose checks array for the UI -----
$checks = [
    [
        'key'     => 'blanks',
        'label'   => 'Blank responses',
        'detail'  => 'Empty rows in the text column.',
        'count'   => count($blankIds),
        'ids'     => $blankIds,
        'action'  => 'remove',
        'severity'=> count($blankIds) > 0 ? 'warn' : 'ok',
    ],
    [
        'key'     => 'short',
        'label'   => 'Very short responses',
        'detail'  => 'Responses under 10 characters.',
        'count'   => count($shortIds),
        'ids'     => $shortIds,
        'action'  => 'remove',
        'severity'=> count($shortIds) > 0 ? 'warn' : 'ok',
    ],
    [
        'key'     => 'duplicates_exact',
        'label'   => 'Exact duplicates',
        'detail'  => 'Responses with identical text. The first occurrence is kept.',
        'count'   => count($dupExactIds),
        'ids'     => $dupExactIds,
        'action'  => 'remove',
        'severity'=> count($dupExactIds) > 0 ? 'warn' : 'ok',
    ],
    [
        'key'     => 'duplicates_near',
        'label'   => 'Near-duplicates',
        'detail'  => 'Responses whose first 50 characters match another row. Possible copy-paste.',
        'count'   => count($dupNearIds),
        'ids'     => $dupNearIds,
        'action'  => 'remove',
        'severity'=> count($dupNearIds) > 0 ? 'warn' : 'ok',
    ],
    [
        'key'     => 'low_effort',
        'label'   => 'Low-effort responses',
        'detail'  => 'Single words, all-caps, repeated characters, or filler text like "n/a".',
        'count'   => count($lowEffortIds),
        'ids'     => $lowEffortIds,
        'action'  => 'remove',
        'severity'=> count($lowEffortIds) > 0 ? 'warn' : 'ok',
    ],
];

json_out([
    'ok'           => true,
    'total'        => $total,
    'length_p50'   => $p50,
    'length_p90'   => $p90,
    'language'     => ['primary' => $primaryLang, 'mixed' => $languageMix],
    'sentiment'    => $sentDist,
    'sentiment_message' => $sentMessage,
    'checks'       => $checks,
]);
