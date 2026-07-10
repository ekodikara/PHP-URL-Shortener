-- 003 — shared session store (enables SESSION_DRIVER=db / horizontal scaling).
-- data is a BLOB: PHP session payloads are arbitrary bytes, not guaranteed UTF-8.
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`      VARCHAR(128) NOT NULL,
  `data`    MEDIUMBLOB NOT NULL,
  `expires` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
