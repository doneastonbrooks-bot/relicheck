<?php
// GET /api/mm/report-export.php?project_id=N&format=md
//
// Returns the assembled mixed-methods report as plain markdown or as a
// structured JSON payload the front end can use to build DOCX / PDF
// client-side.
//
// format options:
//   md   - returns text/markdown body
//   json - returns the structured payload (sections + meta) for client builders
//
// Sections appear in canonical order. User-edited prose wins over AI/template
// drafts. Strips HTML tags from body_html before emitting markdown so headings,
// bold, italic, and lists survive cleanly.

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
$format    = clean_string((string)($_GET['format'] ?? 'md'), 16);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
if (!in_array($format, ['md', 'json'], true)) fail('bad_input', 'format must be md or json.');
mm_require_project($pdo, $uid, $projectId);

// ----------------------------------------------------------------
// Pull project meta + ordered sections.
// ----------------------------------------------------------------
$projectTitle = '';
try {
    $s = $pdo->prepare('SELECT title FROM mm_projects WHERE id = :p');
    $s->execute([':p' => $projectId]);
    $projectTitle = (string)($s->fetchColumn() ?: ('Project ' . $projectId));
} catch (Throwable $e) {}

$REPORT_SECTIONS = [
    ['key' => 'exec_summary',      'title' => 'Executive Summary'],
    ['key' => 'methods',           'title' => 'Methods'],
    ['key' => 'results_qual',      'title' => 'Results — Qualitative'],
    ['key' => 'results_quant',     'title' => 'Results — Quantitative'],
    ['key' => 'integration',       'title' => 'Integration'],
    ['key' => 'recommendations',   'title' => 'Recommendations'],
    ['key' => 'strength_appendix', 'title' => 'Strength Check Appendix'],
];

