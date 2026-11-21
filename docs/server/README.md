# API サーバの初期設定

Lightsail（Ubuntu 24.04）を作った直後に1回やる作業。スクリプト `deploy/backend/server/setup-server.sh` にまとめてあり、
**何度実行してもよい**（入っているものは飛ばし、設定ファイルは置き直す）。1台目・増やした台（スケールアウト）・入れ替えで作った新しい台、どれも同じ手順でよい。

前提: インフラの段階1の構築（[../infra/README.md](../infra/README.md) の5）が済んでいること。

## スクリプトがやること

| 項目 | 内容 |
|---|---|
| OS | タイムゾーン Asia/Tokyo、スワップ 2GB（1GB のサーバでメモリ不足で落ちないように）、セキュリティ更新の自動適用（unattended-upgrades） |
| PHP 8.4 | ondrej/php の PPA から php8.4-fpm と拡張（mbstring・intl・xml・curl・mysql・bcmath・zip・opcache）。1GB 向けの FPM 設定、opcache |
| nginx | 80番は常にアプリを動かす（LB は HTTP で届くため）。秘密ヘッダー `X-Origin-Verify` がなければ 403。例外は `/up`（LB のヘルスチェック）と Let's Encrypt の確認だけ。証明書があれば 443番も同じ設定で動かす |
| Let's Encrypt | `ISSUE_CERT=true` のとき `origin.<domain>` の証明書を取る。更新は certbot の自動更新、更新後に nginx を読み直す |
| デプロイ | deploy agent（1分ごとにリリースを取りに行く）、キューのワーカー、Laravel のスケジューラ（cron）。仕組みは [../deploy/README.md](../deploy/README.md) |
| DB 接続 | マネージドDB に TLS で接続するための CA（`/etc/poifo/rds-global-bundle.pem`） |
| バックアップ | `/etc/poifo/backup.env` がある台だけ、毎日 3:30 に DB を暗号化して S3 へ（下の「DB のバックアップ」） |

LB の有無で nginx の設定は変わらない。LB を入れるときにサーバ側の作業は要らない。

## 手順

### 1. スクリプトを実行する（1回目は雛形ができて止まる）

Lightsail コンソール → インスタンス → 「SSH を使用して接続」（または手元PCから SSH）。

```sh
sudo git clone https://github.com/mizu15963/POIFO.git /opt/poifo-src
sudo /opt/poifo-src/deploy/backend/server/setup-server.sh
```

パッケージを入れたあと、次の3つの雛形を作って「値を入れてからもう一度実行してください」で止まる。

| ファイル | 中身 |
|---|---|
| `/etc/poifo/server.env` | `ORIGIN_VERIFY_SECRET`（GitHub Secrets と同じ値）、`ORIGIN_HOST`（独自ドメインの段階で `origin.<domain>`）、`ISSUE_CERT` |
| `/etc/poifo/deploy-agent.env` | `poifo-deploy-agent` のアクセスキー、`DEPLOY_BUCKET`（app の output `deploy_bucket`）、`SERVER_NAME`（台ごとに別）、`MIGRATE`（**1台だけ true**） |
| `/var/www/poifo/shared/.env` | アプリの本番設定（項目は [../../backend/README.md](../../backend/README.md) の「本番の設定」）。`MYSQL_ATTR_SSL_CA=/etc/poifo/rds-global-bundle.pem`。**2台目以降は1台目と同じ内容**（`APP_KEY` を含む） |

アクセスキーは IAM コンソール → ユーザー → 該当ユーザー → セキュリティ認証情報 → アクセスキーを作成（「AWS の外部で実行されるアプリケーション」）。

### 2. DB のアプリ用ユーザーを作る（1回だけ。2台目以降は不要）

マスターユーザー（`dbadmin`）はアプリに使わない。データベース `poifo` の中だけ操作できるユーザー `poifo_app` を作る。

```sh
sudo /opt/poifo-src/deploy/backend/server/setup-db-user.sh <base の output db_endpoint>
# dbadmin のパスワード（DB_MASTER_PASSWORD）を聞かれる → DB_USERNAME / DB_PASSWORD が表示される
```

表示された値を `.env` の `DB_USERNAME` / `DB_PASSWORD` に入れる（この画面にしか出ない。パスワードマネージャーにも保存）。
何度実行してもよい。パスワードを作り直すときは `sudo RESET_PASSWORD=true …`（全台の `.env` を直す）。

### 3. もう一度スクリプトを実行する

```sh
sudo /opt/poifo-src/deploy/backend/server/setup-server.sh
```

