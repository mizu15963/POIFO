output "backup_bucket" {
  value = aws_s3_bucket.backup.bucket
}

output "backup_writer_user" {
  description = "このユーザーのアクセスキーをコンソールで発行し、サーバの環境変数に置く"
  value       = aws_iam_user.backup_writer.name
}

output "name_servers" {
  description = "ドメインの管理元で、このネームサーバを設定（委任）する"
  value       = try(aws_route53_zone.main[0].name_servers, [])
}

output "db_endpoint" {
  description = "Laravel の DB_HOST（同じリージョンの Lightsail インスタンスからだけ接続できる）"
  value       = aws_lightsail_database.main.master_endpoint_address
}

output "db_port" {
  value = aws_lightsail_database.main.master_endpoint_port
}
