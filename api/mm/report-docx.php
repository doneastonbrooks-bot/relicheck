<?php
// GET /api/mm/report-docx.php?project_id=N
//
// Streams a real .docx file built from raw OOXML inside a ZIP archive.
// Uses PHP's built-in ZipArchive - no Composer, no external libraries,
// nothing to load from a CDN.
//
// Word's .docx format is a ZIP with these files at minimum:
//   [Content_Types].xml      - MIME types for parts in the package
//   _rels/.rels              - relationships at the package level
//   word/_rels/document.xml.rels  - relationships from document.xml
//   word/document.xml        - the actual document content
//   word/styles.xml          - paragraph and run styles we reference
//
// We translate the report's sanitized HTML into a small set of OOXML
// paragraph and run elements. Whitelist covers the same tags the rich
// text editor saves: p, br, b/strong, i/em, u, h3, h4, ul, ol, li,
// blockquote, table.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if (!class_exists('ZipArchive')) {
    fail('mm_no_zip', 'Server does not have PHP ZipArchive enabled.', 500);
}

// -------- Pull project + sections --------
$projectTitle = '';
try {
    $s = $pdo->prepare('SELECT title FROM mm_projects WHERE id = :p');
    $s->execute([':p' => $projectId]);
    $projectTitle = (string)($s->fetchColumn() ?: ('Project ' . $projectId));
} catch (Throwable $e) {}

$REPORT_SECTIONS = [
    ['key' => 'exec_summary',      'title' => 'Executive Summary'],
    ['key' => 'methods',           'title' => 'Methods'],
    ['key' => 'results_qual',      'title' => 'Results - Qualitative'],
    ['key' => 'results_quant',     'title' => 'Results - Quantitative'],
    ['key' => 'integration',       'title' => 'Integration'],
    ['key' => 'recommendations',   'title' => 'Recommendations'],
    ['key' => 'strength_appendix', 'title' => 'Strength Check Appendix'],
];

