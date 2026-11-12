/*
 * Daily Scrum (Daily Hub), pessoa por pessoa. Cards, status e horas vêm do Monday pela base sincronizada (DATA), com
 * as mesmas contas do Capacity; as responsabilidades que saem da Daily vêm da API /api/daily. O Kanban é só leitura:
 * concluir uma responsabilidade nunca muda o status do card.
 *
 * Regras de tendência e gamificação (também explicadas no tour e nas dicas da tela):
 * - Card aberto: real ÷ estimado; a partir de 80% "perto do limite", acima de 100% "estourou".
 * - Card finalizado: até 80% do estimado "adiantado", até 100% "no alvo", acima "acima do estimado".
 * - Tendência (pessoa e sprint): horas que faltam nos cards abertos (não iniciado = estimado; em andamento =
 *   estimado − real) contra a capacidade que resta (capacidade × dias úteis restantes ÷ dias úteis da sprint):
 *   até 90% no prazo, até 100% apertado, acima risco de atraso.
 * - XP: cada entrega vale 10; +5 no alvo; +8 adiantada.
 */
window.Daily = (() => {
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const normalize = value => window.SprintRules.normalize(value);
  const hours = minutes => window.Capacity.formatMinutes(Math.max(0, Math.round(minutes)));
  const minutesOf = value => window.Capacity.minutes(value);
  const day = value => value ? value.slice(0, 10).split('-').reverse().slice(0, 2).join('/') : '—';
  const dateTime = value => value ? new Date(value).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo'}) : '—';
  const state = {active: false, data: null, sprint: null, date: null, dev: null, filter: 'pending', busy: false, error: '', request: 0, doneAction: null, previewTimer: null, people: [], retroDone: null};

  /** Colunas do Kanban na ordem da conversa da Daily: o que trava primeiro, depois o que anda e o que vem. */
  const COLUMNS = [['blocked', '🔴 Impedidos'], ['progress', '🔵 Em andamento'], ['pending', '⏭️ Próximos'], ['done', '✅ Finalizados']];
  const PENDING_EXTRA = ['pendente', 'refinar', 'falta escopo', 'escopo tecnico', 'prox. sprint'];
  /**
   * Status real do Monday → coluna. Finalizado é só Implementado (QA e Aguardando Deploy seguem em andamento);
   * Pendente Samax conta como impedido; cancelado, descontinuado e despriorizado ficam fora.
   */
  function column(status) {
    const value = normalize(status);
    if (window.SprintRules.isRemoved(status)) return null;
    if (window.SprintRules.isBlocked(status) || value === 'pendente samax') return 'blocked';
    if (window.SprintRules.isDone(status)) return 'done';
    if (window.SprintRules.isNotStarted(status) || PENDING_EXTRA.includes(value)) return 'pending';
    return 'progress';
  }

  // ── Regras de horas, tendência e gamificação ─────────────────────────────────
  /** Indicador de horas de um card: real × estimado e o selo da entrega ou do risco. */
  function cardHours(row) {
    const estimate = minutesOf(row.EstH), real = minutesOf(row.RealH) ?? 0, done = column(row.Status) === 'done';
    if (estimate === null || estimate <= 0) return {estimate, real, ratio: null, level: 'none', badge: 'sem estimativa', points: done ? 10 : 0};
    const ratio = real / estimate;
    if (done) {
      if (ratio <= .8) return {estimate, real, ratio, level: 'great', badge: '⚡ adiantado', points: 18};
      if (ratio <= 1) return {estimate, real, ratio, level: 'good', badge: '🎯 no alvo', points: 15};
      return {estimate, real, ratio, level: 'over', badge: `🐢 +${Math.round((ratio - 1) * 100)}% do estimado`, points: 10};
    }
    if (ratio > 1) return {estimate, real, ratio, level: 'over', badge: `🔥 estourou +${hours(real - estimate)}`, points: 0};
    if (ratio >= .8) return {estimate, real, ratio, level: 'warn', badge: '⚠️ perto do limite', points: 0};
    return {estimate, real, ratio, level: 'ok', badge: '', points: 0};
  }
  /** Horas que ainda faltam num card aberto (não iniciado = estimado inteiro; em andamento = estimado − real). */
  function remainingOf(row) {
    const key = column(row.Status);
    if (key === 'done' || key === null) return 0;
    const estimate = minutesOf(row.EstH);
    if (estimate === null) return 0;
    return key === 'pending' ? estimate : Math.max(0, estimate - (minutesOf(row.RealH) ?? 0));
  }
  /** Tendência: o que falta contra a capacidade que resta na sprint. */
  function trend(remaining, capacity, dates, today) {
    const days = dates ? window.SprintRules.workingDays(dates.start, dates.end) : [];
    const left = days.filter(date => date >= today).length;
    const available = days.length ? capacity * left / days.length : 0;
    const ratio = available > 0 ? remaining / available : (remaining > 0 ? Infinity : 0);
    const level = ratio <= .9 ? 'green' : ratio <= 1 ? 'yellow' : 'red';
    const label = {green: '🟢 No prazo', yellow: '🟡 Apertado', red: '🔴 Risco de atraso'}[level];
    return {level, label, remaining, available, left, total: days.length,
      detail: `faltam ${hours(remaining)} nos cards abertos para ${hours(available)} de capacidade nos ${left} dia(s) útil(eis) restantes`};
  }
  /** Placar de entregas de um conjunto de cards: entregues, no alvo, adiantadas e XP. */
  function score(rows) {
    const delivered = rows.filter(row => column(row.Status) === 'done').map(cardHours);
    const onTarget = delivered.filter(item => item.level === 'good' || item.level === 'great').length;
    return {delivered: delivered.length, onTarget, early: delivered.filter(item => item.level === 'great').length,
      xp: delivered.reduce((sum, item) => sum + item.points, 0), precision: delivered.length ? Math.round(onTarget / delivered.length * 100) : null};
  }

  const people = value => String(value ?? '').split(',').map(name => name.trim()).filter(Boolean);
  /** Cards da sprint (um por Id), sem os retirados do escopo. */
  function sprintCards(sprint) {
    const unique = new Map();
    for (const row of (typeof DATA === 'undefined' ? [] : DATA)) {
      if (Number(row.Sprint) !== Number(sprint) || row.AsOf) continue;
      const key = row.Id ? String(row.Id) : `${row.Nome}|${unique.size}`;
      if (!unique.has(key)) unique.set(key, row);
    }
    return [...unique.values()].filter(row => column(row.Status) !== null);
  }
  const ownedBy = (row, name) => people(row.Responsavel).some(person => normalize(person) === normalize(name));
  const initials = name => name.split(/\s+/).filter(Boolean).map(part => part[0]).slice(0, 2).join('').toUpperCase();

  // ── API ──────────────────────────────────────────────────────────────────────
  async function load() {
    const request = ++state.request;
    try {
      const url = new URL(el('page-daily-scrum').dataset.url, location.href);
      if (state.sprint) url.searchParams.set('sprint', state.sprint);
      if (state.date) url.searchParams.set('date', state.date);
      const data = await mondayRequest(url.pathname + url.search);
      if (request !== state.request) return;
      state.data = data; state.sprint = data.sprint; state.date = data.date; state.error = '';
      window.rememberScreenSprint?.('daily-scrum', data.sprint);
    } catch (error) {
      if (request === state.request) state.error = error.message;
    }
    render();
  }

  async function act(path, body) {
    if (state.busy) return false;
    state.busy = true; render();
    let ok = true;
    try {
      const base = el('page-daily-scrum').dataset.url.replace(/\/$/, '');
      state.data = await mondayRequest(`${base}${path}`, {...body, date: state.date});
      state.error = '';
    } catch (error) {
      state.error = error.message; ok = false;
    } finally {
      state.busy = false; state.request++;
      render();
    }
    return ok;
  }

  // ── Tela ─────────────────────────────────────────────────────────────────────
  function render() {
    if (!state.active) return;
    el('daily-error').textContent = state.error;
    el('daily-error').hidden = !state.error;
    const data = state.data;
    if (!data) { el('daily-macro').innerHTML = '<p class="pp-muted">Carregando a Daily...</p>'; return; }
    renderFilters(data);
    const dates = window.MONDAY_SPRINT_DATES?.[data.sprint] || null;
    const model = window.Capacity.model(typeof DATA === 'undefined' ? [] : DATA, data.sprint, dates, window.SprintRules.settings.capacityOverrides || {});
    const cards = sprintCards(data.sprint);
    const developers = model.developers.filter(dev => dev.capacity > 0 || cards.some(row => ownedBy(row, dev.name)));
    state.people = developers.map(dev => dev.name);
    if (!state.people.includes(state.dev)) state.dev = state.people[0] ?? null;
    renderMacro(data, dates, cards, developers);
    renderFaces(developers, cards);
    const dev = developers.find(item => item.name === state.dev);
    const mine = dev ? cards.filter(row => ownedBy(row, dev.name)) : [];
    renderPerson(dev, mine, dates, data);
    renderBoard(mine, data);
    renderTodo(data, cards);
    renderRetroActions(data);
  }

  /** Ações abertas das retrospectivas (de qualquer sprint), abaixo das responsabilidades; concluir pede o resultado. */
  function renderRetroActions(data) {
    const actions = data.retroActions || [];
    const late = actions.filter(action => action.late).length;
    el('daily-retro-count').textContent = actions.length ? `${actions.length} aberta(s)${late ? ` · ${late} atrasada(s)` : ''}` : '';
    el('daily-retro-actions').innerHTML = actions.length ? `<ul>${actions.map(action => {
      const finishing = state.retroDone === action.id;
      const blocked = `Só ${action.responsible ? `o responsável (${action.responsible.name})` : 'o responsável'} e o facilitador da retro${action.retro.facilitator ? ` (${action.retro.facilitator})` : ''} podem concluir`;
      return `<li class="daily-action daily-retro-action ${action.late ? 'late' : ''}">
        <div class="daily-action-main">
          <strong>${escape(action.description)}</strong>
          <span class="daily-action-meta">
            <span class="daily-tag">Retro da ${escape(action.retro.sprintName || `Sprint ${action.retro.sprint}`)}</span>
            <span>👤 ${escape(action.responsible?.name || 'Sem responsável')}</span>
            <span class="${action.late ? 'daily-late' : ''}">📅 ${action.dueDate ? day(action.dueDate) : 'sem prazo'}${action.late ? ` · ${action.lateDays} dia(s) atrasada` : ''}</span>
            ${action.signal ? `<span>🎯 ${escape(action.signal)}</span>` : ''}
          </span>
          ${finishing ? `<div class="daily-retro-form">
            <label>Resultado <textarea id="daily-retro-result" rows="2" maxlength="4000" placeholder="O que foi feito"></textarea></label>
            <label class="daily-publish-check"><input type="checkbox" id="daily-retro-signal"> O sinal de resultado foi atingido</label>
            <div class="daily-action-buttons row"><button type="button" class="pp-primary" data-retro-confirm="${action.id}" ${state.busy ? 'disabled' : ''}>Concluir</button><button type="button" class="pp-ghost" data-retro-cancel>Cancelar</button></div>
          </div>` : ''}
        </div>
        <div class="daily-action-buttons">${finishing ? '' : `<button type="button" class="pp-primary" data-retro-done="${action.id}" ${action.canManage && !state.busy ? '' : `disabled title="${escape(blocked)}"`}>Concluir</button>`}</div>
      </li>`;
    }).join('')}</ul>` : '<p class="daily-empty">Nenhuma ação de retrospectiva aberta.</p>';
  }

  async function completeRetroAction(id) {
    const result = (el('daily-retro-result')?.value || '').trim();
    if (!result) { state.error = 'Descreva o resultado para concluir a ação da retro.'; render(); el('daily-retro-result')?.focus(); return; }
    if (await act(`/retro-actions/${id}/complete`, {sprint: state.sprint, result, signal_reached: el('daily-retro-signal').checked})) state.retroDone = null;
    render();
  }

  function renderFilters(data) {
    el('daily-sprint').replaceChildren(...data.sprints.map(sprint => new Option(sprint.name, String(sprint.number))));
    el('daily-sprint').value = String(data.sprint);
    const dates = [...new Set([...data.dailies.map(daily => daily.date), data.today, data.date])].filter(Boolean).sort().reverse();
    el('daily-date').replaceChildren(...dates.map(date => new Option(date === data.today ? `Hoje (${day(date)})` : `${day(date)}${data.dailies.some(daily => daily.date === date) ? '' : ' (sem registro)'}`, date)));
    el('daily-date').value = data.date;
    el('daily-subtitle').textContent = `Daily de ${day(data.date)}${data.date === data.today ? ' · hoje' : ' · consulta'} · ${data.sprints.find(sprint => sprint.number === data.sprint)?.name || `Sprint ${data.sprint}`}`;
  }

  /** Indicador macro: objetivo, cards por situação, horas do time, tendência da sprint e XP das entregas. */
  function renderMacro(data, dates, cards, developers) {
    const count = key => cards.filter(row => column(row.Status) === key).length;
    const capacity = developers.reduce((sum, dev) => sum + dev.capacity, 0);
    const planned = developers.reduce((sum, dev) => sum + dev.totalEstimate, 0);
    const registered = developers.reduce((sum, dev) => sum + dev.registered, 0);
    const sprintTrend = trend(cards.reduce((sum, row) => sum + remainingOf(row), 0), capacity, dates, data.today);
    const sprintScore = score(cards);
    const done = count('done');
    el('daily-macro').innerHTML = `
      <div class="daily-goal"><strong>🎯 Objetivo da Sprint</strong><p>${data.goal ? escape(data.goal) : '<em>Sem objetivo registrado. Ele é informado ao encerrar a Sprint Planning.</em>'}</p></div>
      <div class="daily-macro-grid">
        <div class="daily-stat red"><span>🔴 Impedidos</span><strong>${count('blocked')}</strong></div>
        <div class="daily-stat blue"><span>🔵 Em andamento</span><strong>${count('progress')}</strong></div>
        <div class="daily-stat"><span>⏭️ Próximos</span><strong>${count('pending')}</strong></div>
        <div class="daily-stat green"><span>✅ Finalizados</span><strong>${done} <small>de ${cards.length}</small></strong>
          <span class="daily-bar"><span style="width:${cards.length ? done / cards.length * 100 : 0}%"></span></span></div>
        <div class="daily-stat"><span>⏱️ Horas do time</span><strong>${hours(planned)} <small>planejadas de ${hours(capacity)}</small></strong><small>${hours(registered)} registradas</small></div>
        <div class="daily-stat trend-${sprintTrend.level}" title="${escape(sprintTrend.detail)}"><span>📈 Tendência da sprint</span><strong>${sprintTrend.label}</strong><small>${escape(sprintTrend.detail)}</small></div>
        <div class="daily-stat xp" title="Cada entrega vale 10 XP; +5 no alvo do estimado; +8 adiantada (até 80% do estimado)"><span>⭐ XP da sprint</span><strong>${sprintScore.xp} XP</strong><small>${sprintScore.delivered ? `${sprintScore.onTarget} de ${sprintScore.delivered} entregas no alvo (${sprintScore.precision}%)` : 'nenhuma entrega ainda'}</small></div>
      </div>`;
  }

  /** Só os rostos e o time de cada pessoa; quem tem impedimento ganha a marcação vermelha. */
  function renderFaces(developers, cards) {
    el('daily-faces').innerHTML = developers.length ? `<button type="button" class="daily-face-nav" data-daily-step="-1" aria-label="Pessoa anterior">‹</button>
      ${developers.map((dev, index) => {
        const blocked = cards.filter(row => ownedBy(row, dev.name) && column(row.Status) === 'blocked').length;
        return `<button type="button" class="daily-face ${dev.name === state.dev ? 'selected' : ''}" data-daily-dev="${escape(dev.name)}" aria-pressed="${dev.name === state.dev}" title="${escape(dev.name)}${blocked ? ` · ${blocked} impedido(s)` : ''}">
          <span class="cap-avatar cap-avatar-${index % 4} daily-avatar">${escape(initials(dev.name))}${blocked ? `<i class="daily-face-alert">${blocked}</i>` : ''}</span>
          <span class="daily-face-name">${escape(dev.name.split(' ')[0])}</span>
          ${dev.team ? `<span class="team-badge ${escape(dev.team)}">${escape(window.SprintRules.teamLabel(dev.team))}</span>` : ''}
        </button>`;
      }).join('')}
      <button type="button" class="daily-face-nav" data-daily-step="1" aria-label="Próxima pessoa">›</button>` : '<p class="pp-muted">Nenhum desenvolvedor com capacidade ou cards nesta sprint.</p>';
  }

  /** Painel da pessoa na largura toda: horas na sprint, tendência e conquistas. */
  function renderPerson(dev, mine, dates, data) {
    const panel = el('daily-person');
    if (!dev) { panel.innerHTML = ''; return; }
    const balance = dev.capacity - dev.totalEstimate;
    const percent = dev.capacity > 0 ? Math.round(dev.totalEstimate / dev.capacity * 100) : null;
    const personTrend = trend(mine.reduce((sum, row) => sum + remainingOf(row), 0), dev.capacity, dates, data.today);
    const personScore = score(mine);
    const level = window.SprintRules.capacityLevel(dev.totalEstimate, dev.capacity);
    const burning = mine.filter(row => cardHours(row).level === 'over' && column(row.Status) !== 'done').length;
    const stat = (label, value, detail = '', kind = '') => `<div class="daily-person-stat ${kind}"><span>${label}</span><strong>${value}</strong>${detail ? `<small>${detail}</small>` : ''}</div>`;
    panel.innerHTML = `<header class="daily-person-head">
        <span class="cap-avatar cap-avatar-${Math.max(0, state.people.indexOf(dev.name)) % 4} daily-avatar large">${escape(initials(dev.name))}</span>
        <div><h2>${escape(dev.name)}</h2>${dev.team ? `<span class="team-badge ${escape(dev.team)}">${escape(window.SprintRules.teamLabel(dev.team))}</span>` : ''}</div>
        <div class="daily-person-trend trend-${personTrend.level}" title="${escape(personTrend.detail)}"><strong>${personTrend.label}</strong><small>${escape(personTrend.detail)}</small></div>
      </header>
      <div class="daily-person-grid">
        ${stat('Capacidade', hours(dev.capacity), dev.inheritedFrom ? `herdada da Sprint ${dev.inheritedFrom}` : 'na sprint')}
        ${stat('Planejado', hours(dev.totalEstimate), percent === null ? '' : `${percent}% da capacidade`, `level-${level}`)}
        ${stat('Consumido', dev.knownReal ? hours(dev.registered) : '—', 'tempo real apontado')}
        ${stat('Saldo', balance < 0 ? `−${hours(-balance)}` : hours(balance), 'capacidade − planejado', balance < 0 ? 'level-red' : '')}
        ${stat('Estourados', String(burning), burning ? 'cards abertos acima do estimado' : 'nenhum card acima do estimado', burning ? 'level-red' : 'level-green')}
        ${stat('⭐ XP', `${personScore.xp}`, personScore.delivered ? `${personScore.delivered} entrega(s) · ${personScore.onTarget} no alvo${personScore.early ? ` · ${personScore.early} adiantada(s)` : ''}` : 'nenhuma entrega ainda', 'xp')}
      </div>
      ${personScore.delivered && personScore.precision >= 80 ? '<p class="daily-achievement">🏆 Precisão de estimativa acima de 80% nesta sprint</p>' : ''}`;
  }

  /** Há quanto tempo o card impedido está no status atual (histórico do Monday); sem histórico, não calcula. */
  function blockedTime(row, data) {
    const age = data.statusAges?.[String(row.Id)];
    if (!age?.since) return '<small class="daily-age">impedido · sem histórico confiável do início</small>';
    return `<small class="daily-age">⏳ impedido há ${age.workingDays} dia(s) útil(eis) · desde ${day(age.since)}</small>`;
  }

  /** Ordem dentro de cada coluna: da prioridade mais crítica para a mais baixa (sem prioridade por último), depois pelo nome. */
  const byPriority = (a, b) => window.KpiTable.priorityRank(a.Prioridade) - window.KpiTable.priorityRank(b.Prioridade) || String(a.Nome).localeCompare(String(b.Nome), 'pt-BR');

  /** Kanban da pessoa: cada card com criticidade, status do Monday, horas e o selo da tendência; colunas ordenadas pela prioridade. */
  function renderBoard(rows, data) {
    const board = el('daily-board');
    if (!state.dev) { board.innerHTML = '<p class="pp-muted">Escolha uma pessoa.</p>'; return; }
    const link = row => window.ItemDetail?.link({id: row.Id, name: row.Nome, url: row.Url}) || escape(row.Nome);
    const isToday = data.date === data.today;
    board.innerHTML = `<div class="daily-kanban">${COLUMNS.map(([key, label]) => {
      const items = rows.filter(row => column(row.Status) === key).sort(byPriority);
      return `<section class="daily-column daily-${key}" aria-label="${label}"><h3>${label} <span>${items.length}</span></h3>
        ${items.map(row => {
          const info = cardHours(row);
          const width = info.ratio === null ? 0 : Math.min(100, info.ratio * 100);
          return `<article class="daily-card hours-${info.level}">
            <span class="daily-card-title">${link(row)}</span>
            <span class="daily-card-tags">
              ${row.Prioridade ? `<span class="daily-priority p-${escape(normalize(row.Prioridade).replace(/[^a-z]/g, ''))}">${escape(row.Prioridade)}</span>` : ''}
              <span class="daily-card-status">${escape(row.Status || 'Sem status')}</span>
              <span class="daily-card-id">#${escape(row.Id)}</span>
            </span>
            <span class="daily-card-hours" title="Tempo real ÷ tempo estimado">
              <span>${hours(info.real)} <small>de ${info.estimate === null ? '—' : hours(info.estimate)}</small></span>
              <span class="daily-hours-bar"><span style="width:${width}%"></span></span>
            </span>
            ${info.badge ? `<span class="daily-card-badge">${info.badge}</span>` : ''}
            ${key === 'blocked' ? blockedTime(row, data) : ''}
            ${isToday && key !== 'done' ? `<button type="button" class="daily-card-action" data-daily-card-action="${escape(row.Id)}">＋ responsabilidade</button>` : ''}
          </article>`;
        }).join('') || '<p class="daily-empty">—</p>'}
      </section>`;
    }).join('')}</div>`;
  }

  const PUBLISH = {published: 'Publicado no Monday', failed: 'Falha ao publicar no Monday', pending: 'Publicação pendente', sending: 'Publicando...'};
  const NOTICE = {published: '💬 Avisada no card', failed: 'Falha ao avisar no card', sending: 'Avisando no card...'};
  /** Responsabilidades que saíram da Daily, agrupadas por responsável. */
  function renderTodo(data, cards) {
    const isToday = data.date === data.today;
    const visible = data.actions.filter(action => state.filter === 'all' || action.status === state.filter);
    const pending = data.actions.filter(action => action.status === 'pending').length;
    el('daily-todo-count').textContent = `${pending} pendente(s) · ${data.actions.length - pending} concluída(s)`;
    document.querySelectorAll('#daily-filter [data-daily-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.dailyFilter === state.filter)));
    el('daily-new').hidden = !isToday;
    el('daily-new-submit').disabled = state.busy;
    const responsible = el('daily-new-responsible'), chosenResponsible = responsible.value;
    responsible.replaceChildren(new Option('Sem responsável', ''), ...data.users.map(user => new Option(user.name, String(user.id))));
    responsible.value = chosenResponsible;
    const item = el('daily-new-item'), chosenItem = item.value;
    const mine = cards.filter(row => state.dev && ownedBy(row, state.dev)), others = cards.filter(row => !mine.includes(row));
    const group = (label, rows) => rows.length ? [Object.assign(document.createElement('optgroup'), {label}), ...rows.map(row => new Option(`#${row.Id} · ${row.Nome}`, String(row.Id)))] : [];
    item.replaceChildren(new Option('Sem card', ''), ...group(`Cards de ${state.dev}`, mine), ...group('Outros cards da sprint', others));
    item.value = chosenItem;
    renderNewHint();
    const groups = new Map();
    for (const action of visible) {
      const key = action.responsible?.name || 'Sem responsável';
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(action);
    }
    el('daily-actions').innerHTML = visible.length ? [...groups.entries()].sort(([a], [b]) => a.localeCompare(b, 'pt-BR')).map(([name, actions]) => `
      <section class="daily-owner"><h3>👤 ${escape(name)} <small>${actions.length}</small></h3>
        <ul>${actions.map(action => actionRow(action, data)).join('')}</ul>
      </section>`).join('') : `<p class="daily-empty">${state.filter === 'pending' ? 'Nenhuma responsabilidade pendente.' : 'Nenhuma responsabilidade.'}</p>`;
  }

  function actionRow(action, data) {
    const earlier = action.origin < data.date;
    const card = action.item ? (window.ItemDetail?.link({id: action.item.id, name: action.item.name, url: action.item.url}) || escape(action.item.name)) : '';
    const canRetry = action.status === 'done' && action.item && ['failed', 'pending'].includes(action.publishStatus) && data.canPublish;
    const canNotice = action.item && data.canPublish && [null, undefined, 'failed'].includes(action.noticeStatus);
    const notice = action.item && action.noticeStatus ? `<span class="daily-publish ${escape(action.noticeStatus)}">${NOTICE[action.noticeStatus] || ''}</span>` : '';
    return `<li class="daily-action ${action.status}">
      <div class="daily-action-main">
        <strong>${escape(action.description)}</strong>
        <span class="daily-action-meta">
          <span class="daily-tag ${earlier ? 'earlier' : ''}">Daily de ${day(action.origin)}${earlier && action.status === 'pending' ? ' · pendente de Daily anterior' : ''}</span>
          ${card ? `<span>🔗 ${card}</span>` : ''}
          <span>📅 aberta ${dateTime(action.openedAt)}${action.openedBy ? ` por ${escape(action.openedBy)}` : ''}</span>
          ${action.reopened ? `<span class="daily-tag">reaberta ${action.reopened}x</span>` : ''}
          ${notice}
        </span>
        ${action.status === 'done' ? `<p class="daily-resolution">✅ ${escape(action.resolution)}<small>Concluída ${dateTime(action.closedAt)}${action.closedBy ? ` por ${escape(action.closedBy)}` : ''} · tempo de resolução ${escape(action.elapsed)}${action.publishStatus ? ` · <span class="daily-publish ${escape(action.publishStatus)}">${PUBLISH[action.publishStatus] || ''}</span>` : ''}</small></p>` : ''}
      </div>
      <div class="daily-action-buttons">
        ${action.status === 'pending' ? `<button type="button" class="pp-primary" data-daily-done="${action.id}" ${state.busy ? 'disabled' : ''}>Feito</button>` : `<button type="button" class="pp-ghost" data-daily-reopen="${action.id}" ${state.busy ? 'disabled' : ''}>Reabrir</button>`}
        ${canRetry ? `<button type="button" class="pp-ghost" data-daily-publish="${action.id}" ${state.busy ? 'disabled' : ''}>Publicar no Monday</button>` : ''}
        ${canNotice ? `<button type="button" class="pp-ghost" data-daily-notice="${action.id}" ${state.busy ? 'disabled' : ''} title="Comenta no card que esta responsabilidade está pendente">Avisar no card</button>` : ''}
      </div>
    </li>`;
  }

  // ── Conclusão ────────────────────────────────────────────────────────────────
  function openDone(id) {
    const action = state.data.actions.find(item => item.id === id);
    if (!action) return;
    state.doneAction = action;
    el('daily-done-sub').textContent = action.description;
    el('daily-done-facts').innerHTML = `<div><dt>Responsável</dt><dd>${escape(action.responsible?.name || 'Sem responsável')}</dd></div>
      <div><dt>Abertura</dt><dd>${dateTime(action.openedAt)} · Daily de ${day(action.origin)}</dd></div>
      <div><dt>Card</dt><dd>${action.item ? escape(`#${action.item.id} · ${action.item.name}`) : 'Sem card vinculado'}</dd></div>`;
    el('daily-resolution').value = '';
    el('daily-done-message').textContent = '';
    el('daily-publish-box').hidden = !action.item;
    el('daily-publish').checked = Boolean(action.item && state.data.canPublish);
    el('daily-publish').disabled = !state.data.canPublish;
    el('daily-preview').innerHTML = state.data.canPublish ? '' : '<p class="pp-muted">Publicar no Monday exige a permissão "Atualizar cards". A responsabilidade é concluída e registrada só no Sprint Metrics.</p>';
    el('daily-done-dialog').showModal();
    el('daily-resolution').focus();
    schedulePreview();
  }

  /** Prévia do comentário, montada pelo servidor com o mesmo modelo que vai para o card. */
  function schedulePreview() {
    clearTimeout(state.previewTimer);
    const action = state.doneAction;
    if (!action?.item || !el('daily-publish').checked) { if (state.data?.canPublish) el('daily-preview').innerHTML = ''; return; }
    state.previewTimer = setTimeout(async () => {
      try {
        const base = el('page-daily-scrum').dataset.url.replace(/\/$/, '');
        const result = await mondayRequest(`${base}/actions/${action.id}/preview`, {resolution: el('daily-resolution').value});
        el('daily-preview').innerHTML = `<span class="daily-preview-label">Prévia do comentário no card</span><div class="daily-preview-body">${result.html}</div>`;
      } catch { /* A prévia é só apoio; a conclusão segue funcionando. */ }
    }, 350);
  }

  async function submitDone(event) {
    event.preventDefault();
    const resolution = el('daily-resolution').value.trim();
    if (!resolution) { el('daily-done-message').textContent = 'Descreva a resolução para concluir.'; el('daily-resolution').focus(); return; }
    const publish = Boolean(state.doneAction?.item) && el('daily-publish').checked;
    if (!await act(`/actions/${state.doneAction.id}/complete`, {resolution, publish})) { el('daily-done-message').textContent = state.error; return; }
    el('daily-done-dialog').close();
    const saved = state.data.actions.find(item => item.id === state.doneAction.id);
    if (publish && saved?.publishStatus === 'failed') alert('A responsabilidade foi concluída, mas o Monday recusou o comentário. Use "Publicar no Monday" para tentar de novo.');
  }

  /** Abre a criação de responsabilidade já ligada ao card e à pessoa (quando ela tem login no app). */
  function prefillFromCard(id) {
    el('daily-new-item').value = String(id);
    renderNewHint();
    const user = state.data.users.find(person => normalize(person.name) === normalize(state.dev));
    if (user) el('daily-new-responsible').value = String(user.id);
    el('daily-todo').scrollIntoView({behavior: 'smooth', block: 'start'});
    el('daily-new-description').focus();
  }

  function step(delta) {
    if (!state.people.length) return;
    const index = Math.max(0, state.people.indexOf(state.dev));
    state.dev = state.people[(index + delta + state.people.length) % state.people.length];
    render();
  }

  function onClick(event) {
    const target = event.target;
    if (target.closest('[data-close]')) { target.closest('dialog')?.close(); return; }
    const dev = target.closest('[data-daily-dev]');
    if (dev) { state.dev = dev.dataset.dailyDev; render(); return; }
    const move = target.closest('[data-daily-step]');
    if (move) { step(Number(move.dataset.dailyStep)); return; }
    const filter = target.closest('[data-daily-filter]');
    if (filter) { state.filter = filter.dataset.dailyFilter; render(); return; }
    const retroDone = target.closest('[data-retro-done]');
    if (retroDone) { state.retroDone = Number(retroDone.dataset.retroDone); render(); el('daily-retro-result')?.focus(); return; }
    if (target.closest('[data-retro-cancel]')) { state.retroDone = null; render(); return; }
    const retroConfirm = target.closest('[data-retro-confirm]');
    if (retroConfirm) { completeRetroAction(Number(retroConfirm.dataset.retroConfirm)); return; }
    const fromCard = target.closest('[data-daily-card-action]');
    if (fromCard) { prefillFromCard(fromCard.dataset.dailyCardAction); return; }
    const done = target.closest('[data-daily-done]');
    if (done) { openDone(Number(done.dataset.dailyDone)); return; }
    const reopen = target.closest('[data-daily-reopen]');
    if (reopen) { if (confirm('Reabrir esta responsabilidade? A conclusão atual fica no histórico e ela volta às pendências.')) act(`/actions/${reopen.dataset.dailyReopen}/reopen`, {}); return; }
    const publish = target.closest('[data-daily-publish]');
    if (publish) { act(`/actions/${publish.dataset.dailyPublish}/publish`, {}); return; }
    const notice = target.closest('[data-daily-notice]');
    if (notice) act(`/actions/${notice.dataset.dailyNotice}/notice`, {});
  }

  /** Dica do formulário: com card escolhido, diz se a responsabilidade vai ser comentada no card como pendente. */
  function renderNewHint() {
    const hint = el('daily-new-hint');
    if (!hint || !state.data) return;
    hint.textContent = !el('daily-new-item').value ? ''
      : state.data.canPublish ? '💬 Ao adicionar, a responsabilidade é comentada no card do Monday como pendente; ao concluir, a resolução também vai para o card.'
      : 'Sem a permissão "Atualizar cards", a responsabilidade fica registrada só no Sprint Metrics, sem comentário no card.';
  }

  /** ← e → passam de pessoa durante a Daily (fora de campos de texto e de modais). */
  function onKey(event) {
    if (!state.active || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
    if (event.target.closest?.('input, textarea, select, dialog[open]')) return;
    event.preventDefault();
    step(event.key === 'ArrowLeft' ? -1 : 1);
  }

  async function submitNew(event) {
    event.preventDefault();
    const description = el('daily-new-description').value.trim();
    if (!description) { state.error = 'Descreva o que precisa ser feito.'; render(); el('daily-new-description').focus(); return; }
    const ok = await act('/actions', {sprint: state.sprint, description, responsible_id: el('daily-new-responsible').value || null, item_id: el('daily-new-item').value || null});
    if (ok) {
      el('daily-new-description').value = ''; el('daily-new-item').value = ''; renderNewHint();
      const created = state.data.actions.at(-1);
      if (created?.noticeStatus === 'failed') alert('A responsabilidade foi registrada, mas o Monday recusou o comentário no card. Use "Avisar no card" para tentar de novo.');
    }
  }

  /**
   * Histórico das Dailies de uma sprint (modo leitura da Sprint Planning): por Daily, as responsabilidades abertas
   * nela, com responsável, card, resolução, tempo de resolução, reaberturas e publicações no Monday.
   */
  function historyHtml(dailies) {
    if (!dailies.length) return '<h2 class="pp-dailies-title">Dailies · nenhuma Daily registrada nesta sprint</h2>';
    const actions = dailies.flatMap(daily => daily.actions);
    const open = actions.filter(action => action.status === 'pending').length;
    return `<h2 class="pp-dailies-title">Dailies · ${dailies.length} Daily(s) · ${actions.length} responsabilidade(s) · ${open} pendente(s)</h2>
      <div class="pp-dailies">${dailies.map(daily => `<section class="pp-daily"><h3>Daily de ${day(daily.date)} <small>${daily.actions.length} responsabilidade(s)</small></h3>
        <ul>${daily.actions.map(action => `<li class="${action.status}">
          <strong>${action.status === 'done' ? '✅' : '⏳'} ${escape(action.description)}</strong>
          <span>👤 ${escape(action.responsible?.name || 'Sem responsável')}${action.item ? ` · 🔗 ${escape(action.item.name)}` : ''}${action.status === 'done' ? ` · resolvida em ${escape(action.elapsed)}` : ' · pendente'}${action.reopened ? ` · reaberta ${action.reopened}x` : ''}${action.publishStatus === 'published' ? ' · publicada no Monday' : ''}</span>
          ${action.resolution ? `<em>${escape(action.resolution)}</em>` : ''}
        </li>`).join('') || '<li>Nenhuma responsabilidade.</li>'}</ul></section>`).join('')}</div>`;
  }

  function enter(hint = null) {
    state.active = true;
    if (Number.isInteger(hint)) { state.sprint = hint; state.date = null; }
    render();
    window.HoursStrip?.loadReserves().then(() => render());
    load();
  }
  function leave() { state.active = false; el('daily-done-dialog')?.close(); }
  function onDataUpdated() { if (state.active) render(); }

  document.addEventListener('DOMContentLoaded', () => {
    const page = el('page-daily-scrum');
    if (!page) return;
    page.addEventListener('click', onClick);
    document.addEventListener('keydown', onKey);
    el('daily-sprint').addEventListener('change', event => { state.sprint = Number(event.target.value); state.date = null; state.dev = null; load(); });
    el('daily-date').addEventListener('change', event => { state.date = event.target.value; load(); });
    el('daily-new').addEventListener('submit', submitNew);
    el('daily-new-item').addEventListener('change', renderNewHint);
    el('daily-done-form').addEventListener('submit', submitDone);
    el('daily-resolution').addEventListener('input', schedulePreview);
    el('daily-publish').addEventListener('change', schedulePreview);
  });
  return {enter, leave, onDataUpdated, column, cardHours, remainingOf, trend, score, historyHtml, sprintCards};
})();
