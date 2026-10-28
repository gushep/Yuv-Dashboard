<p align="center"><img src="public/yuv-logo.png" width="180" alt="YUV"></p>

# Yuv Sprint Metrics

Plataforma interna de acompanhamento de métricas do time responsável pela evolução e sustentação da plataforma YUV. O dashboard transforma os dados dos cards da Monday em uma visão compartilhada de planejamento, execução, suporte e entregas por sprint.

Seu propósito é ajudar o time a entender onde o esforço está sendo consumido, acompanhar demandas de suporte, comparar o planejamento com a execução e comunicar as mudanças implementadas. As telas também funcionam como painéis de acompanhamento e apresentação.

## Para quem serve

- **Gestão e liderança técnica:** acompanhar indicadores, capacidade e previsibilidade das sprints.
- **Desenvolvimento e sustentação:** consultar demandas, horas registradas, responsáveis e distribuição do esforço.
- **CS e Comercial:** conhecer as mudanças implementadas por meio da release da sprint.

Os indicadores contextualizam o trabalho do time. Horas sem apontamento não representam, por si só, ociosidade ou produtividade individual.

## Painéis disponíveis

| Tela | Finalidade |
| --- | --- |
| Torre de controle | Tela inicial. Farol da sprint, trajetória dia a dia (burnup), previsão probabilística, ponto de decisão, manobras sugeridas, alertas e compromisso da sprint. Ao passar o mouse nos números do farol e nos sinais, explica o cálculo; ao clicar, lista as tasks do indicador. |
| Burndown | Três painéis (Geral, Suporte e Planejado e não planejado) com restante, concluído e escopo da sprint no fim de cada dia útil, em cards ou horas estimadas, contra a linha ideal (do escopo do primeiro dia útil até zero no último). Mostra ritmo médio, ritmo necessário e o SLA do suporte (tempo corrido da abertura do card até Implementado: média, mediana, maior tempo e lista dos cards), e explica o cálculo na própria tela. |
| Dashboard e sprints | Visão dos indicadores e detalhamento por sprint. O item Sprints do menu abre a sprint ativa mais recente (a que contém o dia de hoje; sem ela, a última já iniciada), e o filtro no topo da tela troca para as demais. Nos indicadores da sprint, passar o mouse sobre um card mostra o que ele apresenta e o critério de cálculo; clicar abre a lista das tasks daquele indicador, com link para o card na Monday. |
| Épicos e time | Consulta das informações do quadro por diferentes perspectivas. |
| Tarefas (antiga Dado Bruto) | Quatro abas, na ordem do fluxo: **Roadmap** e **Backlog** (quadros configurados em **Configurações → Abas Roadmap e Backlog**, como o Funil de Demandas e o Product Backlog; colunas reconhecidas pelo título, Kanban na ordem do fluxo de status de cada quadro e filtros de impacto/severidade, origem, módulo e esforço), **Sprint atual** (todas as tarefas da sprint) e **Suporte** (só Tipo = Suporte). Cada aba guarda os próprios filtros, sprint (começando pela ativa) e agrupamento, e todas têm as mesmas visualizações, sem paginação; com a permissão "Atualizar cards", itens do Roadmap e do Backlog também têm status alterado, comentários e anexos. Lista das tarefas, aberta na sprint ativa, com filtros de sprint, vários responsáveis, vários status, vários clientes (coluna Cliente do Monday, reconhecida pelo título), tipo e busca. A mesma lista filtrada aparece em **Tabela**, **Kanban** ou **Lista agrupada**, agrupando por etapa, responsável, cliente, prioridade, tipo, épico ou sprint (a escolha fica salva no navegador). Por etapa, as colunas são as mesmas 4 dos painéis do Bombeiro e da Sprint (Backlog, Em andamento, Em teste e Concluído, pela mesma regra), com o status real em cada card; quem pode atualizar cards arrasta o card para outra etapa e escolhe qual status do Monday aplicar (`public/task-views.js`). Na **Tabela** e na **Lista agrupada**, quem pode atualizar cards marca vários itens (ou todos os filtrados) e aplica de uma vez o mesmo **Status**, **Prioridade** ou **Cliente**, com as opções do quadro de cada aba e até 200 itens por vez; o retorno mostra quantos foram alterados e mantém selecionados os que o Monday recusou (`public/bulk-edit.js`). Clicar no nome abre o detalhe do card no estilo ClickUp: todos os campos, descrição, subitens, anexos e comentários (imagens, inclusive as coladas no corpo dos comentários, aparecem em miniatura e abrem ampliadas; vídeos tocam no próprio modal; demais arquivos abrem em nova aba pelo link público temporário do Monday, válido por cerca de 1 hora); ao lado, novo comentário com anexos e o histórico de alterações lido do log da Monday. Usada pelo time para validações de QA. Tem o botão **Novo Ticket**. |
| Sustentação — Painel do Bombeiro | Demandas de Suporte por status e prioridade, com avisos visuais e toque Den Den Mushi para novas demandas. Colunas: Backlog, Em andamento, Em teste (status de QA: Testes, Reprovado e Aprovado) e Concluído. Tem o botão **Novo Ticket**. |
| Painel da Sprint | Acompanhamento do trabalho vinculado à sprint selecionada, nas mesmas quatro colunas do Painel do Bombeiro (Backlog, Em andamento, Em teste e Concluído). |
| Apresentação dos painéis | Exibição dos painéis em carrossel. |
| Capacidade e execução da sprint | Comparação de disponibilidade, planejamento e execução por desenvolvedor: horas previstas e registradas separadas em planejadas, não planejadas e total, saldo final (disponíveis − previstas totais) e saldo atual (disponíveis − registradas). |
| Previsibilidade da sprint | Comparação da demanda restante com a capacidade disponível. |
| Consumo de horas | Distribuição entre Sprint e Suporte, detalhamento individual e comparação com a reserva coletiva de suporte. |
| Release da sprint | Itens implementados agrupados por Tipo, com categorias expansíveis, links dos cards e exportação via impressão para PDF. |

