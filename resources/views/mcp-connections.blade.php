{{-- Ferramentas de IA conectadas (McpConnectionController): autorizações da pessoa pelo MCP, com revogação, e como conectar. --}}
@php($date = fn ($value) => $value?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—')
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ferramentas de IA conectadas · Yuv Sprint Metrics</title>
    @include('pwa-head')
    @include('oauth.style')
</head>
<body><main>
    <img src="/yuv-logo.png" alt="YUV">
    <h1>Ferramentas de IA conectadas</h1>
    @if (session('notice')) <div class="notice" role="status">{{ session('notice') }}</div> @endif

    @if (! $user->canUseMcp())
        <p>Seu usuário não tem a permissão "Acesso ao MCP". Peça a liberação a quem administra os usuários do Metrics.</p>
    @else
        <p>Ferramentas que você autorizou a consultar o Metrics em seu nome. Revogar corta o acesso na hora.</p>
        @if ($connections->isEmpty())
            <p class="empty">Nenhuma ferramenta conectada.</p>
        @else
            <table>
                <thead><tr><th>Ferramenta</th><th>Autorizada em</th><th>Último uso</th><th></th></tr></thead>
                <tbody>
                @foreach ($connections as $connection)
                    <tr>
                        <td>{{ $connection['name'] }}</td>
                        <td>{{ $date($connection['authorizedAt']) }}</td>
                        <td>{{ $date($connection['lastUsedAt']) }}</td>
                        <td>
                            <form method="POST" action="{{ route('mcp.connections.destroy', $connection['clientId']) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="danger" aria-label="Revogar o acesso de {{ $connection['name'] }}">Revogar</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <section class="scope">
            <strong>Como conectar</strong>
            <p>Endereço do MCP: <code>{{ $mcpUrl }}</code></p>
            <ul>
                <li><b>Claude</b> (claude.ai e Desktop): Configurações → Conectores → Adicionar conector personalizado, com o endereço acima.</li>
                <li><b>Claude Code</b>: <code>claude mcp add --transport http yuv-metrics {{ $mcpUrl }}</code> e depois <code>/mcp</code> para autorizar.</li>
                <li><b>ChatGPT</b>: Configurações → Apps e conectores → criar conector com o endereço acima e autenticação OAuth.</li>
                <li><b>Gemini CLI</b>: em <code>settings.json</code>, <code>"mcpServers": {"yuv-metrics": {"httpUrl": "{{ $mcpUrl }}"}}</code> e depois <code>/mcp auth yuv-metrics</code>.</li>
            </ul>
            <small>A ferramenta abre esta página do Metrics para você entrar e autorizar. Os nomes dos menus podem variar conforme o plano de cada ferramenta.</small>
        </section>
    @endif
    <p style="margin-top:20px"><a href="{{ route('home') }}">Voltar ao Metrics</a></p>
</main></body></html>
