<?php

namespace App\Services\Admin;

use App\Models\Admin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * 管理者の二要素認証（TOTP。Google Authenticator などの認証アプリで6桁のコードを出す方式）。
 */
class TwoFactor
{
    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** 認証アプリに読み込ませる QR コード（SVG） */
    public function qrCodeSvg(string $email, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl(config('app.name').' 管理画面', $email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** 登録前の確認（シークレットはまだ保存していない） */
    public function verifySecret(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $this->normalize($code), 1);
    }

    /**
     * 登録済みの管理者のコードを確かめる。同じコード（同じ時間枠）の使い回しは拒否する。
     */
    public function verifyAdmin(Admin $admin, string $code): bool
    {
        if (! $admin->two_factor_secret) {
            return false;
        }

        // 前回の時間枠を渡すと、それより新しい枠で一致したときだけ一致した枠（int）が返る
        $timestep = $this->google2fa->verifyKeyNewer($admin->two_factor_secret, $this->normalize($code), $admin->two_factor_last_timestep ?? 0, 1);

        if (! is_int($timestep)) {
            return false;
        }

        $admin->forceFill(['two_factor_last_timestep' => $timestep])->save();

        return true;
    }

    private function normalize(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }
}
