<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SportRadar Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1d2430; }
        header { background: #0d1b2a; color: #fff; padding: 12px 16px; display: flex; gap: 12px; align-items: center; }
        header a { color: #fff; text-decoration: none; font-size: 14px; }
        .wrap { max-width: 1100px; margin: 18px auto; padding: 0 12px; }
        .card { background: #fff; border-radius: 8px; padding: 14px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e6e9ee; text-align: left; padding: 8px; font-size: 13px; }
        input, textarea, select, button { padding: 8px; font-size: 14px; }
        textarea { width: 100%; min-height: 100px; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .ok { color: #1c7c36; }
        .err { color: #aa1f1f; }
        form { margin: 0; }
    </style>
</head>
<body>
<header>
    <strong>SportRadar Admin</strong>
    <a href="/admin">Dashboard</a>
    <a href="/admin/credentials">Credentials</a>
    <a href="/admin/content">Content</a>
    <a href="/admin/seo">SEO</a>
    <a href="/admin/audit">Audit</a>
    <form action="/admin/logout" method="post" style="margin-left:auto;">
        @csrf
        <button type="submit">Logout</button>
    </form>
</header>
<div class="wrap">
    @if(session('ok')) <div class="card ok">{{ session('ok') }}</div> @endif
    @if(session('error')) <div class="card err">{{ session('error') }}</div> @endif
    @yield('content')
</div>
</body>
</html>
