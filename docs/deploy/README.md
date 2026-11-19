# アプリのデプロイ（フロント・バックエンド）

このリポジトリには、フロント（`frontend/`、Astro 静的サイト）とバックエンド（`backend/`、Laravel）が同居している。
デプロイは **ディレクトリごとに別のワークフロー** にし、変更があった側だけが動く。インフラ（`terraform/`）の変更とも分かれている。

| 変更したディレクトリ | 動くワークフロー | 配置先 |
|---|---|---|
| `frontend/` | **デプロイ（フロント）** `deploy-frontend.yml` | S3 `poifo-front-<アカウントID>` に同期 → CloudFront のキャッシュ削除 |
| `backend/` | **デプロイ（バックエンド）** `deploy-backend.yml` | S3 `poifo-deploy-<アカウントID>` にリリースを置く → サーバが取り込む |
| `terraform/` | 更新（Terraform）`infra-update.yml` | AWS のリソース（[../infra/README.md](../infra/README.md)） |
| `frontend/` `backend/`（PR） | アプリ CI `app-ci.yml` | なし（テスト・ビルドだけ） |

どのワークフローも main に入ったときに動く。手動実行（Run workflow）もできる。

## しくみ

### フロント

1. `npm ci && npm run build`（`PUBLIC_*` は GitHub Variables からビルド時に埋め込む）
2. `dist/_astro/`（ファイル名にハッシュ付き）は1年キャッシュ、それ以外（HTML など）は毎回確認させる設定で S3 に同期。消えたページは S3 からも消す
3. CloudFront のキャッシュを削除（`/*`、月1,000件まで無料）して完了を待つ

### バックエンド（プル型）

サーバ（Lightsail）に GitHub Actions から入る経路は作らない。Actions はリリースを S3 に置くだけで、サーバが取りに行く。
SSH は計画書どおり運用者の IP だけに絞ったままにできる。

```text
GitHub Actions                         S3 poifo-deploy-…                サーバ（Lightsail）
 テスト → composer install --no-dev
 → release.tar.gz ──────────────────▶ releases/<コミット>.tar.gz
 → latest.json    ──────────────────▶ releases/latest.json ◀──────── deploy agent（1分ごと）
                                                                       取得・ハッシュ確認・展開
                                                                       migrate・キャッシュ作成
                                                                       current を切り替え・PHP-FPM reload
                                                                       キューのワーカー再起動
 結果を待つ（最大10分） ◀─────────── status/<デプロイID>.json ◀────── 結果を書く
```

- migrate やキャッシュ作成が失敗したら切り替えない（今のリリースのまま動き続ける）
- 切り替えた後に失敗したら、前のリリースに自動で戻す
- サーバには直近5リリースを残す（`/var/www/poifo/releases/`）
- 同じコミットでも、Actions から再実行すれば取り込み直す（デプロイID = 実行ID-試行回数）
- **戻す**: 「デプロイ（バックエンド）」を手動実行し、`ref` に戻したいコミットかタグを入れる。DB の migrate は戻らないので、テーブルを変えた変更を戻すときは注意する

| AWS の権限 | 何ができるか |
|---|---|
| ロール `poifo-gha-deploy`（Actions、Environment `poifo-deploy` のジョブだけ） | フロント用バケットへの同期、CloudFront のキャッシュ削除、`releases/` への書き込み、`status/` の読み取り。インフラは変えられない |
| IAM ユーザー `poifo-deploy-agent`（サーバ） | `releases/` の読み取りと `status/` への書き込みだけ |

費用: S3 の保管（リリースは30日で自動削除）と、サーバが1分ごとに確認するリクエスト（月4万回程度で数円）。

## 初回の準備

インフラの手順（[../infra/README.md](../infra/README.md)）の bootstrap と段階1の構築が済んでいる前提。

### 1. AWS と GitHub

1. bootstrap を手元PCで再 apply して、deploy ロールを作る（まだ一度も apply していなければ、最初の apply で一緒にできる）
   ```powershell
   cd terraform/bootstrap
   terraform apply
   gh secret set AWS_DEPLOY_ROLE_ARN --body (terraform output -raw deploy_role_arn)
   ```
2. **構築・削除（Terraform）** で `apply` / `app` を実行し、リリース置き場 `poifo-deploy-<アカウントID>` と IAM ユーザー `poifo-deploy-agent` を作る
3. GitHub の Settings → Environments → New environment → `poifo-deploy`
   - Deployment branches and tags: Selected branches → `main` のみ
   - Required reviewers は付けない（main に入ったら自動で出す）。毎回承認したければ自分を入れる
