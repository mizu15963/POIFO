@extends('admin.layouts.app')
@section('title', '成果通知')
@section('content')
<h1>成果通知の受信ログ</h1>
<p><a class="btn" href="{{ route('admin.conversions.held') }}">保留した成果を確認する</a></p>
<div class="card">
    <form method="get" action="{{ route('admin.postbacks.index') }}" class="inline">
        <div>
            <label for="result">結果</label>
            <select id="result" name="result">
                <option value="">すべて</option>
                @foreach ($labels as $value => $label)
                    <option value="{{ $value }}" @selected($result === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="cv_id">cv_id</label><input id="cv_id" type="text" name="cv_id" value="{{ $cvId }}"></div>
        <div><label for="date">受信日</label><input id="date" type="date" name="date" value="{{ $date?->format('Y-m-d') }}"></div>
        <div><button type="submit" class="btn-outline">表示</button></div>
    </form>
</div>
@include('admin.partials.pager', ['paginator' => $logs])
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>受信日時</th><th>結果</th><th>cv_id</th><th>会員ID（通知）</th><th>送信元 IP</th><th>応答</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr class="{{ in_array($log->result, ['invalid_hash', 'invalid_path', 'ip_denied', 'error', 'held', 'unknown_user'], true) ? 'warn' : '' }}">
                <td><a href="{{ route('admin.postbacks.show', $log) }}">{{ $log->id }}</a></td>
                <td>{{ $log->created_at?->format('Y/n/j H:i:s') }}</td>
                <td>{{ $labels[$log->result] ?? $log->result }}</td>
                <td>{{ $log->cv_id }}</td>
                <td class="muted">{{ $log->params['user_id'] ?? '' }}</td>
                <td class="muted">{{ $log->ip_address }}</td>
                <td>{{ $log->response_code ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">ログはありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('admin.partials.pager', ['paginator' => $logs])
@endsection
