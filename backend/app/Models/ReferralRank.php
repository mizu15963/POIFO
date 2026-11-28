<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 紹介ランク（機能仕様 15.4）。管理画面（A17）で名前・率・必要な有効紹介人数を変え、増やし・減らしできる。
 * code = partner は特別パートナー用で消せず、rate は個別の率の上限として使う。
 */
class ReferralRank extends Model
{
    public const PARTNER = 'partner';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['min_valid_referrals' => 'integer', 'sort_order' => 'integer'];
    }

    public function isPartner(): bool
    {
        return $this->code === self::PARTNER;
    }

    /** 率（%）を 0.01% 単位の整数にする。ポイントの計算は整数で行う */
    public function rateBps(): int
    {
        return self::toBps($this->rate);
    }

    public static function toBps(mixed $rate): int
    {
        return (int) round(((float) $rate) * 100);
    }

    /** 0.01% 単位の整数を表示用の % にする（例: 850 → "8.5"） */
    public static function formatBps(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }
}
