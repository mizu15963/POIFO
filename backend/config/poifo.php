<?php

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

    'points' => [
        // 1ポイント = 何円か
        'yen_per_point' => (float) env('POIFO_YEN_PER_POINT', 1),
        'min_withdrawal_points' => (int) env('POIFO_MIN_WITHDRAWAL_POINTS', 1000),
        // 換金申請から承認できるまでの審査期間（日）
        'withdrawal_review_days' => (int) env('POIFO_WITHDRAWAL_REVIEW_DAYS', 7),
    ],
];
