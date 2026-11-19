variable "project" {
  type = string
}

variable "region" {
  type    = string
  default = "ap-northeast-1"
}

# ---------------- ドメイン ----------------
variable "domain_name" {
  description = "サイトのドメイン。base スタックのホストゾーンと同じ値。GitHub Variables: DOMAIN_NAME"
  type        = string
  default     = ""
}

variable "enable_custom_domain" {
  description = <<-EOT
    true で独自ドメイン・証明書・API 用 CloudFront・WAF を作る。
    base でホストゾーンを作り、ドメイン側でネームサーバを委任してから true にする。GitHub Variables: ENABLE_CUSTOM_DOMAIN
  EOT
  type        = bool
  default     = false
}

# ---------------- Lightsail ----------------
variable "availability_zone" {
  type    = string
  default = "ap-northeast-1a"
}

variable "lightsail_bundle_id" {
  description = "micro_3_0 = 1GB メモリ・2vCPU・SSD 40GB（$7/月）。DB はマネージドデータベースに分けたので 1GB。変更するとサーバが作り直しになるので注意"
  type        = string
  default     = "micro_3_0"
}

variable "lightsail_blueprint_id" {
  description = "OS イメージ。変更するとサーバが作り直しになるので注意"
  type        = string
  default     = "ubuntu_24_04"
}

variable "auto_snapshot_time" {
  description = "自動スナップショットの時刻（UTC、HH:00）。18:00 UTC = 3:00 JST"
  type        = string
  default     = "18:00"
}

variable "ssh_public_key" {
  description = "SSH 公開鍵（ssh-ed25519 ...）。空なら Lightsail のデフォルト鍵を使う。GitHub Variables: SSH_PUBLIC_KEY"
  type        = string
  default     = ""
}

variable "ssh_allowed_cidrs" {
  description = "SSH(22) を許可する運用者の IP（例: [\"203.0.113.10/32\"]）。Lightsail コンソールのブラウザ SSH は別途常に許可。GitHub Secrets: SSH_ALLOWED_CIDRS"
  type        = list(string)
  default     = []
  sensitive   = true
}

# ---------------- API 入口（CloudFront → Lightsail） ----------------
variable "origin_verify_secret" {
  description = "CloudFront からオリジンに付ける秘密ヘッダー X-Origin-Verify の値。nginx にも同じ値を設定する。GitHub Secrets: ORIGIN_VERIFY_SECRET"
  type        = string
  default     = ""
  sensitive   = true
}

# ---------------- WAF ----------------
variable "admin_allowed_cidrs" {
  description = "admin. へのアクセスを許可する IP。空ならルールを作らない（二要素認証とレート制限で守る）。GitHub Secrets: ADMIN_ALLOWED_CIDRS"
  type        = list(string)
  default     = []
  sensitive   = true
}

variable "postback_allowed_cidrs" {
  description = "ポストバック受信パスを許可する ASP の送信元 IP。空ならルールを作らない。GitHub Secrets: POSTBACK_ALLOWED_CIDRS"
  type        = list(string)
  default     = []
  sensitive   = true
}

variable "postback_path_prefix" {
  description = "ポストバック受信パスの先頭"
  type        = string
  default     = "/postback/"
}

variable "waf_managed_rules_count_mode" {
  description = "true の間、AWS マネージドルールは検知（カウント）のみでブロックしない。ポストバックの誤検知がないことを確かめてから false にする。GitHub Variables: WAF_COUNT_MODE"
  type        = bool
  default     = true
}

variable "waf_rate_limit" {
  description = "同一 IP から 5 分間に許すリクエスト数。超えるとブロック"
  type        = number
  default     = 1000
}
