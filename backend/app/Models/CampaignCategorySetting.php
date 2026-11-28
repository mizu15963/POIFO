<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * カテゴリの表示設定（機能仕様 4.7）。ASP のカテゴリ名（category）ごとに、メニューに出す短い表示名・並び・出すかどうか。
 * 行がないカテゴリは「ASP の名前のまま・件数の多い順・メニューに出す」。
 */
class CampaignCategorySetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['in_menu' => 'boolean', 'sort_order' => 'integer'];
    }
}
