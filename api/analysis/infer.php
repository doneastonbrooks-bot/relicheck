<?php
// POST /api/analysis/infer.php
// Runs a statistical test on data sent from the Inferential Studio client.
// The client sends variable values directly (already loaded into state.dataset),
// so no server-side dataset lookup is needed.
//
// Body: { tool, values?, groups?, x?, y? }
// Returns the standard _stats.php result shape as JSON.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_stats.php';

require_method('POST');
check_origin();
require_auth();

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || !isset($body['tool'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing tool parameter.']);
    exit;
}

header('Content-Type: application/json');

$tool   = (string)($body['tool']   ?? '');
$values = (array) ($body['values'] ?? []);
$groups = (array) ($body['groups'] ?? []);
$x      = (array) ($body['x']      ?? []);
$y      = (array) ($body['y']      ?? []);

switch ($tool) {
    case 't_test':
        echo json_encode(stats_t_test($values, $groups));
        break;
    case 'anova':
        echo json_encode(stats_anova($values, $groups));
        break;
    case 'chi_square':
        echo json_encode(stats_chi_square($values, $groups));
        break;
    case 'correlation':
        echo json_encode(stats_pearson($x, $y));
        break;
    case 'regression':
        echo json_encode(stats_regression($x, $y));
        break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown tool: ' . $tool]);
}
