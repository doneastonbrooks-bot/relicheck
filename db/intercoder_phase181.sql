-- ============================================================
-- Phase 181 — Intercoder Check schema additions
-- Run in phpMyAdmin against dbs15641829.
-- All blocks are idempotent: re-running is safe.
-- ============================================================
USE dbs15641829;

-- 1. Add coder_id to mm_coded_responses so every coding row knows
--    who made it. NULL allowed during backfill; we set NOT NULL after.
ALTER TABLE mm_coded_responses
  ADD COLUMN coder_id BIGINT UNSIGNED NULL AFTER category_id;

-- 2. Backfill every existing coding row with the project owner's user_id.
--    Without this, all historical codings would be orphaned and the
--    intercoder check would treat them as anonymous.
UPDATE mm_coded_responses cr
  JOIN mm_projects p ON p.id = cr.project_id
  SET   cr.coder_id = p.user_id
  WHERE cr.coder_id IS NULL;

-- 3. Lock coder_id NOT NULL now that the backfill is complete.
ALTER TABLE mm_coded_responses
  MODIFY COLUMN coder_id BIGINT UNSIGNED NOT NULL;

-- 4. Drop the old unique-per-(response,category) constraint and replace
--    it with one that also keys on coder. Two coders coding the same
--    response under the same category is the WHOLE point of this feature
--    and the old constraint would reject the second insert.
ALTER TABLE mm_coded_responses
  DROP INDEX uq_mm_coded_unique;
ALTER TABLE mm_coded_responses
  ADD UNIQUE KEY uq_mm_coded_unique_by_coder (response_id, category_id, coder_id);

-- 5. Index used by intercoder agreement queries.
ALTER TABLE mm_coded_responses
  ADD KEY idx_mm_coded_project_coder (project_id, coder_id, response_id);

-- 6. New table for shareable-link invitations.
CREATE TABLE IF NOT EXISTS mm_coder_invites (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id        BIGINT UNSIGNED NOT NULL,
  invited_by        BIGINT UNSIGNED NOT NULL,
  email             VARCHAR(255)    NULL
    COMMENT 'Optional. Not used for delivery (shareable-link model); recorded for the project owner to remember who they invited.',
  token             VARCHAR(64)     NOT NULL,
  accepted_at       DATETIME        NULL,
  accepted_user_id  BIGINT UNSIGNED NULL,
  revoked_at        DATETIME        NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mm_coder_invite_token (token),
  KEY idx_mm_coder_invite_project (project_id),
  CONSTRAINT fk_mm_coder_invite_project
    FOREIGN KEY (project_id)  REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. New table tracking who has been granted coder access to which project.
--    Separate from mm_coder_invites because one accepted invite creates one
--    membership; further membership changes (revoke, role bump) live here.
CREATE TABLE IF NOT EXISTS mm_project_coders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id        BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  role              ENUM('owner','coder') NOT NULL DEFAULT 'coder',
  invited_via_id    BIGINT UNSIGNED NULL
    COMMENT 'mm_coder_invites.id that produced this membership; NULL for owner.',
  added_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at        DATETIME        NULL,
  UNIQUE KEY uq_mm_project_coder (project_id, user_id),
  KEY idx_mm_project_coder_user (user_id),
  CONSTRAINT fk_mm_project_coder_project
    FOREIGN KEY (project_id)  REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Seed mm_project_coders with the existing project owners. Every project
--    counts its owner as the implicit first coder. Idempotent insert.
INSERT IGNORE INTO mm_project_coders (project_id, user_id, role, added_at)
  SELECT id, user_id, 'owner', NOW()
  FROM mm_projects;

-- ============================================================
-- Verification queries (run after the migration to confirm)
-- ============================================================
-- a. Every coded response should now have a coder_id matching its
--    project's owner (sanity-check the backfill):
--    SELECT COUNT(*) AS orphans
--      FROM mm_coded_responses
--      WHERE coder_id IS NULL OR coder_id = 0;
--    Expected: 0
--
-- b. Every project should have exactly one owner row in mm_project_coders:
--    SELECT COUNT(*) AS bad_owner_count FROM (
--      SELECT project_id, SUM(CASE WHEN role='owner' THEN 1 ELSE 0 END) AS owners
--      FROM mm_project_coders GROUP BY project_id
--    ) t WHERE owners != 1;
--    Expected: 0
--
-- c. mm_coder_invites should be empty until the first invite is created:
--    SELECT COUNT(*) FROM mm_coder_invites;
--    Expected: 0
-- ============================================================