### Endereços e uso no celular

Cada tela tem seu próprio endereço (`/torre-de-controle`, `/burndown`, `/sprints/18`, `/capacity?sprint=18`...), definido em `App\Screen::slug()`. Recarregar a página, usar o voltar do navegador ou compartilhar o link mantém a tela e a sprint escolhida; os links antigos com `#tela` continuam funcionando, e `/dados` redireciona para `/tarefas`. Todas as telas são servidas pela mesma página, e o acesso continua controlado pelas telas liberadas para cada usuário.

No celular (até 640px), `public/responsive.css` e `public/responsive.js` transformam as tabelas de lista em cartões (com o cabeçalho como botões de ordenação e sem os campos vazios), abrem os modais em tela cheia e compactam os cabeçalhos das telas. As tabelas comparativas de Capacity, Previsibilidade e Consumo mantêm rolagem lateral com a primeira coluna fixa.

### App Android

O app **Yuv Sprint Metrics** (pacote `br.com.yuv.metrics`, identificador técnico mantido para não quebrar as instalações existentes) é um pacote TWA gerado pelo PWABuilder: abre o próprio site em tela cheia, então sempre mostra a versão publicada e não precisa ser gerado de novo a cada deploy. O `public/.well-known/assetlinks.json` liga o app ao domínio (sem ele, o app abre com a barra do Chrome). O instalador fica em `public/downloads/yuv-sprint-metrics.apk` no servidor, fora do Git (`*.apk` é ignorado); quando o arquivo existe, o menu lateral mostra "Baixar app Android". Para publicar uma versão nova, gere o pacote com a **mesma chave de assinatura** (guardada fora do repositório) e substitua o arquivo no servidor.

## Integração com a Monday

A aplicação consulta a API GraphQL da Monday pelo backend, com paginação, mapeamento de colunas e cache. Na tela **Configurações**, informe o token, selecione o quadro e confira o mapeamento das colunas.

Os principais campos são Sprint, Tipo, Status, Responsável, Tempo Estimado e Tempo Real. Prioridade, épico, esforço restante e Melhorias (antigas Novas Funcionalidades e Valor Agregado; o mapeamento reconhece os três nomes de coluna) apoiam os painéis que os utilizam. As colunas Módulo e Descrição são localizadas pelo título e usadas na release quando existem no quadro; os comentários dos cards são consultados para a release apenas quando a Descrição está vazia.

### Alterações enviadas à Monday

O dashboard é de acompanhamento: classificações da sprint continuam sendo feitas na Monday. As únicas escritas, todas gravadas direto na Monday e liberadas por permissão no cadastro de usuários (ninguém começa com elas), são:

