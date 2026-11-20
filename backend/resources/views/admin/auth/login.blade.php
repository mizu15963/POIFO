@extends('admin.layouts.app')
@section('title', 'ログイン')
@section('content')
<div class="card narrow">
    <h1>POIFO 管理画面 ログイン</h1>
    <form method="post" action="{{ route('admin.login') }}">
        @csrf
        <label for="email">メールアドレス</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">パスワード</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <p><button type="submit">次へ</button></p>
    </form>
    <p class="muted">次の画面で、認証アプリの6桁のコードを入力します。</p>
</div>
@endsection
