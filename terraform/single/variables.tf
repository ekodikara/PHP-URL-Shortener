variable "region" {
  description = "AWS region"
  type        = string
  default     = "us-east-1"
}

variable "name" {
  description = "Name/prefix for resources and the /<name>/ SSM secret path"
  type        = string
  default     = "snip"
}

variable "instance_type" {
  description = "EC2 instance type (arm64/Graviton). t4g.micro is free-tier-ish; t4g.small has headroom for MySQL+PHP+Caddy."
  type        = string
  default     = "t4g.small"
}

variable "root_volume_gb" {
  description = "Root gp3 EBS size (holds the OS, images, and the MySQL data volume)"
  type        = number
  default     = 20
}

variable "ssh_cidr" {
  description = "CIDR allowed to reach port 22. Leave empty to keep SSH closed and use SSM Session Manager instead (recommended)."
  type        = string
  default     = ""
}

variable "key_name" {
  description = "Existing EC2 key pair name for SSH (optional; SSM works without a key)"
  type        = string
  default     = ""
}

variable "domain" {
  description = "Public hostname (e.g. sho.rt). Only used for the optional Route 53 record."
  type        = string
  default     = ""
}

variable "route53_zone_id" {
  description = "Route 53 hosted zone id. Empty = don't manage DNS here (point an A record at the EIP yourself)."
  type        = string
  default     = ""
}

variable "backup_bucket_name" {
  description = "Globally-unique S3 bucket name for nightly DB dumps"
  type        = string
}

variable "repo_url" {
  description = "Git URL the instance clones on first boot to get docker-compose.prod.yml + code"
  type        = string
  default     = ""
}
