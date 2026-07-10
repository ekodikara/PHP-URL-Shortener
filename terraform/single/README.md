# Terraform — Snip single-box (cost-minimized)

Provisions the current Docker Compose stack (Caddy → PHP/Apache → MySQL) on **one**
Amazon Linux 2023 arm64 instance in the default VPC, with an Elastic IP, a private
S3 backup bucket, daily EBS snapshots, and app secrets in SSM Parameter Store.
No ALB, RDS, NAT, or Fargate — those are the parts that cost money.

**Cost:** ~$6–16/mo (t4g.micro↔small on-demand + gp3 + optional Route 53 zone
$0.50 + S3 pennies; EIP is free while attached; cheaper with a Savings Plan).

## Prerequisites
- An AWS account + credentials (`aws configure` or SSO). **Nothing here runs
  without you explicitly applying it.**
- Terraform ≥ 1.5 (or the `hashicorp/terraform` Docker image).
- A globally-unique S3 bucket name for `backup_bucket_name`.

## Use
```bash
cp terraform.tfvars.example terraform.tfvars   # edit values
terraform init
terraform plan          # review — creates ~15 resources
terraform apply

# fill each secret (values never touch Terraform state):
aws ssm put-parameter --overwrite --type SecureString --name /snip/DB_PASSWORD --value '…'
aws ssm put-parameter --overwrite --type SecureString --name /snip/SITE_HOST   --value 'https://sho.rt/'
# … repeat for STRIPE_*, RECAPTCHA_*, SITE_DOMAIN, ADMIN_EMAILS, etc.

# point DNS at the EIP (or set route53_zone_id), then re-run cloud-init or reboot
# so user_data re-pulls secrets and brings the stack up.
```

## Validate without an AWS account
```bash
terraform init -backend=false && terraform validate && terraform fmt -check
# or via Docker:
docker run --rm -v "$PWD":/w -w /w hashicorp/terraform:1.9 init -backend=false
docker run --rm -v "$PWD":/w -w /w hashicorp/terraform:1.9 validate
```

## Notes
- **SSH is optional** — leave `ssh_cidr` empty and use
  `aws ssm start-session --target <id>` (the `ssm_connect` output). Port 3306 is
  never opened.
- Secrets live in SSM SecureString under `/<name>/`; `user_data` writes them to
  `/opt/<name>/.env` on boot. Rotate by updating the parameter and rebooting.
- When traffic outgrows one box, see `../../docs/AWS-SCALE-ARCHITECTURE.md` and
  `../scale/`. The app is already multi-instance-ready via `SESSION_DRIVER=db`.
- **Even cheaper:** AWS Lightsail (flat ~$5–10/mo). Not templated here because it
  doesn't share a VPC with the scale stack; fine if you never plan to graduate.
