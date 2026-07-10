-- 002 — password reset + email verification support.

-- Track whether a user has confirmed ownership of their email.
ALTER TABLE `users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0;

-- Single-use, expiring tokens for password reset and email verification.
-- Only the SHA-256 hash of the raw token is stored (the raw token lives only in
-- the emailed link), mirroring how api_tokens are handled.
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `kind`       ENUM('reset','verify') NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires`    INT UNSIGNED NOT NULL,
  `used`       TINYINT(1) NOT NULL DEFAULT 0,
  `created`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_kind` (`user_id`, `kind`),
  CONSTRAINT `fk_authtok_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
