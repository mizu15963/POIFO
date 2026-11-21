# API サーバ（nginx＋PHP-FPM＋Laravel）。DB は base スタックの Lightsail マネージドデータベース。
#
# 1台目（primary）は「a」「b」の2つの枠を持ち、固定 IP は api_active_slot の枠に付く。
#   ふだんは片方だけ（poifo-api-a など）。ミドルウェア更新やサイズ変更は、もう片方の枠に新しいサーバを作り（api_standby = true）、
#   準備ができたら api_active_slot を切り替えて固定 IP を移し、最後に古い枠を消す（api_standby = false）。
#   CloudFront のオリジン（origin.<domain>）は固定 IP を指したままなので、DNS の切り替え待ちがない。手順は docs/server/README.md
# 2台目以降（extra）は api_instance_count で増やす（poifo-api-2, poifo-api-3 ...。LB が必要。loadbalancer.tf）
#
# 更新ワークフローのガード（.github/scripts/terraform.sh）が次を止める:
#   - 固定 IP が付いているサーバを消す・作り直す変更（切り替えた後でないと古いサーバは消せない）
#   - まだ無いサーバに固定 IP を移す変更（先に api_standby = true で作って準備する）
resource "aws_lightsail_key_pair" "main" {
  count      = var.ssh_public_key != "" ? 1 : 0
  name       = "${var.project}-key"
  public_key = var.ssh_public_key
}

locals {
  standby_slot  = var.api_active_slot == "a" ? "b" : "a"
  primary_slots = toset(var.api_standby ? ["a", "b"] : [var.api_active_slot])
  slot_bundle = {
    a = coalesce(var.api_a_bundle_id, var.lightsail_bundle_id)
    b = coalesce(var.api_b_bundle_id, var.lightsail_bundle_id)
  }
  slot_blueprint = {
    a = coalesce(var.api_a_blueprint_id, var.lightsail_blueprint_id)
    b = coalesce(var.api_b_blueprint_id, var.lightsail_blueprint_id)
  }
  key_pair_name = var.ssh_public_key != "" ? aws_lightsail_key_pair.main[0].name : null
}

resource "aws_lightsail_instance" "primary" {
  for_each          = local.primary_slots
  name              = "${var.project}-api-${each.key}"
  availability_zone = var.availability_zone
  blueprint_id      = local.slot_blueprint[each.key]
  bundle_id         = local.slot_bundle[each.key]
  key_pair_name     = local.key_pair_name

  # 毎日の自動スナップショット（7世代）。インスタンスを削除すると自動スナップショットも消える点に注意
  add_on {
    type          = "AutoSnapshot"
    snapshot_time = var.auto_snapshot_time
    status        = "Enabled"
  }

  lifecycle {
    # 鍵は作成時にしか反映されないので、後から変えてもサーバは作り直さない
    ignore_changes = [key_pair_name]

    precondition {
      condition     = var.api_instance_count == 1 || var.enable_load_balancer
      error_message = "API サーバを2台以上にするときは ENABLE_LOAD_BALANCER=true にしてください（LB なしでは2台目以降にリクエストが届きません）。"
    }
  }
}

resource "aws_lightsail_instance" "extra" {
  count             = var.api_instance_count - 1
  name              = "${var.project}-api-${count.index + 2}"
  availability_zone = var.availability_zone
  blueprint_id      = var.lightsail_blueprint_id
  bundle_id         = var.lightsail_bundle_id
  key_pair_name     = local.key_pair_name

  add_on {
    type          = "AutoSnapshot"
    snapshot_time = var.auto_snapshot_time
    status        = "Enabled"
  }

  lifecycle {
    ignore_changes = [key_pair_name]
  }
}

resource "aws_lightsail_static_ip" "api" {
  name = "${var.project}-api-ip"
}

# 固定 IP は使用中の枠（api_active_slot）のサーバに付く。LB なしのとき origin.<domain> がこの IP を指す
resource "aws_lightsail_static_ip_attachment" "api" {
  static_ip_name = aws_lightsail_static_ip.api.name
  instance_name  = aws_lightsail_instance.primary[var.api_active_slot].name
}

# 以前の構成（1台目が api[0]）の state を引き継ぐ
moved {
  from = aws_lightsail_instance.api[0]
  to   = aws_lightsail_instance.primary["a"]
}
moved {
  from = aws_lightsail_instance_public_ports.api[0]
  to   = aws_lightsail_instance_public_ports.primary["a"]
}
moved {
  from = aws_lightsail_lb_attachment.api[0]
  to   = aws_lightsail_lb_attachment.primary["a"]
}

# ファイアウォール。ここに書いたポートだけが開く
resource "aws_lightsail_instance_public_ports" "primary" {
  for_each      = aws_lightsail_instance.primary
  instance_name = each.value.name

  # HTTPS: CloudFront から（LB なしのとき）。直接アクセスは nginx が秘密ヘッダーの有無で拒否する
  port_info {
    protocol  = "tcp"
    from_port = 443
    to_port   = 443
    cidrs     = ["0.0.0.0/0"]
  }

  # HTTP: LB ありのときの LB → インスタンスの通信（LB が HTTPS を終端し、インスタンスには HTTP で届く）。
  #       nginx は秘密ヘッダーのないリクエストを拒否する（/up を除く）
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

# 2台目以降も同じ（LB からだけ使われる）
resource "aws_lightsail_instance_public_ports" "extra" {
  count         = length(aws_lightsail_instance.extra)
  instance_name = aws_lightsail_instance.extra[count.index].name

  # HTTPS: CloudFront から（LB なしのとき）。直接アクセスは nginx が秘密ヘッダーの有無で拒否する
  port_info {
    protocol  = "tcp"
    from_port = 443
    to_port   = 443
    cidrs     = ["0.0.0.0/0"]
  }

  # HTTP: LB なしのときは Let's Encrypt の証明書発行・更新（HTTP-01）用。
  #       LB ありのときは LB → インスタンスの通信（LB が HTTPS を終端し、インスタンスには HTTP で届く）
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
