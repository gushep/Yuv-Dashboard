<?php

namespace App\Mcp\Resources;

use App\Mcp\Prompts\ConsultingScopePrompt;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('consultoria-yuv')]
#[Uri('metrics://consultoria/metodo')]
#[MimeType('text/markdown')]
#[Description('Método, checklist, modelo de referência e instruções de consultoria YUV. Revisar com o usuário antes de publicar; o MCP não faz levantamento técnico nos repositórios.')]
class ConsultingMethodResource extends Resource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        $sections = [];
        foreach (ConsultingScopePrompt::FILES as $file => $title) {
            $sections[] = '# '.$title."\n\n".file_get_contents(resource_path("mcp/consultoria/{$file}"));
        }

        return Response::text(implode("\n\n", $sections));
    }
}
