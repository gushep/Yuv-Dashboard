# Yuv Sprint Metrics em Laravel

Esta é a primeira etapa da migração do dashboard local. O aplicativo original em PowerShell permanece na pasta pai. Esta versão usa Laravel 13, login com sessão, banco SQLite local e integração Monday no servidor. O token é criptografado no banco com a chave `APP_KEY` e nunca é incluído na resposta da API.

## Iniciar neste computador

1. Execute `Iniciar Laravel.cmd` na pasta pai.
2. Abra `http://127.0.0.1:8000/login`.
3. Antes do primeiro login, crie o usuário inicial no terminal, a partir da pasta `laravel`:

   ```powershell
   ..\.tools\php\php.exe artisan app:create-admin seu-email@empresa.com "Seu Nome"
   ```

   O comando solicita a senha sem mostrá-la na tela.
4. Entre no aplicativo e configure novamente o quadro e o token na tela **Configurações**. O token DPAPI do aplicativo antigo não é copiado automaticamente.

Para instalar em outro ambiente, use PHP 8.3 ou superior com as extensões exigidas pelo Laravel, Composer, `composer install`, uma configuração `.env` própria, `php artisan key:generate` e `php artisan migrate`. Configure o servidor web para servir somente `public/`. Nunca publique `.env`, `database/database.sqlite`, `.monday-local` ou a pasta `.tools`.

## Estado da migração

- O HTML, CSS e JavaScript dos painéis foram preservados, inclusive Sustentação, Sprint, Capacity e apresentação em carrossel.
- As rotas `/api/config`, `/api/boards`, `/api/inspect`, `/api/sync`, `/api/refresh` e `/api/capacity` agora são atendidas por Laravel e exigem login. As rotas de escrita usam CSRF.
- A consulta GraphQL, a paginação, o mapeamento de colunas, a conversão de horas e o cache compartilhado foram portados para PHP.
- Esta etapa ainda usa uma única configuração de quadro para todos os usuários. Não há gestão de papéis, organizações independentes, OAuth Monday, fila de sincronização nem implantação de produção. Essas partes são necessárias antes de abrir o sistema como SaaS.

## Testes

```powershell
..\.tools\php\php.exe vendor\bin\phpunit
```

