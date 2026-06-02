<?php
// POST /api/mm/code-existing-semantic.php
// Body: { project_id }
//
// Semantically codes every text response against the project's EXISTING themes
// using ReliCheck Intelligence (Claude). This is the semantic counterpart to
// code-existing.php (which does literal keyword overlap and misses concepts
// expressed in different words — "culture" vs "cultural", "budget" vs
// "resources"). It UNDERSTANDS meaning, so a theme like "Cultural_Mismatch"
// matches responses that never use the literal word.
//
// Unlike build.php, it NEVER discovers, inserts, renames, or deletes
// categories. It reads the themes already defined for the project and only
// writes mm_coded_responses + mm_sentiment_scores, so the user's hand-built
// codebook is preserved exactly (no duplicate themes).
//
// Robustness (post-review):
//  - Theme-name matching is normalization-tolerant. The model is asked to echo
//    names verbatim, but real LLMs reformat ("Barriers_Time" -> "Barriers Time"
//    / "Time Barriers" / "name: description"). We resolve via normalized key,
//    then word-order-insensitive key, then prefix — and TRACK names that still
//    fail so the failure is reported, never silent (the keyword-tagger's bug).
//  - Coding runs FIRST and accumulates; the wipe + write happen only once we
//    have real results, inside a short transaction. If every batch fails we
//    fail WITHOUT having wiped, so the project's prior tags are preserved.
//  - Small batches + a high token cap so a batch's JSON can't truncate and
//    silently drop 50+ responses.
//
// Mirrors code-existing.php's response contract ({ ok, coded_rows, ... }).

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

check_rate_limit('mm_code_semantic:user:' . $uid, 30, 3600);

// This endpoint makes several multi-second AI calls. Free the session lock now
// (we only read the session, never write it) so the user can navigate to other
// steps while tagging runs instead of every request blocking behind this one.
release_session_lock();

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// ----- Existing themes (NO discovery, NO insert) -----
$catStmt = $pdo->prepare(
    'SELECT id, name, COALESCE(description, "") AS description
     FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$catStmt->execute([':p' => $projectId]);
$cats = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($cats) === 0) fail('mm_no_categories', 'No themes exist yet. Add or discover themes first, then re-tag.');

// Canonicalize a theme name so the model's reformatting can't cause a silent
// miss: lowercase, drop any "name: description" / "name — description" echo,
// turn underscores/hyphens into spaces, strip other punctuation, collapse space.
$normKey = function (string $s): string {
    $s = mb_strtolower(trim($s));
    $colon = mb_strpos($s, ':');
    if ($colon !== false) $s = mb_substr($s, 0, $colon);
    $s = preg_replace('/\s+[—–-]\s+.*$/u', '', $s);     // " — description" tail
    $s = preg_replace('/[_\-]+/u', ' ', $s);             // underscores/hyphens -> space
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s);    // drop other punctuation
    $s = preg_replace('/\s+/u', ' ', trim((string)$s));
    return (string)$s;
};
$sortKey = function (string $norm): string {
    if ($norm === '') return '';
    $w = explode(' ', $norm);
    sort($w);
    return implode(' ', $w);
};

$catById       = [];   // id -> name (for reporting)
$catByNorm     = [];   // normalized name -> id
$catBySort     = [];   // word-order-insensitive key -> id
$sortAmbig     = [];   // sort keys that map to >1 theme (don't trust them)
$catLines      = [];
foreach ($cats as $c) {
    $id  = (int)$c['id'];
    $nm  = (string)$c['name'];
    $catById[$id] = $nm;
    $nk  = $normKey($nm);
    if ($nk !== '' && !isset($catByNorm[$nk])) $catByNorm[$nk] = $id;
    $sk  = $sortKey($nk);
    if ($sk !== '') {
        if (isset($catBySort[$sk]) && $catBySort[$sk] !== $id) $sortAmbig[$sk] = true;
        elseif (!isset($catBySort[$sk])) $catBySort[$sk] = $id;
    }
    $desc = (string)$c['description'];
    // Quote the exact name so the model can copy it verbatim.
    $catLines[] = '- "' . $nm . '"' . ($desc !== '' ? ' — ' . $desc : '');
}

// Resolve an AI-returned category label to a theme id, tolerating reformatting.
// Returns 0 if it genuinely cannot be matched to any existing theme.
$resolve = function (string $raw) use ($normKey, $sortKey, $catByNorm, $catBySort, $sortAmbig): int {
    $n = $normKey($raw);
    if ($n === '') return 0;
    if (isset($catByNorm[$n])) return $catByNorm[$n];          // exact (normalized)
    $sk = $sortKey($n);
    if ($sk !== '' && isset($catBySort[$sk]) && empty($sortAmbig[$sk])) return $catBySort[$sk]; // reordered words
    foreach ($catByNorm as $k => $id) {                         // one contains the other
        if ($k !== '' && (mb_strpos($n, $k) === 0 || mb_strpos($k, $n) === 0)) return $id;
    }
    return 0;
};

