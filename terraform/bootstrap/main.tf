# bootstrap: 最初に1回だけ、手元PCから管理者（IAM Identity Center のプロファイル）で apply する。
#   - Terraform state 用 S3 バケット（ロックは use_lockfile。DynamoDB 不要）
#   - GitHub Actions の OIDC プロバイダ（アカウントに1つだけ。既にあれば作らず参照）
#   - Actions 用 IAM ロール
#       plan  : 読み取り専用。main ブランチと PR（同じリポジトリのブランチ）から使える
#       apply : Environment（承認者あり）を通ったジョブだけが使える
#       deploy: アプリのデプロイ用。Environment「poifo-deploy」のジョブだけが使える（S3 への配置と CloudFront のキャッシュ削除のみ）
# この state は手元PC（.gitignore 済み）。state を置くバケットを作るスタックの state はそこに置けないため。
terraform {
  required_version = ">= 1.10.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

provider "aws" {
  region = var.region
  default_tags {
    tags = {
      Project   = var.project
      ManagedBy = "terraform"
      Stack     = "bootstrap"
    }
  }
}

data "aws_caller_identity" "current" {}

locals {
  account_id   = data.aws_caller_identity.current.account_id
  state_bucket = "${var.project}-tfstate-${local.account_id}"
  oidc_host    = "token.actions.githubusercontent.com"

  # sub の形式: 従来 repo:OWNER/REPO:...  ID 付き repo:OWNER@OWNER_ID/REPO@REPO_ID:...
  # 2026-07-15 以降に作成・改名・移管したリポジトリは ID 付き形式で発行される
  github_owner = split("/", var.github_repo)[0]
  github_name  = split("/", var.github_repo)[1]
  sub_repo = (var.github_owner_id != "" && var.github_repo_id != ""
    ? "repo:${local.github_owner}@${var.github_owner_id}/${local.github_name}@${var.github_repo_id}"
  : "repo:${var.github_repo}")

  sub_main_branch  = "${local.sub_repo}:ref:refs/heads/main"
  sub_pull_request = "${local.sub_repo}:pull_request"
  sub_apply_env    = "${local.sub_repo}:environment:${var.apply_environment}"
  sub_deploy_env   = "${local.sub_repo}:environment:${var.deploy_environment}"

  # CloudFront・WAF(CLOUDFRONT)・ACM(CloudFront 用)・Route 53 は us-east-1 扱いになる
  allowed_regions = distinct([var.region, "us-east-1"])
}

# ---------------- state バケット ----------------
resource "aws_s3_bucket" "tfstate" {
  bucket        = local.state_bucket
  force_destroy = true # 撤収時に旧バージョンごと消せるように
}

resource "aws_s3_bucket_versioning" "tfstate" {
  bucket = aws_s3_bucket.tfstate.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "tfstate" {
  bucket = aws_s3_bucket.tfstate.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "tfstate" {
  bucket                  = aws_s3_bucket.tfstate.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "tfstate" {
  bucket = aws_s3_bucket.tfstate.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

data "aws_iam_policy_document" "tfstate" {
  statement {
    sid       = "DenyInsecureTransport"
    effect    = "Deny"
    actions   = ["s3:*"]
    resources = [aws_s3_bucket.tfstate.arn, "${aws_s3_bucket.tfstate.arn}/*"]
    principals {
      type        = "*"
      identifiers = ["*"]
    }
    condition {
      test     = "Bool"
      variable = "aws:SecureTransport"
      values   = ["false"]
    }
  }
}

resource "aws_s3_bucket_policy" "tfstate" {
  bucket     = aws_s3_bucket.tfstate.id
  policy     = data.aws_iam_policy_document.tfstate.json
  depends_on = [aws_s3_bucket_public_access_block.tfstate]
}

# ---------------- OIDC プロバイダ（アカウントに1つ） ----------------
resource "aws_iam_openid_connect_provider" "github" {
  count          = var.create_github_oidc_provider ? 1 : 0
  url            = "https://${local.oidc_host}"
  client_id_list = ["sts.amazonaws.com"]
  # AWS は現在この値を検証に使わないが API 上必須
  thumbprint_list = ["6938fd4d98bab03faadb97b34396831e3780aea1"]
}

data "aws_iam_openid_connect_provider" "github" {
  count = var.create_github_oidc_provider ? 0 : 1
  url   = "https://${local.oidc_host}"
}

locals {
  oidc_provider_arn = (var.create_github_oidc_provider
    ? aws_iam_openid_connect_provider.github[0].arn
  : data.aws_iam_openid_connect_provider.github[0].arn)
}

data "aws_iam_policy_document" "trust" {
  for_each = {
    # fork からの PR には OIDC トークンが発行されないので、pull_request は同じリポジトリのブランチだけ
    plan   = [local.sub_main_branch, local.sub_pull_request]
    apply  = [local.sub_apply_env]
    deploy = [local.sub_deploy_env]
  }
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]
    principals {
      type        = "Federated"
      identifiers = [local.oidc_provider_arn]
    }
    condition {
      test     = "StringEquals"
      variable = "${local.oidc_host}:aud"
      values   = ["sts.amazonaws.com"]
    }
    condition {
      test     = "StringEqualsIgnoreCase"
      variable = "${local.oidc_host}:sub"
      values   = each.value
    }
  }
}

# ---------------- plan ロール（読み取り専用） ----------------
resource "aws_iam_role" "plan" {
  name                 = "${var.project}-gha-plan"
  assume_role_policy   = data.aws_iam_policy_document.trust["plan"].json
  max_session_duration = 3600
}

# ReadOnlyAccess は S3 の中身も読める。DB バックアップは暗号化してから置く前提
resource "aws_iam_role_policy_attachment" "plan_readonly" {
  role       = aws_iam_role.plan.name
  policy_arn = "arn:aws:iam::aws:policy/ReadOnlyAccess"
}

# ---------------- apply ロール（Environment 承認済みジョブだけ） ----------------
resource "aws_iam_role" "apply" {
  name                 = "${var.project}-gha-apply"
  assume_role_policy   = data.aws_iam_policy_document.trust["apply"].json
  max_session_duration = 3600
}

data "aws_iam_policy_document" "apply" {
  statement {
    sid       = "StateBucketList"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.tfstate.arn]
  }
  statement {
    sid       = "StateObjects"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"] # *.tflock を含む
    resources = ["${aws_s3_bucket.tfstate.arn}/*"]
  }

  # 使うサービス（Lightsail・CloudFront・WAF・ACM・Route 53）。東京と us-east-1 以外では何もできない
  statement {
    sid       = "ProjectServices"
    actions   = var.apply_regional_actions
    resources = ["*"]
    condition {
      test     = "StringEquals"
      variable = "aws:RequestedRegion"
      values   = local.allowed_regions
    }
  }

  # プロジェクト名で始まる S3 バケットだけ（フロント配信用・DB バックアップ用）
  statement {
    sid       = "ProjectBuckets"
    actions   = ["s3:*"]
    resources = ["arn:aws:s3:::${var.project}-*", "arn:aws:s3:::${var.project}-*/*"]
  }
  statement {
    sid       = "NeverTouchStateBucketConfig"
    effect    = "Deny"
    actions   = ["s3:DeleteBucket", "s3:PutBucketPolicy", "s3:DeleteBucketPolicy", "s3:PutBucketVersioning", "s3:PutLifecycleConfiguration"]
    resources = [aws_s3_bucket.tfstate.arn]
  }
  statement {
    sid       = "S3ListForProvider"
    actions   = ["s3:ListAllMyBuckets", "s3:GetBucketLocation"]
    resources = ["*"]
  }

  # IAM: DB バックアップを S3 に送るための IAM ユーザー（<project>-* のみ）。
  # アクセスキーは Terraform では作らない（state に残るため）。コンソールで発行してサーバに置く
  statement {
    sid = "ProjectUsersOnly"
    actions = [
      "iam:CreateUser", "iam:DeleteUser", "iam:GetUser", "iam:TagUser", "iam:UntagUser",
      "iam:PutUserPolicy", "iam:GetUserPolicy", "iam:DeleteUserPolicy", "iam:ListUserPolicies",
      "iam:ListAttachedUserPolicies", "iam:ListGroupsForUser", "iam:ListAccessKeys",
      "iam:ListMFADevices", "iam:ListSSHPublicKeys", "iam:ListServiceSpecificCredentials",
      "iam:ListSigningCertificates", "iam:GetLoginProfile",
    ]
    resources = ["arn:aws:iam::${local.account_id}:user/${var.project}-*"]
  }
  statement {
    sid       = "NeverTouchGhaRoles"
    effect    = "Deny"
    actions   = ["iam:*"]
    resources = ["arn:aws:iam::${local.account_id}:role/${var.project}-gha-*"]
  }
  statement {
    sid       = "ServiceLinkedRoles"
    actions   = ["iam:CreateServiceLinkedRole"]
    resources = ["*"]
    condition {
      test     = "StringEquals"
      variable = "iam:AWSServiceName"
      values   = var.service_linked_role_services
    }
  }
}

resource "aws_iam_role_policy" "apply" {
  name   = "${var.project}-stacks"
  role   = aws_iam_role.apply.id
  policy = data.aws_iam_policy_document.apply.json
}

# ---------------- deploy ロール（アプリのデプロイ。Environment「poifo-deploy」のジョブだけ） ----------------
# インフラは変えられない。フロントの S3 同期・CloudFront のキャッシュ削除・バックエンドのリリース置き場への配置だけ
resource "aws_iam_role" "deploy" {
  name                 = "${var.project}-gha-deploy"
  assume_role_policy   = data.aws_iam_policy_document.trust["deploy"].json
  max_session_duration = 3600
}

data "aws_iam_policy_document" "deploy" {
  # フロント: dist/ を S3 に同期（--delete で古いファイルも消す）
  statement {
    sid       = "FrontBucketList"
    actions   = ["s3:ListBucket"]
    resources = ["arn:aws:s3:::${var.project}-front-${local.account_id}"]
  }
  statement {
    sid       = "FrontObjects"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = ["arn:aws:s3:::${var.project}-front-${local.account_id}/*"]
  }
  statement {
    sid       = "FrontInvalidation"
    actions   = ["cloudfront:ListDistributions", "cloudfront:CreateInvalidation", "cloudfront:GetInvalidation"]
    resources = ["*"]
  }

  # バックエンド: リリース（tar.gz と latest.json）を置き、サーバが書いた結果（status/）を読む
  statement {
    sid       = "DeployBucketList"
    actions   = ["s3:ListBucket"]
    resources = ["arn:aws:s3:::${var.project}-deploy-${local.account_id}"]
  }
  statement {
    sid       = "DeployReleases"
    actions   = ["s3:PutObject"]
    resources = ["arn:aws:s3:::${var.project}-deploy-${local.account_id}/releases/*"]
  }
  statement {
    sid       = "DeployStatus"
    actions   = ["s3:GetObject"]
    resources = ["arn:aws:s3:::${var.project}-deploy-${local.account_id}/status/*"]
  }
}

resource "aws_iam_role_policy" "deploy" {
  name   = "${var.project}-deploy"
  role   = aws_iam_role.deploy.id
  policy = data.aws_iam_policy_document.deploy.json
}
