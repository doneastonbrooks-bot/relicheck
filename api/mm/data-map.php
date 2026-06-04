<?php
// POST /api/mm/data-map.php
//   Body: { project_id,
//           save?: [ { idx, type, construct?, included? } ] }   // optional: persist roles
//
// Data Map = classification and organization (NOT evaluation — Data Quality does
// that). Inspects the project's linked dataset and assigns each variable a mixed
// methods analysis role: case identifier, demographic/grouping, quantitative,
// Likert item, open-ended qualitative, or excluded. Also reports the qual<->quant
// integration links and which mixed methods designs the dataset can support.
//
// Confirmed roles are stored back onto datasets.column_meta (type + construct +
// dm_confirmed). RSSI/SIRI scoring does not read column_meta, so this does not
// touch any scoring. The same store is read on the next load so detection is
// overridden by what the user confirmed.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
check_rate_limit('mm_datamap:user:' . $uid, 240, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'project_id is required.');
mm_require_project($pdo, $uid, $projectId);

// Linked dataset (same lookup as ttest.php / descriptives.php / data-quality.php).
$dq = $pdo->prepare('SELECT dataset_id FROM mm_projects WHERE id = :p AND user_id = :u');
$dq->execute([':p' => $projectId, ':u' => $uid]);
$datasetId = (int)($dq->fetchColumn() ?: 0);
if ($datasetId <= 0) fail('mm_no_dataset', 'No dataset is linked to this project. Upload data first.', 404);

