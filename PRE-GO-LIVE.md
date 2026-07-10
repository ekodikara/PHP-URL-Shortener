# Snip — Pre-Go-Live Enhancement Checklist

Source: software-architect panel review (2026-07-10). Findings that survived
adversarial verification are tagged ✅; survey-only (verification cut off by the
org spend limit) are tagged ⚠️. This file tracks the fix work.

Status legend: `[ ]` todo · `[~]` partial (env-limited) · `[x]` done

---

## Tier 1 — launch blockers (money / data / secrets)

- [x] **T1.1 ⚠️ Billing: webhook records event "processed" before handling it**
  FIXED `stripe-webhook.php` — handling wrapped in try/catch; on failure the
  `stripe_events` row is deleted and a 500 returned so Stripe retries.
- [x] **T1.2 ⚠️ Billing: `subscription.deleted` downgrades by customer id only**
  FIXED `inc/stripe.php` — `downgrade_by_customer()` now matches the deleted
  `stripe_subscription_id`; a late delete for a superseded sub is a no-op.
- [x] **T1.3 ⚠️ Billing: 100%-off promo never activates** — FIXED: webhook now
  accepts `payment_status` `paid` OR `no_payment_required`.
- [x] **T1.4 ⚠️ Billing: webhook silent-200 when secret unset** — FIXED:
  `error_log` a loud STRIPE MISCONFIG warning.
- [x] **T1.5 ⚠️ No schema-migration system** — DONE: `scripts/migrate.php` +
  `migrations/` (forward-only, tracked in `schema_migrations`, idempotent).
  Verified: applied 001 to the live DB, re-run is a no-op.
- [x] **T1.6 ⚠️ Billing demo path (`upgrade.php`)** — VERIFIED ALREADY RESOLVED:
  renders pricing only; plan changes go through Stripe Checkout.
- [x] **T1.7 ✅/⚠️ Secrets/creds** — DONE: committed `.env.example` (+ `.gitignore`
  negation); prod compose already requires strong secrets via `${VAR:?}`.

## Tier 2 — high (operate-in-the-dark / correctness)

- [x] **T2.1 ⚠️ No monitoring / health / alerting** — DONE: `/health` endpoint
  (`health.php`) returns JSON + 200/503 on a DB check for uptime monitors.
  Verified live. (Error-tracking service is external; hook documented.)
- [x] **T2.2 ⚠️ Backups: no tested restore/encryption/alerting** — DONE:
  `backup.sh` gains an ERR trap → `BACKUP_ALERT_URL` webhook, empty-dump guard,
  optional `BACKUP_GPG_RECIPIENT` client-side encryption; added `restore.sh`
  (latest-or-named, decrypts, confirms before overwrite).
- [x] **T2.3 ⚠️ No automated tests** — DONE: PHPUnit suite (`tests/`, `phpunit.xml`,
  `scripts/test.sh`) covering pricing math, money formatting, slug validation,
  code generation. **Verified green: 12 tests, 424 assertions.** (Redirect/auth
  integration tests are a follow-up.)
- [x] **T2.4 ⚠️ No global exception boundary** — DONE: `inc/bootstrap.php` sets
  exception + shutdown handlers → self-contained themed 500 (JSON for XHR),
  details to `error_log`. Verified normal pages unaffected.
- [x] **T2.5 ⚠️ Non-reproducible builds** — DONE: committed `composer.lock`
  (un-ignored), pinned composer image to `composer:2.8`, Dockerfile now
  requires the lock.
- [x] **T2.6 ⚠️ Redirect hot path** — DONE: 302 + `Cache-Control: no-store`
  (clicks/visit-cap keep counting); `access_log` write wrapped non-fatally;
  persistent PDO (`config.php`); session skipped on the redirect path
  (`SNIP_SKIP_SESSION`). Verified: `/my-launch` → 302 + no-store.
- [~] **T2.7 ✅ Email verification + password reset absent** — IN PROGRESS
  (next): password-reset flow + pluggable mailer + verification scaffolding.

## Tier 3 — medium (hardening, verified where noted)

- [ ] **T3.1 ✅ Unbounded log growth + indefinite visitor-IP retention** (GDPR) —
  add a prune script + retention window; composite index for the suspicion
  query.
- [ ] **T3.2 ✅ Unbounded container logs + no CPU/mem limits** — add `logging:`
  caps and resource limits in `docker-compose.prod.yml`.
- [ ] **T3.3 ✅ No CSP for scripts/styles** (only `frame-ancestors`) — add a
  scoped Content-Security-Policy allowing the known CDNs.
- [ ] **T3.4 ⚠️ `urls.domain_id` no FK/index; `stripe_customer_id` non-unique** —
  fix in migration 001 (index + `ON DELETE SET NULL`; unique nullable customer).
- [ ] **T3.5 ⚠️ Dashboard loads ALL links, one QR per row** — add pagination.

## Tier 4 — low (verified)

- [ ] **T4.1 ✅ CSRF token in GET query strings** (logout, billing-portal) —
  convert to POST forms.
- [ ] **T4.2 ✅ Rate limiter fails open on DB error** — log the failure (keep
  fail-open so a DB blip can't lock out the whole site).
- [ ] **T4.3 ✅ HSTS missing on custom-domain vhost** — add the header to the
  on-demand `https://` block in `Caddyfile`.
- [ ] **T4.4 ✅ `tls-check` rate limit keyed on Caddy IP** — key on the host being
  checked instead.
- [ ] **T4.5 ✅ No `.env.example`** — covered by T1.7.
- [ ] **T4.6 ✅ MySQL healthcheck passes root pw on the command line** — use
  `MYSQL_PWD` env so it's not in argv.
- [ ] **T4.7 (bonus) Dead legacy artifacts** — remove `README`, `index.html`,
  `shortenedurls.sql`.

## Not audited (spend limit) — follow-up pass
- Multi-tenancy / SSO: OIDC/SAML signature + replay validation, domain-verify
  robustness, host-header injection.
- Privacy / compliance: retention policy, ToS/AUP + link-safety (phishing/
  malware), account deletion / data export.
