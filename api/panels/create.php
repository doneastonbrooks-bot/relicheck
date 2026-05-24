<?php
// POST /api/panels/create.php
// Body: { survey_id, name, self_assessment?, confidentiality_mode? }
// Creates a new 360 panel in draft status. The panel is bound to an
// existing survey owned by the caller.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('POST');
check_origin();
$user = require_auth();

// Enforce per-tier panel count limit.
require_once __DIR__ . '/../_tiers.php';
$pdo = db();
$pcs = $pdo->prepare('SELECT COUNT(*) AS c FROM survey_360_panels WHERE user_id = :u');
$pcs->execute([':u' => (int)$user['id']]);
$pcur = (int)($pcs->fetch()['c'] ?? 0);
require_under_limit((int)$user['id'], 'max_panels', $pcur, 1);

$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
$name = trim((string)($body['name'] ?? ''));
$self = !empty($body['self_assessment']) ? 1 : 0;
$mode = (string)($body['confidentiality_mode'] ?? 'anonymous');
if (!in_array($mode, ['anonymous','named'], true)) $mode = 'anonymous';

if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);
if ($name === '') fail('bad_input', 'Panel name is required.', 400);
if (mb_strlen($name) > 160) $name = mb_substr($name, 0, 160);

invitations_require_survey_owned_by($sid, (int)$user['id']);

$pdo = db();
$ins = $pdo->prepare(
    'INSERT INTO survey_360_panels
        (survey_id, user_id, name, status, self_assessment, confidentiality_mode)
     VALUES
        (:sid, :uid, :nm, "draft", :sa, :cm)'
);
$ins->execute([
    ':sid' => $sid,
    ':uid' => (int)$user['id'],
    ':nm'  => $name,
    ':sa'  => $self,
    ':cm'  => $mode,
]);

json_out([
    'ok'       => true,
    'panel_id' => (int)$pdo->lastInsertId(),
]);
