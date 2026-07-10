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

- [ ] **T2.1 ⚠️ No monitoring / health / alerting** — add a `/health` endpoint
  (DB check) for uptime monitors; document error-tracking hook.
- [ ] **T2.2 ⚠️ Backups: no tested restore, no encryption, no failure alerting**
  — add `restore.sh`, optional client-side encryption + failure trap to
  `backup.sh`.
- [ ] **T2.3 ⚠️ No automated tests** for quota/pricing/slug/redirect/auth — add
  PHPUnit + tests for the pure/critical logic; wire a runner.
- [ ] **T2.4 ⚠️ No global exception boundary** — uncaught errors render blank
  500s / leak HTML from JSON endpoints. Fix: set exception/error/shutdown
  handlers in `inc/bootstrap.php` → themed 500 (JSON for AJAX), details logged.
- [ ] **T2.5 ⚠️ Non-reproducible builds** — `composer.lock` gitignored; floating
  base-image tag. Fix: commit `composer.lock`, pin the base image.
- [ ] **T2.6 ⚠️ Redirect hot path** — 301 w/o cache header breaks click counting;
  synchronous `access_log` write; new PDO per request; session on the anon hot
  path. Fix: 302 + `Cache-Control: no-store`; wrap logging non-fatally;
  persistent PDO; skip session for redirect.
- [ ] **T2.7 ✅ Email verification + password reset absent** (adjusted medium) —
  no email transport exists. Fix: at minimum wire a mail transport + password
  reset; email verification gate. (Largest feature; may stage.)

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
