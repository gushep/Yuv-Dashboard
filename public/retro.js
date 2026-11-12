/*
 * Sprint Retrospective. Tela inicial: cards das ações de todas as retros (clicar filtra a lista), a retro em andamento e
 * as últimas sprints com o resumo de cada retro. Sala: o facilitador conduz sete etapas com cronômetro (tempo da retro e
 * de cada etapa, com alerta quando estoura); o time escreve cartas viradas até a revelação, vota, discute, escolhe até
 * dois focos e monta o plano de ação. Olhinho: a retro encerrada só para leitura (ou os dados da sprint, quando a retro
 * foi feita fora do app). Regras no servidor (RetrospectiveService); a sala é consultada a cada poucos segundos.
 */
window.Retro = (() => {
  const POLL_MS = 2500;
  const LOBBY_POLL_MS = 15000;
  const TOTAL_MINUTES = 45;
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const day = value => value ? value.slice(0, 10).split('-').reverse().slice(0, 2).join('/') : '—';
  const initials = name => String(name || '?').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
  const hours = minutes => window.Capacity?.formatMinutes ? window.Capacity.formatMinutes(Math.max(0, Math.round(minutes))) : `${Math.round(minutes / 60)}h`;
  const percent = (part, whole) => whole > 0 ? Math.round(part / whole * 100) : null;
  const COLUMNS = [
    ['keep', 'Manter', 'O que ajudou o time e deve continuar?'],
    ['improve', 'Melhorar', 'O que dificultou o fluxo? Traga um exemplo'],
    ['try', 'Experimentar', 'Qual mudança concreta podemos testar?'],
  ];
  const COLUMN_LABEL = Object.fromEntries(COLUMNS.map(([key, label]) => [key, label]));
  const STATUS = {open: ['blue', 'Em andamento'], late: ['red', 'Atrasada'], done: ['green', 'Concluída'], dropped: ['muted', 'Descartada']};
  const REVIEW = {done: ['green', 'Concluída'], continued: ['blue', 'Continua'], dropped: ['muted', 'Descartada'], reopened: ['yellow', 'Reaberta']};
  const state = {active: false, overview: null, room: null, review: null, view: 'lobby', filter: 'open', error: '', busy: false,
    roomTimer: null, lobbyTimer: null, clockTimer: null, fetchedAt: 0, merging: null, dialog: null, request: 0, editingOpening: false, drafts: {}, pending: null, pointer: false};
  const page = () => el('page-sprint-retrospective');
  const base = () => page().dataset.url.replace(/\/$/, '');

  // ── Indicadores da sprint (mesmas contas do Dashboard e do Capacity) ─────────
  /**
   * Indicadores da sprint para a etapa "Dados": entregas (com recorde e média das sprints anteriores), escopo, suporte,
   * planejado entregue, horas, precisão das estimativas, retrabalho e responsabilidades da Daily. São congelados no
   * encerramento da retro.
   */
  function metricsFor(sprint, dailyStats = null) {
    if (typeof spMetrics !== 'function' || typeof DATA === 'undefined') return null;
    const m = spMetrics(sprint);
    if (!m || !m.tot) return null;
    const previous = (typeof SPRINTS === 'undefined' ? [] : SPRINTS).filter(number => number < sprint).map(number => spMetrics(number).done);
    const real = rows => rows.reduce((sum, row) => sum + (window.Capacity.minutes(row.RealH) ?? 0), 0);
    const supportMinutes = real(m.lists.support || []), registeredMinutes = real(m.lists.tot || []);
    const delivered = (m.lists.done || []).filter(row => (window.Capacity.minutes(row.EstH) ?? 0) > 0);
    const onTarget = delivered.filter(row => (window.Capacity.minutes(row.RealH) ?? 0) <= window.Capacity.minutes(row.EstH)).length;
    const dates = window.MONDAY_SPRINT_DATES?.[sprint] || null;
    const model = window.Capacity.model(DATA, sprint, dates, window.SprintRules?.settings?.capacityOverrides || {});
    return {
      delivered: m.done, deliveredRecord: previous.length ? Math.max(...previous) : null,
      deliveredAverage: previous.length ? Math.round(previous.reduce((sum, value) => sum + value, 0) / previous.length * 10) / 10 : null,
      scope: m.tot, planned: m.planned, unplanned: m.unplanned, support: m.support, plannedDone: m.plannedDone, plannedPct: percent(m.plannedDone, m.planned),
      supportMinutes, registeredMinutes, supportPct: percent(supportMinutes, registeredMinutes),
      capacityMinutes: model.developers.reduce((sum, dev) => sum + dev.capacity, 0), plannedMinutes: model.developers.reduce((sum, dev) => sum + dev.totalEstimate, 0),
      onTarget, onTargetBase: delivered.length, onTargetPct: percent(onTarget, delivered.length),
      rework: m.rework, reworkPct: m.reworkPct, bugs: m.bugs, drag: m.drag, dragPct: m.arrastPct,
      daily: dailyStats,
    };
  }

  function metricsHtml(metrics, frozen) {
    if (!metrics) return '<p class="pp-muted">Sem dados sincronizados desta sprint. Atualize os dados do Monday para ver os indicadores.</p>';
    const big = (value, label, detail) => `<div class="rt-big"><strong>${value}</strong><b>${label}</b><small>${detail}</small></div>`;
    const mini = (label, value, detail) => `<div class="rt-mini"><span>${label}</span><strong>${value}</strong><small>${detail}</small></div>`;
    const record = metrics.deliveredRecord === null ? 'primeira sprint da série'
      : metrics.delivered > metrics.deliveredRecord ? `maior marca da série; recorde anterior ${metrics.deliveredRecord}, média ${String(metrics.deliveredAverage).replace('.', ',')}`
      : `recorde ${metrics.deliveredRecord}, média ${String(metrics.deliveredAverage).replace('.', ',')}`;
    const daily = metrics.daily;
    const average = daily?.averageMinutes ? (daily.averageMinutes >= 1440 ? `${Math.floor(daily.averageMinutes / 1440)}d ${Math.round(daily.averageMinutes % 1440 / 60)}h` : hours(daily.averageMinutes)) : '—';
    return `<div class="rt-grid4">
        ${big(metrics.delivered, 'Entregas', record)}
        ${big(metrics.scope, 'Cards no escopo', `${metrics.planned} planejados · ${metrics.unplanned} não planejados · ${metrics.support} de suporte`)}
        ${big(metrics.support, 'Cards de suporte', `${hours(metrics.supportMinutes)}${metrics.supportPct === null ? '' : `, ${metrics.supportPct}% do tempo registrado`}`)}
        ${big(metrics.plannedPct === null ? '—' : `${metrics.plannedPct}%`, 'Do planejado', `${metrics.plannedDone} de ${metrics.planned} itens planejados entregues`)}
      </div>
      <div class="rt-grid4">
        ${mini('Horas', `${hours(metrics.plannedMinutes)} de ${hours(metrics.capacityMinutes)}`, `planejado × capacidade · ${hours(metrics.registeredMinutes)} registradas`)}
        ${mini('Estimativas', metrics.onTargetPct === null ? '—' : `${metrics.onTargetPct}% no alvo`, `${metrics.onTarget} de ${metrics.onTargetBase} entregas até 100% do estimado`)}
        ${mini('Daily', daily ? `${daily.done} de ${daily.total}` : '—', daily?.total ? `responsabilidades resolvidas · média ${average}` : 'nenhuma responsabilidade registrada')}
        ${mini('Qualidade', `${metrics.reworkPct}% retrabalho`, `${metrics.bugs} bug(s) em produção · ${metrics.dragPct}% arrastado`)}
      </div>
      <p class="rt-note">${frozen ? '🔒 Indicadores congelados no encerramento da retro.' : 'Calculados com as mesmas regras do Dashboard e do Capacity; ficam congelados quando a retro for encerrada.'}</p>`;
  }

  /** Resultado da Sprint Review da mesma sprint: aceite das entregas, objetivo e feedbacks. */
  function reviewHtml(review) {
    if (!review) return '';
    const v = review.verdicts, presented = v.accepted + v.adjust + v.rejected;
    const goal = {yes: 'objetivo atingido', partial: 'objetivo parcial', no: 'objetivo não atingido'}[review.goalResult];
    const parts = [presented ? `${v.accepted} de ${presented} entregas aceitas${v.adjust ? ` · ${v.adjust} com ajustes` : ''}${v.rejected ? ` · ${v.rejected} não aceita(s)` : ''}` : 'nenhuma entrega avaliada', goal, `${review.feedbacks} feedback(s)${review.demands ? `, ${review.demands} virou(aram) demanda` : ''}`].filter(Boolean);
    return `<div class="rt-goal neutral"><span>Sprint Review${review.status === 'open' ? ' (em andamento)' : ''}</span><p>${escape(parts.join(' · '))}</p></div>`;
  }

  // ── Cronômetro ───────────────────────────────────────────────────────────────
  const clock = seconds => `${Math.floor(Math.max(0, seconds) / 60)}:${String(Math.floor(Math.max(0, seconds) % 60)).padStart(2, '0')}`;
  /** Tempo da etapa atual e da retro, contando desde a última consulta da sala. */
  function timing(retro) {
    const live = retro.status === 'open' ? (Date.now() - state.fetchedAt) / 1000 : 0;
    const current = retro.steps[retro.step];
    const stepSeconds = current.seconds + live, total = retro.elapsedSeconds + live;
    return {stepSeconds, total, stepOver: stepSeconds > current.minutes * 60, totalOver: total > TOTAL_MINUTES * 60, current};
  }
  function renderClock() {
    const retro = state.view === 'room' ? state.room?.retro : null;
    if (!state.active || !retro) return;
    const time = timing(retro);
    const total = el('rt-clock'), alert = el('rt-alert');
    if (total) total.innerHTML = `⏱ Retro <b>${clock(time.total)}</b> de ${TOTAL_MINUTES}:00`;
    if (alert) {
      const messages = [time.stepOver ? `${escape(time.current.name)} estourou: ${clock(time.stepSeconds)} de ${time.current.minutes} min` : '', time.totalOver ? `a retro passou de ${TOTAL_MINUTES} min` : ''].filter(Boolean);
      alert.hidden = !messages.length;
      alert.textContent = messages.length ? `⚠ ${messages.join(' · ')}` : '';
    }
    const stepLabel = document.querySelector(`#rt-stepper [data-step="${retro.step}"] small`);
    if (stepLabel) stepLabel.textContent = `${retro.step + 1} · ${clock(time.stepSeconds)} de ${time.current.minutes} min`;
  }

  // ── API ──────────────────────────────────────────────────────────────────────
  async function loadOverview() {
    try {
      state.overview = await mondayRequest(page().dataset.url);
      state.error = '';
    } catch (error) {
      state.error = error.message;
    }
    render();
  }

  /** Enquanto alguém digita na sala, a consulta não redesenha a etapa: o redesenho fica para quando o campo perder o foco. */
  async function loadRoom() {
    const request = ++state.request;
    try {
      const data = await mondayRequest(page().dataset.roomUrl);
      if (request !== state.request || state.busy) return;
      applyRoom(data);
      state.error = '';
    } catch (error) {
      if (request === state.request) state.error = error.message;
    }
    if (holding()) { state.pending = true; renderClock(); return; }
    render();
  }

  /**
   * A sala não é redesenhada enquanto alguém digita ou está com o botão do mouse pressionado: trocar o botão entre o
   * pressionar e o soltar faz o navegador descartar o clique (era assim que "Adicionar ação" se perdia).
   */
  const holding = () => typing() || state.pointer;
  function flushPending() {
    if (!holding()) { state.focus = null; if (state.pending) render(); }
  }

  function applyRoom(data) {
    state.room = data;
    state.fetchedAt = Date.now();
    if (!data.retro && state.view === 'room') { state.view = 'lobby'; loadOverview(); }
  }

  const typing = () => { const active = document.activeElement; return Boolean(active && el('rt-room')?.contains(active) && active.matches('input, textarea, select')); };

  /** Passo na sala ou numa ação: responde com a sala (tem `retro`) ou com a tela inicial (tem `kpis`). */
  async function act(path, body = {}) {
    if (state.busy) return false;
    state.busy = true; render();
    let ok = true;
    try {
      const data = await mondayRequest(`${base()}${path}`, body);
      if ('kpis' in data) state.overview = data; else applyRoom(data);
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
    state.pending = null;
    el('retro-error').textContent = state.error;
    el('retro-error').hidden = !state.error;
    const reviewing = state.view === 'review' && state.review;
    const retro = state.view === 'room' ? state.room?.retro : null;
    el('rt-lobby').hidden = Boolean(reviewing || retro);
    el('rt-room').hidden = !(reviewing || retro);
    el('rt-subtitle').textContent = reviewing ? `${state.review.sprint?.name || 'Sprint'} · somente leitura`
      : retro ? `Retrospectiva da ${retro.sprintName} · facilitador ${retro.facilitator?.name || 'Maestro Scrum'}` : 'Aprender com cada sprint e acompanhar as ações até o fim';
    renderHeroActions(retro, reviewing);
    if (reviewing) renderReview(state.review);
    else if (retro) renderRoom(retro);
    else renderLobby(state.overview);
  }

  function renderHeroActions(retro, reviewing) {
    const buttons = [];
    if (reviewing || retro) buttons.push('<button type="button" class="pp-ghost" data-view="lobby">Voltar às sprints</button>');
    if (retro?.canConduct) buttons.push(`<button type="button" class="pp-danger" data-close-retro ${state.busy ? 'disabled' : ''}>Encerrar retro</button>`);
    if (!retro && !reviewing && state.overview?.open) buttons.push(`<button type="button" class="pp-primary" data-view="room">Entrar na retro da ${escape(state.overview.open.sprintName)}</button>`);
    el('rt-hero-actions').innerHTML = buttons.join('');
  }

  // ── Tela inicial ─────────────────────────────────────────────────────────────
  const actionState = action => action.status === 'open' ? (action.late ? 'late' : 'open') : action.status;
  const FILTERS = {open: action => action.status === 'open', late: action => action.late, done: action => action.status === 'done', all: () => true};

  function renderLobby(data) {
    if (!data) { el('rt-history').innerHTML = '<p class="pp-muted">Carregando...</p>'; return; }
    const banner = el('rt-open-banner');
    banner.hidden = !data.open;
    if (data.open) banner.innerHTML = `<span class="rt-dot" aria-hidden="true"></span><span><strong>Retro da ${escape(data.open.sprintName)} em andamento</strong> · facilitador ${escape(data.open.facilitator || '—')} · etapa ${data.open.step + 1} de 7 (${escape(data.open.stepName)})</span>
      ${window.Ceremony ? Ceremony.barHtml({present: data.open.present || [], seconds: data.open.elapsedSeconds || 0, label: 'Retro', targetMinutes: TOTAL_MINUTES}) : ''}
      <button type="button" class="pp-primary" data-view="room">Entrar na retro</button>`;
    const k = data.kpis;
    const card = (filter, kind, label, value, detail, bar = null) => `<button type="button" class="rt-kpi ${kind}" data-filter="${filter}" aria-pressed="${state.filter === filter}"><span>${label}</span><strong>${value}</strong><small>${detail}</small>${bar === null ? '' : `<span class="rt-bar" aria-hidden="true"><b style="width:${bar}%"></b></span>`}</button>`;
    const retros = data.sprints.filter(sprint => sprint.retro).length;
    el('rt-kpis').innerHTML = [
      card('open', 'blue', 'Em andamento', k.open + k.late, k.late ? `${k.late} atrasada(s)` : 'nenhuma atrasada'),
      card('late', 'red', 'Atrasadas', k.late, 'prazo vencido'),
      card('done', 'green', 'Concluídas', k.done, `${k.signalReached} com o sinal de resultado atingido`),
      card('all', 'violet', 'Taxa de conclusão', k.rate === null ? '—' : `${k.rate}%`, `${k.done} de ${k.total - k.dropped} ações`, k.rate ?? 0),
      card('all', '', 'Geral', k.total, `${k.dropped} descartada(s) · ${retros} retro(s) no histórico`),
    ].join('');
    const list = data.actions.filter(FILTERS[state.filter] || FILTERS.all);
    el('rt-actions').innerHTML = list.length ? `<div class="rt-actions">${list.map(action => actionRow(action, 'lobby')).join('')}</div>`
      : `<p class="rt-empty">${state.filter === 'late' ? 'Nenhuma ação atrasada.' : state.filter === 'done' ? 'Nenhuma ação concluída ainda.' : state.filter === 'open' ? 'Nenhuma ação em andamento.' : 'Nenhuma ação registrada nas retros.'}</p>`;
    el('rt-history').innerHTML = data.sprints.length ? data.sprints.map(sprint => historyRow(sprint, data)).join('') : '<p class="pp-muted">Nenhuma sprint iniciada no cronograma.</p>';
  }

  function actionRow(action, from) {
    const status = actionState(action);
    const card = action.item ? (window.ItemDetail?.link({id: action.item.id, name: action.item.name, url: action.item.url}) || escape(action.item.name)) : '';
    const manage = action.canManage && !state.busy;
    const blocked = 'Só o responsável e o facilitador da retro podem alterar a ação';
    const buttons = action.status === 'open'
      ? `<button type="button" class="pp-primary rt-small" data-action-mode="complete" data-action="${action.id}" data-from="${from}" ${manage ? '' : `disabled title="${blocked}"`}>Concluir</button>
         <button type="button" class="pp-ghost rt-small" data-action-mode="drop" data-action="${action.id}" data-from="${from}" ${manage ? '' : `disabled title="${blocked}"`}>Descartar</button>`
      : `<button type="button" class="pp-ghost rt-small" data-reopen="${action.id}" data-from="${from}" ${manage ? '' : `disabled title="${blocked}"`}>Reabrir</button>`;
    return `<div class="rt-action ${status}">
      <div class="rt-action-main"><b>${escape(action.description)}</b>
        <small>Retro da ${escape(action.retro.sprintName || `Sprint ${action.retro.sprint}`)}${action.signal ? ` · sinal: ${escape(action.signal)}` : ''}${card ? ` · 🔗 ${card}` : ''}</small>
        ${action.result ? `<small class="rt-result">${action.status === 'done' ? '✅' : '🗑️'} ${escape(action.result)}${action.status === 'done' && action.signalReached !== null ? ` · sinal ${action.signalReached ? 'atingido' : 'não atingido'}` : ''}${action.closedBy ? ` · ${escape(action.closedBy)}` : ''}</small>` : ''}
      </div>
      <div class="rt-who">${action.responsible ? `<span class="cap-avatar">${escape(initials(action.responsible.name))}</span><span>${escape(action.responsible.name)}</span>` : '<span class="pp-muted">Sem responsável</span>'}</div>
      <div class="rt-due ${action.late ? 'late' : ''}">${action.dueDate ? day(action.dueDate) : 'sem prazo'}${action.late ? ` · ${action.lateDays} dia(s) atrasada` : ''}</div>
      <div><span class="rt-chip ${STATUS[status][0]}">${STATUS[status][1]}</span></div>
      <div class="rt-row-buttons">${buttons}</div>
    </div>`;
  }

  const EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  function historyRow(sprint, data) {
    const retro = sprint.retro;
    const eye = `<button type="button" class="pp-eye" data-review="${sprint.number}" title="${retro ? 'Ver como foi a retro' : 'Ver os dados da sprint'}" aria-label="Ver a retro da ${escape(sprint.name)}">${EYE}</button>`;
    let action = '';
    if (retro?.status === 'open') action = '<button type="button" class="pp-primary" data-view="room">Entrar na retro</button>';
    else if (!retro) {
      const why = !sprint.completed ? `Disponível quando a ${sprint.name} for fechada no Monday` : !data.canFacilitate ? 'Só o Maestro Scrum inicia a retro'
        : data.open ? `Encerre a retro da ${data.open.sprintName} antes` : '';
      action = `<button type="button" class="pp-primary" data-start="${sprint.number}" ${why || state.busy ? `disabled title="${escape(why)}"` : ''}>Iniciar retro</button>`;
    }
    const counts = retro ? `<span class="rt-chip green">Manter ${retro.cards.keep}</span><span class="rt-chip yellow">Melhorar ${retro.cards.improve}</span><span class="rt-chip blue">Experimentar ${retro.cards.try}</span><span class="rt-chip">${retro.focus} foco(s)</span>`
      : sprint.current ? '<span class="rt-chip blue">Sprint em andamento</span><span class="pp-muted">A retro abre quando a sprint for fechada no Monday</span>'
      : sprint.completed ? '<span class="rt-chip">Retro ainda não feita</span>' : '<span class="rt-chip yellow">Sprint ainda não fechada no Monday</span>';
    const a = retro?.actions;
    const progress = retro?.status === 'open' ? `<span class="rt-chip blue">● Em andamento</span><small> · etapa ${retro.step + 1} de 7 (${escape(retro.stepName)})</small>`
      : a?.total ? `<small><b>${a.total} ação(ões)</b> · ${a.done} concluída(s) · ${a.open} em andamento${a.late ? ` · ${a.late} atrasada(s)` : ''}${a.dropped ? ` · ${a.dropped} descartada(s)` : ''}</small><span class="rt-bar" aria-hidden="true"><b style="width:${a.rate ?? 0}%"></b></span>`
      : retro ? '<small>Nenhuma ação registrada</small>' : '<small>—</small>';
    return `<article class="rt-row ${retro ? '' : 'none'}">
      <div><h3>${escape(sprint.name)}</h3><div class="rt-when">${day(sprint.start)} – ${day(sprint.end)}${retro ? ` · retro ${day(retro.date)}` : ''}</div>
        ${retro && window.Ceremony ? `<div class="rt-attendance">${Ceremony.attendanceHtml(retro.attendees || [], retro.durationSeconds, {live: retro.status === 'open'})}</div>` : ''}</div>
      <div class="rt-counts">${counts}</div>
      <div class="rt-prog">${progress}</div>
      <div class="rt-row-buttons">${eye}${action}</div>
    </article>`;
  }

  // ── Sala ─────────────────────────────────────────────────────────────────────
  function renderRoom(retro) {
    el('rt-room-top').innerHTML = `<div class="rt-people" aria-label="Pessoas na sala">${retro.participants.map((person, index) => `<span class="cap-avatar cap-avatar-${index % 4}" title="${escape(person.name)}">${escape(initials(person.name))}</span>`).join('')}</div>
      <span class="pp-muted">${retro.participants.length} na sala</span>
      <span class="rt-timer" id="rt-clock"></span>
      <span class="rt-chip red rt-alert" id="rt-alert" role="alert" hidden></span>`;
    el('rt-stepper').hidden = false;
    el('rt-stepper').innerHTML = retro.steps.map((step, index) => `<li><button type="button" data-step="${index}" class="${index < retro.step ? 'done' : ''}" ${index === retro.step ? 'aria-current="step"' : ''} ${retro.canConduct && !state.busy ? '' : 'disabled'}>
        <small>${index + 1}${index < retro.step ? ' ✓' : ''} · ${clock(step.seconds)} de ${step.minutes} min</small><span>${escape(step.name)}</span></button></li>`).join('');
    const nav = retro.canConduct ? `<div class="rt-step-nav">
        ${retro.step > 0 ? `<button type="button" class="pp-ghost" data-step="${retro.step - 1}" ${state.busy ? 'disabled' : ''}>← ${escape(retro.steps[retro.step - 1].name)}</button>` : '<span></span>'}
        ${retro.step < retro.steps.length - 1 ? `<button type="button" class="pp-primary" data-step="${retro.step + 1}" ${state.busy ? 'disabled' : ''}>${escape(retro.steps[retro.step + 1].name)} →</button>`
          : `<button type="button" class="pp-danger" data-close-retro ${state.busy ? 'disabled' : ''}>Encerrar retro</button>`}
      </div>` : `<p class="rt-note">O facilitador ${escape(retro.facilitator?.name || '')} conduz as etapas; a tela acompanha.</p>`;
    el('rt-step').innerHTML = STEP_RENDERERS[retro.step](retro, false) + nav;
    restoreDrafts();
    renderClock();
  }

  const stepHead = (title, detail) => `<div class="rt-step-head"><h2>${title}</h2><p>${detail}</p></div>`;
  const draft = (id, value) => escape(state.drafts[id] ?? value ?? '');

  function openingStep(retro, readOnly) {
    if (retro.canConduct && !readOnly && state.editingOpening) {
      return `${stepHead('Abertura', 'Edite o objetivo e os combinados antes de começar')}
        <label class="rt-field">Objetivo da retro<textarea id="rt-goal" data-draft rows="2" maxlength="2000">${draft('rt-goal', retro.goal)}</textarea></label>
        <div class="rt-grid2">${retro.rules.map((rule, index) => `<div class="rt-rule-edit"><input type="text" id="rt-rule-title-${index}" data-draft maxlength="120" value="${draft(`rt-rule-title-${index}`, rule.title)}" aria-label="Título do combinado ${index + 1}">
          <textarea id="rt-rule-text-${index}" data-draft rows="2" maxlength="500" aria-label="Texto do combinado ${index + 1}">${draft(`rt-rule-text-${index}`, rule.text)}</textarea></div>`).join('')}</div>
        <div class="rt-inline-actions"><button type="button" class="pp-primary" data-save-opening>Salvar abertura</button><button type="button" class="pp-ghost" data-edit-opening="off">Cancelar</button></div>`;
    }
    return `${stepHead('Abertura', 'O facilitador lê o objetivo e os combinados')}
      <div class="rt-goal"><span>Objetivo da retro</span><p>${escape(retro.goal)}</p></div>
      <div class="rt-grid4">${retro.rules.map(rule => `<div class="rt-rule"><b>${escape(rule.title)}</b><span>${escape(rule.text)}</span></div>`).join('')}</div>
      ${retro.canConduct && !readOnly ? '<div class="rt-inline-actions"><button type="button" class="pp-ghost" data-edit-opening="on">Editar objetivo e combinados</button></div>' : ''}`;
  }

  function previousStep(retro, readOnly) {
    const rows = retro.previousActions;
    return `${stepHead('Ações das retros anteriores', readOnly ? 'O que foi decidido sobre as ações abertas das retros anteriores' : 'Pauta de abertura: o que combinamos aconteceu?')}
      ${rows.length ? `<div class="rt-actions">${rows.map(action => {
        const review = action.review ? `<span class="rt-chip ${REVIEW[action.review.type]?.[0] || ''}">${REVIEW[action.review.type]?.[1] || action.review.type}${action.review.note ? `: ${escape(action.review.note)}` : ''}</span>` : '';
        const manage = !readOnly && action.canManage && action.status === 'open' && !state.busy;
        return `<div class="rt-action ${actionState(action)}">
          <div class="rt-action-main"><b>${escape(action.description)}</b><small>Retro da ${escape(action.retro.sprintName)} · 👤 ${escape(action.responsible?.name || 'sem responsável')} · prazo ${action.dueDate ? day(action.dueDate) : '—'}${action.signal ? ` · sinal: ${escape(action.signal)}` : ''}</small>${review}</div>
          ${readOnly || action.status !== 'open' ? `<div><span class="rt-chip ${STATUS[actionState(action)][0]}">${STATUS[actionState(action)][1]}</span></div>` : `<div class="rt-seg" role="group" aria-label="Decisão do time">
            <button type="button" data-action-mode="complete" data-action="${action.id}" data-from="room" ${manage ? '' : 'disabled'}>Concluída</button>
            <button type="button" data-action-mode="keep" data-action="${action.id}" data-from="room" ${manage ? '' : 'disabled'}>Continua</button>
            <button type="button" data-action-mode="drop" data-action="${action.id}" data-from="room" ${manage ? '' : 'disabled'}>Descartar</button></div>`}
        </div>`;
      }).join('')}</div>` : '<p class="rt-empty">Nenhuma ação aberta das retros anteriores.</p>'}
      ${readOnly ? '' : '<p class="rt-note">"Continua" mantém a ação em andamento com o mesmo responsável; o prazo pode ser renovado. Só o responsável e o facilitador registram a decisão.</p>'}`;
  }

  function dataStep(retro, readOnly) {
    const votes = retro.goalVotes;
    const vote = (key, label, kind) => readOnly ? `<span class="rt-chip ${kind}">${label} ${votes[key]}</span>`
      : `<button type="button" class="rt-vote-goal ${kind}" data-goal-vote="${key}" aria-pressed="${votes.mine === key}" ${state.busy ? 'disabled' : ''}>${label} <b>${votes[key]}</b></button>`;
    return `${stepHead('O que os dados mostram', readOnly ? 'Indicadores da sprint' : 'Calculados automaticamente da sprint')}
      ${metricsHtml(retro.metrics || metricsFor(retro.sprint, retro.dailyStats), Boolean(retro.metrics))}
      ${reviewHtml(retro.review)}
      <div class="rt-goal"><span>Objetivo da sprint (da Sprint Planning)</span><p>${retro.sprintGoal ? escape(retro.sprintGoal) : '<em>Sem objetivo registrado na planning.</em>'}</p></div>
      <div class="rt-goal-votes"><span>O time atingiu o objetivo?</span>${vote('yes', 'Sim', 'green')}${vote('partial', 'Parcial', 'yellow')}${vote('no', 'Não', 'red')}</div>
      ${retro.canConduct && !readOnly ? `<label class="rt-field">Contexto observado (facilitador)<textarea id="rt-context" data-draft rows="2" maxlength="4000">${draft('rt-context', retro.context)}</textarea></label>
        <div class="rt-inline-actions"><button type="button" class="pp-ghost" data-save-field="context" data-source="rt-context">Salvar contexto</button></div>`
        : retro.context ? `<div class="rt-goal neutral"><span>Contexto observado</span><p>${escape(retro.context)}</p></div>` : ''}`;
  }

  function boardStep(retro, readOnly) {
    const conduct = retro.canConduct && !readOnly;
    const cardHtml = group => {
      if (group.hidden) return `<div class="rt-card hidden-card">Carta virada · ${escape(group.author || '—')}</div>`;
      const merging = state.merging && state.merging !== group.id && conduct;
      return `<div class="rt-card ${group.mine ? 'mine' : ''} ${state.merging === group.id ? 'merging' : ''}">
        <p>${escape(group.text)}</p>
        ${group.merged.map(item => `<p class="rt-merged">↳ ${item.hidden ? 'carta virada' : escape(item.text)} <small>${escape(item.author || '')}</small></p>`).join('')}
        <div class="rt-card-meta"><span>${retro.revealed || group.mine ? escape(group.mine ? `${group.author} (você)` : group.author || '') : ''}</span>
          <span class="rt-card-buttons">
            ${conduct && retro.revealed ? (merging ? `<button type="button" class="rt-link" data-merge-into="${group.id}">agrupar aqui</button>` : `<button type="button" class="rt-link" data-merge="${group.id}" title="Agrupar com outra carta parecida">${state.merging === group.id ? 'cancelar' : 'agrupar'}</button>`) : ''}
            ${!readOnly && ((group.mine && !retro.revealed) || conduct) ? `<button type="button" class="rt-link" data-delete-card="${group.id}" aria-label="Apagar carta">apagar</button>` : ''}
            ${retro.revealed ? (readOnly ? `<span class="rt-votes">▲ ${group.votes}</span>` : `<button type="button" class="rt-votes" data-vote="${group.id}" aria-pressed="${group.myVote}" ${state.busy ? 'disabled' : ''}>▲ ${group.votes}</button>`) : ''}
          </span></div>
      </div>`;
    };
    const ordered = column => retro.cards.filter(card => card.column === column).sort((a, b) => retro.revealed ? b.votes - a.votes : 0);
    return `${stepHead('A visão de vocês', retro.revealed ? `Cartas reveladas · ${retro.votesPerPerson} votos por pessoa${readOnly ? '' : ` · você tem ${retro.votesLeft} voto(s)`}` : 'Cartas viradas até o facilitador revelar')}
      <div class="rt-board">${COLUMNS.map(([key, label, question]) => `<div class="rt-col ${key}"><h3>${label} <small>${retro.cards.filter(card => card.column === key).length}</small></h3><p class="rt-q">${question}</p>
        ${ordered(key).map(cardHtml).join('')}
        ${readOnly ? '' : `<div class="rt-add"><input type="text" id="rt-add-${key}" data-draft maxlength="1000" placeholder="Adicionar em ${label}" aria-label="Adicionar em ${label}" value="${draft(`rt-add-${key}`, '')}"><button type="button" class="pp-primary rt-small" data-add-card="${key}" ${state.busy ? 'disabled' : ''}>＋</button></div>`}
      </div>`).join('')}</div>
      ${conduct && !retro.revealed ? `<div class="rt-inline-actions"><span class="rt-note">Antes da revelação, cada pessoa vê só o texto das próprias cartas. Depois, todas mostram quem escreveu.</span><button type="button" class="pp-primary" data-reveal ${state.busy ? 'disabled' : ''}>Revelar cartas</button></div>` : ''}`;
  }

  function discussionStep(retro, readOnly) {
    const conduct = retro.canConduct && !readOnly;
    return `${stepHead('Discussão', 'Temas mais votados primeiro; o facilitador pode incluir temas próprios')}
      ${retro.topics.length ? retro.topics.map((topic, index) => `<div class="rt-topic">
        <div class="rt-rank">${topic.votes === null ? '★' : topic.votes}<small>${topic.votes === null ? 'tema' : 'votos'}</small></div>
        <div><h3>${escape(topic.title)} ${topic.column ? `<span class="rt-chip ${topic.column === 'keep' ? 'green' : topic.column === 'improve' ? 'yellow' : 'blue'}">${COLUMN_LABEL[topic.column]}</span>` : '<span class="rt-chip violet">Tema do facilitador</span>'}${topic.focus ? ` <span class="rt-chip yellow">Foco ${topic.focus}</span>` : ''}</h3>
          ${topic.question ? `<p class="rt-ask">${escape(topic.question)}</p>` : ''}
          ${conduct ? `<textarea id="rt-notes-${topic.id}" data-draft rows="2" maxlength="4000" aria-label="Anotações do tema ${index + 1}" placeholder="Anotações da discussão">${draft(`rt-notes-${topic.id}`, topic.notes)}</textarea>
            <div class="rt-inline-actions"><button type="button" class="pp-ghost rt-small" data-save-notes="${topic.id}">Salvar anotações</button></div>`
            : topic.notes ? `<p class="rt-notes">${escape(topic.notes)}</p>` : ''}
        </div></div>`).join('') : '<p class="rt-empty">Nenhum tema ainda. As cartas votadas viram temas quando a retro chega nesta etapa.</p>'}
      ${conduct ? `<div class="rt-add-topic"><input type="text" id="rt-topic-title" data-draft maxlength="500" placeholder="Novo tema (ex.: Organização da capacidade)" aria-label="Novo tema" value="${draft('rt-topic-title', '')}">
        <input type="text" id="rt-topic-question" data-draft maxlength="1000" placeholder="Pergunta-guia (opcional)" aria-label="Pergunta-guia" value="${draft('rt-topic-question', '')}">
        <button type="button" class="pp-primary" data-add-topic ${state.busy ? 'disabled' : ''}>Incluir tema</button></div>` : ''}`;
  }

  function focusStep(retro, readOnly) {
    const conduct = retro.canConduct && !readOnly;
    const next = retro.sprint + 1;
    return `${stepHead(`Escolha de foco para a Sprint ${next}`, 'Até dois focos, pelo impacto e pela viabilidade do que foi discutido')}
      <div class="rt-grid2">${[1, 2].map(slot => {
        const topic = retro.topics.find(item => item.focus === slot);
        return `<div class="rt-focus ${topic ? 'set' : ''}"><span>Foco ${slot}</span><b>${topic ? escape(topic.title) : 'Não escolhido'}</b>
          ${topic?.votes ? `<small>${topic.votes} voto(s)</small>` : ''}
          ${conduct ? `<select id="rt-focus-${slot}" data-focus-slot="${slot}" aria-label="Tema do foco ${slot}"><option value="">Escolher tema...</option>${retro.topics.map(item => `<option value="${item.id}" ${item.focus === slot ? 'selected' : ''}>${escape(item.title)}</option>`).join('')}</select>` : ''}
        </div>`;
      }).join('')}</div>`;
  }

  function planStep(retro, readOnly) {
    const conduct = retro.canConduct && !readOnly;
    const focusName = focus => focus ? (retro.topics.find(topic => topic.focus === focus)?.title ? `Foco ${focus}` : `Foco ${focus}`) : '—';
    const items = new Map();
    for (const row of (typeof DATA === 'undefined' ? [] : DATA)) {
      if ([retro.sprint, retro.sprint + 1].includes(Number(row.Sprint)) && row.Id && !items.has(String(row.Id))) items.set(String(row.Id), row);
    }
    return `${stepHead('Nosso plano de ação', 'As ações abrem a próxima retro e ficam em acompanhamento aqui e na Daily até serem concluídas')}
      ${retro.actions.length ? `<div class="rt-scroll"><table class="rt-table"><thead><tr><th>Ação</th><th>Responsável</th><th>Prazo</th><th>Sinal de resultado</th><th>Foco</th><th>Card</th>${conduct ? '<th></th>' : ''}</tr></thead><tbody>
        ${retro.actions.map(action => `<tr><td>${escape(action.description)}</td><td>${escape(action.responsible?.name || '—')}</td><td>${action.dueDate ? day(action.dueDate) : '—'}</td><td>${escape(action.signal || '—')}</td><td>${focusName(action.focus)}</td>
          <td>${action.item ? (window.ItemDetail?.link({id: action.item.id, name: action.item.name, url: action.item.url}) || escape(action.item.name)) : '—'}</td>
          ${conduct ? `<td><button type="button" class="rt-link" data-delete-action="${action.id}">remover</button></td>` : ''}</tr>`).join('')}
      </tbody></table></div>` : '<p class="rt-empty">Nenhuma ação no plano ainda.</p>'}
      ${conduct ? `<div class="rt-new-action">
        <label class="rt-field wide">Ação<input type="text" id="rt-new-description" data-draft maxlength="2000" value="${draft('rt-new-description', '')}" placeholder="O que vamos fazer"></label>
        <label class="rt-field">Responsável<select id="rt-new-responsible" data-draft><option value="">Sem responsável</option>${retro.users.map(user => `<option value="${user.id}" ${String(state.drafts['rt-new-responsible'] ?? '') === String(user.id) ? 'selected' : ''}>${escape(user.name)}</option>`).join('')}</select></label>
        <label class="rt-field">Prazo<input type="date" id="rt-new-due" data-draft value="${draft('rt-new-due', '')}"></label>
        <label class="rt-field wide">Sinal de resultado<input type="text" id="rt-new-signal" data-draft maxlength="1000" value="${draft('rt-new-signal', '')}" placeholder="Como saberemos que deu certo"></label>
        <label class="rt-field">Foco<select id="rt-new-focus" data-draft><option value="">—</option>${[1, 2].map(slot => `<option value="${slot}" ${String(state.drafts['rt-new-focus'] ?? '') === String(slot) ? 'selected' : ''}>Foco ${slot}</option>`).join('')}</select></label>
        <label class="rt-field">Card (opcional)<select id="rt-new-item" data-draft><option value="">Sem card</option>${[...items.values()].map(row => `<option value="${escape(row.Id)}" ${String(state.drafts['rt-new-item'] ?? '') === String(row.Id) ? 'selected' : ''}>#${escape(row.Id)} · ${escape(row.Nome)}</option>`).join('')}</select></label>
        <button type="button" class="pp-primary" data-add-action ${state.busy ? 'disabled' : ''}>Adicionar ação</button>
      </div>
      <label class="rt-field">Dependências a mapear<textarea id="rt-dependencies" data-draft rows="2" maxlength="4000">${draft('rt-dependencies', retro.dependencies)}</textarea></label>
      <div class="rt-inline-actions"><button type="button" class="pp-ghost" data-save-field="dependencies" data-source="rt-dependencies">Salvar dependências</button></div>`
      : retro.dependencies ? `<div class="rt-goal neutral"><span>Dependências a mapear</span><p>${escape(retro.dependencies)}</p></div>` : ''}`;
  }

  const STEP_RENDERERS = [openingStep, previousStep, dataStep, boardStep, discussionStep, focusStep, planStep];

  /** Rascunhos digitados voltam para os campos depois de cada redesenho, com o cursor no lugar. */
  function restoreDrafts() {
    const focused = state.focus;
    if (!focused) return;
    const field = el(focused.id);
    if (field && document.activeElement !== field) {
      field.focus();
      if (typeof field.setSelectionRange === 'function' && focused.start !== null) { try { field.setSelectionRange(focused.start, focused.end); } catch { /* campo sem seleção */ } }
    }
  }

  // ── Modo leitura ─────────────────────────────────────────────────────────────
  async function openReview(sprint) {
    state.review = {loading: true, sprint: {name: `Sprint ${sprint}`}};
    state.view = 'review';
    render();
    try {
      state.review = await mondayRequest(`${base()}/sprints/${sprint}`);
      state.error = '';
    } catch (error) {
      state.error = error.message; state.review = null; state.view = 'lobby';
    }
    render();
  }

  function renderReview(review) {
    el('rt-stepper').hidden = true;
    el('rt-stepper').innerHTML = '';
    if (review.loading) { el('rt-room-top').innerHTML = ''; el('rt-step').innerHTML = '<p class="pp-muted">Carregando a retro...</p>'; return; }
    const retro = review.retro;
    if (!retro) {
      el('rt-room-top').innerHTML = `<span class="rt-chip">Retro feita fora do app</span><span class="pp-muted">${day(review.sprint.start)} a ${day(review.sprint.end)}</span>`;
      el('rt-step').innerHTML = `${stepHead(`Dados da ${escape(review.sprint.name)}`, 'Indicadores calculados com as mesmas regras do Dashboard')}
        ${metricsHtml(metricsFor(review.sprint.number, review.dailyStats), false)}
        ${reviewHtml(review.review)}
        <div class="rt-goal"><span>Objetivo da sprint (da Sprint Planning)</span><p>${review.sprintGoal ? escape(review.sprintGoal) : '<em>Sem objetivo registrado na planning.</em>'}</p></div>`;
      return;
    }
    el('rt-room-top').innerHTML = `<span class="rt-chip ${retro.status === 'open' ? 'blue' : 'green'}">${retro.status === 'open' ? 'Em andamento' : 'Encerrada'}</span>
      <span class="pp-muted">Facilitador ${escape(retro.facilitator?.name || '—')} · ${day(retro.startedAt)}</span>
      ${window.Ceremony ? Ceremony.attendanceHtml(retro.attendees || [], retro.elapsedSeconds, {live: retro.status === 'open'}) : ''}`;
    el('rt-step').innerHTML = STEP_RENDERERS.map(renderer => `<div class="rt-review-block">${renderer(retro, true)}</div>`).join('');
  }

  // ── Conclusão, descarte e manutenção de ações ────────────────────────────────
  function findAction(id) {
    const all = [...(state.overview?.actions || []), ...(state.room?.retro?.previousActions || [])];
    return all.find(action => action.id === id);
  }

  function openActionDialog(id, mode, from) {
    const action = findAction(id);
    if (!action) return;
    state.dialog = {id, mode, from};
    const titles = {complete: ['Concluir ação', 'Resultado', 'Concluir'], drop: ['Descartar ação', 'Motivo', 'Descartar'], keep: ['Manter ação em andamento', 'Observação (opcional)', 'Manter']};
    const [title, field, submit] = titles[mode];
    el('rt-action-title').textContent = title;
    el('rt-action-sub').textContent = action.description;
    el('rt-action-signal').textContent = action.signal ? `Sinal de resultado: ${action.signal}` : '';
    el('rt-action-text-name').textContent = field;
    el('rt-action-text').value = '';
    el('rt-action-text-label').querySelector('.nt-req').hidden = mode === 'keep';
    el('rt-action-signal-box').hidden = mode !== 'complete';
    el('rt-action-signal-reached').checked = false;
    el('rt-action-due-box').hidden = mode !== 'keep';
    el('rt-action-due').value = '';
    el('rt-action-message').textContent = '';
    el('rt-action-submit').textContent = submit;
    el('rt-action-dialog').showModal();
    el('rt-action-text').focus();
  }

  async function submitActionDialog(event) {
    event.preventDefault();
    const {id, mode, from} = state.dialog || {};
    if (!id) return;
    const text = el('rt-action-text').value.trim();
    if (mode !== 'keep' && !text) { el('rt-action-message').textContent = mode === 'complete' ? 'Descreva o resultado para concluir.' : 'Informe o motivo para descartar.'; return; }
    const body = mode === 'complete' ? {result: text, signal_reached: el('rt-action-signal-reached').checked}
      : mode === 'drop' ? {reason: text} : {note: text || null, due_date: el('rt-action-due').value || null};
    if (await act(`/actions/${id}/${mode}`, {...body, from})) el('rt-action-dialog').close();
    else el('rt-action-message').textContent = state.error;
  }

  // ── Eventos ──────────────────────────────────────────────────────────────────
  const value = id => (el(id)?.value ?? '').trim();
  const clearDrafts = (...ids) => ids.forEach(id => { delete state.drafts[id]; });

  async function onClick(event) {
    const target = event.target;
    if (target.closest('[data-close]')) { target.closest('dialog')?.close(); return; }
    const retro = state.room?.retro;
    const view = target.closest('[data-view]');
    if (view) {
      state.view = view.dataset.view;
      state.merging = null;
      if (state.view === 'room') loadRoom(); else loadOverview();
      schedule();
      render();
      return;
    }
    const filter = target.closest('[data-filter]');
    if (filter) { state.filter = filter.dataset.filter; render(); return; }
    const review = target.closest('[data-review]');
    if (review) { openReview(Number(review.dataset.review)); return; }
    const start = target.closest('[data-start]');
    if (start) {
      if (await act('/retros', {sprint: Number(start.dataset.start)})) { state.view = 'room'; schedule(); render(); }
      return;
    }
    const mode = target.closest('[data-action-mode]');
    if (mode) { openActionDialog(Number(mode.dataset.action), mode.dataset.actionMode, mode.dataset.from); return; }
    const reopen = target.closest('[data-reopen]');
    if (reopen) { if (confirm('Reabrir esta ação? O resultado atual fica no histórico.')) act(`/actions/${reopen.dataset.reopen}/reopen`, {from: reopen.dataset.from}); return; }
    if (!retro) return;
    const step = target.closest('[data-step]');
    if (step && !step.disabled) { state.drafts = {}; act(`/retros/${retro.id}/step`, {step: Number(step.dataset.step)}); return; }
    if (target.closest('[data-close-retro]')) {
      if (!confirm(`Encerrar a retro da ${retro.sprintName}? Os indicadores ficam congelados e as ações vão para o acompanhamento.`)) return;
      if (await act(`/retros/${retro.id}/close`, {metrics: retro.metrics || metricsFor(retro.sprint, retro.dailyStats)})) { state.view = 'lobby'; state.drafts = {}; loadOverview(); schedule(); }
      return;
    }
    const editOpening = target.closest('[data-edit-opening]');
    if (editOpening) { state.editingOpening = editOpening.dataset.editOpening === 'on'; clearDrafts('rt-goal'); render(); return; }
    if (target.closest('[data-save-opening]')) {
      const rules = retro.rules.map((rule, index) => ({title: value(`rt-rule-title-${index}`) || rule.title, text: value(`rt-rule-text-${index}`) || rule.text}));
      if (await act(`/retros/${retro.id}/setup`, {goal: value('rt-goal'), rules})) { state.editingOpening = false; state.drafts = {}; render(); }
      return;
    }
    const saveField = target.closest('[data-save-field]');
    if (saveField) {
      if (await act(`/retros/${retro.id}/setup`, {[saveField.dataset.saveField]: value(saveField.dataset.source) || null})) { clearDrafts(saveField.dataset.source); render(); }
      return;
    }
    const goalVote = target.closest('[data-goal-vote]');
    if (goalVote) { act(`/retros/${retro.id}/goal-vote`, {vote: goalVote.dataset.goalVote}); return; }
    const addCard = target.closest('[data-add-card]');
    if (addCard) { submitCard(retro, addCard.dataset.addCard); return; }
    if (target.closest('[data-reveal]')) { act(`/retros/${retro.id}/reveal`); return; }
    const vote = target.closest('[data-vote]');
    if (vote) { act(`/cards/${vote.dataset.vote}/vote`); return; }
    const merge = target.closest('[data-merge]');
    if (merge) { const id = Number(merge.dataset.merge); state.merging = state.merging === id ? null : id; render(); return; }
    const mergeInto = target.closest('[data-merge-into]');
    if (mergeInto) { const card = state.merging; state.merging = null; act(`/cards/${card}/merge`, {target: Number(mergeInto.dataset.mergeInto)}); return; }
    const deleteCard = target.closest('[data-delete-card]');
    if (deleteCard) { if (confirm('Apagar esta carta?')) act(`/cards/${deleteCard.dataset.deleteCard}/delete`); return; }
    const notes = target.closest('[data-save-notes]');
    if (notes) {
      const id = `rt-notes-${notes.dataset.saveNotes}`;
      if (await act(`/topics/${notes.dataset.saveNotes}`, {notes: value(id) || null})) { clearDrafts(id); render(); }
      return;
    }
    if (target.closest('[data-add-topic]')) {
      if (!value('rt-topic-title')) { state.error = 'Informe o tema.'; render(); return; }
      if (await act(`/retros/${retro.id}/topics`, {title: value('rt-topic-title'), question: value('rt-topic-question') || null})) { clearDrafts('rt-topic-title', 'rt-topic-question'); render(); }
      return;
    }
    if (target.closest('[data-add-action]')) {
      if (!value('rt-new-description')) { state.error = 'Descreva a ação.'; render(); return; }
      const ok = await act(`/retros/${retro.id}/actions`, {
        description: value('rt-new-description'), responsible_id: value('rt-new-responsible') ? Number(value('rt-new-responsible')) : null, due_date: value('rt-new-due') || null,
        signal: value('rt-new-signal') || null, focus: value('rt-new-focus') ? Number(value('rt-new-focus')) : null, item_id: value('rt-new-item') || null,
      });
      if (ok) { clearDrafts('rt-new-description', 'rt-new-responsible', 'rt-new-due', 'rt-new-signal', 'rt-new-focus', 'rt-new-item'); render(); }
      return;
    }
    const deleteAction = target.closest('[data-delete-action]');
    if (deleteAction) { if (confirm('Remover esta ação do plano?')) act(`/actions/${deleteAction.dataset.deleteAction}/delete`); }
  }

  async function submitCard(retro, column) {
    const id = `rt-add-${column}`;
    if (!value(id)) { el(id)?.focus(); return; }
    if (await act(`/retros/${retro.id}/cards`, {column, text: value(id)})) { clearDrafts(id); render(); el(id)?.focus(); }
  }

  function onChange(event) {
    const slot = event.target.closest('[data-focus-slot]');
    const retro = state.room?.retro;
    if (slot && retro) {
      const topic = slot.value;
      const current = retro.topics.find(item => item.focus === Number(slot.dataset.focusSlot));
      if (topic) act(`/topics/${topic}`, {focus: Number(slot.dataset.focusSlot)});
      else if (current) act(`/topics/${current.id}`, {focus: null});
    }
  }

  function onInput(event) {
    const field = event.target;
    if (field.matches('[data-draft]') && field.id) state.drafts[field.id] = field.value;
    if (field.id) state.focus = {id: field.id, start: field.selectionStart ?? null, end: field.selectionEnd ?? null};
  }

  function onKeydown(event) {
    const input = event.target.closest('[id^="rt-add-"]');
    if (input && event.key === 'Enter' && state.room?.retro) { event.preventDefault(); submitCard(state.room.retro, input.id.replace('rt-add-', '')); }
  }

  /** Consulta a sala a cada poucos segundos dentro dela; na tela inicial, a cada 15 segundos (retro em andamento e ações). */
  function schedule() {
    clearInterval(state.roomTimer); clearInterval(state.lobbyTimer); clearInterval(state.clockTimer);
    if (!state.active) return;
    if (state.view === 'room') {
      state.roomTimer = setInterval(() => { if (!document.hidden && !state.busy) loadRoom(); }, POLL_MS);
      state.clockTimer = setInterval(renderClock, 1000);
    } else if (state.view === 'lobby') {
      state.lobbyTimer = setInterval(() => { if (!document.hidden && !state.busy) loadOverview(); }, LOBBY_POLL_MS);
    }
  }

  function enter() {
    state.active = true;
    if (state.view !== 'room') state.view = 'lobby';
    render();
    loadOverview();
    loadRoom();
    schedule();
  }
  function leave() {
    state.active = false;
    schedule();
    el('rt-action-dialog')?.close();
  }
  function onDataUpdated() { if (!state.active) return; if (holding()) state.pending = true; else render(); }

  document.addEventListener('DOMContentLoaded', () => {
    const root = page();
    if (!root) return;
    root.addEventListener('click', onClick);
    root.addEventListener('change', onChange);
    root.addEventListener('input', onInput);
    root.addEventListener('keydown', onKeydown);
    root.addEventListener('focusin', event => { if (event.target.id) state.focus = {id: event.target.id, start: event.target.selectionStart ?? null, end: event.target.selectionEnd ?? null}; });
    root.addEventListener('focusout', () => setTimeout(flushPending, 0));
    root.addEventListener('pointerdown', () => { state.pointer = true; });
    // O clique chega depois do soltar; o redesenho pendente só roda depois dele.
    document.addEventListener('pointerup', () => { if (state.pointer) setTimeout(() => { state.pointer = false; flushPending(); }, 0); });
    document.addEventListener('pointercancel', () => { state.pointer = false; });
    el('rt-action-form').addEventListener('submit', submitActionDialog);
    // Ao voltar de outra aba, a lista da tela inicial mostra se a sala abriu e o card certo.
    document.addEventListener('visibilitychange', () => { if (!document.hidden && state.active && state.view === 'lobby') loadOverview(); });
  });

  return {enter, leave, onDataUpdated, metricsFor, clock, timing};
})();
