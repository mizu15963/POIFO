terraform {
  required_version = ">= 1.10.0" # use_lockfile のため

  # bucket / key / region は init 時に -backend-config で渡す
  backend "s3" {}

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

provider "aws" {
  region = var.region
  default_tags {
    tags = {
      Project   = var.project
      ManagedBy = "terraform"
      Stack     = "app"
    }
  }
}

# CloudFront 用の ACM 証明書と WAF（CLOUDFRONT スコープ）は us-east-1 に作る決まり
provider "aws" {
  alias  = "us_east_1"
  region = "us-east-1"
  default_tags {
    tags = {
      Project   = var.project
      ManagedBy = "terraform"
      Stack     = "app"
    }
  }
}
