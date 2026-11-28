# backend/ の作業ルール（AI エージェント・開発者共通）

POIFO（ポイフォ）のバックエンド。Laravel 13 / PHP 8.4 / MySQL 8.4。
API（`api.<domain>`）・ASP のポストバック受信（`api.<domain>/postback/`）・管理画面（`admin.<domain>`）を **1つの Laravel** で動かす。
全体の構成は [README.md](README.md) と [../docs/infra/README.md](../docs/infra/README.md) を先に読むこと。

## 絶対に守ること

- **このリポジトリは public。** 秘密の値（APIキー、LINE のチャネルシークレット、DB パスワード、ASP の送信元 IP、AWS アカウントID）をコード・テスト・ドキュメント・コミットメッセージに書かない。値は `.env`（コミットしない）と GitHub Secrets に置き、`.env.example` には空欄か例だけを書く。
- **ASP（GF Rewards）の資料は社外秘。** 原本や文面をリポジトリに貼らない。実装に必要な要点は [../docs/partners/gf-rewards/README.md](../docs/partners/gf-rewards/README.md) にある範囲で書く。
- **ポイントは台帳（`point_transactions`）で管理する。** 残高の列を作って直接増減しない。付与・取消・換金はすべて台帳の行の追加・状態変更で表す。残高は `status = confirmed` の合計、判定中は `pending` の合計。
- **会員の行は消さない。** 退会は `status = withdrawn`。LINE のユーザーIDは不正対策（再入会防止）のため退会後も残す。
- **ASP に渡す会員IDは `users.uid`（ULID）。** LINE のユーザーID や連番の `id` を外部に出さない。
- **口座番号は暗号化キャスト（`encrypted`）で保存する。** 同一口座の検知は `BankAccount::fingerprint()` で行い、平文で比較・ログ出力しない。
- **管理画面のルートは `routes/admin.php` だけに置く。** `viewer.host:admin` が付いたグループ以外に管理機能を足さない。管理者の操作（ポイント調整・換金承認・会員停止など）は `admin_audit_logs` に記録する。

## スケールアウト前提（API サーバを複数台に増やせること）

負荷が増えたら API サーバ（Lightsail）を手動で増やし、前段のロードバランサで振り分ける。どのサーバにリクエストが届いても同じ結果になるよう、**状態をサーバの中に持たない。**

- セッション・キャッシュ・キュー・ロック・試行回数制限は共有の DB に置く（`SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` は `database`。将来 Redis にしてもよいが、`file` / `array` にはしない）。
- メンテナンスモードは `APP_MAINTENANCE_DRIVER=cache`（全台に効く）。
- ファイルをサーバのディスクに保存しない。アップロード等が必要になったら S3 に置く（`Storage::disk('s3')`）。CSV のような出力はその場でストリームで返す。
- スケジュール実行は全台の cron から呼ばれるので、**`Schedule` に登録するタスクには必ず `->onOneServer()` を付ける**（テストで確認している）。重い処理はキューに回す。
- 同時に更新される可能性がある処理（ポイント・換金・成果通知）は、DB のトランザクションと `lockForUpdate()` で守る。PHP のメモリやローカルファイルでの排他に頼らない。
- `APP_KEY` は全台で同じ値にする（口座番号などの暗号化とセッションに使う。台ごとに違うと復号できない）。
- マイグレーションは同時に1台だけが実行する（`migrate --force --isolated`）。

## ホストでの振り分け

本番の Lightsail には CloudFront 経由でしか届かず、`Host` はオリジン名になる。閲覧者がアクセスしたホスト名は
CloudFront Function が付ける `x-poifo-viewer-host` ヘッダーで判断する（`App\Http\Middleware\RestrictViewerHost`）。

