<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · YUV Dashboard</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:linear-gradient(135deg,#eef5ff,#f7f3ff);font:15px system-ui,sans-serif;color:#10214b}
        main{width:min(92vw,420px);padding:34px;background:#fff;border:1px solid #dfe7f4;border-radius:20px;box-shadow:0 18px 55px #172a5c1a}
        img{width:130px;height:auto}h1{margin:24px 0 6px;font-size:27px}p{color:#61718e;margin:0 0 26px}
        label{display:block;font-weight:700;margin:16px 0 6px}input{width:100%;padding:13px;border:1px solid #cbd6e9;border-radius:9px;font:inherit}input:focus{outline:2px solid #3673ee}
        button{width:100%;margin-top:25px;padding:13px;background:#1554e8;color:#fff;font:700 15px system-ui,sans-serif;border:0;border-radius:9px;cursor:pointer}
        .error{color:#bb1c38;background:#fff0f2;border-radius:8px;padding:10px;margin:12px 0}
    </style>
</head>
<body><main>
    <img src="/yuv-logo.png" alt="YUV">
    <h1>Acessar dashboard</h1>
    <p>Entre com sua conta para consultar os indicadores.</p>
    @if ($errors->any()) <div class="error" role="alert">{{ $errors->first() }}</div> @endif
    <form method="post" action="/login">
        @csrf
        <label for="email">E-mail</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" autofocus>
        <label for="password">Senha</label><input id="password" name="password" type="password" required autocomplete="current-password">
        <button type="submit">Entrar</button>
    </form>
</main></body></html>
