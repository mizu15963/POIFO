# AWS WAF（CloudFront 用は us-east-1・CLOUDFRONT スコープ）。独自ドメイン有効時だけ作る。
# CloudFront 定額プラン（Free）はルール5個まで（マネージドルールグループも1個と数える）。
# 定額プランに入るまでは従量課金（Web ACL $5/月＋ルール $1/月、時間割り）。
#
# API 用（5個）: 1 管理画面IP制限 / 2 ポストバック送信元制限 / 3 コアルール / 4 SQLi / 5 レート制限
# フロント用（2個）: コアルール / レート制限
locals {
  waf_admin_ip_enabled    = nonsensitive(length(var.admin_allowed_cidrs) > 0)
  waf_postback_ip_enabled = nonsensitive(length(var.postback_allowed_cidrs) > 0)
}

resource "aws_wafv2_ip_set" "admin" {
  count              = local.custom_domain && local.waf_admin_ip_enabled ? 1 : 0
  provider           = aws.us_east_1
  name               = "${var.project}-admin-allowed"
  scope              = "CLOUDFRONT"
  ip_address_version = "IPV4"
  addresses          = var.admin_allowed_cidrs
}

resource "aws_wafv2_ip_set" "postback" {
  count              = local.custom_domain && local.waf_postback_ip_enabled ? 1 : 0
  provider           = aws.us_east_1
  name               = "${var.project}-postback-allowed"
  scope              = "CLOUDFRONT"
  ip_address_version = "IPV4"
  addresses          = var.postback_allowed_cidrs
}

resource "aws_wafv2_web_acl" "api" {
  count    = local.custom_domain ? 1 : 0
  provider = aws.us_east_1
  name     = "${var.project}-api"
  scope    = "CLOUDFRONT"

  default_action {
    allow {}
  }

  # 1. admin. へのアクセスは許可 IP 以外ブロック
  dynamic "rule" {
    for_each = local.waf_admin_ip_enabled ? [1] : []
    content {
      name     = "admin-ip-restriction"
      priority = 10
      action {
        block {}
      }
      statement {
        and_statement {
          statement {
            byte_match_statement {
              search_string         = local.admin_host
              positional_constraint = "EXACTLY"
              field_to_match {
                single_header {
                  name = "host"
                }
              }
              text_transformation {
                priority = 0
                type     = "LOWERCASE"
              }
            }
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
        }
      }
      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name                = "${var.project}-admin-ip"
        sampled_requests_enabled   = true
      }
    }
  }

  # 2. ポストバック受信パスは ASP の送信元 IP 以外ブロック
  dynamic "rule" {
    for_each = local.waf_postback_ip_enabled ? [1] : []
    content {
      name     = "postback-source-ip"
      priority = 20
      action {
        block {}
      }
      statement {
        and_statement {
          statement {
            byte_match_statement {
              search_string         = var.postback_path_prefix
              positional_constraint = "STARTS_WITH"
              field_to_match {
                uri_path {}
              }
              text_transformation {
                priority = 0
                type     = "NONE"
              }
            }
          }
          statement {
            not_statement {
              statement {
                ip_set_reference_statement {
                  arn = aws_wafv2_ip_set.postback[0].arn
                }
              }
            }
          }
        }
      }
      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name                = "${var.project}-postback-ip"
        sampled_requests_enabled   = true
      }
    }
  }

  # 3〜4. AWS マネージドルール。waf_managed_rules_count_mode = true の間は検知のみ
  dynamic "rule" {
    for_each = {
      30 = "AWSManagedRulesCommonRuleSet"
      40 = "AWSManagedRulesSQLiRuleSet"
    }
    content {
      name     = rule.value
      priority = tonumber(rule.key)
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
          name        = rule.value
        }
      }
      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name                = "${var.project}-api-${rule.value}"
        sampled_requests_enabled   = true
      }
    }
  }

  # 5. レート制限
  rule {
    name     = "rate-limit"
    priority = 50
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
