<?php
// GET /api/public/test.php?slug=<slug>
// Public endpoint. Returns the published test stripped of its answer key,
// so the page can render the items for a student. Used by take-test.html.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

$slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    fail('bad_slug', 'Invalid slug.', 400);
}

$stmt = db()->prepare(
    'SELECT id, slug, title, description, num_items, item_labels, is_published
       FROM tests WHERE slug = :slug LIMIT 1'
);
$stmt->execute([':slug' => $slug]);
$test = $stmt->fetch();
if (!$test) fail('not_found', 'No test was found at that link.', 404);
if (!(int)$test['is_published']) fail('not_published', 'This test is not currently open for responses.', 410);

json_out([
    'test' => [
        'slug'        => (string)$test['slug'],
        'title'       => (string)$test['title'],
        'description' => $test['description'] !== null ? (string)$test['description'] : '',
        'num_items'   => (int)$test['num_items'],
        'item_labels' => json_decode((string)$test['item_labels'], true) ?: null,
    ],
]);
