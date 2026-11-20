@extends('admin.layouts.app')
@section('title', '成果通知 #'.$log->id)
@section('content')
<h1>成果通知 #{{ $log->id }}</h1>
<div class="card">
    <p>受信 {{ $log->created_at?->format('Y/n/j H:i:s') }} ／ {{ $log->method }} ／ 送信元 IP {{ $log->ip_address }}<br>
    結果: {{ $labels[$log->result] ?? $log->result }} ／ 署名: {{ $log->hash_valid ? '一致' : '不一致または未確認' }} ／ 応答: {{ $log->response_code ?? '-' }}</p>
    @if ($log->error)<p class="errors">{{ $log->error }}</p>@endif
</div>
<h2>パラメータ</h2>
@php($params = collect($log->params ?? [])->except('hash')->all())
<pre>{{ json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
<p class="muted">hash の値は表示していません。</p>
<p><a class="btn btn-outline" href="{{ route('admin.postbacks.index') }}">一覧へ戻る</a></p>
@endsection
