<?php

namespace App\Services\Referrals;

use App\Models\ReferralRank;
use App\Models\ReferralSetting;
use Illuminate\Support\Facades\DB;

/**
 * 紹介の設定（ランク・初回特典）を管理画面（A17）から変える。
 * 変えても確定した報酬はさかのぼらない（判定中の報酬は、確定した時点の設定で計算する）。
 */
class ReferralSettingsService
{
    /**
     * @param  array<int, array{id?: int|string|null, name: string, rate: string|float, min_valid_referrals: int|string, delete?: bool}>  $ranks  特別パートナー以外のランク（id がなければ追加）
     * @param  array{name: string, rate: string|float}  $partner  特別パートナー（rate は率の上限）
     * @param  array{initial_bonus_points: int, initial_threshold_points: int, initial_days: int}  $settings
     * @return array{before: array, after: array} 操作ログに残す変更前後
     */
    public function save(array $ranks, array $partner, array $settings): array
    {
        $kept = array_values(array_filter($ranks, fn ($r) => empty($r['delete'])));
        if ($kept === []) {
            throw new ReferralException('ランクを1つ以上残してください。');
        }

        $mins = array_map(fn ($r) => (int) $r['min_valid_referrals'], $kept);
        if (! in_array(0, $mins, true)) {
            throw new ReferralException('必要な有効紹介人数が0人のランク（全会員が入るランク）を1つ残してください。');
        }
        if (count($mins) !== count(array_unique($mins))) {
            throw new ReferralException('必要な有効紹介人数が同じランクがあります。人数はランクごとに変えてください。');
        }

        return DB::transaction(function () use ($ranks, $partner, $settings) {
            $before = $this->snapshot();

            $generalIds = ReferralRank::where('code', '!=', ReferralRank::PARTNER)->pluck('id')->all();
            foreach ($ranks as $row) {
                $id = isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null;
                if ($id !== null && ! in_array($id, $generalIds, true)) {
                    throw new ReferralException('ランクが見つかりません。画面を開き直してください。');
                }

                if (! empty($row['delete'])) {
                    if ($id !== null) {
                        ReferralRank::whereKey($id)->delete();
                    }

                    continue;
                }

                $values = [
                    'name' => $row['name'],
                    'rate' => ReferralRank::toBps($row['rate']) / 100,
                    'min_valid_referrals' => (int) $row['min_valid_referrals'],
                    'sort_order' => (int) $row['min_valid_referrals'],
                ];
                $id === null
                    ? ReferralRank::create($values + ['code' => ReferralService::newRankCode()])
                    : ReferralRank::whereKey($id)->update($values);
            }

            ReferralRank::updateOrCreate(['code' => ReferralRank::PARTNER], [
                'name' => $partner['name'],
                'rate' => ReferralRank::toBps($partner['rate']) / 100,
                'min_valid_referrals' => 0,
                'sort_order' => 99999,
            ]);

            ReferralSetting::current()->update([
                'initial_bonus_points' => (int) $settings['initial_bonus_points'],
                'initial_threshold_points' => (int) $settings['initial_threshold_points'],
                'initial_days' => (int) $settings['initial_days'],
            ]);

            return ['before' => $before, 'after' => $this->snapshot()];
        });
    }

    private function snapshot(): array
    {
        $s = ReferralSetting::current();

        return [
            'ranks' => ReferralRank::orderBy('sort_order')->get()
                ->map(fn (ReferralRank $r) => [$r->code, $r->name, ReferralRank::formatBps($r->rateBps()), $r->min_valid_referrals])->all(),
            'initial' => [$s->initial_bonus_points, $s->initial_threshold_points, $s->initial_days],
        ];
    }
}
