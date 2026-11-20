@extends('admin.layouts.app')
@section('title', $event->exists ? 'イベントの編集' : 'イベントの登録')
@section('content')
<h1>{{ $event->exists ? 'イベントの編集' : 'イベントの登録' }}</h1>
<form method="post" action="{{ $event->exists ? route('admin.events.update', $event) : route('admin.events.store') }}" class="card">
    @csrf
    @if ($event->exists) @method('put') @endif
    <label for="title">タイトル（必須・100文字まで）</label>
    <input id="title" type="text" name="title" maxlength="100" required value="{{ old('title', $event->title) }}">
    <div class="grid2">
        <div>
            <label for="starts_at">開始日時（必須）</label>
            <input id="starts_at" type="datetime-local" name="starts_at" required value="{{ old('starts_at', $event->starts_at?->format('Y-m-d\TH:i')) }}">
        </div>
        <div>
            <label for="ends_at">終了日時</label>
            <input id="ends_at" type="datetime-local" name="ends_at" value="{{ old('ends_at', $event->ends_at?->format('Y-m-d\TH:i')) }}">
        </div>
        <div>
            <label for="format">形式（例: オンライン）</label>
            <input id="format" type="text" name="format" maxlength="50" value="{{ old('format', $event->format) }}">
        </div>
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                @foreach (\App\Models\Event::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $event->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="capacity">参加上限人数（空なら上限なし）</label>
            <input id="capacity" type="number" min="1" name="capacity" value="{{ old('capacity', $event->capacity) }}">
        </div>
        <div>
            <label for="reward_points">参加ポイント（参加を確認した人に付与。0 なら付与しない）</label>
            <input id="reward_points" type="number" min="0" name="reward_points" required value="{{ old('reward_points', $event->reward_points) }}">
        </div>
        <div>
            <label for="sort_order">並び順（小さいほど前）</label>
            <input id="sort_order" type="number" name="sort_order" required value="{{ old('sort_order', $event->sort_order) }}">
        </div>
    </div>
    <label for="summary">概要（一覧のカードに出す・200文字まで）</label>
    <textarea id="summary" name="summary" maxlength="200" style="min-height:60px">{{ old('summary', $event->summary) }}</textarea>
    <label for="body">本文（詳細ページに出す）</label>
    <textarea id="body" name="body">{{ old('body', $event->body) }}</textarea>
    <label for="notes">注意事項</label>
    <textarea id="notes" name="notes">{{ old('notes', $event->notes) }}</textarea>
    <label for="thumbnail_url">サムネイル URL（https）</label>
    <input id="thumbnail_url" type="url" name="thumbnail_url" value="{{ old('thumbnail_url', $event->thumbnail_url) }}">
    <label for="openchat_url">オープンチャット URL（https。参加した会員にだけ表示）</label>
    <input id="openchat_url" type="url" name="openchat_url" value="{{ old('openchat_url', $event->openchat_url) }}">
    <p class="muted">公開中のイベントは、終了日時（なければ開始日の終わり）を過ぎると自動で「終了」として表示されます。参加は開始日時まで受け付けます。</p>
    <p><button type="submit">保存</button> <a class="btn btn-outline" href="{{ $event->exists ? route('admin.events.show', $event) : route('admin.events.index') }}">戻る</a></p>
</form>
@endsection
