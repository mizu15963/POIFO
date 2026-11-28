@extends('admin.layouts.app')
@section('title', 'カテゴリの表示')
@section('content')
<h1>カテゴリの表示</h1>
<p class="muted">会員サイトのメニューの「カテゴリから探す」に出すボタンの設定です（機能仕様 4.7）。カテゴリは GF Rewards の値のままで、ここでは表示名・並び・出すかどうかだけ変えられます。表示名が空なら GF Rewards の名前をそのまま出します。並び順を入れたカテゴリが先（小さいほど前）、空のカテゴリはそのあとに掲載中の件数の多い順です。掲載中の案件が0件のカテゴリはサイトに出ません。「ゲーム」のボタンは特集の印（ゲーム案件特集）の一覧につながる固定のボタンで、ここには出ません。変更は操作ログに残ります。</p>
<form method="post" action="{{ route('admin.campaign-categories.update') }}">
    @csrf @method('put')
    <div class="card">
        <div class="table-wrap"><table>
            <thead><tr><th>GF Rewards のカテゴリ</th><th>掲載中／取り込み済み</th><th>表示名</th><th>並び順</th><th>メニューに出す</th></tr></thead>
            <tbody>
            @forelse ($rows as $i => $row)
                <tr>
                    <td>{{ $row['category'] }}<input type="hidden" name="categories[{{ $i }}][category]" value="{{ $row['category'] }}"></td>
                    <td>{{ $row['listed'] }}件／{{ $row['total'] }}件</td>
                    <td><input type="text" name="categories[{{ $i }}][label]" value="{{ old("categories.$i.label", $row['setting']?->label) }}" maxlength="32" placeholder="{{ $row['category'] }}" aria-label="{{ $row['category'] }} の表示名"></td>
                    <td><input type="number" name="categories[{{ $i }}][sort_order]" value="{{ old("categories.$i.sort_order", $row['setting']?->sort_order) }}" min="-9999" max="9999" style="max-width:6em" aria-label="{{ $row['category'] }} の並び順"></td>
                    <td><input type="hidden" name="categories[{{ $i }}][in_menu]" value="0"><label style="margin:0;font-weight:400"><input type="checkbox" name="categories[{{ $i }}][in_menu]" value="1" @checked(old("categories.$i.in_menu", $row['setting']?->in_menu ?? true))> 出す</label></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">まだ案件がありません。</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    @if ($rows->isNotEmpty())<p><button type="submit">保存する</button></p>@endif
</form>
@endsection
