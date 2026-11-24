# ドキュメント一覧

POIFO（ポイフォ）の仕様書・連携資料・ブランド資産をまとめたディレクトリです。

| ディレクトリ | 内容 |
| --- | --- |
| [specs/](specs/) | 自社で作成した設計仕様書 |
| [partners/gf-rewards/](partners/gf-rewards/) | ASP（GF Rewards）から受領した連携仕様書・資料 |
| [brand/](brand/) | ロゴなどのブランド資産とカラー |
| [reviews/](reviews/) | 仕様書のレビュー記録 |
| [infra/](infra/) | AWS 環境の構築・更新・削除の手順（Terraform＋GitHub Actions） |
| [deploy/](deploy/) | フロント・バックエンドのデプロイの手順 |
| [server/](server/) | API サーバ（Lightsail）の初期設定・バックアップ・監視 |

## 仕様書

| ファイル | 内容 | 状態 |
| --- | --- | --- |
| [specs/functional-spec.md](specs/functional-spec.md) | 機能仕様（会員・案件・ポイント・成果通知・換金・イベント・管理画面、まだ決まっていないこと） | 実装済みの内容を書き起こし（2026-11-21）。一部は仮 |
| [specs/screen-spec.md](specs/screen-spec.md) | 画面仕様（会員サイト・管理画面の画面ごとの表示と操作、画面遷移） | 実装済みの画面を書き起こし（2026-11-21） |
| [specs/database.md](specs/database.md) | テーブル定義（全テーブルのカラム・型・インデックス・つながり） | マイグレーションから書き起こし（2026-11-23） |

2026-11-19 の「トップ画面構成・機能仕様」と 2026-11-20 の「管理画面・会員機能 仮仕様書」は、上の2本に統合した（[レビュー記録](reviews/2026-11-20_top-page-spec-review.md)は当時の版が対象）。

## インフラ

サーバ構成計画書は Claude Docs で管理しています（[サーバ構成計画書](https://claude.ai/artifact/Li3ceqNda1GbgupeQTfVNZ)）。
概要は、フロントが Astro 静的出力を S3＋CloudFront、バックエンドが Lightsail 1台（nginx＋Laravel）と Lightsail マネージドデータベース（MySQL）、入口は CloudFront＋AWS WAF です。

アプリのコードは [frontend/](../frontend/README.md) と [backend/](../backend/README.md) にあります。

AWS 環境の構築・更新・削除（Terraform＋GitHub Actions）の手順は [infra/README.md](infra/README.md)。
