variable "project" {
  type = string
}

variable "region" {
  type    = string
  default = "ap-northeast-1"
}

variable "domain_name" {
  description = "サイトのドメイン（例: example.jp）。空ならホストゾーンを作らない。GitHub Variables: DOMAIN_NAME"
  type        = string
  default     = ""
}

variable "backup_retention_days" {
  description = "DB バックアップの保持日数"
  type        = number
  default     = 30
}
