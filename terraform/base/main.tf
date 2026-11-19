# base: 消すと困る・作り直すと面倒なもの。app スタックを destroy しても残す
#   - DB バックアップ用 S3 バケット（サーバ外のバックアップ先）
#   - バックアップ送信専用の IAM ユーザー（PutObject のみ）
#   - Route 53 ホストゾーン（作り直すとネームサーバが変わり、ドメイン側の設定をやり直すことになる）
data "aws_caller_identity" "current" {}

locals {
  account_id = data.aws_caller_identity.current.account_id
}

# ---------------- DB バックアップ ----------------
resource "aws_s3_bucket" "backup" {
  bucket = "${var.project}-backup-${local.account_id}"
  # 中身があると destroy は失敗する（誤削除の防止）。本当に消すときは中身を消してから
  force_destroy = false
}

resource "aws_s3_bucket_versioning" "backup" {
  bucket = aws_s3_bucket.backup.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "backup" {
  bucket = aws_s3_bucket.backup.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "backup" {
  bucket                  = aws_s3_bucket.backup.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "backup" {
  bucket = aws_s3_bucket.backup.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "backup" {
  bucket = aws_s3_bucket.backup.id
  rule {
    id     = "expire-old-backups"
    status = "Enabled"
    filter {}
    expiration {
      days = var.backup_retention_days
    }
    noncurrent_version_expiration {
      noncurrent_days = 7
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

data "aws_iam_policy_document" "backup_bucket" {
  statement {
    sid       = "DenyInsecureTransport"
    effect    = "Deny"
    actions   = ["s3:*"]
    resources = [aws_s3_bucket.backup.arn, "${aws_s3_bucket.backup.arn}/*"]
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

resource "aws_s3_bucket_policy" "backup" {
  bucket     = aws_s3_bucket.backup.id
  policy     = data.aws_iam_policy_document.backup_bucket.json
  depends_on = [aws_s3_bucket_public_access_block.backup]
}

# サーバ（Lightsail）からバックアップを送るユーザー。書き込みだけで、読み出し・削除はできない
# （サーバが乗っ取られてもバックアップを消されないように。上書きされても旧バージョンが残る）
resource "aws_iam_user" "backup_writer" {
  name = "${var.project}-backup-writer"
}

data "aws_iam_policy_document" "backup_writer" {
  statement {
    actions   = ["s3:PutObject"]
    resources = ["${aws_s3_bucket.backup.arn}/*"]
  }
}

resource "aws_iam_user_policy" "backup_writer" {
  name   = "put-backup"
  user   = aws_iam_user.backup_writer.name
  policy = data.aws_iam_policy_document.backup_writer.json
}

# ---------------- DNS ----------------
resource "aws_route53_zone" "main" {
  count = var.domain_name != "" ? 1 : 0
  name  = var.domain_name
}
