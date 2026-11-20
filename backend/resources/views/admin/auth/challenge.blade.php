@extends('admin.layouts.app')
@section('title', '二要素認証')
@section('content')
<div class="card narrow">
    <h1>二要素認証</h1>
    <p>認証アプリに表示されている6桁のコードを入力してください。</p>
    <form method="post" action="{{ route('admin.two-factor.challenge') }}">
        @csrf
        <label for="code">コード</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" required autofocus>
        <p><button type="submit">ログイン</button></p>
    </form>
    <p class="muted">スマホを紛失した場合は、サーバで <code>php artisan admin:reset-two-factor &lt;メールアドレス&gt;</code> を実行して登録し直してください。</p>
</div>
@endsection