$sections = [];
try {
    if (sc_table_exists($pdo, 'mm_report_sections')) {
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
                'key'   => $def['key'],
                'title' => $def['title'],
                'body_text' => (string)($r['body_text'] ?? ''),
                'body_html' => (string)($r['body_html'] ?? ''),
                'source'    => (string)($r['source'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {}

// Empty fallback if the project hasn't run Generate report yet.
if (count($sections) === 0) {
    foreach ($REPORT_SECTIONS as $def) {
        $sections[] = ['key' => $def['key'], 'title' => $def['title'], 'body_text' => '', 'body_html' => '', 'source' => ''];
    }
}

// ----------------------------------------------------------------
// JSON output
// ----------------------------------------------------------------
if ($format === 'json') {
    json_out([
        'ok'        => true,
        'project_id'=> $projectId,
        'title'     => $projectTitle,
        'sections'  => $sections,
        'generated_at' => date('c'),
    ]);
}

// ----------------------------------------------------------------
// Markdown output
// ----------------------------------------------------------------
header('Content-Type: text/markdown; charset=utf-8');
$filename = preg_replace('/[^a-z0-9\-_]+/i', '_', $projectTitle ?: ('project_' . $projectId));
$filename = trim((string)$filename, '_');
if ($filename === '') $filename = 'mixed_methods_report';
header('Content-Disposition: attachment; filename="' . $filename . '.md"');

$out = '# ' . ($projectTitle !== '' ? $projectTitle : ('Mixed-Methods Report')) . "\n\n";
$out .= '_Mixed-Methods Studio report generated ' . date('Y-m-d') . '_' . "\n\n";

foreach ($sections as $sec) {
    $out .= '## ' . $sec['title'] . "\n\n";
    if ($sec['body_html'] !== '') {
        $out .= rep_html_to_markdown($sec['body_html']) . "\n\n";
    } elseif ($sec['body_text'] !== '') {
        $out .= $sec['body_text'] . "\n\n";
    } else {
        $out .= "_Section not generated yet._\n\n";
    }
}

echo $out;
exit;

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
function sc_table_exists(PDO $pdo, string $name): bool {
    try {
        $s = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name));
        return $s && $s->fetch() !== false;
    } catch (Throwable $e) { return false; }
}

// Convert the small whitelist of HTML tags we save into markdown.
function rep_html_to_markdown(string $html): string {
    // Decode entities so we get real characters in the output.
    $h = $html;
    // Normalize self-closing or broken tags so the regexes are simple.
    $h = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $h);
    $h = preg_replace('#</\s*p\s*>#i', "\n\n", $h);
    $h = preg_replace('#<\s*p[^>]*>#i', '', $h);
    $h = preg_replace('#<\s*h3[^>]*>#i', "\n### ", $h);
    $h = preg_replace('#</\s*h3\s*>#i', "\n\n", $h);
    $h = preg_replace('#<\s*h4[^>]*>#i', "\n#### ", $h);
    $h = preg_replace('#</\s*h4\s*>#i', "\n\n", $h);
    $h = preg_replace('#<\s*strong[^>]*>#i', '**', $h);
    $h = preg_replace('#</\s*strong\s*>#i', '**', $h);
    $h = preg_replace('#<\s*b[^>]*>#i', '**', $h);
    $h = preg_replace('#</\s*b\s*>#i', '**', $h);
    $h = preg_replace('#<\s*em[^>]*>#i', '*', $h);
    $h = preg_replace('#</\s*em\s*>#i', '*', $h);
    $h = preg_replace('#<\s*i[^>]*>#i', '*', $h);
    $h = preg_replace('#</\s*i\s*>#i', '*', $h);
    $h = preg_replace('#<\s*u[^>]*>#i', '', $h);
    $h = preg_replace('#</\s*u\s*>#i', '', $h);
    $h = preg_replace('#<\s*blockquote[^>]*>#i', "\n> ", $h);
    $h = preg_replace('#</\s*blockquote\s*>#i', "\n\n", $h);
    // Lists. We turn <ul>/<ol> into newlines and prefix each <li> with - or 1.
    $h = preg_replace_callback('#<\s*ul[^>]*>(.*?)</\s*ul\s*>#is', function ($m) {
        $body = preg_replace_callback('#<\s*li[^>]*>(.*?)</\s*li\s*>#is', function ($mm) {
            return "- " . trim(preg_replace('/\s+/', ' ', strip_tags($mm[1]))) . "\n";
        }, $m[1]);
        return "\n" . $body . "\n";
    }, $h);
    $h = preg_replace_callback('#<\s*ol[^>]*>(.*?)</\s*ol\s*>#is', function ($m) {
        $i = 0;
        $body = preg_replace_callback('#<\s*li[^>]*>(.*?)</\s*li\s*>#is', function ($mm) use (&$i) {
            $i++;
            return $i . '. ' . trim(preg_replace('/\s+/', ' ', strip_tags($mm[1]))) . "\n";
        }, $m[1]);
        return "\n" . $body . "\n";
    }, $h);
    // Tables: collapse to plain text rows. Markdown tables would require
    // careful header detection; this is good enough for the Results-Quant
    // and Strength sections we emit.
    $h = preg_replace_callback('#<\s*table[^>]*>(.*?)</\s*table\s*>#is', function ($m) {
        $rows = [];
        if (preg_match_all('#<\s*tr[^>]*>(.*?)</\s*tr\s*>#is', $m[1], $rm)) {
            foreach ($rm[1] as $row) {
                if (preg_match_all('#<\s*t[dh][^>]*>(.*?)</\s*t[dh]\s*>#is', $row, $cm)) {
                    $cells = array_map(function ($c) {
                        return trim(preg_replace('/\s+/', ' ', strip_tags($c)));
                    }, $cm[1]);
                    $rows[] = implode(' | ', $cells);
                }
            }
        }
        return "\n" . implode("\n", $rows) . "\n\n";
    }, $h);

    // Drop any remaining tags.
    $h = strip_tags((string)$h);
    // Decode HTML entities.
    $h = html_entity_decode($h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Collapse runs of blank lines.
    $h = preg_replace("/\n{3,}/", "\n\n", $h);
    return trim((string)$h);
}
