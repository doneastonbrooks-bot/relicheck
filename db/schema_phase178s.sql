USE dbs15641829;

-- Phase 178s: hybrid interpretation engine, Phase 2 (AI rewrite layer).
-- Two new tables:
--   1. mm_rewrite_cache stores AI rewrites keyed by a content hash so the
--      same structured result never gets rewritten twice. Scoped per project
--      so deleting a project clears its cached prose.
--   2. mm_rewrite_audit logs every validator rejection so we can iterate on
--      the prompt over time without losing the failure data.

CREATE TABLE IF NOT EXISTS mm_rewrite_cache (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    project_id  INT UNSIGNED NOT NULL,
    hash        CHAR(64) NOT NULL,
    rewrite     MEDIUMTEXT  NOT NULL,
    model       VARCHAR(80) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_project_hash (user_id, project_id, hash),
    KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_rewrite_audit (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    project_id      INT UNSIGNED NOT NULL,
    analysis_type   VARCHAR(40) NOT NULL,
    structured_json MEDIUMTEXT  NOT NULL,
    rule_summary    MEDIUMTEXT  NOT NULL,
    ai_output       MEDIUMTEXT  NOT NULL,
    reject_reason   VARCHAR(120) NOT NULL,
    reject_detail   TEXT,
    model           VARCHAR(80) NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created (created_at),
    KEY idx_project (project_id),
    KEY idx_reason  (reject_reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries (run after the CREATE statements above):
-- SHOW TABLES LIKE 'mm_rewrite_%';
-- DESCRIBE mm_rewrite_cache;
-- DESCRIBE mm_rewrite_audit;

-- Rollback (if needed):
-- DROP TABLE IF EXISTS mm_rewrite_audit;
-- DROP TABLE IF EXISTS mm_rewrite_cache;
