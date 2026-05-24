<?php
// Diagnostic file removed 2026-05-22 after Codebook save HY093 bug was fixed.
// Safe to delete this file from the host via FileZilla.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'gone', 'message' => 'Diagnostic endpoint removed.']);