// ----- Responses -----
$responses  = mm_load_responses($pdo, $projectId, 3000);
$totalCount = count($responses);
if ($totalCount < 1) fail('insufficient_data', 'This project has no text responses to code.');

$truncate = function (string $t, int $max = 600): string {
    if (strlen($t) > $max) return substr($t, 0, $max) . '...';
    return $t;
};

// ----- Coding prompt -----
$systemCode = <<<SYS
You are a mixed-methods qualitative coder. You are given a fixed category set and a numbered batch of open-ended responses. Code each response with rich per-category metadata.

Rules:
- Use ONLY the categories listed. Do not invent new ones.
- CRITICAL: the "name" you output for a category MUST be copied verbatim from inside the quotation marks in the category list (for example "Barriers_Time"), with identical capitalization, underscores, and spacing. Do not paraphrase, reorder the words, translate, drop the underscores, or append the description.
- For each response, output its number, sentiment, confidence, and a list of per-category items. Each per-category item has:
    * "name"        (verbatim category name, exactly as quoted in the list)
    * "intensity"   ("low" = passing mention, "moderate" = clear mention, "high" = central focus of the response)
    * "relevance"   ("usable" = clearly about this category, "unclear" = possible but ambiguous, "off_topic" = does not really fit)
    * "quote_worthy" (true if the response would make a strong pull-quote for a report, false otherwise)
- A response may match one OR many categories. If a response is off-topic to ALL categories, return an empty "categories" list for it — do NOT force a weak match.
- Sentiment is per response (not per category): positive, neutral, negative, or mixed.
- Confidence is per response: high, moderate, or low.

Output a single JSON object with this exact shape:

{
  "codes": [
    {
      "response_n": <integer>,
      "sentiment":  "positive" | "neutral" | "negative" | "mixed",
      "confidence": "high" | "moderate" | "low",
      "categories": [
        {
          "name":          "<verbatim category name>",
          "intensity":     "low" | "moderate" | "high",
          "relevance":     "usable" | "unclear" | "off_topic",
          "quote_worthy":  true | false
        }
      ]
    }
  ]
}

Output the raw JSON only. No markdown fences.
SYS;

// ----- Pass: code into an in-memory buffer FIRST (no DB writes yet) -----
// pending[] = ['rid'=>int, 'sent'=>str, 'conf'=>str, 'cats'=>[ ['cid'=>int,'int'=>str,'rel'=>str,'qw'=>int] ]]
$pending           = [];
$batchSize         = 50;
$batchFailures     = 0;
$failedOffsets     = [];
$unmatched         = [];   // raw label -> count (themes the model named that we couldn't resolve)
$responsesWithCode = 0;
$codeRows          = 0;

for ($offset = 0; $offset < $totalCount; $offset += $batchSize) {
    $batch   = array_slice($responses, $offset, $batchSize);
    $idIndex = [];
    $lines   = [];
    foreach ($batch as $i => $r) {
        $n = $i + 1;
        $idIndex[$n] = (int)$r['id'];
        $lines[] = $n . '. ' . $truncate((string)$r['text']);
    }
    $userMsg  = "Category set:\n" . implode("\n", $catLines) . "\n\n";
    $userMsg .= "Batch (" . count($batch) . " responses, offset " . $offset . " of " . $totalCount . "):\n";
    $userMsg .= implode("\n", $lines) . "\n\n";
    $userMsg .= "Code every response in this batch.";

    try {
        $bresp   = ai_complete($systemCode, [['role' => 'user', 'content' => $userMsg]], 8000);
        $bparsed = ai_extract_json($bresp['text']);
        if (!$bparsed || !isset($bparsed['codes']) || !is_array($bparsed['codes'])) {
            $batchFailures++; $failedOffsets[] = $offset;
            error_log('code-existing-semantic.php: batch offset ' . $offset . ' returned unparseable JSON (project ' . $projectId . ').');
            continue;
        }
    } catch (Throwable $e) {
        $batchFailures++; $failedOffsets[] = $offset;
        error_log('code-existing-semantic.php: batch offset ' . $offset . ' failed: ' . $e->getMessage());
        continue;
    }

    foreach ($bparsed['codes'] as $code) {
        if (!is_array($code)) continue;
        $n = (int)($code['response_n'] ?? 0);
        if (!isset($idIndex[$n])) continue;
        $responseId = $idIndex[$n];
        $codeConf = strtolower(clean_string((string)($code['confidence'] ?? 'moderate'), 16));
        if (!in_array($codeConf, ['high','moderate','low'], true)) $codeConf = 'moderate';
        $sent = strtolower(clean_string((string)($code['sentiment'] ?? 'neutral'), 16));
        if (!in_array($sent, ['positive','neutral','negative','mixed'], true)) $sent = 'neutral';

        $catEntries = (isset($code['categories']) && is_array($code['categories'])) ? $code['categories'] : [];
        $rowCats = [];
        foreach ($catEntries as $catEntry) {
            if (is_string($catEntry)) {
                $cnRaw       = $catEntry;
                $intensity   = 'moderate';
                $relevance   = 'usable';
                $quoteWorthy = 0;
            } elseif (is_array($catEntry)) {
                $cnRaw     = (string)($catEntry['name'] ?? '');
                $intensity = strtolower(clean_string((string)($catEntry['intensity'] ?? 'moderate'), 16));
                if (!in_array($intensity, ['low','moderate','high'], true)) $intensity = 'moderate';
                $relevance = strtolower(clean_string((string)($catEntry['relevance'] ?? 'usable'), 16));
                if (!in_array($relevance, ['usable','unclear','off_topic'], true)) $relevance = 'usable';
                $quoteWorthy = !empty($catEntry['quote_worthy']) ? 1 : 0;
            } else {
                continue;
            }
            $cnRaw = trim($cnRaw);
            if ($cnRaw === '') continue;
            $cid = $resolve($cnRaw);
            if ($cid === 0) {
                // The model named a theme we could not resolve. Record it so the
                // failure is visible instead of silently dropped.
                $key = mb_substr($cnRaw, 0, 80);
                $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                error_log('code-existing-semantic.php: unmatched theme name "' . $key . '" (project ' . $projectId . ').');
                continue;
            }
            $rowCats[] = ['cid' => $cid, 'int' => $intensity, 'rel' => $relevance, 'qw' => $quoteWorthy];
        }

        $pending[] = ['rid' => $responseId, 'sent' => $sent, 'conf' => $codeConf, 'cats' => $rowCats];
        if (count($rowCats) > 0) { $responsesWithCode++; $codeRows += count($rowCats); }
    }
}

