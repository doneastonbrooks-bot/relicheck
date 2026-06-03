-- Qualitative Analysis Studio schema
-- Run once on prod. Idempotent via IF NOT EXISTS / IF NOT EXISTS guards.
-- All FK enforcement is advisory (ENGINE=InnoDB) but not declared to stay
-- compatible with production deployments that may run migrations out of order.

CREATE TABLE IF NOT EXISTS qual_projects (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rc_project_id         BIGINT UNSIGNED NULL,
    user_id               BIGINT UNSIGNED NOT NULL,
    title                 VARCHAR(255) NOT NULL,
    research_question     TEXT NULL,
    purpose               TEXT NULL,
    data_source           VARCHAR(100) NULL,
    participant_description TEXT NULL,
    data_type             VARCHAR(50) NOT NULL DEFAULT 'open_ended_survey',
    analysis_approach     VARCHAR(50) NOT NULL DEFAULT 'thematic',
    researcher_stance_memo TEXT NULL,
    notes                 TEXT NULL,
    status                VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_qp_user       (user_id, status, updated_at),
    KEY idx_qp_rc         (rc_project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One record per imported file, survey source, or transcript
CREATE TABLE IF NOT EXISTS qual_documents (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   BIGINT UNSIGNED NOT NULL,
    dataset_id   BIGINT UNSIGNED NULL,
    title        VARCHAR(255) NOT NULL,
    source_type  VARCHAR(50)  NOT NULL DEFAULT 'survey',
    doc_metadata JSON NULL,
    status       VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_qdoc_proj (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per response / paragraph / text unit
CREATE TABLE IF NOT EXISTS qual_segments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      BIGINT UNSIGNED NOT NULL,
    document_id     BIGINT UNSIGNED NOT NULL,
    participant_id  VARCHAR(100) NULL,
    question_ref    VARCHAR(255) NULL,
    raw_text        TEXT NOT NULL,
    cleaned_text    TEXT NULL,
    seg_order       INT UNSIGNED NOT NULL DEFAULT 0,
    word_count      SMALLINT UNSIGNED NULL,
    metadata_json   JSON NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_qseg_proj (project_id),
    KEY idx_qseg_doc  (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codebook: one row per code
CREATE TABLE IF NOT EXISTS qual_codes (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id          BIGINT UNSIGNED NOT NULL,
    name                VARCHAR(255) NOT NULL,
    definition          TEXT NULL,
    include_when        TEXT NULL,
    exclude_when        TEXT NULL,
    example_quote       TEXT NULL,
    parent_category_id  BIGINT UNSIGNED NULL,
    position            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status              VARCHAR(16) NOT NULL DEFAULT 'draft',
    created_by_type     VARCHAR(16) NOT NULL DEFAULT 'human',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_qcode_proj (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Code applications: codes applied to segments (supports multi-code + dual-coder)
CREATE TABLE IF NOT EXISTS qual_code_applications (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id       BIGINT UNSIGNED NOT NULL,
    segment_id       BIGINT UNSIGNED NOT NULL,
    code_id          BIGINT UNSIGNED NOT NULL,
    coder_id         BIGINT UNSIGNED NOT NULL,
    coder_type       VARCHAR(16) NOT NULL DEFAULT 'human',
    selected_text    TEXT NULL,
    action_type      VARCHAR(16) NOT NULL DEFAULT 'applied',
    confidence_score DECIMAL(4,3) NULL,
    memo             TEXT NULL,
    evidence_json    JSON NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qca_seg_code_coder (segment_id, code_id, coder_id),
    KEY idx_qca_proj  (project_id),
    KEY idx_qca_code  (code_id),
    KEY idx_qca_seg   (segment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytic memos (project, segment, code level)
CREATE TABLE IF NOT EXISTS qual_memos (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id  BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(16) NOT NULL DEFAULT 'project',
    object_id   BIGINT UNSIGNED NULL,
    memo_type   VARCHAR(50) NOT NULL DEFAULT 'analytic',
    title       VARCHAR(255) NULL,
    body        TEXT NOT NULL,
    author_id   BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_qmemo_proj (project_id),
    KEY idx_qmemo_obj  (object_type, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories: groupings of codes into higher-level buckets
CREATE TABLE IF NOT EXISTS qual_categories (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id  BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_qcat_proj (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Themes: interpretive claims built from categories
CREATE TABLE IF NOT EXISTS qual_themes (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id         BIGINT UNSIGNED NOT NULL,
    name               VARCHAR(255) NOT NULL,
    interpretive_claim TEXT NULL,
    notes              TEXT NULL,
    position           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_qthm_proj (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Theme → category join (one theme draws on many categories)
CREATE TABLE IF NOT EXISTS qual_theme_categories (
    theme_id    BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (theme_id, category_id),
    KEY idx_qtc_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exemplar quotes: segments pinned as evidence for a specific theme
CREATE TABLE IF NOT EXISTS qual_theme_quotes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id  BIGINT UNSIGNED NOT NULL,
    theme_id    BIGINT UNSIGNED NOT NULL,
    segment_id  BIGINT UNSIGNED NOT NULL,
    note        TEXT NULL,
    added_by    BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qtq_theme_seg (theme_id, segment_id),
    KEY idx_qtq_proj  (project_id),
    KEY idx_qtq_theme (theme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail: every significant user or AI action
CREATE TABLE IF NOT EXISTS qual_audit_trail (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    action       VARCHAR(100) NOT NULL,
    object_type  VARCHAR(50)  NULL,
    object_id    BIGINT UNSIGNED NULL,
    object_name  VARCHAR(255) NULL,
    prev_value   TEXT NULL,
    new_value    TEXT NULL,
    memo         TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_qat_proj (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
