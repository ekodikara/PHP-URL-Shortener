-- 004 — content filtering: cache IPQS URL-category verdicts + admin domain blocklist.
-- Adds the adult-content layers on top of Safe Browsing (see inc/content_filter.php).

-- `checked` gains a default so category-only inserts (which don't touch the
-- Safe Browsing verdict) don't fail; `category`/`cat_checked` hold the IPQS verdict.
ALTER TABLE `url_reputation`
  MODIFY COLUMN `checked` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `category`    VARCHAR(40) NOT NULL DEFAULT '' AFTER `threat`,
  ADD COLUMN `cat_checked` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `checked`;

CREATE TABLE IF NOT EXISTS `blocked_domains` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain`     VARCHAR(253) NOT NULL,
  `note`       VARCHAR(190) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NULL DEFAULT NULL,
  `created`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
