# Snip — scale stack SKELETON. Validated (terraform validate) but intentionally
# NOT a full apply-ready deployment. It ships the two pieces you need on day one
# of graduating (a container registry and a secrets container) plus an outline of
# the modules to add. Flesh out the commented sections when you scale — see
# docs/AWS-SCALE-ARCHITECTURE.md.

# --- ECR: push the immutable web image here (built in CI) -------------------
resource "aws_ecr_repository" "web" {
  name                 = "${var.name}-web"
  image_tag_mutability = "IMMUTABLE"
  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_ecr_lifecycle_policy" "web" {
  repository = aws_ecr_repository.web.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "keep last 10 images"
      selection    = { tagStatus = "any", countType = "imageCountMoreThan", countNumber = 10 }
      action       = { type = "expire" }
    }]
  })
}

# --- Secrets Manager: the app's .env, injected into the task definition ------
# Create the container empty here; put the real JSON in out-of-band so secrets
# never enter Terraform state.
resource "aws_secretsmanager_secret" "app" {
  name        = "${var.name}/app-env"
  description = "Snip application environment (.env) for the ECS task definition"
}

resource "aws_secretsmanager_secret_version" "app_placeholder" {
  secret_id     = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({ PLACEHOLDER = "set real values with `aws secretsmanager put-secret-value`" })
  lifecycle {
    ignore_changes = [secret_string]
  }
}

output "ecr_repository_url" {
  value = aws_ecr_repository.web.repository_url
}

output "app_secret_arn" {
  value = aws_secretsmanager_secret.app.arn
}

# ---------------------------------------------------------------------------
# MODULE OUTLINE — add these when you graduate (each is a small module or file):
#
# network.tf   VPC (2 AZ), public + private subnets. Avoid a NAT gateway (~$32/mo)
#              by using VPC gateway/interface endpoints for S3/ECR/Secrets/Logs,
#              or place Fargate tasks in public subnets locked down by SG.
#
# alb.tf       aws_lb (application) + listener :443 with an ACM cert (var.domain),
#              target group (ip), health_check { path = "/health" }. HTTP→HTTPS
#              redirect on :80.
#
# ecs.tf       aws_ecs_cluster + aws_ecs_service (launch_type FARGATE,
#              desired_count = var.web_min_tasks) + aws_ecs_task_definition
#              (image = aws_ecr_repository.web:<tag>, cpu/memory = var.web_*,
#              secrets from aws_secretsmanager_secret.app, awslogs driver,
#              env SESSION_DRIVER=db + TRUSTED_PROXIES=<alb/cf ranges>).
#              app_autoscaling_target + policy (target-tracking on
#              ALBRequestCountPerTarget, plus a CPU policy). A one-off task /
#              CodeBuild step runs `php scripts/migrate.php` on deploy.
#
# data.tf      RDS MySQL (var.db_instance_class, Multi-AZ optional, automated
#              backups + PITR) in private subnets, SG allowing 3306 only from the
#              ECS task SG. Swap to aws_rds_cluster (Aurora Serverless v2,
#              serverlessv2_scaling_configuration) when var.db_engine says so.
#
# edge.tf      (optional) S3 assets bucket + CloudFront for /assets/*.
#              Custom-domain TLS: a shared-storage Caddy tier OR ACM+CloudFront —
#              see docs/AWS-SCALE-ARCHITECTURE.md ("the genuinely hard part").
# ---------------------------------------------------------------------------
