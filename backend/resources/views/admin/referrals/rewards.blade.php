@extends('admin.layouts.app')
@section('title', '紹介報酬')
@section('content')
@php($statusLabels = \App\Models\PointTransaction::STATUS_LABELS)
<h1>紹介報酬</h1>
<div class="card">
    <form method="get" action="{{ route('admin.referrals.rewards') }}" class="inline">
        <div><label for="referrer">紹介した人（会員ID）</label><input id="referrer" type="text" name="referrer" value="{{ $filters['referrer'] }}"></div>
        <div><label for="referee">紹介した友達（会員ID）</label><input id="referee" type="text" name="referee" value="{{ $filters['referee'] }}"></div>
        <div>
            <label for="type">種類</label>
            <select id="type" name="type">
                <option value="">すべて</option>
                @foreach (\App\Models\ReferralReward::TYPE_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                <option value="">すべて</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="from">期間（から）</label><input id="from" type="date" name="from" value="{{ $filters['from'] }}"></div>
        <div><label for="to">期間（まで）</label><input id="to" type="date" name="to" value="{{ $filters['to'] }}"></div>
        <div><button type="submit" class="btn-outline">表示</button></div>
        <div><a class="btn" href="{{ route('admin.referrals.export', request()->query()) }}">CSV を出力</a></div>
    </form>
    <p class="muted">紹介報酬は、紹介した友達の案件の成果と同じ状態（判定中・確定・否認）になります。率は成果が確定した時点のランクで決まり、あとから設定を変えても確定済みの報酬は変わりません。紹介した人と友達の振込先口座が同じ（同じ口座・同じ名義）ときは行を黄色にしています。</p>
</div>
@include('admin.partials.pager', ['paginator' => $rewards])
<div class="table-wrap"><table>
    <thead><tr><th>日時</th><th>紹介した人</th><th>紹介した友達</th><th>種類</th><th>元の成果</th><th>元のポイント</th><th>率</th><th>ランク</th><th>ポイント</th><th>状態</th></tr></thead>
    <tbody>
    @forelse ($rewards as $r)
        @php($warn = isset($sameBank[$r->referrer_user_id.':'.$r->referee_user_id]))
        <tr @class(['warn' => $warn])>
            <td>{{ $r->created_at?->format('Y/n/j H:i') }}</td>
            <td><a href="{{ route('admin.members.show', $r->referrer_user_id) }}">{{ $r->referrer?->display_name ?? '（表示名なし）' }}</a><div class="muted">{{ $r->referrer?->uid }}</div></td>
            <td><a href="{{ route('admin.members.show', $r->referee_user_id) }}">{{ $r->referee?->display_name ?? '（表示名なし）' }}</a><div class="muted">{{ $r->referee?->uid }}</div></td>
            <td>{{ \App\Models\ReferralReward::TYPE_LABELS[$r->type] ?? $r->type }}@if ($warn)<div class="muted">同じ振込先口座</div>@endif</td>
            <td>{{ $r->conversion?->campaign_name }}<div class="muted">{{ $r->conversion?->cv_id }}</div></td>
            <td class="num">{{ number_format($r->base_points) }}</td>
            <td class="num">{{ $r->rate !== null ? \App\Models\ReferralRank::formatBps(\App\Models\ReferralRank::toBps($r->rate)).'%' : '-' }}</td>
            <td>{{ $r->rank_name ?? $r->rank }}</td>
            <td class="num">{{ number_format($r->points) }}</td>
            <td>{{ $statusLabels[$r->pointTransaction?->status] ?? '-' }}</td>
        </tr>
    @empty
        <tr><td colspan="10" class="muted">紹介報酬はありません。</td></tr>
    @endforelse
    </tbody>
</table></div>
@include('admin.partials.pager', ['paginator' => $rewards])
@endsection
