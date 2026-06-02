<?php
// POST /api/mm/themes-deterministic.php
// Body: { project_id }
//
// READ-ONLY DRY RUN. Deterministic, transparent theme matcher (foundation
// layer, "rules first, AI last"). For each theme it derives match terms and
// cue phrases from the codebook the researcher actually wrote (inclusion rules
// + definitions + theme name), then scans the project's open-ended responses
// and reports, per theme: the derived terms (so the researcher can see and
// later tune them), how many responses matched, and a sample of matches with
// the exact phrase that triggered each and whether it reads as negated.
//
// It WRITES NOTHING. No AI. Same responses + same codebook => same result
// every run. An optional AI pass is a later, separate layer on top of this.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
release_session_lock();

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// ---- Themes (name + definition) ----
$catStmt = $pdo->prepare(
    'SELECT id, name, COALESCE(definition, description, "") AS def
       FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC'
);
$catStmt->execute([':p' => $projectId]);
$themes = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$themes) fail('mm_no_categories', 'No themes exist yet for this project. Add or discover themes first.');

// ---- Codebook rich fields keyed by theme_id (best-effort; columns optional) ----
$cb = [];
try {
    $cbStmt = $pdo->prepare(
        'SELECT theme_id, short_definition, full_description, inclusion_rules, exclusion_rules
           FROM mm_codebooks WHERE project_id = :p'
    );
    $cbStmt->execute([':p' => $projectId]);
    foreach ($cbStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cb[(int)$r['theme_id']] = $r;
} catch (Throwable $e) {
    // Older schema may lack these columns; degrade to name + definition only.
}

// ---- Resources ----
$STOP = array_flip([
    'the','a','an','and','or','of','to','in','on','for','with','is','are','was','were','be','been','being',
    'it','this','that','these','those','as','at','by','from','but','if','then','than','so','such','do','does',
    'did','has','have','had','i','you','he','she','they','we','them','his','her','their','our','your','my','me',
    'us','about','into','over','under','when','where','which','who','whom','what','why','how','will','would',
    'can','could','should','may','might','must','also','more','most','some','any','each','every','only','very',
    'just','too','there','here','because','applies','apply','code','coded','coding','mention','mentions',
    'mentioned','respondent','respondents','response','responses','answer','answers','etc','use','used','whenever',
]);
$NEG = ['no','not','never','without','lack','lacking','lacked','hardly','barely','cannot','cant','wasnt','isnt',
        'arent','wont','dont','didnt','doesnt','couldnt','none','nor','neither','absence','absent'];

$norm = function (string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', trim((string)$s));
    return (string)$s;
};

$deriveTerms = function (string $name, string $def, array $cbrow) use ($norm, $STOP): array {
    $terms = [];
    $phrases = [];
    $incl = (string)($cbrow['inclusion_rules'] ?? '');
    if ($incl !== '') {
        $parts = preg_split('/[,;\r\n\x{2022}]|\bor\b|\band\b/u', mb_strtolower($incl)) ?: [];
        foreach ($parts as $p) {
            $p = $norm($p);
            $w = $p === '' ? [] : explode(' ', $p);
            if (count($w) >= 2 && count($w) <= 4) $phrases[$p] = true;
        }
    }
    $blob = $name . ' ' . $def . ' ' . (string)($cbrow['short_definition'] ?? '')
          . ' ' . (string)($cbrow['full_description'] ?? '') . ' ' . $incl;
    foreach (explode(' ', $norm($blob)) as $w) {
        if ($w === '' || isset($STOP[$w]) || mb_strlen($w) < 4) continue;
        $terms[$w] = true;
    }
    foreach (preg_split('/[_\s]+/', mb_strtolower($name)) ?: [] as $w) {
        $w = $norm($w);
        if ($w !== '' && !isset($STOP[$w]) && mb_strlen($w) >= 3) $terms[$w] = true;
    }
    return ['terms' => array_keys($terms), 'phrases' => array_keys($phrases)];
};

$deriveExcl = function (array $cbrow) use ($norm, $STOP): array {
    $s = (string)($cbrow['exclusion_rules'] ?? '');
    if ($s === '') return [];
    $ex = [];
    foreach (explode(' ', $norm($s)) as $w) {
        if ($w === '' || isset($STOP[$w]) || mb_strlen($w) < 4) continue;
        $ex[$w] = true;
    }
    return array_keys($ex);
};

// ---- Build per-theme term sets ----
$themeDefs = [];
foreach ($themes as $t) {
    $tid = (int)$t['id'];
    $row = $cb[$tid] ?? [];
    $d = $deriveTerms((string)$t['name'], (string)$t['def'], is_array($row) ? $row : []);
    $themeDefs[] = [
        'id'      => $tid,
        'name'    => (string)$t['name'],
        'terms'   => $d['terms'],
        'phrases' => $d['phrases'],
        'exclude' => $deriveExcl(is_array($row) ? $row : []),
        'matches' => [],
        'count'   => 0,
    ];
}

// ---- Responses ----
$responses = mm_load_responses($pdo, $projectId, 5000);

$matchedAny = 0;
foreach ($responses as $r) {
    $rawText = (string)($r['text'] ?? '');
    $low = $norm($rawText);
    if ($low === '') continue;
    $words = explode(' ', $low);
    $rid = (string)($r['respondent_ref'] ?? ('R' . (int)($r['id'] ?? 0)));
    $thisMatched = false;

    foreach ($themeDefs as &$td) {
        // Exclusion veto.
        $vetoed = false;
        foreach ($td['exclude'] as $ex) {
            if ($ex !== '' && preg_match('/\b' . preg_quote($ex, '/') . '\b/u', $low)) { $vetoed = true; break; }
        }
        if ($vetoed) continue;

        // Phrases first (more specific), then single terms.
        $hit = null;
        foreach ($td['phrases'] as $ph) {
            if ($ph !== '' && mb_strpos($low, $ph) !== false) { $hit = $ph; break; }
        }
        if ($hit === null) {
            foreach ($td['terms'] as $term) {
                if ($term !== '' && preg_match('/\b' . preg_quote($term, '/') . '\b/u', $low)) { $hit = $term; break; }
            }
        }
        if ($hit === null) continue;

        // Negation: any negation cue within 3 words before the hit.
        $pos = mb_strpos($low, $hit);
        $wi  = ($pos !== false && $pos > 0) ? substr_count(mb_substr($low, 0, $pos), ' ') : 0;
        $tail = array_slice($words, max(0, $wi - 3), min($wi, 3));
        $neg = count(array_intersect($tail, $NEG)) > 0;

        // Evidence span from the original (un-normalized) text.
        $rp = mb_stripos($rawText, $hit);
        if ($rp !== false) {
            $s = max(0, $rp - 40);
            $evi = ($s > 0 ? '…' : '') . mb_substr($rawText, $s, mb_strlen($hit) + 80) . '…';
        } else {
            $evi = mb_substr($rawText, 0, 120) . (mb_strlen($rawText) > 120 ? '…' : '');
        }

        if (count($td['matches']) < 25) {
            $td['matches'][] = ['ref' => $rid, 'term' => $hit, 'negated' => $neg, 'evidence' => trim($evi)];
        }
        $td['count']++;
        $thisMatched = true;
    }
    unset($td);

    if ($thisMatched) $matchedAny++;
}

json_out([
    'ok'                => true,
    'engine'            => 'deterministic_codebook_v1',
    'project_id'        => $projectId,
    'response_count'    => count($responses),
    'responses_matched' => $matchedAny,
    'themes'            => array_map(function ($t) {
        return [
            'id'          => $t['id'],
            'name'        => $t['name'],
            'match_count' => $t['count'],
            'terms'       => $t['terms'],
            'phrases'     => $t['phrases'],
            'exclude'     => $t['exclude'],
            'samples'     => $t['matches'],
        ];
    }, $themeDefs),
]);
