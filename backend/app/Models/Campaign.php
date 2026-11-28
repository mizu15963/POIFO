<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ASP の案件のキャッシュ。is_hidden / is_featured / sort_order と特集の印（tags）は運営側の設定で、同期では上書きしない。
 */
class Campaign extends Model
{
    /**
     * 特集の印（トップの案件メニュー、機能仕様 4.3）。キーは API の ?tag= と campaign_tags.tag の値。
     * 「おすすめ」は is_featured、「高還元」はポイント順なので、ここには入れない。
     */
    public const TAGS = [
        'beginner' => 'はじめて向け',
        'game' => 'ゲーム案件特集',
        'together' => 'みんなで挑戦中',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_platforms' => 'array',
            'conversion_actions' => 'array',
            'raw' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'synced_at' => 'datetime',
            'is_hidden' => 'boolean',
            'is_featured' => 'boolean',
            'referral_excluded' => 'boolean',
        ];
    }

    /** 会員に見せてよい案件（配信中・非表示でない・期間内） */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('external_status', 'started')
            ->where('is_hidden', false)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CampaignTag::class);
    }

    /** 特集の印が付いた案件だけ */
    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->whereHas('tags', fn (Builder $q) => $q->where('tag', $tag));
    }

    /**
     * 特集の印を付け直す（渡したものだけにする）。
     *
     * @param  list<string>  $tags  Campaign::TAGS のキー
     */
    public function syncTags(array $tags): void
    {
        $tags = array_values(array_intersect(array_keys(self::TAGS), $tags));
        $this->tags()->whereNotIn('tag', $tags)->delete();
        $have = $this->tags()->pluck('tag')->all();
        foreach (array_diff($tags, $have) as $tag) {
            $this->tags()->create(['tag' => $tag]);
        }
        $this->unsetRelation('tags');
    }

    /** @return list<string> 付いている特集の印（TAGS の順） */
    public function tagKeys(): array
    {
        $have = $this->tags->pluck('tag')->all();

        return array_values(array_filter(array_keys(self::TAGS), fn ($k) => in_array($k, $have, true)));
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }
}
