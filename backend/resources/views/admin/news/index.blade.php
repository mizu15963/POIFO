@extends('admin.layouts.app')
@section('title', 'お知らせ')
@section('content')
<h1>お知らせ</h1>
<div class="card">
    <form method="get" action="{{ route('admin.news.index') }}" class="inline">
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                <option value="">すべて</option>
                @foreach (\App\Models\News::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><button type="submit" class="btn-outline">表示</button></div>
    </form>
    <p class="muted">会員サイトには「公開」で、公開日時を過ぎて公開終了日時の前のものだけ出ます。「重要」にした公開中のお知らせは、新しい1件がトップとマイページの上に出ます。</p>
</div>
<p><a class="btn" href="{{ route('admin.news.create') }}">お知らせを登録する</a></p>
@include('admin.partials.pager', ['paginator' => $news])
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>タイトル</th><th>状態</th><th>重要</th><th>公開日時</th><th>公開終了日時</th><th></th></tr></thead>
        <tbody>
        @forelse ($news as $n)
            <tr>
                <td>{{ $n->id }}</td>
                <td><a href="{{ route('admin.news.edit', $n) }}">{{ $n->title }}</a></td>
                <td>{{ $n->displayStatus() }}</td>
                <td>@if ($n->is_important)<span class="badge badge-requested">重要</span>@endif</td>
                <td>{{ $n->published_at?->format('Y/n/j H:i') ?? '-' }}</td>
                <td>{{ $n->expires_at?->format('Y/n/j H:i') ?? '-' }}</td>
                <td>
                    @if ($n->status === \App\Models\News::STATUS_DRAFT)
                        <form method="post" action="{{ route('admin.news.destroy', $n) }}" onsubmit="return confirm('このお知らせを削除しますか？')">
                            @csrf @method('delete')
                            <button type="submit" class="btn-outline">削除</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">お知らせはまだありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('admin.partials.pager', ['paginator' => $news])
@endsection
