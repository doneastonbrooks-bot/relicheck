<?php
// Phase 181 helper: ensure the tests table has the TIA Studio Phase 1
// metadata columns. Called at the top of every /api/tests/ endpoint so
// the migration runs on the first request after deploy without needing
// a manual SQL step on Ionos.
//
// Each ALTER is wrapped in its own try/catch because some MySQL versions
// reject `ADD COLUMN IF NOT EXISTS`. On those versions the ADD throws if
// the column already exists, which is the result we want anyway (silent
// pass-through after the first successful run).

declare(strict_types=1);

function tia_ensure_tests_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        ['assessment_purpose',        "VARCHAR(40) NULL AFTER pass_threshold"],
        ['decision_type',             "VARCHAR(40) NULL AFTER assessment_purpose"],
        ['intended_cognitive_demand', "VARCHAR(40) NULL AFTER decision_type"],
        ['includes_open_ended',       "TINYINT(1) NOT NULL DEFAULT 0 AFTER intended_cognitive_demand"],
        ['includes_rubric',           "TINYINT(1) NOT NULL DEFAULT 0 AFTER includes_open_ended"],
        ['includes_group_analysis',   "TINYINT(1) NOT NULL DEFAULT 0 AFTER includes_rubric"],
        ['status',                    "VARCHAR(30) NOT NULL DEFAULT 'setup' AFTER includes_group_analysis"],
    ];
    foreach ($columns as [$col, $spec]) {
        try {
            $pdo->exec("ALTER TABLE tests ADD COLUMN $col $spec");
        } catch (Throwable $_) {
            // Column probably already exists. Safe to ignore.
        }
    }
}
