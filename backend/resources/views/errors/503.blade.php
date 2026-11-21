{{-- メンテナンス中（php artisan down）。API（/v1/*）は JSON の 503 になり、フロントは /maintenance/ に移る --}}
@extends('errors.layout', ['code' => 503, 'pose' => 'maintenance', 'heading' => 'ただいまメンテナンス中です', 'message' => 'より快適にご利用いただけるよう、システムのメンテナンスを行っています。ご不便をおかけしますが、終了までしばらくお待ちください。'])
