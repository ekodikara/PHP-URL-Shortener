# Terraform — Snip scale stack (skeleton)

The autoscaling target (ALB → ECS Fargate → RDS/Aurora), documented in
[`../../docs/AWS-SCALE-ARCHITECTURE.md`](../../docs/AWS-SCALE-ARCHITECTURE.md).

**Status: skeleton, not apply-complete.** It provisions the two things you need
first — an **ECR** repository (for the immutable web image) and a **Secrets
Manager** container (for the app `.env`) — and documents, as a commented outline
in `main.tf`, the VPC / ALB / ECS / RDS modules to add when you graduate. This
keeps the stack `terraform validate`-clean without pretending to be a finished
production deployment you haven't reviewed.

## Validate (no AWS account needed)
```bash
terraform init -backend=false && terraform validate
# or: docker run --rm -v "$PWD":/w -w /w hashicorp/terraform:1.9 validate
```

## Graduating — order of operations
1. Build + push the web image to the ECR repo (output `ecr_repository_url`); the
   `Dockerfile` already bakes in `vendor/`.
2. Put real values into the app secret (`app_secret_arn`) with
   `aws secretsmanager put-secret-value` — include `SESSION_DRIVER=db`.
3. Fill in `network.tf` / `alb.tf` / `ecs.tf` / `data.tf` per the outline in
   `main.tf`, then `terraform apply`.
4. Migrate data (single box → RDS) and run `scripts/migrate.php` as a one-off
   task. Flip DNS to the ALB. See the scale doc's migration section.

The app is already multi-instance-ready: `SESSION_DRIVER=db` shares sessions via
the DB, `/health` drives ALB checks, and `client_ip()`/`REQUEST_HTTPS` honor the
forwarded headers.
