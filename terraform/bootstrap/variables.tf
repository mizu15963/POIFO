variable "project" {
  description = "リソース名・タグの接頭辞（英小文字とハイフン）"
  type        = string
  default     = "poifo"
}

variable "region" {
  type    = string
  default = "ap-northeast-1"
}

variable "github_repo" {
  description = "<OWNER>/<REPO>"
  type        = string
}

variable "github_owner_id" {
  description = "オーナーの数値ID。gh api repos/<OWNER>/<REPO> --jq .owner.id。空なら従来形式の sub"
  type        = string
  default     = ""
}

variable "github_repo_id" {
  description = "リポジトリの数値ID。gh api repos/<OWNER>/<REPO> --jq .id"
  type        = string
  default     = ""
}

variable "apply_environment" {
  description = "apply/destroy ジョブの GitHub Environment 名（Required reviewers を設定）"
  type        = string
  default     = "poifo-apply"
}

variable "create_github_oidc_provider" {
  description = "OIDC プロバイダを作るか。アカウントに既にあれば false（1アカウント1つ）"
  type        = bool
  default     = true
}

variable "apply_regional_actions" {
  description = "apply ロールに許可するサービスのアクション（東京と us-east-1 に限定される）"
  type        = list(string)
  default = [
    "lightsail:*",
    "cloudfront:*",
    "wafv2:*",
    "acm:*",
    "route53:*",
    "sts:GetCallerIdentity",
  ]
}

variable "service_linked_role_services" {
  description = "初回利用時にサービスリンクロールの自動作成を許可するサービス"
  type        = list(string)
  default     = ["wafv2.amazonaws.com"]
}
