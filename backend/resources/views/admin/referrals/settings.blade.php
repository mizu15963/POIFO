@extends('admin.layouts.app')
@section('title', '紹介の設定')
@section('content')
@php($fmt = fn ($rate) => \App\Models\ReferralRank::formatBps(\App\Models\ReferralRank::toBps($rate)))
<h1>紹介の設定</h1>
<p class="muted">友達紹介のランクと初回特典の数字です（機能仕様 15章）。保存すると、これから確定する紹介報酬に使われます。確定済みの紹介報酬は変わりません（判定中の報酬は、確定した時点の設定で計算します）。変更は操作ログに残ります。</p>
<form method="post" action="{{ route('admin.referrals.settings.update') }}" onsubmit="return confirm('紹介の設定を保存します。これから確定する紹介報酬に使われます。よろしいですか？')">
    @csrf @method('put')
    <div class="card">
        <h2>ランク</h2>
        <p class="muted">一般のランクは「有効な紹介の人数」（紹介した友達のうち初回特典の条件を満たした人。退会・停止中は数えない）で毎回決まります。0人のランクを必ず1つ残してください。消したランクにいた会員は、人数に応じて下のランクになります。</p>
        <div class="table-wrap"><table>
            <thead><tr><th>名前</th><th>紹介報酬の率（%）</th><th>必要な有効紹介人数</th><th>削除</th></tr></thead>
            <tbody>
            @php($i = 0)
            @foreach ($ranks as $rank)
                <tr>
                    <td><input type="hidden" name="ranks[{{ $i }}][id]" value="{{ $rank->id }}"><input type="text" name="ranks[{{ $i }}][name]" value="{{ old("ranks.$i.name", $rank->name) }}" maxlength="32" aria-label="名前"></td>
                    <td><input type="number" name="ranks[{{ $i }}][rate]" value="{{ old("ranks.$i.rate", $fmt($rank->rate)) }}" min="0" max="100" step="0.01" aria-label="率"></td>
                    <td><input type="number" name="ranks[{{ $i }}][min_valid_referrals]" value="{{ old("ranks.$i.min_valid_referrals", $rank->min_valid_referrals) }}" min="0" aria-label="必要な有効紹介人数"></td>
                    <td><label style="margin:0;font-weight:400"><input type="checkbox" name="ranks[{{ $i }}][delete]" value="1" @checked(old("ranks.$i.delete"))> 削除</label></td>
                </tr>
                @php($i++)
            @endforeach
                <tr>
                    <td><input type="text" name="ranks[{{ $i }}][name]" value="{{ old("ranks.$i.name") }}" maxlength="32" placeholder="追加するときだけ入力" aria-label="追加するランクの名前"></td>
                    <td><input type="number" name="ranks[{{ $i }}][rate]" value="{{ old("ranks.$i.rate") }}" min="0" max="100" step="0.01" aria-label="追加するランクの率"></td>
                    <td><input type="number" name="ranks[{{ $i }}][min_valid_referrals]" value="{{ old("ranks.$i.min_valid_referrals") }}" min="0" aria-label="追加するランクの人数"></td>
                    <td class="muted">追加</td>
                </tr>
            </tbody>
        </table></div>
    </div>
    <div class="grid2">
        <div class="card">
            <h2>特別パートナー</h2>
            <p class="muted">運営が会員詳細で指定する招待制のランクです。率は会員ごとに決め、ここで決めた上限を超えません（上限を下げると、上限を超える率の会員は上限の率になります）。</p>
            <label for="partner_name">名前</label>
            <input id="partner_name" type="text" name="partner[name]" value="{{ old('partner.name', $partner?->name ?? '特別パートナー') }}" maxlength="32" required>
            <label for="partner_rate">率の上限（%）</label>
            <input id="partner_rate" type="number" name="partner[rate]" value="{{ old('partner.rate', $partner ? $fmt($partner->rate) : 15) }}" min="0" max="100" step="0.01" required>
        </div>
        <div class="card">
            <h2>初回特典</h2>
            <p class="muted">紹介した友達の確定した案件の成果の合計が、登録から決まった日数のうちに条件のポイント以上になったら、紹介した人に一度だけ付けます。</p>
            <label for="initial_bonus_points">初回特典のポイント</label>
            <input id="initial_bonus_points" type="number" name="initial_bonus_points" value="{{ old('initial_bonus_points', $settings->initial_bonus_points) }}" min="0" required>
            <label for="initial_threshold_points">条件のポイント（友達の確定ポイントの合計）</label>
            <input id="initial_threshold_points" type="number" name="initial_threshold_points" value="{{ old('initial_threshold_points', $settings->initial_threshold_points) }}" min="0" required>
            <label for="initial_days">期限（登録から何日以内）</label>
            <input id="initial_days" type="number" name="initial_days" value="{{ old('initial_days', $settings->initial_days) }}" min="1" required>
        </div>
    </div>
    <p><button type="submit">保存する</button></p>
</form>
@endsection
