# Snip — Session Status / Handoff

_Last updated: 2026-07-12. Read `CLAUDE.md` first for architecture & conventions._

## TL;DR
Snip is a **built, verified, launch-prep** URL-shortener SaaS. This session added
content filtering, click analytics, registration hardening, a perpetual-free plan,
billing transparency, and go-live docs. All work lives on branch
**`worktree-content-filtering`** (pushed to both remotes; **not merged to master**,
**no PR** — `gh` CLI isn't installed here). Business is **Australian** (owner Moonxt),
prod domain **jmpz.cc**. Not yet live — go-live is gated on a Stripe account + a few
config/legal items, not on code.

## Where we left off
- **Branch:** `worktree-content-filtering`, working tree **clean**, everything
  committed + pushed to `origin` (ekodikara/PHP-URL-Shortener) **and** `u99x`
  (ekodikara/u99x). Latest commit `9c6e2f7`.
- **Running locally:** Docker project `snip` at http://localhost:8088. If down:
  `docker compose -p snip up -d --build` from this worktree, then
  `docker compose -p snip exec -T web php scripts/migrate.php`.
- **Test account:** `alice@example.com` / `supersecret1` (Premium, email-verified,
  sample links incl. `/launch` with seeded analytics). Gone on `down -v`.
- A **Stop hook** (in `~/.claude/settings.json`) plays an alert sound when a task
  finishes. Session runs in **ultracode** mode.

## Shipped this session ✅ (all on the branch)
- **Content filtering** (`inc/content_filter.php`) — bundled adult-domain blocklist
  (`data/adult-domains.txt`, ~77k) + admin `blocked_domains` + IPQS category, on top
  of Safe Browsing. Enforced at create + redirect. Security-audited & fixed
  (trailing-dot bypass, admin subdomain-walk, 77k-list DoS → APCu cache).
- **Free plan → perpetual** (was a 30-day trial): 10 links/month, **unlimited
  redirects (no more 410 link-breaking)**, no card. Trial cap history: 50→10→removed.
- **Plan UI**: period-aware limit messaging + a full-width inline **comparison table**
  (replaced a popup).
- **Analytics** (`stats.php`, `/stats?code=…`): clicks-over-time, unique visitors
  (privacy hash), referrers, browser/OS/device, **country geo w/ flags** (DB-IP mmdb),
  **live activity feed** (polled), **human/bot split**, CSV export. MCP `get_stats` enriched.
- **Registration hardening**: disposable-email block (`data/disposable-email-domains.txt`)
  + enforced email verification before creating links.
- **Billing**: fixed a real **plan-change double-billing bug** (existing subscribers now
  route to the Portal); added a dashboard **billing-status block**, `/refund` policy,
  at-checkout auto-renewal disclosure, statement-descriptor display, webhook now stores
  `current_period_end`/`cancel_at_period_end`.
- **Docs (repo root):** `DEPLOY.md` (runbook, corrected for AU + migrate step),
  `PAYMENTS.md` (Stripe-AU vs PayPal/MoR), `BUSINESS-SETUP.md` (sole-trader → Stripe),
  this `STATUS.md`. Tests: 33/485 green (`bash scripts/test.sh`).

## Next up — go-live path ⏭️ (details in DEPLOY.md / PAYMENTS.md / BUSINESS-SETUP.md)
**Business (longest lead — start first):**
1. Register a **sole trader ABN** (free) → optionally the "Moonxt" business name
   (~A$47) — enough for Stripe. See `BUSINESS-SETUP.md`.
2. Activate **Stripe AU** (self-serve: ABN + AU bank + KYC).

**Must-fix before charging (mostly config, not code):**
3. **Email delivery** — SMTP relay + SPF/DKIM/DMARC for jmpz.cc (verification is
   enforced, so broken mail dead-ends signups). **Hard blocker.**
4. Set `SAFE_BROWSING_API_KEY`, real **reCAPTCHA** keys (blank fails closed), Stripe
   **live keys + webhook**, run `scripts/update-geoip.sh` before build.
5. **Stripe Dashboard (no code):** statement descriptor `SNIP.APP`, email receipts,
   renewal-reminder + dunning emails, Radar defaults, Portal cancel/plan-switch on.
6. One **restore drill** (`restore.sh`).

**Deferred / nice-to-have:**
- Merge `worktree-content-filtering` → `master` and open a PR (needs `gh` or the web).
- Legal: Privacy Policy (Australian Privacy Act), have a solicitor sight `/refund` +
  checkout wording. GST only at A$75k turnover (then switch pricing to AUD + GST-incl).
- Timestamped ToS-acceptance checkbox at checkout (stronger chargeback evidence).
- Sentry error monitoring; vendor qrcodejs locally; conversion attribution (Dub-style).

## Useful commands
```bash
docker compose -p snip up -d --build                                   # start (this worktree)
docker compose -p snip exec -T web php scripts/migrate.php             # apply migrations (every deploy)
docker compose -p snip ps                                              # status
bash scripts/test.sh                                                   # run PHPUnit (Docker)
php -l <file>                                                          # lint
git -C . log --oneline -15                                             # this session's commits
```
