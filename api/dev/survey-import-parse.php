<?php
// POST /api/dev/survey-import-parse.php
// Accepts a multipart file upload (.docx, .txt, .csv) and returns the
// extracted plain text so the client can feed it into parseSurveyText().
// Only .docx needs the server; .txt / .csv are read client-side and this
// endpoint is not called for those types.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
require_auth();

header('Content-Type: application/json');

if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'No file received.']);
    exit;
}

$file = $_FILES['file'];
$name = (string)($file['name'] ?? '');
$tmp  = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);

if ($size > 8 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File is too large. Maximum size is 8 MB.']);
    exit;
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

if ($ext !== 'docx') {
    echo json_encode(['ok' => false, 'error' => 'Only .docx files need server parsing. Send .txt and .csv directly from the browser.']);
    exit;
}

if (!class_exists('ZipArchive')) {
    echo json_encode(['ok' => false, 'error' => 'Server cannot read .docx files right now. Copy and paste the text instead.']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    echo json_encode(['ok' => false, 'error' => 'Could not open the file. Make sure it is a valid .docx.']);
    exit;
}

$xml = $zip->getFromName('word/document.xml');
$zip->close();

if ($xml === false) {
    echo json_encode(['ok' => false, 'error' => 'Could not read document content. The file may be corrupted.']);
    exit;
}

// Each <w:p> is a paragraph — insert a newline before it so item boundaries survive tag stripping.
$text = preg_replace('/<w:p[ >\/]/', "\n<w:p>", $xml);

// Tabs and line breaks in XML become spaces between words.
$text = preg_replace('/<w:tab\/>/', "\t", $text);
$text = preg_replace('/<w:br[^>]*\/>/', "\n", $text);

$text = strip_tags($text);
$text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

// Collapse horizontal whitespace; preserve paragraph breaks.
$text = preg_replace('/[ \t]+/', ' ', $text);
$text = preg_replace('/\n /u', "\n", $text);
$text = preg_replace('/\n{3,}/u', "\n\n", $text);
$text = trim($text);

echo json_encode(['ok' => true, 'text' => $text, 'filename' => $name]);
