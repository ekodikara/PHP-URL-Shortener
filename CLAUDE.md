# Snip — URL Shortener

A multi-page PHP URL shortener with user accounts, tiered plans, custom link
names, QR codes, and click stats. Built on PDO + MySQL, served by Apache, and
fully containerized.

> History: this repo began as the classic Brian Cray PHP URL shortener (flat,
> `mysql_*` API, single form). It was (1) security-hardened, then (2) rewritten
> into the current "Snip" application. The old `README` and `index.html`
> (an htaccess help page) are leftovers from the original and are no longer
> wired into anything (`DirectoryIndex` is `index.php`).

## Running it

```bash
docker compose up -d --build      # build + start web (Apache/PHP 8.3) + db (MySQL 8)
# open http://localhost:8088
docker compose down               # stop
docker compose down -v            # stop AND wipe the DB volume (schema reloads on next up)
```

- **Host port is 8088** (mapped to container :80). 8080 was already in use on this
  machine — change the `ports:` mapping in `docker-compose.yml` if needed.
- `schema.sql` auto-loads on **first** DB startup only (via
  `/docker-entrypoint-initdb.d`). If you change the schema, you must
  `docker compose down -v` to force a reload — editing `schema.sql` alone won't
  re-run it on an existing volume.
- The browser/Playwright MCP runs in its own container, so from there the app is
  at `http://host.docker.internal:8088`, NOT `localhost`.
- Lint any PHP file: `php -l <file>`.

### Test account (created during verification; exists only in the live DB volume)
- `alice@example.com` / `supersecret1` — on the **Premium** plan with sample links.
- Gone if you run `docker compose down -v`.

## Production deployment (AWS, cost-minimized)

See **`DEPLOY.md`** for the full runbook. Shape: one small instance
(Lightsail / EC2 t4g.small) running **`docker-compose.prod.yml`** —
**Caddy** (auto-HTTPS via `Caddyfile`) → `web` (PHP/Apache, internal) →
`db` (MySQL, internal, EBS). No ALB/RDS/Fargate (those are the costs). DB backed
up nightly by **`backup.sh`** (mysqldump → S3). Prod secrets live in `.env`.

- Caddy terminates TLS; PHP reads `X-Forwarded-Proto` (`REQUEST_HTTPS` in
  config.php) so generated URLs + the Secure cookie flag are correct.
- **`TRUSTED_PROXIES`** (env) controls `client_ip()`: empty = use `REMOTE_ADDR`
  (ignore XFF — safe default); `private` = trust XFF when `REMOTE_ADDR` is a
  private/reserved range (correct for Caddy/ALB on an internal network); or a
  comma list of exact proxy IPs. Without this set behind a proxy, per-IP
  rate-limits and spam/VPN detection would see every visitor as the proxy.

## Architecture

Plain PHP, no framework, no Composer. Every page/endpoint starts with
`require __DIR__ . '/inc/bootstrap.php';` which loads config, starts the session,
and pulls in the helper modules.

