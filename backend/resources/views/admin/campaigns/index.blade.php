@extends('admin.layouts.app')
@section('title', '案件の表示設定')
@section('content')
<h1>案件の表示設定</h1>
<div class="card">
    <form method="get" action="{{ route('admin.campaigns.index') }}" class="inline">
        <div>
            <label for="filter">掲載状態</label>
            <select id="filter" name="filter">
                <option value="">すべて</option>
                @foreach ($filters as $value => $label)
                    <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div><label for="q">案件名・案件ID</label><input id="q" type="text" name="q" value="{{ $keyword }}"></div>
        <div><button type="submit" class="btn-outline">表示</button></div>
    </form>
    <p class="muted">案件の中身（名前・ポイント・期間）は GF Rewards から15分ごとに取り込むので、ここでは表示の仕方だけ変えられます。並びは「おすすめ → 並び順（小さいほど前）→ 新しい順」です。</p>
</div>
@include('admin.partials.pager', ['paginator' => $campaigns])
<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>案件</th><th>サイト</th><th>ASP の状態</th><th>掲載期間</th><th>最大ポイント</th><th>お気に入り</th><th>表示設定</th></tr></thead>
        <tbody>
        @forelse ($campaigns as $c)
            <tr>
                <td>{{ $c->id }}<div class="muted">{{ $c->external_id }}</div></td>
                <td>{{ $c->name }}<div class="muted">{{ $c->category }} {{ $c->platform }}</div></td>
                <td>@if (isset($listedIds[$c->id]))<span class="badge badge-paid">掲載中</span>@else<span class="badge">非掲載</span>@endif</td>
                <td>{{ $c->external_status ?? '-' }}<div class="muted">取込 {{ $c->synced_at?->format('n/j H:i') }}</div></td>
                <td class="muted">{{ $c->starts_at?->format('Y/n/j') ?? '-' }} 〜 {{ $c->ends_at?->format('Y/n/j') ?? '' }}</td>
                <td class="num">{{ $c->max_points !== null ? number_format($c->max_points) : '定率' }}</td>
                <td class="num">{{ number_format($c->favorites_count) }}</td>
                <td>
                    <form method="post" action="{{ route('admin.campaigns.update', $c) }}" class="inline">
                        @csrf @method('put')
                        <label style="margin:0;font-weight:400"><input type="checkbox" name="is_featured" value="1" @checked($c->is_featured)> おすすめ</label>
                        <label style="margin:0;font-weight:400"><input type="checkbox" name="is_hidden" value="1" @checked($c->is_hidden)> 非表示</label>
                        <div style="min-width:80px"><input type="number" name="sort_order" value="{{ $c->sort_order }}" aria-label="並び順" style="width:80px"></div>
                        <button type="submit" class="btn-outline">保存</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">案件がありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('admin.partials.pager', ['paginator' => $campaigns])
@endsection
