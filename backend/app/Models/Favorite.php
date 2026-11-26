<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 会員のお気に入り案件（機能仕様 4.5）。掲載が終わった案件も行は残し、一覧で「掲載終了」と出す。
 */
class Favorite extends Model
{
    public const UPDATED_AT = null;

    /** 1人あたりの上限 */
    public const LIMIT = 200;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
