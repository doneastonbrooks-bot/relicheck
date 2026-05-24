<?php
// POST /api/ai/rewrite-finding.php
// Body: {
//   project_id: <int>,
//   structured: {
//     analysisType: "t_test" | "anova" | "pearson" | "chi_square" | "reliability",
//     variables:   { <named labels per test> },
//     n:           <int>,
//     statistic:   { name, value, df? },
//     pValue:      <float>,
//     effectSize:  { name, value },
//     interpretationFlags: { significant, smallSample, weakEffect, ... }
//   },
//   ruleBasedSummary: <string>          // the APA-style sentence
// }
//
// Returns: {
//   ok: true,
//   rewrite: <string>,                  // 2-3 sentence plain-language explanation
//   cached: <bool>,
//   model: <string>
// }
//
// On validator rejection, falls back gracefully:
// {
//   ok: false,
//   error: 'validator_rejected',
//   reason: <string>,                   // human-readable
//   rule_based_fallback: <string>       // the rule-based summary, ready to show
// }
//
// Phase 178s. Hybrid interpretation engine: statistics write the finding,
// AI improves the explanation. The five-check validator enforces:
//   1. numeric_diff       - every number in AI output must match structured
//   2. variable_invented  - no variable names that aren't in structured.variables
//   3. significance_dir   - cannot say "significant" unless p < .05
//   4. effect_overstated  - cannot say "large/strong/substantial" if below medium
//   5. causal_language    - cannot say "causes/leads to/results in/produces"
//
// On any fail, the rule-based summary stays; the AI output is logged to
// mm_rewrite_audit for prompt iteration later.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

check_rate_limit('ai_rewrite_finding:user:' . $uid, 60, 3600);

$body = read_json_body();
$projectId  = (int)($body['project_id'] ?? 0);
$structured = $body['structured'] ?? null;
$ruleSummary = clean_string((string)($body['ruleBasedSummary'] ?? ''), 4000);

if ($projectId <= 0)        fail('bad_input', 'Missing project_id.');
if (!is_array($structured)) fail('bad_input', 'Missing structured result.');
if ($ruleSummary === '')    fail('bad_input', 'Missing ruleBasedSummary.');

mm_require_project($pdo, $uid, $projectId);

$analysisType = (string)($structured['analysisType'] ?? '');
$allowedTypes = ['t_test', 'anova', 'pearson', 'chi_square', 'reliability'];
if (!in_array($analysisType, $allowedTypes, true)) {
    fail('bad_input', 'Unsupported analysisType.');
}

// ----- Cache lookup -----
$hashInput = json_encode([
    'type'       => $analysisType,
    'variables'  => $structured['variables']  ?? null,
    'n'          => $structured['n']          ?? null,
    'statistic'  => $structured['statistic']  ?? null,
    'pValue'     => $structured['pValue']     ?? null,
    'effectSize' => $structured['effectSize'] ?? null,
    'rule'       => $ruleSummary,
], JSON_UNESCAPED_UNICODE);
$hash = hash('sha256', (string)$hashInput);

try {
    $stmt = $pdo->prepare('SELECT rewrite, model FROM mm_rewrite_cache WHERE user_id = :u AND project_id = :p AND hash = :h LIMIT 1');
    $stmt->execute([':u' => $uid, ':p' => $projectId, ':h' => $hash]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached && !empty($cached['rewrite'])) {
        json_out(['ok' => true, 'rewrite' => (string)$cached['rewrite'], 'cached' => true, 'model' => (string)$cached['model']]);
    }
} catch (Throwable $e) {
    // Cache miss path; either table missing or query failed. Log and continue.
    error_log('rewrite-finding: cache lookup failed: ' . $e->getMessage());
}

// ----- Build the prompt and call Haiku -----
$model = 'claude-haiku-4-5-20251001';

