# Deploying Snip to AWS (cost-minimized)

Single small instance running the Docker Compose prod stack, with Caddy for free
auto-HTTPS. No ALB, no RDS, no Fargate — those are the parts that cost money.
**Target: ~$5–12/month** (≈free for 12 months on a new account's free tier).

```
Route 53 ─DNS─► EC2 t4g.small / Lightsail  ┌─ caddy :443/:80 (TLS)
                                           ├─ web   :80 (internal)
                                           └─ db    MySQL (internal, EBS)
                                                │ nightly mysqldump
                                                ▼  S3 (backups)
```

## 1. Provision

- **Lightsail** (simplest, flat price): a $5–10/mo instance, Ubuntu 22.04+, 1 GB+ RAM.
- **or EC2**: `t4g.small` (ARM/Graviton, cheapest), Amazon Linux 2023 or Ubuntu,
  20 GB gp3 EBS. Attach an **IAM role** with `s3:PutObject`/`ListBucket`/`DeleteObject`
  on your backup bucket (so `backup.sh` needs no stored keys).
- **Security group / firewall**: allow inbound **80** and **443** only (and 22 from your IP).
  Do **not** open 3306 — MySQL stays internal.

## 2. Install Docker + tools

```bash
# Amazon Linux 2023
sudo dnf install -y docker awscli git && sudo systemctl enable --now docker
sudo usermod -aG docker ec2-user
# Docker Compose v2 plugin
sudo mkdir -p /usr/libexec/docker/cli-plugins
sudo curl -sL "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" \
  -o /usr/libexec/docker/cli-plugins/docker-compose && sudo chmod +x /usr/libexec/docker/cli-plugins/docker-compose
# log out/in so the docker group applies
```

## 3. Get the code + secrets

```bash
sudo mkdir -p /opt/snip && sudo chown $USER /opt/snip
git clone <your-repo> /opt/snip && cd /opt/snip
cp .env .env.bak 2>/dev/null || true
nano .env   # fill in the values below
chmod 600 .env
```

**`.env` for production** (rotate every secret — the dev/test values must not ship):

```dotenv
# Public site
SITE_DOMAIN=jmpz.cc                 # bare domain Caddy serves + gets a cert for
SITE_HOST=https://jmpz.cc/          # trailing slash — used for BASE_HREF & Stripe URLs
TRUSTED_PROXIES=private            # trust Caddy's X-Forwarded-For (internal network)

# Database (strong, unique)
DB_PASSWORD=__change_me__
DB_ROOT_PASSWORD=__change_me_too__

# Stripe LIVE keys (Australian Stripe account — see "Payments" note at the end)
STRIPE_PUBLISHABLE_KEY=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=             # from the Dashboard webhook you create in step 6

# Google reCAPTCHA v2 — REAL keys required. Blank FAILS CLOSED (blocks clients
# the adaptive gate deems suspicious), so do NOT ship blank.
RECAPTCHA_SITE_KEY=
RECAPTCHA_SECRET=

# Outbound mail — REQUIRED. Email verification is enforced, so if mail doesn't
# deliver, signups dead-end. Set a from-address AND wire a real SMTP relay
# (SES / Postmark / Resend — the base image has no MTA) + SPF/DKIM/DMARC DNS.
MAIL_FROM=noreply@jmpz.cc

# Link safety — Google Safe Browsing v4 key (blank = malware/phishing checks OFF).
SAFE_BROWSING_API_KEY=
# Optional: IPQualityScore (VPN + adult/URL-category), and the IP→country DB path
# for click-analytics geo (fetched by scripts/update-geoip.sh; see step 5).
IPQS_API_KEY=
GEOIP_DB=

ADMIN_EMAILS=you@example.com
ABUSE_EMAIL=abuse@jmpz.cc          # shown on /report + /terms (default abuse@<host>)
```

## 4. DNS

Point an **A record** for `SITE_DOMAIN` at the instance's public IP (Elastic IP on
EC2 so it survives reboots; Lightsail static IP is free while attached).

## 5. Launch

