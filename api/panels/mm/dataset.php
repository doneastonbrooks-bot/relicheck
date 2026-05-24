<?php
// POST /api/mm/dataset.php
// Body: { project_id, title? }
//
// Builds a structured dataset from the reviewed categories + sentiment for
// the project. Each category becomes a binary column (1 if the response was
// coded to it, 0 otherwise). Sentiment becomes its own column. The dataset
// is stored in mm_structured_datasets + mm_dataset_cells so the user can
// re-export later.
//
// GET /api/mm/dataset.php?project_id=N&id=M   returns the dataset for preview/CSV.

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

    // Load all responses for the project; cells live in mm_dataset_cells keyed
    // by variable_id + response_id.
    $resps = mm_load_responses($pdo, $projectId, 5000);
    $cellStmt = $pdo->prepare(
        'SELECT response_id, variable_id, cell_value FROM mm_dataset_cells WHERE dataset_id = :d'
    );
    $cellStmt->execute([':d' => (int)$ds['id']]);
    $byResp = [];
    foreach ($cellStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $byResp[(int)$c['response_id']][(int)$c['variable_id']] = (string)$c['cell_value'];
    }

    $rows = [];
    foreach ($resps as $r) {
        $rid = (int)$r['id'];
        $row = [
            'respondent_ref' => $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : ('R' . $rid),
        ];
        foreach ($schema as $col) {
            $vid = (int)($col['variable_id'] ?? 0);
            $row[$col['var_name']] = $byResp[$rid][$vid] ?? '';
        }
        $rows[] = $row;
    }

    json_out([
        'ok'      => true,
        'dataset' => [
            'id'        => (int)$ds['id'],
            'title'     => (string)$ds['title'],
            'row_count' => (int)$ds['row_count'],
            'col_count' => (int)$ds['col_count'],
            'columns'   => $schema,
            'rows'      => $rows,
        ],
    ]);
}

// POST: rebuild dataset from current categories + sentiment.
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$title     = clean_string((string)($body['title'] ?? 'Mixed-Methods Dataset'), 200);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

$catStmt = $pdo->prepare('SELECT id, name FROM mm_theme_categories WHERE project_id = :p ORDER BY position ASC, id ASC');
$catStmt->execute([':p' => $projectId]);
$cats = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($cats) === 0) {
    fail('mm_no_categories', 'Run the builder or add categories before building a dataset.');
}

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
    // Replace existing variables and dataset for the project.
    $pdo->prepare('DELETE FROM mm_structured_datasets WHERE project_id = :p')->execute([':p' => $projectId]);
    $pdo->prepare('DELETE FROM mm_generated_variables WHERE project_id = :p')->execute([':p' => $projectId]);

    $insertVar = $pdo->prepare(
        'INSERT INTO mm_generated_variables (project_id, var_name, display_label, var_type, role, source_category_id, notes)
         VALUES (:p, :n, :l, :t, :r, :sc, :nt)'
    );

    $columns = [];
    $usedNames = [];

    // One binary variable per category.
    foreach ($cats as $c) {
        $base = mm_var_name((string)$c['name']);
        $name = $base;
        $i = 2;
        while (isset($usedNames[$name])) { $name = $base . '_' . $i; $i++; }
        $usedNames[$name] = true;

        $insertVar->execute([
            ':p'  => $projectId,
            ':n'  => $name,
            ':l'  => (string)$c['name'],
            ':t'  => 'binary',
            ':r'  => 'predictor',
            ':sc' => (int)$c['id'],
            ':nt' => 'Auto-built from category: ' . (string)$c['name'],
        ]);
        $columns[] = [
            'variable_id' => (int)$pdo->lastInsertId(),
            'var_name'    => $name,
            'label'       => (string)$c['name'],
            'type'        => 'binary',
            'category_id' => (int)$c['id'],
        ];
    }

    // Sentiment column.
    $insertVar->execute([
        ':p'  => $projectId,
        ':n'  => 'sentiment',
        ':l'  => 'Sentiment',
        ':t'  => 'sentiment',
        ':r'  => 'outcome',
        ':sc' => null,
        ':nt' => 'Sentiment label per response.',
    ]);
    $sentVarId = (int)$pdo->lastInsertId();
    $columns[] = [
        'variable_id' => $sentVarId,
        'var_name'    => 'sentiment',
        'label'       => 'Sentiment',
        'type'        => 'sentiment',
        'category_id' => null,
    ];

    // Numeric / group passthroughs when present.
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

    // Dataset header.
    $insertDs = $pdo->prepare(
        'INSERT INTO mm_structured_datasets (project_id, title, row_count, col_count, schema_json)
         VALUES (:p, :t, :rc, :cc, :sj)'
    );
    $insertDs->execute([
        ':p'  => $projectId,
        ':t'  => $title !== '' ? $title : 'Mixed-Methods Dataset',
        ':rc' => count($responses),
        ':cc' => count($columns),
        ':sj' => json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $datasetId = (int)$pdo->lastInsertId();

    // Build coding lookup: response_id -> [category_id, ...]
    $codeStmt = $pdo->prepare('SELECT response_id, category_id FROM mm_coded_responses WHERE project_id = :p');
    $codeStmt->execute([':p' => $projectId]);
    $codesByResp = [];
    foreach ($codeStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $codesByResp[(int)$cr['response_id']][(int)$cr['category_id']] = true;
    }
    $sentStmt = $pdo->prepare('SELECT response_id, sentiment FROM mm_sentiment_scores WHERE project_id = :p');
    $sentStmt->execute([':p' => $projectId]);
    $sentByResp = [];
    foreach ($sentStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
        $sentByResp[(int)$sr['response_id']] = (string)$sr['sentiment'];
    }

    $insCell = $pdo->prepare(
        'INSERT INTO mm_dataset_cells (dataset_id, response_id, variable_id, cell_value)
         VALUES (:d, :r, :v, :c)'
    );

    foreach ($responses as $r) {
        $rid = (int)$r['id'];
        foreach ($columns as $col) {
            if ($col['type'] === 'binary' && $col['category_id'] !== null) {
                $val = isset($codesByResp[$rid][(int)$col['category_id']]) ? '1' : '0';
            } elseif ($col['type'] === 'sentiment') {
                $val = $sentByResp[$rid] ?? '';
            } elseif ($col['var_name'] === 'numeric_value') {
                $val = $r['numeric_value'] !== null ? (string)$r['numeric_value'] : '';
            } elseif ($col['var_name'] === 'group') {
                $val = $r['group_value'] !== null ? (string)$r['group_value'] : '';
            } else {
                $val = '';
            }
            $insCell->execute([
                ':d' => $datasetId,
                ':r' => $rid,
                ':v' => (int)$col['variable_id'],
                ':c' => $val,
            ]);
        }
    }

    $pdo->commit();
    json_out([
        'ok'         => true,
        'dataset_id' => $datasetId,
        'row_count'  => count($responses),
        'col_count'  => count($columns),
        'columns'    => $columns,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('mm_dataset_failed', 'Could not build dataset: ' . $e->getMessage(), 500);
}
