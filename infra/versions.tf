terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  # Backend parametrizado via -backend-config no `terraform init` (ver deploy.yml).
  # key: oficina/oficina-faturamento/<env>/terraform.tfstate
  backend "s3" {}
}
