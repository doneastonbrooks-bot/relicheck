-- Phase 15 migration: per-account custom domain support.
-- Adds four columns to the users table that let a Business-tier
-- customer point a vanity hostname (e.g. surveys.acme.edu) at their
-- ReliCheck account and serve public /s/<slug> links from it.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN custom_domain VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Vanity hostname owned by the customer, e.g. surveys.acme.edu',
  ADD COLUMN custom_domain_status ENUM('disabled','pending','verified') NOT NULL DEFAULT 'disabled'
    COMMENT 'Verification state. Only verified domains are used for share-URL generation.',
  ADD COLUMN custom_domain_verification_token CHAR(32) NULL DEFAULT NULL
    COMMENT 'Random token the customer must publish in a TXT record to prove ownership.',
  ADD COLUMN custom_domain_verified_at DATETIME NULL DEFAULT NULL
    COMMENT 'When verification last succeeded.';

-- Each domain can belong to at most one account. Allows NULLs (most accounts).
ALTER TABLE users
  ADD UNIQUE KEY uniq_users_custom_domain (custom_domain);