```bash
cd /opt/snip
./scripts/update-geoip.sh          # fetch IP→country DB (baked into the image; skip to disable geo)
docker compose -f docker-compose.prod.yml up -d --build
# Apply DB migrations — REQUIRED on every deploy (schema.sql is only the fresh-volume baseline):
docker compose -f docker-compose.prod.yml exec -T web php scripts/migrate.php
docker compose -f docker-compose.prod.yml logs -f caddy   # watch the cert issue
```

Caddy provisions a Let's Encrypt cert automatically once DNS resolves and 80/443
are open. Visit `https://SITE_DOMAIN`. Then smoke-test: `/health` returns 200,
register → **receive the verification email** → verify → create a link → the
redirect works → a real card checkout activates the plan.

## 6. Stripe webhook

In the Stripe Dashboard → Developers → Webhooks, add an endpoint:
`https://SITE_DOMAIN/stripe-webhook` (events: `checkout.session.completed`,
`customer.subscription.deleted`). Copy its signing secret into `STRIPE_WEBHOOK_SECRET`
in `.env`, then `docker compose -f docker-compose.prod.yml up -d` to apply.

## 7. Backups + durability

```bash
# nightly DB dump → S3
( crontab -l 2>/dev/null; echo "0 3 * * * cd /opt/snip && S3_BUCKET=my-snip-backups ./backup.sh >> /var/log/snip-backup.log 2>&1" ) | crontab -
```

Also enable a **daily EBS snapshot** (Data Lifecycle Manager) on the instance volume
so the MySQL data volume itself is recoverable.

## 8. Updates

```bash
cd /opt/snip && git pull
./scripts/update-geoip.sh          # optional: refresh the geo DB (monthly-ish)
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec -T web php scripts/migrate.php   # apply any new migrations
```

Schema changes ship as numbered files in `migrations/`; **`scripts/migrate.php`**
(forward-only, tracked in `schema_migrations`) applies them idempotently and is
**required on every deploy**. `schema.sql` is only the fresh-volume baseline — never
wipe the volume to "reload" it. The bundled adult/disposable blocklists are
committed; refresh them with `scripts/update-adult-blocklist.sh` /
`update-disposable-emails.sh` when desired.

## Notes / gotchas
- **TLS terminates at Caddy**; PHP sees HTTP. The app reads `X-Forwarded-Proto`
  (`REQUEST_HTTPS`) so generated URLs and the Secure cookie flag stay correct —
  but still set `SITE_HOST=https://…` to be explicit.
- `TRUSTED_PROXIES=private` makes `client_ip()` read the real visitor IP from
  Caddy's `X-Forwarded-For`; without it, rate-limits/abuse detection would see
  every visitor as the proxy. If you later add CloudFront/ALB, trust those instead.
- Single instance = single point of failure. Fine to start; when you outgrow it,
  graduate the DB to RDS and the app to ECS/ALB behind the same `client_ip()` logic.
- **Payments (Australia).** Moonxt is Australian, so **Stripe is self-serve** —
  activate with an **ABN + Australian bank account + business verification**; no
  invite or cross-border approval needed. **GST (10%)** applies once turnover
  reaches **AUD $75k/yr** — enable **Stripe Tax** to compute/collect it. The
  Stripe integration is already built, so it's the default. A Merchant-of-Record
  (Paddle / Lemon Squeezy) is only worth adopting to offload global sales-tax
  compliance, and would replace `inc/stripe.php`.
- **Email deliverability is a launch gate.** `REQUIRE_EMAIL_VERIFICATION` is on
  and the base image has no MTA — point PHP `mail()` at an SMTP relay
  (SES/Postmark/Resend) and add **SPF/DKIM/DMARC** for `SITE_DOMAIN`, or
  verification emails silently fail and new users can't create links.
- **Privacy.** Visitor IPs are stored in `access_log`; publish a Privacy Policy
  under the **Australian Privacy Act / APPs** (GDPR only applies if you target EU
  users). `LOG_RETENTION_DAYS` caps how long IPs are kept.
- **GeoIP data.** `data/*.mmdb` is gitignored — run `scripts/update-geoip.sh`
  before `docker compose build` so the IP→country DB is baked in; otherwise
  analytics country shows "Unknown" (fail-open).
