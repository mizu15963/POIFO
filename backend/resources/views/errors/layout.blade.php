{{--
  エラー画面の共通レイアウト（api. の LINE ログイン・案件への遷移 /go/{id}、admin. の管理画面で、HTML で返すエラー）。
  JSON を返すリクエスト（/v1/* など）には使われない（bootstrap/app.php の shouldRenderJsonWhen）。
  DB やセッションが落ちていても表示できるよう、config と request だけを使い、CSS もこのファイルに書く。
  使い方: @extends('errors.layout', ['code' => 404, 'heading' => '…', 'message' => '…'])（'pose' => 'maintenance' で画像を変える）
--}}
@php
    $isAdmin = \App\Http\Middleware\RestrictViewerHost::viewerHost(request()) === strtolower((string) config('poifo.hosts.admin'));
    $frontUrl = config('poifo.front_url');
    // 管理画面は相対パスにする（本番の Host はオリジン名なので url() だと別ホストになる）
    $backUrl = $isAdmin ? '/' : $frontUrl.'/';
    $backLabel = $isAdmin ? '管理画面のトップへ' : 'ポイフォのトップへ';
    // 会員向けはキャラクター付き（画像はフロントの配信先から読む）。管理画面は飾らない
    // 既定はエラーページ用の謝るポーズ（実寸 447x480 → 表示幅180pxで高さ193）。メンテナンス（503）は整備士のポーズ
    $pose ??= 'sorry';
    [$mascotWidth, $mascotHeight] = $pose === 'maintenance' ? [280, 207] : [180, 193];
    $mascot = $isAdmin ? null : $frontUrl.'/images/mascot/poifox-'.$pose.'.webp';
@endphp
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }} | {{ $isAdmin ? 'POIFO 管理画面' : 'ポイフォ' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #EEF7F7; color: #1A1E21; font-family: "Noto Sans JP", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif; line-height: 1.7; }
        main { max-width: 480px; min-height: 100vh; margin: 0 auto; background: #fff; padding: 40px 16px; text-align: center; }
        img { display: block; max-width: 100%; height: auto; margin: 0 auto 8px; }
        .code { margin: 0; font-size: .85rem; font-weight: 600; letter-spacing: .08em; color: #B45A00; }
        h1 { margin: 4px 0 12px; font-size: 1.4rem; font-weight: 600; line-height: 1.4; }
        p.message { margin: 0 0 24px; color: #5F6B70; text-align: left; }
        a.btn { display: block; padding: 12px 20px; border-radius: 999px; background: #1A1E21; color: #fff; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
<main>
    @if ($mascot)
        <img src="{{ $mascot }}" alt="" width="{{ $mascotWidth }}" height="{{ $mascotHeight }}">
    @endif
    <p class="code">{{ $code }}</p>
    <h1>{{ $heading }}</h1>
    <p class="message">{{ $message }}</p>
    <a class="btn" href="{{ $backUrl }}">{{ $backLabel }}</a>
</main>
</body>
</html>
