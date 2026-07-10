# ---------------------------------------------------------------------------
# Networking: security group (80/443 public, 22 optional, 3306 never)
# ---------------------------------------------------------------------------
resource "aws_security_group" "web" {
  name_prefix = "${var.name}-web-"
  description = "Snip web: 80/443 public, SSH optional, MySQL never exposed"
  vpc_id      = data.aws_vpc.default.id

  ingress {
    description      = "HTTP"
    from_port        = 80
    to_port          = 80
    protocol         = "tcp"
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }
  ingress {
    description      = "HTTPS"
    from_port        = 443
    to_port          = 443
    protocol         = "tcp"
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }
  dynamic "ingress" {
    for_each = var.ssh_cidr == "" ? [] : [var.ssh_cidr]
    content {
      description = "SSH"
      from_port   = 22
      to_port     = 22
      protocol    = "tcp"
      cidr_blocks = [ingress.value]
    }
  }
  egress {
    description      = "all outbound"
    from_port        = 0
    to_port          = 0
    protocol         = "-1"
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }
  lifecycle {
    create_before_destroy = true
  }
}

# ---------------------------------------------------------------------------
# IAM: instance role — SSM Session Manager + S3 backups + read app secrets
# ---------------------------------------------------------------------------
data "aws_iam_policy_document" "assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "instance" {
  name               = "${var.name}-instance"
  assume_role_policy = data.aws_iam_policy_document.assume.json
}

# Lets you open a shell via `aws ssm start-session` with port 22 closed.
resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.instance.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

data "aws_iam_policy_document" "app" {
  statement {
    sid       = "Backups"
    actions   = ["s3:PutObject", "s3:GetObject", "s3:ListBucket", "s3:DeleteObject"]
    resources = [aws_s3_bucket.backups.arn, "${aws_s3_bucket.backups.arn}/*"]
  }
  statement {
    sid       = "ReadAppSecrets"
    actions   = ["ssm:GetParameter", "ssm:GetParametersByPath"]
    resources = ["arn:aws:ssm:${var.region}:${data.aws_caller_identity.current.account_id}:parameter/${var.name}/*"]
  }
}

resource "aws_iam_role_policy" "app" {
  name   = "${var.name}-app"
  role   = aws_iam_role.instance.id
  policy = data.aws_iam_policy_document.app.json
}

resource "aws_iam_instance_profile" "instance" {
  name = "${var.name}-instance"
  role = aws_iam_role.instance.name
}

# ---------------------------------------------------------------------------
# Compute: the single instance + Elastic IP
# ---------------------------------------------------------------------------
locals {
  user_data = templatefile("${path.module}/user_data.sh.tftpl", {
    name          = var.name
    region        = var.region
    backup_bucket = var.backup_bucket_name
    repo_url      = var.repo_url
  })
}

resource "aws_instance" "web" {
  ami                         = data.aws_ssm_parameter.al2023_arm64.value
  instance_type               = var.instance_type
  subnet_id                   = element(tolist(data.aws_subnets.default.ids), 0)
  vpc_security_group_ids      = [aws_security_group.web.id]
  iam_instance_profile        = aws_iam_instance_profile.instance.name
  key_name                    = var.key_name == "" ? null : var.key_name
  user_data                   = local.user_data
  user_data_replace_on_change = true

  root_block_device {
    volume_type = "gp3"
    volume_size = var.root_volume_gb
    encrypted   = true
    tags        = { Name = "${var.name}-root" }
  }

  metadata_options {
    http_endpoint = "enabled"
    http_tokens   = "required" # IMDSv2 only
  }

  tags = {
    Name     = "${var.name}-web"
    snapshot = "daily"
  }
}

resource "aws_eip" "web" {
  instance = aws_instance.web.id
  domain   = "vpc"
  tags     = { Name = "${var.name}-eip" }
}

# ---------------------------------------------------------------------------
# Storage: S3 backup bucket (private, versioned, encrypted, lifecycle-expired)
# ---------------------------------------------------------------------------
resource "aws_s3_bucket" "backups" {
  bucket = var.backup_bucket_name
}

resource "aws_s3_bucket_public_access_block" "backups" {
  bucket                  = aws_s3_bucket.backups.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "backups" {
  bucket = aws_s3_bucket.backups.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id
  rule {
    id     = "expire-old-backups"
    status = "Enabled"
    filter {}
    expiration {
      days = 90
    }
    noncurrent_version_expiration {
      noncurrent_days = 30
    }
  }
}

# ---------------------------------------------------------------------------
# Backups: daily EBS snapshot of the data volume (Data Lifecycle Manager)
# ---------------------------------------------------------------------------
data "aws_iam_policy_document" "dlm_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["dlm.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "dlm" {
  name               = "${var.name}-dlm"
  assume_role_policy = data.aws_iam_policy_document.dlm_assume.json
}

resource "aws_iam_role_policy_attachment" "dlm" {
  role       = aws_iam_role.dlm.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSDataLifecycleManagerServiceRole"
}

resource "aws_dlm_lifecycle_policy" "daily" {
  description        = "${var.name} daily EBS snapshots"
  execution_role_arn = aws_iam_role.dlm.arn
  state              = "ENABLED"

  policy_details {
    resource_types = ["VOLUME"]
    target_tags    = { Name = "${var.name}-root" }
    schedule {
      name = "daily-7d"
      create_rule {
        interval      = 24
        interval_unit = "HOURS"
        times         = ["04:00"]
      }
      retain_rule {
        count = 7
      }
    }
  }
}

# ---------------------------------------------------------------------------
# Secrets: SSM Parameter Store SecureString placeholders for the app's .env.
# Created empty ("CHANGEME") and then ignored — fill real values with
# `aws ssm put-parameter --overwrite` so secrets never live in Terraform state.
# user_data pulls these into /opt/<name>/.env on boot.
# ---------------------------------------------------------------------------
locals {
  secret_params = [
    "SITE_DOMAIN", "SITE_HOST", "DB_PASSWORD", "DB_ROOT_PASSWORD",
    "STRIPE_SECRET_KEY", "STRIPE_PUBLISHABLE_KEY", "STRIPE_WEBHOOK_SECRET",
    "RECAPTCHA_SITE_KEY", "RECAPTCHA_SECRET", "IPQS_API_KEY", "ADMIN_EMAILS",
    "MAIL_FROM", "SESSION_DRIVER",
  ]
}

resource "aws_ssm_parameter" "app" {
  for_each = toset(local.secret_params)
  name     = "/${var.name}/${each.value}"
  type     = "SecureString"
  value    = "CHANGEME"
  lifecycle {
    ignore_changes = [value]
  }
}

# ---------------------------------------------------------------------------
# DNS (optional): A record → EIP, only if a hosted zone id is supplied
# ---------------------------------------------------------------------------
resource "aws_route53_record" "a" {
  count   = var.route53_zone_id == "" ? 0 : 1
  zone_id = var.route53_zone_id
  name    = var.domain
  type    = "A"
  ttl     = 300
  records = [aws_eip.web.public_ip]
}
