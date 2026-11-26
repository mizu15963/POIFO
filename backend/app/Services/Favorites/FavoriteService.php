<?php

namespace App\Services\Favorites;

use App\Models\Campaign;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * お気に入りの登録・解除（機能仕様 4.5）。
 */
class FavoriteService
{
    /**
     * 登録する。登録済みなら何もしない（エラーにしない）。
     *
     * @throws FavoriteException 上限を超えるとき
     */
    public function add(User $user, Campaign $campaign): Favorite
    {
        return DB::transaction(function () use ($user, $campaign) {
            // 同じ会員が別のサーバへ同時に登録しても上限を超えないよう、会員の行で順番を待たせてから数える
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $existing = Favorite::query()->where('user_id', $user->id)->where('campaign_id', $campaign->id)->first();
            if ($existing) {
                return $existing;
            }

            if (Favorite::query()->where('user_id', $user->id)->count() >= Favorite::LIMIT) {
                throw new FavoriteException('お気に入りは'.Favorite::LIMIT.'件までです。');
            }

            return Favorite::create(['user_id' => $user->id, 'campaign_id' => $campaign->id, 'created_at' => now()]);
        });
    }

    /** 解除する。登録していなければ何もしない */
    public function remove(User $user, Campaign $campaign): void
    {
        Favorite::query()->where('user_id', $user->id)->where('campaign_id', $campaign->id)->delete();
    }
}
