<?php
// POST /api/ai/analyze-upload.php
// Body: {
//   "filename": "string",
//   "headers":  ["col1","col2",...],
//   "samples":  [["v1","v2",...], ["v1","v2",...], ...],   // up to ~30 rows
//   "current": { "likertPoints": 5, "likertLow": "...", "likertHigh": "..." }
// }
//
// Returns a structured analysis the dataset upload wizard can apply to its
// column-mapping step:
//
// {
//   ok: true,
//   model: "...",
//   suggestion: {
//     dataset_title:   "Hybrid Work Engagement",
//     likert_points:   5,
//     likert_low:      "Strongly disagree",
//     likert_high:     "Strongly agree",
//     notes:           "Detected 5-point Likert across 9 columns. Two items appear reverse-worded.",
//     columns: [
//       { index: 0, name: "ID",        type: "ignore", reverse: false, construct: "" },
//       { index: 1, name: "Q1",        type: "likert", reverse: false, construct: "Engagement" },
//       { index: 5, name: "Q5_rev",    type: "likert", reverse: true,  construct: "Engagement" },
//       { index: 9, name: "Comments",  type: "open",   reverse: false, construct: "" },
//       ...
//     ]
//   }
// }
//
// The model never sees respondent identifiers in a privileged way; we send
// only the header strings and a small sample of rows. The wizard runs entirely
// client-side until "Save" is clicked, so this endpoint only suggests; no
// dataset is created here.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ai.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 15 calls per hour per user. Upload flow benefits from a few retries.
check_rate_limit('ai_analyze_upload:user:' . (int)$user['id'], 15, 3600);

$body = read_json_body();
$headersIn = $body['headers'] ?? null;
$samplesIn = $body['samples'] ?? null;
if (!is_array($headersIn) || !is_array($samplesIn)) {
    fail('bad_input', 'headers and samples are required.');
}

// Trim incoming. Cap headers and sample size so the prompt stays bounded.
$headers = [];
foreach ($headersIn as $h) {
    $headers[] = clean_string((string)$h, 160);
    if (count($headers) >= 200) break;
}
if (count($headers) === 0) fail('bad_input', 'No columns to analyze.');

$samples = [];
foreach ($samplesIn as $row) {
    if (!is_array($row)) continue;
    $cleanRow = [];
    for ($i = 0; $i < count($headers); $i++) {
        $v = $row[$i] ?? '';
        $cleanRow[] = clean_string((string)$v, 200);
    }
    $samples[] = $cleanRow;
    if (count($samples) >= 30) break;
}

$filename = clean_string((string)($body['filename'] ?? ''), 160);

$cur = is_array($body['current'] ?? null) ? $body['current'] : [];
$curPoints = (int)($cur['likertPoints'] ?? 5);
if ($curPoints < 2 || $curPoints > 11) $curPoints = 5;
$curLow  = clean_string((string)($cur['likertLow']  ?? 'Strongly disagree'), 80);
$curHigh = clean_string((string)($cur['likertHigh'] ?? 'Strongly agree'),    80);

// Compute light numeric summaries per column so the model has signal beyond
// raw strings. Counts of blank, numeric, integer-in-1..K candidates for likely
// Likert scale points, and a small set of distinct values.
$colSummaries = [];
foreach ($headers as $ci => $name) {
    $blank = 0; $numeric = 0; $integers = 0; $minN = null; $maxN = null;
    $distinct = [];
    foreach ($samples as $row) {
        $v = trim((string)($row[$ci] ?? ''));
        if ($v === '') { $blank++; continue; }
        if (!isset($distinct[$v]) && count($distinct) < 8) $distinct[$v] = true;
        if (is_numeric($v)) {
            $n = (float)$v;
            $numeric++;
            if ((float)(int)$n === $n) $integers++;
            if ($minN === null || $n < $minN) $minN = $n;
            if ($maxN === null || $n > $maxN) $maxN = $n;
        }
    }
    $colSummaries[] = [
        'index'    => $ci,
        'name'     => $name,
        'blank'    => $blank,
        'numeric'  => $numeric,
        'integer_count' => $integers,
        'min'      => $minN,
        'max'      => $maxN,
        'distinct_sample' => array_keys($distinct),
    ];
}

$payload = [
    'filename'  => $filename,
    'rows_sampled' => count($samples),
    'likert_current' => [
        'points' => $curPoints,
        'low'    => $curLow,
        'high'   => $curHigh,
    ],
    'columns'   => $colSummaries,
    // Send the first 10 sample rows verbatim too. The summaries cover the
    // signal but a few raw rows help the model judge open-ended vs categorical.
    'sample_rows' => array_slice($samples, 0, 10),
];

$system = <<<SYS
You are a survey-data assistant inside ReliCheck. The user just uploaded a CSV or Excel file and is about to map its columns to question types. Your job is to read the column names and a small sample of values and produce a column-by-column mapping the user can confirm with one click.

For each column, choose one type:
  - "likert"  : a numeric Likert/rating item (e.g., 1..5 or 1..7). The item should be appropriate for reliability and factor analysis.
  - "single"  : a categorical column (gender, role, condition group, yes/no). Useful for filtering and group comparisons.
  - "open"    : free-text answers, comments, identifiers, dates, or anything that should not be treated as Likert or categorical.
  - "ignore"  : columns that should be excluded from analysis (e.g., respondent IDs, timestamps, IP, email, name, internal codes).

