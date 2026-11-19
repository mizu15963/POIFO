# 独自ドメインを有効にしたときだけ作るもの（enable_custom_domain = true）
#   - ACM 証明書（ドメイン本体・api.・admin.）
#   - API・管理画面用 CloudFront（api. と admin. → origin.<domain> の Lightsail）
#   - DNS レコード
data "aws_route53_zone" "main" {
  count = local.custom_domain ? 1 : 0
  name  = var.domain_name
}

locals {
  api_host    = "api.${var.domain_name}"
  admin_host  = "admin.${var.domain_name}"
  origin_host = "origin.${var.domain_name}" # Lightsail の固定 IP を指す。Let's Encrypt の証明書はこの名前で取る
}

# ---------------- 証明書（CloudFront 用は us-east-1） ----------------
resource "aws_acm_certificate" "main" {
  count                     = local.custom_domain ? 1 : 0
  provider                  = aws.us_east_1
  domain_name               = var.domain_name
  subject_alternative_names = [local.api_host, local.admin_host]
  validation_method         = "DNS"

  lifecycle {
    create_before_destroy = true
  }
}

# for_each のキーは plan 時に決まっている名前にする（検証用レコードの値は apply 時に決まるため）
resource "aws_route53_record" "cert_validation" {
  for_each        = local.custom_domain ? toset([var.domain_name, local.api_host, local.admin_host]) : toset([])
  zone_id         = data.aws_route53_zone.main[0].zone_id
  name            = local.cert_validation[each.key].resource_record_name
  type            = local.cert_validation[each.key].resource_record_type
  records         = [local.cert_validation[each.key].resource_record_value]
  ttl             = 300
  allow_overwrite = true
}

locals {
  cert_validation = local.custom_domain ? {
    for o in aws_acm_certificate.main[0].domain_validation_options : o.domain_name => o
  } : {}
}

resource "aws_acm_certificate_validation" "main" {
  count                   = local.custom_domain ? 1 : 0
  provider                = aws.us_east_1
  certificate_arn         = aws_acm_certificate.main[0].arn
  validation_record_fqdns = [for r in aws_route53_record.cert_validation : r.fqdn]
}

# ---------------- API・管理画面用 CloudFront ----------------
resource "aws_cloudfront_function" "api_viewer_host" {
  count   = local.custom_domain ? 1 : 0
  name    = "${var.project}-api-viewer-host"
  runtime = "cloudfront-js-2.0"
  publish = true
  code    = file("${path.module}/functions/api-viewer-host.js")
}

resource "aws_cloudfront_distribution" "api" {
  count           = local.custom_domain ? 1 : 0
  enabled         = true
  comment         = "${var.project} api/admin"
  is_ipv6_enabled = true
  http_version    = "http2and3"
  aliases         = [local.api_host, local.admin_host]
  web_acl_id      = aws_wafv2_web_acl.api[0].arn

  origin {
    origin_id   = "lightsail"
    domain_name = local.origin_host

    custom_origin_config {
      http_port              = 80
      https_port             = 443
      origin_protocol_policy = "https-only"
      origin_ssl_protocols   = ["TLSv1.2"]
      origin_read_timeout    = 30
    }

    # nginx はこのヘッダーがないリクエストを拒否する（CloudFront＝WAF を通らない直接アクセスを防ぐ）
    custom_header {
      name  = "X-Origin-Verify"
      value = var.origin_verify_secret
    }
  }

  default_cache_behavior {
    target_origin_id       = "lightsail"
    viewer_protocol_policy = "redirect-to-https"
    allowed_methods        = ["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"]
    cached_methods         = ["GET", "HEAD"]
    compress               = true
    # API・ポストバックはキャッシュしない。Cookie・クエリ・ヘッダーはすべてオリジンへ渡す（Host を除く）
    cache_policy_id          = local.cache_policy_caching_disabled
    origin_request_policy_id = local.origin_request_all_viewer_except_host

    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.api_viewer_host[0].arn
    }
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    acm_certificate_arn      = aws_acm_certificate_validation.main[0].certificate_arn
    ssl_support_method       = "sni-only"
    minimum_protocol_version = "TLSv1.2_2021"
  }

  lifecycle {
    precondition {
      condition     = length(var.origin_verify_secret) >= 32
      error_message = "ORIGIN_VERIFY_SECRET（32文字以上のランダムな文字列）を GitHub Secrets に登録してください。"
    }
  }
}

# ---------------- DNS レコード ----------------
resource "aws_route53_record" "origin" {
  count   = local.custom_domain ? 1 : 0
  zone_id = data.aws_route53_zone.main[0].zone_id
  name    = local.origin_host
  type    = "A"
  ttl     = 300
  records = [aws_lightsail_static_ip.api.ip_address]
}

locals {
  alias_records = local.custom_domain ? {
    "front-A"    = { name = var.domain_name, type = "A", cf = aws_cloudfront_distribution.front }
    "front-AAAA" = { name = var.domain_name, type = "AAAA", cf = aws_cloudfront_distribution.front }
    "api-A"      = { name = local.api_host, type = "A", cf = aws_cloudfront_distribution.api[0] }
    "api-AAAA"   = { name = local.api_host, type = "AAAA", cf = aws_cloudfront_distribution.api[0] }
    "admin-A"    = { name = local.admin_host, type = "A", cf = aws_cloudfront_distribution.api[0] }
    "admin-AAAA" = { name = local.admin_host, type = "AAAA", cf = aws_cloudfront_distribution.api[0] }
  } : {}
}

resource "aws_route53_record" "alias" {
  for_each = local.alias_records
  zone_id  = data.aws_route53_zone.main[0].zone_id
  name     = each.value.name
  type     = each.value.type

  alias {
    name                   = each.value.cf.domain_name
    zone_id                = each.value.cf.hosted_zone_id
    evaluate_target_health = false
  }
}
