<?php

namespace App\Services\Line;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LINE ログイン（OAuth 2.0 / OpenID Connect）。
 * https://developers.line.biz/ja/docs/line-login/integrate-line-login/
 */
class LineLoginClient
{
    public function authorizeUrl(string $state, string $nonce): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => config('poifo.line.channel_id'),
            'redirect_uri' => config('poifo.line.redirect_uri'),
            'state' => $state,
            'nonce' => $nonce,
            'scope' => 'profile openid',
        ];

        if ($botPrompt = config('poifo.line.bot_prompt')) {
            $params['bot_prompt'] = $botPrompt;
        }

        return config('poifo.line.authorize_url').'?'.http_build_query($params);
    }

    /**
     * 認可コードをトークンに交換し、ID トークンを LINE の検証エンドポイントで検証して中身を返す。
     *
     * @return array{sub: string, name?: string, picture?: string}
     */
    public function fetchProfile(string $code, string $nonce): array
    {
        $token = Http::asForm()->timeout(10)->post(config('poifo.line.token_url'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('poifo.line.redirect_uri'),
            'client_id' => config('poifo.line.channel_id'),
            'client_secret' => config('poifo.line.channel_secret'),
        ]);

        if (! $token->successful() || ! $token->json('id_token')) {
            throw new RuntimeException('LINE token request failed: '.$token->status());
        }

        $verified = Http::asForm()->timeout(10)->post(config('poifo.line.verify_url'), [
            'id_token' => $token->json('id_token'),
            'client_id' => config('poifo.line.channel_id'),
            'nonce' => $nonce,
        ]);

        if (! $verified->successful() || ! $verified->json('sub')) {
            throw new RuntimeException('LINE id_token verification failed: '.$verified->status());
        }

        return $verified->json();
    }
}
