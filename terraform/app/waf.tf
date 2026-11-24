# AWS WAF（CloudFront 用は us-east-1・CLOUDFRONT スコープ）。独自ドメイン有効時だけ作る。
# CloudFront 定額プラン（Free）に収める。Free の制約:
#   - ルールは5個まで（マネージドルールグループも1個と数える）
#   - ヘッダーで条件を書くルール（Host で admin. を見分けるなど）は Pro 以上
#   - SQLi・PHP・WordPress のマネージドルールは Pro 以上
#   - URI パスで絞り込むルール（/postback/ だけ IP 制限、/auth/ だけ回数制限など）も入れると
#     WAFFeatureNotIncludedInPricingPlanException で拒否される例がある（他プロジェクトの実例）ので使わない
# 定額プランに入るまでは従量課金（Web ACL $5/月＋ルール $1/月、時間割り）。
#
# API 用（2個）: コアルール / 全体のレート制限
#   ポストバックの送信元 IP の確認はアプリ（POIFO_POSTBACK_ALLOWED_IPS と x-poifo-viewer-ip）が行う。
#   ログインの回数制限もアプリ側
# 管理画面用（2〜3個）: 送信元 IP 制限（ADMIN_ALLOWED_CIDRS があるときだけ） / コアルール / レート制限
# フロント用（2個）: コアルール / レート制限
locals {
  waf_admin_ip_enabled = nonsensitive(length(var.admin_allowed_cidrs) > 0)
}

resource "aws_wafv2_ip_set" "admin" {
  count              = local.custom_domain && local.waf_admin_ip_enabled ? 1 : 0
  provider           = aws.us_east_1
  name               = "${var.project}-admin-allowed"
  scope              = "CLOUDFRONT"
  ip_address_version = "IPV4"
  addresses          = var.admin_allowed_cidrs
}

resource "aws_wafv2_web_acl" "api" {
  count    = local.custom_domain ? 1 : 0
  provider = aws.us_east_1
  name     = "${var.project}-api"
  scope    = "CLOUDFRONT"

  default_action {
    allow {}
  }

  # 1. AWS マネージドルール（コアルール）。waf_managed_rules_count_mode = true の間は検知のみ
  rule {
    name     = "AWSManagedRulesCommonRuleSet"
    priority = 10
    override_action {
      dynamic "count" {
        for_each = var.waf_managed_rules_count_mode ? [1] : []
        content {}
      }
      dynamic "none" {
        for_each = var.waf_managed_rules_count_mode ? [] : [1]
        content {}
      }
    }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesCommonRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-api-AWSManagedRulesCommonRuleSet"
      sampled_requests_enabled   = true
    }
  }

  # 2. 全体のレート制限
  rule {
    name     = "rate-limit"
    priority = 20
    action {
      block {}
    }
    statement {
      rate_based_statement {
        limit                 = var.waf_rate_limit
        aggregate_key_type    = "IP"
        evaluation_window_sec = 300
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-api-rate"
      sampled_requests_enabled   = true
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${var.project}-api"
    sampled_requests_enabled   = true
  }
}

resource "aws_wafv2_web_acl" "admin" {
  count    = local.custom_domain ? 1 : 0
  provider = aws.us_east_1
  name     = "${var.project}-admin"
  scope    = "CLOUDFRONT"

  default_action {
    allow {}
  }

  # 1. 送信元 IP の制限: ADMIN_ALLOWED_CIDRS 以外をブロック（IP セットだけで判定し、ヘッダーは見ない）。
  #    未設定ならこのルールは作らない（二要素認証とレート制限で守る）
  dynamic "rule" {
    for_each = local.waf_admin_ip_enabled ? [1] : []
    content {
      name     = "admin-source-ip"
      priority = 10
      action {
        block {}
      }
      statement {
        not_statement {
          statement {
            ip_set_reference_statement {
              arn = aws_wafv2_ip_set.admin[0].arn
            }
          }
        }
      }
      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name                = "${var.project}-admin-source-ip"
        sampled_requests_enabled   = true
      }
    }
  }

  # 2. AWS マネージドルール（コアルール）
  rule {
    name     = "AWSManagedRulesCommonRuleSet"
    priority = 20
    override_action {
      dynamic "count" {
        for_each = var.waf_managed_rules_count_mode ? [1] : []
        content {}
      }
      dynamic "none" {
        for_each = var.waf_managed_rules_count_mode ? [] : [1]
        content {}
      }
    }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesCommonRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-admin-common"
      sampled_requests_enabled   = true
    }
  }

  # 3. レート制限（管理画面はログインの総当たりを早めに止めたいので、全体より厳しい値）
  rule {
    name     = "rate-limit"
    priority = 30
    action {
      block {}
    }
    statement {
      rate_based_statement {
        limit                 = var.waf_admin_rate_limit
        aggregate_key_type    = "IP"
        evaluation_window_sec = 300
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-admin-rate"
      sampled_requests_enabled   = true
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${var.project}-admin"
    sampled_requests_enabled   = true
  }
}

resource "aws_wafv2_web_acl" "front" {
  count    = local.custom_domain ? 1 : 0
  provider = aws.us_east_1
  name     = "${var.project}-front"
  scope    = "CLOUDFRONT"

  default_action {
    allow {}
  }

  rule {
    name     = "AWSManagedRulesCommonRuleSet"
    priority = 10
    override_action {
      dynamic "count" {
        for_each = var.waf_managed_rules_count_mode ? [1] : []
        content {}
      }
      dynamic "none" {
        for_each = var.waf_managed_rules_count_mode ? [] : [1]
        content {}
      }
    }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesCommonRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-front-common"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "rate-limit"
    priority = 20
    action {
      block {}
    }
    statement {
      rate_based_statement {
        limit                 = var.waf_rate_limit
        aggregate_key_type    = "IP"
        evaluation_window_sec = 300
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-front-rate"
      sampled_requests_enabled   = true
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${var.project}-front"
    sampled_requests_enabled   = true
  }
}
