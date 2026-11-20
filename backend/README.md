# POIFO バックエンド

ポイフォの API・ASP 成果通知（ポストバック）の受信・管理画面。Laravel 13 / PHP 8.4 / MySQL 8.4。
作業ルールは [AGENTS.md](AGENTS.md)（Claude Code は [CLAUDE.md](CLAUDE.md) から読み込む）。

## 本番の構成

```text
閲覧者 ─ https://<domain>        ─ CloudFront ─ S3（frontend/ の静的サイト）
       ─ https://api.<domain>    ┐
       ─ https://admin.<domain>  ┴ CloudFront＋WAF ─ Lightsail（nginx＋PHP-FPM＋この Laravel）─ Lightsail マネージドDB（MySQL）
GF Rewards ─ https://api.<domain>/postback/<秘密の文字列>/gf-rewards（WAF とアプリの両方で送信元 IP を制限）
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
| `GET/PUT /v1/me/bank-account` | api. | 会員 | 振込先口座の表示（口座番号は末尾3桁以外伏せ字）・登録／変更。同じ口座がほかの会員にあれば登録できない |
| `GET/POST /v1/me/withdrawals` | api. | 会員 | 換金申請の履歴（残高・最低ポイント・口座登録の有無つき）・申請 |
| `GET/POST /postback/{token}/gf-rewards` | api. | パスの文字列・送信元IP・hash | GF Rewards の成果通知。本文 `0`/`1`/`2` を返す（下の「成果通知の受信」） |
| `GET /login` → `/two-factor/challenge` | admin. | — | 管理者ログイン（パスワード → 認証アプリの6桁コード）。初回は `/two-factor/setup` で登録 |
| `GET /` | admin. | 管理者 | ホーム（未確認・振込待ちの件数） |
| `GET /withdrawals` | admin. | 管理者 | 換金申請の一覧（申請月・状態で絞り込み、同一口座・同一名義の警告） |
| `POST /withdrawals/bulk` | admin. | 管理者 | 選んだ申請を承認／却下（ポイントを戻す）／振込済みにする |
| `GET /withdrawals/export` | admin. | 管理者 | 振込用 CSV（UTF-8 BOM 付き・CRLF。Excel で開ける） |
| `GET /up` | どちらも | — | ヘルスチェック |

スケジューラ: `gf:sync-campaigns`（15分ごと。GF Rewards 案件連携API → `campaigns`。API は1時間60回まで）

### データ（主なテーブル）

| テーブル | 内容 |
|---|---|
| `users` | 会員。LINE のユーザーIDと、ASP に渡す `uid`（ULID）。退会しても行は残す |
| `campaigns` / `campaign_clicks` | 案件のキャッシュ（運営の表示設定つき）とクリック記録 |
| `postback_logs` / `conversions` | 成果通知の受信ログ（拒否したものも全件）と、cv_id ごとの成果（未承認／承認／否認、保留の理由） |
| `point_transactions` | ポイント台帳（判定中・確定・否認）。残高は確定分の合計 |
| `bank_accounts` / `withdrawal_requests` | 振込先口座（口座番号は暗号化）と換金申請（審査期間・全銀CSV出力の単位） |
| `events` | イベント（みんなでポイ活） |
| `admins` / `admin_audit_logs` | 管理者（2FA 用の列あり）と操作ログ |

### 成果通知の受信（お金に直結するので多重に確かめる）

上から順に確かめ、どこかで通らなければポイントを確定しない。

1. WAF: `/postback/` 配下は GF Rewards の送信元 IP 以外を拒否
2. URL の秘密の文字列（`POIFO_POSTBACK_PATH_TOKEN`）。違えば 404。値はアプリの `.env` だけに置き、GF Rewards の管理画面に URL ごと登録する
3. 送信元 IP（`POIFO_POSTBACK_ALLOWED_IPS`、CIDR 可）。IP は CloudFront Function が付ける `x-poifo-viewer-ip` から取る（`X-Forwarded-For` の先頭は偽装できるので使わない）。
   本番で許可リストが空なら全て拒否する
4. `hash`（HMAC-SHA256）
5. 案件APIで取得済みの情報との突き合わせ。会員・案件・成果地点が見つからない、定額案件でポイント数が違う、定率案件で上限を超える場合は
   **保留**（`conversions.hold_reason`）にして、承認の通知が来ても判定中のままにする（ASP には `0`）。一度保留にしたら自動では解除しない

拒否・保留を含めて全件を `postback_logs` に残す。次の場合は `POIFO_ALERT_EMAILS` にメールで知らせる（同じ種類は10分に1通。送信はキュー）:
パスの文字列や送信元 IP の不一致、署名の不一致、保留、処理の失敗、1分間に `POIFO_POSTBACK_BURST_PER_MINUTE` 件を超える受信。

会員単位の回数制限: 会員 API 全体は1分120回、口座の登録・変更は1分3回・1日5回、換金申請は1分3回・1日10回（`AppServiceProvider`）。

### 換金（当面は月1回・手作業で振込）

1. 会員が換金を申請 → 申請時点の口座情報を保存し、ポイントを台帳から引く（審査期間 `POIFO_WITHDRAWAL_REVIEW_DAYS` 日は承認できない）
2. 月末に管理者が `admin.<domain>/withdrawals` でその月の申請を確認し、承認する（不審なものは却下 → ポイントを会員に戻す）
3. 状態「承認済み（振込待ち）」で CSV を出力（氏名・口座名義カナ・銀行／支店コードと名前・種別・口座番号・振込額）
4. 銀行で人の手で振り込む → 振り込んだ申請を選んで「振込済みにする」

CSV の出力・承認・却下・振込済み・ログインは `admin_audit_logs` に記録する。
会員は frontend の `/mypage/bank/`（口座登録）と `/mypage/withdraw/`（申請）から使う。

### 管理者の追加

```sh
php artisan admin:create admin@example.com "山田"   # パスワードは対話で入力（12文字以上）
php artisan admin:reset-two-factor admin@example.com  # スマホ紛失などで二要素認証をやり直すとき
```

初回ログイン時に二要素認証（Google Authenticator などの認証アプリ）を登録する。登録しないとログインできない。

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
php artisan db:seed         # サンプルの案件・イベント・換金申請（任意）
composer dev                # http://localhost:8000
```

