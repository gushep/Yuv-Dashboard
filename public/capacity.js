/* Capacity usa os dados já carregados pelo dashboard; cálculos internos em minutos. */
window.Capacity = (() => {
  const defaultMinutes = () => window.SprintRules.capacityMinutes();
  const state = { sprint: null, userSelectedSprint: false, developer: 'all', expanded: new Set(), overrides: {}, loadedOverrides: false, reserves: {}, defaultReserve: null, error: '', active: false, loading: false };
  const TEAM_ORDER = { fullstack: 0, projects: 1 };
  const el = id => document.getElementById(id);
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const normalized = value => String(value ?? '').trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const people = value => String(value ?? '').split(',').map(name => name.trim()).filter(Boolean);
  const planning = value => {
    const text = normalized(value);
    return text === 'planejada' || text === 'planejado' ? 'planned'
      : text === 'nao planejado' || text === 'nao planejada' ? 'unplanned' : 'unknown';
  };
  function minutes(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return Number.isFinite(value) && value >= 0 ? Math.round(value * 60) : null;
    const text = String(value).trim().toLocaleLowerCase('pt-BR');
    let match = text.match(/^(\d+)\s*h\s*(\d{1,2})?\s*m?$/);
    if (match) return Number(match[1]) * 60 + Number(match[2] || 0);
    match = text.match(/^(\d{1,2})\s*m$/);
    if (match) return Number(match[1]);
    match = text.match(/^(\d+):(\d{2})$/);
    if (match && Number(match[2]) < 60) return Number(match[1]) * 60 + Number(match[2]);
    const decimal = Number(text.replace(',', '.'));
    return Number.isFinite(decimal) && decimal >= 0 ? Math.round(decimal * 60) : null;
  }
  function formatMinutes(value) {
    if (value === null) return '—';
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(Math.round(value));
    const hours = Math.floor(abs / 60), mins = abs % 60;
    return `${sign}${hours}h${mins ? String(mins).padStart(2, '0') : ''}`;
  }
  const formatPct = value => value === null ? '—' : `${Math.round(value)}%`;
  const safeUrl = value => {
    try { const url = new URL(value); return url.protocol === 'https:' && (url.hostname === 'monday.com' || url.hostname.endsWith('.monday.com')) ? url.href : ''; }
    catch { return ''; }
  };
  function todaySaoPaulo(now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-US', {timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(now);
    const part = type => parts.find(item => item.type === type)?.value;
    return `${part('year')}-${part('month')}-${part('day')}`;
  }
  function weekdays(from, to) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from || '') || !/^\d{4}-\d{2}-\d{2}$/.test(to || '') || from > to) return null;
    let count = 0;
    for (let day = Date.parse(`${from}T00:00:00Z`), end = Date.parse(`${to}T00:00:00Z`); day <= end; day += 86400000) {
      const weekday = new Date(day).getUTCDay();
      if (weekday >= 1 && weekday <= 5) count++;
    }
    return count;
  }
  function elapsedMinutes(totalMinutes, dates, today) {
    if (!dates?.start || !dates?.end) return null;
    const all = weekdays(dates.start, dates.end);
    if (!all) return null;
    if (today < dates.start) return 0;
    if (today >= dates.end) return totalMinutes;
    return Math.round(totalMinutes * weekdays(dates.start, today) / all);
  }
  const isDone = status => window.SprintRules.isDone(status);
  function model(allRows, sprint, dates, overrides = {}, today = todaySaoPaulo()) {
    const sprintById = new Map();
    for (const row of allRows) if (row.Id && !row.AsOf) {
      const key = String(row.Id);
      if (!sprintById.has(key)) sprintById.set(key, new Set());
      sprintById.get(key).add(Number(row.Sprint));
    }
    const unique = new Map();
    allRows.forEach((row, index) => {
      if (Number(row.Sprint) === Number(sprint)) unique.set(row.Id ? `${row.Id}|${sprint}` : `snapshot-${index}`, row);
    });
    const groups = new Map(), pending = [], unassigned = [], outside = [];
    for (const row of unique.values()) {
      const named = people(row.Responsavel);
      if (!named.length) { unassigned.push(row); continue; }
      // Só Full stack e Projetos entram nas métricas por pessoa; card só de quem está sem time fica à parte.
      const owners = named.filter(name => window.SprintRules.teamOf(name) !== null);
      if (!owners.length) { outside.push(row); continue; }
      const reasons = [];
      if (owners.length > 1) reasons.push('vários responsáveis; sem apontamentos individuais');
      if (row.Id && sprintById.get(String(row.Id))?.size > 1) reasons.push('card em várias sprints; tempo total não separado por sprint');
      if (planning(row.Planejada) === 'unknown') reasons.push('planejamento não informado');
      if (reasons.length) { pending.push({row, reasons}); continue; }
      const owner = owners[0];
      if (!groups.has(owner)) groups.set(owner, []);
      groups.get(owner).push(row);
    }
    // Quem está classificado conta na capacidade mesmo sem card nesta sprint.
    const known = new Set([...groups.keys()].map(name => window.SprintRules.normalize(name)));
    for (const member of window.SprintRules.teamMembers()) if (!known.has(window.SprintRules.normalize(member.name))) groups.set(member.name, []);
    const developers = [...groups.entries()].map(([name, rows]) => {
      const saved = window.SprintRules.capacityFor(overrides, sprint, name);
      const capacity = saved.minutes;
      const elapsed = elapsedMinutes(capacity, dates, today);
      const result = {name, team: window.SprintRules.teamOf(name), inheritedFrom: saved.inherited ? saved.fromSprint : null, rows, capacity, elapsed, plannedEstimate:0, completedEstimate:0, missingEstimate:0,
        unplannedEstimate:0, supportEstimate:0, otherEstimate:0, missingUnplannedEstimate:0, totalEstimate:0,
        realPlanned:0, realUnplanned:0, realSupport:0, otherUnplanned:0, registered:0, knownReal:0, missingReal:0,
        knownPlanned:0, knownSupport:0, knownOther:0, supportAllEstimate:0, supportAllReal:0};
      for (const row of rows) {
        const category = planning(row.Planejada);
        const estimate = minutes(row.EstH), real = minutes(row.RealH);
        // Suporte de qualquer planejamento: vai para o card do Bombeiro e sai do card do time.
        if (normalized(row.Tipo) === 'suporte') {
          if (estimate !== null && !window.SprintRules.isRemoved(row.Status)) result.supportAllEstimate += estimate;
          if (real !== null) result.supportAllReal += real;
        }
        if (category === 'planned' && !window.SprintRules.isRemoved(row.Status)) {
          if (estimate === null) result.missingEstimate++;
          else { result.plannedEstimate += estimate; if (isDone(row.Status)) result.completedEstimate += estimate; }
        } else if (category === 'unplanned' && !window.SprintRules.isRemoved(row.Status)) {
          if (estimate === null) result.missingUnplannedEstimate++;
          else if (normalized(row.Tipo) === 'suporte') result.supportEstimate += estimate;
          else result.otherEstimate += estimate;
        }
        if (real === null) { result.missingReal++; continue; }
        result.knownReal++;
        result.registered += real;
        if (category === 'planned') { result.realPlanned += real; result.knownPlanned++; }
        else if (normalized(row.Tipo) === 'suporte') { result.realSupport += real; result.knownSupport++; }
        else { result.otherUnplanned += real; result.knownOther++; }
      }
      result.realUnplanned = result.realSupport + result.otherUnplanned;
      result.unplannedEstimate = result.supportEstimate + result.otherEstimate;
      result.totalEstimate = result.plannedEstimate + result.unplannedEstimate;
      result.utilization = result.knownReal && elapsed > 0 ? result.registered / elapsed * 100 : null;
      result.delivery = result.plannedEstimate > 0 ? result.completedEstimate / result.plannedEstimate * 100 : null;
      result.supportImpact = result.registered > 0 ? result.realSupport / result.registered * 100 : null;
      return result;
    }).sort((a,b) => (TEAM_ORDER[a.team] ?? 9) - (TEAM_ORDER[b.team] ?? 9) || a.name.localeCompare(b.name, 'pt-BR'));
    return {developers, pending, unassigned, outside, selectedCount:unique.size};
  }
  /**
   * Composição da capacidade: total (Full stack + Projetos, como sempre), Full stack descontada a reserva do Bombeiro
   * (só depois de classificar os times) e Projetos à parte.
   */
  /**
   * Capacidade e horas de cada time. As horas de Full stack e Projetos não incluem os cards de Suporte, que ficam no
   * card do Bombeiro: assim cada hora conta num lugar só.
   */
  function teamTotals(developers, reserveMinutes) {
    const of = team => developers.filter(dev => dev.team === team);
    const sum = (list, read) => list.reduce((total, dev) => total + read(dev), 0);
    const fullstack = of('fullstack'), projects = of('projects');
    const reserve = window.SprintRules.teamsConfigured() ? reserveMinutes : 0;
    const fullstackCapacity = sum(fullstack, dev => dev.capacity), projectsCapacity = sum(projects, dev => dev.capacity);
    const withoutSupport = list => ({estimate: sum(list, dev => dev.totalEstimate - dev.supportAllEstimate), registered: sum(list, dev => dev.registered - dev.supportAllReal)});
    return {
      total: fullstackCapacity + projectsCapacity, reserve,
      fullstack: {people: fullstack.length, capacity: fullstackCapacity, net: Math.max(0, fullstackCapacity - reserve), ...withoutSupport(fullstack)},
      projects: {people: projects.length, capacity: projectsCapacity, ...withoutSupport(projects)}
    };
  }
  /** Semáforo dos cards (regra única em SprintRules, também usada no Consumo de horas). */
  const capacityLevel = (used, capacity) => window.SprintRules.capacityLevel(used, capacity);
  const LEVEL_TEXT = window.SprintRules.LEVEL_TEXT;
  /** Horas fora dos cards (compartilhados, sem responsável ou só de quem está sem time), sem os de Suporte. */
  function outsideTotals(result) {
    const rows = [...result.pending.map(item => item.row), ...result.unassigned, ...result.outside].filter(row => normalized(row.Tipo) !== 'suporte');
    return {
      estimate: rows.filter(row => !window.SprintRules.isRemoved(row.Status)).reduce((sum, row) => sum + (minutes(row.EstH) ?? 0), 0),
      registered: rows.reduce((sum, row) => sum + (minutes(row.RealH) ?? 0), 0)
    };
  }
  /**
   * Horas de sustentação da sprint, pela mesma regra do Consumo de horas: cards de Tipo Suporte, uma vez por card,
   * de qualquer responsável (inclusive sem responsável ou sem time).
   */
  function supportTotals(rows, sprint) {
    const unique = new Map();
    rows.forEach((row, index) => {
      if (Number(row.Sprint) === Number(sprint) && normalized(row.Tipo) === 'suporte') unique.set(row.Id ? String(row.Id) : `snapshot-${index}`, row);
    });
    let registered = 0, estimate = 0;
    for (const row of unique.values()) {
      registered += minutes(row.RealH) ?? 0;
      if (!window.SprintRules.isRemoved(row.Status)) estimate += minutes(row.EstH) ?? 0;
    }
    return {cards: unique.size, registered, estimate};
  }
  /**
   * Cards de composição em duas linhas com os mesmos cards: em cima o previsto (Tempo Estimado) e embaixo o registrado
   * (Tempo Real), cada um contra a capacidade do card. Cada hora conta num card só: Suporte no Bombeiro, o resto no time
   * de quem é o card; o total soma os três.
   */
  function renderTeams(developers, result) {
    const summary = teamSummary(DATA, state.sprint, result, state.reserves[state.sprint] ?? state.defaultReserve ?? 0);
    el('cap-teams').innerHTML = summary.html;
    return summary.totals;
  }
  /**
   * Os mesmos cards para qualquer tela (Capacity e Consumo de horas): recebe as linhas, a sprint, o resultado do
   * `model` e a reserva do Bombeiro, e devolve o HTML das duas linhas e os totais.
   */
  function summaryFor(rows, sprint, dates, overrides, reserveMinutes) {
    return teamSummary(rows, sprint, model(rows, sprint, dates, overrides), reserveMinutes);
  }
  function teamSummary(rows, sprint, result, reserveMinutes) {
    const developers = result.developers;
    const totals = teamTotals(developers, reserveMinutes);
    const configured = window.SprintRules.teamsConfigured();
    const support = supportTotals(rows, sprint);
    const outside = outsideTotals(result);
    const people = count => `${count} pessoa${count === 1 ? '' : 's'}`;
    const cards = [
      {label: 'Capacidade da sprint', capacity: totals.total, kind: '',
        estimate: totals.fullstack.estimate + support.estimate + totals.projects.estimate, registered: totals.fullstack.registered + support.registered + totals.projects.registered,
        note: `Full stack ${formatMinutes(totals.fullstack.capacity)} (${people(totals.fullstack.people)}) · Projetos ${formatMinutes(totals.projects.capacity)} (${people(totals.projects.people)})`,
        extra: key => outside[key] ? ` · fora da conta: ${formatMinutes(outside[key])} em cards compartilhados, sem responsável ou sem time` : ''},
      {label: 'Full stack para a sprint', capacity: totals.fullstack.net, kind: 'fullstack', estimate: totals.fullstack.estimate, registered: totals.fullstack.registered,
        note: configured ? `Capacidade ${formatMinutes(totals.fullstack.capacity)} − Bombeiro ${formatMinutes(totals.reserve)} · sem os cards de Suporte` : 'Times ainda não classificados nas Configurações: sem desconto do Bombeiro'},
      {label: 'Bombeiro (sustentação)', capacity: reserveMinutes, kind: 'support', estimate: support.estimate, registered: support.registered,
        note: `Reserva da Sprint ${sprint} (tela Consumo de horas) · ${support.cards} card(s) de Suporte de qualquer pessoa`},
      {label: 'Projetos (IA)', capacity: totals.projects.capacity, kind: 'projects', estimate: totals.projects.estimate, registered: totals.projects.registered,
        note: 'Demandas próprias, fora da sprint e da sustentação'}
    ];
    const meter = (card, key) => {
      const used = card[key], balance = card.capacity - used, over = balance < 0;
      const percent = card.capacity > 0 ? used / card.capacity * 100 : null;
      const level = capacityLevel(used, card.capacity);
      // Caixinha no canto superior direito: horas ainda disponíveis (ou o excedente) contra a capacidade do card.
      const available = `<span class="team-card-available" title="${over ? 'Horas acima da capacidade' : 'Horas ainda disponíveis'}">${over ? `Excedido ${formatMinutes(-balance)}` : `Disponível ${formatMinutes(balance)}`}</span>`;
      const notes = [over ? `<b>${formatMinutes(-balance)} acima da capacidade</b>` : '', key === 'estimate' ? card.note : '', card.extra ? card.extra(key).replace(/^ · /, '') : ''].filter(Boolean).join(' · ');
      return `<div class="team-card ${card.kind} level-${level}" title="${escapeHtml(LEVEL_TEXT[level])}"><div class="team-card-head"><span class="team-card-label" title="${card.label}">${card.label}</span>${available}</div>
        <strong class="team-card-value"><span class="team-card-used">${formatMinutes(used)}</span> <b>de ${formatMinutes(card.capacity)}${percent === null ? '' : ` · ${formatPct(percent)}`}</b></strong>
        <span class="team-meter" role="img" aria-label="${escapeHtml(`${card.label}: ${formatMinutes(used)} de ${formatMinutes(card.capacity)}`)}"><span style="width:${Math.min(100, percent ?? (used ? 100 : 0))}%"></span></span>
        ${notes ? `<small title="${escapeHtml(notes.replace(/<[^>]+>/g, ''))}">${notes}</small>` : ''}</div>`;
    };
    const html = [['estimate', 'Previsto', 'horas planejadas · tempo estimado dos cards'], ['registered', 'Registrado', 'horas utilizadas · tempo real apontado']]
      .map(([key, title, hint]) => `<h3 class="team-summary-title">${title} <small>${hint}</small></h3><div class="team-summary-row">${cards.map(card => meter(card, key)).join('')}</div>`).join('');
    return {html, totals, support, outside, cards: cards.map(({label, kind, capacity, estimate, registered}) => ({label, kind, capacity, estimate, registered}))};
  }
  function dateLabel(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('pt-BR',{day:'2-digit',month:'short',year:'numeric',timeZone:'UTC'}).format(new Date(`${value}T12:00:00Z`));
  }
  function badge(text, kind) { return `<span class="cap-badge ${kind}">${escapeHtml(text)}</span>`; }
  function detail(dev) {
    const tr = document.createElement('tr');
    tr.className = 'cap-detail-row';
    const td = document.createElement('td');
    td.className = 'cap-detail-cell';
    td.colSpan = 13;
    const lines = dev.rows.map(task => {
      const category = planning(task.Planejada);
      const name = escapeHtml(task.Nome || 'Sem título');
      const url = safeUrl(task.Url);
      const taskName = url ? `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${name} ↗</a>` : name;
      const real = minutes(task.RealH), estimate = minutes(task.EstH);
      return `<tr><td>${taskName}</td><td>${badge(category === 'planned' ? 'Planejado' : 'Não planejado',category === 'planned' ? 'plan':'unplan')}</td><td>${badge(task.Tipo || 'Não informado',normalized(task.Tipo)==='suporte'?'support':'other')}</td><td>${badge(task.Status || 'Não informado',isDone(task.Status)?'done':'unknown')}</td><td>${estimate === null ? 'Sem estimativa' : formatMinutes(estimate)}</td><td>${real === null ? 'Sem apontamento' : formatMinutes(real)}</td></tr>`;
    }).join('');
    td.innerHTML = `<div class="cap-detail"><h3>${escapeHtml(dev.name)} · itens da sprint</h3><div class="cap-detail-scroll"><table class="cap-detail-table"><thead><tr><th>Tarefa</th><th>Planejamento</th><th>Tipo</th><th>Status</th><th>Horas previstas</th><th>Tempo real</th></tr></thead><tbody>${lines}</tbody><tfoot><tr><td colspan="4">Total dos itens atribuídos</td><td>${formatMinutes(dev.totalEstimate)}${dev.missingEstimate + dev.missingUnplannedEstimate ? ` · ${dev.missingEstimate + dev.missingUnplannedEstimate} sem estimativa` : ''}</td><td>${dev.knownReal ? formatMinutes(dev.registered) : 'Sem apontamento'}</td></tr></tfoot></table></div><p class="cap-detail-summary">Previstas planejadas: ${formatMinutes(dev.plannedEstimate)} · Previstas não planejadas: ${formatMinutes(dev.unplannedEstimate)} (suporte: ${formatMinutes(dev.supportEstimate)}; outros: ${formatMinutes(dev.otherEstimate)}) · Previstas totais: ${formatMinutes(dev.totalEstimate)}</p><p class="cap-detail-summary">Real planejado:${formatMinutes(dev.realPlanned)} · Real não planejado: ${formatMinutes(dev.realUnplanned)} (suporte: ${formatMinutes(dev.realSupport)}; outros: ${formatMinutes(dev.otherUnplanned)}) · Registradas: ${formatMinutes(dev.registered)}${dev.missingReal ? ` · ${dev.missingReal} item(ns) sem apontamento` : ''}</p></div>`;
    tr.append(td);
    return tr;
  }
  function percentBar(value, type) {
    if (value === null) return '<span title="Sem denominador ou sem apontamentos suficientes">—</span>';
    const fill = type === 'delivery' ? 'cap-delivery' : value > 100 ? 'cap-util-high' : value === 100 ? 'cap-util-full' : 'cap-util-low';
    const label = type === 'delivery' ? 'cap-blue' : value > 100 ? 'cap-over' : '';
    return `<div class="cap-meter"><span class="cap-track"><span class="cap-fill ${fill}" style="width:${Math.max(0,Math.min(100,value))}%"></span></span><span class="cap-percent ${label}">${formatPct(value)}</span></div>`;
  }
  function renderExtra(id, title, items, withReason = false) {
    const node = el(id);
    node.hidden = !items.length;
    if (!items.length) return;
    const list = items.map(item => {
      const row = withReason ? item.row : item;
      const why = withReason ? ` — ${item.reasons.join('; ')}` : '';
      const estimate = minutes(row.EstH), real = minutes(row.RealH);
      return `<li>${escapeHtml(row.Nome || 'Sem título')} (${escapeHtml(row.Responsavel || 'sem responsável')})${escapeHtml(why)} · Estimativa: ${estimate === null ? 'sem estimativa' : formatMinutes(estimate)} · Real: ${real === null ? 'sem apontamento' : formatMinutes(real)}</li>`;
    }).join('');
    node.innerHTML = `<details><summary>${title} · ${items.length} item(ns)</summary><ul>${list}</ul></details>`;
  }
  /** Ritmo do time: horas registradas por dia útil projetadas até o fim da sprint, contra a capacidade somada. */
  function teamPaceText(developers, dates, today = todaySaoPaulo()) {
    const tracked = developers.filter(dev => dev.knownReal);
    if (!tracked.length) return 'Ritmo do time: sem horas registradas.';
    const registered = tracked.reduce((sum, dev) => sum + dev.registered, 0);
    const capacity = tracked.reduce((sum, dev) => sum + dev.capacity, 0);
    const pace = window.SprintRules.pace(registered, dates, today, capacity);
    if (pace.status === 'unavailable') return 'Ritmo do time: datas da sprint indisponíveis.';
    if (pace.status === 'not-started') return 'Ritmo do time: a sprint ainda não começou.';
    if (pace.status === 'finished') return `Ritmo do time: sprint encerrada com ${formatMinutes(registered)} registradas (${formatPct(capacity ? registered / capacity * 100 : null)} da capacidade).`;
    return `Ritmo do time: ${formatMinutes(registered)} registradas em ${pace.elapsedDays} de ${pace.totalDays} dias úteis; mantido o ritmo, ${formatMinutes(pace.projected)} ao fim da sprint (${formatPct(pace.projectedPercent)} da capacidade de ${formatMinutes(capacity)}).`;
  }
  function render() {
    if (!el('page-capacity')) return;
    const sprints = [...new Set(DATA.map(row => Number(row.Sprint)).filter(number => Number.isInteger(number) && number > 0))].sort((a,b)=>b-a);
    const sprintSelect = el('cap-sprint');
    if (!sprints.includes(state.sprint)) state.sprint = sprints[0] ?? null;
    sprintSelect.replaceChildren(...sprints.map(number => new Option(`Sprint ${number}`,String(number))));
    if (state.sprint !== null) { sprintSelect.value = String(state.sprint); window.rememberScreenSprint?.('capacity', state.sprint); }
    const dates = window.MONDAY_SPRINT_DATES?.[state.sprint] || null;
    el('cap-dates').textContent = dates ? `${dateLabel(dates.start)} – ${dateLabel(dates.end)}` : 'Datas da sprint não disponíveis';
    const result = state.sprint === null ? {developers:[],pending:[],unassigned:[],outside:[]} : model(DATA,state.sprint,dates,state.overrides);
    const select = el('cap-developer');
    const names = result.developers.map(dev => dev.name);
    const teamFilters = ['fullstack','projects'].map(team => [`team:${team}`, `Só ${window.SprintRules.teamLabel(team)}`]);
    if (state.developer !== 'all' && !names.includes(state.developer) && !teamFilters.some(([value]) => value === state.developer)) state.developer = 'all';
    select.replaceChildren(new Option('Todos os desenvolvedores','all'),...teamFilters.map(([value,label]) => new Option(label,value)),...names.map(name => new Option(name,name)));
    select.value = state.developer;
    const shown = state.developer === 'all' ? result.developers
      : state.developer.startsWith('team:') ? result.developers.filter(dev => `team:${dev.team}` === state.developer)
      : result.developers.filter(dev => dev.name === state.developer);
    const teamTotalsResult = renderTeams(result.developers, result);
    const canEdit = Boolean(window.CAN_MANAGE_CAPACITY);
    const tbody = el('cap-body');
    tbody.replaceChildren();
    let currentTeam;
    for (const [index, dev] of shown.entries()) {
      if (dev.team !== currentTeam) {
        currentTeam = dev.team;
        const group = teamTotalsResult[dev.team];
        const head = document.createElement('tr');
        head.className = `team-group-row ${dev.team}`;
        head.innerHTML = `<td colspan="13"><span class="team-badge ${dev.team}">${escapeHtml(window.SprintRules.teamLabel(dev.team))}</span> ${group.people} pessoa(s) · capacidade ${formatMinutes(group.capacity)}${dev.team === 'fullstack' && teamTotalsResult.reserve ? ` · −${formatMinutes(teamTotalsResult.reserve)} Bombeiro = ${formatMinutes(group.net)} para a sprint` : ''}${dev.team === 'projects' ? ' · não atende sustentação nem itens da sprint' : ''}</td>`;
        tbody.append(head);
      }
      const tr = document.createElement('tr');
      const initials = dev.name.split(/\s+/).map(part => part[0]).slice(0,2).join('').toUpperCase();
      const balanceFinal = dev.capacity-dev.totalEstimate, balanceNow = dev.capacity-dev.registered;
      const missingTotal = dev.missingEstimate + dev.missingUnplannedEstimate;
      const missingNote = count => count ? `<br><small title="Itens sem estimativa ficam fora da soma">${count} sem estimativa</small>` : '';
      const estimateParts = [dev.supportEstimate ? `Suporte ${formatMinutes(dev.supportEstimate)}` : '', dev.otherEstimate ? `Outros ${formatMinutes(dev.otherEstimate)}` : ''].filter(Boolean).join(' · ');
      const unplannedEstimateCell = `${formatMinutes(dev.unplannedEstimate)}${estimateParts ? `<br><small>${estimateParts}</small>` : ''}${missingNote(dev.missingUnplannedEstimate)}`;
      const realTip = `Real planejado: ${formatMinutes(dev.realPlanned)} · Real não planejado: ${formatMinutes(dev.realUnplanned)} (suporte: ${formatMinutes(dev.realSupport)}; outros: ${formatMinutes(dev.otherUnplanned)})${dev.missingReal ? ` · ${dev.missingReal} sem apontamento` : ''}`;
      const unplannedParts = [dev.knownSupport ? `Suporte ${formatMinutes(dev.realSupport)}` : '',dev.knownOther ? `Outros ${formatMinutes(dev.otherUnplanned)}` : ''].filter(Boolean).join(' · ');
      const unplannedCell = dev.knownSupport || dev.knownOther ? `${formatMinutes(dev.realUnplanned)}<br><small>${unplannedParts}</small>` : '—';
      const utilTip = `Registradas: ${formatMinutes(dev.registered)} ÷ capacidade até hoje: ${formatMinutes(dev.elapsed)}. ${realTip}. Segunda a sexta; hoje conta como dia útil completo; sem feriados configurados.`;
      const deliveryTip = `Estimativas concluídas: ${formatMinutes(dev.completedEstimate)} ÷ estimativas planejadas: ${formatMinutes(dev.plannedEstimate)}. ${dev.missingEstimate} item(ns) planejado(s) sem estimativa.${dev.plannedEstimate ? '' : ' Denominador zero: indicador indisponível.'}`;
      tr.innerHTML = `<td><button class="cap-dev-button" type="button" aria-expanded="${state.expanded.has(dev.name)}"><span class="cap-avatar cap-avatar-${index%4}">${escapeHtml(initials)}</span><span class="cap-dev-name" title="${escapeHtml(dev.name)}">${escapeHtml(dev.name)}</span><span class="cap-chevron">${state.expanded.has(dev.name)?'⌄':'›'}</span></button><span class="team-badge ${dev.team}">${escapeHtml(window.SprintRules.teamLabel(dev.team))}</span></td><td><input class="cap-hours-input" type="number" min="0" max="200" step="0.25" value="${dev.capacity/60}"${canEdit ? '' : ' disabled'} aria-label="Capacidade em horas de ${escapeHtml(dev.name)} na sprint ${state.sprint}" title="${canEdit ? 'Horas decimais: 1,5 equivale a 1h30. Vale desta sprint em diante, até um novo ajuste.' : 'Somente o Maestro Scrum altera as horas.'}">h${dev.inheritedFrom ? `<small class="cap-inherited" title="Sem ajuste nesta sprint: vale o último valor salvo">herdado da Sprint ${dev.inheritedFrom}</small>` : ''}</td><td class="cap-group-start">${formatMinutes(dev.plannedEstimate)}${missingNote(dev.missingEstimate)}</td><td>${unplannedEstimateCell}</td><td class="cap-total" title="${escapeHtml(`Previstas planejadas ${formatMinutes(dev.plannedEstimate)} + não planejadas ${formatMinutes(dev.unplannedEstimate)}`)}">${formatMinutes(dev.totalEstimate)}${missingNote(missingTotal)}</td><td class="cap-balance ${balanceFinal<0?'cap-negative':''}" title="${escapeHtml(`Disponíveis ${formatMinutes(dev.capacity)} − previstas totais ${formatMinutes(dev.totalEstimate)}`)}">${formatMinutes(balanceFinal)}</td><td class="cap-group-start">${dev.knownPlanned?formatMinutes(dev.realPlanned):'—'}</td><td>${unplannedCell}</td><td class="cap-total" title="${escapeHtml(realTip)}">${dev.knownReal?formatMinutes(dev.registered):'—'}</td><td class="cap-balance ${balanceNow<0?'cap-negative':''}" title="${escapeHtml(`Disponíveis ${formatMinutes(dev.capacity)} − registradas ${formatMinutes(dev.registered)}`)}">${dev.knownReal?formatMinutes(balanceNow):'—'}</td><td class="cap-group-start" title="${escapeHtml(utilTip)}">${percentBar(dev.utilization,'util')}</td><td title="${escapeHtml(deliveryTip)}">${percentBar(dev.delivery,'delivery')}${dev.missingEstimate ? `<small>${dev.missingEstimate} sem estimativa</small>` : ''}</td><td title="${escapeHtml(dev.registered ? `${formatMinutes(dev.realSupport)} ÷ ${formatMinutes(dev.registered)}` : 'Sem horas registradas: denominador zero')}"><span class="cap-impact">${formatPct(dev.supportImpact)}</span></td>`;
      tr.querySelector('.cap-dev-button').addEventListener('click', () => {
        state.expanded.has(dev.name) ? state.expanded.delete(dev.name) : state.expanded.add(dev.name);
        render();
      });
      tr.querySelector('.cap-hours-input').addEventListener('change', event => saveCapacity(dev.name,event.target.value));
      tbody.append(tr);
      if (state.expanded.has(dev.name)) tbody.append(detail(dev));
    }
    if (!shown.length) {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td colspan="13" class="cap-empty">${state.sprint === null ? 'Nenhuma sprint disponível.' : 'Nenhum desenvolvedor com itens atribuídos nesta sprint.'}</td>`;
      tbody.append(tr);
    }
    renderExtra('cap-pending','Pendências de atribuição',result.pending,true);
    renderExtra('cap-unassigned','Sem responsável',result.unassigned);
    renderExtra('cap-outside','Cards de pessoas sem time (fora das métricas por pessoa)',result.outside);
    const standardElapsed = elapsedMinutes(defaultMinutes(),dates,todaySaoPaulo());
    el('cap-reference').textContent = `Referência: ${formatMinutes(defaultMinutes())} por dev · até hoje: ${formatMinutes(standardElapsed)}`;
    el('cap-pace').textContent = teamPaceText(result.developers, dates);
    el('cap-calendar').textContent = 'Dias úteis: segunda a sexta; hoje completo';
    el('cap-calendar').title = 'Capacidade até hoje = capacidade total × dias úteis transcorridos ÷ dias úteis totais. Inclui o dia atual completo no fuso America/Sao_Paulo; não há feriados configurados.';
    const updated = window.MONDAY_LAST_SYNC ? `Última atualização: ${new Date(window.MONDAY_LAST_SYNC).toLocaleString('pt-BR')}` : 'Base incorporada disponível';
    el('cap-last-sync').textContent = state.loading ? `Buscando dados atuais do Monday... ${updated}` : updated;
    const warnings = [];
    if (!dates) warnings.push('Datas da sprint indisponíveis: utilização até hoje não pode ser calculada.');
    if (!window.MONDAY_LIVE_SPRINTS?.has(state.sprint)) warnings.push('Esta sprint usa a base incorporada; ela não distingue ausência de tempo de zero nem contém IDs para auditar duplicações.');
    else warnings.push('Tempo Real é uma coluna numérica de total do card, sem histórico de apontamentos por pessoa ou sprint. As horas exibidas são provisórias; cards compartilhados ou em várias sprints estão fora dos totais individuais.');
    if (result.pending.length) warnings.push(`${result.pending.length} card(s) em Pendências de atribuição, fora dos totais individuais.`);
    if (result.unassigned.length) warnings.push(`${result.unassigned.length} card(s) sem responsável, fora dos totais individuais.`);
    if (result.outside.length) warnings.push(`${result.outside.length} card(s) só de pessoas sem time (Configurações), fora dos totais individuais.`);
    const missing = result.developers.reduce((sum,dev)=>sum+dev.missingEstimate,0);
    if (missing) warnings.push(`${missing} item(ns) planejado(s) sem estimativa: entrega do planejado considera apenas estimativas informadas.`);
    el('cap-warning').textContent = warnings.join(' ');
    el('cap-quality').hidden = !warnings.length;
    el('cap-warning-summary').textContent = `Qualidade dos dados: ${window.MONDAY_LIVE_SPRINTS?.has(state.sprint) ? 'Tempo Real por card' : 'base incorporada'}${result.pending.length ? ` · ${result.pending.length} pendência(s)` : ''}${result.unassigned.length ? ` · ${result.unassigned.length} sem responsável` : ''}${missing ? ` · ${missing} sem estimativa` : ''} · ver detalhes`;
    el('cap-error').textContent = state.error;
    el('cap-error').hidden = !state.error;
  }
  async function loadOverrides() {
    if (state.loadedOverrides || location.protocol === 'file:') return;
    try {
      // A reserva de suporte (Bombeiro) de cada sprint sai da capacidade full stack.
      const [result, reserves] = await Promise.all([mondayRequest('/api/capacity'), mondayRequest('/api/support-reserves')]);
      state.overrides = result.overrides || {};
      state.reserves = reserves.reserves || {};
      state.defaultReserve = reserves.defaultMinutes ?? null;
      state.loadedOverrides = true;
      render();
    } catch (error) { state.error = `Não foi possível carregar as capacidades salvas: ${error.message}`; render(); }
  }
  async function saveCapacity(developer, hoursText) {
    if (!window.CAN_MANAGE_CAPACITY) { state.error = 'Somente o Maestro Scrum altera as horas.'; render(); return; }
    const hours = Number(String(hoursText).replace(',','.'));
    if (!Number.isFinite(hours) || hours < 0 || hours > 200) { state.error = 'Informe uma capacidade entre 0h e 200h.'; render(); return; }
    const value = Math.round(hours * 60);
    try {
      const result = await mondayRequest('/api/capacity',{sprint:state.sprint,developer,minutes:value});
      state.overrides = result.overrides || {};
      window.SprintRules.applySettings({capacityOverrides: state.overrides});
      state.error = '';
      render();
    } catch (error) { state.error = `Não foi possível salvar a capacidade: ${error.message}`; render(); }
  }
  function enter(sprintHint = null) {
    state.active = true;
    document.body.classList.add('capacity-view');
    if (Number.isInteger(sprintHint)) { state.sprint = sprintHint; state.userSelectedSprint = true; }
    if (!window.MONDAY_LAST_SYNC && location.protocol !== 'file:') state.loading = true;
    render();
    // Recarrega a cada entrada: a reserva pode ter mudado na tela Consumo de horas.
    state.loadedOverrides = false;
    loadOverrides();
    if (typeof mondayReady !== 'undefined' && mondayReady && !window.MONDAY_LAST_SYNC) syncMonday(false);
  }
  function leave() {
    state.active = false;
    document.body.classList.remove('capacity-view');
  }
  function onDataUpdated(result) {
    window.MONDAY_SPRINT_DATES = Object.fromEntries((result.sprints || []).map(item => [Number(item.number),item]));
    window.MONDAY_LAST_SYNC = result.syncedAt;
    window.MONDAY_LIVE_SPRINTS = new Set(result.rows.map(row => Number(row.Sprint)));
    state.loading = false;
    if (!state.userSelectedSprint) state.sprint = null;
    state.error = '';
    if (state.active) render();
  }
  function onLoading() { state.loading = true; if (state.active) render(); }
  function onError(message) { state.loading = false; state.error = `Falha ao atualizar os dados: ${message}. Exibindo os últimos dados disponíveis.`; if (state.active) render(); }
  document.addEventListener('DOMContentLoaded', () => {
    if (!el('cap-sprint')) return;
    el('cap-sprint').addEventListener('change', event => { state.sprint = Number(event.target.value); state.userSelectedSprint = true; state.expanded.clear(); render(); });
    el('cap-developer').addEventListener('change', event => { state.developer = event.target.value; render(); });
  });
  return {enter,leave,render,onLoading,onDataUpdated,onError,model,summaryFor,teamTotals,supportTotals,outsideTotals,capacityLevel,minutes,formatMinutes,percentBar,weekdays,elapsedMinutes,todaySaoPaulo};
})();
