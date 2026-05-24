<?php
// GET /api/help/catalog.php
//
// Returns the help-article catalog: categories (slug + title + count) plus
// a flat articles list. Used by the in-app Help Center to render the tile
// grid and the per-category browse view.
//
// No auth required - same content is on the marketing site already.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_help_index.php';

require_method('GET');

json_out([
    'ok'         => true,
    'categories' => help_categories(),
    'articles'   => help_index(),
    'total'      => count(help_index()),
]);
