<?php
// POST /api/public/submit.php
// Body: { slug, answers: { qid: value, ... } }
// Public endpoint. Validates the answers against the published survey
// shape and inserts a row into responses.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_webhooks.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
// We deliberately DON'T call check_origin() here because survey takers may
// arrive via embedded links from email or other origins. Submission is a
// public action by design.

$body = read_json_body();
$slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    fail('bad_slug', 'Invalid slug.', 400);
}

// Rate limit: 60 submissions per IP per slug per hour. Generous for a real
// classroom or panel session, restrictive enough to block bot floods.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
check_rate_limit('submit:ip:' . $ip . ':slug:' . $slug, 60, 3600);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, owner_id, settings, questions, is_published
       FROM surveys WHERE slug = :slug LIMIT 1'
);
$stmt->execute([':slug' => $slug]);
$survey = $stmt->fetch();
if (!$survey) fail('not_found', 'No survey was found at that link.', 404);
if (!(int)$survey['is_published']) fail('not_published', 'This survey is not open for responses.', 410);

// Enforce per-tier responses-per-survey limit (using the OWNER's plan).
$cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM responses WHERE survey_id = :sid');
$cnt->execute([':sid' => $survey['id']]);
$current = (int)$cnt->fetch()['c'];
$ownerInfo = tier_for_user((int)$survey['owner_id']);
$cap = (int)$ownerInfo['limits']['max_responses_per_survey'];
if ($current + 1 > $cap) {
    // Public-facing message; don't reveal the owner's plan name.
    fail('survey_full', "This survey is no longer accepting responses. The owner's plan limit ({$cap}) has been reached.", 410, [
        'limit' => $cap,
    ]);
}

$questions = json_decode((string)$survey['questions'], true) ?: [];
$settings  = json_decode((string)$survey['settings'],  true) ?: [];
$kPoints   = max(2, min(11, (int)($settings['likertPoints'] ?? 5)));

if (!is_array($body['answers'] ?? null)) {
    fail('bad_answers', 'answers must be an object keyed by question id.', 400);
}
$rawAnswers = $body['answers'];

/* Evaluate skip logic against the raw answers. We compute visibility once
   based on the values the respondent submitted; hidden questions are not
   validated as required and not stored. */
$visible = [];
foreach ($questions as $q) {
    $qid = (string)($q['id'] ?? '');
    if ($qid === '') continue;
    $rule = $q['showIf'] ?? null;
    if (!is_array($rule) || empty($rule['questionId'])) {
        $visible[$qid] = true;
        continue;
    }
    $tv = $rawAnswers[$rule['questionId']] ?? null;
    if ($tv === null || $tv === '') { $visible[$qid] = false; continue; }
    $tn = is_numeric($tv) ? (float)$tv : null;
    $rv = is_numeric($rule['value'] ?? null) ? (float)$rule['value'] : null;
    $op = (string)($rule['op'] ?? 'equals');
    if ($op === 'equals')      $visible[$qid] = ($tn !== null && $rv !== null && $tn === $rv);
    elseif ($op === 'not_equals') $visible[$qid] = ($tn !== null && $rv !== null && $tn !== $rv);
    else $visible[$qid] = true;
}

$cleanAnswers = [];
foreach ($questions as $q) {
    $qid  = (string)($q['id']   ?? '');
    $type = (string)($q['type'] ?? '');
    if ($qid === '') continue;
    if (!($visible[$qid] ?? true)) continue;  // hidden by skip logic
    $required = !empty($q['required']);
    $val = $rawAnswers[$qid] ?? null;

    if ($type === 'likert') {
        if ($val === null || $val === '') {
            if ($required) fail('missing_required', 'Please answer all required questions.', 422, ['question_id' => $qid]);
            continue;
        }
        // Use per-question scale if set, else fall back to the survey-level default.
        $qK = isset($q['likertPoints']) ? (int)$q['likertPoints'] : $kPoints;
        if ($qK < 2 || $qK > 11) $qK = $kPoints;
        $n = (int)$val;
        if ($n < 1 || $n > $qK) fail('bad_value', 'Likert value out of range.', 422, ['question_id' => $qid]);
        $cleanAnswers[$qid] = $n;
    } elseif ($type === 'single') {
        if ($val === null || $val === '') {
            if ($required) fail('missing_required', 'Please answer all required questions.', 422, ['question_id' => $qid]);
            continue;
        }
        $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
        $n = (int)$val;
        if ($n < 0 || $n >= count($opts)) fail('bad_value', 'Choice index out of range.', 422, ['question_id' => $qid]);
        $cleanAnswers[$qid] = $n;
    } elseif ($type === 'multi') {
        if (!is_array($val)) {
            if ($required) fail('missing_required', 'Please answer all required questions.', 422, ['question_id' => $qid]);
            continue;
        }
        $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
        $set  = [];
        foreach ($val as $item) {
            $n = (int)$item;
            if ($n >= 0 && $n < count($opts)) $set[$n] = true;
        }
        if ($required && empty($set)) fail('missing_required', 'Please answer all required questions.', 422, ['question_id' => $qid]);
        $cleanAnswers[$qid] = array_keys($set);
    } elseif ($type === 'open') {
        $s = is_string($val) ? trim($val) : '';
        if ($s === '') {
            if ($required) fail('missing_required', 'Please answer all required questions.', 422, ['question_id' => $qid]);
            continue;
        }
        if (mb_strlen($s) > 5000) $s = mb_substr($s, 0, 5000);
        $cleanAnswers[$qid] = $s;
    }
    // ignore unknown types
}

$ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

/* Validate the arm assignment when arming is enabled. The client posts
   the arm_id it received from /api/public/survey.php; we confirm it
   exists and that the cell hasn't filled up between assignment and
   submission. If the arm is full, redirect the response into any other
   arm that still has space (best-effort) rather than reject the work
   the respondent already did. */
$armId = null;
if (!empty($settings['armingEnabled']) && is_array($settings['arms'] ?? null) && count($settings['arms'])) {
    $claimed = isset($body['arm_id']) ? (string)$body['arm_id'] : '';
    $known = [];
    foreach ($settings['arms'] as $a) {
        $aid = (string)($a['id'] ?? '');
        if ($aid === '') continue;
        $known[$aid] = [
            'quota' => isset($a['quota']) && is_numeric($a['quota']) ? (int)$a['quota'] : 0,
        ];
    }
    if ($claimed !== '' && isset($known[$claimed])) {
        $q = $known[$claimed]['quota'];
        if ($q > 0) {
            $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM responses WHERE survey_id = :sid AND arm_id = :a');
            $cnt->execute([':sid' => $survey['id'], ':a' => $claimed]);
            $now = (int)$cnt->fetch()['c'];
            if ($now >= $q) {
                // Arm filled while the respondent was answering. Reassign.
                $claimed = '';
            }
        }
    }
    if ($claimed === '') {
        // Best-effort reassignment to any arm with remaining capacity.
        $cstmt = $pdo->prepare('SELECT arm_id, COUNT(*) AS c FROM responses WHERE survey_id = :sid AND arm_id IS NOT NULL GROUP BY arm_id');
        $cstmt->execute([':sid' => $survey['id']]);
        $live = [];
        foreach ($cstmt->fetchAll() as $r) { $live[(string)$r['arm_id']] = (int)$r['c']; }
        foreach ($known as $aid => $info) {
            if ($info['quota'] === 0 || ($live[$aid] ?? 0) < $info['quota']) {
                $claimed = $aid; break;
            }
        }
    }
    if ($claimed !== '') $armId = $claimed;
    // If still empty, the survey is full; let the response save with arm_id=null.
}

$ins = $pdo->prepare(
    'INSERT INTO responses (survey_id, ip_hash, user_agent, answers, arm_id)
     VALUES (:sid, :ip, :ua, :ans, :arm)'
);
$ins->execute([
    ':sid' => $survey['id'],
    ':ip'  => ip_hash(),
    ':ua'  => $ua,
    ':ans' => json_encode($cleanAnswers, JSON_UNESCAPED_UNICODE),
    ':arm' => $armId,
]);
$responseId = (int)$pdo->lastInsertId();

/* Fire the response.received webhook (non-blocking; errors swallowed in helper).
   Bookkeeping failure must never break submission, so the whole block is wrapped. */
try {
    $titleStmt = $pdo->prepare('SELECT title FROM surveys WHERE id = :id LIMIT 1');
    $titleStmt->execute([':id' => $survey['id']]);
    $title = (string)($titleStmt->fetchColumn() ?: '');
    webhooks_fire('response.received', (int)$survey['owner_id'], [
        'survey_id'      => (int)$survey['id'],
        'survey_title'   => $title,
        'survey_slug'    => $slug,
        'survey_url'     => 'https://relichecksurvey.com/app.html#survey/' . (int)$survey['id'],
        'response_id'    => $responseId,
        'response_count' => $current + 1,
        'submitted_at'   => gmdate('c'),
    ]);
} catch (Throwable $e) {
    // Swallow.
}

/* Phase 38: if this response came in via a tracked invitation link, mark the
   invitation completed. Wrapped in try/catch so a missing helper or table
   never breaks submission. */
$invToken = isset($body['inv']) && is_string($body['inv']) ? $body['inv'] : null;
if ($invToken && preg_match('/^[a-f0-9]{32}$/', $invToken)) {
    try {
        require_once __DIR__ . '/../_invitations.php';
        invitations_mark_completed($invToken, $responseId);
    } catch (Throwable $_) {
        // Swallow.
    }
}

/* Phase 41: channel tag + clean up draft. Additive; never blocks submit.
   - Captures body.ch (email, link, qr, sms, other) into responses.channel.
   - Stamps is_partial=0 + last_seen_at=NOW() on the just-inserted row.
   - Deletes the matching draft row so the response_drafts table doesn't
     accumulate stale rows after submission. */
