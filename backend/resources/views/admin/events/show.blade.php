@extends('admin.layouts.app')
@section('title', $event->title)
@section('content')
<h1>{{ $event->title }}</h1>
<div class="card">
    <p>
        {{ $event->starts_at->format('Y/n/j H:i') }}@if ($event->ends_at) 〜 {{ $event->ends_at->format('Y/n/j H:i') }}@endif
        ／ {{ \App\Models\Event::STATUS_LABELS[$event->status] }}
        @if ($event->displayStatus() !== $event->status)（終了日時を過ぎたので「終了」として表示中）@endif
        ／ 参加 {{ number_format($counted) }}{{ $event->capacity ? ' / '.number_format($event->capacity) : '' }} 人
        ／ 参加ポイント {{ number_format($event->reward_points) }}
    </p>
    <p class="muted">{{ $event->summary }}</p>
    <div class="inline">
        <div><a class="btn" href="{{ route('admin.events.edit', $event) }}">編集</a></div>
        <div><a class="btn btn-outline" href="{{ route('admin.events.export', $event) }}">参加者の CSV</a></div>
        @if ($event->status === \App\Models\Event::STATUS_DRAFT)
            <form method="post" action="{{ route('admin.events.destroy', $event) }}" onsubmit="return confirm('このイベントを削除します。よろしいですか？')">
                @csrf @method('delete')
                <button type="submit" class="btn-danger">削除</button>
            </form>
        @endif
    </div>
</div>

<h2>参加者</h2>
<div class="card">
    <form method="get" action="{{ route('admin.events.show', $event) }}" class="inline">
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                <option value="">すべて</option>
                @foreach (\App\Models\EventParticipant::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><button type="submit" class="btn-outline">表示</button></div>
    </form>
    <p class="muted">イベントのあと、実際に参加した人を選んで「参加済みにする」を押すと、参加ポイントをその場で確定で付与します（1人1回）。付けた結果は戻せません。</p>
</div>
<form method="post" action="{{ route('admin.events.check', $event) }}">
    @csrf
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th><input type="checkbox" aria-label="すべて選択" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"></th>
                <th>会員</th><th>状態</th><th>参加日時</th><th>確認日時</th>
            </tr></thead>
            <tbody>
            @forelse ($participants as $p)
                <tr>
                    <td><input class="row-check" type="checkbox" name="ids[]" value="{{ $p->id }}" @disabled($p->status !== 'joined')></td>
                    <td><a href="{{ route('admin.members.show', $p->user_id) }}">{{ $p->user?->display_name ?? '（表示名なし）' }}</a><div class="muted">{{ $p->user?->uid }}</div></td>
                    <td>{{ \App\Models\EventParticipant::STATUS_LABELS[$p->status] }}</td>
                    <td>{{ $p->joined_at->format('n/j H:i') }}@if ($p->cancelled_at)<div class="muted">取り消し {{ $p->cancelled_at->format('n/j H:i') }}</div>@endif</td>
                    <td>{{ $p->checked_at?->format('n/j H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">参加者はいません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card inline" style="margin-top:16px">
        <div><button type="submit" name="action" value="attended" onclick="return confirm('選んだ人を参加済みにして、参加ポイントを付与します。よろしいですか？')">選んだ人を参加済みにする</button></div>
        <div><button type="submit" name="action" value="absent" class="btn-outline">選んだ人を不参加にする</button></div>
    </div>
</form>
@endsection
