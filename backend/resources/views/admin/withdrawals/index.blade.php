@extends('admin.layouts.app')
@section('title', '換金申請')
@section('content')
<h1>換金申請（{{ $month->format('Y年n月') }}の申請）</h1>

<div class="card">
    <form method="get" action="{{ route('admin.withdrawals.index') }}" class="inline">
        <div>
            <label for="month">申請月</label>
            <input id="month" type="month" name="month" value="{{ $month->format('Y-m') }}">
        </div>
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                <option value="all" @selected($status === null)>すべて</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><button type="submit" class="btn-outline">表示</button></div>
        <div>
            <a class="btn" href="{{ route('admin.withdrawals.export', ['month' => $month->format('Y-m'), 'status' => $status ?? 'all']) }}">この条件で CSV を出力</a>
        </div>
    </form>
    <p class="muted">表示中: {{ number_format($totals['count']) }} 件 ／ 振込額の合計 {{ number_format($totals['amount']) }} 円。振込用の CSV は、状態を「承認済み（振込待ち）」にして出力してください。</p>
</div>

<form method="post" action="{{ route('admin.withdrawals.bulk') }}">
    @csrf
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><input type="checkbox" aria-label="すべて選択" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"></th>
                <th>ID</th><th>申請日時</th><th>状態</th><th>会員</th><th>氏名／口座名義</th><th>振込先</th>
                <th>ポイント</th><th>振込額</th><th>注意</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($requests as $r)
                @php($bank = $r->bank_snapshot)
                <tr class="{{ isset($warnings[$r->id]) ? 'warn' : '' }}">
                    <td><input class="row-check" type="checkbox" name="ids[]" value="{{ $r->id }}" @disabled(in_array($r->status, ['rejected', 'paid']))></td>
                    <td>{{ $r->id }}</td>
                    <td>{{ $r->created_at->format('n/j H:i') }}</td>
                    <td>
                        <span class="badge badge-{{ $r->status }}">{{ $r->statusLabel() }}</span>
                        @if ($r->status === 'requested' && ! $r->isReviewable())
                            <div class="muted">審査期間中（{{ $r->reviewable_after->format('n/j H:i') }} まで）</div>
                        @endif
                        @if ($r->review_note)
                            <div class="muted">{{ $r->review_note }}</div>
                        @endif
                    </td>
                    <td><span class="muted">{{ $r->user?->uid }}</span><br>{{ $r->user?->display_name }}</td>
                    <td>{{ $bank['holder_name'] ?? '（未登録）' }}<br><span class="muted">{{ $bank['account_holder_kana'] ?? '' }}</span></td>
                    <td>
                        {{ $bank['bank_name'] ?? '' }}（{{ $bank['bank_code'] ?? '' }}）<br>
                        {{ $bank['branch_name'] ?? '' }}（{{ $bank['branch_code'] ?? '' }}）<br>
                        {{ \App\Models\BankAccount::accountTypeLabel($bank['account_type'] ?? null) }} {{ $bank['account_number'] ?? '' }}
                    </td>
                    <td class="num">{{ number_format($r->points) }}</td>
                    <td class="num">{{ number_format($r->transferYen()) }} 円</td>
                    <td>@foreach ($warnings[$r->id] ?? [] as $w)<div>⚠ {{ $w }}</div>@endforeach</td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted">この条件の申請はありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card inline" style="margin-top:16px">
        <div>
            <label for="note">却下の理由（却下するときだけ。会員には表示しません）</label>
            <input id="note" type="text" name="note" maxlength="255">
        </div>
        <div><button type="submit" name="action" value="approve">選んだ申請を承認</button></div>
        <div><button type="submit" name="action" value="paid" class="btn-outline">選んだ申請を振込済みにする</button></div>
        <div><button type="submit" name="action" value="reject" class="btn-danger" onclick="return confirm('選んだ申請を却下し、ポイントを会員に戻します。よろしいですか？')">選んだ申請を却下</button></div>
    </div>
</form>
@endsection
