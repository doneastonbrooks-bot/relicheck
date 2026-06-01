<?php
// POST /api/mm/trustworthiness.php
//   Body: { project_id,
//           save_member?: [ { finding, date?, outcome?, feedback? } ] }  // optional: save member-checking log
//
// Backs the qualitative "Trustworthiness" step (the qualitative parallel to
// instrument quality). Three real practices:
//   1. Audit trail      — derived from existing project lifecycle events.
//   2. Member checking  — a log the researcher keeps (persisted in mm_trustworthiness).
//   3. Coding agreement — Cohen's / Fleiss' kappa computed from mm_coded_responses.
//
// Touches NO scoring/RSSI/SIRI logic. The member-checking store (mm_trustworthiness)
// is a new self-contained table; if the migration has not run yet the endpoint
// degrades gracefully (storage_ready=false) instead of erroring.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';
require_once __DIR__ . '/../_stats.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
check_rate_limit('mm_trust:user:' . $uid, 240, 3600);

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'project_id is required.');
$proj = mm_require_project($pdo, $uid, $projectId);

$kappaLabel = static function (?float $k): string {
    if ($k === null) return '—';
    if ($k < 0)     return 'Poor';
    if ($k <= 0.20) return 'Slight';
    if ($k <= 0.40) return 'Fair';
    if ($k <= 0.60) return 'Moderate';
    if ($k <= 0.80) return 'Substantial';
    return 'Almost perfect';
};

