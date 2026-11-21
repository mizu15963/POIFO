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
| Let's Encrypt | `ISSUE_CERT=true` のとき `origin.<domain>` の証明書を取る。確認は DNS（Route 53 の TXT レコード）なので、固定 IP がまだ別のサーバを指していても取れる。更新は certbot の自動更新、更新後に nginx を読み直す |
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

インフラの段階2（[../infra/README.md](../infra/README.md) の6）が済んだら（Route 53 のゾーンと、deploy agent ユーザーの証明書用の権限ができる）、
`/etc/poifo/server.env` に `ORIGIN_HOST=origin.<domain>`、`ISSUE_CERT=true` を入れて、スクリプトを再実行する。証明書を取って 443番が動く。

LB を入れた後に足した台は、HTTPS を LB が終端するので `ISSUE_CERT=false` のままでよい。

## サーバの入れ替え（1台構成）

ミドルウェアの更新・OS の更新・サイズ変更は、動いているサーバを触らず、新しいサーバを作って固定 IP を移す。
CloudFront のオリジン（`origin.<domain>`）は固定 IP を指したままなので、DNS の切り替え待ちはない。並んで動くのは作業中だけ（1GB なら1時間あたり1円程度）。

1台目は「枠 a」（`poifo-api-a`）と「枠 b」（`poifo-api-b`）を持ち、固定 IP は Variables の `API_ACTIVE_SLOT` の枠に付く。以下は a から b へ入れ替える例（b から a のときは逆に読む）。

1. **新しいサーバを作る**: Variables の `API_STANDBY` を `true`（サイズを変えるなら `API_B_BUNDLE` も、例 `small_3_0`）にして **更新（Terraform）** を手動実行。
   `poifo-api-b` ができる（固定 IP はまだ a）。ジョブサマリーの outputs の `standby_instance` で確かめる
2. **新しいサーバを準備する**: `poifo-api-b` に SSH で入り、上の「手順」の1・3を行う（DB ユーザーは作らない）。
   - `/var/www/poifo/shared/.env` は a と同じ内容（a の `sudo cat` をコピー）
   - `/etc/poifo/server.env` も a と同じ（`ISSUE_CERT=true` なら、DNS で確認するのでこの時点で証明書を取れる）
   - `/etc/poifo/deploy-agent.env` は `SERVER_NAME=api-b`、**`MIGRATE=false`**（migrate は a が行う）
   - Actions → **デプロイ（バックエンド）** を手動実行する（a が migrate し、b はそれを待ってから同じリリースに切り替える）。
     `curl -s -o /dev/null -w '%{http_code}\n' http://localhost/up` が 200 になり、`sudo journalctl -u poifo-deploy-agent` にエラーがないことを確かめる。
     準備中に b が古いデプロイを取り込もうとして「primary の migrate 待ち」で失敗していても、この手動実行で取り込み直せる
     秘密ヘッダー付きで主な API を叩いて確かめてもよい（`curl -H "X-Origin-Verify: <値>" -H "x-poifo-viewer-host: api.<domain>" http://localhost/v1/campaigns`）
3. **固定 IP を移す**: `API_ACTIVE_SLOT` を `b` にして **更新（Terraform）** を手動実行。数秒〜数十秒で CloudFront からのリクエストが b に届く。
   サイトと管理画面で動作を確かめる。**問題があれば `API_ACTIVE_SLOT` を `a` に戻して更新するだけで元に戻る**
4. **役割を移す**: b の `deploy-agent.env` を `MIGRATE=true`（`SERVER_NAME` は `api-b` のままでよい）、a の deploy agent を止める（`sudo systemctl disable --now poifo-deploy-agent.timer poifo-queue`）。
   DB のバックアップをしているなら `backup.env` と `backup.pass` も b に移して、b でスクリプトを再実行する
5. **古いサーバを消す**: 数時間〜1日ようすを見てから、`API_STANDBY` を `false` にして **更新（Terraform）** を手動実行。`poifo-api-a` が消える。
   サイズを変えたなら、次の入れ替えに備えて `API_B_BUNDLE` はそのまま残す（a を作り直すときは `API_A_BUNDLE` を使う）
6. Lightsail のアラーム（下の「監視」）を b にも付ける

- 更新ワークフローは、固定 IP が付いているサーバを消す変更と、まだ無いサーバに固定 IP を移す変更を止める。手順1・3・5を1回の更新にまとめようとすると止まる
- cron（スケジューラ）とキューのワーカーは a・b の両方で動いても問題ない（スケジュールは1台だけが実行する作り）
- デプロイは a・b の両方が取り込む。デプロイのワークフローは `API_SERVER_COUNT` 台分の結果がそろえば終わる

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
2. インスタンス `poifo-api-a`（台ごと。入れ替えたら新しいサーバにも）→ メトリクス →「アラームを追加」
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
| 「証明書を取れませんでした」 | 段階2の apply 前（Route 53 のゾーンや証明書用の権限がない）、またはネームサーバの委任が未反映。LB ありなら証明書は要らない（`ISSUE_CERT=false`） |
| `/up` が 502 | まだデプロイしていない、または PHP-FPM が止まっている（`systemctl status php8.4-fpm`） |
| 何でも 403 | 秘密ヘッダーの値が GitHub Secrets と違う。`/etc/poifo/server.env` を直して再実行し、**更新（Terraform）** で CloudFront 側も同じ値か確認 |
| DB に繋がらない | `.env` の `MYSQL_ATTR_SSL_CA` と `DB_HOST`。アプリ用ユーザーは TLS 必須（`REQUIRE SSL`） |
