# フロント（Astro の静的出力）: S3（非公開）＋ CloudFront（OAC で S3 を読む）
data "aws_caller_identity" "current" {}

locals {
  account_id    = data.aws_caller_identity.current.account_id
  custom_domain = var.enable_custom_domain && var.domain_name != ""

  # AWS 管理のポリシー（定額プランでも使える）
  cache_policy_caching_optimized        = "658327ea-f89d-4fab-a63d-7e88639e58f6"
  cache_policy_caching_disabled         = "4135ea2d-6df8-44a3-9df3-4b5e84be39ad"
  origin_request_all_viewer_except_host = "b689b0a8-53d0-40ab-baf2-68738e2966ac"
  response_headers_security_headers     = "67f7725c-6f97-4210-82d7-5512b31e9d03"
}

resource "aws_s3_bucket" "front" {
  bucket        = "${var.project}-front-${local.account_id}"
  force_destroy = true # 中身は Git から再ビルドできる
}

resource "aws_s3_bucket_public_access_block" "front" {
  bucket                  = aws_s3_bucket.front.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "front" {
  bucket = aws_s3_bucket.front.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "front" {
  bucket = aws_s3_bucket.front.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_cloudfront_origin_access_control" "front" {
  name                              = "${var.project}-front"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

# /about → /about/index.html のように、ディレクトリ形式の URL を index.html に読み替える
resource "aws_cloudfront_function" "front_index" {
  name    = "${var.project}-front-index"
  runtime = "cloudfront-js-2.0"
  publish = true
  code    = file("${path.module}/functions/front-index.js")
}

resource "aws_cloudfront_distribution" "front" {
  enabled             = true
  comment             = "${var.project} front"
  default_root_object = "index.html"
  is_ipv6_enabled     = true
  http_version        = "http2and3"
  aliases             = local.custom_domain ? [var.domain_name] : []
  web_acl_id          = local.custom_domain ? aws_wafv2_web_acl.front[0].arn : null

  origin {
    origin_id                = "s3-front"
    domain_name              = aws_s3_bucket.front.bucket_regional_domain_name
    origin_access_control_id = aws_cloudfront_origin_access_control.front.id
  }

  default_cache_behavior {
    target_origin_id           = "s3-front"
    viewer_protocol_policy     = "redirect-to-https"
    allowed_methods            = ["GET", "HEAD"]
    cached_methods             = ["GET", "HEAD"]
    compress                   = true
    cache_policy_id            = local.cache_policy_caching_optimized
    response_headers_policy_id = local.response_headers_security_headers

    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.front_index.arn
    }
  }

  # エラーページ（frontend/src/pages/404.astro・500.astro のビルド結果）。
  # OAC では存在しないキーは 403 になるので、403 も 404 として 404 ページを返す
  custom_error_response {
    error_code            = 403
    response_code         = 404
    response_page_path    = "/404.html"
    error_caching_min_ttl = 60
  }
  custom_error_response {
    error_code            = 404
    response_code         = 404
    response_page_path    = "/404.html"
    error_caching_min_ttl = 60
  }

  # S3 側の障害（5xx）は 500 ページを返す。状態コードはそのままにして、検索エンジンに一時的な障害と伝える。
  # 復旧したらすぐ通常表示に戻るよう、エラーの記憶時間は短くする
  dynamic "custom_error_response" {
    for_each = [500, 502, 503, 504]
    content {
      error_code            = custom_error_response.value
      response_code         = custom_error_response.value
      response_page_path    = "/500.html"
      error_caching_min_ttl = 10
    }
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = local.custom_domain ? null : true
    acm_certificate_arn            = local.custom_domain ? aws_acm_certificate_validation.main[0].certificate_arn : null
    ssl_support_method             = local.custom_domain ? "sni-only" : null
    minimum_protocol_version       = local.custom_domain ? "TLSv1.2_2021" : null
  }
}

data "aws_iam_policy_document" "front_bucket" {
  statement {
    sid       = "AllowCloudFrontRead"
    actions   = ["s3:GetObject"]
    resources = ["${aws_s3_bucket.front.arn}/*"]
    principals {
      type        = "Service"
      identifiers = ["cloudfront.amazonaws.com"]
    }
    condition {
      test     = "StringEquals"
      variable = "AWS:SourceArn"
      values   = [aws_cloudfront_distribution.front.arn]
    }
  }
  statement {
    sid       = "DenyInsecureTransport"
    effect    = "Deny"
    actions   = ["s3:*"]
    resources = [aws_s3_bucket.front.arn, "${aws_s3_bucket.front.arn}/*"]
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

resource "aws_s3_bucket_policy" "front" {
  bucket     = aws_s3_bucket.front.id
  policy     = data.aws_iam_policy_document.front_bucket.json
  depends_on = [aws_s3_bucket_public_access_block.front]
}
