# Segredos que só este serviço lê (docs/fase-4/CONTRATOS.md §3, adendo F4-1, no oficina-api).

locals {
  db_name     = "oficina_faturamento"
  db_username = "faturamento_user"
}

resource "random_password" "db" {
  length  = 32
  special = true
  # Senha mestra do RDS não aceita / @ " nem espaço.
  override_special = "!#$%&*()-_=+[]{}<>:?"
}

resource "aws_secretsmanager_secret" "db" {
  name                    = "oficina/${var.environment}/faturamento/db"
  description             = "Credenciais MySQL do servico de Faturamento."
  recovery_window_in_days = var.secret_recovery_window_days

  tags = { Name = "oficina/${var.environment}/faturamento/db" }
}

resource "aws_secretsmanager_secret_version" "db" {
  secret_id = aws_secretsmanager_secret.db.id

  secret_string = jsonencode({
    host     = aws_db_instance.main.address
    port     = 3306
    dbname   = local.db_name
    username = local.db_username
    password = random_password.db.result
  })
}

# O Terraform cria o segredo com valor provisório. O valor real (credenciais de teste em hml)
# é gravado à mão pelo dono da conta; ignore_changes impede que um apply o sobrescreva.
# Nenhum pipeline nem agente lê este valor.
resource "aws_secretsmanager_secret" "mercadopago" {
  name                    = "oficina/${var.environment}/faturamento/mercadopago"
  description             = "Access token e segredo do webhook do Mercado Pago. Valor gravado a mao."
  recovery_window_in_days = var.secret_recovery_window_days

  tags = { Name = "oficina/${var.environment}/faturamento/mercadopago" }
}

resource "aws_secretsmanager_secret_version" "mercadopago" {
  secret_id = aws_secretsmanager_secret.mercadopago.id

  secret_string = jsonencode({
    MP_ACCESS_TOKEN   = "PREENCHER"
    MP_WEBHOOK_SECRET = "PREENCHER"
  })

  lifecycle {
    ignore_changes = [secret_string]
  }
}
