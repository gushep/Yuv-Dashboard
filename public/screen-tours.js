/*
 * Tours guiados de cada tela, abertos pelo botão "Tour" no canto direito da barra superior. Cada tour explica a tela
 * aberta, de cima para baixo, com o mesmo motor do "Tour das novidades" (public/tour.js). Passo sem `element` aparece
 * centralizado; passo de uma parte que não está à vista (ex.: seção que só aparece com dados) fica de fora.
 */
window.ScreenTours = (() => {
  const maestro = () => window.CAN_MANAGE_CAPACITY ? 'Você é <strong>Maestro Scrum</strong> e pode alterar.' : 'Só o <strong>Maestro Scrum</strong> altera; para você fica só para leitura.';
  /** Barra superior e botão do tour: fecham todos os tours. */
  const common = () => [
    {element: '#tb-hours', title: 'Horas da sprint na barra superior', description: 'Resumo das horas da sprint em andamento: para Capacidade, Full stack, Bombeiro e Projetos, o <strong>Planejado</strong> e o <strong>Realizado</strong> contra a capacidade, com a coluna <strong>Disponível</strong> e a cor do semáforo. Clique para abrir o Capacity.'},
    {element: '#tb-tour', title: 'Tour desta tela', description: 'Este botão abre o tour da tela em que você está. As novidades da versão ficam no menu lateral, em <strong>Tour das novidades</strong>.'}
  ];

  /** Título e passos de cada tela (valor usado em navigate()). */
  const TOURS = {
    'control-tower': {title: 'Torre de controle', steps: () => [
      {title: 'Torre de controle', description: 'Para onde a sprint está indo e quanto tempo resta para manobrar. Passe o mouse nos números e nos sinais para ver o cálculo; clique para ver as tasks de cada indicador.'},
      {element: '#tower-sprint', title: 'Sprint analisada', description: 'Escolha a sprint; ao lado ficam as datas. O botão <strong>Atualizar dados</strong> consulta o Monday de novo.'},
      {element: '#tower-status', title: 'Farol da sprint', description: 'Cor geral da sprint (a pior entre os sinais), dias úteis restantes, compromisso concluído, tasks implementadas, não iniciadas e em andamento, e a frase do <strong>ponto de decisão</strong>: até quando dá para decidir cortes.'},
      {element: '#tower-signals', title: 'Sinais', description: 'Cada sinal tem verde, amarelo ou vermelho. A capacidade restante compara o esforço que falta com a capacidade <strong>Full stack menos o Bombeiro</strong>.'},
      {element: '#tower-hours', title: 'Horas da sprint', description: 'Os mesmos cards do Capacity: <strong>Previsto</strong> (planejado) e <strong>Registrado</strong> (utilizado) contra a capacidade, com o disponível e o semáforo.'},
      {element: '#page-control-tower .tower-burnup', title: 'Trajetória da sprint', description: 'Burnup do escopo e dos concluídos dia a dia, reconstituído pelo histórico do Monday, com a projeção tracejada até o fim. Filtre por origem: Planejado, Não planejado ou Suporte.'},
      {element: '#tower-maneuvers', title: 'Manobras sugeridas', description: 'Cards que podem sair ou ser priorizados para a sprint voltar para a rota.'},
      {element: '#tower-alerts', title: 'Alertas', description: 'Cards parados, impedimentos longos, escopo adicionado e reserva de suporte acabando antes do fim da sprint.'},
      {element: '#page-control-tower .tower-commitment', title: 'Compromisso da sprint', description: 'Os cards marcados como "Planejada" no Monday, só para leitura, com busca e filtros por situação, responsável, prioridade e origem.'}
    ]},
    burndown: {title: 'Burndown', steps: () => [
      {title: 'Burndown', description: 'Quanto falta implementar na sprint, dia a dia, contra a linha ideal.'},
      {element: '#burndown-sprint', title: 'Sprint', description: 'Escolha a sprint; ao lado ficam as datas.'},
      {element: '#page-burndown .burndown-units', title: 'Cards ou horas', description: 'Os gráficos contam cards ou horas estimadas.'},
      {element: '#burndown-panels', title: 'Três painéis', description: '<strong>Geral</strong>, <strong>Suporte</strong> e <strong>Planejado e não planejado</strong> (Suporte + este = Geral). Cada um traz restante (com o ideal de hoje), concluído, escopo, ritmo médio, ritmo necessário e o gráfico contra a linha ideal.'},
      {element: '#page-burndown .burndown-sla', title: 'SLA do suporte', description: 'Tempo corrido da abertura até Implementado nos cards de Suporte: média, mediana, maior tempo e a lista. Cards ainda abertos ficam fora da média.'},
      {element: '#page-burndown .burndown-method', title: 'Como é calculado', description: 'Só "Implementado" conta como concluído; Aguardando Deploy e QA Aprovado ainda são restantes; cancelados, descontinuados e despriorizados saem; só dias úteis.'}
    ]},
    dashboard: {title: 'Dashboard', steps: () => [
      {title: 'Dashboard', description: 'Visão histórica das sprints: indicadores gerais e gráficos de entrega e qualidade.'},
      {element: '#dash-kpis', title: 'Indicadores gerais', description: 'Conclusão, retrabalho, escopo limpo, arrastamento, cobertura de estimativa, bugs e outros, em verde ou vermelho conforme a meta.'},
      {element: '#cConclusion', title: 'Taxa de conclusão', description: '% de itens implementados em cada sprint, com a linha da meta de 90%.'},
      {element: '#cVolume', title: 'Volume por sprint', description: 'Planejados, não planejados e suporte, e quantos itens vieram de sprints anteriores.'},
      {element: '#volume-hours-kind', title: 'Volume em horas', description: 'Alterna o gráfico de horas entre reais e estimadas.'},
      {element: '#cRework', title: 'Retrabalho', description: 'Retrabalho por sprint, com meta abaixo de 10%.'},
      {element: '#cClean', title: 'Escopo limpo', description: 'Planejados ÷ total, sem suporte; meta acima de 60%.'},
      {element: '#cQuality', title: 'Qualidade', description: 'Itens limpos, com retrabalho, com bug ou com os dois.'}
    ]},
    sprint: {title: 'Sprints', steps: () => [
      {title: 'Sprints', description: 'Análise detalhada de uma sprint, comparada com a anterior.'},
      {element: '#sprintSel', title: 'Sprint', description: 'Escolha a sprint analisada.'},
      {element: '#sprint-kpis', title: 'Indicadores da sprint', description: 'Conclusão, desenvolvimento concluído, feitos não entregues, em teste, não planejados, suporte e outros, com a tendência contra a sprint anterior.'},
      {element: '#sStatus', title: 'Itens por status', description: 'Como os itens da sprint estão distribuídos entre os status.'},
      {element: '#sPlanned', title: 'Planejado × não planejado', description: 'O que era compromisso e o que entrou durante a sprint.'},
      {element: '#sDev', title: 'Itens por pessoa', description: 'Total de itens de cada pessoa e quantos foram implementados.'},
      {element: '#sprint-table-wrap', title: 'Itens da sprint', description: 'Tabela com os itens da sprint, filtros rápidos (Melhorias, Épico, Bug prod.) e busca.'}
    ]},
    epicos: {title: 'Épicos', steps: () => [
      {title: 'Épicos', description: 'Investimento e histórico por épico.'},
      {element: '#epicos-cards', title: 'Épicos', description: 'Um card por épico, com o progresso.'},
      {element: '#eHours', title: 'Horas por épico', description: 'Horas reais acumuladas em cada épico.'},
      {element: '#ePerSprint', title: 'Épicos por sprint', description: 'Quanto do volume de cada sprint foi para cada épico.'},
      {element: '#epicos-table-wrap', title: 'Itens de épico', description: 'Os itens ligados a épicos, com busca.'}
    ]},
    time: {title: 'Time', steps: () => [
      {title: 'Time', description: 'Análise por desenvolvedor: a visão do time inteiro ou de uma pessoa.'},
      {element: '#timeDevSel', title: 'Pessoa', description: 'Escolha uma pessoa para trocar a visão do time pela visão individual.'},
      {element: '#timeSpSel', title: 'Sprint', description: 'Filtra a sprint analisada.'},
      {element: '#time-overview', title: 'Comparativo do time', description: 'Total × implementados, retrabalho, cobertura de estimativa, perfil por tipo e horas por sprint de cada pessoa.'},
      {element: '#dev-kpis', title: 'Indicadores da pessoa', description: 'Ocupação (horas reais ÷ capacidade, verde entre 70% e 110%), gap de horas e os demais indicadores individuais.'},
      {element: '#dev-table-wrap', title: 'Itens da pessoa', description: 'Os itens da pessoa no período escolhido.'}
    ]},
    raw: {title: 'Tarefas', steps: () => [
      {title: 'Tarefas', description: 'Os cards do quadro Tarefas, com detalhes, status e evidências.'},
      {element: '#raw-tabs', title: 'Abas', description: 'Visão 360°, Roadmap, Backlog, Sprint atual e Suporte, com a quantidade de cada uma.'},
      {element: '#raw-filters', title: 'Filtros rápidos', description: 'Bug prod., Melhorias, Retrabalho e Épico.'},
      {element: '#raw-views', title: 'Formato', description: 'Tabela, Kanban ou lista agrupada.'},
      {element: '#rawSpSel', title: 'Filtros', description: 'Sprint, pessoa, status, cliente, tipo e busca pelo nome do card.'},
      {element: '#raw-list-card .ticket-btn', title: 'Novo Ticket', description: 'Abre um ticket direto no Monday (para quem tem a permissão).'},
      {element: '#raw-table-view', title: 'Lista de tarefas', description: 'Clique num card para ver o detalhe, mudar o status, comentar ou anexar evidências (para quem tem a permissão).'}
    ]},
    planning: {title: 'Sprint Planning', steps: () => [
      {title: 'Sprint Planning', description: 'O planning poker da sprint: o time estima em horas os cards do Backlog, e a estimativa escolhida vai para o card no Monday.'},
      {element: '#pp-hero-actions', title: 'Ações', description: 'Planejar nova sprint (Maestro Scrum), entrar na mesa, voltar às últimas sprints ou encerrar a planning.'},
      {element: '#pp-open-banner', title: 'Planning em andamento', description: 'Quando há uma mesa aberta, este aviso leva direto para ela.'},
      {element: '#pp-history', title: 'Últimas sprints', description: 'Horas planejadas, realizadas e disponíveis de cada time. A sprint em andamento tem <strong>Entrar na mesa</strong>; o <strong>olhinho</strong> abre a mesa de qualquer sprint só para leitura.'},
      {element: '#pp-seats', title: 'A mesa', description: 'Cada pessoa sentada joga a carta virada para baixo; na revelação aparece a média do time ao lado da estimativa da IA.'},
      {element: '#pp-center', title: 'Card na mesa', description: 'O card em estimativa; clique para ler o escopo publicado no card.'},
      {element: '#pp-controls', title: 'Sua carta e as ações da rodada', description: 'Jogue as horas (ou vote vazio, sem contexto). O Maestro abre a estimativa, revela as cartas, escolhe entre a IA e a média do time ou interrompe a rodada.'},
      {element: '#pp-load', title: 'Carga do time', description: 'Full stack (capacidade sem o Bombeiro, sem os cards de Suporte) e Projetos, com capacidade, planejado e disponível em horas e %, e cada pessoa.'},
      {element: '#pp-hand-section', title: 'Sua mão', description: 'Os cards do Backlog para jogar na mesa. Com uma carta na mesa, jogar outra interrompe a estimativa atual.'},
      {element: '#pp-estimated', title: 'Estimados nesta planning', description: 'O que já foi decidido. <strong>Reestimar</strong> devolve o card à mesa; ao encerrar, os estimados são ligados à sprint no Monday.'}
    ]},
    bombeiro: {title: 'Painel do Bombeiro', steps: () => [
      {title: 'Painel do Bombeiro', description: 'As demandas de Suporte da sprint, para acompanhar na TV. Atualiza sozinho a cada 60 segundos.'},
      {element: '#fire-context', title: 'O que está sendo monitorado', description: 'O quadro e a sprint acompanhados; só demandas de Suporte.'},
      {element: '#fire-sprint', title: 'Sprint', description: 'Filtra a sprint.'},
      {element: '#fire-refresh', title: 'Atualizar agora', description: 'Consulta o Monday na hora; ao lado fica a última consulta.'},
      {element: '#fire-audio-toggle', title: 'Alerta sonoro', description: 'Liga ou silencia o toque de demanda nova. <strong>Testar som</strong> toca o alerta por alguns segundos.'},
      {element: '#fire-board', title: 'Kanban do suporte', description: 'Backlog, Em andamento, Em teste e Concluído, com a quantidade em cada coluna.'}
    ]},
    'sprint-panel': {title: 'Painel da Sprint', steps: () => [
      {title: 'Painel da Sprint', description: 'As demandas da sprint que não são de suporte, para acompanhar na TV. Atualiza sozinho a cada 60 segundos.'},
      {element: '#sp-context', title: 'O que está sendo monitorado', description: 'O quadro e a sprint acompanhados.'},
      {element: '#sp-sprint', title: 'Sprint', description: 'Filtra a sprint.'},
      {element: '#sp-refresh', title: 'Atualizar agora', description: 'Consulta o Monday na hora; ao lado fica a última consulta.'},
      {element: '#sp-board', title: 'Kanban da sprint', description: 'Backlog, Em andamento, Em teste e Concluído.'},
      {element: '#page-sprint-panel .sprint-panel-note', title: 'Regras das colunas', description: 'O Backlog inclui "Pronto pra iniciar" e "Pronto para começar"; "Implementado" conta como concluído.'}
    ]},
    carousel: {title: 'Apresentação dos Painéis', steps: () => [
      {title: 'Apresentação dos Painéis', description: 'Alterna sozinha entre o Painel do Bombeiro e o Painel da Sprint, em tela cheia, para a TV. Pause antes de seguir o tour, para o painel não trocar no meio.'},
      {element: '#carousel-current', title: 'Painel da vez', description: 'Qual painel está na tela agora.'},
      {element: '#carousel-pause', title: 'Pausar', description: 'Pausa ou retoma a troca automática. As setas ao lado vão para o painel anterior ou o próximo.'},
      {element: '#carousel-interval', title: 'Intervalo', description: 'De quanto em quanto tempo o painel troca; fica guardado neste navegador.'},
      {element: '#carousel-fullscreen', title: 'Tela cheia', description: 'Abre a apresentação em tela cheia.'},
      {element: '#carousel-exit', title: 'Sair', description: 'Sai da apresentação e volta ao dashboard.'}
    ]},
    capacity: {title: 'Capacity', steps: () => [
      {title: 'Capacity', description: 'Capacidade e execução da sprint, por time e por pessoa.'},
      {element: '#cap-sprint', title: 'Sprint', description: 'Escolha a sprint; ao lado ficam as datas.'},
      {element: '#cap-developer', title: 'Filtro', description: 'Uma pessoa ou um time (Full stack ou Projetos).'},
      {element: '#cap-teams', title: 'Previsto e registrado', description: 'Capacidade da sprint, Full stack (já sem o Bombeiro), Bombeiro e Projetos: o <strong>previsto</strong> (tempo estimado) e o <strong>registrado</strong> (tempo real) contra a capacidade, com o disponível e o semáforo (verde, amarelo a partir de 90%, vermelho a partir de 100%).'},
      {element: '#cap-pace', title: 'Ritmo do time', description: 'Quanto já foi registrado e, mantido o ritmo, quanto vai ser ao fim da sprint.'},
      {element: '#page-capacity .cap-table-scroll', title: 'Por pessoa', description: `Horas disponíveis (herdadas da última sprint salva), previstas e registradas, saldos e indicadores. ${maestro()}`},
      {element: '#page-capacity .cap-formulas', title: 'Fórmulas', description: 'Como cada saldo e indicador da tabela é calculado.'}
    ]},
    forecast: {title: 'Previsibilidade', steps: () => [
      {title: 'Previsibilidade', description: 'Demanda que falta na sprint contra a capacidade futura de cada pessoa.'},
      {element: '#forecast-sprint', title: 'Sprint', description: 'Escolha a sprint.'},
      {element: '#forecast-developer', title: 'Filtros', description: 'Uma pessoa ou um time e a data de referência (corte) da previsão.'},
      {element: '#forecast-refresh', title: 'Atualizar', description: 'Consulta os dados de novo.'},
      {element: '#forecast-teams', title: 'Por time', description: 'Time completo, Full stack (já sem o Bombeiro) e Projetos.'},
      {element: '#page-forecast .cap-table-scroll', title: 'Por pessoa', description: 'Tarefas abertas e horas para concluir (em andamento: estimativa − real; não iniciadas: estimativa toda), capacidade depois do corte e a folga ou o excesso.'},
      {element: '#forecast-legend', title: 'Legenda', description: 'As faixas de ocupação da capacidade futura.'}
    ]},
    consumption: {title: 'Consumo de horas', steps: () => [
      {title: 'Consumo de horas', description: 'Onde o esforço do time foi gasto: Sprint ou Suporte, por time e por pessoa.'},
      {element: '#cons-sprint', title: 'Sprint', description: 'Escolha a sprint. <strong>Atualizar dados</strong> consulta o Monday de novo.'},
      {element: '#cons-teams', title: 'Previsto e registrado', description: 'Os mesmos cards do Capacity: capacidade, Full stack, Bombeiro e Projetos contra a capacidade, com o disponível e o semáforo.'},
      {element: '#page-consumption .cons-composition', title: 'Composição do executado', description: 'Quanto foi Sprint e quanto foi Suporte (pela coluna Tipo), e quanto foi de Desenvolvimento, de Projetos e sem time.'},
      {element: '#cons-reserve', title: 'Reserva do Bombeiro', description: `Reserva de suporte da sprint (padrão 80h, descontada do Full stack): uso, saldo e ritmo. ${maestro()}`},
      {element: '#cons-individual', title: 'Por pessoa', description: 'Consumo Desenvolvimento e Consumo Projetos. Clique numa pessoa para ver as tasks, com busca, filtros e ordenação pelo cabeçalho.'},
      {element: '#page-consumption .cons-audit', title: 'Conferência', description: 'Horas sem atribuição individual e a qualidade dos dados.'}
    ]},
    release: {title: 'Release da sprint', steps: () => [
      {title: 'Release da sprint', description: 'As entregas implementadas da sprint, organizadas por Tipo, prontas para virar PDF.'},
      {element: '#release-sprint', title: 'Sprint', description: 'Escolha a sprint.'},
      {element: '#release-count', title: 'O que entra', description: 'Só os itens com Status = Implementado.'},
      {element: '#release-selection', title: 'Seleção', description: 'Marque ou desmarque itens; desmarcar só tira do PDF.'},
      {element: '#release-document', title: 'Documento', description: 'As entregas agrupadas por Tipo, com a descrição vinda do objetivo do card.'},
      {element: '#release-export', title: 'Exportar', description: 'Abre a impressão para salvar o PDF com os itens marcados.'}
    ]},
    settings: {title: 'Configurações', steps: () => [
      {title: 'Configurações', description: 'Integração com o Monday e regras das métricas.'},
      {element: '#monday-board', title: 'Quadro e token', description: 'O quadro de Tarefas e o token da API do Monday (guardado criptografado).'},
      {element: '#monday-refresh', title: 'Atualização automática', description: 'De quanto em quanto tempo os dados são buscados no Monday.'},
      {element: '#roadmap-board', title: 'Roadmap e Backlog', description: 'Quadros das abas Roadmap e Backlog da tela Tarefas; vazio desliga a aba.'},
      {element: '#monday-mapping-card', title: 'Colunas', description: 'Qual coluna do Monday é cada campo (Sprint é obrigatória).'},
      {element: '#metric-capacity', title: 'Capacidade padrão', description: 'Horas por pessoa por sprint para quem nunca teve as horas ajustadas no Capacity.'},
      {element: '#team-settings', title: 'Times', description: 'Classifique cada pessoa ativa do Monday em <strong>Full stack</strong>, <strong>Projetos (IA)</strong> ou sem time (fora das métricas por pessoa).'},
      {element: '#metric-save', title: 'Salvar', description: 'Grava as regras e os times; todas as telas passam a usar.'}
    ]},
    users: {title: 'Usuários', steps: () => [
      {title: 'Usuários', description: 'Cadastro de quem acessa o dashboard e do que cada pessoa pode ver e fazer.'},
      {element: '#users-form', title: 'Cadastro', description: 'Nome, e-mail e senha (mínimo de 8 caracteres).'},
      {element: '#users-screens', title: 'Telas', description: 'As telas que a pessoa vê no menu e pode abrir.'},
      {element: '#page-users .users-permissions', title: 'Permissões', description: 'Atualizar cards, abrir tickets, <strong>Maestro Scrum</strong> (conduz a planning, ajusta as horas do Capacity e a reserva do Consumo) e estimar na planning.'},
      {element: '#users-save', title: 'Salvar', description: 'Cadastra ou salva a pessoa.'},
      {element: '#users-body', title: 'Cadastrados', description: 'Quem já tem acesso, com as telas e as ações de editar.'}
    ]},
    'daily-scrum': {title: 'Daily Scrum', steps: () => [
      {title: 'Daily Scrum', description: 'A Daily pessoa por pessoa, rápida e objetiva: o que está impedido, em andamento e o que vem, com as horas de cada um e as responsabilidades que saem da Daily para destravar. Cards, status e horas vêm do Monday.'},
      {element: '#daily-sprint', title: 'Sprint e Daily', description: 'Escolha a sprint e a Daily. A de hoje recebe novas responsabilidades; as anteriores ficam para consulta.'},
      {element: '#daily-macro', title: 'A sprint em resumo', description: 'O objetivo da sprint, os cards por situação, as horas do time, a <strong>tendência</strong> (o que falta nos cards abertos contra a capacidade dos dias úteis restantes: 🟢 no prazo, 🟡 apertado, 🔴 risco de atraso) e o <strong>XP</strong> das entregas: 10 por entrega, +5 no alvo do estimado e +8 adiantada.'},
      {element: '#daily-faces', title: 'O time', description: 'Um rosto por pessoa, com o time dela; a marcação vermelha mostra quantos cards impedidos ela tem. Clique no rosto ou use ← e → para passar a vez.'},
      {element: '#daily-person', title: 'A pessoa da vez', description: 'Capacidade, planejado, consumido, saldo, cards que já estouraram o estimado, a tendência dela e o XP das entregas.'},
      {element: '#daily-board', title: 'Kanban da pessoa', description: 'Os cards em <strong>Impedido</strong>, <strong>Em andamento</strong>, <strong>Próximos</strong> e <strong>Finalizado</strong> (só Implementado), com prioridade, status real do Monday e horas reais × estimadas: ⚠️ perto do limite a partir de 80%, 🔥 estourado acima de 100%; nas entregas, ⚡ adiantada, 🎯 no alvo ou 🐢 acima. Nos impedidos aparece há quanto tempo estão parados. Só leitura; <strong>＋ responsabilidade</strong> já abre o registro ligado ao card.'},
      {element: '#daily-todo', title: 'Responsabilidades da Daily', description: 'O que sai da Daily para destravar, agrupado por responsável. O que ficou pendente continua aparecendo nas próximas Dailies, sem duplicar.'},
      {element: '#daily-actions', title: 'Feito e Reabrir', description: '<strong>Feito</strong> pede a resolução e registra a conclusão e o tempo de resolução. Com card vinculado, a resolução pode ir como comentário no card do Monday (permissão "Atualizar cards"); se o Monday falhar, dá para tentar de novo. <strong>Reabrir</strong> volta às pendências e guarda a conclusão anterior.'},
      {element: '#daily-retro', title: 'Ações das retrospectivas', description: 'As ações combinadas nas retrospectivas que ainda estão abertas, de qualquer sprint, com prazo e sinal de resultado. Aparecem em todas as Dailies até serem concluídas; só o responsável e o facilitador da retro concluem.'}
    ]},
    'sprint-review': {title: 'Sprint Review', steps: () => [
      {title: 'Sprint Review', description: 'A revisão do que a sprint entregou, com o time na sala. Começa no último dia da sprint (ou antes, se ela já foi fechada no Monday), e quem inicia, conduz e marca o aceite das entregas é o Maestro Scrum.'},
      {element: '#rv-kpis', title: 'Resultado das reviews', description: 'Reviews encerradas, % de aceite das entregas apresentadas, entregas com ajustes, feedbacks (e quantos viraram demanda no Funil de Demandas) e em quantas sprints o objetivo foi atingido.'},
      {element: '#rv-history', title: 'Últimas sprints', description: 'Por sprint: quem esteve e quanto durou, o objetivo, as entregas por aceite e os feedbacks. <strong>Iniciar review</strong> abre a sala, <strong>Entrar na review</strong> leva para a que está em andamento e o <strong>olhinho</strong> mostra como foi, só para leitura.'},
      {title: 'Na sala', description: 'Seis etapas com cronômetro (60 min, com alerta por etapa): abertura, resultado da sprint (o Maestro marca se o objetivo foi atingido), demonstração (a entrega da vez fica no palco para todos, com o escopo do card e o aceite), o que não foi entregue (motivo e destino), feedbacks (viram demanda no Funil só quando alguém clicar, com a permissão "Atualizar cards") e próximos passos. Encerrar congela o resultado, que também aparece na retrospectiva.'}
    ]},
    'sprint-retrospective': {title: 'Sprint Retrospective', steps: () => [
      {title: 'Sprint Retrospective', description: 'A retrospectiva da sprint com o time na sala e as ações acompanhadas até o fim. A retro só começa depois que a sprint é fechada no Monday (caixa "Concluído?" do cronograma de sprints), e quem inicia e conduz é o Maestro Scrum.'},
      {element: '#rt-kpis', title: 'Ações das retros', description: 'Ações de todas as retros: em andamento, atrasadas (prazo vencido), concluídas (e quantas atingiram o sinal de resultado), taxa de conclusão e o total. Clique num card para filtrar a lista.'},
      {element: '#rt-actions', title: 'Acompanhamento', description: 'Cada ação com a retro de origem, responsável, prazo e situação. Só o responsável e o facilitador da retro concluem, descartam ou reabrem. As abertas também aparecem no fim da Daily, todos os dias.'},
      {element: '#rt-history', title: 'Últimas sprints', description: 'Por sprint: as cartas em Manter, Melhorar e Experimentar, os focos e o andamento das ações. <strong>Iniciar retro</strong> abre a sala (sprint fechada e sem retro), <strong>Entrar na retro</strong> leva para a retro em andamento e o <strong>olhinho</strong> mostra como foi, só para leitura.'},
      {title: 'Na sala', description: 'Sete etapas com cronômetro: abertura, ações anteriores, dados da sprint, visão do time (cartas viradas até a revelação, depois 3 votos por pessoa), discussão, até dois focos e plano de ação. O cronômetro mostra o tempo da retro e o de cada etapa, e alerta quando estoura. Encerrar congela os indicadores.'}
    ]}
  };

  const has = screen => Boolean(TOURS[screen]);
  const title = screen => TOURS[screen]?.title || '';
  const steps = screen => TOURS[screen] ? [...TOURS[screen].steps(), ...common()] : [];
  return {has, title, steps, maestro, TOURS};
})();
