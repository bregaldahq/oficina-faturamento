# Banco próprio do serviço (ADR-014): instância separada da do serviço de OS, com SG e
# credencial próprios. Fica nas subnets privadas da fundação; só os nodes do EKS alcançam.

resource "aws_db_subnet_group" "main" {
  name       = "${local.name_prefix}-${local.service}"
  subnet_ids = split(",", data.aws_ssm_parameter.private_subnet_ids.value)

  tags = { Name = "${local.name_prefix}-${local.service}" }
}

resource "aws_security_group" "db" {
  name        = "${local.name_prefix}-${local.service}-db"
  description = "RDS MySQL do ${local.service_name}. Entrada 3306 so dos nodes do EKS."
  vpc_id      = data.aws_ssm_parameter.vpc_id.value

  tags = { Name = "${local.name_prefix}-${local.service}-db" }
}

resource "aws_vpc_security_group_ingress_rule" "db_from_nodes" {
  security_group_id            = aws_security_group.db.id
  referenced_security_group_id = data.aws_ssm_parameter.node_security_group_id.value
  from_port                    = 3306
  to_port                      = 3306
  ip_protocol                  = "tcp"
  description                  = "MySQL a partir dos nodes do EKS"
}

resource "aws_db_instance" "main" {
  identifier     = "${local.name_prefix}-${local.service}"
  engine         = "mysql"
  engine_version = "8.0"
  instance_class = var.db_instance_class

  allocated_storage = var.db_allocated_storage
  storage_type      = "gp3"
  storage_encrypted = true

  db_name  = local.db_name
  username = local.db_username
  password = random_password.db.result

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = false
  multi_az               = var.db_multi_az

  backup_retention_period   = var.db_backup_retention_days
  deletion_protection       = var.environment == "prod"
  skip_final_snapshot       = var.environment != "prod"
  final_snapshot_identifier = var.environment == "prod" ? "${local.name_prefix}-${local.service}-final" : null

  auto_minor_version_upgrade = true
  apply_immediately          = var.environment != "prod"

  tags = { Name = "${local.name_prefix}-${local.service}" }
}
