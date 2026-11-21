# バックエンドのデプロイ（プル型）
#   1. GitHub Actions がテスト・ビルドしたリリース（tar.gz）と latest.json をこのバケットの releases/ に置く
#   2. サーバ（Lightsail）の deploy agent が1分ごとに latest.json を見て、新しければ取り込んで切り替える
#   3. 各サーバが結果を status/<デプロイID>/<サーバ名>.json に書く → Actions が台数分を待って成否を表示する
# サーバに外から入る経路（SSH を Actions に開ける等）を作らずに済む
resource "aws_s3_bucket" "deploy" {
  bucket        = "${var.project}-deploy-${local.account_id}"
  force_destroy = true # 中身は Git から作り直せる
}

resource "aws_s3_bucket_public_access_block" "deploy" {
  bucket                  = aws_s3_bucket.deploy.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "deploy" {
  bucket = aws_s3_bucket.deploy.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "deploy" {
  bucket = aws_s3_bucket.deploy.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

# 古いリリースと結果は30日で消す（latest.json は毎回上書きされるので残る）
resource "aws_s3_bucket_lifecycle_configuration" "deploy" {
  bucket = aws_s3_bucket.deploy.id
  rule {
    id     = "expire-old-releases"
    status = "Enabled"
    filter {}
    expiration {
      days = 30
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

data "aws_iam_policy_document" "deploy_bucket" {
  statement {
    sid       = "DenyInsecureTransport"
    effect    = "Deny"
    actions   = ["s3:*"]
    resources = [aws_s3_bucket.deploy.arn, "${aws_s3_bucket.deploy.arn}/*"]
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

resource "aws_s3_bucket_policy" "deploy" {
  bucket     = aws_s3_bucket.deploy.id
  policy     = data.aws_iam_policy_document.deploy_bucket.json
  depends_on = [aws_s3_bucket_public_access_block.deploy]
}

# サーバ用（deploy agent と Let's Encrypt）。リリースを読むこと、結果を書くこと、証明書の確認用 DNS レコードを書くことだけできる。
# アクセスキーは Terraform では作らない（state に残るため）。コンソールで発行してサーバに置く
resource "aws_iam_user" "deploy_agent" {
  name = "${var.project}-deploy-agent"
}

data "aws_iam_policy_document" "deploy_agent" {
  # 一覧の権限がないと、まだ無いファイルが 404 ではなく 403 になって区別できないため（releases/ と status/ だけ）
  statement {
    sid       = "ListReleases"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.deploy.arn]
    condition {
      test     = "StringLike"
      variable = "s3:prefix"
      values   = ["releases/*", "status/*"]
    }
  }
  statement {
    sid       = "ReadReleases"
    actions   = ["s3:GetObject"]
    resources = ["${aws_s3_bucket.deploy.arn}/releases/*"]
  }
  # status/ は書き込みと、ほかの台が primary の migrate 完了（_migrated.json）を読むため
  statement {
    sid       = "WriteStatus"
    actions   = ["s3:PutObject", "s3:GetObject"]
    resources = ["${aws_s3_bucket.deploy.arn}/status/*"]
  }
}

# サーバの Let's Encrypt（DNS-01）用。_acme-challenge.origin.<domain> の TXT レコードだけ書ける。
# DNS で確認するので、固定 IP や LB がどのサーバを向いていても（入れ替え用の新しいサーバでも）証明書を取れる
data "aws_iam_policy_document" "acme_dns" {
  count = local.custom_domain ? 1 : 0
  statement {
    sid       = "AcmeChallengeRecordOnly"
    actions   = ["route53:ChangeResourceRecordSets"]
    resources = ["arn:aws:route53:::hostedzone/${data.aws_route53_zone.main[0].zone_id}"]
    condition {
      test     = "ForAllValues:StringEquals"
      variable = "route53:ChangeResourceRecordSetsNormalizedRecordNames"
      values   = ["_acme-challenge.${lower(local.origin_host)}"]
    }
    condition {
      test     = "ForAllValues:StringEquals"
      variable = "route53:ChangeResourceRecordSetsRecordTypes"
      values   = ["TXT"]
    }
  }
  statement {
    sid       = "AcmeLookup"
    actions   = ["route53:ListHostedZones", "route53:GetChange"]
    resources = ["*"]
  }
  statement {
    sid       = "AcmeListRecords"
    actions   = ["route53:ListResourceRecordSets"]
    resources = ["arn:aws:route53:::hostedzone/${data.aws_route53_zone.main[0].zone_id}"]
  }
}

resource "aws_iam_user_policy" "acme_dns" {
  count  = local.custom_domain ? 1 : 0
  name   = "acme-dns"
  user   = aws_iam_user.deploy_agent.name
  policy = data.aws_iam_policy_document.acme_dns[0].json
}

resource "aws_iam_user_policy" "deploy_agent" {
  name   = "deploy-agent"
  user   = aws_iam_user.deploy_agent.name
  policy = data.aws_iam_policy_document.deploy_agent.json
}