- **Atualizar cards** (tela Tarefas): trocar o status pelas opções da coluna Status do quadro e comentar com anexos (evidências de QA). Prints colados ou arrastados viram anexos; imagens acima de 1,5 MB são reduzidas no navegador e cada anexo é enviado numa requisição própria (`public/attachments.js`).
- **Abrir tickets** (botão Novo Ticket nas telas Tarefas e Painel do Bombeiro, e o botão flutuante de chat no canto inferior direito de todas as telas, para quem tem a permissão de abrir tickets): o mesmo conteúdo abre como modal ou como chat; minimizar o chat mantém o que foi preenchido, e o botão some no modo apresentação. O fluxo começa pela escolha do tipo de demanda, com critérios e exemplos de quando usar cada um (`KINDS` em `public/new-ticket.js`). **Melhoria / Feature** abre a demanda no Funil de Demandas (quadro do Roadmap, configurado em Configurações) com os mesmos campos do formulário de demandas do Monday. Obrigatórios: Título, Nome solicitante, Origem, Tipo, Módulo, Descrição da demanda, Quem é afetado?, Motivação da demanda para o negócio e Severidade (coluna Impacto). Opcionais: Solução proposta, Evidências (arquivos enviados para a coluna Evidências), Requisitos e Possível MRR. As opções vêm das colunas do quadro, reconhecidas pelo título; o Tipo Bug fica de fora, porque é Sustentação. A demanda entra com status Aguardando revisão e a data de hoje em Data do registro (`App\Services\FeatureRequestService`). **Sustentação** (suporte/bombeiro) abre o formulário abaixo. No formulário de sustentação, Título, Cliente, Descrição e Prioridade são obrigatórios; a descrição vem com o modelo de abertura de chamado do time (`DESCRIPTION_TEMPLATE` em `public/new-ticket.js`) e aceita prints colados. O card é criado com Tipo Suporte, Status Backlog e na sprint atual (a que contém o dia de hoje; entre sprints, a próxima a começar). As regras ficam em `App\Services\SupportTicketService`. Assim que o Monday devolve o card, o ticket é anunciado no canal do Bombeiro no Teams pelo `yuv-internal-notifier`, mencionando o grupo Bombeiro, com cliente, prioridade, status, sprint, quem abriu e o botão para o card (`App\Services\BombeiroNotifier`). O aviso sai depois da resposta e uma falha dele fica só no log. Cada card é um único cartão no canal (chave `monday.card.<id>`). As mudanças de status dos cards de Suporte, inclusive as feitas direto no Monday, chegam pelo log de atividades copiado no `monday:refresh` (a cada 15 minutos): o cartão do card é atualizado com o novo status e a cor da etapa do Painel do Bombeiro (Backlog laranja, Em andamento e Em teste azul, Concluído verde) e ganha uma resposta "Status: anterior → novo · por quem mudou". O Teams só notifica o grupo no cartão novo; edições e respostas aparecem no canal sem notificar. Mudanças com mais de 2 horas (ex.: aviso desligado por um tempo) não são avisadas, e com o notificador fora do ar elas esperam a próxima rodada (`monday_activity_events.notified_at`). Precisa de `NOTIFIER_URL` (só alcançável dentro do cluster ou pela VPN) e `NOTIFIER_DESTINATION` (`bombeiro`; `alertas-testes` para testar); sem elas, não avisa.

Como a Monday registra o dono do token como autor, os comentários e o comentário inicial dos tickets terminam com "Enviado por {usuário} pelo Yuv Sprint Metrics", e cada ação é registrada no log do Laravel. O token precisa ter permissão de escrita no quadro. O tamanho de cada anexo também é limitado pelo PHP (`upload_max_filesize` e `post_max_size`): para aceitar vídeos, use ao menos `upload_max_filesize = 50M` e `post_max_size = 55M`.

### Regras dos indicadores

