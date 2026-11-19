<?php

// フロント（例: https://example.jp）から API（https://api.example.jp）を Cookie 付きで呼ぶための設定
return [
    'paths' => ['v1/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('POIFO_FRONT_URL', 'http://localhost:4321')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