$systemPrompt = "You are rewriting a statistical finding for a dissertation researcher's audience. "
              . "You will receive a structured result object and a rule-based summary already produced by ReliCheck. "
              . "Rewrite the rule-based summary in 2 to 3 sentences that explain what the finding means in plain English "
              . "for a non-statistician reader. "
              . "\n\nHARD CONSTRAINTS (must follow):\n"
              . "- Do not change any number. The numbers in your output must match the structured result exactly.\n"
              . "- Do not introduce variables that are not in structured.variables.\n"
              . "- Do not say a finding is \"significant\" unless pValue < .05.\n"
              . "- Do not call an effect \"large\", \"strong\", \"substantial\", or \"big\" if the effect size is below "
              . "the medium band for its metric.\n"
              . "- Do not use causal language (\"causes\", \"caused\", \"leads to\", \"produces\", \"results in\"). "
              . "Use associative language (\"is associated with\", \"differs across\", \"correlates with\") instead.\n"
              . "- Do not add warnings or caveats. Those live in a separate block of the user interface.\n"
              . "\nReturn only the rewritten text. No preamble, no headings, no markdown.";

$userMessage = "STRUCTURED RESULT (JSON):\n"
             . json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
             . "\n\nRULE-BASED SUMMARY:\n"
             . $ruleSummary
             . "\n\nWrite the plain-language rewrite now.";

$aiText = '';
try {
    $r = ai_complete($systemPrompt, [['role' => 'user', 'content' => $userMessage]], 400, $model);
    $aiText = trim((string)($r['text'] ?? ''));
} catch (Throwable $e) {
    // ai_complete already produced a fail() response with a 502 / 503;
    // this catch is defensive in case anything else throws.
    fail('ai_unreachable', 'AI service unavailable: ' . $e->getMessage(), 502);
}

if ($aiText === '') {
    fail('ai_empty', 'AI service returned an empty rewrite.', 502);
}

// ----- Validator -----
$rejection = rewrite_validate($aiText, $structured);

if ($rejection !== null) {
    // Log the rejection and fall back to the rule-based summary.
    try {
        $audit = $pdo->prepare(
            'INSERT INTO mm_rewrite_audit
             (user_id, project_id, analysis_type, structured_json, rule_summary, ai_output, reject_reason, reject_detail, model)
             VALUES (:u, :p, :a, :s, :r, :o, :rr, :rd, :m)'
        );
        $audit->execute([
            ':u'  => $uid,
            ':p'  => $projectId,
            ':a'  => $analysisType,
            ':s'  => json_encode($structured, JSON_UNESCAPED_UNICODE),
            ':r'  => $ruleSummary,
            ':o'  => $aiText,
            ':rr' => $rejection['reason'],
            ':rd' => $rejection['detail'],
            ':m'  => $model,
        ]);
    } catch (Throwable $e) {
        error_log('rewrite-finding: audit insert failed: ' . $e->getMessage());
    }
    json_out([
        'ok'                  => false,
        'error'               => 'validator_rejected',
        'reason'              => $rejection['reason'],
        'detail'              => $rejection['detail'],
        'rule_based_fallback' => $ruleSummary,
    ]);
}

// ----- Cache the passing rewrite -----
try {
    $ins = $pdo->prepare(
        'INSERT INTO mm_rewrite_cache (user_id, project_id, hash, rewrite, model)
         VALUES (:u, :p, :h, :rw, :m)
         ON DUPLICATE KEY UPDATE rewrite = VALUES(rewrite), model = VALUES(model), created_at = CURRENT_TIMESTAMP'
    );
    $ins->execute([':u' => $uid, ':p' => $projectId, ':h' => $hash, ':rw' => $aiText, ':m' => $model]);
} catch (Throwable $e) {
    error_log('rewrite-finding: cache write failed: ' . $e->getMessage());
}

json_out(['ok' => true, 'rewrite' => $aiText, 'cached' => false, 'model' => $model]);


// ============================================================
// Validator
// ============================================================

/**
 * Returns null if the rewrite passes all checks. Returns
 * ['reason' => string, 'detail' => string] if any check fails.
 */