// If EVERY batch failed (nothing parsed at all) bail out WITHOUT wiping, so the
// project's existing tags are preserved rather than destroyed for nothing.
if (count($pending) === 0) {
    fail('mm_semantic_code_failed', 'ReliCheck Intelligence could not code any responses (all '
        . $batchFailures . ' batch(es) failed). Your existing tags were left untouched. Please try again.', 502);
}

// ----- Wipe + write, atomically, now that we have real results -----
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM mm_coded_responses WHERE project_id = :p AND coder_id = :u')->execute([':p' => $projectId, ':u' => $uid]);
    $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE project_id = :p')->execute([':p' => $projectId]);

    $insertCode = $pdo->prepare(
        'INSERT INTO mm_coded_responses
         (project_id, response_id, category_id, coder_id, confidence, intensity, relevance, quote_worthy)
         VALUES (:p, :r, :c, :u, :cf, :int, :rel, :qw)
         ON DUPLICATE KEY UPDATE
            confidence   = VALUES(confidence),
            intensity    = VALUES(intensity),
            relevance    = VALUES(relevance),
            quote_worthy = VALUES(quote_worthy)'
    );
    $insertSent = $pdo->prepare(
        'INSERT INTO mm_sentiment_scores (project_id, response_id, sentiment, confidence)
         VALUES (:p, :r, :s, :cf)
         ON DUPLICATE KEY UPDATE sentiment = VALUES(sentiment), confidence = VALUES(confidence)'
    );

    foreach ($pending as $row) {
        foreach ($row['cats'] as $cat) {
            $insertCode->execute([
                ':p'   => $projectId,
                ':r'   => $row['rid'],
                ':c'   => $cat['cid'],
                ':u'   => $uid,
                ':cf'  => $row['conf'],
                ':int' => $cat['int'],
                ':rel' => $cat['rel'],
                ':qw'  => $cat['qw'],
            ]);
        }
        $insertSent->execute([
            ':p' => $projectId, ':r' => $row['rid'], ':s' => $row['sent'], ':cf' => $row['conf'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_semantic_write_failed', 'Coding succeeded but saving failed: ' . $e->getMessage() . '. Your previous tags were left intact.', 500);
}

// Theme names the model used that we could not resolve (should be empty).
$unmatchedNames = [];
foreach ($unmatched as $nm => $n) $unmatchedNames[] = $nm;

json_out([
    'ok'                              => true,
    'engine'                          => 'semantic_coder_v1',
    'theme_count'                     => count($cats),
    'response_count'                  => $totalCount,
    'coded_rows'                      => $responsesWithCode, // toast: "Tagged N responses to themes"
    'code_row_total'                  => $codeRows,
    'responses_with_at_least_one_code'=> $responsesWithCode,
    'batch_failures'                  => $batchFailures,
    'failed_offsets'                  => $failedOffsets,
    'unmatched_theme_names'           => $unmatchedNames,
    'model'                           => ai_config()['model'],
]);