Rules:
  - If the column name is "id", "respondent_id", "email", "ip", "timestamp", "started_at", "ended_at", "duration", or similar metadata, set type="ignore".
  - Likert columns must look like small integers within a plausible scale (e.g., 1..5, 1..7, 0..10). Use the numeric summary to decide. Strings like "Strongly disagree" / "Agree" mean the column is text-coded Likert: still mark it "likert" so the wizard handles it.
  - Reverse-scored: set reverse=true ONLY if the column name or wording strongly suggests it (e.g., contains "_r", "_rev", "reverse", or the name reads as a negatively worded item like "I dislike", "I rarely", "I am not", "I avoid"). Default to false.
  - construct: a short, concrete grouping label (1-3 words, Title Case) for items that appear to measure the same construct, e.g., "Engagement", "Burnout", "Belonging", "Job Satisfaction". Leave "" for non-Likert columns.
  - likert_points: choose 5, 7, or 10 most often, based on the integer range you observe across Likert columns. If unsure, keep the user's current setting.
  - likert_low / likert_high: short anchor labels appropriate to the construct family. Defaults are "Strongly disagree" and "Strongly agree" but pick "Never"/"Always" for frequency items, "Not at all true"/"Completely true" for self-report items if those obviously fit. Do not overreach.
  - dataset_title: a clean human title derived from the filename (strip extension, replace underscores with spaces, Title Case). Keep under 60 characters.
  - notes: one short sentence (under 200 chars) summarizing what you detected: scale points, construct count, any concerns.

Output format: respond with a single JSON object only, no prose, no markdown fences:

{
  "dataset_title": "<string>",
  "likert_points": <int>,
  "likert_low":    "<string>",
  "likert_high":   "<string>",
  "notes":         "<string>",
  "columns": [
    { "index": <int>, "type": "likert|single|open|ignore", "reverse": <bool>, "construct": "<string>" },
    ...
  ]
}

The "columns" array MUST include every input column in index order.
SYS;

$userPrompt  = "Uploaded file analysis. The user's column-mapping wizard will apply your suggestions.\n\n";
$userPrompt .= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$userPrompt .= "\n\nReturn the JSON object now.";

$resp = ai_complete($system, [
    ['role' => 'user', 'content' => $userPrompt],
], 2500);

$parsed = ai_extract_json($resp['text']);
if (!$parsed || !is_array($parsed)) {
    fail('ai_parse_failed', 'AI returned a response we could not parse. Try again.', 502);
}

// Validate + sanitize the model's output.
$validTypes = ['likert','single','open','ignore'];

$datasetTitle = clean_string((string)($parsed['dataset_title'] ?? ''), 80);
if ($datasetTitle === '') {
    // Fall back to the filename without extension.
    $datasetTitle = preg_replace('/\.[^.]+$/', '', $filename);
    $datasetTitle = trim(str_replace(['_','-'], ' ', $datasetTitle));
    if ($datasetTitle === '') $datasetTitle = 'Untitled dataset';
}

$lp = (int)($parsed['likert_points'] ?? $curPoints);
if ($lp < 2 || $lp > 11) $lp = $curPoints;
$ll = clean_string((string)($parsed['likert_low']  ?? $curLow),  80);
$lh = clean_string((string)($parsed['likert_high'] ?? $curHigh), 80);
if ($ll === '') $ll = $curLow;
if ($lh === '') $lh = $curHigh;

$notes = clean_string((string)($parsed['notes'] ?? ''), 240);

$colsIn = is_array($parsed['columns'] ?? null) ? $parsed['columns'] : [];
// Build an index -> suggestion map so we can guarantee one entry per input column.
$byIdx = [];
foreach ($colsIn as $c) {
    if (!is_array($c)) continue;
    $idx = isset($c['index']) ? (int)$c['index'] : -1;
    if ($idx < 0 || $idx >= count($headers)) continue;
    $type = strtolower(clean_string((string)($c['type'] ?? ''), 16));
    if (!in_array($type, $validTypes, true)) $type = 'ignore';
    $rev  = !empty($c['reverse']);
    $cons = clean_string((string)($c['construct'] ?? ''), 40);
    // Construct only makes sense for likert; clear it otherwise so the UI is clean.
    if ($type !== 'likert') $cons = '';
    $byIdx[$idx] = ['index' => $idx, 'type' => $type, 'reverse' => $rev, 'construct' => $cons];
}
// Fill in any missing columns with a safe default (open-ended) so the wizard
// has a row for every header.
$columnsOut = [];
foreach ($headers as $i => $name) {
    $columnsOut[] = $byIdx[$i] ?? ['index' => $i, 'type' => 'open', 'reverse' => false, 'construct' => ''];
}

json_out([
    'ok' => true,
    'model' => ai_config()['model'],
    'suggestion' => [
        'dataset_title' => $datasetTitle,
        'likert_points' => $lp,
        'likert_low'    => $ll,
        'likert_high'   => $lh,
        'notes'         => $notes,
        'columns'       => $columnsOut,
    ],
]);
