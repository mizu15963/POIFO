# POIFO バックエンド

ポイフォの API・ASP 成果通知（ポストバック）の受信・管理画面。Laravel 13 / PHP 8.4 / MySQL 8.4。
作業ルールは [AGENTS.md](AGENTS.md)（Claude Code は [CLAUDE.md](CLAUDE.md) から読み込む）。

## 本番の構成

```text
閲覧者 ─ https://<domain>        ─ CloudFront ─ S3（frontend/ の静的サイト）
       ─ https://api.<domain>    ┐
       ─ https://admin.<domain>  ┴ CloudFront＋WAF ─ Lightsail（nginx＋PHP-FPM＋この Laravel）─ Lightsail マネージドDB（MySQL）
GF Rewards ─ https://api.<domain>/postback/gf-rewards（WAF で送信元 IP を制限）
```

- api. と admin. は同じ Laravel。閲覧者のホスト名（CloudFront が付ける `x-poifo-viewer-host`）でルートを分ける
- DB は Lightsail マネージドDB（MySQL・標準プラン）。アプリ用 Lightsail とは別。TLS で接続する（`MYSQL_ATTR_SSL_CA`）
- インフラの作り方は [../docs/infra/README.md](../docs/infra/README.md)

## 機能と API（いまある分）

| メソッド・パス | ホスト | 認証 | 内容 |
|---|---|---|---|
| `GET /auth/line/redirect` | api. | — | LINE ログインへ（会員登録も兼ねる） |
| `GET /auth/line/callback` | api. | — | LINE から戻る → フロントの `/auth/complete/` か `/auth/error/?reason=...` へ |
| `GET /go/{campaign}` | api. | 会員 | 計測リンクに会員ID（`uid`）を付けて案件へリダイレクト。未ログインはフロントの `/login/` へ |
| `GET /sanctum/csrf-cookie` | api. | — | POST の前に呼んで CSRF トークンの Cookie を受け取る |
| `GET /v1/campaigns` | api. | — | 掲載中の案件一覧（`?featured=1`、`?category=`、`?per_page=`） |
| `GET /v1/campaigns/{id}` | api. | — | 案件詳細（成果地点ごとのポイント） |
| `GET /v1/events` | api. | — | イベント一覧（`?upcoming=1`、`?limit=`） |
| `GET /v1/events/{id}` | api. | — | イベント詳細（オープンチャット URL） |
| `GET /v1/me` | api. | 会員 | 表示名・ポイント（利用可能／判定中）・公式LINE の友だち追加URL |
| `GET /v1/me/points` | api. | 会員 | ポイント履歴 |
| `POST /v1/logout` | api. | 会員 | ログアウト |
| `GET/POST /postback/gf-rewards` | api. | hash 検証 | GF Rewards の成果通知。本文 `0`/`1`/`2` を返す |
| `GET /` | admin. | （予定）管理者＋2FA | 管理画面（いまは準備中ページだけ） |
| `GET /up` | どちらも | — | ヘルスチェック |

スケジューラ: `gf:sync-campaigns`（15分ごと。GF Rewards 案件連携API → `campaigns`。API は1時間60回まで）

### データ（主なテーブル）

| テーブル | 内容 |
|---|---|
| `users` | 会員。LINE のユーザーIDと、ASP に渡す `uid`（ULID）。退会しても行は残す |
| `campaigns` / `campaign_clicks` | 案件のキャッシュ（運営の表示設定つき）とクリック記録 |
| `postback_logs` / `conversions` | 成果通知の受信ログ（全件）と、cv_id ごとの成果（未承認／承認／否認） |
| `point_transactions` | ポイント台帳（判定中・確定・否認）。残高は確定分の合計 |
| `bank_accounts` / `withdrawal_requests` | 振込先口座（口座番号は暗号化）と換金申請（審査期間・全銀CSV出力の単位） |
| `events` | イベント（みんなでポイ活） |
| `admins` / `admin_audit_logs` | 管理者（2FA 用の列あり）と操作ログ |

### ポイントの流れ

1. 会員が案件を押す → `/go/{id}` で計測リンクに `user_id=<uid>` を付けて遷移
2. GF Rewards が成果を通知（未承認）→ 台帳に **判定中** で記録（応答 `0`）
3. 承認の通知 → 同じ行を **確定** に（応答 `1`）。否認なら **否認**（応答 `0`）
4. 退会者の成果は記録だけして応答 `2`。登録のない会員IDは記録だけして応答 `0`

## ローカル開発

必要なもの: PHP 8.4 以上（テストの PHPUnit 13 が 8.4 を要求）、Composer、（MySQL を使うなら）Docker。

```sh
cd backend
composer setup              # composer install・.env 作成・APP_KEY 生成・マイグレーション
php artisan test
php artisan db:seed         # サンプルの案件・イベント（任意）
composer dev                # http://localhost:8000
```

- 手早く試すなら `.env` を `DB_CONNECTION=sqlite` にして `DB_DATABASE` の行を消す（`database/database.sqlite` を使う）。本番に近づけるなら `docker compose up -d` で MySQL 8.4 を起動して `DB_CONNECTION=mysql`
- ローカルでは `POIFO_ALLOW_HOST_FALLBACK=true` にして、Host ヘッダーで api / admin を判定する。
  `http://api.localhost:8000` と `http://admin.localhost:8000` でそれぞれ開ける（`*.localhost` は多くの環境で 127.0.0.1 に解決される）
- フロント（`http://localhost:4321`）からの Cookie 認証のため、`SESSION_DOMAIN=localhost`・`SANCTUM_STATEFUL_DOMAINS=localhost:4321`
- LINE ログインを試すには LINE Developers でチャネルを作り、コールバックURLに `http://api.localhost:8000/auth/line/callback` を登録する

## 本番の設定（サーバの `.env`）

`.env.example` との違いだけ。秘密の値はサーバの `.env` にだけ置く。

| 変数 | 値 |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://api.<domain>` |
| `POIFO_API_HOST` / `POIFO_ADMIN_HOST` | `api.<domain>` / `admin.<domain>` |
| `POIFO_ALLOW_HOST_FALLBACK` | `false` |
| `POIFO_FRONT_URL` | `https://<domain>` |
| `DB_HOST` など | Lightsail マネージドDB のエンドポイント・ユーザー・パスワード |
| `MYSQL_ATTR_SSL_CA` | AWS の CA バンドル（`global-bundle.pem`）のパス |
| `SESSION_DOMAIN` | `.<domain>`（フロントと api. で Cookie を共有） |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | `<domain>` |
| `LINE_LOGIN_*` | LINE ログインのチャネル。コールバックは `https://api.<domain>/auth/line/callback` |
| `LINE_OFFICIAL_FRIEND_URL` | 公式アカウントの友だち追加URL |
| `GF_REWARDS_API_KEY` / `GF_REWARDS_AD_CHANNEL_ID` | GF Rewards から受け取った値 |

cron に `* * * * * cd /var/www/poifo/backend && php artisan schedule:run >> /dev/null 2>&1` を入れる。
キューは当面 `database`（`php artisan queue:work` を systemd で常駐）。

## まだやっていないこと

- 管理画面のログイン（パスワード＋二要素認証）と各機能（会員検索・停止、ポイント手動調整、換金審査・全銀CSV、ポストバックログ、案件の表示設定、イベント登録）
- 口座登録・換金申請の API と画面
- 退会 API
- デプロイ（GitHub Actions からサーバへの配置）とサーバの初期設定手順
- GF Rewards に確認すること: 判定中（未承認）の通知への応答を `0` にしているが、それでよいか
