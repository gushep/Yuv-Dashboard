/*
 * Resumo de horas dentro da barra superior, em todas as telas: os quatro cards do Capacity (Capacidade, Full stack,
 * Bombeiro e Projetos) em formato mini, cada um com o previsto e o registrado contra a capacidade, as horas
 * disponíveis e a cor do semáforo. Mostra a sprint aberta na tela Sprints ou, nas demais, a sprint em andamento.
 * Fica de fora nas telas de TV e para quem não acessa o Capacity; um clique abre o Capacity da sprint.
 */
window.HoursStrip = (() => {
  const HIDDEN_ON = ['bombeiro', 'sprint-panel', 'carousel', 'no-access'];
  const SHORT_LABELS = {'Capacidade da sprint': 'Capacidade', 'Full stack para a sprint': 'Full stack', 'Bombeiro (sustentação)': 'Bombeiro', 'Projetos (IA)': 'Projetos'};
  const state = {reserves: null, loading: null};
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const page = () => (typeof currentPage === 'undefined' ? null : currentPage);
  const hours = minutes => window.Capacity.formatMinutes(minutes);
  const pct = (used, capacity) => capacity > 0 ? `${Math.round(used / capacity * 100)}%` : '—';

  /** Sprint exibida: a escolhida na tela Sprints; nas demais, a sprint em andamento. */
  function sprintShown() {
    if (page() === 'sprint' && typeof currentSprint !== 'undefined' && Number.isInteger(currentSprint)) return currentSprint;
    return typeof defaultSprint === 'function' ? defaultSprint() : null;
  }

  async function loadReserves(force = false) {
    if (state.reserves && !force) return;
    state.loading ??= mondayRequest('/api/support-reserves')
      .then(result => { state.reserves = result; })
      .catch(() => { /* Sem a reserva salva, o Bombeiro aparece com 0h até a próxima sincronização. */ })
      .finally(() => { state.loading = null; });
    await state.loading;
  }

  /** Célula de uso: "usado de capacidade · %", com a bolinha do semáforo. */
  function usage(used, capacity) {
    const level = window.SprintRules.capacityLevel(used, capacity);
    return `<span class="tb-hours-cell level-${level}"><i aria-hidden="true"></i><b>${hours(used)}</b>&nbsp;de ${hours(capacity)} · ${pct(used, capacity)}</span>`;
  }
  /** Disponível da linha = capacidade − (planejado ou realizado); acima da capacidade mostra o excedente em vermelho. */
  function available(used, capacity) {
    const balance = capacity - used, level = window.SprintRules.capacityLevel(used, capacity);
    return `<span class="tb-hours-cell tb-hours-available level-${level}"><strong>${balance < 0 ? `${hours(-balance)} excedido` : hours(balance)}</strong></span>`;
  }

  /**
   * Tabela de horas de uma sprint: para cada parte da capacidade (Capacidade, Full stack, Bombeiro e Projetos), a coluna
   * de uso e a coluna Disponível; linhas Planejado e Realizado. Usada na barra superior (link para o Capacity) e na
   * lista de sprints da Planning (só leitura). Sem dados sincronizados, devolve vazio.
   */
  function tableHtml(sprint, {link = true} = {}) {
    if (!Number.isInteger(sprint) || typeof DATA === 'undefined' || !DATA.length || !window.Capacity?.summaryFor) return '';
    const reserve = state.reserves?.reserves?.[sprint] ?? state.reserves?.defaultMinutes ?? 0;
    const {cards} = window.Capacity.summaryFor(DATA, sprint, window.MONDAY_SPRINT_DATES?.[sprint] || null, window.SprintRules.settings.capacityOverrides || {}, reserve);
    const head = `<span class="tb-hours-corner">Sprint ${sprint}</span>${cards.map(card => `<span class="tb-hours-col ${card.kind}" title="${escape(card.label)}">${escape(SHORT_LABELS[card.label] || card.label)}</span><span class="tb-hours-col tb-hours-col-available" title="Capacidade menos o planejado ou o realizado">Disponível</span>`).join('')}`;
    const row = (label, hint, key) => `<span class="tb-hours-row" title="${hint}">${label}</span>${cards.map(card => usage(card[key], card.capacity) + available(card[key], card.capacity)).join('')}`;
    const attributes = link
      ? `role="link" tabindex="0" data-sprint="${sprint}" title="Horas da Sprint ${sprint} contra a capacidade. Clique para abrir o Capacity." aria-label="Horas da Sprint ${sprint}. Abrir o Capacity."`
      : `role="group" aria-label="Horas da Sprint ${sprint} contra a capacidade"`;
    return `<div class="tb-hours-table ${link ? '' : 'static'}" ${attributes}>
      ${head}
      ${row('Planejado', 'Tempo estimado dos cards', 'estimate')}
      ${row('Realizado', 'Tempo real apontado nos cards', 'registered')}
    </div>`;
  }

  function render() {
    const node = el('tb-hours');
    if (!node) return;
    const sprint = sprintShown();
    const visible = !HIDDEN_ON.includes(page()) && typeof canAccessScreen === 'function' && canAccessScreen('capacity')
      && Number.isInteger(sprint) && typeof DATA !== 'undefined' && DATA.length > 0 && Boolean(window.Capacity?.summaryFor);
    node.hidden = !visible;
    if (!visible) { node.innerHTML = ''; return; }
    node.innerHTML = tableHtml(sprint);
    const table = node.querySelector('.tb-hours-table');
    const open = () => { if (typeof navigate === 'function') navigate('capacity', sprint); };
    table.onclick = open;
    table.onkeydown = event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); } };
    if (!state.reserves) loadReserves().then(() => { if (state.reserves) render(); });
  }

  /** Depois de cada sincronização: a reserva pode ter mudado na tela Consumo de horas. */
  async function onDataUpdated() { await loadReserves(true); render(); }

  /** Reserva do Bombeiro da sprint, em minutos (a salva no Consumo de horas ou o padrão). */
  const reserveFor = sprint => state.reserves?.reserves?.[sprint] ?? state.reserves?.defaultMinutes ?? 0;

  return {render, onDataUpdated, sprintShown, tableHtml, loadReserves, reserveFor, HIDDEN_ON};
})();
