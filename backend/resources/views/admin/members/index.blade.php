@extends('admin.layouts.app')
@section('title', '会員')
@section('content')
<h1>会員</h1>
<div class="card">
    <form method="get" action="{{ route('admin.members.index') }}" class="inline">
        <div><label for="q">会員ID・表示名</label><input id="q" type="text" name="q" value="{{ $keyword }}"></div>
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                <option value="">すべて</option>
                @foreach (\App\Models\User::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><button type="submit" class="btn-outline">検索</button></div>
    </form>
</div>
@include('admin.partials.pager', ['paginator' => $users])
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>会員ID</th><th>表示名</th><th>状態</th><th>登録日</th><th>最終ログイン</th></tr></thead>
        <tbody>
        @forelse ($users as $u)
            <tr>
                <td>{{ $u->id }}</td>
                <td><a href="{{ route('admin.members.show', $u) }}">{{ $u->uid }}</a></td>
                <td>{{ $u->display_name }}</td>
                <td><span class="badge badge-{{ ['active' => 'paid', 'suspended' => 'rejected', 'withdrawn' => ''][$u->status] ?? '' }}">{{ \App\Models\User::STATUS_LABELS[$u->status] ?? $u->status }}</span></td>
                <td>{{ $u->created_at?->format('Y/n/j') }}</td>
                <td>{{ $u->last_login_at?->format('Y/n/j H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">該当する会員はいません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('admin.partials.pager', ['paginator' => $users])
@endsection
