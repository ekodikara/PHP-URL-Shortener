# Use the account's default VPC + subnets — no NAT gateway / extra AZ cost, which
# is exactly right for a single cheap box. (The scale stack builds a real VPC.)
data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

# Latest Amazon Linux 2023 arm64 AMI, resolved from the public SSM parameter so
# it's never a hard-coded, stale AMI id.
data "aws_ssm_parameter" "al2023_arm64" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64"
}

data "aws_caller_identity" "current" {}