4. Variables（フロントのビルド時に埋め込む公開値）

   | 名前 | 値 |
   |---|---|
   | `PUBLIC_SITE_URL` | `https://<domain>`（ドメインがまだなら `front_url` の `https://xxxx.cloudfront.net`） |
   | `PUBLIC_API_BASE_URL` | `https://api.<domain>` |
   | `PUBLIC_LINE_OFFICIAL_URL` | 公式LINE の友だち追加URL |

5. `gh actions-lock` を実行して actions.lock を更新する（デプロイ用に `actions/setup-node` と `shivammathur/setup-php` を使う）

`AWS_DEPLOY_ROLE_ARN` が未登録の間は、デプロイのワークフローは何もせずに成功で終わる（main への push が赤くならないように）。

### 2. サーバ（1回だけ）

前提: nginx と PHP 8.4（php8.4-fpm、拡張 mbstring・intl・xml・curl・mysql）が入っていること。Composer はサーバに要らない（Actions でビルド済みのものを配る）。
サーバの初期設定全体は別の手順書で扱う。ここではデプロイに必要な分だけ。

```sh
# 必要なコマンド
sudo apt-get install -y jq unzip
curl -sSfL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o /tmp/awscliv2.zip
unzip -q /tmp/awscliv2.zip -d /tmp && sudo /tmp/aws/install

# ディレクトリ（コードは root、書き込みが要る storage は www-data）
sudo mkdir -p /var/www/poifo/releases /var/www/poifo/shared/storage/{app/public,framework/{cache/data,sessions,views},logs}
sudo chown -R www-data:www-data /var/www/poifo/shared/storage

# 本番の .env（項目は backend/README.md の「本番の設定」）。www-data が読めて、ほかの人は読めないように
sudo nano /var/www/poifo/shared/.env
sudo chown root:www-data /var/www/poifo/shared/.env && sudo chmod 640 /var/www/poifo/shared/.env

# deploy agent（リポジトリの deploy/backend/server/ をサーバにコピーしてから）
sudo install -m 755 poifo-deploy-agent.sh /usr/local/bin/poifo-deploy-agent
sudo install -d -m 700 /etc/poifo
sudo install -m 600 deploy-agent.env.example /etc/poifo/deploy-agent.env
sudo nano /etc/poifo/deploy-agent.env     # poifo-deploy-agent のアクセスキーと DEPLOY_BUCKET を入れる
sudo install -m 644 poifo-deploy-agent.service poifo-deploy-agent.timer poifo-queue.service /etc/systemd/system/
sudo install -m 644 poifo.cron /etc/cron.d/poifo
sudo systemctl daemon-reload
sudo systemctl enable --now poifo-deploy-agent.timer
```

- `poifo-deploy-agent` のアクセスキーは IAM コンソール → ユーザー → `poifo-deploy-agent` → セキュリティ認証情報 → アクセスキーを作成（ユースケースは「AWS の外部で実行されるアプリケーション」）
- nginx の `root` は `/var/www/poifo/current/public`、`fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;`（`$realpath_root` にするとリンク切り替え後に新しいリリースを読む）
- 最初のデプロイが成功したら、キューのワーカーを起動する: `sudo systemctl enable --now poifo-queue`

### 3. 最初のデプロイ

1. Actions → **デプロイ（バックエンド）** → Run workflow。1〜2分でサーバが取り込み、ジョブサマリーに結果が出る
2. Actions → **デプロイ（フロント）** → Run workflow

## うまくいかない時

| 症状 | 原因と対処 |
|---|---|
| 「AWS_DEPLOY_ROLE_ARN が未登録のためスキップ」 | 初回の準備1。Secret を登録してから再実行 |
| `Not authorized to perform sts:AssumeRoleWithWebIdentity` | Environment `poifo-deploy` がない、または bootstrap の `deploy_environment` と名前が違う |
| 「10分待ってもサーバが取り込みませんでした」 | サーバで `systemctl status poifo-deploy-agent.timer`、`journalctl -u poifo-deploy-agent -n 100`。アクセスキーと `DEPLOY_BUCKET` を確認 |
| 「migrate で失敗しました」 | 今のリリースのまま動いている。`journalctl -u poifo-deploy-agent` でエラーを見て、直したコミットを main に入れる |
| フロントで「CloudFront が見つかりません」 | app スタックが未構築、または Variables の `PROJECT` が違う |
| フロントが古いまま | キャッシュ削除の完了まで待つ。ブラウザのキャッシュも確認 |
| 同じコミットをもう一度入れたい | Actions の「Re-run jobs」でデプロイIDが変わり、取り込み直す |
