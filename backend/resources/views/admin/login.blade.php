<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0d1b2a; color: #fff; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
        .box { background:#1b263b; padding:20px; border-radius:10px; width:320px; }
        input, button { width:100%; margin-top:8px; padding:10px; box-sizing:border-box; }
        .err { color: #ffb4b4; font-size: 13px; }
    </style>
</head>
<body>
<form class="box" method="post" action="/admin/login">
    @csrf
    <h2>Вход в админку</h2>
    @if(!empty($error)) <div class="err">{{ $error }}</div> @endif
    @if(($lockedUntil ?? 0) > time()) <div class="err">Аккаунт временно заблокирован</div> @endif
    <input type="password" name="password" placeholder="Пароль" required>
    <input type="text" name="otp" placeholder="OTP-код" required>
    <button type="submit">Войти</button>
</form>
</body>
</html>
