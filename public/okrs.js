/*
 * OKRs do time de desenvolvimento: se estamos atingindo os resultados-chave do objetivo, na sprint em andamento, num
 * intervalo de sprints e mês a mês. Produtividade (entregues ÷ todos os cards) e retrabalho (com retrabalho ÷ todos os
 * cards) usam as contas do Dashboard (metricsOf sobre todos os cards do período, inclusive não planejados e suporte, com
 * as sprints encerradas congeladas no último dia); o uso da capacidade (backlog preparado) usa a conta do Capacity:
 * horas apontadas (tempo real) ÷ capacidade da sprint, a mesma linha Realizado da barra de horas do topo.
 * Num intervalo, tudo do período é somado, como no Dashboard: sprints maiores pesam mais.
 */
window.Okrs = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const day = value => value ? value.slice(0, 10).split('-').reverse().join('/') : '—';
  const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  const monthLabel = key => `${MONTHS[Number(key.slice(5, 7)) - 1]}/${key.slice(0, 4)}`;
  const HISTORY_MONTHS = 6;
  /** Faixa amarela: até 5 pontos percentuais do lado errado da meta. */
  const NEAR = 5;

  const OBJECTIVE = 'Aumentar capacidade de entrega, reduzir retrabalho e melhorar previsibilidade das sprints e do backlog.';
  const KEY_RESULTS = [
    {key: 'productivity', title: 'Elevar a produtividade de desenvolvimento', target: 90, better: 'higher', goal: '≥ 90% dos cards da sprint entregues',
      unit: 'card(s) entregue(s)', method: 'Cards com status Implementado ÷ todos os cards da sprint (planejados, não planejados e suporte), sem os retirados (cancelado, descontinuado, despriorizado). Mesma conta da Conclusão do Dashboard.'},
    {key: 'rework', title: 'Manter retrabalho abaixo de 10%', target: 10, better: 'lower', goal: '≤ 10% dos cards com retrabalho',
      unit: 'card(s) com retrabalho', method: 'Cards com a coluna Retrabalho marcada ÷ todos os cards da sprint (planejados, não planejados e suporte), sem os retirados. Mesma conta do Dashboard.'},
    {key: 'backlog', title: 'Manter backlog preparado', target: 80, better: 'higher', goal: '≥ 80% da capacidade da sprint utilizada', hours: true,
      unit: 'consumidas da capacidade', method: 'Uso da capacidade: horas apontadas (tempo real) nos cards da sprint ÷ capacidade da sprint (soma da capacidade de cada pessoa do time, Full stack e Projetos, salva no Capacity). Mesma conta da linha Realizado da coluna Capacidade na barra de horas do topo e da tela Capacity. Backlog preparado = o time teve trabalho pronto para usar a capacidade. Abaixo do total, as mesmas partes da barra de horas: Full stack (capacidade descontada a reserva do Bombeiro, sem os cards de Suporte), Sustentação (reserva do Bombeiro contra os cards de Suporte) e Projetos; somadas, dão o total.'},
  ];

  const state = {active: false, from: null, to: null, person: null, people: []};

  // ── Datas e sprints ──────────────────────────────────────────────────────────
  const dates = sprint => window.MONDAY_SPRINT_DATES?.[sprint] || null;
  const today = () => window.SprintRules.today();
  /** Sprints que já começaram, em ordem crescente. */
  function startedSprints() {
    const now = today();
    return (typeof SPRINTS === 'undefined' ? [] : SPRINTS).filter(sprint => dates(sprint)?.start && dates(sprint).start <= now);
  }
  const currentSprint = () => (typeof defaultSprint === 'function' ? defaultSprint() : null) ?? startedSprints().at(-1) ?? null;
  const inProgress = sprint => { const range = dates(sprint), now = today(); return Boolean(range && range.start <= now && range.end >= now); };
  /** Mês (AAAA-MM) em que a sprint começa: é nele que ela conta no histórico por mês. */
  const monthOf = sprint => dates(sprint)?.start?.slice(0, 7) ?? null;

  // ── Contas ───────────────────────────────────────────────────────────────────
  const ratio = (part, whole) => whole > 0 ? Math.round(part / whole * 1000) / 10 : null;
  /**
   * Capacidade da sprint e horas apontadas, em minutos: o card "Capacidade da sprint" do Capacity (o mesmo da barra de
   * horas do topo), com a capacidade de cada pessoa salva para a sprint.
   */
  function capacityOf(sprint) {
    const empty = {capacity: 0, registered: 0, parts: Object.fromEntries(CAPACITY_PARTS.map(part => [part.kind, {capacity: 0, registered: 0}]))};
    if (typeof DATA === 'undefined' || !window.Capacity?.summaryFor) return empty;
    const reserve = window.HoursStrip?.reserveFor?.(sprint) ?? 0;
    const cards = window.Capacity.summaryFor(DATA, sprint, dates(sprint), window.SprintRules.settings.capacityOverrides || {}, reserve).cards;
    const pick = card => ({capacity: card?.capacity ?? 0, registered: card?.registered ?? 0});
    return {...pick(cards[0]), parts: Object.fromEntries(CAPACITY_PARTS.map(part => [part.kind, pick(cards.find(card => card.kind === part.kind))]))};
  }
  /**
   * Partes da capacidade da sprint, os mesmos cards da barra de horas do topo: Full stack (descontada a reserva do
   * Bombeiro, sem os cards de Suporte), Sustentação (a reserva do Bombeiro contra os cards de Suporte) e Projetos.
   * Somadas, dão a capacidade e as horas do total.
   */
  const CAPACITY_PARTS = [
    {kind: 'fullstack', label: 'Full stack', hint: 'capacidade descontada a reserva do Bombeiro · sem os cards de Suporte'},
    {kind: 'support', label: 'Sustentação (Bombeiro)', hint: 'reserva do Bombeiro · cards de Suporte de qualquer pessoa'},
    {kind: 'projects', label: 'Projetos', hint: 'demandas próprias, fora da sprint e da sustentação'},
  ];
  /**
   * Os três resultados-chave de um conjunto de sprints, somando tudo do período (como o Dashboard).
   * @return {Record<string, {value: ?number, part: number, whole: number}>}
   */
  function compute(sprints) {
    const set = new Set(sprints.map(Number));
    const rows = (typeof DATA === 'undefined' ? [] : DATA).filter(row => set.has(Number(row.Sprint)));
    const metrics = typeof metricsOf === 'function' ? metricsOf(rows) : {done: 0, rework: 0, tot: 0};
    const add = (sum, item) => ({capacity: sum.capacity + item.capacity, registered: sum.registered + item.registered});
    const perSprint = sprints.map(capacityOf);
    const capacity = perSprint.reduce(add, {capacity: 0, registered: 0});
    const parts = CAPACITY_PARTS.map(part => {
      const total = perSprint.reduce((sum, item) => add(sum, item.parts[part.kind]), {capacity: 0, registered: 0});
      return {...part, value: ratio(total.registered, total.capacity), part: total.registered, whole: total.capacity};
    });
    return {
      productivity: {value: ratio(metrics.done, metrics.tot), part: metrics.done, whole: metrics.tot},
      rework: {value: ratio(metrics.rework, metrics.tot), part: metrics.rework, whole: metrics.tot},
      backlog: {value: ratio(capacity.registered, capacity.capacity), part: capacity.registered, whole: capacity.capacity, parts},
    };
  }

  // ── Por pessoa ───────────────────────────────────────────────────────────────
  const normalize = value => window.SprintRules.normalize(value);
  const people = value => String(value ?? '').split(',').map(name => name.trim()).filter(Boolean);
  const ownedBy = (row, name) => people(row.Responsavel).some(person => normalize(person) === normalize(name));
  const initials = name => name.split(/\s+/).filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase();
  const TEAM_ORDER = {fullstack: 0, projects: 1};
  /**
   * Pessoas do período, como na Daily: quem tem capacidade ou cards em alguma sprint dele. A capacidade e as horas
   * apontadas de cada uma vêm do Capacity (somadas nas sprints do período); cards com vários responsáveis ou em várias
   * sprints ficam fora das horas, como lá.
   * @return {Array<{name: string, team: ?string, capacity: number, registered: number}>}
   */
  function developersOf(sprints) {
    if (typeof DATA === 'undefined' || !window.Capacity?.model) return [];
    const set = new Set(sprints.map(Number));
    const rows = DATA.filter(row => set.has(Number(row.Sprint)));
    const byName = new Map();
    for (const sprint of sprints) {
      const developers = window.Capacity.model(DATA, sprint, dates(sprint), window.SprintRules.settings.capacityOverrides || {}).developers;
      // A reserva do Bombeiro sai só do Full stack; cada pessoa de lá fica com a parte proporcional à capacidade dela.
      const fullstackCapacity = developers.filter(dev => dev.team !== 'projects').reduce((sum, dev) => sum + dev.capacity, 0);
      const reserve = window.SprintRules.teamsConfigured() ? (window.HoursStrip?.reserveFor?.(sprint) ?? 0) : 0;
      for (const dev of developers) {
        const key = normalize(dev.name);
        if (!byName.has(key)) byName.set(key, {name: dev.name, team: dev.team, capacity: 0, registered: 0, supportCapacity: 0, supportRegistered: 0});
        const item = byName.get(key);
        item.capacity += dev.capacity; item.registered += dev.registered;
        item.supportCapacity += dev.team !== 'projects' && fullstackCapacity > 0 ? reserve * dev.capacity / fullstackCapacity : 0;
        item.supportRegistered += dev.supportAllReal;
      }
    }
    return [...byName.values()].filter(dev => dev.capacity > 0 || rows.some(row => ownedBy(row, dev.name)))
      .sort((a, b) => (TEAM_ORDER[a.team] ?? 9) - (TEAM_ORDER[b.team] ?? 9) || a.name.localeCompare(b.name, 'pt-BR'));
  }
  /** Os três resultados-chave de uma pessoa: os cards em que ela é responsável e a capacidade e as horas dela. */
  function computePerson(sprints, dev) {
    const set = new Set(sprints.map(Number));
    const rows = (typeof DATA === 'undefined' ? [] : DATA).filter(row => set.has(Number(row.Sprint)) && ownedBy(row, dev.name));
    const metrics = typeof metricsOf === 'function' ? metricsOf(rows) : {done: 0, rework: 0, tot: 0};
    return {
      productivity: {value: ratio(metrics.done, metrics.tot), part: metrics.done, whole: metrics.tot},
      rework: {value: ratio(metrics.rework, metrics.tot), part: metrics.rework, whole: metrics.tot},
      backlog: {value: ratio(dev.registered, dev.capacity), part: dev.registered, whole: dev.capacity, parts: personParts(dev)},
    };
  }
  /**
   * Partes do uso da capacidade da pessoa: duas, a sprint do time dela (Full stack ou Projetos de IA) e a Sustentação.
   * No Full stack, a Sustentação é a parte da reserva do Bombeiro proporcional à capacidade da pessoa, e a sprint é o
   * resto; em Projetos não há reserva. As horas de cards de Suporte vão para a Sustentação; as demais, para a sprint.
   */
  function personParts(dev) {
    const projects = dev.team === 'projects';
    const supportCapacity = dev.supportCapacity ?? 0, supportRegistered = dev.supportRegistered ?? 0;
    const sprintPart = {kind: projects ? 'projects' : 'fullstack', label: projects ? 'Projetos (IA)' : 'Sprint (Full stack)',
      hint: projects ? 'capacidade da pessoa em Projetos · sem os cards de Suporte' : 'capacidade da pessoa descontada a parte dela na reserva do Bombeiro · sem os cards de Suporte',
      part: dev.registered - supportRegistered, whole: dev.capacity - supportCapacity};
    const supportPart = {kind: 'support', label: 'Sustentação (Bombeiro)', hint: projects ? 'Projetos não tem reserva do Bombeiro · cards de Suporte da pessoa' : 'parte da reserva do Bombeiro proporcional à capacidade da pessoa · cards de Suporte dela',
      part: supportRegistered, whole: supportCapacity};
    return [sprintPart, supportPart].map(part => ({...part, value: ratio(part.part, part.whole)}));
  }
  /**
   * Placar da Daily (XP) da pessoa no período: os mesmos cards e as mesmas regras da Daily (cada entrega vale 10; +5 no
   * alvo do estimado; +8 adiantada), somando as sprints do período. Sem a Daily carregada, não mostra.
   * @return {?{xp: number, delivered: number, onTarget: number, early: number, precision: ?number}}
   */
  function scoreOf(sprints, name) {
    if (!window.Daily?.score || !window.Daily?.sprintCards) return null;
    return window.Daily.score(sprints.flatMap(sprint => window.Daily.sprintCards(sprint)).filter(row => ownedBy(row, name)));
  }
  const hoursOf = minutes => window.Capacity?.formatMinutes ? window.Capacity.formatMinutes(Math.max(0, Math.round(minutes))) : `${Math.round(minutes / 60)}h`;
  /** Quantidade na unidade do resultado-chave: cards ou horas. */
  const amount = (keyResult, value) => keyResult.hours ? hoursOf(value) : String(value);
  /** Situação do resultado-chave contra a meta: verde atingido, amarelo perto (até 5 pontos), vermelho fora. */
  function level(keyResult, value) {
    if (value === null || value === undefined) return 'none';
    const gap = keyResult.better === 'higher' ? keyResult.target - value : value - keyResult.target;
    return gap <= 0 ? 'green' : gap <= NEAR ? 'yellow' : 'red';
  }
  const LEVEL_LABEL = {green: 'Atingido', yellow: 'Perto da meta', red: 'Não atingido', none: 'Sem dados'};
  const percent = value => value === null || value === undefined ? '—' : `${String(value).replace('.', ',')}%`;

  // ── Período ──────────────────────────────────────────────────────────────────
  function period() {
    const list = startedSprints();
    if (!list.length) return [];
    let from = list.includes(state.from) ? state.from : currentSprint(), to = list.includes(state.to) ? state.to : currentSprint();
    if (from > to) [from, to] = [to, from];
    return list.filter(sprint => sprint >= from && sprint <= to);
  }

  // ── Tela ─────────────────────────────────────────────────────────────────────
  function render() {
    if (!state.active) return;
    const list = startedSprints();
    if (!list.length) {
      el('okr-period').textContent = 'Aguardando os dados do Monday...';
      ['okr-objective', 'okr-sprints', 'okr-months', 'okr-growth-summary', 'okr-growth-table', 'okr-faces', 'okr-person', 'okr-info'].forEach(id => { el(id).innerHTML = ''; });
      return;
    }
    if (!list.includes(state.from)) state.from = currentSprint();
    if (!list.includes(state.to)) state.to = currentSprint();
    renderFilters(list);
    const sprints = period();
    const first = dates(sprints[0]), last = dates(sprints.at(-1));
    const partial = sprints.some(inProgress);
    el('okr-period').innerHTML = `${sprints.length === 1 ? `Sprint ${sprints[0]}` : `Sprints ${sprints[0]} a ${sprints.at(-1)} (${sprints.length})`}${first && last ? ` · ${day(first.start)} a ${day(last.end)}` : ''}${partial ? ' · <b>parcial: sprint em andamento</b>, os números mudam até ela terminar (o uso da capacidade cresce conforme as horas são apontadas)' : ''}`;
    const teamResults = compute(sprints);
    renderObjective(teamResults, partial);
    renderSprints(sprints);
    renderMonths();
    renderGrowth();
    renderMethod();
    renderPeople(sprints, teamResults, partial);
    renderInfographic(sprints, teamResults, partial);
  }

  function renderFilters(list) {
    const options = list.slice().reverse().map(sprint => `<option value="${sprint}">Sprint ${sprint}${inProgress(sprint) ? ' (atual)' : ''}</option>`).join('');
    for (const [id, value] of [['okr-from', state.from], ['okr-to', state.to]]) {
      const select = el(id);
      if (select.dataset.options !== options) { select.innerHTML = options; select.dataset.options = options; }
      select.value = String(value);
    }
  }

  /** Barra de progresso: maior é melhor vai de 0 a 100%; menor é melhor, de 0 ao dobro da meta. A marca é a meta. */
  function bar(keyResult, value) {
    const scale = keyResult.better === 'higher' ? 100 : Math.max(keyResult.target * 2, value ?? 0);
    const width = value === null ? 0 : Math.min(100, value / scale * 100);
    return `<span class="okr-bar level-${level(keyResult, value)}" aria-hidden="true"><b style="width:${width}%"></b><i style="left:${keyResult.target / scale * 100}%"></i></span>`;
  }

  function detail(keyResult, result) {
    if (!result.whole) return keyResult.hours ? 'sem capacidade cadastrada no período' : 'nenhum card no período';
    return `${amount(keyResult, result.part)} de ${amount(keyResult, result.whole)} ${keyResult.unit}`;
  }

  /** Os números (cards ou horas) por trás do percentual de cada resultado-chave. */
  const COUNT_LABELS = {
    productivity: ['Entregues', 'Faltam entregar', 'Cards na sprint'],
    rework: ['Com retrabalho', 'Sem retrabalho', 'Cards na sprint'],
    backlog: ['Consumido', 'Não utilizado', 'Capacidade da sprint'],
  };
  const PERSON_WHOLE = {productivity: 'Cards da pessoa', rework: 'Cards da pessoa', backlog: 'Capacidade da pessoa'};
  function counts(keyResult, result, person = false) {
    const [partLabel, restLabel, teamWhole] = COUNT_LABELS[keyResult.key];
    const wholeLabel = person ? PERSON_WHOLE[keyResult.key] : teamWhole;
    const value = number => number < 0 ? `excedeu ${amount(keyResult, -number)}` : amount(keyResult, number);
    return `<dl class="okr-counts">${[[partLabel, result.part, 'part'], [restLabel, result.whole - result.part, 'rest'], [wholeLabel, result.whole, 'whole']]
      .map(([label, number, kind]) => `<div class="${kind}"><dt>${label}</dt><dd>${result.whole ? value(number) : '—'}</dd></div>`).join('')}</dl>`;
  }

  /** Situação de cada resultado-chave, quantos foram atingidos e a cor do objetivo. */
  function summary(results) {
    const levels = KEY_RESULTS.map(keyResult => level(keyResult, results[keyResult.key].value));
    const hit = levels.filter(item => item === 'green').length;
    const overall = levels.includes('none') && hit === 0 ? 'none' : hit === KEY_RESULTS.length ? 'green' : levels.includes('red') ? 'red' : 'yellow';
    return {levels, hit, overall};
  }

  /** Uso da capacidade por parte (Full stack, Sustentação e Projetos), abaixo do total, com a mesma meta e as mesmas cores. */
  function partsHtml(keyResult, parts) {
    return `<ul class="okr-parts" aria-label="Uso da capacidade por parte">${parts.map(part => {
      const status = level(keyResult, part.value);
      const base = part.whole ? `${amount(keyResult, part.part)} de ${amount(keyResult, part.whole)}` : `sem capacidade${part.part ? ` · ${amount(keyResult, part.part)} apontadas` : ''}`;
      return `<li class="okr-part level-${status}" title="${escape(`${part.label}: ${part.hint} · ${LEVEL_LABEL[status]}`)}">
          <span class="okr-part-name">${escape(part.label)}</span>${bar(keyResult, part.value)}
          <strong>${percent(part.value)}</strong><small>${base}</small>
        </li>`;
    }).join('')}</ul>`;
  }

  /** Lista dos resultados-chave; na pessoa, cada um mostra também o valor do time no mesmo período. */
  function krList(results, levels, team = null) {
    return `<ul class="okr-krs">${KEY_RESULTS.map((keyResult, index) => {
      const result = results[keyResult.key];
      return `<li class="okr-kr level-${levels[index]}">
          <div class="okr-kr-main"><span class="okr-eyebrow">Resultado-chave ${index + 1}</span><h3>${escape(keyResult.title)}</h3><small>Meta: ${escape(keyResult.goal)}</small>${bar(keyResult, result.value)}${counts(keyResult, result, Boolean(team))}${result.parts ? partsHtml(keyResult, result.parts) : ''}</div>
          <div class="okr-kr-value"><strong>${percent(result.value)}</strong><span class="okr-chip level-${levels[index]}">${LEVEL_LABEL[levels[index]]}</span><small>${escape(detail(keyResult, result))}</small>${team ? `<small class="okr-team-ref">Time: ${percent(team[keyResult.key].value)}</small>` : ''}</div>
        </li>`;
    }).join('')}</ul>`;
  }

  function renderObjective(results, partial) {
    const {levels, hit, overall} = summary(results);
    el('okr-objective').innerHTML = `<div class="okr-objective level-${overall}">
        <span class="okr-eyebrow">Objetivo</span>
        <p>${escape(OBJECTIVE)}</p>
        <strong>${hit} de ${KEY_RESULTS.length}</strong><small>resultados-chave atingidos${partial ? ' até agora' : ''}</small>
      </div>
      ${krList(results, levels)}`;
  }

  /** Rostos do time (como na Daily), cada um com quantos resultados-chave a pessoa atingiu no período. */
  function renderPeople(sprints, teamResults, partial) {
    const developers = developersOf(sprints);
    state.people = developers.map(dev => dev.name);
    if (!state.people.includes(state.person)) state.person = state.people[0] ?? null;
    if (!developers.length) {
      el('okr-faces').innerHTML = '<p class="okr-empty">Nenhuma pessoa com capacidade ou cards no período.</p>';
      el('okr-person').innerHTML = '';
      return;
    }
    const resultsOf = new Map(developers.map(dev => [dev.name, computePerson(sprints, dev)]));
    const scores = new Map(developers.map(dev => [dev.name, scoreOf(sprints, dev.name)]));
    el('okr-faces').innerHTML = `<button type="button" class="daily-face-nav" data-okr-step="-1" aria-label="Pessoa anterior">‹</button>
      ${developers.map((dev, index) => {
        const {hit, overall} = summary(resultsOf.get(dev.name));
        return `<button type="button" class="daily-face ${dev.name === state.person ? 'selected' : ''}" data-okr-person="${escape(dev.name)}" aria-pressed="${dev.name === state.person}" title="${escape(`${dev.name} · ${hit} de ${KEY_RESULTS.length} resultados-chave atingidos`)}">
          <span class="cap-avatar cap-avatar-${index % 4} daily-avatar">${escape(initials(dev.name))}</span>
          <span class="daily-face-name">${escape(dev.name.split(' ')[0])}</span>
          <span class="okr-chip okr-face-hits level-${overall}">${hit} de ${KEY_RESULTS.length}</span>
          ${scores.get(dev.name) ? `<span class="okr-face-xp">⭐ ${scores.get(dev.name).xp} XP</span>` : ''}
        </button>`;
      }).join('')}
      <button type="button" class="daily-face-nav" data-okr-step="1" aria-label="Próxima pessoa">›</button>`;
    const index = state.people.indexOf(state.person), dev = developers[index];
    const results = resultsOf.get(dev.name), {levels, hit, overall} = summary(results), score = scores.get(dev.name);
    const xp = score ? `<div class="daily-person-stat xp okr-person-xp" title="Mesmo placar da Daily: cada entrega vale 10 XP; +5 no alvo do estimado; +8 adiantada (até 80% do estimado)">
        <span>⭐ XP no período</span><strong>${score.xp} XP</strong>
        <small>${score.delivered ? `${score.delivered} entrega(s) · ${score.onTarget} no alvo${score.early ? ` · ${score.early} adiantada(s)` : ''} · precisão ${score.precision}%` : 'nenhuma entrega ainda'}</small>
      </div>${score.delivered && score.precision >= 80 ? '<p class="daily-achievement">🏆 Precisão de estimativa acima de 80%</p>' : ''}` : '';
    const bySprint = sprints.length > 1 ? `<h3 class="okr-person-sub">Sprints do período</h3><div class="okr-scroll"><table class="okr-table">${head('Sprint')}<tbody>${sprints.slice().reverse().map(sprint => {
      const sprintResults = computePerson([sprint], developersOf([sprint]).find(item => normalize(item.name) === normalize(dev.name)) ?? {...dev, capacity: 0, registered: 0, supportCapacity: 0, supportRegistered: 0});
      return `<tr><th scope="row">Sprint ${sprint}${inProgress(sprint) ? ' <em>em andamento</em>' : ''}</th>${KEY_RESULTS.map(keyResult => cell(keyResult, sprintResults[keyResult.key])).join('')}</tr>`;
    }).join('')}</tbody></table></div>` : '';
    el('okr-person').innerHTML = `<div class="okr-person-grid">
        <div class="okr-objective okr-person-head level-${overall}">
          <span class="cap-avatar cap-avatar-${index % 4} daily-avatar large">${escape(initials(dev.name))}</span>
          <h3>${escape(dev.name)}</h3>
          ${dev.team ? `<span class="team-badge ${escape(dev.team)}">${escape(window.SprintRules.teamLabel?.(dev.team) ?? dev.team)}</span>` : ''}
          ${xp}
          <strong>${hit} de ${KEY_RESULTS.length}</strong><small>resultados-chave atingidos${partial ? ' até agora' : ''} · ${index + 1} de ${developers.length} pessoas</small>
        </div>
        ${krList(results, levels, teamResults)}
      </div>${bySprint}`;
  }

  function step(delta) {
    if (!state.people.length) return;
    const index = Math.max(0, state.people.indexOf(state.person));
    state.person = state.people[(index + delta + state.people.length) % state.people.length];
    const sprints = period();
    renderPeople(sprints, compute(sprints), sprints.some(inProgress));
  }

  /** Célula de um resultado-chave nas tabelas: valor colorido pela meta e a base da conta (cards ou horas). */
  function cell(keyResult, result) {
    const status = level(keyResult, result.value);
    const base = result.whole ? `${amount(keyResult, result.part)} de ${amount(keyResult, result.whole)}${keyResult.hours ? '' : ' cards'}` : keyResult.hours ? 'sem capacidade' : 'sem cards';
    return `<td class="okr-cell level-${status}" title="${escape(`${LEVEL_LABEL[status]} · ${detail(keyResult, result)}`)}"><strong>${percent(result.value)}</strong><span class="okr-cell-cards">${base}</span></td>`;
  }
  const head = first => `<thead><tr><th>${first}</th>${KEY_RESULTS.map(keyResult => `<th>${escape(keyResult.title)}<small>meta ${escape(keyResult.goal.split(' ').slice(0, 2).join(' '))}</small></th>`).join('')}</tr></thead>`;

  function renderSprints(sprints) {
    el('okr-sprints').innerHTML = `<div class="okr-scroll"><table class="okr-table">${head('Sprint')}<tbody>${sprints.slice().reverse().map(sprint => {
      const results = compute([sprint]), range = dates(sprint);
      return `<tr><th scope="row">Sprint ${sprint}${inProgress(sprint) ? ' <em>em andamento</em>' : ''}<small>${range ? `${day(range.start)} a ${day(range.end)}` : ''}</small></th>${KEY_RESULTS.map(keyResult => cell(keyResult, results[keyResult.key])).join('')}</tr>`;
    }).join('')}</tbody></table></div>`;
  }

  /** Últimos meses com sprint começando neles, do mais recente para o mais antigo; o mês da sprint atual entra parcial. */
  function months() {
    const groups = new Map();
    for (const sprint of startedSprints()) {
      const key = monthOf(sprint);
      if (!key) continue;
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(sprint);
    }
    return [...groups.entries()].sort(([a], [b]) => b.localeCompare(a)).slice(0, HISTORY_MONTHS).map(([key, sprints]) => ({key, sprints}));
  }

  function renderMonths() {
    const list = months();
    el('okr-months').innerHTML = list.length ? `<div class="okr-scroll"><table class="okr-table">${head('Mês')}<tbody>${list.map(({key, sprints}) => {
      const results = compute(sprints);
      return `<tr><th scope="row">${monthLabel(key)}${sprints.some(inProgress) ? ' <em>parcial</em>' : ''}<small>Sprint${sprints.length > 1 ? 's' : ''} ${sprints.join(', ')}</small></th>${KEY_RESULTS.map(keyResult => cell(keyResult, results[keyResult.key])).join('')}</tr>`;
    }).join('')}</tbody></table></div>` : '<p class="okr-empty">Nenhuma sprint encerrada ainda.</p>';
  }

  // ── Crescimento ──────────────────────────────────────────────────────────────
  const GROWTH_SPRINTS = 12;
  /**
   * Origens dos cards registrados, na ordem de empilhamento (de baixo para cima). Cores validadas para daltonismo e
   * contraste no tema claro e no escuro (as da Torre de controle; no escuro, um passo que cabe na faixa de claridade).
   */
  const ORIGINS = [
    {key: 'planned', label: 'Planejado', light: '#1d6ef5', dark: '#4d8ef7'},
    {key: 'unplanned', label: 'Não planejado', light: '#e8890c', dark: '#d4780c'},
    {key: 'support', label: 'Suporte', light: '#0d9488', dark: '#14a394'},
  ];
  /**
   * Cards registrados na sprint por origem e entregues, com as contas do Dashboard: Suporte = Tipo Suporte; os demais
   * se dividem pela coluna Planejada; entregues = status Implementado. Retirados (cancelado etc.) ficam de fora.
   */
  function growthOf(sprint) {
    const rows = (typeof DATA === 'undefined' ? [] : DATA).filter(row => Number(row.Sprint) === Number(sprint));
    const metrics = typeof metricsOf === 'function' ? metricsOf(rows) : {planned: 0, unplanned: 0, support: 0, done: 0, tot: 0};
    return {sprint, planned: metrics.planned, unplanned: metrics.unplanned, support: metrics.support, total: metrics.tot, done: metrics.done};
  }
  const average = list => list.length ? list.reduce((sum, value) => sum + value, 0) / list.length : null;
  const oneDecimal = value => String(Math.round(value * 10) / 10).replace('.', ',');
  /** Resumo do crescimento: média das últimas 3 sprints encerradas contra as 3 anteriores (ou a última contra a anterior). */
  function growthSummary(series) {
    const closed = series.filter(item => !inProgress(item.sprint));
    const size = closed.length >= 6 ? 3 : closed.length >= 2 ? 1 : 0;
    if (!size) return 'Ainda não há sprints encerradas suficientes para comparar.';
    const recent = closed.slice(-size), before = closed.slice(-size * 2, -size);
    const change = (now, then) => then ? ` (${now >= then ? '+' : '−'}${Math.round(Math.abs(now - then) / then * 100)}%)` : '';
    const [doneNow, doneThen] = [average(recent.map(item => item.done)), average(before.map(item => item.done))];
    const [totalNow, totalThen] = [average(recent.map(item => item.total)), average(before.map(item => item.total))];
    const which = size === 3 ? `Últimas 3 sprints encerradas (${recent.map(item => item.sprint).join(', ')}) contra as 3 anteriores (${before.map(item => item.sprint).join(', ')})`
      : `Sprint ${recent[0].sprint} contra a ${before[0].sprint}`;
    return `${which}: <b>${oneDecimal(doneNow)}</b> entregas por sprint contra ${oneDecimal(doneThen)}${change(doneNow, doneThen)} e <b>${oneDecimal(totalNow)}</b> cards registrados contra ${oneDecimal(totalThen)}${change(totalNow, totalThen)}.`;
  }

  let growthChart = null;
  const isDark = () => document.documentElement.dataset.theme === 'dark';
  const cssVar = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

  function renderGrowth() {
    const series = startedSprints().slice(-GROWTH_SPRINTS).map(growthOf);
    el('okr-growth-summary').innerHTML = series.length ? growthSummary(series) : '';
    el('okr-growth-table').innerHTML = series.length ? `<div class="okr-scroll"><table class="okr-table okr-growth-table"><thead><tr><th>Sprint</th>${ORIGINS.map(origin => `<th><span class="okr-swatch" style="background:${isDark() ? origin.dark : origin.light}"></span>${origin.label}</th>`).join('')}<th>Total registrado</th><th><span class="okr-swatch line"></span>Entregues</th></tr></thead><tbody>
      ${series.slice().reverse().map(item => `<tr><th scope="row">Sprint ${item.sprint}${inProgress(item.sprint) ? ' <em>em andamento</em>' : ''}</th>${ORIGINS.map(origin => `<td>${item[origin.key]}</td>`).join('')}<td><b>${item.total}</b></td><td><b>${item.done}</b> <small>${item.total ? `${Math.round(item.done / item.total * 100)}%` : ''}</small></td></tr>`).join('')}
      </tbody></table></div>` : '';
    const canvas = el('okr-growth-chart');
    if (typeof Chart === 'undefined' || !canvas) return;
    growthChart?.destroy();
    if (!series.length) { growthChart = null; return; }
    const dark = isDark(), surface = cssVar('--card', dark ? '#161c26' : '#ffffff'), ink = cssVar('--text', dark ? '#e6ebf2' : '#0f172a');
    const muted = cssVar('--muted', '#64748b'), grid = cssVar('--border', '#e2e8f0');
    growthChart = new Chart(canvas.getContext('2d'), {
      data: {
        labels: series.map(item => `S${item.sprint}${inProgress(item.sprint) ? '*' : ''}`),
        datasets: [
          {type: 'line', label: 'Entregues', data: series.map(item => item.done), borderColor: ink, backgroundColor: ink, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6,
            pointBorderColor: surface, pointBorderWidth: 2, tension: 0, order: 0},
          ...ORIGINS.map((origin, index) => ({type: 'bar', label: origin.label, data: series.map(item => item[origin.key]), backgroundColor: dark ? origin.dark : origin.light,
            borderColor: surface, borderWidth: {top: 2}, borderSkipped: 'bottom', borderRadius: index === ORIGINS.length - 1 ? {topLeft: 4, topRight: 4} : 0,
            stack: 'cards', maxBarThickness: 36, order: 1})),
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
        scales: {
          x: {stacked: true, grid: {display: false}, ticks: {color: muted}},
          y: {stacked: true, beginAtZero: true, grid: {color: grid}, border: {display: false}, ticks: {color: muted, precision: 0}, title: {display: true, text: 'Cards', color: muted}},
        },
        plugins: {
          legend: {position: 'top', align: 'start', labels: {color: ink, usePointStyle: true, boxWidth: 10}},
          tooltip: {callbacks: {
            title: items => { const item = series[items[0].dataIndex]; return `Sprint ${item.sprint}${inProgress(item.sprint) ? ' (em andamento)' : ''}`; },
            footer: items => { const item = series[items[0].dataIndex]; return `Total registrado: ${item.total} · entregues ${item.total ? Math.round(item.done / item.total * 100) : 0}%`; },
          }},
        },
      },
    });
  }

  // ── Infográfico do período (V1) ──────────────────────────────────────────────
  /** Cores das partes das horas: as mesmas validadas no crescimento (Sustentação = a cor de Suporte). */
  const HOUR_PARTS = {fullstack: {light: '#1d6ef5', dark: '#4d8ef7'}, support: {light: '#0d9488', dark: '#14a394'}, projects: {light: '#e8890c', dark: '#d4780c'}};
  const INFO_CELLS = 60;
  let infoChart = null;

  /**
   * Medidor em meia-rosca (SVG) de um resultado-chave: o arco vai até o valor, na cor da situação, com a marca da meta.
   * Mesma escala da barra: maior é melhor de 0 a 100%; menor é melhor de 0 ao dobro da meta.
   */
  function gauge(keyResult, result) {
    const value = result.value, status = level(keyResult, value);
    const scale = keyResult.better === 'higher' ? 100 : Math.max(keyResult.target * 2, value ?? 0);
    const fill = value === null ? 0 : Math.min(100, value / scale * 100);
    const angle = Math.PI * (1 - keyResult.target / scale);
    const point = radius => `${(100 + radius * Math.cos(angle)).toFixed(1)} ${(100 - radius * Math.sin(angle)).toFixed(1)}`;
    const arc = 'M20 100 A80 80 0 0 1 180 100';
    return `<figure class="okr-gauge level-${status}">
        <svg viewBox="0 0 200 112" role="img" aria-label="${escape(`${keyResult.title}: ${percent(value)}, meta ${keyResult.goal}`)}">
          <path d="${arc}" class="okr-gauge-track" pathLength="100"/>
          <path d="${arc}" class="okr-gauge-value" pathLength="100" stroke-dasharray="${fill.toFixed(1)} 100"/>
          <path d="M${point(64)} L${point(94)}" class="okr-gauge-target"/>
        </svg>
        <strong>${percent(value)}</strong>
        <figcaption><b>${escape(keyResult.title)}</b><span>meta ${escape(keyResult.goal.split(' ').slice(0, 2).join(' '))} · ${LEVEL_LABEL[status]}</span></figcaption>
      </figure>`;
  }

  /** Pictograma da origem dos cards: até 60 quadradinhos, cada um valendo 1 ou mais cards. */
  function pictogram(metrics) {
    const unit = Math.max(1, Math.ceil(metrics.tot / INFO_CELLS));
    const color = origin => isDark() ? origin.dark : origin.light;
    const cells = ORIGINS.flatMap(origin => Array.from({length: Math.round(metrics[origin.key] / unit)}, () => `<i style="background:${color(origin)}"></i>`)).join('');
    return `<div class="okr-picto" role="img" aria-label="${escape(ORIGINS.map(origin => `${origin.label}: ${metrics[origin.key]}`).join(', '))}">${cells}</div>
      <p class="okr-info-note">cada ■ = ${unit} card${unit > 1 ? 's' : ''}</p>
      <ul class="okr-info-legend">${ORIGINS.map(origin => `<li><span class="okr-swatch" style="background:${color(origin)}"></span>${origin.label} <b>${metrics[origin.key]}</b> <small>${metrics.tot ? Math.round(metrics[origin.key] / metrics.tot * 100) : 0}%</small></li>`).join('')}</ul>`;
  }

  /** Destaques do período: mais XP, mais entregas, melhor precisão (3+ entregas) e quem não teve retrabalho. */
  function highlights(sprints) {
    const people = developersOf(sprints).map(dev => ({dev, results: computePerson(sprints, dev), score: scoreOf(sprints, dev.name)}));
    const top = (read, minimum = 1) => {
      const ranked = people.filter(item => (read(item) ?? -1) >= minimum);
      if (!ranked.length) return null;
      const best = Math.max(...ranked.map(read));
      return {value: best, names: ranked.filter(item => read(item) === best).map(item => item.dev.name)};
    };
    const xp = top(item => item.score?.xp ?? null);
    const deliveries = top(item => item.results.productivity.part);
    const precision = top(item => (item.score?.delivered ?? 0) >= 3 ? item.score.precision : null, 0);
    const clean = people.filter(item => item.results.rework.whole > 0 && item.results.rework.part === 0).map(item => item.dev.name);
    const withCards = people.filter(item => item.results.rework.whole > 0).length;
    const card = (icon, title, winner, unit) => `<li><span class="okr-info-icon">${icon}</span><div><small>${title}</small>${winner ? `<b>${escape(winner.names.join(', '))}</b><span>${winner.value}${unit}</span>` : '<b>—</b><span>sem dados no período</span>'}</div></li>`;
    return `<ul class="okr-info-highlights">
        ${card('⭐', 'Mais XP', xp, ' XP')}
        ${card('🚀', 'Mais entregas', deliveries, ' card(s)')}
        ${card('🎯', 'Melhor precisão de estimativa', precision, '% no alvo')}
        <li><span class="okr-info-icon">✨</span><div><small>Sem retrabalho</small><b>${clean.length} de ${withCards} pessoa${withCards === 1 ? '' : 's'}</b><span>${clean.length ? escape(clean.map(name => name.split(' ')[0]).join(', ')) : 'ninguém sem retrabalho'}</span></div></li>
      </ul>`;
  }

  /** Tendência: o período contra o período anterior do mesmo tamanho (entregas, cards registrados e produtividade). */
  function trendLine(sprints, metrics) {
    const list = startedSprints(), first = list.indexOf(sprints[0]);
    const before = first >= sprints.length ? list.slice(first - sprints.length, first) : [];
    if (!before.length) return '<p class="okr-info-trend">📈 Ainda não há um período anterior do mesmo tamanho para comparar.</p>';
    const rows = (typeof DATA === 'undefined' ? [] : DATA).filter(row => before.includes(Number(row.Sprint)));
    const previous = typeof metricsOf === 'function' ? metricsOf(rows) : {done: 0, tot: 0};
    const change = (now, then) => then ? `${now >= then ? '▲ +' : '▼ −'}${Math.round(Math.abs(now - then) / then * 100)}%` : '—';
    const productivityNow = ratio(metrics.done, metrics.tot), productivityThen = ratio(previous.done, previous.tot);
    const points = productivityNow === null || productivityThen === null ? '—' : `${productivityNow >= productivityThen ? '▲ +' : '▼ −'}${String(Math.round(Math.abs(productivityNow - productivityThen) * 10) / 10).replace('.', ',')} p.p.`;
    const label = before.length === 1 ? `Sprint ${before[0]}` : `Sprints ${before[0]} a ${before.at(-1)}`;
    return `<p class="okr-info-trend">📈 Contra o período anterior (${label}):
      <span><b>${metrics.done}</b> entregas contra ${previous.done} <em>${change(metrics.done, previous.done)}</em></span>
      <span><b>${metrics.tot}</b> cards registrados contra ${previous.tot} <em>${change(metrics.tot, previous.tot)}</em></span>
      <span>produtividade <b>${percent(productivityNow)}</b> contra ${percent(productivityThen)} <em>${points}</em></span></p>`;
  }

  function renderInfographic(sprints, results, partial) {
    const box = el('okr-info');
    if (!box) return;
    const rows = (typeof DATA === 'undefined' ? [] : DATA).filter(row => sprints.includes(Number(row.Sprint)));
    const metrics = typeof metricsOf === 'function' ? metricsOf(rows) : {tot: 0, done: 0, rework: 0, planned: 0, unplanned: 0, support: 0};
    const {hit} = summary(results);
    const first = dates(sprints[0]), last = dates(sprints.at(-1));
    const parts = results.backlog.parts || [];
    const registered = results.backlog.part;
    const people = developersOf(sprints).length;
    const fact = (icon, value, label) => `<li><span class="okr-info-icon">${icon}</span><b>${value}</b><small>${label}</small></li>`;
    box.innerHTML = `<header class="okr-info-hero">
        <div><span class="okr-eyebrow">${sprints.length === 1 ? `Sprint ${sprints[0]}` : `Sprints ${sprints[0]} a ${sprints.at(-1)}`}${first && last ? ` · ${day(first.start)} a ${day(last.end)}` : ''}${partial ? ' · parcial' : ''}</span>
          <h3>O time em números</h3><p>${escape(OBJECTIVE)}</p></div>
        <div class="okr-info-score"><strong>${hit}<small>/${KEY_RESULTS.length}</small></strong><span>resultados-chave atingidos${partial ? ' até agora' : ''}</span></div>
      </header>
      <div class="okr-info-gauges">${KEY_RESULTS.map(keyResult => gauge(keyResult, results[keyResult.key])).join('')}</div>
      <ul class="okr-info-facts">
        ${fact('📦', metrics.tot, 'cards registrados')}
        ${fact('✅', metrics.done, 'cards entregues')}
        ${fact('🔁', metrics.rework, 'com retrabalho')}
        ${fact('⏱️', hoursOf(registered), 'horas apontadas')}
        ${fact('👥', people, 'pessoas')}
      </ul>
      <div class="okr-info-split">
        <section><h4>De onde veio o trabalho</h4>${pictogram(metrics)}</section>
        <section><h4>Onde foram as horas</h4>
          <div class="okr-info-donut"><canvas id="okr-info-hours" role="img" aria-label="${escape(parts.map(part => `${part.label}: ${hoursOf(part.part)}`).join(', '))}"></canvas><div class="okr-info-donut-center"><b>${hoursOf(registered)}</b><small>apontadas</small></div></div>
          <ul class="okr-info-legend">${parts.map(part => `<li><span class="okr-swatch" style="background:${isDark() ? HOUR_PARTS[part.kind].dark : HOUR_PARTS[part.kind].light}"></span>${escape(part.label)} <b>${hoursOf(part.part)}</b> <small>${registered ? Math.round(part.part / registered * 100) : 0}% · ${part.whole ? `${percent(part.value)} da capacidade` : 'sem capacidade'}</small></li>`).join('')}</ul>
        </section>
      </div>
      <section class="okr-info-block"><h4>🏆 Destaques do período</h4>${highlights(sprints)}</section>
      ${trendLine(sprints, metrics)}
      <footer class="okr-info-foot">YUV Sprint Metrics · dados do Monday · gerado em ${new Date().toLocaleDateString('pt-BR')}</footer>`;
    infoChart?.destroy(); infoChart = null;
    const canvas = el('okr-info-hours');
    if (typeof Chart === 'undefined' || !canvas || !registered) return;
    const surface = cssVar('--card', isDark() ? '#161c26' : '#ffffff');
    infoChart = new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {labels: parts.map(part => part.label), datasets: [{data: parts.map(part => Math.round(part.part / 6) / 10), backgroundColor: parts.map(part => isDark() ? HOUR_PARTS[part.kind].dark : HOUR_PARTS[part.kind].light), borderColor: surface, borderWidth: 2}]},
      options: {responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: {legend: {display: false}, tooltip: {callbacks: {label: item => `${item.label}: ${hoursOf(parts[item.dataIndex].part)}`}}}},
    });
  }

  /** Imprime só o infográfico (no diálogo do navegador, "Salvar como PDF" gera o arquivo). */
  function printInfographic() {
    const sprints = period();
    const previous = document.title;
    document.title = `Infográfico OKRs - ${sprints.length === 1 ? `Sprint ${sprints[0]}` : `Sprints ${sprints[0]} a ${sprints.at(-1)}`}`;
    document.body.classList.add('okr-printing');
    const done = () => { document.body.classList.remove('okr-printing'); document.title = previous; };
    window.addEventListener('afterprint', done, {once: true});
    window.print();
  }

  function renderMethod() {
    el('okr-method').innerHTML = KEY_RESULTS.map(keyResult => `<div><dt>${escape(keyResult.title)} <small>meta ${escape(keyResult.goal)}</small></dt><dd>${escape(keyResult.method)}</dd></div>`).join('')
      + `<div><dt>Cores</dt><dd>Verde: meta atingida. Amarelo: até ${NEAR} pontos percentuais da meta. Vermelho: fora da meta. Num intervalo ou num mês, tudo do período é somado, como no Dashboard. A sprint em andamento entra com os números parciais.</dd></div>`
      + '<div><dt>Por pessoa</dt><dd>As mesmas contas, só com os cards em que a pessoa é responsável (card com vários responsáveis conta para cada um) e com a capacidade e as horas apontadas dela no Capacity (lá, cards com vários responsáveis ou em várias sprints ficam fora das horas). As pessoas são as mesmas da Daily: quem tem capacidade ou cards no período. O backlog preparado da pessoa se abre em duas partes: a sprint do time dela (Full stack ou Projetos de IA) e a Sustentação. No Full stack, a Sustentação é a parte da reserva do Bombeiro proporcional à capacidade da pessoa e a sprint é o resto; em Projetos não há reserva.</dd></div>';
  }

  function enter(hint = null) {
    state.active = true;
    if (Number.isInteger(hint)) { state.from = hint; state.to = hint; }
    render();
    // A reserva do Bombeiro faz parte da conta do Capacity; quando ela chega, a tela é refeita.
    window.HoursStrip?.loadReserves?.().then(() => render());
  }
  function leave() { state.active = false; }
  function onDataUpdated() { if (state.active) render(); }

  document.addEventListener('DOMContentLoaded', () => {
    if (!el('page-okrs')) return;
    el('okr-from').addEventListener('change', event => { state.from = Number(event.target.value); render(); });
    el('okr-to').addEventListener('change', event => { state.to = Number(event.target.value); render(); });
    el('okr-current').addEventListener('click', () => { state.from = state.to = currentSprint(); render(); });
    el('okr-people').addEventListener('click', event => {
      const person = event.target.closest('[data-okr-person]');
      if (person) { state.person = person.dataset.okrPerson; step(0); return; }
      const move = event.target.closest('[data-okr-step]');
      if (move) step(Number(move.dataset.okrStep));
    });
    // ← e → passam a vez, como na Daily (fora de campos e diálogos).
    document.addEventListener('keydown', event => {
      if (!state.active || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
      if (event.target.closest?.('input, textarea, select, dialog[open]')) return;
      event.preventDefault();
      step(event.key === 'ArrowLeft' ? -1 : 1);
    });
    // Trocar o tema com a tela aberta redesenha o gráfico com as cores do tema novo.
    el('okr-info-print').addEventListener('click', printInfographic);
    new MutationObserver(() => { if (state.active) render(); }).observe(document.documentElement, {attributes: true, attributeFilter: ['data-theme']});
  });

  return {enter, leave, onDataUpdated, compute, computePerson, developersOf, level, months, growthOf, growthSummary, KEY_RESULTS};
})();
