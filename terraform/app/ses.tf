# 管理者への通知メール（Amazon SES、東京リージョン）。独自ドメイン有効時だけ作る。
#   - ドメインを SES の ID として登録し、DKIM の CNAME 3つを Route 53 に置く（Easy DKIM）
#   - 送信だけできる IAM ユーザー <project>-ses-sender（アクセスキーはコンソールで発行してサーバの .env に置く）
#   - 当面はサンドボックスのまま。宛先（管理者）はコンソールで確認済みアドレスとして登録する
#     （メールアドレスは個人情報なので、public リポジトリの Actions ログに出る Terraform では扱わない）
# Lightsail は 25 番ポートが既定で塞がっているので、送信は SES の API（HTTPS）か SMTP の 587 番で行う
resource "aws_sesv2_email_identity" "domain" {
  count          = local.custom_domain ? 1 : 0
  email_identity = var.domain_name

  # Easy DKIM（AWS が鍵を管理し、自動でローテーションする）
  dkim_signing_attributes {
    next_signing_key_length = "RSA_2048_BIT"
  }
}

# DKIM のトークンは常に3つ（apply 時に値が決まるので、count は固定にする）
resource "aws_route53_record" "ses_dkim" {
  count   = local.custom_domain ? 3 : 0
  zone_id = data.aws_route53_zone.main[0].zone_id
  name    = "${aws_sesv2_email_identity.domain[0].dkim_signing_attributes[0].tokens[count.index]}._domainkey.${var.domain_name}"
  type    = "CNAME"
  ttl     = 1800
  records = ["${aws_sesv2_email_identity.domain[0].dkim_signing_attributes[0].tokens[count.index]}.dkim.amazonses.com"]
}

resource "aws_iam_user" "ses_sender" {
  count = local.custom_domain ? 1 : 0
  name  = "${var.project}-ses-sender"
}

# 送信だけ。送信元はこのドメインのアドレスに限る。
# サンドボックス中は宛先の ID にも権限が要るため、リソースはこのリージョンの ID すべてにする
data "aws_iam_policy_document" "ses_sender" {
  count = local.custom_domain ? 1 : 0
  statement {
    sid       = "SendOnly"
    actions   = ["ses:SendEmail", "ses:SendRawEmail"]
    resources = ["arn:aws:ses:${var.region}:${local.account_id}:identity/*"]
    condition {
      test     = "StringLike"
      variable = "ses:FromAddress"
      values   = ["*@${var.domain_name}"]
    }
  }
}

resource "aws_iam_user_policy" "ses_sender" {
  count  = local.custom_domain ? 1 : 0
  name   = "ses-send"
  user   = aws_iam_user.ses_sender[0].name
  policy = data.aws_iam_policy_document.ses_sender[0].json
}
