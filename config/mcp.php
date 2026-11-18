<?php

return [

    'access_token_minutes' => (int) env('MCP_ACCESS_TOKEN_MINUTES', 60),

    'refresh_token_days' => (int) env('MCP_REFRESH_TOKEN_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Domínios aceitos como endereço de retorno (redirect URI) no registro das
    | ferramentas de IA. Claude (claude.ai, Desktop e Code), ChatGPT e Gemini
    | (Gemini CLI, que recebe o retorno em localhost). Para incluir outro
    | domínio, use MCP_REDIRECT_DOMAINS separado por vírgula.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://claude.com,https://chatgpt.com,https://chat.openai.com,https://gemini.google.com,http://localhost,http://127.0.0.1',
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Esquemas próprios de aplicativos desktop (RFC 8252). Nenhum é necessário
    | para as ferramentas homologadas.
    |
    */

    'custom_schemes' => [],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Emissor do OAuth (RFC 8414). Nulo usa o próprio Metrics (Passport).
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    |
    | O Metrics não se conecta a servidores MCP externos.
    |
    */

    'clients' => [],

];
