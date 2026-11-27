<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 案件の特集の印（Campaign::TAGS のどれか）。管理画面で付け外しする */
class CampaignTag extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
