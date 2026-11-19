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

## ホストでの振り分け

本番の Lightsail には CloudFront 経由でしか届かず、`Host` はオリジン名になる。閲覧者がアクセスしたホスト名は
CloudFront Function が付ける `x-poifo-viewer-host` ヘッダーで判断する（`App\Http\Middleware\RestrictViewerHost`）。

| ルートファイル | ホスト | ミドルウェア | 用途 |
|---|---|---|---|
| `routes/api.php` | api. | `api`（Sanctum の Cookie 認証） | 会員向け JSON API（`/v1/...`） |
| `routes/auth.php` | api. | `web` | LINE ログイン、案件への遷移（`/go/{id}`） |
| `routes/postback.php` | api. | なし（WAF で IP 制限） | ASP の成果通知（`/postback/...`） |
| `routes/admin.php` | admin. | `web`（Cookie は admin. 専用） | 管理画面 |

新しいルートを足すときは、どのホストから届くべきかを決めてから該当ファイルに書く。`bootstrap/app.php` の振り分けを崩さない。

## ポストバック（成果通知）の決まりごと

- 応答は **HTTP 200 と本文 `0` / `1` / `2` だけ**（空白・改行・HTML なし）。処理中の例外だけ 500（ASP がリトライする）。
- `hash`（HMAC-SHA256）を必ず検証する。比較は `hash_equals`。
- `cv_id` で重複を弾く。状態は「未承認 → 承認 / 否認」だけ。確定後の変更は受け付けず、前回と同じ応答を返す。
- 受信したリクエストは結果にかかわらず `postback_logs` に残す。
- 10秒でタイムアウトされるので、重い処理（通知送信など）は同期で行わずキューに回す。

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