- **Definições únicas (todas as telas):** concluído é somente `Implementado`; `Aguardando Deploy` e `QA - Aprovado` são feitos, não entregues; `Cancelado`, `Descontinuado` e `Despriorizado` saem do total da sprint e contam como retirados do escopo. Capacidade padrão e pessoas fora das métricas individuais são configuradas em **Configurações**.
- **Histórico:** o log de atividades da Monday é copiado para a tabela `monday_activity_events`, porque a Monday guarda apenas alguns meses. Com ele, sprints encerradas aparecem como estavam no fim efetivo (véspera do início da sprint seguinte) e a Torre de controle reconstitui cada dia da sprint. Como a cópia do log pode atrasar em relação à última sincronização, do horário da sincronização em diante vale o estado atual do card na Monday, e só mudanças do log posteriores a ela são aplicadas.
- **Indicadores da sprint:** os cards se dividem em três origens que somam o total de itens: Suporte (Tipo = Suporte), Sprint Planejados (campo Planejada = Planejada, sem suporte) e Sprint Não Planejados (os demais, sem suporte). A descrição e o critério de cada indicador ficam em `SPRINT_KPI_INFO`, em `resources/views/dashboard.blade.php`. Desenvolvimento Concluído soma Implementado, Aguardando Deploy, QA - Testes e QA - Aprovado (QA - Reprovado volta ao desenvolvimento). Em Teste conta os cards com status de QA (QA - Testes, QA - Reprovado e QA - Aprovado), a mesma coluna "Em teste" dos painéis. Índice de Escopo Limpo = planejados ÷ total de itens, ambos sem suporte e independentemente do status. Os gráficos de composição mostram % nas fatias, total no centro e quantidade/% na legenda.
- **Listas dos indicadores:** o modal aberto ao clicar num indicador (tela Sprint e Torre de controle) é uma tabela com busca, filtro por coluna e ordenação pelo cabeçalho (`public/kpi-table.js`).
- **Torre de controle:** no painel de status, Tasks implementadas e Não iniciados (Backlog, Pronto para começar ou sem status) são mostrados sobre o total da sprint; Em andamento (já saiu do Backlog e ainda não foi implementado, incluindo Aguardando Deploy, QA - Aprovado e impedidos) é mostrado sobre o que falta implementar. Linha de base do escopo no fim do 2º dia útil; suporte que entra no meio da sprint é medido pela reserva, não como escopo adicionado; card parado = 3 dias úteis no mesmo status (se o Monday já mostra um status diferente do último registrado no log copiado, a contagem recomeça no horário da sincronização). A trajetória mostra, por origem, entregues, em andamento, em homologação (QA ainda não aprovado), não iniciados e pendentes. O compromisso da sprint são os cards marcados como Planejada no Monday; a torre é somente de acompanhamento e não guarda marcações próprias. Os limites ficam em `App\Services\ControlTowerService`.
- **Consumo de horas:** Tipo = Suporte entra em Suporte; qualquer outro Tipo entra em Sprint. Considera as horas reais independentemente do status e da classificação Planejado / Não planejado.
- **Reserva de suporte:** o padrão é 40 horas para o time inteiro por sprint, configurável e persistido por sprint. Essa comparação não altera a capacidade dos demais painéis.
- **Atribuição individual:** horas compartilhadas sem distribuição confiável permanecem nos totais do time e aparecem como horas sem atribuição individual.
- **Release:** inclui os itens da sprint com Status = Implementado, uma vez por ID, agrupados pela coluna Tipo. Não exige seleção ou edição manual.
- **Descrições da release:** nesta ordem, a coluna Descrição do card no Monday (reconhecida pelo título, sem configuração; o texto aparece como foi escrito, com as quebras de linha, e os comentários desses cards nem são consultados); o OBJETIVO da ficha de especificação do card; um resumo gravado na tabela `release_summaries`; ou a extração conservadora dos comentários. Sem informação confiável, aparece somente o título. Os resumos são escritos fora do dashboard (no Claude Code, sem chave de API): `php artisan release:summaries-export {sprint}` gera o arquivo com os cards pendentes e `php artisan release:summaries-import {arquivo}` grava os resumos. Se os comentários do card mudarem, o resumo aparece como desatualizado até ser refeito.

A qualidade dos indicadores depende do preenchimento dos cards. Tempo Real acumulado pode incluir esforço de outras sprints quando a fonte não permite separá-lo. Os painéis apresentam avisos de qualidade e atribuição para ajudar na conferência.

## Tecnologia

Laravel 13, PHP (ambiente de desenvolvimento em PHP 8.5), MySQL, Blade, JavaScript e CSS. A aplicação usa autenticação por sessão, proteção CSRF e PHPUnit. Os assets dos painéis ficam em `public/`.

## Executar localmente

Tenha PHP compatível com `composer.lock`, Composer e MySQL disponíveis. Para compilar os assets do pipeline Vite, utilize Node.js e npm compatíveis com `package.json`.

