-- 001 — data-integrity indexes/constraints missing from the initial schema.
-- Forward-only; applied by scripts/migrate.php and tracked in schema_migrations.

-- urls.domain_id had no index or FK: deleting a domain orphaned its links and
-- the redirect isolation join was unindexed. Index it and, on domain delete,
-- null the link's domain_id (it then simply resolves on the main host instead
-- of dangling). ON DELETE SET NULL matches the app's tenancy model.
ALTER TABLE `urls` ADD KEY `domain_id` (`domain_id`);
ALTER TABLE `urls` ADD CONSTRAINT `fk_urls_domain` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE SET NULL;

-- stripe_customer_id was a non-unique KEY, so a mis-fired downgrade could match
-- multiple accounts. One Stripe customer maps to exactly one user here; make it
-- UNIQUE (nullable → multiple NULLs still allowed for non-paying users).
ALTER TABLE `users` DROP KEY `stripe_customer`;
ALTER TABLE `users` ADD UNIQUE KEY `stripe_customer` (`stripe_customer_id`);

-- The IP-suspicion query filters security_log by ip AND ts window; add the
-- composite index it needs (the single-column ip/ts keys can't serve it well).
ALTER TABLE `security_log` ADD KEY `ip_ts` (`ip`, `ts`);