| ルートファイル | ホスト | ミドルウェア | 用途 |
|---|---|---|---|
| `routes/api.php` | api. | `api`（Sanctum の Cookie 認証） | 会員向け JSON API（`/v1/...`） |
| `routes/auth.php` | api. | `web` | LINE ログイン、案件への遷移（`/go/{id}`） |
| `routes/postback.php` | api. | `VerifyPostbackSource`（パスの秘密の文字列・送信元 IP） | ASP の成果通知（`/postback/{token}/...`） |
| `routes/admin.php` | admin. | `web`（Cookie は admin. 専用） | 管理画面 |
| `routes/debug.php` | api. | `web`（`POIFO_DEBUG_LOGIN_ENABLED=true` のときだけ読み込む） | デバッグ用ログイン（`/auth/debug/login`） |

新しいルートを足すときは、どのホストから届くべきかを決めてから該当ファイルに書く。`bootstrap/app.php` の振り分けを崩さない。

API 仕様書（`/docs/api`、Scramble）は管理画面（admin.）の中に置き、管理者のログイン後だけ見せる（`bootstrap/app.php` の `auth:admin` のグループ）。api. には出さない。
仕様書はコードから自動生成されるので、API を変えたらバリデーション（`$request->validate()`）と返却値の形を明示し、
`/docs/api` で意図どおりに載っているか確かめる。コントローラのメソッドの PHPDoc 1行目が各 API の説明になる。
仕様書には成果通知（`postback/`）も含めて全部載せる。そのため、管理者のログインを外さない。
書き出した OpenAPI（YAML）はリポジトリにコミットしない（public のため）。

デバッグ用ログイン（`DebugLoginController`）も同じフラグで有効になる。ログインできるのは `line_user_id` が `debug:` で始まる
デバッグ用の会員だけにし、実際の会員になりすませる機能（uid 指定など）は足さない。開発用の機能はすべて `routes/debug.php` に置く。

## ポストバック（成果通知）の決まりごと

- 応答は **HTTP 200 と本文 `0` / `1` / `2` だけ**（空白・改行・HTML なし）。処理中の例外だけ 500（ASP がリトライする）。
- 受信口は「URL の秘密の文字列 → 送信元 IP（`VerifyPostbackSource`。WAF では IP 制限しないので、これが唯一の IP 制限）→ hash → 案件情報との突き合わせ」の順に多重に確かめる。どれかを外さない。
- 送信元 IP は `App\Support\ClientIp` で取る（CloudFront Function が付ける `x-poifo-viewer-ip`）。`$request->ip()`（X-Forwarded-For の先頭）は偽装できるので、制限や回数制限のキーに使わない。
- `hash`（HMAC-SHA256）を必ず検証する。比較は `hash_equals`。
- 会員・案件・成果地点が見つからない、またはポイント数が案件APIの値と合わない通知は保留（`conversions.hold_reason`）にし、ポイントを確定しない。保留は自動で解除しない。
- 拒否・保留・処理失敗・大量受信は `AdminAlerter` で管理者にメールする（キューで送る。本文に秘密の値や口座番号を入れない）。
- `cv_id` で重複を弾く。状態は「未承認 → 承認 / 否認」だけ。確定後の変更は受け付けず、前回と同じ応答を返す。
- 受信したリクエストは結果にかかわらず `postback_logs` に残す。
- 10秒でタイムアウトされるので、重い処理（通知送信など）は同期で行わずキューに回す。

## 換金と管理画面の決まりごと

