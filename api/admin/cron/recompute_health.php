<?php
// GET/POST /api/admin/cron/recompute_health.php?key=<email_cron_key>
//
// Nightly sweep that recomputes the cached survey-health columns used by the
// dashboard's per-row health pill:
//
//   surveys.health_alpha_min        - min Cronbach alpha across Likert items.
//                                     NULL when the survey has no Likert
//                                     items, fewer than 2 Likert items, or
//                                     fewer than 5 complete responses.
//   surveys.health_last_response_at - MAX(responses.created_at) for the survey,
//                                     or NULL if there are no responses.
//
// Auth: same email_cron_key as the email queue worker. Schedule once a day.
// Idempotent: every run rewrites the two columns from scratch.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_cron.php';

require_method('GET', 'POST');

$cfg = relicheck_config();
$expected = (string)($cfg['email_cron_key'] ?? '');
if ($expected === '') {
    fail('not_configured', 'email_cron_key is not set in api/_config.php.', 500);
}
$given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
if (!hash_equals($expected, $given)) {
    fail('forbidden', 'Invalid cron key.', 403);
}

cron_heartbeat_start('recompute_health');

$pdo = db();

// Sample variance (denominator n-1) to match Stats.variance() in app.html.
$sampleVariance = function (array $xs): ?float {
    $n = count($xs);
    if ($n < 2) return null;
    $mean = array_sum($xs) / $n;
    $sq = 0.0;
    foreach ($xs as $v) {
        $d = $v - $mean;
        $sq += $d * $d;
    }
    return $sq / ($n - 1);
};

// Cronbach's alpha across a [respondent x item] matrix. Returns null if any
// guard fails (matches the client-side cronbachAlpha NaN cases).
$cronbachAlpha = function (array $matrix) use ($sampleVariance): ?float {
    $n = count($matrix);
    if ($n < 2) return null;
    $k = is_array($matrix[0] ?? null) ? count($matrix[0]) : 0;
    if ($k < 2) return null;

    $itemVarSum = 0.0;
    for ($j = 0; $j < $k; $j++) {
        $col = [];
        foreach ($matrix as $row) $col[] = (float)$row[$j];
        $v = $sampleVariance($col);
        if ($v === null) return null;
        $itemVarSum += $v;
    }
    $totals = [];
    foreach ($matrix as $row) {
        $s = 0.0;
        foreach ($row as $cell) $s += (float)$cell;
        $totals[] = $s;
    }
    $totalVar = $sampleVariance($totals);
    if ($totalVar === null || $totalVar == 0.0) return null;

    return ($k / ($k - 1)) * (1 - ($itemVarSum / $totalVar));
};

$rows = $pdo->query(
    'SELECT id, questions FROM surveys'
)->fetchAll();

$considered    = count($rows);
$alphaWritten  = 0;
$lastWritten   = 0;
$alphaCleared  = 0;
$skipped       = 0;
$errors        = 0;
$errorSamples  = [];

foreach ($rows as $r) {
    try {
        $sid = (int)$r['id'];
        $qs  = json_decode((string)$r['questions'], true) ?: [];
        $likertIds = [];
        foreach ($qs as $q) {
            if (($q['type'] ?? '') === 'likert' && !empty($q['id'])) {
                $likertIds[] = (string)$q['id'];
            }
        }

        // health_last_response_at - always recomputed.
        $maxAt = $pdo->prepare(
            'SELECT MAX(submitted_at) AS m FROM responses WHERE survey_id = :sid'
        );
        $maxAt->execute([':sid' => $sid]);
        $maxRow = $maxAt->fetch();
        $lastResponseAt = $maxRow && $maxRow['m'] ? (string)$maxRow['m'] : null;

        // health_alpha_min - recomputed if survey has 2+ Likert items.
        $alphaMin = null;
        if (count($likertIds) >= 2) {
            $resp = $pdo->prepare(
                'SELECT answers FROM responses WHERE survey_id = :sid'
            );
            $resp->execute([':sid' => $sid]);
            $matrix = [];
            while ($rr = $resp->fetch()) {
                $a = json_decode((string)$rr['answers'], true) ?: [];
                $row = [];
                $complete = true;
                foreach ($likertIds as $qid) {
                    $v = $a[$qid] ?? null;
                    if (!is_numeric($v)) { $complete = false; break; }
                    $row[] = (float)$v;
                }
                if ($complete) $matrix[] = $row;
            }
            if (count($matrix) >= 5) {
                $a = $cronbachAlpha($matrix);
                if ($a !== null && is_finite($a)) {
                    $alphaMin = (float)$a;
                }
            }
        }

        $upd = $pdo->prepare(
            'UPDATE surveys
                SET health_alpha_min = :a,
                    health_last_response_at = :t
              WHERE id = :id'
        );
        $upd->execute([
            ':a'  => $alphaMin,
            ':t'  => $lastResponseAt,
            ':id' => $sid,
        ]);

        if ($alphaMin !== null) $alphaWritten++; else $alphaCleared++;
        if ($lastResponseAt !== null) $lastWritten++;
    } catch (Throwable $e) {
        $errors++;
        if (count($errorSamples) < 3) {
            $errorSamples[] = [
                'survey_id' => isset($sid) ? $sid : null,
                'message'   => $e->getMessage(),
            ];
        }
    }
}

$_cronSummary = [
    'ok'             => true,
    'considered'     => $considered,
    'alpha_written'  => $alphaWritten,
    'alpha_cleared'  => $alphaCleared,
    'last_written'   => $lastWritten,
    'skipped'        => $skipped,
    'errors'         => $errors,
    'error_samples'  => $errorSamples,
];
cron_heartbeat_done('recompute_health', $_cronSummary, $errors > 0 ? ($errors . ' health row(s) failed') : null);
json_out($_cronSummary);
