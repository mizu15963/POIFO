<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 友達紹介の初回特典の設定（1行だけ。管理画面 A17 で変える）。
 */
class ReferralSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'initial_bonus_points' => 'integer',
            'initial_threshold_points' => 'integer',
            'initial_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        // マイグレーションで1行入れている。万一なくても仮の値で動くようにする
        return self::query()->orderBy('id')->first()
            ?? self::create(['initial_bonus_points' => 100, 'initial_threshold_points' => 1000, 'initial_days' => 180]);
    }
}
