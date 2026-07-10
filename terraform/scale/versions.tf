terraform {
  required_version = ">= 1.5"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
  # backend "s3" { ... }  # use a remote backend once you graduate to this stack
}

provider "aws" {
  region = var.region
  default_tags {
    tags = {
      Project   = var.name
      Env       = "scale"
      ManagedBy = "terraform"
    }
  }
}
