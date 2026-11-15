resource "aws_ecr_repository" "service" {
  name                 = local.service_name
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }

  # O repositório é por conta, não por ambiente: hml e prod publicam nele com tags diferentes.
  # Por isso só o ambiente hml o cria; prod lê o que já existe (count abaixo).
  count = var.environment == "hml" ? 1 : 0
}

data "aws_ecr_repository" "service" {
  count = var.environment == "hml" ? 0 : 1
  name  = local.service_name
}

locals {
  ecr_repository_url = var.environment == "hml" ? aws_ecr_repository.service[0].repository_url : data.aws_ecr_repository.service[0].repository_url
}

resource "aws_ecr_lifecycle_policy" "service" {
  count      = var.environment == "hml" ? 1 : 0
  repository = aws_ecr_repository.service[0].name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Guarda as ultimas ${var.ecr_image_retention_count} imagens"
        selection = {
          tagStatus   = "any"
          countType   = "imageCountMoreThan"
          countNumber = var.ecr_image_retention_count
        }
        action = { type = "expire" }
      }
    ]
  })
}
