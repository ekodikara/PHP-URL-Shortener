# Snip — Architecture

Snip is a **server-rendered PHP monolith** (no separate SPA/API tier): the same
Apache/PHP process renders HTML pages, answers AJAX/JSON for the shorten box,
speaks JSON-RPC to AI clients over MCP, resolves short-code redirects, and
receives Stripe webhooks. All persistent state lives in **MySQL**. TLS and
routing are handled at the edge by **Caddy**.

The "UI ↔ API ↔ DB" boundary is therefore logical rather than physical:
- **UI** = server-rendered pages (`inc/layout.php` shell) + a thin JS layer
  (`assets/app.js`: AJAX submit, copy, QR, theme toggle).
- **API** = the PHP endpoints (`shorten.php`, `redirect.php`, `mcp.php`, the
  auth/billing/admin handlers) — every one boots through `inc/bootstrap.php`.
- **DB** = MySQL, accessed only via PDO prepared statements in `inc/*.php`.

> All diagrams below are [Mermaid](https://mermaid.js.org) — they render on
> GitHub, in VS Code (Markdown Preview Mermaid), and at mermaid.live.

---

## 1. System context — who talks to Snip, and to whom Snip talks

```mermaid
flowchart LR
  visitor([Visitor<br/>clicks a short link]):::actor
  member([Member<br/>browser]):::actor
  admin([Admin]):::actor
  ai([AI assistant<br/>Claude / MCP client]):::actor

  subgraph snip[Snip]
    app[["Web app<br/>(Caddy + PHP/Apache)"]]
    db[("MySQL")]
    app --- db
  end

  visitor -->|"GET /:code"| app
  member -->|"HTTPS pages + AJAX"| app
  admin -->|"/admin"| app
  ai -->|"JSON-RPC /mcp (Bearer)"| app

  app -->|"Checkout, Portal, webhooks"| stripe[[Stripe]]
  app -->|"verify token"| recaptcha[[reCAPTCHA / Turnstile]]
  app -->|"IP reputation"| ipqs[[IPQualityScore]]
  app -->|"ACME certs"| le[[Let's Encrypt]]
  app -->|"verification / reset mail"| smtp[[SMTP / mail]]
  app -->|"nightly dump"| s3[[AWS S3]]
  app -->|"OIDC / SAML"| idp[[Enterprise IdP]]
  member -.->|"fonts, qrcode.js"| cdn[[Google Fonts / cdnjs]]

  classDef actor fill:#e8eef7,stroke:#5b6b86,color:#1a2233;
```

External calls are all **optional / feature-gated**: Stripe (paid plans),
reCAPTCHA/Turnstile/IPQS (abuse defense), IdP (Enterprise SSO), SMTP (email —
falls back to a dev log), S3 (backups). Core shortening works with none of them.

---

## 2. Runtime — current single-box deployment (Docker Compose)

```mermaid
flowchart TB
  browser([Browser])

  subgraph host["One instance (EC2 t4g.small / Lightsail) — docker-compose.prod.yml"]
    caddy["caddy:443/:80<br/>auto-HTTPS (Let's Encrypt),<br/>on-demand TLS for custom domains"]
    subgraph web["web — php:8.3-apache"]
      rewrite{{".htaccess router<br/>• *.php → 404 (hidden)<br/>• /:code → redirect.php<br/>• /page → page.php"}}
      php["PHP endpoints<br/>bootstrap → helpers/auth/urls/<br/>security/stripe/domains/session"]
    end
    db[("db — MySQL 8<br/>EBS volume")]
    caddy -->|"reverse_proxy web:80<br/>(X-Forwarded-Proto/For)"| rewrite --> php
    php -->|"PDO"| db
  end

  browser <-->|"HTTPS"| caddy
  caddy -->|"/tls-check ask (on-demand TLS)"| php
  db -->|"mysqldump → S3"| s3[[S3 backups]]
```

Key edge behaviors: TLS terminates at Caddy; PHP reads `X-Forwarded-Proto`
(`REQUEST_HTTPS`) and `X-Forwarded-For` (`client_ip()`, gated by
`TRUSTED_PROXIES`). PHP is never exposed directly (`*.php` → 404); the stack is
even fingerprint-spoofed to look like IIS/ASP.NET.

---

## 3. Communication flows (UI ↔ API ↔ DB)

### 3a. Create a short link (AJAX)

```mermaid
sequenceDiagram
  autonumber
  participant U as Browser (app.js)
  participant W as shorten.php
  participant C as create_short_url() (inc/urls.php)
  participant DB as MySQL
  U->>W: POST /shorten (X-Requested-With, CSRF, longurl[, slug])
  W->>W: require_login + CSRF + captcha_gate()
  W->>C: create_short_url(user, url, slug, domain_id)
  C->>DB: quota / custom-slug / uniqueness checks
  C->>DB: INSERT INTO urls (code, long_url, …)
  C-->>W: {code, short_url}
  W-->>U: 200 JSON {short_url}
  U->>U: render the "stub" + client-side QR (qrcode.js)
```

### 3b. Resolve a short code (the hot path)

```mermaid
sequenceDiagram
  autonumber
  participant V as Visitor
  participant R as redirect.php (SNIP_SKIP_SESSION)
  participant DB as MySQL
  V->>R: GET /:code
  R->>DB: SELECT url JOIN owner (plan, blocked, month_visits)
  R->>R: validate code / domain isolation / blocked / scheme
  R->>DB: atomic monthly visit-cap UPDATE (owner)
  R->>DB: UPDATE urls SET clicks = clicks+1
  R->>DB: INSERT access_log (best-effort)
  R-->>V: 302 + Cache-Control: no-store → long_url
  Note over R: no session/CSRF, and 302 (not 301) keeps clicks counting
```

### 3c. Login + session (files today, DB-backed when scaled)

```mermaid
sequenceDiagram
  autonumber
  participant U as Browser
  participant L as login.php
  participant A as login_user() (inc/auth.php)
  participant DB as MySQL
  U->>L: POST /login (CSRF, form-guard, adaptive captcha)
  L->>A: verify email + password_hash (constant-time)
  A->>DB: SELECT user + rate_limit() rows
  A-->>L: user id
  L->>L: establish_session() → session_regenerate_id
  Note over L,DB: SESSION_DRIVER=db → session read/write hits<br/>the sessions table (shared across instances)
  L-->>U: 302 /dashboard (Set-Cookie snipsess)
```

### 3d. Billing (Stripe Checkout + webhook)

```mermaid
sequenceDiagram
  autonumber
  participant U as Browser
  participant CH as checkout.php
  participant S as Stripe
  participant WH as stripe-webhook.php
  participant DB as MySQL
  U->>CH: POST /checkout (plan, interval, CSRF)
  CH->>S: create Checkout Session
  CH-->>U: 302 → Stripe-hosted checkout
  U->>S: pay
  S-->>U: 302 → /billing-success (activates locally too)
  S->>WH: POST /stripe-webhook (signed event)
  WH->>WH: verify signature, INSERT stripe_events (idempotency)
  WH->>DB: activate_subscription / downgrade_by_customer
  Note over WH: handler wrapped in try/catch — on error the<br/>event row is removed + 500 so Stripe retries
```

### 3e. MCP (AI assistant → tools → DB)

```mermaid
sequenceDiagram
  autonumber
  participant AI as MCP client
  participant M as mcp.php (JSON-RPC 2.0)
  participant T as tokens / urls
  participant DB as MySQL
  AI->>M: POST /mcp (Bearer snip_…, method, params)
  M->>M: per-IP rate limit → user_for_token()
  M->>DB: token hash lookup
  M->>M: gate: paid plan + read/full scope
  M->>T: tools/call (shorten_url | list_links | get_stats | delete_link)
  T->>DB: scoped query (WHERE user_id = token owner)
  M-->>AI: JSON-RPC result
```

---

## 4. Data model

```mermaid
erDiagram
  users ||--o{ urls : "owns"
  users ||--o{ api_tokens : "issues"
  users ||--o{ domains : "owns"
  users ||--o{ auth_tokens : "reset/verify"
  domains ||--o{ urls : "brands (domain_id, SET NULL)"

  users {
    int id PK
    string email UK
    string password_hash
    enum plan "free|pro|premium|enterprise"
    string stripe_customer_id UK
    string stripe_subscription_id
    int month_visits
    char visit_month "YYYY-MM"
    tinyint is_admin
    tinyint blocked
    tinyint email_verified
    enum auth_provider "password|oidc|saml"
    int created
  }
  urls {
    int id PK
    int user_id FK
    string code UK
    string long_url
    tinyint is_custom
    tinyint blocked
    int domain_id FK
    int clicks
    int created
  }
  domains {
    int id PK
    int user_id FK
    string host UK
    tinyint verified
    string token "DNS-TXT"
  }
  api_tokens {
    int id PK
    int user_id FK
    char token_hash UK "sha256"
    enum scope "read|full"
    int last_used
  }
  auth_tokens {
    int id PK
    int user_id FK
    enum kind "reset|verify"
    char token_hash UK
    int expires
    tinyint used
  }
  sessions {
    string id PK
    blob data
    int expires
  }
```

**Unattached operational tables** (no FK; shared-state, so already
multi-instance-safe): `rate_limits`, `security_log`, `access_log`,
`ip_reputation`, `stripe_events`, `enterprise_leads`, `schema_migrations`.
This is why the *only* code change needed for horizontal scaling was moving
**sessions** off the local filesystem into `sessions` (`SESSION_DRIVER=db`).

---

## 5. Scale topology (documented target — see AWS-SCALE-ARCHITECTURE.md)

```mermaid
flowchart TB
  users([Users]) --> r53[Route 53]
  r53 --> alb["ALB (HTTPS / ACM)"]
  alb -->|"/health checks"| svc
  subgraph vpc["VPC (2 AZ)"]
    subgraph pub[Public subnets]
      alb
    end
    subgraph priv[Private subnets]
      svc["ECS Fargate — web (N tasks)<br/>target-tracking autoscaling<br/>image from ECR"]
      rds[("RDS MySQL / Aurora Serverless v2")]
      redis[("ElastiCache Redis<br/>(sessions at high scale)")]
      svc --> rds
      svc -. optional .-> redis
    end
  end
  svc --> sm[[Secrets Manager]]
  cf[CloudFront] --> s3assets[[S3 assets]]
  users -.-> cf
  svc --> cw[[CloudWatch logs/alarms]]
```

The app is already scale-ready for this: `SESSION_DRIVER=db` (or Redis) removes
the sticky-session requirement, `/health` drives ALB target health,
`scripts/migrate.php` runs as a one-off task against RDS, secrets come from
`.env` (→ Secrets Manager), and `client_ip()`/`REQUEST_HTTPS` already read the
forwarded headers. The remaining hard problem — TLS for Enterprise custom
domains behind a load balancer — and the cost thresholds for graduating are
covered in `docs/AWS-SCALE-ARCHITECTURE.md`.
