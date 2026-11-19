<?php

namespace App\Services\Points;

use App\Models\PointTransaction;
use App\Models\User;

/**
 * 残高の計算。台帳（point_transactions）から毎回集計する。会員数が少ないので集計テーブルは持たない。
 */
class PointBalance
{
    /** @return array{available: int, pending: int} */
    public function for(User $user): array
    {
        $sums = PointTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [PointTransaction::STATUS_CONFIRMED, PointTransaction::STATUS_PENDING])
            ->selectRaw('status, SUM(points) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            // 利用可能（換金に使える）= 確定分の合計
            'available' => (int) ($sums[PointTransaction::STATUS_CONFIRMED] ?? 0),
            // 判定中 = ASP で承認待ちの成果
            'pending' => (int) ($sums[PointTransaction::STATUS_PENDING] ?? 0),
        ];
    }
}
