<?php
// POST /api/public/dashboard_data.php
// Body: { slug: string, password?: string }
//
// Public read-only data feed for the dashboard.html page. The link is
// resolved by its public slug. If the link is password-protected and the
// caller did not supply a matching password, returns
// { ok: false, needs_password: true }. If expired, { ok:false, reason:"expired" }.
// Otherwise returns the aggregate dashboard payload.
//
// PRIVACY: returns aggregate counts only. No respondent identifiers, no
// emails, no invitation tokens. Per-question summaries are bucketed and
// suppressed when an anonymity threshold is configured (k-anonymity from
// the survey's settings.kAnonymityMin).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
// No check_origin: public link can be opened anywhere.

$body = read_json_body();
$slug = trim((string)($body['slug'] ?? ''));
$pwd  =      (string)($body['password'] ?? '');

if ($slug === '' || mb_strlen($slug) > 24) {
    json_out(['ok' => false, 'reason' => 'bad_slug']);
}

$pdo = db();

try {
    $stmt = $pdo->prepare(
        'SELECT pdl.id AS link_id, pdl.survey_id, pdl.password_hash, pdl.expires_at,
                s.title, s.description, s.questions, s.settings, s.is_published
           FROM public_dashboard_links pdl
           JOIN surveys s ON s.id = pdl.survey_id
          WHERE pdl.slug = :s LIMIT 1'
    );
    $stmt->execute([':s' => $slug]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    json_out(['ok' => false, 'reason' => 'unavailable']);
}

if (!$row) {
    json_out(['ok' => false, 'reason' => 'not_found']);
}

// Expiry check.
if (!empty($row['expires_at'])) {
    $exp = $pdo->prepare('SELECT (NOW() > :e) AS expired');
    $exp->execute([':e' => $row['expires_at']]);
    if ((int)$exp->fetch()['expired'] === 1) {
        json_out(['ok' => false, 'reason' => 'expired']);
    }
}

// Password check. Rate-limit attempts per (IP, link) so brute-force is bounded.
if ($row['password_hash']) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($pwd !== '') {
        check_rate_limit('dash_pwd:ip:' . $ip . ':link:' . (int)$row['link_id'], 20, 3600);
    }
    if ($pwd === '' || !password_verify($pwd, (string)$row['password_hash'])) {
        json_out(['ok' => false, 'needs_password' => true]);
    }
}

// Bump view count (best-effort; never blocks the data response).
try {
    $pdo->prepare('UPDATE public_dashboard_links SET view_count = view_count + 1 WHERE id = :id')
        ->execute([':id' => (int)$row['link_id']]);
} catch (Throwable $_) {}

// ---- Build the aggregate payload ---------------------------------------
$surveyId  = (int)$row['survey_id'];
$questions = json_decode((string)$row['questions'], true) ?: [];
$settings  = json_decode((string)$row['settings'],  true) ?: [];

// Total responses (final, not partial).
$cntStmt = $pdo->prepare(
    "SELECT COUNT(*) AS c FROM responses
      WHERE survey_id = :sid AND (is_partial = 0 OR is_partial IS NULL)"
);
try {
    $cntStmt->execute([':sid' => $surveyId]);
    $total = (int)$cntStmt->fetch()['c'];
} catch (Throwable $e) {
    // Pre-Phase 41 fallback: no is_partial column.
    $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM responses WHERE survey_id = :sid');
    $cntStmt->execute([':sid' => $surveyId]);
    $total = (int)$cntStmt->fetch()['c'];
}

// Pull every response's answers for in-PHP aggregation. The respondent
// identifier columns (ip_hash, user_agent) are intentionally NOT selected.
$ansStmt = $pdo->prepare(
    "SELECT answers, submitted_at FROM responses
      WHERE survey_id = :sid
      ORDER BY submitted_at ASC"
);
$ansStmt->execute([':sid' => $surveyId]);
$answers = [];
$daily   = [];
while ($r = $ansStmt->fetch()) {
    $a = json_decode((string)$r['answers'], true);
    if (is_array($a)) $answers[] = $a;
    $d = substr((string)$r['submitted_at'], 0, 10);
    if ($d !== '') {
        $daily[$d] = ($daily[$d] ?? 0) + 1;
    }
}

