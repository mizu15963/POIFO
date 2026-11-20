# AWS WAF（CloudFront 用は us-east-1・CLOUDFRONT スコープ）。独自ドメイン有効時だけ作る。
# CloudFront 定額プラン（Free）はルール5個まで（マネージドルールグループも1個と数える）。
# 定額プランに入るまでは従量課金（Web ACL $5/月＋ルール $1/月、時間割り）。
#
# API 用（5個）: 1 送信元IP制限（管理画面 OR ポストバック） / 2 ログイン関連の回数制限 / 3 コアルール / 4 SQLi / 5 全体のレート制限
# フロント用（2個）: コアルール / レート制限
locals {
  waf_admin_ip_enabled    = nonsensitive(length(var.admin_allowed_cidrs) > 0)
  waf_postback_ip_enabled = nonsensitive(length(var.postback_allowed_cidrs) > 0)

  # 送信元 IP 制限ルールの条件（設定されているものだけ）
  waf_ip_restrictions = local.custom_domain ? concat(
    local.waf_admin_ip_enabled ? [{
      field    = "host"
      match    = lower(local.admin_host)
      position = "EXACTLY"
      ip_set   = aws_wafv2_ip_set.admin[0].arn
    }] : [],
    local.waf_postback_ip_enabled ? [{
      field    = "path"
      match    = lower(var.postback_path_prefix)
      position = "STARTS_WITH"
      ip_set   = aws_wafv2_ip_set.postback[0].arn
    }] : [],
  ) : []
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

  # 1. 送信元 IP の制限（定額プランの5ルール枠を空けるため、2つの条件を OR で1ルールにまとめる）
  #    - admin. へのアクセスで、許可 IP 以外
  #    - ポストバック受信パス（前方一致）へのアクセスで、ASP の送信元 IP 以外
  #    どちらか片方だけ設定されているときは、その条件だけのルールになる
  dynamic "rule" {
    for_each = length(local.waf_ip_restrictions) > 0 ? [1] : []
    content {
      name     = "source-ip-restriction"
      priority = 10
      action {
        block {}
      }
      statement {
        dynamic "or_statement" {
          for_each = length(local.waf_ip_restrictions) > 1 ? [1] : []
          content {
            dynamic "statement" {
              for_each = local.waf_ip_restrictions
              content {
                and_statement {
                  statement {
                    byte_match_statement {
                      search_string         = statement.value.match
                      positional_constraint = statement.value.position
                      field_to_match {
                        dynamic "single_header" {
                          for_each = statement.value.field == "host" ? [1] : []
                          content {
                            name = "host"
                          }
                        }
                        dynamic "uri_path" {
                          for_each = statement.value.field == "path" ? [1] : []
                          content {}
                        }
                      }
                      # パスは URL デコードしてから比べる（%70ostback のような書き方でのすり抜けを防ぐ）
                      dynamic "text_transformation" {
                        for_each = statement.value.field == "host" ? ["LOWERCASE"] : ["URL_DECODE", "LOWERCASE"]
                        content {
                          priority = text_transformation.key
                          type     = text_transformation.value
                        }
                      }
                    }
                  }
                  statement {
                    not_statement {
                      statement {
                        ip_set_reference_statement {
                          arn = statement.value.ip_set
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
        dynamic "and_statement" {
          for_each = length(local.waf_ip_restrictions) == 1 ? local.waf_ip_restrictions : []
          content {
            statement {
              byte_match_statement {
                search_string         = and_statement.value.match
                positional_constraint = and_statement.value.position
                field_to_match {
                  dynamic "single_header" {
                    for_each = and_statement.value.field == "host" ? [1] : []
                    content {
                      name = "host"
                    }
                  }
                  dynamic "uri_path" {
                    for_each = and_statement.value.field == "path" ? [1] : []
                    content {}
                  }
                }
                # パスは URL デコードしてから比べる（%70ostback のような書き方でのすり抜けを防ぐ）
                dynamic "text_transformation" {
                  for_each = and_statement.value.field == "host" ? ["LOWERCASE"] : ["URL_DECODE", "LOWERCASE"]
                  content {
                    priority = text_transformation.key
                    type     = text_transformation.value
                  }
                }
              }
            }
            statement {
              not_statement {
                statement {
                  ip_set_reference_statement {
                    arn = and_statement.value.ip_set
                  }
                }
              }
            }
          }
        }
      }
      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name                = "${var.project}-source-ip"
        sampled_requests_enabled   = true
      }
    }
  }

  # 2. ログイン関連（/auth/ 配下）の厳しい回数制限。総当たり・連打を早めに止める
  rule {
    name     = "auth-rate-limit"
    priority = 20
    action {
      block {}
    }
    statement {
      rate_based_statement {
        limit                 = var.waf_auth_rate_limit
        aggregate_key_type    = "IP"
        evaluation_window_sec = 300
        scope_down_statement {
          byte_match_statement {
            search_string         = lower(var.auth_path_prefix)
            positional_constraint = "STARTS_WITH"
            field_to_match {
              uri_path {}
            }
            text_transformation {
              priority = 0
              type     = "URL_DECODE"
            }
            text_transformation {
              priority = 1
              type     = "LOWERCASE"
            }
          }
        }
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "${var.project}-auth-rate"
      sampled_requests_enabled   = true
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

  # 5. 全体のレート制限
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
