{{-- Consentimento do OAuth (Passport): a pessoa autoriza uma ferramenta de IA a consultar o Metrics pelo MCP. --}}
@php($redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null))
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autorizar acesso · Yuv Sprint Metrics</title>
    @include('pwa-head')
    @include('oauth.style')
</head>
<body><main>
    <img src="/yuv-logo.png" alt="YUV">
    <h1>Autorizar {{ $client->name }}</h1>
    <p>Esta ferramenta de IA quer consultar o Yuv Sprint Metrics em seu nome, pelo MCP.</p>
    <dl>
        <dt>Conta</dt><dd>{{ $user->name }} · {{ $user->email }}</dd>
        @if ($redirectUri)
            <dt>Retorno para</dt><dd class="mono">{{ parse_url($redirectUri, PHP_URL_HOST) ?: $redirectUri }}</dd>
        @endif
    </dl>
    <section class="scope">
        <strong>O que ela poderá fazer</strong>
        <ul>
            <li>Consultar os dados das telas que você vê no Metrics (sprints, cerimônias, capacity e indicadores).</li>
            <li>Ler os cards do Monday para montar escopos de consultoria e, quando você pedir, publicá-los no card, se você tiver a permissão de atualizar cards.</li>
        </ul>
        <small>O acesso pode ser revogado a qualquer momento em <a href="{{ route('mcp.connections') }}">Ferramentas de IA conectadas</a>.</small>
    </section>
    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="ghost">Negar</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">Autorizar</button>
        </form>
    </div>
</main></body></html>
