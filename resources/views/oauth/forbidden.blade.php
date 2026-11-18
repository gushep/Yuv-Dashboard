{{-- Pedido de autorização de quem não tem a permissão "Acesso ao MCP": o pedido é descartado. --}}
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso não permitido · Yuv Sprint Metrics</title>
    @include('pwa-head')
    @include('oauth.style')
</head>
<body><main>
    <img src="/yuv-logo.png" alt="YUV">
    <h1>Acesso ao MCP não liberado</h1>
    <p>Seu usuário ({{ $user->email }}) não tem a permissão "Acesso ao MCP", por isso {{ $client->name }} não pode consultar o Yuv Sprint Metrics.</p>
    <p>Peça a liberação a quem administra os usuários do Metrics e tente conectar de novo.</p>
    <a class="button" href="{{ route('home') }}">Voltar ao Metrics</a>
</main></body></html>
