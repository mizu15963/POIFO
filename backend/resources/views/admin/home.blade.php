@extends('admin.layouts.app')
@section('title', 'ホーム')
@section('content')
<h1>ホーム</h1>
<div class="card">
    <h2 style="font-size:1.05rem;margin-top:0">換金申請</h2>
    <p>申請中（未確認）: <strong>{{ number_format($requestedCount) }}</strong> 件 ／ 承認済み（振込待ち）: <strong>{{ number_format($approvedCount) }}</strong> 件</p>
    <p><a class="btn" href="{{ route('admin.withdrawals.index') }}">換金申請を確認する</a></p>
    <p class="muted">月末の流れ: ① その月の申請を確認して承認（不審なものは却下）→ ② 「承認済み」で CSV を出力 → ③ 銀行で振込 → ④ 振り込んだ申請を「振込済みにする」</p>
</div>
<div class="card">
    <h2 style="font-size:1.05rem;margin-top:0">成果通知</h2>
    <p>保留中の成果: <strong>{{ number_format($heldCount) }}</strong> 件</p>
    <p><a class="btn btn-outline" href="{{ route('admin.conversions.held') }}">保留を確認する</a> <a class="btn btn-outline" href="{{ route('admin.postbacks.index') }}">受信ログ</a></p>
</div>
<div class="card">
    <h2 style="font-size:1.05rem;margin-top:0">開催予定のイベント</h2>
    @forelse ($upcomingEvents as $e)
        <p><a href="{{ route('admin.events.show', $e) }}">{{ $e->title }}</a> <span class="muted">{{ $e->starts_at->format('n/j H:i') }}</span></p>
    @empty
        <p class="muted">開催予定のイベントはありません。</p>
    @endforelse
    <p><a class="btn btn-outline" href="{{ route('admin.events.index') }}">イベント一覧</a></p>
</div>
@endsection
