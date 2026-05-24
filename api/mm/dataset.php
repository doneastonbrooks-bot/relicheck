<?php
// POST /api/mm/dataset.php  build dataset from categories + sentiment
// GET  /api/mm/dataset.php?project_id=N&id=M  return saved dataset

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    $datasetId = (int)($_GET['id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    if ($datasetId <= 0) {
        $s = $pdo->prepare('SELECT * FROM mm_structured_datasets WHERE project_id = :p ORDER BY id DESC LIMIT 1');
        $s->execute([':p' => $projectId]);
        $ds = $s->fetch(PDO::FETCH_ASSOC);
    } else {
        $s = $pdo->prepare('SELECT * FROM mm_structured_datasets WHERE id = :i AND project_id = :p');
        $s->execute([':i' => $datasetId, ':p' => $projectId]);
        $ds = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$ds) json_out(['ok' => true, 'dataset' => null]);

    $schema = json_decode((string)($ds['schema_json'] ?? ''), true);
    if (!is_array($schema)) $schema = [];

    $resps = mm_load_responses($pdo, $projectId, 5000);
    $cellStmt = $pdo->prepare('SELECT response_id, variable_id, cell_value FROM mm_dataset_cells WHERE dataset_id = :d');
    $cellStmt->execute([':d' => (int)$ds['id']]);
    $byResp = [];
    foreach ($cellStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $byResp[(int)$c['response_id']][(int)$c['variable_id']] = (string)$c['cell_value'];
    }

    $rows = [];
    foreach ($resps as $r) {
        $rid = (int)$r['id'];
        $row = [
            'response_id'    => $rid,
            'respondent_ref' => $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : ('R' . $rid),
        ];
        foreach ($schema as $col) {
            $vid = (int)($col['variable_id'] ?? 0);
            $row[$col['var_name']] = $byResp[$rid][$vid] ?? '';
        }
        $rows[] = $row;
    }

    json_out(['ok' => true, 'dataset' => [
        'id' => (int)$ds['id'], 'title' => (string)$ds['title'],
        'row_count' => (int)$ds['row_count'], 'col_count' => (int)$ds['col_count'],
        'columns' => $schema, 'rows' => $rows,
    ]]);
}

// POST: rebuild
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$title     = clean_string((string)($body['title'] ?? 'Mixed-Methods Dataset'), 200);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

// Variable Builder selections. Honor body.variables when provided; default to
// the most useful set so the existing UI keeps working.
$varDefaults = [
    'presence'      => true,   // theme presence (0/1) per category
    'intensity'     => true,   // theme intensity (0/1/2/3) per category, needs mm_coded_responses.intensity
    'sentiment_cat' => true,   // sentiment as a label column
    'sentiment_num' => true,   // sentiment as a numeric column (-1/0/+1, mixed=0)
    'length_chars'  => true,   // response length in characters
    'length_words'  => true,   // response length in words
];
$varSelections = $varDefaults;
if (isset($body['variables']) && is_array($body['variables'])) {
    foreach ($varDefaults as $k => $_) {
        if (array_key_exists($k, $body['variables'])) $varSelections[$k] = !empty($body['variables'][$k]);
    }
}

// Detect whether intensity exists on mm_coded_responses (Phase 156 column).
$hasIntensity = false;
try {
    $r = $pdo->query("SHOW COLUMNS FROM mm_coded_responses LIKE 'intensity'");
    $hasIntensity = $r && $r->fetch() !== false;
} catch (Throwable $e) { $hasIntensity = false; }

$catStmt = $pdo->prepare('SELECT id, name FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC');
$catStmt->execute([':p' => $projectId]);
$cats = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($cats) === 0) fail('mm_no_categories', 'Run the builder or add categories before building a dataset.');

$responses = mm_load_responses($pdo, $projectId, 5000);
if (count($responses) === 0) fail('mm_no_responses', 'No text responses to encode.');