// Per-question summary + histogram buckets.
$perQuestion = [];
foreach ($questions as $q) {
    $qid = (string)($q['id'] ?? '');
    if ($qid === '') continue;
    $type = (string)($q['type'] ?? '');
    $entry = [
        'id'     => $qid,
        'type'   => $type,
        'prompt' => (string)($q['prompt'] ?? ''),
        'count'  => 0,
    ];

    if ($type === 'likert') {
        $points  = max(2, min(11, (int)($q['likertPoints'] ?? ($settings['likertPoints'] ?? 5))));
        $buckets = array_fill(0, $points, 0);
        $sum = 0; $sumsq = 0; $n = 0;
        foreach ($answers as $a) {
            $v = $a[$qid] ?? null;
            if (!is_numeric($v)) continue;
            $vi = (int)$v;
            if ($vi < 1 || $vi > $points) continue;
            $buckets[$vi - 1]++;
            $sum   += $vi;
            $sumsq += $vi * $vi;
            $n++;
        }
        $entry['count']   = $n;
        $entry['buckets'] = $buckets;
        $entry['mean']    = $n > 0 ? $sum / $n : null;
        $entry['sd']      = $n > 1 ? sqrt(max(0, ($sumsq - ($sum * $sum) / $n) / ($n - 1))) : null;
    } elseif ($type === 'single' || $type === 'multi') {
        $opts    = is_array($q['options'] ?? null) ? $q['options'] : [];
        $buckets = array_fill(0, count($opts), 0);
        $n = 0;
        foreach ($answers as $a) {
            $v = $a[$qid] ?? null;
            if ($type === 'single') {
                if (!is_numeric($v)) continue;
                $vi = (int)$v;
                if (isset($buckets[$vi])) { $buckets[$vi]++; $n++; }
            } else { // multi
                if (!is_array($v)) continue;
                $hadAny = false;
                foreach ($v as $idx) {
                    if (is_numeric($idx) && isset($buckets[(int)$idx])) {
                        $buckets[(int)$idx]++;
                        $hadAny = true;
                    }
                }
                if ($hadAny) $n++;
            }
        }
        $entry['count']   = $n;
        $entry['buckets'] = $buckets;
        $entry['labels']  = $opts;
    } elseif ($type === 'open') {
        $n = 0;
        foreach ($answers as $a) {
            $v = $a[$qid] ?? null;
            if (is_string($v) && trim($v) !== '') $n++;
        }
        $entry['count'] = $n;
    }

    $perQuestion[] = $entry;
}

// k-anonymity floor (per survey settings). Suppresses small buckets.
$k = (int)($settings['kAnonymityMin'] ?? 0);
if ($k > 1) {
    foreach ($perQuestion as &$q) {
        if (!isset($q['buckets'])) continue;
        foreach ($q['buckets'] as &$b) {
            if ($b > 0 && $b < $k) $b = -1; // -1 = suppressed
        }
        unset($b);
    }
    unset($q);
}

// Response-over-time series (daily).
$days = [];
if (!empty($daily)) {
    ksort($daily);
    foreach ($daily as $d => $n) {
        $days[] = ['date' => $d, 'count' => $n];
    }
}

json_out([
    'ok'          => true,
    'survey'      => [
        'title'        => (string)$row['title'],
        'description'  => (string)$row['description'],
        'is_published' => (bool)$row['is_published'],
    ],
    'total_responses' => $total,
    'per_question'    => $perQuestion,
    'daily_responses' => $days,
    'anonymity'       => [
        'k_min' => $k,
    ],
]);
