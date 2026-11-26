@extends('admin.layouts.app')
@section('title', $news->exists ? 'お知らせの編集' : 'お知らせの登録')
@section('content')
<h1>{{ $news->exists ? 'お知らせの編集' : 'お知らせの登録' }}</h1>
<form method="post" action="{{ $news->exists ? route('admin.news.update', $news) : route('admin.news.store') }}" class="card">
    @csrf
    @if ($news->exists) @method('put') @endif
    <label for="title">タイトル（必須・100文字まで）</label>
    <input id="title" type="text" name="title" maxlength="100" required value="{{ old('title', $news->title) }}">
    <label for="body">本文（必須・5,000文字まで。HTML は使えません。改行はそのまま出て、URL は自動でリンクになります）</label>
    <textarea id="body" name="body" maxlength="5000" required style="min-height:200px">{{ old('body', $news->body) }}</textarea>
    <div class="grid2">
        <div>
            <label for="published_at">公開日時（必須。未来の日時にすると予約になります）</label>
            <input id="published_at" type="datetime-local" name="published_at" required value="{{ old('published_at', ($news->published_at ?? now())->format('Y-m-d\TH:i')) }}">
        </div>
        <div>
            <label for="expires_at">公開終了日時（空なら出し続けます）</label>
            <input id="expires_at" type="datetime-local" name="expires_at" value="{{ old('expires_at', $news->expires_at?->format('Y-m-d\TH:i')) }}">
        </div>
        <div>
            <label for="status">状態</label>
            <select id="status" name="status">
                @foreach (\App\Models\News::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $news->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="margin-top:28px;font-weight:400"><input type="hidden" name="is_important" value="0"><input type="checkbox" name="is_important" value="1" @checked(old('is_important', $news->is_important))> 重要（トップとマイページの上に出す）</label>
        </div>
    </div>
    <p class="muted">削除できるのは下書きだけです。公開したお知らせを下げるときは、公開終了日時を入れてください。</p>
    <p><button type="submit">保存</button> <a class="btn btn-outline" href="{{ route('admin.news.index') }}">戻る</a></p>
</form>
@endsection
