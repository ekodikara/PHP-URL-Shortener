-- 007 — billing transparency: store the subscription's current-period-end and
-- cancel-at-period-end (synced from Stripe webhooks) so the dashboard can show
-- "Renews on X" / "Access until X" without a live Stripe API call on page load.
ALTER TABLE `users`
  ADD COLUMN `current_period_end`   INT UNSIGNED NULL DEFAULT NULL AFTER `stripe_subscription_id`,
  ADD COLUMN `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0 AFTER `current_period_end`;
