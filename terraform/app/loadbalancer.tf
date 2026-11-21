# Lightsail ロードバランサー（enable_load_balancer = true のとき）。$18/月。
#   CloudFront → https://origin.<domain>（LB が HTTPS を終端）→ http://各インスタンス:80
#   LB からインスタンスへは X-Origin-Verify ヘッダーもそのまま届くので、nginx の確認は LB なしと同じ。
#   ヘルスチェック（lb_health_check_path）はヘッダーなしで来るので、nginx でそのパスだけ許可する
resource "aws_lightsail_lb" "api" {
  count             = var.enable_load_balancer ? 1 : 0
  name              = "${var.project}-api-lb"
  instance_port     = 80
  health_check_path = var.lb_health_check_path

  lifecycle {
    precondition {
      condition     = local.custom_domain
      error_message = "ロードバランサーは独自ドメイン（ENABLE_CUSTOM_DOMAIN=true）が必要です（HTTPS の証明書を origin.<domain> で発行するため）。"
    }
  }
}

# 1台目の枠は、入れ替え中の新しいサーバ（api_standby）も LB につなぐ（準備が済むまではヘルスチェックで外される）
resource "aws_lightsail_lb_attachment" "primary" {
  for_each      = var.enable_load_balancer ? aws_lightsail_instance.primary : {}
  lb_name       = aws_lightsail_lb.api[0].name
  instance_name = each.value.name
}

resource "aws_lightsail_lb_attachment" "extra" {
  count         = var.enable_load_balancer ? length(aws_lightsail_instance.extra) : 0
  lb_name       = aws_lightsail_lb.api[0].name
  instance_name = aws_lightsail_instance.extra[count.index].name
}

# LB の証明書（Lightsail が発行。DNS 検証のレコードを Route 53 に作る）
resource "aws_lightsail_lb_certificate" "api" {
  count       = var.enable_load_balancer ? 1 : 0
  name        = "${var.project}-origin-cert"
  lb_name     = aws_lightsail_lb.api[0].name
  domain_name = local.origin_host
}

resource "aws_route53_record" "lb_cert_validation" {
  count           = var.enable_load_balancer ? 1 : 0
  zone_id         = data.aws_route53_zone.main[0].zone_id
  name            = one(aws_lightsail_lb_certificate.api[0].domain_validation_records).resource_record_name
  type            = one(aws_lightsail_lb_certificate.api[0].domain_validation_records).resource_record_type
  records         = [one(aws_lightsail_lb_certificate.api[0].domain_validation_records).resource_record_value]
  ttl             = 300
  allow_overwrite = true
}

# 証明書の検証が済むまで（数分〜）attach は失敗することがある。失敗したら少し待って apply し直す
resource "aws_lightsail_lb_certificate_attachment" "api" {
  count            = var.enable_load_balancer ? 1 : 0
  lb_name          = aws_lightsail_lb.api[0].name
  certificate_name = aws_lightsail_lb_certificate.api[0].name
  depends_on       = [aws_route53_record.lb_cert_validation]
}
