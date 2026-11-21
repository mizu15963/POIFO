output "instance_names" {
  value = concat([for k in sort(keys(aws_lightsail_instance.primary)) : aws_lightsail_instance.primary[k].name], aws_lightsail_instance.extra[*].name)
}

output "active_instance" {
  description = "固定 IP が付いている（CloudFront から届く）1台目のサーバ"
  value       = aws_lightsail_static_ip_attachment.api.instance_name
}

output "standby_instance" {
  description = "入れ替え用に作った新しいサーバ（api_standby = true のときだけ）"
  value       = var.api_standby ? aws_lightsail_instance.primary[local.standby_slot].name : null
}

# 固定 IP はオリジンの所在を明かすので、公開ログ（ジョブサマリー）には出さない。
# 手元PCで terraform output -raw static_ip、または Lightsail コンソールで確認する
output "static_ip" {
  value     = aws_lightsail_static_ip.api.ip_address
  sensitive = true
}

output "front_bucket" {
  description = "フロントのデプロイ先（aws s3 sync dist/ s3://<これ>/ --delete）"
  value       = aws_s3_bucket.front.bucket
}

output "front_distribution_id" {
  description = "デプロイ後のキャッシュ削除に使う（aws cloudfront create-invalidation --distribution-id <これ> --paths '/*'）"
  value       = aws_cloudfront_distribution.front.id
}

output "front_url" {
  value = local.custom_domain ? "https://${var.domain_name}" : "https://${aws_cloudfront_distribution.front.domain_name}"
}

output "api_url" {
  value = local.custom_domain ? "https://${local.api_host}" : null
}

output "admin_url" {
  value = local.custom_domain ? "https://${local.admin_host}" : null
}

output "origin_host" {
  description = "Let's Encrypt の証明書をこの名前で取る（nginx の server_name）"
  value       = local.custom_domain ? local.origin_host : null
}

output "deploy_bucket" {
  description = "バックエンドのリリース置き場（サーバの deploy agent の DEPLOY_BUCKET）"
  value       = aws_s3_bucket.deploy.bucket
}

output "deploy_agent_user" {
  description = "このユーザーのアクセスキーをコンソールで発行し、サーバの /etc/poifo/deploy-agent.env に置く"
  value       = aws_iam_user.deploy_agent.name
}

output "ses_sender_user" {
  description = "通知メール送信用の IAM ユーザー（アクセスキーはコンソールで発行し、サーバの .env に入れる）"
  value       = local.custom_domain ? aws_iam_user.ses_sender[0].name : null
}
