<?php

namespace App\Services\Email;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 会員のメールアドレスの登録と確認（機能仕様 3.5）。登録・確認・消去はこのクラスだけで行う。
 *
 * - 入力したアドレスは email_verifications に入れ、確認メールのリンクを開いたら users.email に移す
 * - 確認前のアドレスには連絡を送らない。変えるときも確認が済むまでは古いアドレスのまま
 * - 同じアドレスを別の会員が確認済みで使っていたら登録できない（どの会員かは出さない）
 */
class EmailVerificationService
{
    /** リンクの有効時間 */
    public const VALID_HOURS = 24;

    /** 確認メールを送れる回数（登録・再送を合わせて1時間あたり） */
    public const SENDS_PER_HOUR = 3;

    private const UNAVAILABLE = 'このメールアドレスは登録できません。';

    public static function normalize(string $email): string
    {
        // 大文字・小文字の違いで同じアドレスを二重に登録できないよう、小文字にそろえる
        return Str::lower(trim($email));
    }

    /**
     * アドレスを登録（または変更）し、確認メールを送る。確認済みの今のアドレスと同じなら何もしない。
     */
    public function register(User $user, string $email): void
    {
        $email = self::normalize($email);

        if (! $user->isActive()) {
            throw new EmailVerificationException('現在このアカウントでは登録できません。');
        }

        if ($user->email === $email && $user->email_verified_at !== null) {
            // 確認済みのアドレスに戻した: 確認中の別のアドレスは取り下げる
            EmailVerification::where('user_id', $user->id)->delete();

            return;
        }

        if (self::usedByOther($user, $email)) {
            throw new EmailVerificationException(self::UNAVAILABLE);
        }

        $this->send($user, $email);
    }

    /** 確認中のアドレスに確認メールを送り直す（期限切れでも送れる） */
    public function resend(User $user): void
    {
        $pending = $user->pendingEmailVerification();

        if ($pending === null) {
            throw new EmailVerificationException('確認待ちのメールアドレスがありません。');
        }

        if (self::usedByOther($user, $pending->email)) {
            throw new EmailVerificationException(self::UNAVAILABLE);
        }

        $this->send($user, $pending->email);
    }

    /**
     * 確認メールのリンクのトークンで確認する。リンクは別のブラウザ（メールアプリ）で開かれることが多いので、
     * ログインは求めない（トークンは推測できない長さにしている）。
     */
    public function verify(string $token): User
    {
        $verification = EmailVerification::where('token_hash', hash('sha256', $token))->first();

        if ($verification === null) {
            throw new EmailVerificationException('リンクが無効です。', 'token');
        }

        if ($verification->isExpired()) {
            throw new EmailVerificationException('リンクの有効期限が切れています。', 'token');
        }

        try {
            return DB::transaction(function () use ($verification) {
                $user = User::query()->lockForUpdate()->findOrFail($verification->user_id);

                if (! $user->isActive()) {
                    throw new EmailVerificationException('リンクが無効です。', 'token');
                }

                if (self::usedByOther($user, $verification->email)) {
                    throw new EmailVerificationException(self::UNAVAILABLE, 'token');
                }

                $user->forceFill(['email' => $verification->email, 'email_verified_at' => now()])->save();
                EmailVerification::where('user_id', $user->id)->delete();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // 別の会員が同じアドレスを同時に確認した
            throw new EmailVerificationException(self::UNAVAILABLE, 'token');
        }
    }

    /** 退会したときに消す（MemberService から呼ぶ） */
    public function forget(User $user): void
    {
        EmailVerification::where('user_id', $user->id)->delete();
        $user->forceFill(['email' => null, 'email_verified_at' => null, 'email_marketing_opt_in' => false]);
    }

    private function send(User $user, string $email): void
    {
        // 他人のアドレスにメールを送りつけるのを抑える。入力ミスで弾いたものは数えない
        $limitKey = 'email-send:user:'.$user->id;

        if (RateLimiter::tooManyAttempts($limitKey, self::SENDS_PER_HOUR)) {
            throw new EmailVerificationException('確認メールの送信は1時間に'.self::SENDS_PER_HOUR.'回までです。しばらくしてからお試しください。');
        }

        RateLimiter::hit($limitKey, 3600);
        $token = Str::random(64);

        DB::transaction(function () use ($user, $email, $token) {
            // 確認中のものは1件だけ（古いリンクは使えなくする）
            EmailVerification::where('user_id', $user->id)->delete();
            EmailVerification::create([
                'user_id' => $user->id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::VALID_HOURS),
            ]);
        });

        $url = config('poifo.front_url').'/mypage/account/verify/?token='.$token;
        Mail::to($email)->queue(new EmailVerificationMail($url, self::VALID_HOURS));
    }

    private static function usedByOther(User $user, string $email): bool
    {
        return User::where('email', $email)->where('id', '!=', $user->id)->exists();
    }
}