1. Clone o repositório e instale as dependências:

   ```bash
   git clone git@github.com:jimibrasil/yuv-sprint-metrics.git
   cd yuv-sprint-metrics
   composer install
   ```

2. Copie `.env.example` para `.env`. Crie o banco MySQL e configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD`.

3. Em uma instalação nova, gere a chave e execute as migrations:

   ```bash
   php artisan key:generate
   php artisan migrate --no-interaction
   ```

4. Crie o usuário inicial. O comando solicita a senha sem exibi-la:

   ```bash
   php artisan app:create-admin seu-email@empresa.com "Seu Nome"
   ```

5. Inicie o servidor de desenvolvimento:

   ```bash
   php artisan serve
   ```

6. Acesse o endereço informado pelo servidor, faça login e configure a integração com a Monday na tela **Configurações**.

7. Opcional: para manter dados e histórico atualizados sem o dashboard aberto, deixe o agendador rodando (`php artisan schedule:work`). Ele executa `php artisan monday:refresh` a cada 15 minutos. Sem agendador, o próprio uso do dashboard copia o histórico, no máximo uma vez por hora.

Para compilar os assets Vite, execute `npm install` e `npm run build`. As orientações do ambiente Windows original estão em [MIGRACAO.md](MIGRACAO.md), que registra a etapa inicial da migração com SQLite.

## Credenciais e configuração

O token da Monday fica na coluna `token` da tabela `monday_connections`, criptografado pelo Laravel com a `APP_KEY`. A API de configuração informa apenas se existe um token cadastrado.

Ao migrar uma instalação existente, preserve o banco e a mesma `APP_KEY` para manter a leitura do token. O comando `key:generate` das instruções acima se aplica a instalações novas.

O `.env` contém a configuração do ambiente e não deve ser versionado. No servidor web, a raiz pública deve apontar para `public/`.

Atualmente, a configuração do quadro é compartilhada entre os usuários. O projeto não implementa organizações independentes, gestão de papéis ou OAuth da Monday.

## Produção

A imagem é construída a partir do `Dockerfile`, sobre `serversideup/php` com nginx e PHP-FPM, e serve `public/` na porta `8080`. O workflow `pipeline.yaml` roda os testes a cada push na `main`, publica a imagem com a tag do commit e cria a versão; o `pr.yaml` roda os testes e constrói a imagem em todo PR.

Para rodar em produção, a aplicação precisa de:

- **Processos:** a mesma imagem em três papéis. O web, com o comando padrão da imagem. O agendador, com `php artisan schedule:work`, sempre um só, porque ele dispara o `monday:refresh` a cada 15 minutos. E as migrations, com `php artisan migrate --force`, antes de o web subir em cada versão nova.
- **Réplicas:** uma. A aplicação é de uso interno e o agendador não pode rodar duplicado.
- **Estado:** todo no MySQL 8.4: usuários, sessões, cache, configuração da Monday e o histórico de atividades copiado. O histórico não se recupera da Monday depois de alguns meses, então o banco precisa de volume persistente e backup. O disco do container não guarda nada que precise sobreviver a um restart.
- **Segredos:** `APP_KEY` e `DB_PASSWORD`. A `APP_KEY` precisa ser a mesma do banco que se restaura: é com ela que o token da Monday foi cifrado.
- **Configuração:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com o endereço público em `https`, `LOG_CHANNEL=stderr`, `SESSION_SECURE_COOKIE=true` e as variáveis `DB_*`.
- **Rede:** o TLS termina no proxy de entrada. A aplicação confia no `X-Forwarded-Proto` de qualquer origem, por isso o container não pode ser alcançado sem passar pelo proxy. A saída precisa alcançar `api.monday.com`.
- **Saúde:** `GET /up` responde 200 quando a aplicação sobe.

As páginas referenciam `.js` e `.css` com `URL::versioned('arquivo.js')` (macro em `AppServiceProvider`), que acrescenta `?v=` com a data de modificação: a cada versão, arquivos alterados ganham endereço novo e o navegador não fica com a versão antiga. Novos `.js`/`.css` devem ser incluídos da mesma forma.

## Verificação

```bash
php artisan test --compact
```

As páginas `tests/forecast.tests.html`, `tests/consumption.tests.html`, `tests/release.tests.html` e `tests/bombeiro-audio.tests.html` verificam interações com dados controlados. Sirva a raiz do repositório em um servidor local de desenvolvimento para abri-las no navegador; elas não devem ser expostas em produção.
