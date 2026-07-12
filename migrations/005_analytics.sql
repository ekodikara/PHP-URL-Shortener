-- 005 — link analytics: device + unique-visitor hash on access_log, plus a
-- composite (code, ts) index for per-link time-range aggregation.
ALTER TABLE `access_log`
  ADD COLUMN `device`       VARCHAR(10) NOT NULL DEFAULT '' AFTER `platform`,
  ADD COLUMN `visitor_hash` CHAR(64)    NOT NULL DEFAULT '' AFTER `user_agent`,
  ADD KEY `code_ts` (`code`, `ts`);
