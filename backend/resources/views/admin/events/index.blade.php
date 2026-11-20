@extends('admin.layouts.app')
@section('title', 'イベント')
@section('content')
<h1>イベント</h1>
<p><a class="btn" href="{{ route('admin.events.create') }}">イベントを登録する</a></p>
@include('admin.partials.pager', ['paginator' => $events])
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>タイトル</th><th>開催日時</th><th>状態</th><th>参加</th><th>参加ポイント</th></tr></thead>
        <tbody>
        @forelse ($events as $e)
            <tr>
                <td>{{ $e->id }}</td>
                <td><a href="{{ route('admin.events.show', $e) }}">{{ $e->title }}</a></td>
                <td>{{ $e->starts_at->format('Y/n/j H:i') }}@if ($e->ends_at) 〜 {{ $e->ends_at->format('n/j H:i') }}@endif</td>
                <td>
                    {{ \App\Models\Event::STATUS_LABELS[$e->status] }}
                    @if ($e->displayStatus() !== $e->status)<div class="muted">（終了日時を過ぎたので「終了」として表示中）</div>@endif
                </td>
                <td class="num">{{ number_format($e->counted_participants) }}{{ $e->capacity ? ' / '.number_format($e->capacity) : '' }} 人</td>
                <td class="num">{{ number_format($e->reward_points) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">イベントはまだありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('admin.partials.pager', ['paginator' => $events])
@endsection
