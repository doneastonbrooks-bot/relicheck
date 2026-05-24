<?php
// POST /api/mm/code-existing.php
// Body: { project_id }
//
// Applies the project's current themes to every text response using a local
// deterministic matcher. No AI calls. Fast, free, reproducible.
//
// Algorithm:
//   1. Build a keyword profile for each theme from its name + description.
//   2. For each response, tokenize the text and score against each theme
//      profile using term-overlap with a tiny boost for exact name matches.
//   3. Bucket the score into intensity (none / low / moderate / high) and
//      record themes that pass the threshold.
//   4. Score sentiment using compact positive / negative lexicons.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// ----- Themes -----
$catStmt = $pdo->prepare(
    'SELECT id, name, COALESCE(description, "") AS description
     FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$catStmt->execute([':p' => $projectId]);
$cats = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($cats) === 0) fail('mm_no_categories', 'No themes exist yet. Generate per-question themes or run the Builder first.');

// ----- Responses -----
$responses = mm_load_responses($pdo, $projectId, 5000);
$totalCount = count($responses);
if ($totalCount < 1) fail('insufficient_data', 'This project has no text responses to code.');

// ============================================================
// Text helpers
// ============================================================
$STOP = array_flip([
    'the','a','an','and','or','but','if','then','else','for','to','of','in','on','at','by','from','with','as','is','are','was','were','be','been','being','it','its','this','that','these','those','i','you','he','she','we','they','them','our','your','their','my','me','us','him','her','no','not','do','does','did','done','have','has','had','having','will','would','should','could','can','may','might','must','about','into','out','over','under','up','down','than','so','too','very','also','just','such','any','some','all','more','most','other','only','own','same','than','because','while','when','where','why','how','what','which','who','whom','whose','there','here','one','two','three','first','second','last','still','yet','really','almost','always','often','usually','never','rather','seem','seems','seemed','make','makes','made','get','got','getting','go','goes','went','going','say','said','saying','think','thought','thinks','feel','felt','feels','people','person','someone','everyone','anyone','thing','things','something','anything','everything'
]);

$tokenize = function (string $text) use ($STOP): array {
    $text = mb_strtolower($text);
    // Replace non-letter/number runs with spaces.
    $text = preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $text);
    $tokens = preg_split('/\s+/u', trim($text)) ?: [];
    $out = [];
    foreach ($tokens as $t) {
        $t = trim($t, "'");
        if ($t === '' || strlen($t) < 3) continue;
        if (isset($STOP[$t])) continue;
        $out[] = $t;
    }
    return $out;
};

$bagOf = function (array $tokens): array {
    $bag = [];
    foreach ($tokens as $t) $bag[$t] = ($bag[$t] ?? 0) + 1;
    return $bag;
};

// ============================================================
// Build theme profiles: a bag of weighted keywords per theme.
// Name tokens get weight 3 (signals what the theme IS).
// Description tokens get weight 1 (extra context).
// ============================================================
$themeProfiles = [];
foreach ($cats as $c) {
    $tid = (int)$c['id'];
    $nameTokens = $tokenize((string)$c['name']);
    $descTokens = $tokenize((string)$c['description']);
    $profile = [];
    foreach ($nameTokens as $t) $profile[$t] = ($profile[$t] ?? 0) + 3;
    foreach ($descTokens as $t) $profile[$t] = ($profile[$t] ?? 0) + 1;
    if (count($profile) === 0) continue; // theme with no useful tokens
    $themeProfiles[$tid] = [
        'name'    => (string)$c['name'],
        'tokens'  => $profile,
        'name_words' => $nameTokens, // for exact-phrase boost
        'norm'    => array_sum($profile),
    ];
}
if (count($themeProfiles) === 0) {
    fail('mm_no_theme_keywords', 'Themes have no usable keywords. Add definitions or rename them.');
}

// ============================================================
// Sentiment lexicon (small but covers common workplace/equity vocab).
// ============================================================
$POS = array_flip([
    'good','great','excellent','positive','strong','effective','helpful','supportive','clear','transparent','fair','equitable','inclusive','respect','respectful','trust','trusted','appreciate','appreciated','appreciation','recognize','recognized','valued','value','genuine','meaningful','progress','improve','improved','improving','better','best','works','working','success','successful','accountable','accountability','consistent','listen','listened','listening','open','collaborative','growth','grateful','thankful','love','enjoy','enjoyed','encouraged','empowered','safe','welcoming'
]);
$NEG = array_flip([
    'bad','poor','negative','weak','ineffective','unhelpful','unsupportive','unclear','opaque','unfair','inequitable','exclusive','disrespect','disrespectful','distrust','distrusted','ignore','ignored','undervalued','tokenistic','token','performative','superficial','regress','worse','worst','broken','fails','failed','failure','unaccountable','inconsistent','dismissive','dismissed','dismiss','closed','siloed','hostile','toxic','harm','harmed','harmful','marginalize','marginalized','retaliation','retaliated','retaliate','frustrated','frustration','angry','anger','sad','tired','burnout','burned','overworked','unsafe','threatened','intimidated','disappointed','disappointing','disappointment','lacks','lacking','no','not','never','none','nothing','barely','hardly'
]);
$MIX_CUE = array_flip(['but','however','although','though','yet','still']);

