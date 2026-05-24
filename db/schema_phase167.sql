-- Phase 167: 2FA login challenges.
-- After a successful password check, if totp_enabled=1, we issue a short-
-- lived challenge token instead of completing the login. The client sends
-- the token plus a 6-digit TOTP code to complete the sign-in.
--
-- Tokens are single-use, 5-minute TTL.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS auth_2fa_challenges (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64)        NOT NULL,
  expires_at   DATETIME        NOT NULL,
  used_at      DATETIME        NULL,
  ip_hash      CHAR(64)        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_2fa_token (token_hash),
  KEY idx_2fa_user (user_id, used_at),
  CONSTRAINT fk_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE auth_2fa_challenges;
SELECT COUNT(*) AS twofa_challenges_total FROM auth_2fa_challenges;
