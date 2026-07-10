terraform {
  required_version = ">= 1.5"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }

  # Remote state (recommended once more than one person runs this). Create the
  # bucket + lock table first, then uncomment and `terraform init -migrate-state`.
  # Left commented so `terraform init -backend=false` works with no AWS account.
  # backend "s3" {
  #   bucket         = "my-tf-state"
  #   key            = "snip/single/terraform.tfstate"
  #   region         = "us-east-1"
  #   dynamodb_table = "my-tf-locks"
  #   encrypt        = true
  # }
}

provider "aws" {
  region = var.region
  default_tags {
    tags = {
      Project   = var.name
      Env       = "single"
      ManagedBy = "terraform"
    }
  }
}
