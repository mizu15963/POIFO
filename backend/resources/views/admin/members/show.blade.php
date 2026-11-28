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
        <h2>友達紹介</h2>
        @php($rank = $referral['rank'])
        <p>紹介コード: {{ $member->referral_code ?? '（未発行）' }}<br>
        紹介元: @if ($member->referrer)<a href="{{ route('admin.members.show', $member->referrer) }}">{{ $member->referrer->display_name ?? '（表示名なし）' }}</a> <span class="muted">{{ $member->referrer->uid }}</span>@else なし @endif<br>
        紹介した人数: {{ number_format($referral['total']) }}人（有効 {{ number_format($rank['valid_referrals']) }}人）<br>
        ランク: {{ $rank['name'] }}（{{ \App\Models\ReferralRank::formatBps($rank['rate_bps']) }}%）</p>
        @if ($member->referral_disabled_at)
            <p class="muted">紹介は無効です（{{ $member->referral_disabled_at->format('Y/n/j H:i') }}〜。理由は操作ログ）。</p>
        @endif
        @if ($member->status !== 'withdrawn')
        <form method="post" action="{{ route('admin.members.referral.partner', $member) }}">
            @csrf
            @if ($member->referral_partner_rate !== null)
                <input type="hidden" name="partner" value="0">
                <label for="partner_reason">特別パートナーを外す理由</label>
                <input id="partner_reason" type="text" name="reason" maxlength="255" required>
                <p><button type="submit" class="btn-outline" onclick="return confirm('特別パートナーを外します。よろしいですか？')">特別パートナーを外す</button></p>
            @else
                <input type="hidden" name="partner" value="1">
                <label for="partner_rate">特別パートナーにする（率 %、上限 {{ $referral['partner_cap'] !== null ? \App\Models\ReferralRank::formatBps($referral['partner_cap']) : '-' }}%）</label>
                <input id="partner_rate" type="number" name="rate" min="0" max="100" step="0.01" required value="{{ old('rate') }}">
                <label for="partner_reason">理由</label>
                <input id="partner_reason" type="text" name="reason" maxlength="255" required>
                <p><button type="submit" class="btn-outline">特別パートナーにする</button></p>
            @endif
        </form>
        @if ($member->referral_partner_rate !== null)
            <form method="post" action="{{ route('admin.members.referral.partner', $member) }}">
                @csrf
                <input type="hidden" name="partner" value="1">
                <label for="partner_rate_change">特別パートナーの率を変える（%）</label>
                <input id="partner_rate_change" type="number" name="rate" min="0" max="100" step="0.01" required value="{{ rtrim(rtrim((string) $member->referral_partner_rate, '0'), '.') }}">
                <label for="partner_rate_reason">理由</label>
                <input id="partner_rate_reason" type="text" name="reason" maxlength="255" required>
                <p><button type="submit" class="btn-outline">率を変える</button></p>
            </form>
        @endif
        <form method="post" action="{{ route($member->referral_disabled_at ? 'admin.members.referral.enable' : 'admin.members.referral.disable', $member) }}">
            @csrf
            <label for="referral_reason">{{ $member->referral_disabled_at ? '紹介の無効を取り消す理由' : '紹介を無効にする理由' }}</label>
            <input id="referral_reason" type="text" name="reason" maxlength="255" required>
            @if ($member->referral_disabled_at)
                <p><button type="submit" class="btn-outline">無効を取り消す</button></p>
            @else
                <p class="muted">この会員が紹介した人・紹介された人のどちらとしても、これからの紹介報酬が付かなくなり、判定中の紹介報酬は否認になります。</p>
                <p><button type="submit" class="btn-danger" onclick="return confirm('紹介を無効にします。判定中の紹介報酬は否認になります。よろしいですか？')">紹介を無効にする</button></p>
            @endif
        </form>
        @endif
        @if ($referral['referees']->isNotEmpty())
            <p class="muted" style="margin-bottom:4px">紹介した友達（新しい順・20人まで。<a href="{{ route('admin.referrals.rewards', ['referrer' => $member->uid]) }}">紹介報酬を見る</a>）</p>
            <ul style="margin:0;padding-left:1.2em">
            @foreach ($referral['referees'] as $f)
                <li><a href="{{ route('admin.members.show', $f) }}">{{ $f->display_name ?? '（表示名なし）' }}</a> <span class="muted">{{ $f->created_at?->format('Y/n/j') }} {{ \App\Models\User::STATUS_LABELS[$f->status] ?? '' }}</span></li>
            @endforeach
            </ul>
        @endif
    </div>
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