```
config.php                 DB connect (PDO), APP constants, $GLOBALS['PLANS'],
                           $GLOBALS['RESERVED_SLUGS']. Credentials come from ENV.
inc/bootstrap.php          Entry include: requires config, starts hardened session,
                           then requires helpers/auth/urls.
inc/helpers.php            e() escaping, redirect_to(), json_response(), wants_json(),
                           CSRF (csrf_token/csrf_field/csrf_check), random/unique codes,
                           is_valid_slug(), month_start_ts(), flash messages.
inc/auth.php               current_user() [request-cached], require_login(),
                           register_user(), login_user(), establish_session(), logout_user().
inc/urls.php               QUOTA ENGINE: urls_this_month(), custom_urls_count(),
                           create_short_url() — validates URL + enforces all plan rules.
inc/layout.php             render_header()/render_footer() glass shell, render_plans()
                           pricing cards. Loads Google Fonts + qrcodejs (CDN).
inc/shorten_box.php        Shared AJAX shorten form partial (expects $user in scope).

index.php                  Landing: hero + shorten box (members) + pricing.
register.php / login.php   Auth pages (POST to self, CSRF-checked).
logout.php                 Destroys session.
dashboard.php              Member home: usage meter, shorten box, links table
                           (per-row QR, copy, click count, delete).
upgrade.php                Plan selection. DEMO: switches plan instantly (no payment).

shorten.php                POST endpoint. Creates a link (AJAX → JSON, form → redirect).
redirect.php               Resolves /<code>, enforces free-tier visit cap, counts click,
                           301-redirects. Re-validates scheme (http/https only).
delete.php                 POST. Owner-scoped delete.

assets/style.css           Glassmorphism theme (see Design below).
assets/app.js              AJAX submit, copy-to-clipboard, client-side QR, toast.

schema.sql                 InnoDB: users + urls (FK, unique code). Auto-loaded by Docker.
Dockerfile                 php:8.3-apache + pdo_mysql + mod_rewrite + AllowOverride All;
                           copies rename.htaccess → .htaccess; creates writable cache/.
docker-compose.yml         web + db (MySQL 8) services; env-injected DB creds.
rename.htaccess            Rewrite rules + include protection (copied to .htaccess in image).
```

## Routing (clean / extensionless URLs)

`rename.htaccess` (→ `.htaccess` in the image), in order:
1. **Hides PHP**: any DIRECT request for a `*.php` URL returns **404** (matched on
   `THE_REQUEST`, so internal rewrites are unaffected). PHP is never exposed.
2. Serves real files/dirs as-is (assets, etc.).
3. **Extensionless pages**: `/dashboard` → `dashboard.php` when that file exists
   (`RewriteCond %{REQUEST_FILENAME}.php -f`).
4. **Short codes**: anything else matching `^([A-Za-z0-9_-]{1,40})$` →
   `redirect.php?code=$1` (random codes AND custom slugs).
- Also blocks `/inc/` (404) and `*.sql` (403).

So `/dashboard` serves the page, `/dashboard.php` 404s, and `/abc123` /
`/my-launch` hit `redirect.php`. **All internal links/forms/redirects/fetch use
extensionless paths** (`href="login"`, `action="upgrade"`, `redirect_to('dashboard')`,
`fetch('shorten')`, home is `/`). When adding a page, link to it WITHOUT `.php`.
`require`/`include` still use real `.php` filesystem paths — those are unaffected.

## Server fingerprint disguise

The stack is made to look like **Windows Azure / IIS** to slow attackers:
- `Server: Microsoft-IIS/10.0` — set via **mod_security** `SecServerSignature`
  (needs `ServerTokens Full`; configured in the Dockerfile, not `.htaccess`).
- Real PHP `X-Powered-By` suppressed (`expose_php Off`); fake `X-Powered-By: ASP.NET`,
  `X-AspNet-Version`, and `X-Azure-Ref` headers added via mod_headers in `.htaccess`.
