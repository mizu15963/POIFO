# POIFO

ポイントサイト（LINEログイン会員登録・案件掲載・ASPポストバック・ポイント付与/交換・管理画面）のリポジトリです。

- フロントエンド: Astro（静的）→ S3 + CloudFront
- バックエンド: Lightsail（API + MySQL）

## インフラ

AWS 環境は `terraform/` で定義し、GitHub Actions から承認付きで構築・更新・削除します。手順は [docs/infra/README.md](docs/infra/README.md)。

## ドキュメント

仕様書・ASP連携資料・ブランド資産は [docs/](docs/README.md) にまとめています。
