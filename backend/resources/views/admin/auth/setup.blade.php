@extends('admin.layouts.app')
@section('title', '二要素認証の登録')
@section('content')
<div class="card narrow">
    <h1>二要素認証の登録</h1>
    <p>管理画面は二要素認証が必須です。スマホの認証アプリ（Google Authenticator、Microsoft Authenticator など）で、下の QR コードを読み取ってください。</p>
    <div class="qr">{!! $qrSvg !!}</div>
    <p class="muted">読み取れない場合は、このキーを手入力してください:<br><code>{{ $secret }}</code></p>
    <form method="post" action="{{ route('admin.two-factor.setup') }}">
        @csrf
        <label for="code">アプリに表示された6桁のコード</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" required>
        <p><button type="submit">登録してログイン</button></p>
    </form>
</div>
@endsection
