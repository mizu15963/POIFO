<?php

use Dedoc\Scramble\OpenApiVersion;

// API 仕様書（Swagger 相当）。開発期間中だけ使う。
// 公開するかどうかは POIFO_API_DOCS_ENABLED で切り替え、ルートは bootstrap/app.php で登録する
// （Scramble 既定のルートは AppServiceProvider で無効化している）。
return [
    // 仕様書に載せるルート（URI の先頭で判定）。ASP からの成果通知（postback/）は受け口の存在を
    // 外に見せないため載せない（社内向けの仕様書はリポジトリの外で管理する）
    'api_path' => [
        'include' => ['v1', 'auth', 'go'],
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
        'description' => 'POIFO 会員向け API・LINE ログインの仕様書（開発用。本番では公開しない）。'
            .' Try It は同じ api. ホストから Cookie 付きで呼ぶ。POST/PUT は先に /sanctum/csrf-cookie を開いて XSRF-TOKEN を取得すること。',
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

    // 公開の可否は POIFO_API_DOCS_ENABLED だけで決める（ルート自体を登録しない）
    'middleware' => [
        'web',
    ],

    'extensions' => [],

    'security_strategy' => null,
];
