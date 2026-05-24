-- Phase 5: Device authorization flow for the MM Studio desktop app.
--
-- Adds one new table: device_authorizations. Stores the pending
-- "verify on the web app" handshakes the desktop initiates before it
-- has a token. Once approved by a signed-in user, we issue a regular
-- api_tokens row (existing table) and consume this row, so the rest
-- of the API never knows whether a token came from the dashboard's
-- "Create API token" button or from the desktop's device flow.
--
-- This is purely additive (Phase 18 rule): no existing endpoint or
-- table is modified. Rollback is a single DROP TABLE.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS device_authorizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_code VARCHAR(16) NOT NULL,
    device_code VARCHAR(64) NOT NULL,
    submission_id VARCHAR(64) NOT NULL,
    client_label VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    approved_user_id INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    consumed_at DATETIME DEFAULT NULL,
    issued_token_id BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_device_code (device_code),
    KEY ix_user_code (user_code),
    KEY ix_expires_at (expires_at),
    KEY ix_approved_user_id (approved_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: list any rows currently in the table. Expected empty
-- on first run.
SELECT COUNT(*) AS row_count FROM device_authorizations;

-- ----------------------------------------------------------------------
-- Rollback (uncomment to revert):
--
-- USE dbs15641829;
-- DROP TABLE IF EXISTS device_authorizations;
-- ----------------------------------------------------------------------
