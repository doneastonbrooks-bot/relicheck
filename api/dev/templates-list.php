<?php
// GET /api/dev/templates-list.php
// Returns the available templates: system templates (seeded on first call) plus
// any owned by the caller. Used by the template browser.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);
sds_seed_system_templates($pdo);

$stmt = $pdo->prepare(
    'SELECT id, slug, category, name, description, items_count, scale, domains, is_system
       FROM survey_templates
      WHERE is_system = 1 OR user_id = :uid
   ORDER BY is_system DESC, category, name'
);
$stmt->execute([':uid' => (int)$user['id']]);

$templates = array_map(function ($r) {
    return [
        'id'        => (int)$r['id'],
        'slug'      => $r['slug'],
        'cat'       => $r['category'],
        'name'      => $r['name'],
        'note'      => $r['description'],
        'items'     => (int)$r['items_count'],
        'scale'     => $r['scale'],
        'domains'   => $r['domains'] !== null ? (json_decode((string)$r['domains'], true) ?: []) : [],
        'is_system' => (bool)$r['is_system'],
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

json_out(['ok' => true, 'templates' => $templates]);
