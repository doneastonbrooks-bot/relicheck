<?php
// GET /api/_ping.php
// One-shot sanity check. No auth, no dependencies, no database. If this
// returns JSON, the upload path works and the PHP environment is healthy.
// If this returns blank, the issue is at the IONOS / FileZilla layer.

header('Content-Type: application/json');
echo json_encode([
    'ok'         => true,
    'message'    => 'pong',
    'php'        => phpversion(),
    'time'       => date('c'),
    'script'     => basename(__FILE__),
]);
