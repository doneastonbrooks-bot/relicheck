<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'ping' => 'pong', 'time' => date('c')]);
