variable "environment" {
  description = "Ambiente: hml ou prod."
  type        = string

  validation {
    condition     = contains(["hml", "prod"], var.environment)
    error_message = "environment deve ser hml ou prod."
  }
}

variable "aws_region" {
  description = "Região AWS."
  type        = string
  default     = "us-east-1"
}

variable "ecr_image_retention_count" {
  description = "Quantas imagens o ECR guarda."
  type        = number
  default     = 10
}

variable "queue_max_receive_count" {
  description = "Tentativas antes de a mensagem ir para a DLQ (docs/fase-4/CONTRATOS.md §5)."
  type        = number
  default     = 5
}

variable "db_instance_class" {
  description = "Classe da instância RDS."
  type        = string
  default     = "db.t4g.micro"
}

variable "db_allocated_storage" {
  description = "Armazenamento do RDS, em GB."
  type        = number
  default     = 20
}

variable "db_backup_retention_days" {
  description = "Dias de backup automático. 0 desliga (hml)."
  type        = number
  default     = 0
}

variable "db_multi_az" {
  description = "Multi-AZ no RDS."
  type        = bool
  default     = false
}

variable "secret_recovery_window_days" {
  description = "Janela de recuperação dos segredos. 0 apaga na hora (hml)."
  type        = number
  default     = 0
}
