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

# ---------------- DB（Lightsail マネージドデータベース） ----------------
variable "db_availability_zone" {
  type    = string
  default = "ap-northeast-1a"
}

variable "db_blueprint_id" {
  description = "mysql_8_4 = MySQL 8.4。一覧: aws lightsail get-relational-database-blueprints"
  type        = string
  default     = "mysql_8_4"
}

variable "db_bundle_id" {
  description = "micro_2_0 = 標準 1GB メモリ・SSD 40GB（$15/月）。一覧: aws lightsail get-relational-database-bundles"
  type        = string
  default     = "micro_2_0"
}

variable "db_name" {
  description = "最初に作るデータベース名"
  type        = string
  default     = "poifo"
}

variable "db_master_username" {
  type    = string
  default = "dbadmin"
}

variable "db_master_password" {
  description = "マスターユーザーのパスワード（16文字以上）。GitHub Secrets: DB_MASTER_PASSWORD。state にも保存される"
  type        = string
  default     = ""
  sensitive   = true
}
