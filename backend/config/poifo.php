<?php

use App\Services\Exchange\BankTransferHandler;

// POIFO 固有の設定。値は .env で与える（秘密の値はコミットしない）。
return [

    // 閲覧者がアクセスしたホスト名で api / admin を振り分ける。
    // 本番では CloudFront Function が x-poifo-viewer-host ヘッダーに入れて渡す（偽装不可）。
    'hosts' => [
        'api' => env('POIFO_API_HOST', 'api.localhost'),
        'admin' => env('POIFO_ADMIN_HOST', 'admin.localhost'),
        // ヘッダーがないとき Host ヘッダーで代用するか（ローカル開発用。本番は false）
        'allow_host_header_fallback' => (bool) env('POIFO_ALLOW_HOST_FALLBACK', false),
    ],

    // 送信元 IP の取り方（App\Support\ClientIp）。X-Forwarded-For の先頭は偽装できるので使わない
    'client_ip' => [
        // API 用の CloudFront Function が event.viewer.ip を必ず上書きで付けるヘッダー
        'header' => env('POIFO_CLIENT_IP_HEADER', 'x-poifo-viewer-ip'),
        // ヘッダーがないとき接続元の IP で代用するか（ローカル開発用。本番は false）
        'allow_remote_addr_fallback' => (bool) env('POIFO_CLIENT_IP_FALLBACK', false),
    ],

    // ASP からの成果通知（お金に直結するので、WAF に加えてアプリでも多重に確かめる）
    'postback' => [
        // 受信 URL に入れる推測されにくい文字列（https://api.<domain>/postback/<これ>/gf-rewards）。空なら受け付けない
        'path_token' => (string) env('POIFO_POSTBACK_PATH_TOKEN', ''),
        // 送信元として許可する IP（カンマ区切り、CIDR 可）。空なら本番では全て拒否、本番以外では確認しない
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('POIFO_POSTBACK_ALLOWED_IPS', ''))))),
        // 1分間にこの件数を超えて届いたら管理者に知らせる
        'burst_per_minute' => (int) env('POIFO_POSTBACK_BURST_PER_MINUTE', 30),
    ],

    // 異常（署名の不一致・未知の案件・大量受信など）を知らせる管理者のメールアドレス（カンマ区切り）
    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('POIFO_ALERT_EMAILS', ''))))),
    // 同じ種類の通知は、この秒数のあいだ1通にまとめる（全台で共有のキャッシュで判定）
    'alert_interval_seconds' => (int) env('POIFO_ALERT_INTERVAL_SECONDS', 600),

    // API 仕様書（Swagger 相当、/docs/api）を出すか。開発の確認中だけ true にする。本番は必ず false
    'api_docs_enabled' => (bool) env('POIFO_API_DOCS_ENABLED', false),

    // デバッグ用ログイン（/auth/debug/login）を出すか。開発の確認中だけ true にする。本番は必ず false
    'debug_login_enabled' => (bool) env('POIFO_DEBUG_LOGIN_ENABLED', false),

    // フロント（Astro 静的サイト）の URL。ログイン後のリダイレクト先などに使う
    'front_url' => rtrim(env('POIFO_FRONT_URL', 'http://localhost:4321'), '/'),

    'line' => [
        'channel_id' => env('LINE_LOGIN_CHANNEL_ID'),
        'channel_secret' => env('LINE_LOGIN_CHANNEL_SECRET'),
        'redirect_uri' => env('LINE_LOGIN_REDIRECT_URI'),
        // ログイン画面で公式アカウントの友だち追加を表示する（normal / aggressive / 空で表示しない）
        'bot_prompt' => env('LINE_LOGIN_BOT_PROMPT', 'normal'),
        // 公式アカウントの友だち追加URL（登録完了画面・マイページに出す）
        'official_friend_url' => env('LINE_OFFICIAL_FRIEND_URL'),
        'authorize_url' => 'https://access.line.me/oauth2/v2.1/authorize',
        'token_url' => 'https://api.line.me/oauth2/v2.1/token',
        'verify_url' => 'https://api.line.me/oauth2/v2.1/verify',
    ],

    'gf_rewards' => [
        // 案件連携API・ポストバックのハッシュ検証の両方に使う（同じキー）
        'api_key' => env('GF_REWARDS_API_KEY'),
        'ad_channel_id' => env('GF_REWARDS_AD_CHANNEL_ID'),
        'campaigns_url' => env('GF_REWARDS_CAMPAIGNS_URL', 'https://api.gf-rewards.com/media/v1/campaigns'),
    ],

    /*
     * ポイントの交換先（docs/specs/functional-spec.md 7章）。キーが交換先コードで、申請に保存するので変えない・使い回さない。
     * 交換先ごとに条件を持ち、ほかの交換先の条件には影響しない。条件を変えても、変える前に出た申請には影響しない
     * （申請した時点のレート・手数料・受け取り情報を申請に保存する）。
     *
     * - status: open（受付中）／ suspended（一覧には出すが申請できない）／ hidden（出さない・申請できない）
     * - fee: type が none（なし）／ fixed（1回 amount_yen 円）／ percent（交換額の percent %、円未満切り捨て）
     * - handler: 交換先ごとの処理（受け取り情報の控えなど）。App\Services\Exchange\ExchangeMethodHandler を実装する
     */
    'exchange_methods' => [
        'bank_transfer' => [
            'name' => '銀行振込（現金）',
            // ポイント履歴に「ポイント交換（銀行振込）」と出す名前
            'short_name' => '銀行振込',
            'description' => '登録した口座に、月に1回まとめてお振込みします。',
            'icon' => null,
            'status' => env('POIFO_EXCHANGE_BANK_TRANSFER_STATUS', 'open'),
            'sort' => 1,
            // 環境変数の名前は交換先を分ける前のまま（既存の .env をそのまま使う）
            'min_points' => (int) env('POIFO_MIN_WITHDRAWAL_POINTS', 1000),
            'step_points' => 1,
            'max_points' => null,
            // 1ポイント = 何円か
            'yen_per_point' => (float) env('POIFO_YEN_PER_POINT', 1),
            'fee' => ['type' => 'none'],
            // 申請から承認できるまでの審査期間（日）
            'review_days' => (int) env('POIFO_WITHDRAWAL_REVIEW_DAYS', 7),
            'handler' => BankTransferHandler::class,
        ],
    ],
];
