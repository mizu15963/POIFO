<?php

use Dedoc\Scramble\OpenApiVersion;

// API 仕様書（Swagger 相当）。開発期間中だけ使う。
// 管理画面（admin.）の中に置き、管理者のログイン後だけ見せる。ルートは bootstrap/app.php で登録する
// （Scramble 既定のルートは AppServiceProvider で無効化している）。
return [
    // 仕様書に載せるルート（URI の先頭で判定）。開発中の確認用なので成果通知（postback/）も載せる。
    // 仕様書自体は管理者のログイン後だけ見られる
    'api_path' => [
        'include' => ['v1', 'auth', 'go', 'postback'],
    ],

    'api_domain' => null,

    'export_path' => 'api.json',

    // 生成結果はキャッシュしない（サーバのローカルに状態を持たない。開発用なので毎回生成で十分）
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => null,
    ],

    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        'version' => env('API_VERSION', '0.1.0'),
        'description' => 'POIFO 会員向け API・LINE ログイン・成果通知の仕様書（管理者のログイン後だけ見られる）。'
            .' Try It は api. へ Cookie 付きで送る。ログインが必要な API は、同じブラウザで先に会員としてログイン（デバッグ用ログインなど）しておくこと。'
            .' POST/PUT は先に api. の /sanctum/csrf-cookie を開いて XSRF-TOKEN を取得すること。',
    ],

    'ui' => [
        'title' => 'POIFO API',
    ],

    'dev_tools' => [
        'enabled' => false,
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
    ],

    // 送り先は AppServiceProvider で相対の '/' に差し替える（開いているホスト＝api. に向けて Try It する）
    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'enum_cases_auto_description_strategy' => false,

    'flatten_deep_query_parameters' => true,

    // ログインの確認は bootstrap/app.php のルートグループ（auth:admin）で行う
    'middleware' => [
        'web',
    ],

    'extensions' => [],

    'security_strategy' => null,
];
