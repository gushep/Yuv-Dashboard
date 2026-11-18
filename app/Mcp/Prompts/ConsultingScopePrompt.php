<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * Escopo Funcional V1 no padrão de Consultoria YUV: entrega à IA as instruções de uso pelo MCP, o método, o modelo e a
 * referência da plataforma, versionados em resources/mcp/consultoria.
 */
#[Name('escopo-funcional')]
#[Description('Gera o Escopo Funcional V1 (padrão de Consultoria Funcional YUV) de um card do Monday: instruções, método, modelo e referência da plataforma. A IA lê o card com monday-card, estima com task-history e só publica com publish-scope quando o usuário pedir.')]
class ConsultingScopePrompt extends Prompt
{
    /** Arquivos do método, na ordem em que a IA deve lê-los. */
    public const FILES = [
        'instrucoes.md' => 'Instruções',
        'metodo-consultoria.md' => 'Método de consultoria',
        'modelo.md' => 'Modelo de referência',
        'referencia-plataforma.md' => 'Referência da plataforma',
    ];

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['card' => ['required', 'regex:/^\d{1,20}$/']], [
            'card.required' => 'Informe o id do card do Monday.',
            'card.regex' => 'O id do card deve ter só números.',
        ]);
        $sections = ["Card do Monday: {$validated['card']}. Comece lendo o card com a ferramenta monday-card."];
        foreach (self::FILES as $file => $title) {
            $sections[] = "=== {$title} ===\n\n".file_get_contents(resource_path("mcp/consultoria/{$file}"));
        }

        return Response::text(implode("\n\n", $sections));
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('card', 'Id do card do Monday (Backlog, Tarefas ou Funil de Demandas).', required: true),
        ];
    }
}
