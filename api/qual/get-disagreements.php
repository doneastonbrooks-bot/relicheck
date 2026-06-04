<?php
// GET /api/qual/get-disagreements.php?project_id=N
// Compares lead coder vs second coder, returns per-segment agreement/disagreement.
// Lead researcher only.
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
release_session_lock();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_require_project($pdo, $uid, $projectId);

// Find accepted second coder
$invSt = $pdo->prepare(
    "SELECT accepted_by FROM qual_coder_invites
     WHERE project_id=:p AND status='accepted' LIMIT 1"
);
$invSt->execute([':p' => $projectId]);
$invRow = $invSt->fetch(PDO::FETCH_ASSOC);
if (!$invRow || !$invRow['accepted_by']) {
    json_out([
        'ok'     => true,
        'ready'  => false,
        'reason' => 'No second coder has accepted an invite yet.',
        'stats'  => null,
        'segments' => [],
    ]);
    return;
}
$coderBId = (int)$invRow['accepted_by'];

// Load code names
$codeSt = $pdo->prepare("SELECT id, name FROM qual_codes WHERE project_id=:p ORDER BY id");
$codeSt->execute([':p' => $projectId]);
$codeNames = [];
foreach ($codeSt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $codeNames[(int)$c['id']] = $c['name'];
}

// Load all human code applications (both coders)
$appSt = $pdo->prepare(
    "SELECT segment_id, code_id, coder_id FROM qual_code_applications
     WHERE project_id=:p AND coder_id IN (:a,:b) AND coder_type='human' AND action_type='applied'"
);
$appSt->execute([':p' => $projectId, ':a' => $uid, ':b' => $coderBId]);
$applications = $appSt->fetchAll(PDO::FETCH_ASSOC);

// Group: bySegment[seg_id][coder_id][] = code_id
$bySegment = [];
foreach ($applications as $r) {
    $sid = (int)$r['segment_id'];
    $cid = (int)$r['coder_id'];
    $codeId = (int)$r['code_id'];
    $bySegment[$sid][$cid][$codeId] = true;
}

// Only look at segments coded by both
$bothCoded = array_filter($bySegment, function ($byCoder) use ($uid, $coderBId) {
    return isset($byCoder[$uid]) && isset($byCoder[$coderBId]);
});

$totalBothCoded   = count($bothCoded);
$agreementCount   = 0;
$disagreementSegs = [];

foreach ($bothCoded as $sid => $byCoder) {
    $aSet = $byCoder[$uid]      ?? [];
    $bSet = $byCoder[$coderBId] ?? [];

    // Agreed: in both
    $agreedIds  = array_intersect_key($aSet, $bSet);
    // Only A, only B
    $onlyAIds   = array_diff_key($aSet, $bSet);
    $onlyBIds   = array_diff_key($bSet, $aSet);

    $isAgreement = (count($onlyAIds) === 0 && count($onlyBIds) === 0);
    if ($isAgreement) { $agreementCount++; }

    $toCodeList = function (array $codeIdMap) use ($codeNames): array {
        $out = [];
        foreach (array_keys($codeIdMap) as $cid) {
            $out[] = ['id' => $cid, 'name' => $codeNames[$cid] ?? ('Code ' . $cid)];
        }
        return $out;
    };

    $disagreementSegs[$sid] = [
        'segment_id'   => $sid,
        'is_agreement' => $isAgreement,
        'agreed'       => $toCodeList($agreedIds),
        'only_lead'    => $toCodeList($onlyAIds),
        'only_second'  => $toCodeList($onlyBIds),
    ];
}

// Fetch segment text for the segments we care about
$segIds = array_keys($bothCoded);
$segments = [];
if ($segIds) {
    $placeholders = implode(',', array_fill(0, count($segIds), '?'));
    $segSt = $pdo->prepare(
        "SELECT id, participant_id, question_ref, raw_text, cleaned_text, metadata_json
         FROM qual_segments WHERE id IN ($placeholders) AND project_id=?
         ORDER BY seg_order, id"
    );
    $segSt->execute(array_merge($segIds, [$projectId]));
    foreach ($segSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int)$r['id'];
        $meta = $r['metadata_json'];
        if (is_string($meta)) $meta = json_decode($meta, true) ?: [];
        $segments[] = array_merge($disagreementSegs[$sid] ?? [], [
            'segment_id'    => $sid,
            'participant_id'=> $r['participant_id'],
            'question_ref'  => $r['question_ref'],
            'text'          => $r['cleaned_text'] ?: $r['raw_text'],
            'metadata_json' => $meta,
        ]);
    }
}

// Coder names
$leadName  = $user['name'] ?? $user['email'] ?? 'Lead coder';
$coderBName = null;
try {
    $uSt = $pdo->prepare("SELECT name, email FROM users WHERE id=:id LIMIT 1");
    $uSt->execute([':id' => $coderBId]);
    $uRow = $uSt->fetch(PDO::FETCH_ASSOC);
    if ($uRow) $coderBName = $uRow['name'] ?: $uRow['email'];
} catch (Throwable $_) {}

$agreedPct = $totalBothCoded > 0 ? round($agreementCount / $totalBothCoded * 100, 1) : null;

json_out([
    'ok'    => true,
    'ready' => true,
    'coders' => [
        'lead'   => ['uid' => $uid,      'name' => $leadName,       'role' => 'lead'],
        'second' => ['uid' => $coderBId, 'name' => $coderBName,     'role' => 'second'],
    ],
    'stats' => [
        'both_coded'    => $totalBothCoded,
        'agreements'    => $agreementCount,
        'disagreements' => $totalBothCoded - $agreementCount,
        'agreement_pct' => $agreedPct,
    ],
    'segments' => $segments,
]);
