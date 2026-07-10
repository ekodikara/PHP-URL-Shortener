# Snip — Scaling on AWS (the "flip on when users come" path)

This is the documented target for when the single box (`terraform/single/`,
`DEPLOY.md`) stops being enough. It is **not applied** — it's the blueprint plus
a Terraform skeleton (`terraform/scale/`) to grow into. The runtime diagram is
in [`ARCHITECTURE.md` §5](./ARCHITECTURE.md#5-scale-topology-documented-target).

## When to graduate (don't pay before you must)

Stay on one box until you hit a real signal:
- Sustained CPU/RAM pressure on the instance (e.g. >70% for hours), or
- p95 latency creeping up under load / MySQL contention, or
- You need **no-downtime deploys / HA** (one box = one point of failure), or
- MySQL working set outgrows the instance.

The single box comfortably serves low-thousands of redirects/day. Graduate the
**web tier first** (cheap, stateless), the **database last** (most expensive).

## Target architecture

```
Route 53 → ACM → ALB → ECS Fargate (web, N tasks, autoscaled) → RDS MySQL
                         │                                   ↘ ElastiCache (sessions, later)
                         ├ image ← ECR                        Secrets Manager (.env)
                         └ /health target checks              CloudWatch logs+alarms
S3 + CloudFront ← static assets
```

## Component decisions

| Concern | Choice | Why / cost |
|---|---|---|
| Compute | **ECS Fargate** service, `desiredCount` autoscaled | No servers to patch; scales in seconds. Cheapest serverless-container option; scale-to-1 (or scheduled 0 for non-prod). |
| Autoscaling | Target-tracking on **ALB RequestCountPerTarget** + CPU | Requests-per-target tracks real load better than CPU alone for PHP. min 1–2 / max N. |
| Database | **RDS MySQL** `db.t4g.micro/small` (Multi-AZ when HA matters) | Fixed, predictable ~$12–30/mo. Managed backups + PITR replace `backup.sh`. |
| Database (spiky) | **Aurora Serverless v2** (0.5–N ACU) | DB autoscales with load; higher floor (~$40+/mo at 0.5 ACU). Pick this only if traffic is bursty. |
| Sessions | `SESSION_DRIVER=db` now → **ElastiCache Redis** at high write volume | Already implemented (`inc/session.php`). Redis is the later optimization, not required to scale. |
| TLS (apex) | **ACM cert on the ALB** | Free certs, auto-renew; replaces Caddy for the main domain. |
| TLS (Enterprise custom domains) | **hardest problem** — see below | On-demand per-tenant certs don't fit a plain ALB. |
| Secrets | **Secrets Manager** → injected into the task definition | Replaces the `.env` file; rotation-capable. |
| Images | **ECR** (build in CI, immutable tag per deploy) | `vendor/` is already baked into the image. |
| Assets | **S3 + CloudFront** for `/assets/*` (+ vendor a local `qrcode.js`) | Offloads static bytes; optional at Snip's asset size. |
| Migrations | Run `scripts/migrate.php` as a **one-off ECS task** on deploy | RDS won't auto-load `schema.sql`; the migration runner is the mechanism. |
| Observability | CloudWatch logs (awslogs driver) + alarms; `/health` on the ALB | `/health` already returns 200/503 on a DB check. |

## App readiness (already done — nothing blocks the web tier)

- **Sessions** are the only local state; `SESSION_DRIVER=db` (migration 003) makes
  the fleet share sessions through the DB. No sticky sessions needed.
- **Rate limits, logs, usage counters, Stripe idempotency, auth tokens** are all
  already in MySQL → correct across instances.
- **`client_ip()` / `REQUEST_HTTPS`** already read `X-Forwarded-For` /
  `X-Forwarded-Proto`; set `TRUSTED_PROXIES` to the ALB/CloudFront ranges.
- **`/health`** is ready as the ALB health check target.

## The genuinely hard part: multi-tenant custom-domain TLS

Today Caddy issues per-customer certs **on demand** (gated by `/tls-check`) and
stores them on a local volume. Behind an ALB that model breaks (ALB needs certs
attached ahead of time; local cert storage isn't shared across tasks). Options,
in order of effort:
1. **Keep a small Caddy tier** in front of ECS for custom domains, configured
   with a **shared cert store** (Caddy's S3 or DynamoDB storage module) so all
   Caddy tasks share issued certs. ALB/ACM serves only the apex.
2. **ACM + CloudFront SNI** with a Lambda that requests/attaches a cert when a
   domain is verified (more moving parts; ACM issuance limits apply).
3. Defer: offer custom domains only via a CNAME to the apex without per-domain
   TLS until (1) is built.
Recommend (1) — it reuses the existing, working Caddy on-demand logic.

## Data migration (single box → RDS), zero-ish downtime

1. Create RDS, run `scripts/migrate.php` against it (fresh schema + migrations).
2. `mysqldump` the live box → import into RDS (or use DMS for near-live cutover).
3. Point the app's DB env at RDS; deploy the Fargate service; flip DNS to the ALB.
4. Decommission the box once traffic has drained.

## Cost sketch (order-of-magnitude, us-east-1)

| Topology | ~Monthly | Scales? |
|---|---|---|
| Single box (`terraform/single`) | **$6–16** | vertically only |
| Balanced: Fargate (1–2 tasks) + ALB + RDS t4g.micro | **~$45–70** | web auto; DB manual |
| Scale-ready: Fargate + ALB + Aurora Serverless v2 | **~$90–150+** | web + DB auto |

(ALB ≈ $16/mo fixed is the main step-up; Fargate ≈ $10–15/task; NAT gateway ≈
$32/mo — avoid by putting tasks in public subnets with SGs, or use a VPC endpoint
for ECR/Secrets, if squeezing cost.)

## What's in `terraform/scale/`

A validated skeleton to grow into — provider + variables + starter resources
(ECR repository, Secrets Manager secret) and a commented outline of the
VPC / ALB / ECS / RDS modules to add. It is intentionally **not** wired for a
full `apply` yet; flesh out the commented sections when you graduate. See
`terraform/scale/README.md`.