$sections = [];
try {
    $s = $pdo->query("SHOW TABLES LIKE 'mm_report_sections'");
    if ($s && $s->fetch()) {
        $stmt = $pdo->prepare(
            'SELECT section_key, title, body_text, body_html, source
             FROM mm_report_sections WHERE project_id = :p'
        );
        $stmt->execute([':p' => $projectId]);
        $byKey = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byKey[(string)$r['section_key']] = $r;
        foreach ($REPORT_SECTIONS as $def) {
            $r = $byKey[$def['key']] ?? null;
            $sections[] = [
                'key'       => $def['key'],
                'title'     => $def['title'],
                'body_text' => (string)($r['body_text'] ?? ''),
                'body_html' => (string)($r['body_html'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {}

if (count($sections) === 0) {
    foreach ($REPORT_SECTIONS as $def) {
        $sections[] = ['key' => $def['key'], 'title' => $def['title'], 'body_text' => '', 'body_html' => ''];
    }
}

// ============================================================
// OOXML helpers
// ============================================================
function dx_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function dx_run(string $text, array $opts = []): string {
    $rpr = '';
    if (!empty($opts['bold']))   $rpr .= '<w:b/>';
    if (!empty($opts['italic'])) $rpr .= '<w:i/>';
    if (!empty($opts['underline'])) $rpr .= '<w:u w:val="single"/>';
    if (!empty($opts['color'])) $rpr .= '<w:color w:val="' . dx_esc((string)$opts['color']) . '"/>';
    if (!empty($opts['size']))  $rpr .= '<w:sz w:val="' . (int)$opts['size'] . '"/>';
    $rpr = $rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '';
    // xml:space="preserve" keeps leading/trailing whitespace.
    return '<w:r>' . $rpr . '<w:t xml:space="preserve">' . dx_esc($text) . '</w:t></w:r>';
}

function dx_paragraph(string $runs, array $opts = []): string {
    $ppr = '';
    if (!empty($opts['style'])) $ppr .= '<w:pStyle w:val="' . dx_esc((string)$opts['style']) . '"/>';
    if (!empty($opts['numId'])) $ppr .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . (int)$opts['numId'] . '"/></w:numPr>';
    if (!empty($opts['indent'])) $ppr .= '<w:ind w:left="' . (int)$opts['indent'] . '"/>';
    $ppr = $ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '';
    return '<w:p>' . $ppr . $runs . '</w:p>';
}

// Walk a DOMElement's inline children and emit OOXML runs with formatting.
function dx_inline_runs(DOMNode $node, array $fmt = []): string {
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $t = (string)$child->nodeValue;
            if ($t !== '') $out .= dx_run($t, $fmt);
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($child->nodeName);
            $next = $fmt;
            if ($tag === 'strong' || $tag === 'b') $next['bold'] = true;
            elseif ($tag === 'em' || $tag === 'i') $next['italic'] = true;
            elseif ($tag === 'u') $next['underline'] = true;
            elseif ($tag === 'br') { $out .= '<w:r><w:br/></w:r>'; continue; }
            $out .= dx_inline_runs($child, $next);
        }
    }
    return $out;
}

// Convert one block-level HTML element into one or more OOXML <w:p> blocks.
function dx_block_html(DOMNode $node): string {
    $out = '';
    foreach ($node->childNodes as $el) {
        if ($el->nodeType === XML_TEXT_NODE) {
            $t = trim((string)$el->nodeValue);
            if ($t !== '') $out .= dx_paragraph(dx_run($t));
            continue;
        }
        if ($el->nodeType !== XML_ELEMENT_NODE) continue;
        $tag = strtolower($el->nodeName);
        if ($tag === 'h3') {
            $out .= dx_paragraph(dx_inline_runs($el, ['bold' => true, 'size' => 28]), ['style' => 'Heading3']);
        } elseif ($tag === 'h4') {
            $out .= dx_paragraph(dx_inline_runs($el, ['bold' => true, 'size' => 24]), ['style' => 'Heading4']);
        } elseif ($tag === 'ul') {
            foreach ($el->getElementsByTagName('li') as $li) {
                $out .= dx_paragraph(dx_inline_runs($li), ['numId' => 1]);
            }
        } elseif ($tag === 'ol') {
            foreach ($el->getElementsByTagName('li') as $li) {
                $out .= dx_paragraph(dx_inline_runs($li), ['numId' => 2]);
            }
        } elseif ($tag === 'blockquote') {
            $out .= dx_paragraph(dx_inline_runs($el, ['italic' => true]), ['indent' => 720]);
        } elseif ($tag === 'table') {
            foreach ($el->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td->nodeType !== XML_ELEMENT_NODE) continue;
                    $tagName = strtolower($td->nodeName);
                    if ($tagName !== 'td' && $tagName !== 'th') continue;
                    $cells[] = trim(preg_replace('/\s+/', ' ', (string)$td->textContent));
                }
                $line = implode('    |    ', $cells);
                $out .= dx_paragraph(dx_run($line));
            }
            $out .= dx_paragraph(dx_run(''));
        } elseif ($tag === 'p' || $tag === 'div') {
            $out .= dx_paragraph(dx_inline_runs($el));
        } else {
            $out .= dx_paragraph(dx_inline_runs($el));
        }
    }
    return $out;
}

// Render one section's body HTML to OOXML.
function dx_section_body(string $html, string $textFallback): string {
    if (trim($html) === '' && trim($textFallback) === '') {
        return dx_paragraph(dx_run('Section not generated yet.', ['italic' => true, 'color' => '9aa3ad']));
    }
    if (trim($html) === '') {
        // Plain text fallback - one paragraph per blank-line block.
        $blocks = preg_split('/\n\s*\n/', trim($textFallback));
        $out = '';
        foreach ($blocks as $b) {
            $b = str_replace("\n", ' ', $b);
            $out .= dx_paragraph(dx_run(trim($b)));
        }
        return $out;
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $dom->getElementsByTagName('div')->item(0);
    if (!$root) return dx_paragraph(dx_run($textFallback !== '' ? $textFallback : ''));
    return dx_block_html($root);
}

// ============================================================
// Assemble document.xml body
// ============================================================
$body = '';
$body .= dx_paragraph(dx_run($projectTitle !== '' ? $projectTitle : 'Mixed-Methods Report', ['bold' => true, 'size' => 40]), ['style' => 'Title']);
$body .= dx_paragraph(dx_run('Mixed-Methods Studio report generated ' . date('F j, Y'), ['italic' => true, 'color' => '5a6470']));
$body .= dx_paragraph(dx_run(''));

foreach ($sections as $sec) {
    $body .= dx_paragraph(dx_run((string)$sec['title'], ['bold' => true, 'size' => 32]), ['style' => 'Heading2']);
    $body .= dx_section_body((string)$sec['body_html'], (string)$sec['body_text']);
    $body .= dx_paragraph(dx_run(''));
}

// ============================================================
// Static OOXML parts
// ============================================================
$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>'
    . $body
    . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
    . '</w:body></w:document>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
    . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="48"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading4"><w:name w:val="heading 4"/><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>'
    . '</w:styles>';

$numberingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="-"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>'
    . '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>'
    . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
    . '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
    . '</w:numbering>';

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
    . '</Types>';

$packageRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
    . '</Relationships>';

// ============================================================
// Build the ZIP
// ============================================================
$tmp = tempnam(sys_get_temp_dir(), 'mmdoc');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    fail('mm_docx_zip_failed', 'Could not create DOCX archive.', 500);
}
$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $packageRels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/styles.xml', $stylesXml);
$zip->addFromString('word/numbering.xml', $numberingXml);
$zip->addFromString('word/_rels/document.xml.rels', $docRels);
$zip->close();

$filename = preg_replace('/[^a-z0-9\-_]+/i', '_', $projectTitle ?: ('project_' . $projectId));
$filename = trim((string)$filename, '_');
if ($filename === '') $filename = 'mixed_methods_report';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
exit;
