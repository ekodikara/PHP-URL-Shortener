variable "region" {
  type    = string
  default = "us-east-1"
}

variable "name" {
  type    = string
  default = "snip"
}

# --- knobs for the modules you'll flesh out (see main.tf outline) ---

variable "web_min_tasks" {
  description = "Fargate service minimum task count (autoscaling floor)"
  type        = number
  default     = 1
}

variable "web_max_tasks" {
  description = "Fargate service maximum task count (autoscaling ceiling)"
  type        = number
  default     = 6
}

variable "web_cpu" {
  description = "Fargate task CPU units (256 = 0.25 vCPU)"
  type        = number
  default     = 512
}

variable "web_memory" {
  description = "Fargate task memory (MiB)"
  type        = number
  default     = 1024
}

variable "db_engine" {
  description = "rds-mysql (fixed, cheap) or aurora-serverless-v2 (autoscaling DB)"
  type        = string
  default     = "rds-mysql"
}

variable "db_instance_class" {
  description = "RDS instance class when db_engine = rds-mysql"
  type        = string
  default     = "db.t4g.micro"
}
