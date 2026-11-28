<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 会員。ログインは LINE のみ。退会しても行は消さない（再入会での不正防止）。
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => '有効',
        self::STATUS_SUSPENDED => '停止中',
        self::STATUS_WITHDRAWN => '退会済み',
    ];

    protected $fillable = ['line_user_id', 'display_name', 'avatar_url', 'status', 'last_login_at'];

    protected $hidden = ['line_user_id', 'remember_token', 'email'];

    /** HasUlids で自動採番する列（主キーは通常の連番のまま） */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'news_seen_at' => 'datetime',
            'suspended_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'referral_disabled_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'email_marketing_opt_in' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * メールアドレスを入れたか（確認待ちを含む。機能仕様 3.5）。
     * 入れていない会員は案件に進めず、初回登録（S27）へ案内する。確認待ちなら案件には進める。
     */
    public function hasRegisteredEmail(): bool
    {
        return $this->email !== null || $this->emailVerifications()->exists();
    }

    /** メールアドレスを確認済みか（換金の申請に必要） */
    public function hasVerifiedEmail(): bool
    {
        return $this->email !== null && $this->email_verified_at !== null;
    }

    public function emailVerifications(): HasMany
    {
        return $this->hasMany(EmailVerification::class);
    }

    /** 確認中のアドレス（なければ null） */
    public function pendingEmailVerification(): ?EmailVerification
    {
        return $this->emailVerifications()->latest('id')->first();
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    public function eventParticipations(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** お知らせの未読の数（最後にお知らせ一覧を開いた日時より後に公開されたもの。開いたことがなければ登録日時から） */
    public function unreadNewsCount(): int
    {
        $since = $this->news_seen_at ?? $this->created_at;

        return News::visible()->when($since, fn ($q) => $q->where('published_at', '>', $since))->count();
    }

    /** 紹介元（登録のときだけ入れ、あとで変えない） */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }

    /** 紹介した友達 */
    public function referees(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class);
    }
}