function mm_var_name(string $label): string {
    $s = strtolower($label);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    if ($s === '') $s = 'var';
    if (strlen($s) > 60) $s = substr($s, 0, 60);
    return $s;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM mm_structured_datasets WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_generated_variables WHERE project_id = :p')->execute([':p' => $projectId]);

    $insertVar = $pdo->prepare(
        'INSERT INTO mm_generated_variables (project_id, var_name, display_label, var_type, role, source_category_id, notes)
         VALUES (:p, :n, :l, :t, :r, :sc, :nt)'
    );

    $columns = [];
    $usedNames = [];
    $registerCol = function (string $base) use (&$usedNames) {
        $name = $base; $i = 2;
        while (isset($usedNames[$name])) { $name = $base . '_' . $i; $i++; }
        $usedNames[$name] = true;
        return $name;
    };

    // Per-category theme columns. Presence is binary; intensity is ordinal numeric.
    foreach ($cats as $c) {
        $catBase = mm_var_name((string)$c['name']);
        if (!empty($varSelections['presence'])) {
            $name = $registerCol($catBase);
            $insertVar->execute([
                ':p' => $projectId, ':n' => $name, ':l' => (string)$c['name'],
                ':t' => 'binary', ':r' => 'predictor', ':sc' => (int)$c['id'],
                ':nt' => 'Theme presence (0/1) for: ' . (string)$c['name'],
            ]);
            $columns[] = [
                'variable_id' => (int)$pdo->lastInsertId(),
                'var_name' => $name, 'label' => (string)$c['name'] . ' (presence)',
                'type' => 'binary', 'category_id' => (int)$c['id'],
            ];
        }
        if (!empty($varSelections['intensity']) && $hasIntensity) {
            $name = $registerCol($catBase . '_intensity');
            $insertVar->execute([
                ':p' => $projectId, ':n' => $name, ':l' => (string)$c['name'] . ' (intensity)',
                ':t' => 'intensity', ':r' => 'predictor', ':sc' => (int)$c['id'],
                ':nt' => 'Theme intensity (0=none, 1=low, 2=moderate, 3=high) for: ' . (string)$c['name'],
            ]);
            $columns[] = [
                'variable_id' => (int)$pdo->lastInsertId(),
                'var_name' => $name, 'label' => (string)$c['name'] . ' (intensity)',
                'type' => 'intensity', 'category_id' => (int)$c['id'],
            ];
        }
    }

    // Sentiment columns.
    if (!empty($varSelections['sentiment_cat'])) {
        $name = $registerCol('sentiment');
        $insertVar->execute([
            ':p' => $projectId, ':n' => $name, ':l' => 'Sentiment',
            ':t' => 'sentiment', ':r' => 'outcome', ':sc' => null,
            ':nt' => 'Sentiment label per response.',
        ]);
        $columns[] = ['variable_id' => (int)$pdo->lastInsertId(), 'var_name' => $name, 'label' => 'Sentiment', 'type' => 'sentiment', 'category_id' => null];
    }
    if (!empty($varSelections['sentiment_num'])) {
        $name = $registerCol('sentiment_num');
        $insertVar->execute([
            ':p' => $projectId, ':n' => $name, ':l' => 'Sentiment (numeric)',
            ':t' => 'ordinal', ':r' => 'outcome', ':sc' => null,
            ':nt' => 'Sentiment as numeric (+1=positive, 0=neutral or mixed, -1=negative).',
        ]);
        $columns[] = ['variable_id' => (int)$pdo->lastInsertId(), 'var_name' => $name, 'label' => 'Sentiment (numeric)', 'type' => 'sentiment_num', 'category_id' => null];
    }

    // Response length columns.
    if (!empty($varSelections['length_chars'])) {
        $name = $registerCol('response_length');
        $insertVar->execute([
            ':p' => $projectId, ':n' => $name, ':l' => 'Response length (chars)',
            ':t' => 'frequency', ':r' => 'predictor', ':sc' => null,
            ':nt' => 'Character count of the response text.',
        ]);
        $columns[] = ['variable_id' => (int)$pdo->lastInsertId(), 'var_name' => $name, 'label' => 'Response length (chars)', 'type' => 'length_chars', 'category_id' => null];
    }
    if (!empty($varSelections['length_words'])) {
        $name = $registerCol('response_words');
        $insertVar->execute([
            ':p' => $projectId, ':n' => $name, ':l' => 'Response length (words)',
            ':t' => 'frequency', ':r' => 'predictor', ':sc' => null,
            ':nt' => 'Word count of the response text.',
        ]);
        $columns[] = ['variable_id' => (int)$pdo->lastInsertId(), 'var_name' => $name, 'label' => 'Response length (words)', 'type' => 'length_words', 'category_id' => null];
    }

    $hasNumeric = false; $hasGroup = false;
    foreach ($responses as $r) {
        if ($r['numeric_value'] !== null && $r['numeric_value'] !== '') $hasNumeric = true;
        if ($r['group_value']   !== null && $r['group_value']   !== '') $hasGroup   = true;
        if ($hasNumeric && $hasGroup) break;
    }
    if ($hasNumeric) {
        $insertVar->execute([':p' => $projectId, ':n' => 'numeric_value', ':l' => 'Numeric score', ':t' => 'frequency', ':r' => 'predictor', ':sc' => null, ':nt' => 'Quantitative passthrough.']);
        $numVarId = (int)$pdo->lastInsertId();
        $columns[] = ['variable_id' => $numVarId, 'var_name' => 'numeric_value', 'label' => 'Numeric score', 'type' => 'frequency', 'category_id' => null];
    } else { $numVarId = 0; }
    if ($hasGroup) {
        $insertVar->execute([':p' => $projectId, ':n' => 'group', ':l' => 'Group', ':t' => 'category', ':r' => 'neutral', ':sc' => null, ':nt' => 'Group passthrough.']);
        $grpVarId = (int)$pdo->lastInsertId();
        $columns[] = ['variable_id' => $grpVarId, 'var_name' => 'group', 'label' => 'Group', 'type' => 'category', 'category_id' => null];
    } else { $grpVarId = 0; }

    $insertDs = $pdo->prepare(
        'INSERT INTO mm_structured_datasets (project_id, title, row_count, col_count, schema_json) VALUES (:p, :t, :rc, :cc, :sj)'
    );
    $insertDs->execute([
        ':p' => $projectId, ':t' => $title !== '' ? $title : 'Mixed-Methods Dataset',
        ':rc' => count($responses), ':cc' => count($columns),
        ':sj' => json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $datasetId = (int)$pdo->lastInsertId();

    // Codes lookup: presence and (optionally) intensity per response+category.
    $codeSelect = $hasIntensity
        ? 'SELECT response_id, category_id, intensity FROM mm_coded_responses WHERE project_id = :p'
        : 'SELECT response_id, category_id FROM mm_coded_responses WHERE project_id = :p';
    $codeStmt = $pdo->prepare($codeSelect);
    $codeStmt->execute([':p' => $projectId]);
    $codesByResp = [];
    foreach ($codeStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $codesByResp[(int)$cr['response_id']][(int)$cr['category_id']] = [
            'present'   => true,
            'intensity' => $hasIntensity ? (string)($cr['intensity'] ?? '') : '',
        ];
    }
    $sentStmt = $pdo->prepare('SELECT response_id, sentiment FROM mm_sentiment_scores WHERE project_id = :p');
    $sentStmt->execute([':p' => $projectId]);
    $sentByResp = [];
    foreach ($sentStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
        $sentByResp[(int)$sr['response_id']] = (string)$sr['sentiment'];
    }

    $intensityScore = function (string $i): int {
        switch ($i) {
            case 'low':      return 1;
            case 'moderate': return 2;
            case 'high':     return 3;
            default:         return 0;
        }
    };
    $sentimentScore = function (string $s): int {
        switch ($s) {
            case 'positive': return 1;
            case 'negative': return -1;
            default:         return 0; // neutral / mixed / empty
        }
    };

    $insCell = $pdo->prepare('INSERT INTO mm_dataset_cells (dataset_id, response_id, variable_id, cell_value) VALUES (:d, :r, :v, :c)');
    foreach ($responses as $r) {
        $rid = (int)$r['id'];
        $text = (string)$r['text'];
        foreach ($columns as $col) {
            $val = '';
            $type = (string)$col['type'];
            $catId = isset($col['category_id']) && $col['category_id'] !== null ? (int)$col['category_id'] : null;

            if ($type === 'binary' && $catId !== null) {
                $val = isset($codesByResp[$rid][$catId]) ? '1' : '0';
            } elseif ($type === 'intensity' && $catId !== null) {
                $code = $codesByResp[$rid][$catId] ?? null;
                $val = $code ? (string)$intensityScore((string)$code['intensity']) : '0';
            } elseif ($type === 'sentiment') {
                $val = $sentByResp[$rid] ?? '';
            } elseif ($type === 'sentiment_num') {
                $val = (string)$sentimentScore($sentByResp[$rid] ?? '');
            } elseif ($type === 'length_chars') {
                $val = (string)mb_strlen($text);
            } elseif ($type === 'length_words') {
                $tokens = preg_split('/\s+/', trim($text));
                $val = (string)(trim($text) === '' ? 0 : count($tokens));
            } elseif ($col['var_name'] === 'numeric_value') {
                $val = $r['numeric_value'] !== null ? (string)$r['numeric_value'] : '';
            } elseif ($col['var_name'] === 'group') {
                $val = $r['group_value'] !== null ? (string)$r['group_value'] : '';
            }
            $insCell->execute([':d' => $datasetId, ':r' => $rid, ':v' => (int)$col['variable_id'], ':c' => $val]);
        }
    }

    $pdo->commit();
    json_out([
        'ok' => true,
        'dataset_id' => $datasetId,
        'row_count'  => count($responses),
        'col_count'  => count($columns),
        'columns'    => $columns,
        'variables'  => $varSelections,
        'capabilities' => ['intensity' => $hasIntensity],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_dataset_failed', 'Could not build dataset: ' . $e->getMessage(), 500);
}