- Combined with the `.php` → 404 rule above, there is no outward signal that this
  is PHP/Apache. (It's obscurity, not real security — keep the actual hardening too.)

## Plans & quota rules

Defined in `config.php` as `$GLOBALS['PLANS']`. Enforced centrally in
`inc/urls.php::create_short_url()` and `redirect.php` (visit cap).

| Plan    | Price | url_limit            | monthly_visit_cap    | custom_slugs | is_trial |
|---------|-------|----------------------|----------------------|--------------|----------|
| free    | $0    | 10 (per month)       | unlimited (null)     | 0            | no       |
| pro     | $7    | 50 (per month)       | unlimited (null)     | 0            | no       |
| premium | $12   | unlimited (null)     | unlimited (null)     | 100          | no       |

- **`monthly_visit_cap`** is account-wide redirects per calendar month, enforced in
  `redirect.php` against the link OWNER's plan. Tracked by `users.month_visits` +
  `users.visit_month` (`YYYY-MM`); the counter resets when the month rolls over.
  Over-cap would return HTTP 410 — but **all plans now set this to `null`
  (unlimited)**, so a link never breaks on volume (competitors cap tracked
  analytics, not the redirect). The mechanism is dormant but kept config-driven
  for a possible future capped plan.

- `null` means unlimited. **`limit_period`** is `'month'` (free, Pro — the count
  resets each calendar month) or `'total'` (Premium/Enterprise — lifetime). Usage
  is computed by `plan_usage()` in `inc/urls.php` (→ `urls_this_month` or
  `urls_total`).
- **The `free` plan is a PERPETUAL free tier** (`is_trial => false`), not a
  time-limited trial: 10 new links/month, unlimited redirects, no custom slugs,
  no card required. New accounts start on it and stay indefinitely. The
  `TRIAL_DAYS` constant and the `trial_*` helpers in `inc/auth.php`
  (`is_on_trial`, `trial_active`, `trial_expired`, `trial_ends_ts`,
  `trial_days_left`) are now **dormant** — kept for a possible future paid trial.

## Billing (Stripe subscriptions)

- **Checkout Sessions in `subscription` mode** (hosted) + **Customer Portal** for
  self-service. SDK: `stripe/stripe-php` (composer; installed in the Docker image,
  `vendor/` is gitignored). Helpers in `inc/stripe.php`; pure pricing math
  (`plan_amount`, `plan_amount_cents`, `money`) is in `inc/helpers.php`.
- **Keys** come from `.env` (gitignored) via docker-compose `env_file`:
  `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`. Never
  hardcode. `STRIPE_API_VERSION` + `YEARLY_DISCOUNT` (0.12) are in config.php.
- **Pricing**: every plan is monthly by default; yearly = `monthly × 12 × (1 −
  0.12)` (12% off) → Pro $73.92/yr, Premium $126.72/yr. Prices are inline
  `price_data` in the Checkout line item (no pre-created Stripe Products needed).
  The monthly/yearly toggle (`render_billing_toggle()` + app.js) swaps displayed
  prices and the hidden `interval` field on each checkout form.
- **Endpoints** (clean URLs): `checkout` (POST → creates session, redirects to
  Stripe), `billing-success` (verifies session, activates plan — works without a
  webhook locally), `billing-portal` (→ Customer Portal), `stripe-webhook`
  (signature-verified; `checkout.session.completed` activates,
  `customer.subscription.deleted` downgrades to locked free state).
- **Cancellation** = keep access until period end. The Customer Portal sets
  `cancel_at_period_end`; we only downgrade on the final `subscription.deleted`.
- **For real webhooks locally**: run `stripe listen --forward-to
  localhost:8088/stripe-webhook` and put the printed `whsec_…` in `.env`.
- New `users` columns: `billing_interval`, `stripe_customer_id`,
  `stripe_subscription_id` (in schema.sql; ALTER the live DB if upgrading an
  existing volume rather than recreating it).
- **On trial expiry**: existing links keep redirecting, but `create_short_url()`
  blocks NEW links until the user upgrades. You cannot switch *back* to the trial
  (`upgrade.php` rejects `is_trial` targets).
- Monthly count = rows in `urls` for the user with `created >= month_start_ts()`.
- Custom slug count = rows with `is_custom = 1`. Validated by `is_valid_slug()`
  (3–40 of `[A-Za-z0-9_-]`, not in `$GLOBALS['RESERVED_SLUGS']`, unique).
- Visit caps: see `monthly_visit_cap` note above (the free trial caps at 10/month).

## Conventions / patterns to follow

- **Always** open a page/endpoint with `require __DIR__ . '/inc/bootstrap.php';`.
- **All DB access is PDO prepared statements.** Never concatenate user input into SQL.
- **Every POST is CSRF-protected**: render `<?= csrf_field() ?>` in the form and call
  `csrf_check()` at the top of the handler.
- **Escape all output** with `e()` (htmlspecialchars wrapper).
- **AJAX vs form**: endpoints branch on `wants_json()` — JSON for XHR
  (`X-Requested-With: XMLHttpRequest`), `set_flash()` + `redirect_to()` for plain forms.
- **Short codes**: random via `unique_random_code()` (6 chars from `ALLOWED_CHARS`);
  custom via validated slug. Both stored in `urls.code` (unique).
- **Auth**: passwords via `password_hash`/`password_verify`; `establish_session()`
  regenerates the session id on login/register.
- Redirects only ever go to `http(s)://` URLs (validated at create AND at redirect time).

## Configuration (environment variables)

Set in `docker-compose.yml` (the `web` service), read in `config.php`:
`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and optionally `SITE_HOST`
(trusted public base URL with trailing slash; if blank, derived from the request
host, sanitized — guards against Host-header injection).

## MCP integration (paid plans)

Snip exposes an **MCP server** (Model Context Protocol, JSON-RPC 2.0 over HTTP)
so paid users can drive it from AI assistants.

- **Endpoint**: `mcp.php` → clean URL `/mcp`. POST only. No session/CSRF —
  auth is a Bearer token. Handles `initialize`, `ping`, `tools/list`,
  `tools/call`, and `notifications/*`.
- **Tools**: `shorten_url(url, custom_name?)`, `list_links()`, `get_stats(code)`,
  `delete_link(code)` — all scoped to the token's owner and reuse the same
  quota/plan rules as the web UI (`create_short_url`, etc.).
- **Auth**: per-user API tokens (`inc/tokens.php`). Raw token shown once on the
  **Connect AI** page (`connect.php`, clean `/connect`); only a SHA-256 hash is
  stored in the `api_tokens` table. Bearer token → `user_for_token()`.
- **Gating**: `/mcp` requires `plan_is_paid()` (Pro/Premium); trial/free tokens
  get JSON-RPC error -32002 (403). `/connect` redirects non-paid users to upgrade.
- The `.htaccess` has a `SetEnvIfNoCase Authorization` line so the Bearer header
  reaches PHP (plus a `getallheaders()` fallback in `bearer_token()`).
- Client setup: `claude mcp add --transport http snip <BASE>/mcp --header
  "Authorization: Bearer snip_…"` (shown on the Connect AI page).

## Security hardening layer (`inc/security.php`)

- **Audit log** — `log_security_event($pdo, $event, $detail, $user_id)` writes to
  the `security_log` table (on the DB volume, so it survives `--build`). Events:
  `login_ok/login_fail`, `register`, `bot_blocked`, `rate_limited`,
  `mcp_invalid_token`, `mcp_denied`, `mcp_scope_denied`, `mcp_rate_limited`.
- **Rate limiting** — `rate_limit($pdo, $key, $max, $window)` (fixed window, in
  the `rate_limits` table). Applied: login 10/15min per IP + 8/15min per account;
  register 5/hr per IP; `/mcp` 120/min per token. Over-limit → HTTP 429.
- **Bot defenses on register/login** — `form_guard_fields()` renders a honeypot
  (`hp_url`), a JS-proof field (`js_ok`, set by app.js), and (if configured) a
  Turnstile widget; `form_guard_check()` rejects honeypot-filled, sub-2s, or
  no-JS submissions. So scripted/curl signups are blocked while real browsers
  (incl. Playwright driving the UI) pass.
- **Cloudflare Turnstile (optional)** — enforced only when `TURNSTILE_SITE_KEY`
  and `TURNSTILE_SECRET` are set in `.env`; otherwise the honeypot/JS/timing
  layers carry it. `turnstile_script()` is injected in the layout head.
- **MCP token scopes** — `api_tokens.scope` is `read` or `full`. Read tokens only
  see/call `list_links` + `get_stats`; write tools (`shorten_url`, `delete_link`)
  return JSON-RPC -32004. Chosen on the Connect AI page.
- **IP note**: `client_ip()` uses `REMOTE_ADDR`. Behind a real proxy/load balancer
  in prod, switch to a trusted `X-Forwarded-For` parse, or per-IP limits collapse.

## Adaptive captcha, blocking & access logging

- **Adaptive Google reCAPTCHA** — shown ONLY to suspicious clients, on login,
  register, shorten, and token-create. `is_suspicious()` = behavioural spam
  (≥`SUSPICION_EVENTS` bad `security_log` events from the IP in `SUSPICION_WINDOW`)
  OR VPN/proxy (`ip_is_vpn()` via IPQualityScore, cached in `ip_reputation`; needs
  `IPQS_API_KEY`). `captcha_gate()` returns '' / 'captcha' / 'blocked'; surfaces
  render `recaptcha_block()` when `captcha_needed()`. Keys in `.env`
  (`RECAPTCHA_SITE_KEY/SECRET`) — **default to Google's public TEST keys** (always
  pass); replace for production.
- **Account block** — `users.blocked` + `blocked_reason`. Enforced in
  `login_user()` (suspended message), `require_login()` (force logout),
  `user_for_token()`/`mcp.php` (-32005), and `redirect.php` (owner blocked → 410).
- **Link block** — `urls.blocked`. `redirect.php` → 410. Owners toggle their own
  links via `link-toggle.php` (dashboard ⏸/▶); admins via the admin panel.
- **Admin panel** — `admin.php` (clean `/admin`), gated by `is_admin()`
  (`users.is_admin` column OR `ADMIN_EMAILS` config). Block/unblock users & links,
  review recent access + security events. alice@example.com is admin by default.
- **Access logging** — `log_access()` writes every redirect to `access_log`
  (ts, code, ip, browser, platform, referer, UA) via `parse_user_agent()`.

## Abuse protection (Safe Browsing, reports, interstitial, terms)

- **Google Safe Browsing v4** — `url_threat($pdo, $url)` in `inc/security.php`
  (needs `SAFE_BROWSING_API_KEY` in `.env`; empty = checks disabled, fail-open).
  Enforced in `create_short_url()` (creation rejected + `malicious_url_blocked`
  logged) and in `redirect.php` (link auto-disabled + `auto_block_malicious`,
  410). Verdicts cached in the `url_reputation` table for `URL_SCAN_TTL` (12h);
  lookup failures are NOT cached.
- **Content filter (adult/disallowed destinations)** — `inc/content_filter.php`.
  Safe Browsing does NOT flag adult content, so this adds AUP layers,
  cheapest-first: (1) a **bundled adult-domain blocklist** (`data/adult-domains.txt`,
  a StevenBlack "porn-only" snapshot, ~77k domains; refresh via
  `scripts/update-adult-blocklist.sh`); (2) an **admin-managed `blocked_domains`
  table** (manage in `/admin` → "Blocked domains"); (3) **IPQualityScore URL
  category** (reuses `IPQS_API_KEY`; only the adult flag hard-blocks; cached in
  `url_reputation.category`/`cat_checked`, independent of the Safe Browsing
  columns); (4) a **keyword backstop** (`url_keyword_flag()`, word-bounded) that
  does NOT block — it logs `content_review_flagged` for admin review.
  `url_is_disallowed($pdo, $url)` folds Safe Browsing + layers 1–3 into one
  verdict (`''` = allow, else `unsafe:<threat>` or `adult:<source>`), enforced in
  `create_short_url()` (reject + `adult_url_blocked`) and `redirect.php`
  (auto-disable + `auto_block_disallowed`, 410). `CONTENT_FILTER_ON=0` disables
  the adult layers only (Safe Browsing still runs). Schema in migration
  `004_content_filtering.sql`.
- **Public abuse reports** — `report.php` (clean `/report`, linked from the
  footer and the interstitial): honeypot + rate-limited (5/hr/IP), accepts a
  code or full short URL, writes to `link_reports`. `AUTO_BLOCK_REPORTS` (3)
  open reports on one code auto-disables the link. Admin panel has an "Abuse
  reports" section: resolve (= confirm abuse, disables the link) or dismiss.
  Unknown codes get a success response but are not stored (no existence leak).
- **Interstitial** — `redirect.php` shows a "You're leaving Snip" notice page
  (destination host + full URL + report link) for links owned by TRIAL accounts;
  paid plans 301 directly. Flagged destinations never redirect at all.
- **Terms/AUP** — `terms.php` (clean `/terms`). `ABUSE_EMAIL` (env; defaults to
  `abuse@<host>`) is shown on `/report` and `/terms`.
- `terms`, `report`, `abuse` are in `RESERVED_SLUGS`.

## Enterprise plan (custom domains, SSO, contact sales)

- **Plan**: `enterprise` (config) — unlimited everything, `custom_slugs => null`
  (unlimited; `null` is treated as unlimited in `create_short_url`/shorten_box),
  `contact => true` (no Stripe checkout; `checkout.php` already rejects it).
  Pricing card shows "Custom / Contact sales".
- **Contact sales**: `enterprise.php` (clean `/enterprise`) — public form
  (honeypot + rate-limited) → `enterprise_leads` table; visible in the admin panel.
- **Multi-tenant custom domains** (`inc/domains.php`, `domains.php` page,
  Enterprise-only): `domains` table maps host→owner (DNS-TXT verified). Links get
  `urls.domain_id`; `redirect.php` enforces isolation — a branded link resolves
  only on its host, a main-app link only on the main host (`(int)domain_id ===
  (int)current_domain_id`). New links created while on a verified custom host are
  tagged with it; `request_base()` brands the returned short URL.
- **TLS for custom domains**: prod `Caddyfile` uses **on-demand TLS** with
  `ask http://web:80/tls-check`; `tls-check.php` returns 200 only for the main
  domain or a verified custom domain, so certs are minted only for known hosts.
- **SSO** (`inc/sso.php`, `sso.php`, Enterprise): env-gated, both protocols.
  **OIDC** (hand-rolled: discovery or explicit endpoints, `state` CSRF, code
  exchange, userinfo) and **SAML 2.0** (`onelogin/php-saml`: `/sso?provider=saml&
  action=login|acs|metadata`). SSO users are auto-provisioned on the Enterprise
  plan (`sso_provision`). "Sign in with SSO" buttons appear on `/login` only when
  a provider is configured. Config: `OIDC_*` / `SAML_IDP_*` in `.env`.
  NOTE: OIDC callback + SAML need a real IdP to exercise end-to-end; the
  initiation/gating/config paths are verified.

## Lighthouse test pipeline

Lighthouse audits `/`, `/login`, `/register`, `/enterprise` against score
thresholds in **`lighthouserc.json`** (single source of truth: perf ≥ 80,
a11y ≥ 90, best-practices ≥ 90 are errors; SEO is warn-only because the app
intentionally serves `noindex`). Reports land in `.lighthouseci/reports/`
(gitignored). Three ways to run, same config:

- **Manual**: `scripts/lighthouse.sh` (app must be up on :8088;
  `LIGHTHOUSE_BASE_URL=…` to audit another host).
- **Auto on every commit**: `.githooks/post-commit` runs the script after each
  commit. Enable once per clone with `git config core.hooksPath .githooks`;
  skip one commit with `SKIP_LIGHTHOUSE=1 git commit …`. Never blocks the
  commit — failures are reported to fix + amend.
- **CI**: `.github/workflows/lighthouse.yml` — auto on every push, manual via
  the Actions "Run workflow" button. Boots the compose stack, audits, uploads
  the reports as an artifact.

## Known gaps / TODO

- **Billing is demo-only**: `upgrade.php` flips the plan with no payment. Real
  billing needs Stripe Checkout + a verified webhook before changing `users.plan`.
- **QR codes load qrcodejs from cdnjs** (needs internet). Vendor it locally for
  offline use.
- HTTP only (no TLS) in the current Docker setup — dev/local only.
- No password reset / email verification / rate limiting on auth yet.
- Old artifacts (`README`, `index.html`, `shortenedurls.sql`) are unused and could
  be removed.
- Nothing is committed yet — all of this is uncommitted working-tree changes.
