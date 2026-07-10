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
- [x] **T2.7 ✅ Email verification + password reset absent** — DONE & VERIFIED
  end-to-end: pluggable mailer (`inc/mail.php`; dev writes to `cache/mail.log`),
  single-use expiring tokens (`auth_tokens`, migration 002), `/forgot` (anti-
  enumeration, rate-limited) → `/reset` (peek+consume, sets new hash), `/verify`
  (+ resend), verification email on register, "Forgot password?" on login, and
  a soft dashboard verify banner. Verified in-browser: reset alice's password
  and logged in with it; resent + confirmed verification (banner cleared).
  (Hard-gating on verified state left soft to avoid disrupting existing users.)

## Tier 3 — medium (hardening, verified where noted)

- [x] **T3.1 ✅ Unbounded log growth + indefinite visitor-IP retention** — DONE:
  `scripts/prune.php` (retention `LOG_RETENTION_DAYS`, default 90) prunes
  access/security/stripe logs, dead rate_limits, stale ip_reputation, and
  used/expired auth_tokens; `security_log(ip,ts)` composite index added
  (migration 001); login rate-limit key now hashes the email so it can't be
  overflowed. Verified prune runs clean.
- [x] **T3.2 ✅ Unbounded container logs + no CPU/mem limits** — DONE:
  `docker-compose.prod.yml` — json-file logging (10m×3) + `mem_limit`/`cpus`
  on all three services (db 640m, web 256m, caddy 128m).
- [x] **T3.3 ✅ No CSP for scripts/styles** — DONE: scoped Content-Security-Policy
  in `inc/bootstrap.php` (self + Google Fonts + cdnjs + Turnstile + reCAPTCHA;
  object/base/frame-ancestors locked). Verified live: zero console violations,
  fonts + QR + theme intact.
- [x] **T3.4 ⚠️ `urls.domain_id` FK/index; `stripe_customer_id` unique** — DONE in
  migration 001 (see T1.5).
- [x] **T3.5 ⚠️ Dashboard loads ALL links, one QR per row** — DONE: stats via SQL
  aggregates; table paginated (25/page) with a pager. Verified stats correct.

## Tier 4 — low (verified)

- [x] **T4.1 ✅ CSRF token in GET query strings** — DONE: `logout` + `billing-portal`
  are POST-only + CSRF; nav "Sign out" and both "Manage billing" links are now
  POST forms. Verified sign-out is a button.
- [x] **T4.2 ✅ Rate limiter fails open on DB error** — ALREADY SATISFIED:
  `rate_limit()` already `error_log`s and fails open by design (a DB blip must
  not lock out the whole site). No change needed.
- [x] **T4.3 ✅ HSTS missing on custom-domain vhost** — DONE: added HSTS to the
  on-demand `https://` block in `Caddyfile`.
- [x] **T4.4 ✅ `tls-check` rate limit keyed on Caddy IP** — DONE: now keyed on the
  requested host (20/min) so tenants aren't throttled together.
- [x] **T4.5 ✅ No `.env.example`** — DONE (T1.7).
- [x] **T4.6 ✅ MySQL healthcheck passes root pw on the command line** — DONE: both
  compose files use `MYSQL_PWD` env (not argv).
- [x] **T4.7 (bonus) Dead legacy artifacts** — DONE: removed `README`,
  `index.html`, `shortenedurls.sql`.

## Not audited (spend limit) — follow-up pass
- Multi-tenancy / SSO: OIDC/SAML signature + replay validation, domain-verify
  robustness, host-header injection.
- Privacy / compliance: retention policy, ToS/AUP + link-safety (phishing/
  malware), account deletion / data export.
