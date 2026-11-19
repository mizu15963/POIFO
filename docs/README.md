# ドキュメント一覧

POIFO（ポイフォ）の仕様書・連携資料・ブランド資産をまとめたディレクトリです。

| ディレクトリ | 内容 |
| --- | --- |
| [specs/](specs/) | 自社で作成した設計仕様書 |
| [partners/gf-rewards/](partners/gf-rewards/) | ASP（GF Rewards）から受領した連携仕様書・資料 |
| [brand/](brand/) | ロゴなどのブランド資産とカラー |
| [reviews/](reviews/) | 仕様書のレビュー記録 |
| [infra/](infra/) | AWS 環境の構築・更新・削除の手順（Terraform＋GitHub Actions） |

## 仕様書

| ファイル | 内容 | 状態 |
| --- | --- | --- |
| [specs/top-page-spec.md](specs/top-page-spec.md) | トップ画面構成・機能仕様（初期版、2026-11-19） | プロトタイプ。レビュー指摘あり（[reviews/2026-11-20_top-page-spec-review.md](reviews/2026-11-20_top-page-spec-review.md)） |

## インフラ

サーバ構成計画書は Claude Docs で管理しています（[サーバ構成計画書](https://claude.ai/artifact/Li3ceqNda1GbgupeQTfVNZ)）。
概要は、フロントが Astro 静的出力を S3＋CloudFront、バックエンドが Lightsail 1台（nginx＋Laravel）と Lightsail マネージドデータベース（MySQL）、入口は CloudFront＋AWS WAF です。

アプリのコードは [frontend/](../frontend/README.md) と [backend/](../backend/README.md) にあります。

AWS 環境の構築・更新・削除（Terraform＋GitHub Actions）の手順は [infra/README.md](infra/README.md)。
