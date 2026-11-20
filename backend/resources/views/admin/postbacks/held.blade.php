@extends('admin.layouts.app')
@section('title', '保留した成果')
@section('content')
<h1>保留した成果</h1>
<div class="card">
    <p class="muted">成果通知の内容が案件情報と合わなかったため、ポイントを確定していない成果です。GF Rewards の管理画面などで内容を確かめてから「保留を解除」してください。
    解除すると、承認済みの成果は通知ポイントで確定し、未承認の成果は判定中のまま承認を待ちます。会員が見つからない・退会済みの成果は解除できません。</p>
</div>
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>保留日時</th><th>理由</th><th>会員</th><th>案件 / 成果地点</th><th>状態</th><th>通知ポイント</th><th>案件情報のポイント</th><th></th></tr></thead>
        <tbody>
        @forelse ($conversions as $c)
            <tr>
                <td>{{ $c->id }}<div class="muted">{{ $c->cv_id }}</div></td>
                <td>{{ $c->held_at?->format('Y/n/j H:i') }}</td>
                <td>{{ \App\Models\Conversion::HOLD_REASONS[$c->hold_reason] ?? $c->hold_reason }}</td>
                <td>@if ($c->user)<a href="{{ route('admin.members.show', $c->user) }}">{{ $c->user->display_name ?? $c->user->uid }}</a>@else<span class="muted">{{ $c->external_user_id }}（不明）</span>@endif</td>
                <td>{{ $c->campaign_name }}<div class="muted">{{ $c->external_campaign_id }} / {{ $c->cv_action_id }} {{ $c->cv_action_name }}</div></td>
                <td>{{ ['pending' => '未承認', 'approved' => '承認', 'rejected' => '否認'][$c->status] ?? $c->status }}</td>
                <td class="num">{{ number_format($c->points) }}</td>
                <td class="num">{{ $c->expected_points !== null ? number_format($c->expected_points) : '-' }}</td>
                <td>
                    <form method="post" action="{{ route('admin.conversions.release', $c) }}" onsubmit="return confirm('この成果の保留を解除します。承認済みなら通知ポイントで確定します。よろしいですか？')">
                        @csrf
                        <button type="submit" @disabled(! $c->user)>保留を解除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">保留中の成果はありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