- 手早く試すなら `.env` を `DB_CONNECTION=sqlite` にして `DB_DATABASE` の行を消す（`database/database.sqlite` を使う）。本番に近づけるなら `docker compose up -d` で MySQL 8.4 を起動して `DB_CONNECTION=mysql`
- ローカルでは `POIFO_ALLOW_HOST_FALLBACK=true` にして、Host ヘッダーで api / admin を判定する。
  `http://api.localhost:8000` と `http://admin.localhost:8000` でそれぞれ開ける（`*.localhost` は多くの環境で 127.0.0.1 に解決される）
- フロント（`http://localhost:4321`）からの Cookie 認証のため、`SESSION_DOMAIN=localhost`・`SANCTUM_STATEFUL_DOMAINS=localhost:4321`
- LINE ログインを試すには LINE Developers でチャネルを作り、コールバックURLに `http://api.localhost:8000/auth/line/callback` を登録する

### API 仕様書（Swagger 相当。開発期間中だけ）

`POIFO_API_DOCS_ENABLED=true` のとき、api. に API 仕様書が出る。`.env.example` と本番は false なので、見るときだけ手元の `.env` を true にする。
ルートやバリデーション・返却値から自動で作るので、手で書く仕様書はない（[Scramble](https://scramble.dedoc.co/)）。

- 画面: `http://api.localhost:8000/docs/api`（Try It でその場で API を呼べる）
- OpenAPI の JSON: `http://api.localhost:8000/docs/api.json`（Postman などに読み込める）
- 載せるのは会員 API（`/v1`）・LINE ログイン・案件遷移（`/go`）・成果通知（`/postback`）。管理画面（admin.）は載せない
- false のときはルート自体を作らないので 404 になる。サーバでは `.env` を変えたあと `php artisan route:cache` し直す（デプロイでも実行される）
- ログインが必要な API は、同じブラウザで LINE ログインを済ませておけば Cookie で通る。POST/PUT は先に `/sanctum/csrf-cookie` を開き、
  発行された `XSRF-TOKEN` Cookie の値（URL デコードしたもの）を Try It の `X-XSRF-TOKEN` 欄に入れる
- サーバ上で使うときは `SANCTUM_STATEFUL_DOMAINS` に `api.<domain>` も足す（足さないとログインが必要な API が 401 になる）。開発期間が終わったら両方戻す

## 本番の設定（サーバの `.env`）

`.env.example` との違いだけ。秘密の値はサーバの `.env` にだけ置く。

| 変数 | 値 |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://api.<domain>` |
| `POIFO_API_HOST` / `POIFO_ADMIN_HOST` | `api.<domain>` / `admin.<domain>` |
| `POIFO_ALLOW_HOST_FALLBACK` | `false` |
| `POIFO_FRONT_URL` | `https://<domain>` |
| `POIFO_API_DOCS_ENABLED` | `false`（開発期間中に API 仕様書を見るときだけ `true`） |
| `DB_HOST` など | Lightsail マネージドDB のエンドポイント・ユーザー・パスワード |
| `MYSQL_ATTR_SSL_CA` | AWS の CA バンドル（`global-bundle.pem`）のパス |
| `SESSION_DOMAIN` | `.<domain>`（フロントと api. で Cookie を共有） |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | `<domain>` |
| `LINE_LOGIN_*` | LINE ログインのチャネル。コールバックは `https://api.<domain>/auth/line/callback` |
| `LINE_OFFICIAL_FRIEND_URL` | 公式アカウントの友だち追加URL |
| `GF_REWARDS_API_KEY` / `GF_REWARDS_AD_CHANNEL_ID` | GF Rewards から受け取った値 |
| `POIFO_POSTBACK_PATH_TOKEN` | 成果通知の URL に入れる推測されにくい文字列（例: `openssl rand -hex 24` の出力）。GF Rewards には `https://api.<domain>/postback/<この値>/gf-rewards` を登録 |
| `POIFO_POSTBACK_ALLOWED_IPS` | GF Rewards の送信元 IP（カンマ区切り。値は ASP 資料を参照し、リポジトリには書かない） |
| `POIFO_CLIENT_IP_HEADER` / `POIFO_CLIENT_IP_FALLBACK` | `x-poifo-viewer-ip` / `false` |
| `POIFO_ALERT_EMAILS` | 異常を知らせる管理者のメールアドレス（カンマ区切り） |
| `MAIL_MAILER` など | 通知メールの送信設定（Amazon SES など） |

cron に `* * * * * cd /var/www/poifo/current && php artisan schedule:run >> /dev/null 2>&1` を入れる。
キューは当面 `database`（`php artisan queue:work` を systemd で常駐）。
どちらも設定ファイルを [../deploy/backend/server/](../deploy/backend/server/) に用意してある。

### API サーバを増やすとき（スケールアウト）

アプリはサーバ内に状態を持たない作りにしてある（セッション・キャッシュ・キュー・ロックは共有 DB、ファイルは保存しない）。負荷が増えたら手動で台数を増やし、ロードバランサで振り分ける。

1. 1台目と同じ手順でサーバを作る。デプロイエージェント・cron（`schedule:run`）・`queue:work` も同じ設定で入れる
2. `.env` は **`APP_KEY` を含めて全台同じ**にする（口座番号などの暗号化とセッションに使う。違うと復号できない）
3. cron と `queue:work` は全台で動かしてよい（スケジュールのタスクは `onOneServer()` で1台だけが実行する）
4. マイグレーションは同時に1台だけが実行するようにする（`php artisan migrate --force --isolated`。共有 DB のロックで2台目以降は待たずに抜ける）
5. ロードバランサを CloudFront と API サーバの間に入れ、`origin.<domain>` をロードバランサに向ける。オリジン確認ヘッダー（`X-Origin-Verify`）・`x-poifo-viewer-host`・`x-poifo-viewer-ip` はそのまま各サーバへ渡す
6. ログは各サーバの `storage/logs` に出る。台数が増えたら CloudWatch Logs などに集める

## デプロイ

main に `backend/` の変更が入ると、ワークフロー「デプロイ（バックエンド）」がテスト・ビルドしたリリースを S3 に置き、
サーバの deploy agent が取り込む（migrate・キャッシュ作成・切り替えまで自動）。サーバ上の配置は
`/var/www/poifo/current`（最新リリースへのリンク）、`.env` と `storage` は `/var/www/poifo/shared/`。
詳しくは [../docs/deploy/README.md](../docs/deploy/README.md)。

## まだやっていないこと

- 管理画面の残りの機能（会員検索・停止、ポイント手動調整、ポストバックログ、保留した成果の確認・解除、案件の表示設定、イベント登録）
- 退会 API
- サーバの初期設定手順（nginx・PHP のインストールなど。デプロイに必要な分は docs/deploy/README.md にある）
- GF Rewards に確認すること: 判定中（未承認）の通知への応答を `0` にしているが、それでよいか
