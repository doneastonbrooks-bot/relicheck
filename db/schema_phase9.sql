-- Phase 9 migration: Stripe billing tables.
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

-- One Stripe customer per user. We never store card details; that lives in
-- Stripe. We just keep the mapping so we can talk to Stripe about this user.
CREATE TABLE IF NOT EXISTS stripe_customers (
  user_id              BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  stripe_customer_id   VARCHAR(120) NOT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stripe_customer (stripe_customer_id),
  CONSTRAINT fk_stripe_customers_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Active subscription per user. We update this on subscription.updated events.
CREATE TABLE IF NOT EXISTS subscriptions (
  user_id                BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  stripe_subscription_id VARCHAR(120) NOT NULL,
  status                 VARCHAR(40)  NOT NULL,    -- active, past_due, canceled, incomplete, etc.
  tier                   VARCHAR(20)  NOT NULL,    -- researcher / professional / business
  cycle                  VARCHAR(10)  NOT NULL,    -- monthly / annual
  current_period_end     DATETIME     NULL,
  cancel_at_period_end   TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subscription (stripe_subscription_id),
  CONSTRAINT fk_subscriptions_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency log: we record every Stripe event id we have already processed,
-- so a duplicate webhook delivery never double-applies the change.
CREATE TABLE IF NOT EXISTS stripe_events (
  event_id        VARCHAR(120) NOT NULL PRIMARY KEY,
  event_type      VARCHAR(80)  NOT NULL,
  received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id         BIGINT UNSIGNED NULL,
  KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
