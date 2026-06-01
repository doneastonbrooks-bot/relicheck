<?php
// GET /api/dev/project-load.php?id=N
// Returns the full project payload (project + sections + items + constructs +
// stored SDSI/SIRI review objects). Owner-checked.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
sds_require_project($pdo, (int)$user['id'], $id);

json_out(array_merge(['ok' => true], sds_project_payload($pdo, $id)));
