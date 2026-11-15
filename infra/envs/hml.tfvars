environment = "hml"
aws_region  = "us-east-1"

# Menor custo em hml: sem backup automatico, sem Multi-AZ, segredo apagado na hora.
db_instance_class           = "db.t4g.micro"
db_allocated_storage        = 20
db_backup_retention_days    = 0
db_multi_az                 = false
secret_recovery_window_days = 0
