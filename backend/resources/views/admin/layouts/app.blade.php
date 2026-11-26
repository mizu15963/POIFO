<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', '管理画面') | POIFO 管理画面</title>
    <style>
        :root { --brand: #3AD1CE; --brand-bg: #E9F9F9; --text: #1A1E21; --muted: #5F6B70; --border: #E3E9EA; --danger: #C4353A; --warn-bg: #FFF4DB; }
        * { box-sizing: border-box; }
        body { font-family: "Noto Sans JP", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif; color: var(--text); margin: 0; background: #F7FAFA; line-height: 1.6; }
        header { background: var(--text); color: #fff; }
        header .inner, main { max-width: 1200px; margin: 0 auto; padding: 0 16px; }
        header .inner { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; min-height: 52px; gap: 16px; }
        header a { color: #fff; text-decoration: none; margin-right: 16px; }
        header .brand { font-weight: 700; border-left: 4px solid var(--brand); padding-left: 8px; }
        main { padding-top: 24px; padding-bottom: 48px; }
        h1 { font-size: 1.3rem; font-weight: 600; margin: 0 0 16px; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .narrow { max-width: 420px; margin: 48px auto; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input[type=text], input[type=email], input[type=password], input[type=month], input[type=date], input[type=datetime-local], input[type=number], input[type=url], textarea, select { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 1rem; }
        .inline { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        .inline > div { min-width: 160px; }
        button, .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; border: 1px solid var(--text); background: var(--text); color: #fff; font-size: .95rem; cursor: pointer; text-decoration: none; }
        .btn-outline { background: #fff; color: var(--text); }
        .btn-danger { background: var(--danger); border-color: var(--danger); }
        .flash { background: var(--brand-bg); border: 1px solid var(--brand); padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .errors { background: #FDECEC; border: 1px solid var(--danger); color: var(--danger); padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .errors ul { margin: 0; padding-left: 1.2em; }
        table { width: 100%; border-collapse: collapse; background: #fff; font-size: .9rem; }
        th, td { border-bottom: 1px solid var(--border); padding: 8px; text-align: left; vertical-align: top; }
        th { background: var(--brand-bg); white-space: nowrap; }
        td.num { text-align: right; white-space: nowrap; }
        tr.warn td { background: var(--warn-bg); }
        .muted { color: var(--muted); font-size: .85rem; }
        .badge { display: inline-block; padding: 0 8px; border-radius: 999px; background: var(--border); font-size: .8rem; white-space: nowrap; }
        .badge-requested { background: #FFF4DB; } .badge-approved { background: var(--brand-bg); } .badge-paid { background: #E6F4EA; } .badge-rejected { background: #FDECEC; }
        .table-wrap { overflow-x: auto; }
        .qr svg { width: 200px; height: 200px; }
        textarea { min-height: 120px; font-family: inherit; }
        .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0 16px; }
        .stats { display: flex; flex-wrap: wrap; gap: 16px; }
        .stats > div { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; min-width: 160px; }
        .stats strong { font-size: 1.3rem; }
        .pager { margin: 12px 0; }
        .pager nav { display: flex; gap: 8px; flex-wrap: wrap; }
        h2 { font-size: 1.05rem; margin: 0 0 8px; }
        pre { background: #fff; border: 1px solid var(--border); padding: 12px; border-radius: 8px; overflow-x: auto; font-size: .85rem; }
        code { background: var(--brand-bg); padding: 2px 6px; border-radius: 4px; word-break: break-all; }
    </style>
</head>
<body>
@auth('admin')
<header>
    <div class="inner">
        <nav>
            <a class="brand" href="{{ route('admin.home') }}">POIFO 管理画面</a>
            <a href="{{ route('admin.withdrawals.index') }}">換金申請</a>
            <a href="{{ route('admin.members.index') }}">会員</a>
            <a href="{{ route('admin.campaigns.index') }}">案件</a>
            <a href="{{ route('admin.events.index') }}">イベント</a>
            <a href="{{ route('admin.news.index') }}">お知らせ</a>
            <a href="{{ route('admin.postbacks.index') }}">成果通知</a>
            <a href="{{ route('admin.api-docs.ui') }}" target="_blank" rel="noopener">API 仕様書</a>
        </nav>
        <form method="post" action="{{ route('admin.logout') }}">
            @csrf
            <span class="muted" style="color:#ccc;margin-right:8px">{{ auth('admin')->user()->name }}</span>
            <button type="submit" class="btn-outline">ログアウト</button>
        </form>
    </div>
</header>
@endauth
<main>
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
</body>
</html>