function rewrite_validate(string $aiText, array $structured): ?array
{
    $lower = mb_strtolower($aiText);

    // ----- Check 1: significance language must match direction of pValue -----
    $p = isset($structured['pValue']) ? (float)$structured['pValue'] : null;
    $significantWords = ['significant', 'significantly', 'significance'];
    $aiSaysSignificant = false;
    $aiSaysNotSignificant = false;
    foreach ($significantWords as $w) {
        if (mb_strpos($lower, $w) !== false) {
            // Check if there is a "not" or "non-" nearby (within 25 chars before).
            $pos = mb_strpos($lower, $w);
            $window = mb_substr($lower, max(0, $pos - 25), 25);
            if (preg_match('/\b(not|no |non-?|insignificant|not statistically)\b/', $window)) {
                $aiSaysNotSignificant = true;
            } else {
                $aiSaysSignificant = true;
            }
        }
    }
    if ($p !== null) {
        if ($p >= 0.05 && $aiSaysSignificant && !$aiSaysNotSignificant) {
            return ['reason' => 'significance_overclaim', 'detail' => 'AI claimed significance but pValue is ' . $p . '.'];
        }
    }

    // ----- Check 2: effect-size language must not overstate -----
    // If effectSize.value is below the medium band, the AI cannot say large/strong/substantial/big.
    $effectBands = [
        'cohens_d'    => ['weak' => 0.20, 'medium' => 0.50],
        'eta_squared' => ['weak' => 0.01, 'medium' => 0.06],
        'r_squared'   => ['weak' => 0.01, 'medium' => 0.09],
        'cramers_v'   => ['weak' => 0.10, 'medium' => 0.30],
    ];
    $eff = $structured['effectSize'] ?? null;
    if (is_array($eff) && isset($eff['name']) && isset($eff['value'])) {
        $effKey = strtolower(preg_replace('/[^a-z]+/i', '_', (string)$eff['name']));
        if (isset($effectBands[$effKey])) {
            $absVal = abs((float)$eff['value']);
            if ($absVal < $effectBands[$effKey]['medium']) {
                $bigWords = ['large', 'strong', 'substantial', 'big', 'sizable', 'sizeable'];
                foreach ($bigWords as $w) {
                    if (preg_match('/\b' . preg_quote($w, '/') . '\b/i', $aiText)) {
                        return ['reason' => 'effect_overstated', 'detail' => "AI used '" . $w . "' but effect size is below the medium band."];
                    }
                }
            }
        }
    }

    // ----- Check 3: no causal language -----
    $causalPatterns = [
        '/\bcauses\b/i', '/\bcaused\b/i', '/\bcausing\b/i', '/\bcausation\b/i',
        '/\bleads to\b/i', '/\bleading to\b/i', '/\bled to\b/i',
        '/\bproduces\b/i', '/\bproduced\b/i', '/\bproducing\b/i',
        '/\bresults in\b/i', '/\bresulted in\b/i',
        '/\bbecause of\b/i'
    ];
    foreach ($causalPatterns as $pat) {
        if (preg_match($pat, $aiText, $m)) {
            return ['reason' => 'causal_language', 'detail' => "AI used causal language: '" . $m[0] . "'"];
        }
    }

    // ----- Check 4: numeric diff -----
    // Every number that appears in the AI output as a decimal-looking token
    // should be present in the structured result's number set (or be a small
    // integer such as a year, an n, df, or a verbatim copy of the structured
    // result's numbers). We extract numbers from the AI output and check
    // against the structured set.
    $structNums = rewrite_collect_numbers($structured);
    preg_match_all('/-?\d+(?:\.\d+)?/', $aiText, $aiNums);
    if (!empty($aiNums[0])) {
        foreach ($aiNums[0] as $tok) {
            $n = (float)$tok;
            // Tolerate small integers (1-12) that often appear as counts in prose.
            if ($n >= 1 && $n <= 12 && floor($n) === $n) continue;
            // Tolerate 0 and 1 (very common as boundary references).
            if ($n === 0.0 || $n === 1.0) continue;
            $matched = false;
            foreach ($structNums as $sn) {
                if (abs($sn - $n) < 0.011) { $matched = true; break; }
                // Also tolerate the AI rounding to 2 or 3 decimals.
                if (round($sn, 2) === round($n, 2)) { $matched = true; break; }
                if (round($sn, 3) === round($n, 3)) { $matched = true; break; }
                // Tolerate p-value as percentage (5% for .05 etc) -- rare but possible.
                if (abs(($sn * 100) - $n) < 0.011) { $matched = true; break; }
            }
            if (!$matched) {
                return ['reason' => 'numeric_drift', 'detail' => "AI output contains number '" . $tok . "' not present in structured result."];
            }
        }
    }

    // ----- Check 5: variable name presence -----
    // The structured variables object names the variables in play. If the AI
    // output mentions a content-word that looks like a variable label (Title
    // Case or snake_case 4+ chars) but isn't in the variables list, flag it.
    // We do not block bare common nouns (the rule-based summary often uses
    // "outcome", "group", "variable").
    $vars = isset($structured['variables']) && is_array($structured['variables']) ? array_values($structured['variables']) : [];
    $allowedVarTokens = [];
    foreach ($vars as $v) {
        if (!is_string($v)) continue;
        // Split each variable label into tokens; allow all of them.
        foreach (preg_split('/[\s_\-\/]+/', strtolower((string)$v)) as $tok) {
            if ($tok !== '') $allowedVarTokens[$tok] = true;
        }
    }
    // Allowed common words a rewrite uses to refer to variables generically.
    $commonAllow = ['outcome','group','variable','variables','score','scores','rating','ratings','response','responses',
                    'levels','level','mean','means','difference','differences','relationship','association',
                    'category','categories','item','items','likert','condition','conditions','factor','factors',
                    'test','sample','samples'];
    foreach ($commonAllow as $w) $allowedVarTokens[$w] = true;

    // Scan AI output for capitalized identifiers that look like variable names
    // (e.g., L1_OrgPromotesEquity, Role, Gender). If found, require their
    // tokens to be in $allowedVarTokens.
    preg_match_all('/[A-Z][A-Za-z0-9]{2,}(?:_[A-Za-z0-9]+)*/', $aiText, $caps);
    if (!empty($caps[0])) {
        foreach ($caps[0] as $cap) {
            // Allow capitalized words that are clearly English starting words.
            $low = strtolower($cap);
            if (in_array($low, ['the','a','an','this','that','these','those','if','and','or','but','for','with','from','to','in','at','on','of','as','is','was','were','are','be','been','being','it','they','them','their','our','we','i','you','your','his','her','its','one','two','three'])) continue;
            // If it appears in allowed tokens (broken down), pass.
            $caps2 = preg_split('/[\s_\-]+/', $low);
            $allPresent = true;
            foreach ($caps2 as $tok) {
                if ($tok === '') continue;
                if (!isset($allowedVarTokens[$tok])) { $allPresent = false; break; }
            }
            if (!$allPresent) {
                // One more pass: allow words that appear directly inside any variable label string.
                $varBlob = strtolower(implode(' ', $vars));
                if (mb_strpos($varBlob, $low) !== false) continue;
                return ['reason' => 'variable_invented', 'detail' => "AI introduced an unknown identifier: '" . $cap . "'"];
            }
        }
    }

    return null;
}

/**
 * Pulls every number out of the structured result into a flat array for
 * comparison against numbers in the AI output.
 */
function rewrite_collect_numbers(array $structured): array
{
    $out = [];
    $push = function ($v) use (&$out) {
        if (is_numeric($v)) $out[] = (float)$v;
    };
    if (isset($structured['n'])) $push($structured['n']);
    if (isset($structured['pValue'])) $push($structured['pValue']);
    $stat = $structured['statistic'] ?? null;
    if (is_array($stat)) {
        if (isset($stat['value'])) $push($stat['value']);
        if (isset($stat['df'])) {
            // df may be a number or a "df1,df2" string. Push both halves.
            $dfRaw = $stat['df'];
            if (is_numeric($dfRaw)) $push($dfRaw);
            elseif (is_string($dfRaw)) {
                foreach (preg_split('/[\s,;]+/', $dfRaw) as $part) {
                    if (is_numeric($part)) $push((float)$part);
                }
            }
        }
    }
    $eff = $structured['effectSize'] ?? null;
    if (is_array($eff) && isset($eff['value'])) $push($eff['value']);
    return $out;
}