$scoreSentiment = function (array $tokens) use ($POS, $NEG, $MIX_CUE): array {
    $pos = 0; $neg = 0; $mix = false;
    foreach ($tokens as $t) {
        if (isset($POS[$t])) $pos++;
        if (isset($NEG[$t])) $neg++;
        if (isset($MIX_CUE[$t])) $mix = true;
    }
    $label = 'neutral';
    if ($pos === 0 && $neg === 0) $label = 'neutral';
    elseif ($mix && $pos > 0 && $neg > 0) $label = 'mixed';
    elseif ($pos > $neg) $label = 'positive';
    elseif ($neg > $pos) $label = 'negative';
    else $label = 'mixed';
    $strength = abs($pos - $neg);
    $confidence = $strength >= 3 ? 'high' : ($strength >= 1 ? 'moderate' : 'low');
    return ['label' => $label, 'confidence' => $confidence, 'pos' => $pos, 'neg' => $neg];
};

// ============================================================
// Score one response against one theme profile.
// Returns a float score in roughly [0..1].
// ============================================================
$scorePair = function (array $respBag, string $respLower, array $profile): float {
    if (!$profile['norm']) return 0.0;
    $hits = 0.0;
    foreach ($profile['tokens'] as $tok => $w) {
        if (isset($respBag[$tok])) {
            $hits += $w * min($respBag[$tok], 2);
        }
    }
    // Boost when the literal theme name appears as a phrase.
    $nameWordCount = count($profile['name_words']);
    if ($nameWordCount >= 2) {
        $phrase = implode(' ', $profile['name_words']);
        if (mb_strpos($respLower, $phrase) !== false) $hits += 4 * $nameWordCount;
    }
    // Normalize so longer descriptions don't unfairly outscore short ones.
    $denom = max(1.0, sqrt($profile['norm']));
    return $hits / $denom;
};

// ============================================================
// Wipe + write transactionally
// ============================================================
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

    $LOW_BAR    = 0.5;  // below this, theme is not assigned
    $MOD_BAR    = 1.5;
    $HIGH_BAR   = 3.0;

    $responsesWithAnyCode = 0;
    $codedRows           = 0;
    $perThemeHits        = [];   // theme_id => count

    foreach ($responses as $r) {
        $rid = (int)$r['id'];
        $text = (string)$r['text'];
        if (trim($text) === '') continue;

        $tokens = $tokenize($text);
        $bag    = $bagOf($tokens);
        $lower  = mb_strtolower($text);

        // Score every theme.
        $scored = [];
        foreach ($themeProfiles as $tid => $profile) {
            $s = $scorePair($bag, $lower, $profile);
            if ($s >= $LOW_BAR) $scored[$tid] = $s;
        }

        // Sentiment is per response, regardless of theme matches.
        $sent = $scoreSentiment($tokens);
        $insertSent->execute([
            ':p' => $projectId, ':r' => $rid, ':s' => $sent['label'], ':cf' => $sent['confidence'],
        ]);

        if (count($scored) === 0) continue;

        // If too many themes fire, keep the top 6 so codes stay meaningful.
        arsort($scored);
        $scored = array_slice($scored, 0, 6, true);

        $any = false;
        foreach ($scored as $tid => $s) {
            $intensity = $s >= $HIGH_BAR ? 'high'
                       : ($s >= $MOD_BAR ? 'moderate' : 'low');
            $relevance = 'usable';
            // Treat a low score on a theme that fired only because of a single
            // weak keyword as "unclear" - useful for the reviewer to filter.
            if ($s < $MOD_BAR && count($scored) > 3) $relevance = 'unclear';
            $confidence = $intensity === 'high' ? 'high'
                        : ($intensity === 'moderate' ? 'moderate' : 'low');
            $quoteWorthy = ($intensity === 'high' && mb_strlen($text) <= 350) ? 1 : 0;
            $insertCode->execute([
                ':p'   => $projectId,
                ':r'   => $rid,
                ':c'   => $tid,
                ':u'   => $uid,
                ':cf'  => $confidence,
                ':int' => $intensity,
                ':rel' => $relevance,
                ':qw'  => $quoteWorthy,
            ]);
            $codedRows++;
            $perThemeHits[$tid] = ($perThemeHits[$tid] ?? 0) + 1;
            $any = true;
        }
        if ($any) $responsesWithAnyCode++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_apply_themes_failed', 'Apply themes failed: ' . $e->getMessage(), 500);
}

// Top 5 themes by hit count - useful summary for the banner.
arsort($perThemeHits);
$topThemes = [];
$nameById = [];
foreach ($themeProfiles as $tid => $profile) $nameById[$tid] = $profile['name'];
foreach (array_slice($perThemeHits, 0, 5, true) as $tid => $n) {
    $topThemes[] = ['name' => $nameById[$tid] ?? ('#' . $tid), 'n' => $n];
}

json_out([
    'ok'                              => true,
    'engine'                          => 'local_matcher_v1',
    'theme_count'                     => count($cats),
    'response_count'                  => $totalCount,
    'coded_rows'                      => $codedRows,
    'responses_with_at_least_one_code'=> $responsesWithAnyCode,
    'top_themes'                      => $topThemes,
]);