### 4. 最初のデプロイ

Actions → **デプロイ（バックエンド）** → Run workflow。1〜2分で取り込まれる。
取り込んだあと、キューのワーカーを起動するためにもう一度スクリプトを実行する（`poifo-queue` が有効になる）。

確認:

```sh
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/up                                   # 200
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/v1/campaigns                          # 403（秘密ヘッダーなし）
```

### 5. 独自ドメインの段階になったら（1台目、LB なし）

インフラの段階2（[../infra/README.md](../infra/README.md) の6）で `origin.<domain>` が固定 IP を指したあと、
`/etc/poifo/server.env` に `ORIGIN_HOST=origin.<domain>`、`ISSUE_CERT=true` を入れて、スクリプトを再実行する。証明書を取って 443番が動く。

LB を入れた後に足した台は、HTTPS を LB が終端するので `ISSUE_CERT=false` のままでよい。

## スクリプトや設定を更新したとき

```sh
sudo git -C /opt/poifo-src pull
sudo /opt/poifo-src/deploy/backend/server/setup-server.sh
```

`server.env` を直したとき（秘密ヘッダーの値の変更など）も、再実行すれば nginx の設定が作り直される。nginx の設定ファイルは手で直さない。

## DB のバックアップ

マネージドDB の自動バックアップ（毎日、7日分）とは別に、AWS の外でも戻せる形で、暗号化したダンプを S3（`poifo-backup-<アカウントID>`、30日で自動削除）に置く。
**1台だけ**（通常は `MIGRATE=true` の台）で設定する。

```sh
sudo install -m 600 /opt/poifo-src/deploy/backend/server/backup.env.example /etc/poifo/backup.env
sudo nano /etc/poifo/backup.env          # poifo-backup-writer のアクセスキー、BACKUP_BUCKET（base の output backup_bucket）
openssl rand -hex 32 | sudo tee /etc/poifo/backup.pass >/dev/null && sudo chmod 600 /etc/poifo/backup.pass
sudo cat /etc/poifo/backup.pass          # パスワードマネージャーに保存（無くすと戻せない）
sudo /opt/poifo-src/deploy/backend/server/setup-server.sh   # cron が入る
sudo /usr/local/bin/poifo-db-backup      # 1回試す。ログは /var/log/poifo-db-backup.log
```

- `poifo-backup-writer` は置くことしかできない（読めない・消せない）。サーバが乗っ取られてもバックアップは消されない
- 戻すとき: 管理者で S3 からダウンロード → `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:backup.pass -in <ファイル> | gunzip > dump.sql` → `mysql` で流し込む

## 監視（Lightsail のアラーム。コンソールで設定）

メールアドレスは個人情報なので Terraform（公開される Actions ログ）では扱わず、コンソールで設定する。

1. Lightsail コンソール → アカウント → 通知の連絡先 → メールアドレスを追加し、届いたメールで確認する（yamada さん・クライアント）
2. インスタンス `poifo-api`（台ごと）→ メトリクス →「アラームを追加」
   - CPU 使用率: 5分平均が 80% 以上を 2回続けたら
   - ステータスチェックの失敗: 1以上を 2回続けたら
   - バースト容量（CPU クレジット）: 残り 20% 以下（1GB プランはバースト型。使い切ると遅くなる）
3. データベース `poifo-db` → メトリクス → CPU 使用率 80% 以上、空きストレージ 2GB 以下
4. LB を入れたら、LB の「正常でないホスト数」1以上

アプリの異常（成果通知の拒否・保留など）はアプリが SES でメールする（[../infra/README.md](../infra/README.md) の「通知メール」）。

## うまくいかない時

| 症状 | 原因と対処 |
|---|---|
| 「Ubuntu 24.04 向けです」 | インスタンスのブループリントが違う。Terraform の既定（`ubuntu_24_04`）で作り直す |
| 「証明書を取れませんでした」 | `origin.<domain>` がまだこのサーバを指していない（段階2の apply 前、DNS 未反映、または LB ありの構成）。LB ありなら `ISSUE_CERT=false` |
| `/up` が 502 | まだデプロイしていない、または PHP-FPM が止まっている（`systemctl status php8.4-fpm`） |
| 何でも 403 | 秘密ヘッダーの値が GitHub Secrets と違う。`/etc/poifo/server.env` を直して再実行し、**更新（Terraform）** で CloudFront 側も同じ値か確認 |
| DB に繋がらない | `.env` の `MYSQL_ATTR_SSL_CA` と `DB_HOST`。アプリ用ユーザーは TLS 必須（`REQUIRE SSL`） |
