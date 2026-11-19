# API サーバ（nginx＋Laravel＋MySQL を同居）。
# 注意: サーバを消す・作り直す変更（bundle_id / blueprint_id / availability_zone など）は DB ごと消える。
#       更新ワークフローはサーバの削除を含む plan を止める。サイズ変更はスナップショットから作り直す（docs/infra 参照）
resource "aws_lightsail_key_pair" "main" {
  count      = var.ssh_public_key != "" ? 1 : 0
  name       = "${var.project}-key"
  public_key = var.ssh_public_key
}

resource "aws_lightsail_instance" "api" {
  name              = "${var.project}-api"
  availability_zone = var.availability_zone
  blueprint_id      = var.lightsail_blueprint_id
  bundle_id         = var.lightsail_bundle_id
  key_pair_name     = var.ssh_public_key != "" ? aws_lightsail_key_pair.main[0].name : null

  # 毎日の自動スナップショット（7世代）。インスタンスを削除すると自動スナップショットも消える点に注意
  add_on {
    type          = "AutoSnapshot"
    snapshot_time = var.auto_snapshot_time
    status        = "Enabled"
  }

  lifecycle {
    # 鍵は作成時にしか反映されないので、後から変えてもサーバは作り直さない
    ignore_changes = [key_pair_name]
  }
}

resource "aws_lightsail_static_ip" "api" {
  name = "${var.project}-api-ip"
}

resource "aws_lightsail_static_ip_attachment" "api" {
  static_ip_name = aws_lightsail_static_ip.api.name
  instance_name  = aws_lightsail_instance.api.name
}

# ファイアウォール。ここに書いたポートだけが開く
resource "aws_lightsail_instance_public_ports" "api" {
  instance_name = aws_lightsail_instance.api.name

  # HTTPS: CloudFront から。直接アクセスは nginx が秘密ヘッダーの有無で拒否する
  port_info {
    protocol  = "tcp"
    from_port = 443
    to_port   = 443
    cidrs     = ["0.0.0.0/0"]
  }

  # HTTP: Let's Encrypt の証明書発行・更新（HTTP-01）用。nginx は ACME 以外を HTTPS へ転送
  port_info {
    protocol  = "tcp"
    from_port = 80
    to_port   = 80
    cidrs     = ["0.0.0.0/0"]
  }

  # SSH: 運用者の IP と、Lightsail コンソールのブラウザ SSH だけ
  port_info {
    protocol          = "tcp"
    from_port         = 22
    to_port           = 22
    cidrs             = var.ssh_allowed_cidrs
    cidr_list_aliases = ["lightsail-connect"]
  }
}
