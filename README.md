<p align="center"><img src="public/yuv-logo.png" width="180" alt="YUV"></p>

# YUV Dashboard

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
| Torre de controle | Tela inicial. Farol da sprint, trajetória dia a dia (burnup), previsão probabilística, ponto de decisão, manobras sugeridas, alertas e compromisso da sprint. |
| Dashboard e sprints | Visão dos indicadores e detalhamento por sprint. Nos indicadores da sprint, passar o mouse sobre um card mostra o que ele apresenta e o critério de cálculo; clicar abre a lista das tasks daquele indicador, com link para o card na Monday. |
| Épicos, time e dados | Consulta das informações do quadro por diferentes perspectivas. |
| Sustentação — Painel do Bombeiro | Demandas de Suporte por status e prioridade, com avisos visuais e toque Den Den Mushi para novas demandas. |
| Painel da Sprint | Acompanhamento do trabalho vinculado à sprint selecionada. |
| Apresentação dos painéis | Exibição dos painéis em carrossel. |
| Capacidade e execução da sprint | Comparação de disponibilidade, planejamento e execução por desenvolvedor. |
| Previsibilidade da sprint | Comparação da demanda restante com a capacidade disponível. |
| Consumo de horas | Distribuição entre Sprint e Suporte, detalhamento individual e comparação com a reserva coletiva de suporte. |
| Release da sprint | Itens implementados agrupados por Tipo, com categorias expansíveis, links dos cards e exportação via impressão para PDF. |

## Integração com a Monday

A aplicação consulta a API GraphQL da Monday pelo backend, com paginação, mapeamento de colunas e cache. Na tela **Configurações**, informe o token, selecione o quadro e confira o mapeamento das colunas.

Os principais campos são Sprint, Tipo, Status, Responsável, Tempo Estimado e Tempo Real. Prioridade, épico, esforço restante e Novas Funcionalidades (antiga Valor Agregado; o mapeamento reconhece os dois nomes de coluna) apoiam os painéis que os utilizam. Os comentários dos cards são consultados para a release; o módulo é exibido quando existe nos dados.

### Regras dos indicadores

- **Definições únicas (todas as telas):** concluído é somente `Implementado`; `Aguardando Deploy` e `QA - Aprovado` são feitos, não entregues; `Cancelado`, `Descontinuado` e `Despriorizado` saem do total da sprint e contam como retirados do escopo. Capacidade padrão e pessoas fora das métricas individuais são configuradas em **Configurações**.
- **Histórico:** o log de atividades da Monday é copiado para a tabela `monday_activity_events`, porque a Monday guarda apenas alguns meses. Com ele, sprints encerradas aparecem como estavam no fim efetivo (véspera do início da sprint seguinte) e a Torre de controle reconstitui cada dia da sprint. Como a cópia do log pode atrasar em relação à última sincronização, do horário da sincronização em diante vale o estado atual do card na Monday, e só mudanças do log posteriores a ela são aplicadas.
- **Indicadores da sprint:** os cards se dividem em três origens que somam o total de itens: Suporte (Tipo = Suporte), Sprint Planejados (campo Planejada = Planejada, sem suporte) e Sprint Não Planejados (os demais, sem suporte). A descrição e o critério de cada indicador ficam em `SPRINT_KPI_INFO`, em `resources/views/dashboard.blade.php`.
- **Torre de controle:** no painel de status, Tasks implementadas e Não iniciados (Backlog, Pronto para começar ou sem status) são mostrados sobre o total da sprint; Em andamento (já saiu do Backlog e ainda não foi implementado, incluindo Aguardando Deploy, QA - Aprovado e impedidos) é mostrado sobre o que falta implementar. Linha de base do escopo no fim do 2º dia útil; suporte que entra no meio da sprint é medido pela reserva, não como escopo adicionado; card parado = 3 dias úteis no mesmo status. O compromisso da sprint são os cards marcados como Planejada no Monday; a torre é somente de acompanhamento e não guarda marcações próprias. Os limites ficam em `App\Services\ControlTowerService`.
- **Consumo de horas:** Tipo = Suporte entra em Suporte; qualquer outro Tipo entra em Sprint. Considera as horas reais independentemente do status e da classificação Planejado / Não planejado.
- **Reserva de suporte:** o padrão é 40 horas para o time inteiro por sprint, configurável e persistido por sprint. Essa comparação não altera a capacidade dos demais painéis.
- **Atribuição individual:** horas compartilhadas sem distribuição confiável permanecem nos totais do time e aparecem como horas sem atribuição individual.
- **Release:** inclui os itens da sprint com Status = Implementado, uma vez por ID, agrupados pela coluna Tipo. Não exige seleção ou edição manual.
- **Descrições da release:** nesta ordem, o OBJETIVO da ficha de especificação do card; um resumo gravado na tabela `release_summaries`; ou a extração conservadora dos comentários. Sem informação confiável, aparece somente o título. Os resumos são escritos fora do dashboard (no Claude Code, sem chave de API): `php artisan release:summaries-export {sprint}` gera o arquivo com os cards pendentes e `php artisan release:summaries-import {arquivo}` grava os resumos. Se os comentários do card mudarem, o resumo aparece como desatualizado até ser refeito.

A qualidade dos indicadores depende do preenchimento dos cards. Tempo Real acumulado pode incluir esforço de outras sprints quando a fonte não permite separá-lo. Os painéis apresentam avisos de qualidade e atribuição para ajudar na conferência.

## Tecnologia

Laravel 13, PHP (ambiente de desenvolvimento em PHP 8.5), MySQL, Blade, JavaScript e CSS. A aplicação usa autenticação por sessão, proteção CSRF e PHPUnit. Os assets dos painéis ficam em `public/`.

## Executar localmente

Tenha PHP compatível com `composer.lock`, Composer e MySQL disponíveis. Para compilar os assets do pipeline Vite, utilize Node.js e npm compatíveis com `package.json`.

1. Clone o repositório e instale as dependências:

   ```bash
   git clone https://github.com/gushep/Yuv-Dashboard.git
   cd Yuv-Dashboard
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

## Verificação

```bash
php artisan test --compact
```

As páginas `tests/forecast.tests.html`, `tests/consumption.tests.html`, `tests/release.tests.html` e `tests/bombeiro-audio.tests.html` verificam interações com dados controlados. Sirva a raiz do repositório em um servidor local de desenvolvimento para abri-las no navegador; elas não devem ser expostas em produção.
