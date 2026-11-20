{{ $title }}

@foreach ($details as $key => $value)
{{ $key }}: {{ $value }}
@endforeach

発生日時: {{ now()->format('Y-m-d H:i:s') }}（{{ config('app.timezone') }}）
詳細は管理画面または postback_logs を確認してください。
同じ種類の通知は {{ (int) (config('poifo.alert_interval_seconds') / 60) }} 分間まとめて1通にしています。
