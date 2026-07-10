-- Snip — database schema

CREATE TABLE IF NOT EXISTS `users` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`                  VARCHAR(190) NOT NULL,
  `password_hash`          VARCHAR(255) NOT NULL,
  `plan`                   ENUM('free','pro','premium','enterprise') NOT NULL DEFAULT 'free',
  `billing_interval`       ENUM('month','year') NULL DEFAULT NULL,
  `stripe_customer_id`     VARCHAR(255) NULL DEFAULT NULL,
  `stripe_subscription_id` VARCHAR(255) NULL DEFAULT NULL,
  `month_visits`           INT UNSIGNED NOT NULL DEFAULT 0,
  `visit_month`            CHAR(7) NULL DEFAULT NULL,
  `is_admin`               TINYINT(1) NOT NULL DEFAULT 0,
  `blocked`                TINYINT(1) NOT NULL DEFAULT 0,
  `blocked_reason`         VARCHAR(255) NULL DEFAULT NULL,
  `created`                INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `stripe_customer` (`stripe_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,            -- sha256 hex of the raw token
  `label`      VARCHAR(80) NOT NULL DEFAULT 'MCP token',
  `scope`      ENUM('read','full') NOT NULL DEFAULT 'full',
  `created`    INT UNSIGNED NOT NULL,
  `last_used`  INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security audit trail (failed logins, blocked bots, rate limits, MCP abuse).
-- Lives on the DB volume, so it survives `docker compose up --build`.
CREATE TABLE IF NOT EXISTS `security_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`         INT UNSIGNED NOT NULL,
  `event`      VARCHAR(40) NOT NULL,
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `user_id`    INT UNSIGNED NULL DEFAULT NULL,
  `detail`     VARCHAR(255) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `event` (`event`),
  KEY `ts` (`ts`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed-window rate-limit counters keyed by "action:identifier".
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `rl_key`       VARCHAR(160) NOT NULL,
  `count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`rl_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cached IP reputation (VPN/proxy) from IPQualityScore.
CREATE TABLE IF NOT EXISTS `ip_reputation` (
  `ip`      VARCHAR(45) NOT NULL,
  `is_vpn`  TINYINT(1) NOT NULL DEFAULT 0,
  `checked` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Enterprise "contact sales" inquiries.
CREATE TABLE IF NOT EXISTS `enterprise_leads` (
  `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`      INT UNSIGNED NOT NULL,
  `name`    VARCHAR(120) NOT NULL DEFAULT '',
  `email`   VARCHAR(190) NOT NULL DEFAULT '',
  `company` VARCHAR(120) NOT NULL DEFAULT '',
  `message` TEXT,
  `ip`      VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom branded domains for Enterprise tenants (multi-tenant host routing).
CREATE TABLE IF NOT EXISTS `domains` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `host`     VARCHAR(255) NOT NULL,
  `user_id`  INT UNSIGNED NOT NULL,
  `verified` TINYINT(1) NOT NULL DEFAULT 0,
  `token`    VARCHAR(64) NOT NULL DEFAULT '',
  `created`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `host` (`host`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_domains_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Access log: who visits short links (and key auth events) — for security review.
CREATE TABLE IF NOT EXISTS `access_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`         INT UNSIGNED NOT NULL,
  `event`      VARCHAR(20) NOT NULL DEFAULT 'redirect',
  `code`       VARCHAR(40) NULL DEFAULT NULL,
  `user_id`    INT UNSIGNED NULL DEFAULT NULL,
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `browser`    VARCHAR(40) NOT NULL DEFAULT '',
  `platform`   VARCHAR(40) NOT NULL DEFAULT '',
  `referer`    VARCHAR(255) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ts` (`ts`),
  KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cached Google Safe Browsing verdicts for destination URLs.
CREATE TABLE IF NOT EXISTS `url_reputation` (
  `url_hash` CHAR(64) NOT NULL,                 -- sha256 hex of the destination URL
  `threat`   VARCHAR(40) NOT NULL DEFAULT '',   -- '' = clean, else e.g. SOCIAL_ENGINEERING
  `checked`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`url_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Abuse reports filed against short links via the public /report form.
CREATE TABLE IF NOT EXISTS `link_reports` (
  `id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`     INT UNSIGNED NOT NULL,
  `code`   VARCHAR(40) NOT NULL,
  `reason` VARCHAR(20) NOT NULL DEFAULT 'other',  -- phishing|malware|spam|other
  `detail` TEXT,
  `email`  VARCHAR(190) NOT NULL DEFAULT '',
  `ip`     VARCHAR(45) NOT NULL DEFAULT '',
  `status` ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `urls` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`   INT UNSIGNED NOT NULL,
  `code`      VARCHAR(40) NOT NULL,
  `long_url`  VARCHAR(2048) NOT NULL,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  `blocked`   TINYINT(1) NOT NULL DEFAULT 0,
  `domain_id` INT UNSIGNED NULL DEFAULT NULL,
  `clicks`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `user_id` (`user_id`),
  KEY `user_created` (`user_id`, `created`),
  CONSTRAINT `fk_urls_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
