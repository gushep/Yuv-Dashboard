/*
 * Tours guiados (driver.js).
 * - Tour das novidades: cada tour tem uma versão; abre sozinho uma vez por navegador e pode ser revisto pelo botão
 *   "Tour das novidades" do menu. Passa só pelas telas liberadas para a pessoa e nunca abre sozinho nas telas de TV
 *   (Bombeiro, Painel da Sprint e Apresentação).
 * - Tour da tela: o botão "Tour" no canto direito da barra superior explica a tela aberta (passos em screen-tours.js).
 */
window.Tour = (() => {
  const CURRENT = 'times-v1';
  const TV_SCREENS = ['bombeiro', 'sprint-panel', 'carousel'];
  const storageKey = id => `sprint-metrics-tour-${id}`;
  const seen = id => { try { return localStorage.getItem(storageKey(id)) === '1'; } catch { return false; } };
  const markSeen = id => { try { localStorage.setItem(storageKey(id), '1'); } catch { /* sem armazenamento: abre de novo na próxima visita */ } };
  /** Elemento visível na tela; sem ele, o passo aparece centralizado em vez de apontar para um espaço vazio. */
  const visible = selector => {
    const node = document.querySelector(selector);
    return node && node.getClientRects().length && node.offsetWidth > 0 ? node : undefined;
  };
  /** Tela aberta agora (variável `currentPage` do dashboard). */
  const page = () => (typeof currentPage === 'undefined' ? null : currentPage);
  const maestro = () => window.CAN_MANAGE_CAPACITY
    ? 'Você é <strong>Maestro Scrum</strong>: pode ajustar estes valores.'
    : 'Somente o <strong>Maestro Scrum</strong> altera estes valores; para você, o campo fica só para leitura.';

  /** Passos do tour atual. `screen` é a tela em que o passo aparece; sem ela, o passo vale em qualquer tela. */
  const STEPS = {
    'times-v1': () => [
      {title: 'Novidade: times Full stack e Projetos', description: 'A capacidade da sprint agora separa quem atende os itens da sprint e a sustentação (<strong>Full stack</strong>) de quem atende demandas próprias (<strong>Projetos (IA)</strong>). Os totais continuam como antes; dentro deles aparece, em letra menor, o que compõe cada número. Vamos ver onde isso aparece.'},
      {screen: 'settings', element: '#team-settings', title: 'Configurações · times da capacidade', description: 'Aqui aparecem os usuários <strong>ativos</strong> do Monday. Escolha o time de cada pessoa: <strong>Full stack</strong> ou <strong>Projetos (IA)</strong>.'},
      {screen: 'settings', element: '#team-list', title: 'Sem time = fora das métricas', description: 'Quem fica <strong>sem time</strong> (por exemplo, QA ou gestores) não entra em nenhuma métrica por pessoa nem na capacidade. Os cards dessa pessoa continuam nos totais da sprint. Enquanto ninguém for classificado, todos contam como Full stack, como era antes.'},
      {screen: 'settings', element: '#metric-save', title: 'Salvar', description: 'Ao salvar, todas as telas passam a usar os times. As horas de cada pessoa não ficam aqui: são ajustadas na tela Capacity.'},
      {screen: 'capacity', element: '#cap-teams', title: 'Capacity · previsto e registrado contra a capacidade', description: 'Duas linhas com os mesmos cards: em cima o <strong>previsto</strong> (tempo estimado), embaixo o <strong>registrado</strong> (tempo real), sempre “X de Y” da capacidade, com barra e saldo. <strong>Full stack para a sprint</strong> já desconta a reserva do Bombeiro (ex.: 4 × 80h = 320h − 80h = 240h). <strong>Bombeiro</strong> mostra os cards de Suporte contra a reserva; <strong>Projetos</strong> fica à parte. Cada hora conta num card só, e o total soma os três. Cada card funciona como semáforo: <strong>verde</strong> com saldo, <strong>amarelo</strong> a partir de 90% da capacidade e <strong>vermelho</strong> ao chegar ou passar dela.'},
      {screen: 'capacity', element: '#cap-developer', title: 'Filtrar por time', description: 'Além de uma pessoa, dá para ver só Full stack ou só Projetos.'},
      {screen: 'capacity', element: '#page-capacity .cap-table-scroll', title: 'Horas por pessoa, herdadas da última sprint', description: `A tabela fica agrupada por time, com o selo de cada pessoa. As horas digitadas valem desta sprint em diante: a próxima sprint já vem com o último valor salvo (aparece “herdado da Sprint N”). ${maestro()}`},
      {screen: 'consumption', element: '#cons-teams', title: 'Consumo de horas · os mesmos cards do Capacity', description: 'No topo aparecem as mesmas duas linhas do Capacity: <strong>Previsto</strong> e <strong>Registrado</strong> contra a capacidade, com Capacidade da sprint, Full stack (já sem o Bombeiro), Bombeiro e Projetos, e o mesmo semáforo. Se Projetos registrar suporte, aparece um alerta.'},
      {screen: 'consumption', element: '#page-consumption .cons-composition', title: 'Composição do executado', description: 'O total executado de Sprint e Suporte, com quanto foi de <strong>Desenvolvimento</strong>, de <strong>Projetos</strong> e, se houver, de pessoas sem time ou sem atribuição.'},
      {screen: 'consumption', element: '#cons-reserve', title: 'Reserva do Bombeiro', description: `A reserva de suporte da sprint (padrão 80h) é descontada da capacidade Full stack. ${maestro()}`},
      {screen: 'consumption', element: '#cons-individual', title: 'Duas tabelas: Desenvolvimento e Projetos', description: 'O consumo por pessoa agora vem separado em <strong>Consumo Desenvolvimento</strong> (Full stack) e <strong>Consumo Projetos</strong>, cada uma com o próprio total.'},
      {screen: 'control-tower', element: '#tower-signals', title: 'Torre de Controle · capacidade restante', description: 'O sinal de capacidade compara o esforço que falta com a capacidade <strong>Full stack menos o Bombeiro</strong>, porque só ela atende os itens da sprint. O detalhe mostra o total do time e a parte de Projetos.'},
      {screen: 'control-tower', element: '#tower-hours', title: 'Torre de Controle · horas da sprint', description: 'As horas <strong>planejadas</strong> (Previsto), <strong>utilizadas</strong> (Registrado) e <strong>disponíveis</strong> de cada parte: capacidade total, Full stack, Bombeiro e Projetos, com o mesmo semáforo do Capacity.'},
      {screen: 'forecast', element: '#forecast-teams', title: 'Previsibilidade por time', description: 'Resumo do time completo, do Full stack (já sem o Bombeiro) e de Projetos. Na tabela, cada pessoa tem o selo do time e dá para filtrar por time.'},
      {screen: 'planning', element: '#pp-history', title: 'Planning · últimas sprints', description: 'Cada sprint mostra as horas planejadas, realizadas e disponíveis por time. A sprint em andamento tem <strong>Entrar na mesa</strong> até terminar; as encerradas têm o <strong>olhinho</strong> para ver como foi a planning. Ao encerrar a planning, os cards estimados são ligados à sprint no Monday.'},
      {screen: 'planning', element: '#pp-hand-section', title: 'Planning · interromper e reestimar', description: 'Com uma carta na mesa, o Maestro pode jogar outra direto da mão: a estimativa atual é <strong>interrompida</strong> (cartas descartadas, nada gravado) e o card volta para a mão. Em <strong>Estimados nesta planning</strong>, o botão <strong>Reestimar</strong> devolve o card à mesa; a estimativa atual vale até o time registrar a nova.'},
      {screen: 'planning', element: '#pp-load', title: 'Planning · carga por time', description: 'A carga da mesa vem em duas partes: <strong>Full stack</strong> (capacidade já sem o Bombeiro, sem os cards de Suporte) e <strong>Projetos</strong>, cada uma com capacidade, planejado e disponível em horas e %, a cor do semáforo e as suas pessoas. Só entram as pessoas com time.'},
      {element: '#tb-hours', title: 'Horas da sprint na barra superior', description: 'Em todas as telas, a barra superior resume as horas da sprint em andamento (na tela Sprints, da sprint escolhida) numa tabela: para Capacidade, Full stack, Bombeiro e Projetos, o uso contra a capacidade e a coluna <strong>Disponível</strong>; nas linhas, <strong>Planejado</strong> (tempo estimado) e <strong>Realizado</strong> (tempo real), com a cor do semáforo. Clique nela para abrir o Capacity.'},
      {element: '#tour-button', title: 'Reveja quando quiser', description: 'Este tour fica no menu lateral, em <strong>Tour das novidades</strong>.'}
    ]
  };

  let driverObj = null;
  /**
   * Roda uma lista de passos com o driver.js. Passo com `screen` leva antes para a tela dele (tour das novidades);
   * os tours de tela ficam na tela aberta.
   */
  function run(steps, onDestroyed = () => {}) {
    const create = window.driver?.js?.driver;
    if (!create) { alert('Não foi possível carregar o tour. Confira a conexão e recarregue a página.'); return; }
    driverObj?.destroy();
    // Leva para a tela do passo antes de destacá-lo; as telas trocam na mesma página, então basta esperar o desenho.
    const show = index => (driverObj.isActive() ? driverObj.moveTo(index) : driverObj.drive(index));
    const go = index => {
      const screen = steps[index]?.screen;
      if (screen && page() !== screen) { navigate(screen); setTimeout(() => show(index), 450); }
      else show(index);
    };
    driverObj = create({
      showProgress: true, allowClose: true, overlayOpacity: .55, stagePadding: 6, popoverClass: 'sm-tour',
      progressText: '{{current}} de {{total}}', nextBtnText: 'Próximo', prevBtnText: 'Voltar', doneBtnText: 'Concluir',
      // O elemento é procurado na hora de destacar (getter), depois da troca de tela.
      steps: steps.map(step => ({
        get element() { return step.element ? visible(step.element) : undefined; },
        popover: {title: step.title, description: step.description, side: 'bottom', align: 'start'}
      })),
      onNextClick: () => { const next = driverObj.getActiveIndex() + 1; next >= steps.length ? driverObj.destroy() : go(next); },
      onPrevClick: () => { const previous = driverObj.getActiveIndex() - 1; if (previous >= 0) go(previous); },
      onDestroyed
    });
    go(0);
  }

  function start(id = CURRENT) {
    const origin = page(), originSprint = typeof currentSprint === 'undefined' ? null : currentSprint;
    const steps = STEPS[id]().filter(step => !step.screen || (typeof canAccessScreen === 'function' && canAccessScreen(step.screen)));
    run(steps, () => {
      markSeen(id);
      if (origin && page() !== origin && typeof canAccessScreen === 'function' && canAccessScreen(origin)) navigate(origin, originSprint);
    });
  }

  /**
   * Tour da tela aberta (botão "?" no canto direito da barra superior): os passos vêm de ScreenTours. Passos de partes
   * que não estão à vista agora (ex.: seção escondida até haver dados) ficam de fora.
   */
  function startScreen(screen = page()) {
    const steps = (window.ScreenTours?.steps(screen) || []).filter(step => !step.element || visible(step.element));
    if (!steps.length) { alert('Esta tela ainda não tem tour guiado.'); return; }
    run(steps);
  }
  /** Rótulo da tela para o botão do tour; vazio quando a tela não tem tour. */
  const screenTitle = (screen = page()) => window.ScreenTours?.title(screen) || '';

  /** Abre sozinho uma vez, depois que a tela inicial carregou, fora das telas de TV. */
  function autoStart() {
    if (seen(CURRENT)) return;
    setTimeout(() => { if (!TV_SCREENS.includes(page()) && page() !== 'no-access') start(CURRENT); }, 1500);
  }
  document.addEventListener('DOMContentLoaded', () => { if (window.driver?.js?.driver) autoStart(); });
  return {start, startScreen, screenTitle, seen, CURRENT};
})();
