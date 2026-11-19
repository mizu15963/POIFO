# DB: Lightsail マネージドデータベース（MySQL）。アプリ用サーバとは分け、base に置く
# （app スタックを destroy・作り直ししても DB は残る）。
#   - 外部には公開しない（同じリージョンの Lightsail インスタンスからだけ接続できる）
#   - 毎日の自動バックアップ（7日分、ポイントインタイムリストア可）は Lightsail が行う
#   - 削除するときは最終スナップショットを残す
resource "aws_lightsail_database" "main" {
  relational_database_name = "${var.project}-db"
  availability_zone        = var.db_availability_zone
  blueprint_id             = var.db_blueprint_id
  bundle_id                = var.db_bundle_id

  master_database_name = var.db_name
  master_username      = var.db_master_username
  master_password      = var.db_master_password

  publicly_accessible      = false
  backup_retention_enabled = true
  # UTC。18:00-18:30 UTC = 3:00-3:30 JST、メンテナンスは月曜 4:00-4:30 JST
  preferred_backup_window      = "18:00-18:30"
  preferred_maintenance_window = "Sun:19:00-Sun:19:30"
  apply_immediately            = true

  skip_final_snapshot = false
  # 作成時点の日時で一意な名前にする（削除時に使われる）
  final_snapshot_name = "${var.project}-db-final-${formatdate("YYYYMMDDhhmm", timestamp())}"

  lifecycle {
    ignore_changes = [final_snapshot_name]

    precondition {
      condition     = length(var.db_master_password) >= 16
      error_message = "DB_MASTER_PASSWORD（16文字以上）を GitHub Secrets に登録してください。"
    }
  }
}
