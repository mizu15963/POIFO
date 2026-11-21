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
  description = "micro_3_0 = 1GB メモリ・2vCPU・SSD 40GB（$7/月）。DB はマネージドデータベースに分けたので 1GB。変えると作り直しになるので、1台目のサイズ変更は api_a_bundle_id / api_b_bundle_id で入れ替える"
  type        = string
  default     = "micro_3_0"
}

variable "lightsail_blueprint_id" {
  description = "OS イメージ。変更するとサーバが作り直しになるので注意"
  type        = string
  default     = "ubuntu_24_04"
}

variable "api_instance_count" {
  description = "API サーバの台数。2台以上にするときは enable_load_balancer = true にする。GitHub Variables: API_SERVER_COUNT（デプロイもこの台数分の結果を待つ）"
  type        = number
  default     = 1

  validation {
    condition     = var.api_instance_count >= 1 && var.api_instance_count <= 5
    error_message = "api_instance_count は 1〜5 にしてください。"
  }
}

variable "api_active_slot" {
  description = "1台目のサーバのうち、固定 IP を付けて使う枠（a / b）。入れ替えのときに切り替える。GitHub Variables: API_ACTIVE_SLOT"
  type        = string
  default     = "a"

  validation {
    condition     = contains(["a", "b"], var.api_active_slot)
    error_message = "api_active_slot は a か b にしてください。"
  }
}

variable "api_standby" {
  description = "true で、使っていない方の枠に入れ替え用の新しいサーバを作る。入れ替えが済んだら false に戻すと古い方が消える。GitHub Variables: API_STANDBY"
  type        = bool
  default     = false
}

variable "api_a_bundle_id" {
  description = "枠 a のサーバのプラン。空なら lightsail_bundle_id。入れ替えでサイズを変えるときに使う。GitHub Variables: API_A_BUNDLE"
  type        = string
  default     = ""
}

variable "api_b_bundle_id" {
  description = "枠 b のサーバのプラン。空なら lightsail_bundle_id。GitHub Variables: API_B_BUNDLE"
  type        = string
  default     = ""
}

variable "api_a_blueprint_id" {
  description = "枠 a のサーバの OS イメージ。空なら lightsail_blueprint_id。入れ替えで OS を上げるときに使う。GitHub Variables: API_A_BLUEPRINT"
  type        = string
  default     = ""
}

variable "api_b_blueprint_id" {
  description = "枠 b のサーバの OS イメージ。空なら lightsail_blueprint_id。GitHub Variables: API_B_BLUEPRINT"
  type        = string
  default     = ""
}

variable "enable_load_balancer" {
  description = "true で API サーバの前に Lightsail ロードバランサー（$18/月、HTTPS 終端）を置く。独自ドメインが必要。GitHub Variables: ENABLE_LOAD_BALANCER"
  type        = bool
  default     = false
}

variable "lb_health_check_path" {
  description = "ロードバランサーのヘルスチェック先（秘密ヘッダーなしで 200 を返すパス。nginx で許可する）"
  type        = string
  default     = "/up"
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

variable "auth_path_prefix" {
  description = "ログイン関連のパスの先頭（WAF の厳しい回数制限の対象）"
  type        = string
  default     = "/auth/"
}

variable "waf_auth_rate_limit" {
  description = "同一 IP から 5 分間に許すログイン関連（auth_path_prefix 配下）のリクエスト数。超えるとブロック"
  type        = number
  default     = 50

  validation {
    condition     = var.waf_auth_rate_limit >= 10
    error_message = "WAF のレート制限は 10 以上にしてください。"
  }
}

variable "waf_rate_limit" {
  description = "同一 IP から 5 分間に許すリクエスト数。超えるとブロック"
  type        = number
  default     = 1000
}
