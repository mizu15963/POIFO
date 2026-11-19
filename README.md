# POIFO

ポイントサイト（LINEログイン会員登録・案件掲載・ASPポストバック・ポイント付与/交換・管理画面）のリポジトリです。

| ディレクトリ | 内容 | 説明 |
| --- | --- | --- |
| [frontend/](frontend/) | 会員向けサイト（Astro 7・静的出力）→ S3＋CloudFront | [README](frontend/README.md)・[作業ルール](frontend/AGENTS.md) |
| [backend/](backend/) | API・ASPポストバック受信・管理画面（Laravel 13・PHP 8.4）→ Lightsail、DB は Lightsail マネージドDB（MySQL 8.4） | [README](backend/README.md)・[作業ルール](backend/AGENTS.md) |
| [terraform/](terraform/) | AWS 環境（Terraform） | [docs/infra/README.md](docs/infra/README.md) |
| [docs/](docs/) | 仕様書・連携資料の要点・ブランド資産 | [docs/README.md](docs/README.md) |

frontend/ と backend/ はそれぞれ直下の `AGENTS.md`（Claude Code は `CLAUDE.md` から読み込む）に作業ルールがあります。作業するディレクトリのルールに従ってください。

> このリポジトリは **public** です。APIキー・パスワード・ASP の送信元 IP・AWS アカウントID などの秘密の値はコミットせず、`.env`（コミットしない）や GitHub Secrets に置きます。

## CI

- アプリ CI（`.github/workflows/app-ci.yml`）: backend/ と frontend/ の変更で、テスト・整形チェック・型チェック・ビルドを実行

## インフラ

AWS 環境は `terraform/` で定義し、GitHub Actions から承認付きで構築・更新・削除します。手順は [docs/infra/README.md](docs/infra/README.md)。

## ドキュメント

仕様書・ASP連携資料・ブランド資産は [docs/](docs/README.md) にまとめています。