// ----------------------------------------------------------------------------
// SAVE member-checking log (graceful if the table is not migrated yet)
// ----------------------------------------------------------------------------
$storageReady = true;
if (isset($body['save_member']) && is_array($body['save_member'])) {
    $clean = [];
    $i = 0;
    foreach ($body['save_member'] as $m) {
        if (!is_array($m)) continue;
        $finding = clean_string((string)($m['finding'] ?? ''), 400);
        if ($finding === '') continue;
        $outcome = (string)($m['outcome'] ?? 'Confirmed');
        if (!in_array($outcome, ['Confirmed', 'Revised', 'Mixed'], true)) $outcome = 'Confirmed';
        $date = clean_string((string)($m['date'] ?? ''), 20);
        if ($date === '') $date = date('Y-m-d');
        $clean[] = [
            'id'       => ++$i,
            'finding'  => $finding,
            'date'     => $date,
            'outcome'  => $outcome,
            'feedback' => clean_string((string)($m['feedback'] ?? ''), 1000),
        ];
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    try {
        $st = $pdo->prepare(
            'INSERT INTO mm_trustworthiness (project_id, member_checking) VALUES (:p, :m)
             ON DUPLICATE KEY UPDATE member_checking = VALUES(member_checking)'
        );
        $st->execute([':p' => $projectId, ':m' => $json]);
    } catch (PDOException $e) {
        // Table not migrated yet — report cleanly rather than 500.
        json_out(['ok' => false, 'storage_ready' => false,
                  'message' => 'Member-checking storage will be available after the next deploy.'], 200);
    }
}

// ----------------------------------------------------------------------------
// READ member-checking log
// ----------------------------------------------------------------------------
$memberChecking = [];
try {
    $st = $pdo->prepare('SELECT member_checking FROM mm_trustworthiness WHERE project_id = :p LIMIT 1');
    $st->execute([':p' => $projectId]);
    $raw = $st->fetchColumn();
    if ($raw) $memberChecking = json_decode((string)$raw, true) ?: [];
} catch (PDOException $e) {
    $storageReady = false;
}

// ----------------------------------------------------------------------------
// AUDIT TRAIL — derived from existing project lifecycle (read-only)
// ----------------------------------------------------------------------------
$fmt = static function ($dt): string {
    if (!$dt) return '';
    $t = strtotime((string)$dt);
    return $t ? date('Y-m-d', $t) : (string)$dt;
};
$events = []; // each: [sortKey, when, event, detail]

// Project created
$events[] = [(string)($proj['created_at'] ?? '0'), $fmt($proj['created_at'] ?? ''), 'Project created',
             clean_string((string)($proj['title'] ?? ''), 200)];

// Dataset linked
$datasetId = (int)($proj['dataset_id'] ?? 0);
if ($datasetId > 0) {
    try {
        $st = $pdo->prepare('SELECT title, row_count, column_count, created_at FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
        $st->execute([':d' => $datasetId, ':u' => $uid]);
        if ($drow = $st->fetch(PDO::FETCH_ASSOC)) {
            $detail = trim((string)($drow['title'] ?? 'Dataset'));
            if (!empty($drow['row_count'])) $detail .= ' · ' . (int)$drow['row_count'] . ' rows · ' . (int)$drow['column_count'] . ' columns';
            $events[] = [(string)($drow['created_at'] ?? $proj['created_at']), $fmt($drow['created_at'] ?? ''), 'Data linked', $detail];
        }
    } catch (PDOException $e) { /* ignore */ }
}

// Coders added
$coderRoster = []; // user_id => role
try {
    $st = $pdo->prepare('SELECT user_id, role, added_at FROM mm_project_coders WHERE project_id = :p AND revoked_at IS NULL ORDER BY added_at');
    $st->execute([':p' => $projectId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $coderRoster[(int)$cr['user_id']] = (string)$cr['role'];
        $events[] = [(string)$cr['added_at'], $fmt($cr['added_at']), 'Coder added', ucfirst((string)$cr['role'])];
    }
} catch (PDOException $e) { /* coder tables absent */ }

// Member-checking events
foreach ($memberChecking as $m) {
    $events[] = [(string)($m['date'] ?? ''), (string)($m['date'] ?? ''), 'Member check recorded',
                 (string)($m['outcome'] ?? '') . ' · ' . (string)($m['finding'] ?? '')];
}

usort($events, fn($a, $b) => strcmp($a[0], $b[0]));
$audit = array_map(fn($e) => ['when' => $e[1] ?: '—', 'event' => $e[2], 'detail' => $e[3]], $events);

// ----------------------------------------------------------------------------
// CODING AGREEMENT — Cohen's (2 coders) / Fleiss' (3+) kappa
// ----------------------------------------------------------------------------
$agreement = ['computable' => false, 'method' => null, 'coders' => 0, 'shared_responses' => 0,
              'categories' => 0, 'percent_agreement' => null, 'kappa' => null,
              'interpretation' => '—', 'per_category' => [], 'note' => ''];
$coderList = [];

try {
    $st = $pdo->prepare('SELECT response_id, category_id, coder_id FROM mm_coded_responses WHERE project_id = :p');
    $st->execute([':p' => $projectId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Category names
    $catNames = [];
    try {
        $cs = $pdo->prepare('SELECT id, name FROM mm_theme_categories WHERE project_id = :p ORDER BY position');
        $cs->execute([':p' => $projectId]);
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) $catNames[(int)$c['id']] = (string)$c['name'];
    } catch (PDOException $e) { /* ignore */ }

    $reviewed = []; // coder => [response_id => true]
    $applied  = []; // coder => ["resp|cat" => true]
    $coders   = [];
    $cats     = [];
    $codeCount = [];
    foreach ($rows as $r) {
        $cd = (int)$r['coder_id']; $rid = (int)$r['response_id']; $cat = (int)$r['category_id'];
        $coders[$cd] = true; $cats[$cat] = true;
        $reviewed[$cd][$rid] = true;
        $applied[$cd][$rid . '|' . $cat] = true;
        $codeCount[$cd] = ($codeCount[$cd] ?? 0) + 1;
    }
    $coderIds = array_keys($coders);
    $catIds   = array_keys($cats);
    sort($coderIds); sort($catIds);
    $nCoders = count($coderIds);

    // Roster display
    $n = 0;
    foreach ($coderIds as $cd) {
        $role = $coderRoster[$cd] ?? 'coder';
        $coderList[] = ['label' => $role === 'owner' ? 'Owner' : 'Coder ' . (++$n), 'role' => $role, 'coded' => $codeCount[$cd] ?? 0];
    }

    $appliedLabel = static function (array $applied, int $cd, int $rid, int $cat): string {
        return isset($applied[$cd][$rid . '|' . $cat]) ? '1' : '0';
    };

    if ($nCoders < 2) {
        $agreement['note'] = 'Coding agreement needs at least two coders. Invite a second coder to code the same responses, then return here.';
        $agreement['coders'] = $nCoders;
    } elseif (empty($catIds)) {
        $agreement['note'] = 'No coded themes yet. Code some responses first.';
        $agreement['coders'] = $nCoders;
    } elseif ($nCoders === 2) {
        [$c1, $c2] = $coderIds;
        $shared = array_keys(array_intersect_key($reviewed[$c1] ?? [], $reviewed[$c2] ?? []));
        sort($shared);
        if (!$shared) {
            $agreement['note'] = 'The two coders have not coded any of the same responses yet.';
            $agreement['coders'] = 2;
        } else {
            $A = []; $B = []; $perCat = [];
            foreach ($catIds as $cat) {
                $a = []; $b = [];
                foreach ($shared as $rid) {
                    $av = $appliedLabel($applied, $c1, $rid, $cat);
                    $bv = $appliedLabel($applied, $c2, $rid, $cat);
                    $a[] = $av; $b[] = $bv; $A[] = $av; $B[] = $bv;
                }
                $k = stats_cohen_kappa($a, $b);
                $perCat[] = ['category' => $catNames[$cat] ?? ('Theme #' . $cat), 'kappa' => $k,
                             'label' => $kappaLabel($k), 'n' => count($shared)];
            }
            $kall = stats_cohen_kappa($A, $B);
            $match = 0; for ($i = 0, $m = count($A); $i < $m; $i++) if ($A[$i] === $B[$i]) $match++;
            $agreement = array_merge($agreement, [
                'computable' => true, 'method' => "Cohen's \u{3ba}", 'coders' => 2,
                'shared_responses' => count($shared), 'categories' => count($catIds),
                'percent_agreement' => count($A) ? round(100 * $match / count($A), 1) : null,
                'kappa' => $kall === null ? null : round($kall, 3), 'interpretation' => $kappaLabel($kall),
                'per_category' => $perCat, 'note' => '',
            ]);
        }
    } else {
        // 3+ coders → Fleiss over responses reviewed by ALL coders
        $sharedAll = $reviewed[$coderIds[0]] ?? [];
        foreach (array_slice($coderIds, 1) as $cd) $sharedAll = array_intersect_key($sharedAll, $reviewed[$cd] ?? []);
        $shared = array_keys($sharedAll); sort($shared);
        if (!$shared) {
            $agreement['note'] = 'The coders have not all coded the same responses yet, so a multi-coder kappa cannot be computed.';
            $agreement['coders'] = $nCoders;
        } else {
            $buildTable = function (array $catSubset) use ($shared, $applied, $coderIds, $appliedLabel) {
                $table = [];
                foreach ($shared as $rid) foreach ($catSubset as $cat) {
                    $ones = 0;
                    foreach ($coderIds as $cd) if ($appliedLabel($applied, $cd, $rid, $cat) === '1') $ones++;
                    $table[] = [count($coderIds) - $ones, $ones];
                }
                return $table;
            };
            $perCat = [];
            foreach ($catIds as $cat) {
                $k = stats_fleiss_kappa($buildTable([$cat]));
                $perCat[] = ['category' => $catNames[$cat] ?? ('Theme #' . $cat), 'kappa' => $k,
                             'label' => $kappaLabel($k), 'n' => count($shared)];
            }
            $kall = stats_fleiss_kappa($buildTable($catIds));
            // unanimous-agreement % across cells
            $cells = 0; $unan = 0;
            foreach ($shared as $rid) foreach ($catIds as $cat) {
                $ones = 0; foreach ($coderIds as $cd) if ($appliedLabel($applied, $cd, $rid, $cat) === '1') $ones++;
                $cells++; if ($ones === 0 || $ones === count($coderIds)) $unan++;
            }
            $agreement = array_merge($agreement, [
                'computable' => true, 'method' => "Fleiss' \u{3ba}", 'coders' => $nCoders,
                'shared_responses' => count($shared), 'categories' => count($catIds),
                'percent_agreement' => $cells ? round(100 * $unan / $cells, 1) : null,
                'kappa' => $kall === null ? null : round($kall, 3), 'interpretation' => $kappaLabel($kall),
                'per_category' => $perCat, 'note' => '',
            ]);
        }
    }
} catch (PDOException $e) {
    $agreement['note'] = 'Coding data is not available for this project yet.';
}

json_out([
    'ok'              => true,
    'storage_ready'   => $storageReady,
    'audit'           => $audit,
    'coders'          => $coderList,
    'agreement'       => $agreement,
    'member_checking' => $memberChecking,
]);