- 換金の状態は `WithdrawalService` だけで変える（申請 → 承認 → 完了、却下はポイントを戻す）。コントローラや画面から `status` を直接書き換えない。
- 交換先（いまは銀行振込 `bank_transfer` だけ）は `config/poifo.php` の `exchange_methods` に交換先ごとの条件を持つ。申請・審査・却下・台帳の流れは `WithdrawalService` で共通にし、交換先ごとに違う部分（受け取り情報の控えなど）は `App\Services\Exchange\ExchangeMethodHandler` の実装に置く。交換先コードは申請に保存するので、変えない・使い回さない。新しい交換先の受け取り情報は専用のテーブルを足し、`bank_accounts` に混ぜない。
- 申請には申請時点のレート・手数料・受け取り情報（`recipient_snapshot`、暗号化）を保存する。振込に使う口座情報はこの控えから出し、CSV や一覧に現在の `bank_accounts` を使わない。振込用 CSV は銀行振込の申請だけを出す。
- 個人情報（氏名・口座番号）を出す画面・CSV は管理画面（admin.）だけ。出力したら操作ログに残す。会員向け API に口座番号を返さない。
- 会員の更新系 API（特にお金・口座に関わるもの）には会員単位の回数制限（`AppServiceProvider::configureRateLimiting`）を付ける。
- 会員の状態（有効・停止・退会）は `MemberService` だけで変える。停止・退会した会員のセッションは `EnsureMemberIsActive` が次の API 呼び出しで切る。
- イベントの参加は `event_participants`（イベントと会員の組で1行）で判定し、状態は `EventParticipationService` だけで変える。上限人数はイベントの行を `lockForUpdate()` してから数える。参加ポイントは `point_transactions.event_participant_id`（unique）で1回だけ付与する。オープンチャットの URL は参加した会員にだけ返す。
- 友達紹介の報酬は `ReferralService` だけで付ける・状態を変える。紹介報酬の台帳の行（`referral` / `referral_initial`）は友達の成果の台帳の行と同じ状態にそろえ（`PostbackHandler` が成果を処理したトランザクションの中で `syncForConversion()` を呼ぶ）、確定・否認になったものは変えない。同じ成果からは `referral_rewards` の `conversion_id` + `type`（unique）で1回だけ。ランクと初回特典の数字は DB（`referral_ranks` / `referral_settings`、管理画面 A17）に持ち、config に書かない。紹介した友達の名前や使った案件を会員向け API に返さない。
- 会員のメールアドレス（連絡用。ログインには使わない）は `EmailVerificationService` だけで登録・確認・消去する。`users.email` には確認済みのアドレスだけを入れ、確認待ちは `email_verifications`（トークンは SHA-256 だけ保存）。メールアドレスが未登録（確認待ちもない）の会員は `/go/{id}` で初回登録（フロント `/welcome/`）へ移し、換金の申請には確認済みを必要にする（`WithdrawalService`）。確認前のアドレスに連絡を送らない。
- 機能の仕様は [../docs/specs/functional-spec.md](../docs/specs/functional-spec.md)、画面（管理画面を含む）は [../docs/specs/screen-spec.md](../docs/specs/screen-spec.md)、テーブル定義は [../docs/specs/database.md](../docs/specs/database.md)。仕様やマイグレーションを変えたらこれらの文書も直す。
- 管理者のログインは「パスワード → 二要素認証」が両方通ってから `Auth::guard('admin')->login()` する。途中の状態でログイン扱いにしない。

## コーディング

- 日本語でコメントを書く（なぜそうするかを書く。コードを読めば分かることは書かない）。
- コントローラは薄く、業務ロジックは `app/Services/` に置く。
- 外部 API（LINE・GF Rewards）は `Http` ファサードで呼び、テストでは `Http::fake()` を使う。実際の外部サービスにテストから接続しない。
- 日時はアプリのタイムゾーン（`Asia/Tokyo`）で保存する。ASP から来た日時はオフセット付きで解釈してから変換する。
- 金額・ポイントは整数で扱う（浮動小数で計算しない）。
- マイグレーションは追加のみ。マージ済みのマイグレーションは書き換えず、新しいファイルで変更する。

## 確認方法

変更したら必ず実行し、両方通ってからコミットする。

```sh
composer install
php artisan test          # テストは SQLite のメモリDB で動く（phpunit.xml）
./vendor/bin/pint --test  # コード整形のチェック（直すときは ./vendor/bin/pint）
```

- 新しい機能には Feature テストを書く。api. / admin. のどちらのホストで届くかもテストする（`tests/TestCase.php` の `asApiHost()` / `asAdminHost()`）。
- ポイント・換金に関わる変更は、二重付与・状態の逆戻り・退会者の扱いをテストで確かめる。