$drq = $pdo->prepare('SELECT column_meta, data FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
$drq->execute([':d' => $datasetId, ':u' => $uid]);
$drow = $drq->fetch(PDO::FETCH_ASSOC);
if (!$drow) fail('mm_no_dataset', 'Dataset not found for this project.', 404);

$cm   = json_decode((string)$drow['column_meta'], true) ?: [];
$data = json_decode((string)$drow['data'], true) ?: [];
$nRows = count($data);
$nCols = count($cm);
if ($nCols === 0) fail('mm_empty_dataset', 'The linked dataset has no columns.', 404);

// Type allowlist that create.php / update_columns.php accept.
$ALLOWED_TYPES = ['likert','single','multi','open','ignore','numeric','criterion','demographic','identifier'];

// ----------------------------------------------------------------------------
// SAVE branch: persist confirmed roles onto column_meta, then fall through to
// recompute and return the refreshed map.
// ----------------------------------------------------------------------------
if (isset($body['save']) && is_array($body['save'])) {
    foreach ($body['save'] as $a) {
        if (!is_array($a)) continue;
        $i = (int)($a['idx'] ?? -1);
        if ($i < 0 || $i >= $nCols || !is_array($cm[$i])) continue;
        $t = (string)($a['type'] ?? '');
        if (!in_array($t, $ALLOWED_TYPES, true)) continue;
        $cm[$i]['type'] = $t;
        $cm[$i]['dm_confirmed'] = true;
        if (array_key_exists('construct', $a)) {
            $c = clean_string((string)$a['construct'], 200);
            if ($c === '') unset($cm[$i]['construct']);
            else $cm[$i]['construct'] = $c;
        }
        if (array_key_exists('points', $a) && $a['points'] !== null) {
            $p = (int)$a['points'];
            if ($p >= 2 && $p <= 10) $cm[$i]['points'] = $p;
            else unset($cm[$i]['points']);
        }
    }
    $json = json_encode($cm, JSON_UNESCAPED_UNICODE);
    if ($json === false) fail('bad_data', 'Could not encode column metadata.', 500);
    $up = $pdo->prepare('UPDATE datasets SET column_meta = :cm WHERE id = :d AND owner_id = :u');
    $up->execute([':cm' => $json, ':d' => $datasetId, ':u' => $uid]);

    // Phase 2026x: as soon as roles are confirmed, copy the open-ended columns
    // into mm_text_responses so the Qualitative Themes step has responses to
    // read. Previously this copy ran only at dataset-link time, so confirming a
    // column as open AFTER linking (the normal order) left the qual table
    // empty. Runs only when the project has NO responses yet, so it never
    // disturbs existing data or coding. Mirrors reingest-dataset-text.php.
    // Wrapped so any failure here can never break role-saving.
    try {
        if (mm_response_count($pdo, $projectId) === 0 && is_array($data) && !empty($data)) {
            $openIdxM = []; $numericIdxM = -1; $refIdxM = -1;
            foreach ($cm as $ci => $cc) {
                if (!is_array($cc)) continue;
                $ctype = (string)($cc['type'] ?? '');
                $cname = (string)($cc['name'] ?? ('col_' . $ci));
                if ($ctype === 'open') {
                    if ($refIdxM === -1 && preg_match('/^(respondent_?ref|response_?id|id|ref|email)$/i', $cname)) {
                        $refIdxM = $ci; continue;
                    }
                    $set = []; $lenSum = 0; $lenCount = 0;
                    foreach ($data as $row) {
                        if (!is_array($row)) continue;
                        $v = isset($row[$ci]) ? trim((string)$row[$ci]) : '';
                        if ($v === '') continue;
                        if (count($set) < 200) $set[$v] = true;
                        $lenSum += mb_strlen($v); $lenCount++;
                    }
                    // Trust the user's confirmed classification: a column they
                    // explicitly marked Open-ended is materialized as long as it
                    // holds any non-empty text. The old avgLen>20 && distinct>12
                    // heuristic belonged to auto-detection and silently dropped
                    // short answers and small samples (<=12 respondents), leaving
                    // the Qualitative Themes step empty despite a confirmed map.
                    if ($lenCount > 0) $openIdxM[] = ['idx' => $ci, 'name' => $cname];
                } elseif ($ctype === 'likert' && $numericIdxM === -1) {
                    $numericIdxM = $ci;
                }
            }
            $groupIdxM = -1;
            foreach ($cm as $ci => $cc) {
                if (!is_array($cc)) continue;
                if ((string)($cc['type'] ?? '') === 'likert' || $ci === $refIdxM) continue;
                $isOpenC = ((string)($cc['type'] ?? '') === 'open');
                $set = [];
                foreach ($data as $row) {
                    if (!is_array($row)) continue;
                    $v = isset($row[$ci]) ? trim((string)$row[$ci]) : '';
                    if ($v !== '') $set[$v] = true;
                    if (count($set) > 20) break;
                }
                $d = count($set);
                if ($d >= 2 && $d <= 12) { if (!$isOpenC) { $groupIdxM = $ci; break; } if ($groupIdxM === -1) $groupIdxM = $ci; }
            }
            if (!empty($openIdxM)) {
                $srcIdM = 0;
                try {
                    $f = $pdo->prepare('SELECT id FROM mm_data_sources WHERE project_id = :p AND source_type = "dataset" AND source_ref = :ref ORDER BY id DESC LIMIT 1');
                    $f->execute([':p' => $projectId, ':ref' => (string)$datasetId]);
                    $srcIdM = (int)($f->fetchColumn() ?: 0);
                    if ($srcIdM === 0) {
                        $ins = $pdo->prepare('INSERT INTO mm_data_sources (project_id, source_type, source_ref, label, field_name, numeric_field, group_field, row_count) VALUES (:p, "dataset", :ref, :lbl, NULL, NULL, NULL, 0)');
                        $ins->execute([':p' => $projectId, ':ref' => (string)$datasetId, ':lbl' => 'Dataset link']);
                        $srcIdM = (int)$pdo->lastInsertId();
                    }
                } catch (Throwable $e) { error_log('data-map: source resolve failed: ' . $e->getMessage()); }

                $rowI = 0;
                foreach ($data as $row) {
                    if (!is_array($row)) { $rowI++; continue; }
                    $rid = ($refIdxM >= 0 && isset($row[$refIdxM]) && trim((string)$row[$refIdxM]) !== '') ? trim((string)$row[$refIdxM]) : ('R' . ($rowI + 1));
                    $num = ($numericIdxM >= 0 && isset($row[$numericIdxM]) && is_numeric($row[$numericIdxM])) ? (float)$row[$numericIdxM] : null;
                    $grp = null;
                    if ($groupIdxM >= 0 && isset($row[$groupIdxM])) { $g = trim((string)$row[$groupIdxM]); if ($g !== '') $grp = $g; }
                    foreach ($openIdxM as $o) {
                        $text = isset($row[$o['idx']]) ? trim((string)$row[$o['idx']]) : '';
                        if ($text === '') continue;
                        if (mb_strlen($text) > 8000) $text = mb_substr($text, 0, 8000);
                        mm_insert_text_response($pdo, $projectId, $srcIdM, $rid, $grp, $num, $text, $o['name'], $o['name']);
                    }
                    $rowI++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('data-map: materialize-on-confirm failed for project ' . $projectId . ': ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------
$isMissing = static fn($v): bool => $v === null || trim((string)$v) === '';
$numeric   = static function ($v) {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || !is_numeric($s)) return null;
    return (float)$s;
};

// Name-pattern dictionaries (spec detection rules).
$RX_ID    = '/(^|[_\s-])(id|respondent|participant|case|uid)([_\s-]|$)/i';
$RX_DEMO  = '/(gender|sex|race|ethnic|\bage\b|education|\bedu\b|degree|role|grade|school|district|region|income|tenure)/i';
$RX_LIK   = '/(likert|agree|confiden|trust|support|concern|readiness|percept|satisf|attitud|belief|scale)/i';
$RX_OPEN  = '/(open|response|explain|why|describe|comment|concern|benefit|recommend|feedback|elaborat|story|narrativ|qual)/i';

// Map a saved column_meta type -> Data Map detected type label.
$typeToDetected = [
    'identifier'  => 'ID',
    'demographic' => 'Demographic',
    'likert'      => 'Likert',
    'numeric'     => 'Numeric',
    'criterion'   => 'Numeric',
    'open'        => 'Open-Ended Text',
    'single'      => 'Categorical',
    'multi'       => 'Categorical',
    'ignore'      => 'Unknown',
];

// Map a detected type -> assigned role / strand / analysis uses.
$roleFor = static function (string $det, int $distinct, bool $numericDemo): array {
    switch ($det) {
        case 'ID':
            return ['Case identifier', 'Integration', 'Link qualitative and quantitative records by respondent'];
        case 'Demographic':
            if ($numericDemo)
                return ['Demographic / covariate', 'Demographic / Grouping', 'Descriptives, grouping, correlations'];
            return ['Demographic grouping variable', 'Demographic / Grouping',
                    $distinct > 2 ? 'Frequencies, ANOVA, chi-square' : 'Frequencies, t-test, chi-square'];
        case 'Likert':
            return ['Likert item', 'Quantitative', 'Means, reliability, t-test, ANOVA'];
        case 'Numeric':
            return ['Quantitative outcome', 'Quantitative', 'Descriptives, correlations, group comparisons'];
        case 'Categorical':
            return ['Demographic grouping variable', 'Demographic / Grouping',
                    $distinct > 2 ? 'Frequencies, ANOVA, chi-square' : 'Frequencies, t-test, chi-square'];
        case 'Open-Ended Text':
            return ['Qualitative response', 'Qualitative', 'Theme discovery, quote analysis'];
        default:
            return ['Exclude from analysis', 'Excluded', '—'];
    }
};

// ----------------------------------------------------------------------------
// Classify every column
// ----------------------------------------------------------------------------
$cols = [];
for ($j = 0; $j < $nCols; $j++) {
    $name = trim((string)($cm[$j]['name'] ?? ('Column ' . ($j + 1))));
    $lname = strtolower($name);
    $savedType  = (string)($cm[$j]['type'] ?? '');
    $confirmed  = !empty($cm[$j]['dm_confirmed']);
    $construct  = isset($cm[$j]['construct']) ? (string)$cm[$j]['construct'] : '';
    $userPoints = isset($cm[$j]['points']) && (int)$cm[$j]['points'] >= 2 ? (int)$cm[$j]['points'] : null;

    // Profile.
    $nonEmpty = 0; $numCount = 0; $intInScale = 0; $lenSum = 0; $distinct = [];
    $hasDecimals = false; $numMin = PHP_FLOAT_MAX; $numMax = -PHP_FLOAT_MAX;
    foreach ($data as $row) {
        $v = (is_array($row) && array_key_exists($j, $row)) ? $row[$j] : null;
        if ($isMissing($v)) continue;
        $nonEmpty++;
        $s = trim((string)$v);
        $lenSum += function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        $distinct[$s] = ($distinct[$s] ?? 0) + 1;
        $f = $numeric($v);
        if ($f !== null) {
            $numCount++;
            if ($f == (int)$f && $f >= 1 && $f <= 7) $intInScale++;
            if ($f != (int)$f) $hasDecimals = true;
            if ($f < $numMin) $numMin = $f;
            if ($f > $numMax) $numMax = $f;
        }
    }
    if ($numCount === 0) { $numMin = 0; $numMax = 0; }
    $distinctN = count($distinct);
    $numFrac   = $nonEmpty ? $numCount / $nonEmpty : 0.0;
    $uniqFrac  = $nonEmpty ? $distinctN / $nonEmpty : 0.0;
    $avgLen    = $nonEmpty ? $lenSum / $nonEmpty : 0.0;
    $isNumericCol = ($numFrac >= 0.8 && $distinctN >= 3);
    $isLikertVals = ($nonEmpty > 0 && $numFrac >= 0.8 && $intInScale === $numCount && $distinctN >= 2 && $distinctN <= 7);

    // Detect type. A confirmed save overrides detection.
    if ($confirmed && isset($typeToDetected[$savedType])) {
        $det = $typeToDetected[$savedType];
    } else {
        if (preg_match($RX_ID, $name) && $avgLen < 24) {
            $det = 'ID';
        } elseif ($nonEmpty >= 8 && $uniqFrac > 0.95 && $avgLen < 24 && $numFrac > 0.5) {
            $det = 'ID'; // unique short numeric/code column with no telltale name
        } elseif (preg_match($RX_LIK, $name) && $isLikertVals) {
            $det = 'Likert';
        } elseif ($isLikertVals && !preg_match($RX_DEMO, $name)) {
            $det = 'Likert';
        } elseif (preg_match($RX_OPEN, $name) && $numFrac < 0.5) {
            $det = 'Open-Ended Text';
        } elseif ($numFrac < 0.5 && (
                    $avgLen >= 40 ||
                    ($avgLen >= 20 && $uniqFrac > 0.5) ||
                    ($distinctN > 30 && $avgLen >= 12)
                  )) {
            $det = 'Open-Ended Text';
        } elseif (preg_match($RX_DEMO, $name) && ($distinctN <= 30 || $isNumericCol)) {
            $det = 'Demographic';
        } elseif ($isNumericCol) {
            $det = 'Numeric';
        } elseif ($distinctN >= 2 && $distinctN <= 30) {
            $det = 'Categorical';
        } else {
            $det = 'Unknown';
        }
    }

    $numericDemo = ($det === 'Demographic' && $isNumericCol);
    [$role, $strand, $uses] = $roleFor($det, $distinctN, $numericDemo);

    // Top categories (for categorical/demographic display) or format note.
    arsort($distinct);
    $topCats = array_slice(array_keys($distinct), 0, 6);
    $format = '';
    if (in_array($det, ['Demographic', 'Categorical'], true)) {
        $format = $numericDemo ? 'Numeric (' . $distinctN . ' values)'
                               : implode(', ', array_map('strval', $topCats)) . ($distinctN > 6 ? ', …' : '');
    } elseif ($det === 'Likert') {
        $pts    = $userPoints ?? $distinctN;
        $format = 'Likert ' . ($pts ? '(' . $pts . '-point)' : '');
    } elseif ($det === 'Numeric') {
        if ($hasDecimals) {
            $fmt = static fn($n) => rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.');
            $format = 'Continuous score (' . $fmt($numMin) . ' to ' . $fmt($numMax) . ')';
        } else {
            $format = 'Numeric (' . $distinctN . ' values)';
        }
    } elseif ($det === 'Open-Ended Text') {
        $format = 'Free text (avg ' . round($avgLen) . ' chars)';
    } elseif ($det === 'ID') {
        $format = 'Unique per respondent';
    }

    $cols[] = [
        'idx' => $j, 'name' => $name,
        'detected_type' => $det,
        'assigned_role' => $role,
        'strand' => $strand,
        'analysis_uses' => $uses,
        'confirmed'     => $confirmed,
        'construct'     => $construct,
        'points'        => $userPoints,
        'numeric_demo'  => $numericDemo,
        'distinct'      => $distinctN,
        'has_decimals'  => $hasDecimals,
        'num_min'       => $numCount > 0 ? $numMin : null,
        'num_max'       => $numCount > 0 ? $numMax : null,
        'categories'    => array_map('strval', $topCats),
        'format'        => $format,
    ];
}

// ----------------------------------------------------------------------------
// Roll up
// ----------------------------------------------------------------------------
$by = static fn(string $d) => array_values(array_filter($cols, fn($c) => $c['detected_type'] === $d));
$idCols    = $by('ID');
$demoCols  = array_values(array_filter($cols, fn($c) => $c['detected_type'] === 'Demographic' || $c['detected_type'] === 'Categorical'));
$likCols   = $by('Likert');
$numCols   = $by('Numeric');
$openCols  = $by('Open-Ended Text');
$quantCols = array_merge($likCols, $numCols);

$hasId    = count($idCols) > 0;
$hasQuant = count($quantCols) > 0;
$hasQual  = count($openCols) > 0;
$hasDemo  = count($demoCols) > 0;

$integration = (!$hasQuant || !$hasQual) ? 'Needs Mapping' : ($hasId ? 'Strong' : 'Moderate');

$idVarName = $hasId ? $idCols[0]['name'] : '—';

$summary = [
    'respondents'         => $nRows,
    'id_variable'         => $idVarName,
    'demographics'        => count($demoCols),
    'quantitative'        => count($quantCols),
    'likert'              => count($likCols),
    'open_ended'          => count($openCols),
    'integration_strength'=> $integration,
];

// Compact a list of names ("A, B, C" or "A … D (n)").
$names = static function (array $list): string {
    $n = array_map(fn($c) => $c['name'], $list);
    if (count($n) <= 4) return implode(', ', $n);
    return $n[0] . ' … ' . $n[count($n) - 1] . ' (' . count($n) . ')';
};
$readyOrNeeds = static fn(bool $ok) => $ok ? 'Ready' : 'Needs Mapping';

$overview = [
    ['section' => 'Case identifier', 'variables' => $hasId ? $names($idCols) : 'None detected',
     'count' => count($idCols), 'status' => $readyOrNeeds($hasId),
     'guidance' => $hasId ? 'Respondent-level linkage is available' : 'Without an ID, linkage falls back to same-row order'],
    ['section' => 'Demographics', 'variables' => $hasDemo ? $names($demoCols) : 'None detected',
     'count' => count($demoCols), 'status' => $readyOrNeeds($hasDemo),
     'guidance' => $hasDemo ? 'Group comparisons can be explored' : 'No grouping variables found'],
    ['section' => 'Likert items', 'variables' => count($likCols) ? $names($likCols) : 'None detected',
     'count' => count($likCols), 'status' => $readyOrNeeds(count($likCols) > 0),
     'guidance' => count($likCols) ? 'Quantitative item analysis can be run' : 'No Likert-scale items found'],
    ['section' => 'Open-ended responses', 'variables' => count($openCols) ? $names($openCols) : 'None detected',
     'count' => count($openCols), 'status' => $readyOrNeeds(count($openCols) > 0),
     'guidance' => count($openCols) ? 'Qualitative theme discovery can be run' : 'No open-ended text found'],
    ['section' => 'Mixed methods linkage',
     'variables' => ($hasQuant && $hasQual) ? 'Same-row respondent linkage' : 'Incomplete',
     'count' => ($hasQuant && $hasQual) ? 1 : 0, 'status' => $integration,
     'guidance' => ($hasQuant && $hasQual) ? 'Dataset supports case-level integration' : 'Need both a quantitative and a qualitative strand'],
];

// Integration links.
$st = static fn(bool $strong, string $mid = 'Review') => $strong ? 'Strong' : $mid;
$integration_links = [
    ['link_type' => 'Shared respondent ID',
     'status' => $hasId ? ($idVarName . ' detected') : 'No ID column',
     'strength' => $st($hasId), 'meaning' => $hasId ? 'Qualitative and quantitative data can be linked by person' : 'Linkage relies on row order only',
     'action' => $hasId ? 'Confirm' : 'Map an ID'],
    ['link_type' => 'Same-row case linkage',
     'status' => ($hasQuant && $hasQual) ? 'Quant and qual in the same row' : 'Incomplete',
     'strength' => $st($hasQuant && $hasQual), 'meaning' => 'Each respondent carries both Likert and open-ended data',
     'action' => 'Confirm'],
    ['link_type' => 'Demographic linkage',
     'status' => $hasDemo ? 'Demographics available' : 'No demographics',
     'strength' => $st($hasDemo), 'meaning' => 'Themes and scores can be compared by group',
     'action' => $hasDemo ? 'Confirm' : 'Optional'],
    ['link_type' => 'Quantitative-to-qualitative linkage',
     'status' => ($hasQuant && $hasQual) ? 'Likert + open-ended available' : 'Missing a strand',
     'strength' => $st($hasQuant && $hasQual), 'meaning' => 'Quantitative results can be explained with qualitative responses',
     'action' => 'Confirm'],
    ['link_type' => 'Item-to-open-ended question linkage',
     'status' => 'Partial', 'strength' => 'Review',
     'meaning' => 'Some open-ended questions may explain specific Likert constructs',
     'action' => 'Map questions'],
    ['link_type' => 'Joint display readiness',
     'status' => ($hasQuant && $hasQual) ? 'Available' : 'Not yet',
     'strength' => $st($hasQuant && $hasQual), 'meaning' => 'Dataset can support mixed methods joint displays',
     'action' => 'Confirm'],
];

// Design fit.
$both = $hasQuant && $hasQual;
$design_fit = [
    ['design' => 'convergent', 'label' => 'Convergent Parallel',
     'fit' => $both ? 'Strong' : 'Needs Mapping',
     'reason' => $both ? 'Quantitative and qualitative data are collected in the same dataset' : 'Both a quantitative and a qualitative strand are required',
     'use' => 'Compare Likert patterns with open-ended themes'],
    ['design' => 'explanatory', 'label' => 'Explanatory Sequential',
     'fit' => $both ? 'Strong' : ($hasQuant ? 'Moderate' : 'Needs Mapping'),
     'reason' => $both ? 'Quantitative results can be analyzed first, then open-ended responses explain the patterns' : ($hasQuant ? 'Quantitative data is present but open-ended follow-up is limited' : 'No quantitative strand to lead with'),
     'use' => 'Use QUAN results to select themes and quotes for explanation'],
    ['design' => 'exploratory', 'label' => 'Exploratory Sequential',
     'fit' => ($hasQual && !$hasQuant) ? 'Strong' : ($hasQual ? 'Moderate' : 'Needs Mapping'),
     'reason' => ($hasQual && !$hasQuant) ? 'Open-ended responses can generate themes that build new measures' : ($hasQual ? 'Open-ended responses can generate themes, but quantitative items already exist' : 'No qualitative strand to explore'),
     'use' => 'Use themes to refine constructs, not as a pure exploratory build'],
];

json_out([
    'ok'                => true,
    'mock'              => false,
    'respondent_count'  => $nRows,
    'summary'           => $summary,
    'columns'           => $cols,
    'overview'          => $overview,
    'integration_links' => $integration_links,
    'design_fit'        => $design_fit,
    'flags'             => ['has_id' => $hasId, 'has_quant' => $hasQuant, 'has_qual' => $hasQual, 'has_demo' => $hasDemo],
]);
