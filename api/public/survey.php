<?php
// GET /api/public/survey.php?slug=<slug>
// Public endpoint. Returns a sanitized survey ONLY if it's published.
// Used by take.html.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

$slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    fail('bad_slug', 'Invalid slug.', 400);
}

$stmt = db()->prepare(
    'SELECT id, slug, title, description, settings, questions, is_published
       FROM surveys WHERE slug = :slug LIMIT 1'
);
$stmt->execute([':slug' => $slug]);
$row = $stmt->fetch();

if (!$row) {
    fail('not_found', 'No survey was found at that link.', 404);
}
if (!(int)$row['is_published']) {
    fail('not_published', 'This survey is not open for responses yet.', 410);
}

// Sanitize: strip owner info, internal IDs the respondent doesn't need.
$questions = json_decode((string)$row['questions'], true) ?: [];
$settings  = json_decode((string)$row['settings'],  true) ?: [];

/* Random assignment: pick an arm for this respondent based on the
   per-arm quotas and weights configured in settings.arms. The chosen
   arm id is returned in the survey payload and posted back on submit.
   We re-validate on submit, so the client can't claim an arm that's
   already full or doesn't exist. */
$assignedArm = null;
$armingEnabled = !empty($settings['armingEnabled']) && is_array($settings['arms'] ?? null) && count($settings['arms']);
if ($armingEnabled) {
    $arms = $settings['arms'];

    // Pull current per-arm counts in one query
    $cstmt = db()->prepare(
        'SELECT arm_id, COUNT(*) AS c FROM responses
          WHERE survey_id = :sid AND arm_id IS NOT NULL
       GROUP BY arm_id'
    );
    $cstmt->execute([':sid' => $row['id']]);
    $counts = [];
    foreach ($cstmt->fetchAll() as $r) { $counts[(string)$r['arm_id']] = (int)$r['c']; }

    // Eligible arms = those with no quota or remaining capacity
    $eligible = [];
    foreach ($arms as $arm) {
        $aid = (string)($arm['id'] ?? '');
        if ($aid === '') continue;
        $quota = isset($arm['quota']) && is_numeric($arm['quota']) ? (int)$arm['quota'] : 0;
        $weight = isset($arm['weight']) && is_numeric($arm['weight']) && $arm['weight'] > 0 ? (float)$arm['weight'] : 1.0;
        $cnt = $counts[$aid] ?? 0;
        if ($quota > 0 && $cnt >= $quota) continue;
        $eligible[] = ['id' => $aid, 'name' => (string)($arm['name'] ?? $aid), 'weight' => $weight];
    }

    if (count($eligible)) {
        // Weighted random pick
        $total = 0.0;
        foreach ($eligible as $a) $total += $a['weight'];
        $pick = (mt_rand() / mt_getrandmax()) * $total;
        $cum = 0.0;
        $chosen = $eligible[0];
        foreach ($eligible as $a) {
            $cum += $a['weight'];
            if ($pick <= $cum) { $chosen = $a; break; }
        }
        $assignedArm = ['id' => $chosen['id'], 'name' => $chosen['name']];
    } else {
        // All arms have hit their quota. Treat the survey as full.
        fail('survey_full', 'Thanks for your interest. This study has reached its target sample size and is no longer accepting responses.', 410, [
            'reason' => 'all_arms_full',
        ]);
    }
}

// Phase 139: optional cover page (informed consent / introduction screen).
// Lives entirely in settings.coverPage; we whitelist it into the public
// payload so take.html can render the gate. Default to disabled and to
// safe values when any field is missing or malformed.
$cpIn = is_array($settings['coverPage'] ?? null) ? $settings['coverPage'] : null;
$coverPage = ['enabled' => false, 'body' => '', 'consentMode' => 'required', 'consentLabel' => ''];
if ($cpIn !== null && !empty($cpIn['enabled'])) {
    $mode = (string)($cpIn['consentMode'] ?? 'required');
    if (!in_array($mode, ['required', 'optional', 'none'], true)) $mode = 'required';
    $body = (string)($cpIn['body'] ?? '');
    if (strlen($body) > 5000) $body = substr($body, 0, 5000);
    $lbl  = (string)($cpIn['consentLabel'] ?? '');
    if (strlen($lbl) > 200) $lbl = substr($lbl, 0, 200);
    if ($mode === 'none') $lbl = '';
    $coverPage = [
        'enabled'      => true,
        'body'         => $body,
        'consentMode'  => $mode,
        'consentLabel' => $lbl,
    ];
}

json_out([
    'survey' => [
        'slug'        => $row['slug'],
        'title'       => $row['title'],
        'description' => $row['description'],
        'settings'    => [
            'likertPoints'  => (int)($settings['likertPoints'] ?? 5),
            'likertLow'     => (string)($settings['likertLow']  ?? 'Strongly disagree'),
            'likertHigh'    => (string)($settings['likertHigh'] ?? 'Strongly agree'),
            'thankYou'      => (string)($settings['thankYou']   ?? ''),
            'armingEnabled' => $armingEnabled,
            'coverPage'     => $coverPage,
        ],
        'questions'    => $questions,
        'assigned_arm' => $assignedArm,
    ],
]);
