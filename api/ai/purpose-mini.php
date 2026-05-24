<?php
// POST /api/ai/purpose-mini.php
// Diagnostic stub: same header + boilerplate as purpose-check.php, but
// no AI call and no heredoc. If this executes, the issue is in the
// heredoc body of purpose-check.php. If it also serves as text, the
// issue is in our boilerplate (require_once chain or similar).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

json_out(['ok' => true, 'step' => 'mini', 'time' => date('c')]);
