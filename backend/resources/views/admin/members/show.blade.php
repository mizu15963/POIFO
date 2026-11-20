@extends('admin.layouts.app')
@section('title', '会員の詳細')
@section('content')
@php($labels = \App\Models\PointTransaction::TYPE_LABELS)
<h1>{{ $member->display_name ?? '（表示名なし）' }} <span class="muted">{{ $member->uid }}</span></h1>

<div class="stats">
    <div>状態<br><strong>{{ \App\Models\User::STATUS_LABELS[$member->status] ?? $member->status }}</strong></div>
    <div>利用可能ポイント<br><strong>{{ number_format($balance['available']) }}</strong></div>
    <div>判定中ポイント<br><strong>{{ number_format($balance['pending']) }}</strong></div>
    <div>登録日<br><strong>{{ $member->created_at?->format('Y/n/j') }}</strong></div>
    <div>最終ログイン<br><strong>{{ $member->last_login_at?->format('n/j H:i') ?? '-' }}</strong></div>
</div>
@if ($member->status === 'suspended')
    <p class="muted">停止日時: {{ $member->suspended_at?->format('Y/n/j H:i') }} ／ 理由: {{ $member->suspended_reason }}</p>
@endif
@if ($member->status === 'withdrawn')
    <p class="muted">退会日時: {{ $member->withdrawn_at?->format('Y/n/j H:i') }}</p>
@endif

<div class="grid2" style="margin-top:16px">
    @if ($member->status !== 'withdrawn')
    <div class="card">
        <h2>ポイントの手動調整</h2>
        <form method="post" action="{{ route('admin.members.points', $member) }}">
            @csrf
            <label for="points">ポイント（付与は正の数、減算は負の数）</label>
            <input id="points" type="number" name="points" required value="{{ old('points') }}">
            <label for="reason">理由（会員のポイント履歴にも表示されます）</label>
            <input id="reason" type="text" name="reason" maxlength="255" required value="{{ old('reason') }}">
            <p><button type="submit" onclick="return confirm('ポイントを調整します。すぐに確定します。よろしいですか？')">調整する</button></p>
        </form>
    </div>
    <div class="card">
        <h2>停止・再開</h2>
        @if ($member->status === 'active')
            <form method="post" action="{{ route('admin.members.suspend', $member) }}">
                @csrf
                <label for="suspend_reason">停止の理由（会員には表示しません）</label>
                <input id="suspend_reason" type="text" name="reason" maxlength="255" required>
                <p class="muted">停止すると、ログイン・案件への遷移・換金申請・イベント参加ができなくなり、ログイン中のセッションも次の操作で切れます。</p>
                <p><button type="submit" class="btn-danger" onclick="return confirm('この会員を停止します。よろしいですか？')">停止する</button></p>
            </form>
        @else
            <form method="post" action="{{ route('admin.members.resume', $member) }}">
                @csrf
                <p><button type="submit">再開する</button></p>
            </form>
        @endif
    </div>
    @endif
    <div class="card">
        <h2>振込先口座</h2>
        @if ($bank)
            <p>{{ $bank->holder_name }}（{{ $bank->account_holder_kana }}）<br>
            {{ $bank->bank_name }}（{{ $bank->bank_code }}） {{ $bank->branch_name }}（{{ $bank->branch_code }}）<br>
            {{ \App\Models\BankAccount::accountTypeLabel($bank->account_type) }} ****{{ substr((string) $bank->account_number, -3) }}</p>
            <p class="muted">更新 {{ $bank->updated_at?->format('Y/n/j H:i') }}</p>
        @else
            <p class="muted">未登録</p>
        @endif
    </div>
</div>

<h2>ポイント履歴（新しい順・50件まで）</h2>
<div class="table-wrap"><table>
    <thead><tr><th>日時</th><th>種類</th><th>内容</th><th>状態</th><th>ポイント</th></tr></thead>
    <tbody>
    @forelse ($transactions as $t)
        <tr>
            <td>{{ $t->created_at?->format('Y/n/j H:i') }}</td>
            <td>{{ $labels[$t->type] ?? $t->type }}</td>
            <td>{{ $t->description }}</td>
            <td>{{ \App\Models\PointTransaction::STATUS_LABELS[$t->status] ?? $t->status }}</td>
            <td class="num">{{ number_format($t->points) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">履歴はありません。</td></tr>
    @endforelse
    </tbody>
</table></div>

<h2 style="margin-top:16px">成果</h2>
<div class="table-wrap"><table>
    <thead><tr><th>受信</th><th>cv_id</th><th>案件 / 成果地点</th><th>状態</th><th>ポイント</th><th>保留</th></tr></thead>
    <tbody>
    @forelse ($conversions as $c)
        <tr>
            <td>{{ $c->created_at?->format('Y/n/j H:i') }}</td>
            <td>{{ $c->cv_id }}</td>
            <td>{{ $c->campaign_name }}<div class="muted">{{ $c->cv_action_name }}</div></td>
            <td>{{ ['pending' => '未承認', 'approved' => '承認', 'rejected' => '否認'][$c->status] ?? $c->status }}</td>
            <td class="num">{{ number_format($c->points) }}</td>
            <td>{{ $c->hold_reason ? (\App\Models\Conversion::HOLD_REASONS[$c->hold_reason] ?? $c->hold_reason) : '' }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">成果はありません。</td></tr>
    @endforelse
    </tbody>
</table></div>

<div class="grid2" style="margin-top:16px">
    <div>
        <h2>換金申請</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>申請日</th><th>状態</th><th>ポイント</th></tr></thead>
            <tbody>
            @forelse ($withdrawals as $w)
                <tr><td>{{ $w->created_at?->format('Y/n/j') }}</td><td>{{ $w->statusLabel() }}</td><td class="num">{{ number_format($w->points) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">申請はありません。</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    <div>
        <h2>イベント参加</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>イベント</th><th>状態</th></tr></thead>
            <tbody>
            @forelse ($participations as $p)
                <tr><td><a href="{{ route('admin.events.show', $p->event_id) }}">{{ $p->event?->title }}</a></td><td>{{ \App\Models\EventParticipant::STATUS_LABELS[$p->status] }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">参加はありません。</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    <div>
        <h2>最近の案件クリック</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>日時</th><th>案件</th><th>IP</th></tr></thead>
            <tbody>
            @forelse ($clicks as $click)
                <tr><td>{{ $click->created_at?->format('n/j H:i') }}</td><td>{{ $click->campaign?->name }}</td><td class="muted">{{ $click->ip_address }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">クリックはありません。</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>
@endsection