try {
    $ch = isset($body['ch']) && is_string($body['ch']) ? $body['ch'] : null;
    if ($ch !== null && (mb_strlen($ch) > 32 || !preg_match('/^[a-z0-9_-]+$/', $ch))) {
        $ch = null;
    }
    $pdo->prepare(
        'UPDATE responses
            SET channel = :ch,
                is_partial = 0,
                last_seen_at = NOW()
          WHERE id = :rid'
    )->execute([':ch' => $ch, ':rid' => $responseId]);

    if ($invToken && preg_match('/^[a-f0-9]{32}$/', $invToken)) {
        $pdo->prepare(
            'DELETE FROM response_drafts WHERE survey_id = :sid AND inv_token = :t'
        )->execute([':sid' => (int)$survey['id'], ':t' => $invToken]);
    }
} catch (Throwable $_) {
    // Swallow: Phase 41 columns may not exist yet on a half-applied migration.
}

/* Phase 89: Key Drivers digest threshold trigger.
   If the survey owner set settings.keyDriversDigestN and the response count
   just crossed it (and we have not already fired at this threshold), compute
   a lightweight server-side snapshot and dispatch the digest email.

   Wrapped in try/catch so failure here never breaks submission. The
   idempotency key on the email_logs row is the canonical "did we fire yet"
   record; we also stamp settings.keyDriversDigestSentN as a fast path so the
   next submit doesn't even have to run the snapshot. */
try {
    $thresholdN = isset($settings['keyDriversDigestN']) && is_numeric($settings['keyDriversDigestN'])
        ? (int)$settings['keyDriversDigestN'] : 0;
    $sentN = isset($settings['keyDriversDigestSentN']) && is_numeric($settings['keyDriversDigestSentN'])
        ? (int)$settings['keyDriversDigestSentN'] : 0;
    $newCount = $current + 1;
    if ($thresholdN > 0 && $newCount >= $thresholdN && $sentN < $thresholdN) {
        require_once __DIR__ . '/../_email_dispatcher.php';
        require_once __DIR__ . '/../_keydrivers_snapshot.php';

        // Load full survey shape (questions are already in $questions, but
        // _keydrivers_snapshot expects them under survey.questions).
        $surveyForSnap = [
            'id'            => (int)$survey['id'],
            'title'         => '',
            'likertPoints'  => $kPoints,
            'questions'     => $questions,
        ];
        $tStmt = $pdo->prepare('SELECT title FROM surveys WHERE id = :id LIMIT 1');
        $tStmt->execute([':id' => (int)$survey['id']]);
        $surveyForSnap['title'] = (string)($tStmt->fetchColumn() ?: 'Untitled survey');

        // Pull the full response set (just answers + id).
        $rStmt = $pdo->prepare('SELECT id, answers FROM responses WHERE survey_id = :sid ORDER BY id');
        $rStmt->execute([':sid' => (int)$survey['id']]);
        $allResponses = $rStmt->fetchAll();

        $snap = keydrivers_snapshot($surveyForSnap, $allResponses);
        if (!empty($snap['ok'])) {
            // Owner first name and email come from the users table via the
            // dispatcher's recipient resolver ('customer_self'). We just
            // need to pass the payload variables the template references.
            $payload = [
                'survey_name'     => $surveyForSnap['title'],
                'survey_id'       => (string)$surveyForSnap['id'],
                'response_count'  => $newCount,
                'threshold_n'     => $thresholdN,
                'outcome_label'   => $snap['outcome_label'],
                'top_drivers_html'=> $snap['top_drivers_html'],
                'top_drivers_text'=> $snap['top_drivers_text'],
            ];
            $result = relicheck_email_dispatch('survey.keydrivers_digest_reached', [
                'user_id'    => (int)$survey['owner_id'],
                'account_id' => (int)$survey['owner_id'],
                'idempotency_entity_id' => 'keydrivers-digest:' . (int)$survey['id'] . ':' . $thresholdN,
                'payload'    => $payload,
            ]);
            // Stamp the settings JSON so subsequent submits skip the snapshot
            // work, regardless of whether the dispatch queued or was skipped
            // (e.g., suppressed sender). Best-effort: failure here is fine,
            // the idempotency key still prevents duplicate sends.
            if (!empty($result['queued']) || !empty($result['ok'])) {
                $settings['keyDriversDigestSentN'] = $thresholdN;
                $pdo->prepare('UPDATE surveys SET settings = :s WHERE id = :id')
                    ->execute([
                        ':s'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
                        ':id' => (int)$survey['id'],
                    ]);
            }
        }
    }
} catch (Throwable $_) {
    // Swallow: schema_phase89 may not be applied yet, or the math may have
    // failed on a degenerate response set. Submission must not break.
}

json_out(['ok' => true, 'response_id' => $responseId], 201);
