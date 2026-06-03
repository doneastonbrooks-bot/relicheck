<?php
// GET /api/qual/get-trustworthiness.php?project_id=N
// Returns:
//   reflexivity   — researcher stance memo + RQ from qual_projects
//   member_checks — array of member-check memos (memo_type='member_check')
//   agreement     — coding agreement stats from qual_code_applications

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';
require_once __DIR__ . '/../_stats.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

$proj = qual_require_project($pdo, $uid, $projectId);

// ── Researcher reflexivity ────────────────────────────────────────────────
$reflexivity = [
    'stance_memo'       => (string)($proj['researcher_stance_memo'] ?? ''),
    'research_question' => (string)($proj['research_question']      ?? ''),
    'analysis_approach' => (string)($proj['analysis_approach']      ?? ''),
];

// ── Member checks ─────────────────────────────────────────────────────────
$mcSt = $pdo->prepare(
    "SELECT id, body, created_at FROM qual_memos
     WHERE project_id=:p AND memo_type='member_check' AND object_type='project'
     ORDER BY id ASC"
);
$mcSt->execute([':p' => $projectId]);
$memberChecks = [];
foreach ($mcSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $data = json_decode((string)$row['body'], true) ?: [];
    $data['memo_id']    = (int)$row['id'];
    $data['created_at'] = (string)$row['created_at'];
    $memberChecks[] = $data;
}

// ── Coding agreement ──────────────────────────────────────────────────────
$agreement = [
    'computable'        => false,
    'note'              => '',
    'coders'            => 0,
    'shared_segments'   => 0,
    'kappa'             => null,
    'percent_agreement' => null,
    'interpretation'    => null,
    'code_breakdown'    => [],
];

$kappaLabel = static function (?float $k): string {
    if ($k === null) return '—';
    if ($k < 0)     return 'Poor';
    if ($k <= 0.20) return 'Slight';
    if ($k <= 0.40) return 'Fair';
    if ($k <= 0.60) return 'Moderate';
    if ($k <= 0.80) return 'Substantial';
    return 'Almost perfect';
};

// Load all human-applied codes
$appSt = $pdo->prepare(
    "SELECT segment_id, code_id, coder_id FROM qual_code_applications
     WHERE project_id=:p AND coder_type='human' AND action_type='applied'"
);
$appSt->execute([':p' => $projectId]);
$applications = $appSt->fetchAll(PDO::FETCH_ASSOC);

// Group by coder → segment → code
$byCoder = [];
foreach ($applications as $r) {
    $cd  = (int)$r['coder_id'];
    $sid = (int)$r['segment_id'];
    $cid = (int)$r['code_id'];
    $byCoder[$cd][$sid][$cid] = true;
}

$coderIds = array_keys($byCoder);
sort($coderIds);
$nCoders  = count($coderIds);
$agreement['coders'] = $nCoders;

// Load all active codes
$codeSt = $pdo->prepare(
    "SELECT id, name FROM qual_codes WHERE project_id=:p AND status<>'retired' ORDER BY id"
);
$codeSt->execute([':p' => $projectId]);
$allCodes = [];
foreach ($codeSt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $allCodes[(int)$c['id']] = (string)$c['name'];
}

if ($nCoders < 2) {
    $agreement['note'] = 'Coding agreement requires at least two coders. Use the Coding Workspace to invite a second coder and have them code the same segments.';
} elseif (empty($allCodes)) {
    $agreement['note'] = 'No codes applied yet. Apply codes in the Coding Workspace first.';
} else {
    [$c1, $c2] = $coderIds;
    $segs1 = array_keys($byCoder[$c1] ?? []);
    $segs2 = array_keys($byCoder[$c2] ?? []);
    $shared = array_intersect($segs1, $segs2);
    $agreement['shared_segments'] = count($shared);

    if (count($shared) < 2) {
        $agreement['note'] = 'Need at least 2 segments coded by both coders to compute agreement. Currently ' . count($shared) . ' shared.';
    } else {
        $codeIds = array_keys($allCodes);
        $aVec = []; $bVec = [];
        $perCode = [];

        foreach ($shared as $sid) {
            foreach ($codeIds as $cid) {
                $a1 = isset($byCoder[$c1][$sid][$cid]) ? '1' : '0';
                $a2 = isset($byCoder[$c2][$sid][$cid]) ? '1' : '0';
                $aVec[] = $a1;
                $bVec[] = $a2;
                if (!isset($perCode[$cid])) $perCode[$cid] = [0, 0];
                $perCode[$cid][1]++;
                if ($a1 === $a2) $perCode[$cid][0]++;
            }
        }

        $kappa = stats_cohen_kappa($aVec, $bVec);
        $total = count($aVec);
        $agreeN = 0;
        for ($i = 0; $i < $total; $i++) if ($aVec[$i] === $bVec[$i]) $agreeN++;
        $pctAgree = $total > 0 ? round($agreeN / $total * 100, 1) : null;

        $breakdown = [];
        foreach ($perCode as $cid => [$ag, $tot]) {
            if ($tot === 0 || !isset($allCodes[$cid])) continue;
            $breakdown[] = [
                'code'  => $allCodes[$cid],
                'pct'   => round($ag / $tot * 100, 1),
                'agree' => $ag,
                'total' => $tot,
            ];
        }
        // Sort ascending by agreement (lowest agreement first = needs most attention)
        usort($breakdown, fn($a, $b) => $a['pct'] <=> $b['pct']);

        $agreement['computable']        = true;
        $agreement['kappa']             = $kappa !== null ? round((float)$kappa, 3) : null;
        $agreement['percent_agreement'] = $pctAgree;
        $agreement['interpretation']    = $kappaLabel($kappa);
        $agreement['code_breakdown']    = array_slice($breakdown, 0, 12);
    }
}

json_out([
    'ok'           => true,
    'reflexivity'  => $reflexivity,
    'member_checks' => $memberChecks,
    'agreement'    => $agreement,
]);
