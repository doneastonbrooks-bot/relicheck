-- Phase 168: cron heartbeat table.
-- Each cron job writes its name + timestamp + summary on every run so we can
-- tell when a job is silently dead. One row per job (job_name is the PK).
-- The cron helper at api/_cron.php upserts on every run.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS cron_runs (
  job_name           VARCHAR(80)     NOT NULL PRIMARY KEY,
  last_started_at    DATETIME        NULL,
  last_finished_at   DATETIME        NULL,
  last_status        VARCHAR(20)     NOT NULL DEFAULT 'unknown',  -- ok / error / running / unknown
  last_duration_ms   INT UNSIGNED    NULL,
  last_summary_json  TEXT            NULL,
  last_error         VARCHAR(500)    NULL,
  run_count          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_count        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE cron_runs;
SELECT COUNT(*) AS cron_runs_total FROM cron_runs;
