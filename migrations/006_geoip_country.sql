-- 006 — analytics geo: ISO country code per click (resolved from the visitor IP
-- via a local MaxMind-format DB; see inc/geoip.php).
ALTER TABLE `access_log`
  ADD COLUMN `country` CHAR(2) NOT NULL DEFAULT '' AFTER `device`;
