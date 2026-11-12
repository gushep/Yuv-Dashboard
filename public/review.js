/*
 * Sprint Review. Tela inicial: resultado das reviews (aceite das entregas, feedbacks, objetivo), a review em andamento e
 * as últimas sprints. Sala: o Maestro Scrum conduz seis etapas com cronômetro; na demonstração escolhe a entrega da vez
 * (todos veem a mesma) e marca o aceite; qualquer participante registra feedback, que vira demanda no Funil de Demandas
 * só quando alguém pede. Olhinho: a review encerrada só para leitura (ou as entregas, quando foi feita fora do app).
 * Regras no servidor (SprintReviewService); a sala é consultada a cada poucos segundos.
 */
window.Review = (() => {
  const POLL_MS = 2500;
  const LOBBY_POLL_MS = 15000;
  const TOTAL_MINUTES = 60;
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const day = value => value ? value.slice(0, 10).split('-').reverse().slice(0, 2).join('/') : '—';
  const hours = value => { const number = parseFloat(String(value ?? '').replace(',', '.')); return Number.isFinite(number) ? `${number.toLocaleString('pt-BR', {maximumFractionDigits: 2})}h` : '—'; };
  const VERDICTS = [['accepted', '✓ Aceito'], ['adjust', '± Com ajustes'], ['rejected', '✕ Não aceito'], ['skipped', 'Não apresentado']];
  const VERDICT_LABEL = {accepted: 'aceito', adjust: 'com ajustes', rejected: 'não aceito', skipped: 'não apresentado'};
  const REASONS = [['capacity', 'Capacidade'], ['blocked', 'Impedimento'], ['reprioritized', 'Repriorizado'], ['scope', 'Escopo']];
  const DESTINATIONS = [['next', 'Próxima sprint'], ['backlog', 'Volta ao Backlog']];
  const TYPES = [['improvement', 'Melhoria'], ['bug', 'Bug'], ['idea', 'Nova ideia'], ['praise', 'Elogio']];
  const GOAL = {yes: ['green', 'Objetivo atingido'], partial: ['yellow', 'Objetivo parcial'], no: ['red', 'Objetivo não atingido']};
  const state = {active: false, overview: null, room: null, review: null, view: 'lobby', error: '', busy: false, request: 0,
    roomTimer: null, lobbyTimer: null, clockTimer: null, fetchedAt: 0, drafts: {}, pending: false, pointer: false};
  const page = () => el('page-sprint-review');
  const base = () => page().dataset.url.replace(/\/$/, '');
  const link = item => window.ItemDetail?.link({id: item.id, name: item.name, url: item.url}) || escape(item.name);

  // ── API ──────────────────────────────────────────────────────────────────────
  async function loadOverview() {
    try { state.overview = await mondayRequest(page().dataset.url); state.error = ''; } catch (error) { state.error = error.message; }
    render();
  }

  const typing = () => { const active = document.activeElement; return Boolean(active && el('rv-room')?.contains(active) && active.matches('input, textarea, select')); };
  /** Enquanto alguém digita ou está com o botão do mouse pressionado, a sala não é redesenhada (o clique não se perde). */
  const holding = () => typing() || state.pointer;

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
    if (holding()) { state.pending = true; tickClock(); return; }
    render();
  }

  function applyRoom(data) {
    state.room = data;
    state.fetchedAt = Date.now();
    if (!data.review && state.view === 'room') { state.view = 'lobby'; loadOverview(); }
  }

  /** Passo na sala: responde com a sala (tem `review` e `canFacilitate`) ou com o modo leitura. */
  async function act(path, body = {}) {
    if (state.busy) return false;
    state.busy = true; render();
    let ok = true;
    try {
      const data = await mondayRequest(`${base()}${path}`, body);
      if ('canFacilitate' in data) applyRoom(data); else state.review = data;
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
    state.pending = false;
    el('review-error').textContent = state.error;
    el('review-error').hidden = !state.error;
    const reviewing = state.view === 'readonly' && state.review;
    const review = state.view === 'room' ? state.room?.review : null;
    el('rv-lobby').hidden = Boolean(reviewing || review);
    el('rv-room').hidden = !(reviewing || review);
    el('rv-subtitle').textContent = reviewing ? `${state.review.sprint?.name || 'Sprint'} · somente leitura`
      : review ? `Review da ${review.sprintName} · facilitador ${review.facilitator?.name || 'Maestro Scrum'}` : 'O que o time entregou, o que acharam e o que vem a seguir';
    const buttons = [];
    if (reviewing || review) buttons.push('<button type="button" class="pp-ghost" data-view="lobby">Voltar às sprints</button>');
    if (review?.canConduct) buttons.push(`<button type="button" class="pp-danger" data-close-review ${state.busy ? 'disabled' : ''}>Encerrar review</button>`);
    if (!review && !reviewing && state.overview?.open) buttons.push(`<button type="button" class="pp-primary" data-view="room">Entrar na review da ${escape(state.overview.open.sprintName)}</button>`);
    el('rv-hero-actions').innerHTML = buttons.join('');
    if (reviewing) renderReadOnly(state.review); else if (review) renderRoom(review); else renderLobby(state.overview);
  }

  // ── Tela inicial ─────────────────────────────────────────────────────────────
  function renderLobby(data) {
    if (!data) { el('rv-history').innerHTML = '<p class="pp-muted">Carregando...</p>'; return; }
    const banner = el('rv-open-banner');
    banner.hidden = !data.open;
    if (data.open) banner.innerHTML = `<span class="rt-dot" aria-hidden="true"></span><span><strong>Review da ${escape(data.open.sprintName)} em andamento</strong> · facilitador ${escape(data.open.facilitator || '—')} · etapa ${data.open.step + 1} de 6 (${escape(data.open.stepName)})</span>
      ${window.Ceremony ? Ceremony.barHtml({present: data.open.present || [], seconds: data.open.elapsedSeconds || 0, label: 'Review', targetMinutes: TOTAL_MINUTES}) : ''}
      <button type="button" class="pp-primary" data-view="room">Entrar na review</button>`;
    const k = data.kpis;
    const card = (kind, label, value, detail, bar = null) => `<div class="rt-kpi ${kind}"><span>${label}</span><strong>${value}</strong><small>${detail}</small>${bar === null ? '' : `<span class="rt-bar" aria-hidden="true"><b style="width:${bar}%"></b></span>`}</div>`;
    el('rv-kpis').innerHTML = [
      card('blue', 'Reviews', k.reviews, 'encerradas no app'),
      card('green', 'Aceite das entregas', k.rate === null ? '—' : `${k.rate}%`, `${k.accepted} de ${k.presented} apresentadas aceitas`, k.rate ?? 0),
      card('', 'Com ajustes', k.adjust, `${k.rejected} não aceita(s)`),
      card('violet', 'Feedbacks', k.feedbacks, `${k.demands} virou(aram) demanda no Funil`),
      card('', 'Objetivo da sprint', k.goalDecided ? `${k.goalHit} de ${k.goalDecided}` : '—', 'sprints com o objetivo atingido'),
    ].join('');
    el('rv-history').innerHTML = data.sprints.length ? data.sprints.map(sprint => historyRow(sprint, data)).join('') : '<p class="pp-muted">Nenhuma sprint iniciada no cronograma.</p>';
  }

  const EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  function historyRow(sprint, data) {
    const review = sprint.review;
    const eye = `<button type="button" class="pp-eye" data-readonly="${sprint.number}" title="${review ? 'Ver como foi a review' : 'Ver as entregas da sprint'}" aria-label="Ver a review da ${escape(sprint.name)}">${EYE}</button>`;
    let action = '';
    if (review?.status === 'open') action = '<button type="button" class="pp-primary" data-view="room">Entrar na review</button>';
    else if (!review) {
      const why = !sprint.startable ? `Disponível no último dia da sprint (${day(sprint.end)}) ou quando ela for fechada no Monday` : !data.canFacilitate ? 'Só o Maestro Scrum inicia a review'
        : data.open ? `Encerre a review da ${data.open.sprintName} antes` : '';
      action = `<button type="button" class="pp-primary" data-start="${sprint.number}" ${why || state.busy ? `disabled title="${escape(why)}"` : ''}>Iniciar review</button>`;
    }
    const v = review?.verdicts;
    const counts = review ? [
      review.goalResult ? `<span class="rt-chip ${GOAL[review.goalResult][0]}">${GOAL[review.goalResult][1]}</span>` : '',
      review.delivered !== null ? `<span class="rt-chip">${review.delivered} entrega(s)</span>` : '',
      `<span class="rt-chip green">${v.accepted} aceita(s)</span>`, v.adjust ? `<span class="rt-chip yellow">${v.adjust} com ajustes</span>` : '', v.rejected ? `<span class="rt-chip red">${v.rejected} não aceita(s)</span>` : '',
    ].join('') : sprint.current ? '<span class="rt-chip blue">Sprint em andamento</span>' : '<span class="rt-chip">Review ainda não feita</span>';
    const progress = review?.status === 'open' ? `<span class="rt-chip blue">● Em andamento</span><small> · etapa ${review.step + 1} de 6 (${escape(review.stepName)})</small>`
      : review ? `<small><b>${review.feedbacks} feedback(s)</b>${review.demands ? ` · ${review.demands} virou(aram) demanda no Funil` : ''}</small>` : '<small>—</small>';
    return `<article class="rt-row ${review ? '' : 'none'}">
      <div><h3>${escape(sprint.name)}</h3><div class="rt-when">${day(sprint.start)} – ${day(sprint.end)}${review ? ` · review ${day(review.date)}` : ''}</div>
        ${review && window.Ceremony ? `<div class="rt-attendance">${Ceremony.attendanceHtml(review.attendees || [], review.durationSeconds, {live: review.status === 'open'})}</div>` : ''}</div>
      <div class="rt-counts">${counts}</div>
      <div class="rt-prog">${progress}</div>
      <div class="rt-row-buttons">${eye}${action}</div>
    </article>`;
  }

  // ── Sala ─────────────────────────────────────────────────────────────────────
  function timing(review) {
    const live = review.status === 'open' ? (Date.now() - state.fetchedAt) / 1000 : 0;
    const current = review.steps[review.step];
    return {stepSeconds: current.seconds + live, total: review.elapsedSeconds + live, current};
  }
  function tickClock() {
    const review = state.view === 'room' ? state.room?.review : null;
    if (!state.active || !review || !window.Ceremony) return;
    const time = timing(review);
    const clock = el('rv-room-top')?.querySelector('[data-cer-clock]');
    if (clock) clock.textContent = Ceremony.clock(time.total);
    const label = document.querySelector(`#rv-stepper [data-step="${review.step}"] small`);
    if (label) label.textContent = `${review.step + 1} · ${Ceremony.clock(time.stepSeconds)} de ${time.current.minutes} min`;
    const alert = el('rv-step-alert');
    if (alert) { const over = time.stepSeconds > time.current.minutes * 60; alert.hidden = !over; alert.textContent = over ? `⚠ ${time.current.name} estourou: ${Ceremony.clock(time.stepSeconds)} de ${time.current.minutes} min` : ''; }
  }

  function renderRoom(review) {
    const time = timing(review);
    el('rv-room-top').innerHTML = `${window.Ceremony ? Ceremony.barHtml({present: review.participants.map(person => person.name), seconds: time.total, label: 'Review', targetMinutes: TOTAL_MINUTES}) : ''}
      <span class="rt-chip red rt-alert" id="rv-step-alert" role="alert" hidden></span>`;
    el('rv-stepper').hidden = false;
    el('rv-stepper').innerHTML = review.steps.map((step, index) => `<li><button type="button" data-step="${index}" class="${index < review.step ? 'done' : ''}" ${index === review.step ? 'aria-current="step"' : ''} ${review.canConduct && !state.busy ? '' : 'disabled'}>
      <small>${index + 1}${index < review.step ? ' ✓' : ''} · ${window.Ceremony ? Ceremony.clock(step.seconds) : ''} de ${step.minutes} min</small><span>${escape(step.name)}</span></button></li>`).join('');
    const nav = review.canConduct ? `<div class="rt-step-nav">
        ${review.step > 0 ? `<button type="button" class="pp-ghost" data-step="${review.step - 1}" ${state.busy ? 'disabled' : ''}>← ${escape(review.steps[review.step - 1].name)}</button>` : '<span></span>'}
        ${review.step < review.steps.length - 1 ? `<button type="button" class="pp-primary" data-step="${review.step + 1}" ${state.busy ? 'disabled' : ''}>${escape(review.steps[review.step + 1].name)} →</button>`
          : `<button type="button" class="pp-danger" data-close-review ${state.busy ? 'disabled' : ''}>Encerrar review</button>`}
      </div>` : `<p class="rt-note">O Maestro Scrum ${escape(review.facilitator?.name || '')} conduz as etapas e marca o aceite; a tela acompanha.</p>`;
    el('rv-step').innerHTML = STEPS[review.step](review, false) + nav;
    tickClock();
  }

  const head = (title, detail) => `<div class="rt-step-head"><h2>${title}</h2><p>${detail}</p></div>`;
  const draft = (id, value) => escape(state.drafts[id] ?? value ?? '');
  const delivered = review => review.items.filter(item => item.kind === 'delivered');
  const pendingItems = review => review.items.filter(item => item.kind === 'pending');
  const goalBox = review => `<div class="rt-goal"><span>Objetivo da sprint (da Sprint Planning)</span><p>${review.sprintGoal ? escape(review.sprintGoal) : '<em>Sem objetivo registrado na planning.</em>'}</p></div>`;

  function openingStep(review) {
    return `${head('Abertura', 'O objetivo da sprint e quem está na review')}
      ${goalBox(review)}
      <div class="rt-grid4">
        <div class="rt-mini"><span>Entregas</span><strong>${delivered(review).length}</strong><small>cards Implementado na sprint</small></div>
        <div class="rt-mini"><span>Planejados abertos</span><strong>${pendingItems(review).length}</strong><small>planejados que ficaram sem entregar</small></div>
        <div class="rt-mini"><span>Feedbacks</span><strong>${review.feedbacks.length}</strong><small>registrados até agora</small></div>
        <div class="rt-mini"><span>Presentes</span><strong>${(review.status === 'open' ? review.participants.length : review.attendees.length)}</strong><small>${escape((review.status === 'open' ? review.participants.map(person => person.name) : review.attendees).join(', ') || '—')}</small></div>
      </div>`;
  }

  /** Mesmas contas do Dashboard e do Capacity (as da etapa Dados da retro); congeladas ao encerrar. */
  function resultStep(review, readOnly) {
    const metrics = review.metrics || window.Retro?.metricsFor?.(review.sprint) || null;
    const big = (value, label, detail) => `<div class="rt-big"><strong>${value}</strong><b>${label}</b><small>${detail}</small></div>`;
    const conduct = review.canConduct && !readOnly;
    const goal = (key, label, kind) => conduct ? `<button type="button" class="${kind}" data-goal="${key}" aria-pressed="${review.goalResult === key}" ${state.busy ? 'disabled' : ''}>${label}</button>`
      : `<button type="button" class="${kind}" aria-pressed="${review.goalResult === key}" disabled>${label}</button>`;
    return `${head('Resultado da sprint', metrics && review.metrics ? 'Congelado no encerramento da review' : 'Mesmos números do Dashboard e do Capacity')}
      ${metrics ? `<div class="rt-grid4">
        ${big(metrics.delivered, 'Entregas', 'cards Implementado na sprint')}
        ${big(metrics.plannedPct === null ? '—' : `${metrics.plannedPct}%`, 'Do planejado', `${metrics.plannedDone} de ${metrics.planned} planejados entregues`)}
        ${big(metrics.unplanned + metrics.support, 'Fora do planejado', `${metrics.support} de suporte · ${metrics.unplanned} não planejado(s)`)}
        ${big(window.Capacity ? Capacity.formatMinutes(Math.round(metrics.registeredMinutes)) : '—', 'Horas registradas', `de ${window.Capacity ? Capacity.formatMinutes(Math.round(metrics.plannedMinutes)) : '—'} planejadas`)}
      </div>` : '<p class="pp-muted">Sem dados sincronizados desta sprint.</p>'}
      ${goalBox(review)}
      <div class="rv-verdict"><span class="pp-muted">O objetivo da sprint foi atingido? (Maestro Scrum)</span>
        <div class="rt-seg" role="group" aria-label="Objetivo">${goal('yes', 'Sim', 'accepted')}${goal('partial', 'Parcial', 'adjust')}${goal('no', 'Não', 'rejected')}</div></div>
      ${conduct ? `<label class="rt-field">Por quê (opcional)<textarea id="rv-goal-note" data-draft rows="2" maxlength="2000">${draft('rv-goal-note', review.goalNote)}</textarea></label>`
        : review.goalNote ? `<div class="rt-goal neutral"><span>Por quê</span><p>${escape(review.goalNote)}</p></div>` : ''}`;
  }

  function demoStep(review, readOnly) {
    const items = delivered(review);
    if (!items.length) return `${head('Demonstração', 'Entregas da sprint')}<p class="rt-empty">Nenhuma entrega (card Implementado) nesta sprint.</p>`;
    const conduct = review.canConduct && !readOnly;
    const current = items.find(item => item.id === review.currentItemId) || items[0];
    const index = items.indexOf(current);
    const groups = new Map();
    for (const item of items) { const key = item.epic || item.type || 'Sem épico'; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(item); }
    const queue = [...groups.entries()].map(([group, list]) => `<h4>${escape(group)} · ${list.length}</h4>${list.map(item => `<button type="button" class="rv-q ${item.verdict || ''}" data-focus="${item.id}" ${item.id === current.id ? 'aria-current="true"' : ''} ${conduct ? '' : 'disabled'}>
      <b>${escape(item.name)}</b><small>${escape(item.owner || 'Sem responsável')} · ${escape(item.type || '—')}${item.verdict ? ` · ${VERDICT_LABEL[item.verdict]}` : ''}</small></button>`).join('')}`).join('');
    const verdicts = VERDICTS.map(([key, label]) => `<button type="button" class="${key}" ${conduct ? `data-verdict="${key}" data-item="${current.id}"` : 'disabled'} aria-pressed="${current.verdict === key}">${label}</button>`).join('');
    return `${head('Demonstração', `${items.length} entrega(s) · ${items.filter(item => item.verdict && item.verdict !== 'skipped').length} avaliada(s)`)}
      <div class="rv-demo">
        <div class="rv-queue" aria-label="Entregas da sprint">${queue}</div>
        <div class="rv-stage">
          <span class="rv-kind">${escape(current.type || 'Entrega')}${current.epic ? ` · ${escape(current.epic)}` : ''} · ${index + 1} de ${items.length}</span>
          <h3>${escape(current.name)}</h3>
          <div class="rv-meta"><span>👤 Apresenta: ${escape(current.owner || 'Sem responsável')}</span><span>⏱ ${hours(current.real)} de ${hours(current.estimate)} estimadas</span><span>${current.planned ? 'Planejada' : 'Fora do planejado'}</span>${current.url ? `<a href="${escape(current.url)}" target="_blank" rel="noopener">Abrir no Monday ↗</a>` : ''}</div>
          ${current.description ? `<div class="rv-scope"><b>Escopo do card</b>${escape(current.description)}</div>` : ''}
          <div class="rv-verdict"><span>Aceite</span><div class="rt-seg" role="group" aria-label="Aceite da entrega">${verdicts}</div></div>
          ${conduct ? `<label>Observações deste item<textarea id="rv-notes-${current.id}" data-draft rows="2" maxlength="4000">${draft(`rv-notes-${current.id}`, current.notes)}</textarea></label>`
            : current.notes ? `<div class="rv-scope"><b>Observações</b>${escape(current.notes)}</div>` : ''}
          ${readOnly ? '' : `<label>Feedback sobre esta entrega<input type="text" id="rv-stage-feedback" data-draft maxlength="2000" placeholder="O que foi dito sobre esta entrega" value="${draft('rv-stage-feedback', '')}"></label>`}
          <div class="rv-stage-nav">
            ${conduct ? `<button type="button" class="pp-ghost" data-focus="${items[Math.max(0, index - 1)].id}" ${index === 0 || state.busy ? 'disabled' : ''}>← Anterior</button>` : '<span></span>'}
            ${readOnly ? '' : `<button type="button" class="pp-ghost" data-stage-feedback="${current.id}" ${state.busy ? 'disabled' : ''}>＋ Registrar feedback</button>`}
            ${conduct ? `<button type="button" class="pp-ghost" data-save-notes="${current.id}" ${state.busy ? 'disabled' : ''}>Salvar observações</button>` : ''}
            ${conduct ? `<button type="button" class="pp-primary" data-focus="${items[Math.min(items.length - 1, index + 1)].id}" ${index === items.length - 1 || state.busy ? 'disabled' : ''}>Próxima entrega →</button>` : '<span></span>'}
          </div>
        </div>
      </div>`;
  }

  function pendingStep(review, readOnly) {
    const items = pendingItems(review);
    const conduct = review.canConduct && !readOnly;
    const select = (item, field, options, label) => conduct ? `<select class="rv-select" data-decide-field="${field}" data-item="${item.id}" aria-label="${label}"><option value="">—</option>${options.map(([key, text]) => `<option value="${key}" ${item[field] === key ? 'selected' : ''}>${text}</option>`).join('')}</select>`
      : escape(Object.fromEntries(options)[item[field]] || '—');
    return `${head('O que não foi entregue', 'Planejados que ficaram abertos, o motivo e o destino (só registro; o status no Monday não muda)')}
      ${items.length ? `<div class="rt-scroll"><table class="rt-table"><thead><tr><th>Card</th><th>Responsável</th><th>Status no Monday</th><th>Motivo</th><th>Destino</th></tr></thead><tbody>
        ${items.map(item => `<tr><td>${link(item)}</td><td>${escape(item.owner || '—')}</td><td><span class="rt-chip">${escape(item.status || '—')}</span></td><td>${select(item, 'reason', REASONS, 'Motivo')}</td><td>${select(item, 'destination', DESTINATIONS, 'Destino')}</td></tr>`).join('')}
      </tbody></table></div>` : '<p class="rt-empty">Todos os planejados foram entregues. 🎉</p>'}`;
  }

  function feedbackStep(review, readOnly) {
    const rows = review.feedbacks.map(feedback => {
      const demand = feedback.demand;
      const status = demand.status === 'created' ? `<span class="rt-chip violet">Virou demanda${demand.id ? ` #${escape(demand.id)}` : ''}</span>${demand.url ? ` <a href="${escape(demand.url)}" target="_blank" rel="noopener">↗</a>` : ''}`
        : demand.status === 'failed' ? '<span class="rt-chip red">Falha ao criar no Funil</span>' : demand.status === 'sending' ? '<span class="rt-chip">Criando...</span>' : '<span class="rt-chip">Só registrado</span>';
      const canDemand = review.canCreateDemands && [null, 'failed'].includes(demand.status);
      return `<div class="rv-feedback"><div><b>${escape(feedback.text)}</b><small>${escape(feedback.givenBy || feedback.author || '—')} · ${escape(feedback.typeLabel)}${feedback.item ? ` · entrega: ${escape(feedback.item.name)}` : ''}</small></div>
        <div>${status}</div>
        <div class="rt-row-buttons">${canDemand ? `<button type="button" class="pp-primary rt-small" data-demand="${feedback.id}" ${state.busy ? 'disabled' : ''} title="Cria a demanda no Funil de Demandas do Monday">Criar demanda no Funil</button>` : ''}
          ${!readOnly && feedback.canDelete ? `<button type="button" class="pp-ghost rt-small" data-delete-feedback="${feedback.id}">Apagar</button>` : ''}</div></div>`;
    }).join('');
    const items = delivered(review);
    return `${head('Feedbacks', 'Cada feedback pode virar demanda no Funil de Demandas, só quando alguém pedir')}
      ${rows ? `<div>${rows}</div>` : '<p class="rt-empty">Nenhum feedback registrado.</p>'}
      ${readOnly ? '' : `<div class="rv-feedback-form">
        <label class="rt-field">Feedback<input type="text" id="rv-fb-text" data-draft maxlength="2000" placeholder="O que foi dito" value="${draft('rv-fb-text', '')}"></label>
        <label class="rt-field">Quem deu<select id="rv-fb-who" data-draft>${review.users.map(user => `<option value="${user.id}" ${String(state.drafts['rv-fb-who'] ?? review.me.id) === String(user.id) ? 'selected' : ''}>${escape(user.name)}</option>`).join('')}</select></label>
        <label class="rt-field">Tipo<select id="rv-fb-type" data-draft>${TYPES.map(([key, label]) => `<option value="${key}" ${(state.drafts['rv-fb-type'] ?? 'improvement') === key ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <label class="rt-field">Entrega (opcional)<select id="rv-fb-item" data-draft><option value="">—</option>${items.map(item => `<option value="${item.id}" ${String(state.drafts['rv-fb-item'] ?? '') === item.id ? 'selected' : ''}>${escape(item.name)}</option>`).join('')}</select></label>
        <button type="button" class="pp-primary" data-add-feedback ${state.busy ? 'disabled' : ''}>Registrar</button>
      </div>
      ${review.canCreateDemands ? '' : '<p class="rt-note">Criar demanda no Funil exige a permissão "Atualizar cards".</p>'}`}`;
  }

  function nextStep(review) {
    const items = delivered(review);
    const count = key => items.filter(item => item.verdict === key).length;
    const next = pendingItems(review).filter(item => item.destination === 'next');
    const demands = review.feedbacks.filter(feedback => feedback.demand.status === 'created');
    return `${head('Próximos passos', 'O que segue para a próxima Sprint Planning')}
      <div class="rt-goal neutral"><span>Resumo da review</span><p>${items.length} entrega(s) · ${count('accepted')} aceita(s), ${count('adjust')} com ajustes, ${count('rejected')} não aceita(s)${review.goalResult ? ` · ${GOAL[review.goalResult][1].toLowerCase()}` : ''} · ${review.feedbacks.length} feedback(s), ${demands.length} virou(aram) demanda</p></div>
      <div class="rt-grid2">
        <div class="rt-panel"><b>Voltam na próxima sprint</b>${next.length ? `<ul>${next.map(item => `<li>${link(item)}</li>`).join('')}</ul>` : '<p class="rt-empty">Nenhum planejado marcado para a próxima sprint.</p>'}</div>
        <div class="rt-panel"><b>Demandas criadas no Funil</b>${demands.length ? `<ul>${demands.map(feedback => `<li>${escape(feedback.text)}${feedback.demand.id ? ` · #${escape(feedback.demand.id)}` : ''}</li>`).join('')}</ul>` : '<p class="rt-empty">Nenhum feedback virou demanda.</p>'}</div>
      </div>
      ${review.status === 'open' ? '<p class="rt-note">Encerrar congela o resultado e a lista de entregas; o aceite também aparece na etapa Dados da retrospectiva.</p>' : ''}`;
  }

  const STEPS = [openingStep, resultStep, demoStep, pendingStep, feedbackStep, nextStep];

  // ── Modo leitura ─────────────────────────────────────────────────────────────
  async function openReadOnly(sprint) {
    state.review = {loading: true, sprint: {name: `Sprint ${sprint}`}};
    state.view = 'readonly';
    render();
    try { state.review = await mondayRequest(`${base()}/sprints/${sprint}`); state.error = ''; } catch (error) { state.error = error.message; state.review = null; state.view = 'lobby'; }
    render();
  }

  function renderReadOnly(data) {
    el('rv-stepper').hidden = true;
    el('rv-stepper').innerHTML = '';
    if (data.loading) { el('rv-room-top').innerHTML = ''; el('rv-step').innerHTML = '<p class="pp-muted">Carregando a review...</p>'; return; }
    const review = data.review;
    if (!review) {
      el('rv-room-top').innerHTML = '<div class="cer-bar"><span class="rt-chip">Review feita fora do app</span></div>';
      const fake = {items: data.items, sprintGoal: data.sprintGoal, sprint: data.sprint.number, feedbacks: [], canConduct: false, metrics: null, goalResult: null, goalNote: null};
      el('rv-step').innerHTML = `${resultStep(fake, true)}<div class="rt-review-block">${head('Entregas', `${delivered(fake).length} card(s) Implementado`)}
        <ul>${delivered(fake).map(item => `<li>${link(item)} <small class="pp-muted">${escape(item.owner || '')}</small></li>`).join('') || '<li>Nenhuma entrega.</li>'}</ul></div>
        <div class="rt-review-block">${pendingStep(fake, true)}</div>`;
      return;
    }
    el('rv-room-top').innerHTML = `<div class="cer-bar"><span class="rt-chip ${review.status === 'open' ? 'blue' : 'green'}">${review.status === 'open' ? 'Em andamento' : 'Encerrada'}</span>
      <span class="pp-muted">Facilitador ${escape(review.facilitator?.name || '—')} · ${day(review.startedAt)}</span>
      ${window.Ceremony ? Ceremony.attendanceHtml(review.attendees || [], review.elapsedSeconds, {live: review.status === 'open'}) : ''}</div>`;
    el('rv-step').innerHTML = STEPS.map(renderer => `<div class="rt-review-block">${renderer(review, true)}</div>`).join('');
  }

  // ── Eventos ──────────────────────────────────────────────────────────────────
  const value = id => (el(id)?.value ?? '').trim();
  const clear = (...ids) => ids.forEach(id => { delete state.drafts[id]; });

  async function onClick(event) {
    const target = event.target;
    const view = target.closest('[data-view]');
    if (view) { state.view = view.dataset.view; if (state.view === 'room') loadRoom(); else loadOverview(); schedule(); render(); return; }
    const readonly = target.closest('[data-readonly]');
    if (readonly) { openReadOnly(Number(readonly.dataset.readonly)); return; }
    const start = target.closest('[data-start]');
    if (start) { if (await act('/reviews', {sprint: Number(start.dataset.start)})) { state.view = 'room'; schedule(); render(); } return; }
    const demand = target.closest('[data-demand]');
    if (demand) { act(`/feedbacks/${demand.dataset.demand}/demand`); return; }
    const review = state.room?.review;
    if (!review || state.view !== 'room') return;
    const step = target.closest('[data-step]');
    if (step && !step.disabled) { state.drafts = {}; act(`/reviews/${review.id}/step`, {step: Number(step.dataset.step)}); return; }
    if (target.closest('[data-close-review]')) {
      if (!confirm(`Encerrar a review da ${review.sprintName}? O resultado e a lista de entregas ficam congelados.`)) return;
      if (await act(`/reviews/${review.id}/close`, {metrics: review.metrics || window.Retro?.metricsFor?.(review.sprint) || null})) { state.view = 'lobby'; state.drafts = {}; loadOverview(); schedule(); }
      return;
    }
    const goal = target.closest('[data-goal]');
    if (goal) { act(`/reviews/${review.id}/goal`, {result: goal.dataset.goal, note: value('rv-goal-note') || review.goalNote || null}); return; }
    const focus = target.closest('[data-focus]');
    if (focus && !focus.disabled) { act(`/reviews/${review.id}/focus`, {item_id: focus.dataset.focus}); return; }
    const verdict = target.closest('[data-verdict]');
    if (verdict) { act(`/reviews/${review.id}/decide`, {item_id: verdict.dataset.item, verdict: verdict.dataset.verdict}); return; }
    const notes = target.closest('[data-save-notes]');
    if (notes) { const id = `rv-notes-${notes.dataset.saveNotes}`; if (await act(`/reviews/${review.id}/decide`, {item_id: notes.dataset.saveNotes, notes: value(id) || null})) { clear(id); render(); } return; }
    const stageFeedback = target.closest('[data-stage-feedback]');
    if (stageFeedback) {
      if (!value('rv-stage-feedback')) { el('rv-stage-feedback')?.focus(); return; }
      if (await act(`/reviews/${review.id}/feedbacks`, {text: value('rv-stage-feedback'), type: 'improvement', given_by: review.me.id, item_id: stageFeedback.dataset.stageFeedback})) { clear('rv-stage-feedback'); render(); }
      return;
    }
    if (target.closest('[data-add-feedback]')) {
      if (!value('rv-fb-text')) { state.error = 'Escreva o feedback.'; render(); return; }
      const ok = await act(`/reviews/${review.id}/feedbacks`, {text: value('rv-fb-text'), type: value('rv-fb-type') || 'improvement', given_by: value('rv-fb-who') ? Number(value('rv-fb-who')) : null, item_id: value('rv-fb-item') || null});
      if (ok) { clear('rv-fb-text', 'rv-fb-item'); render(); }
      return;
    }
    const remove = target.closest('[data-delete-feedback]');
    if (remove && confirm('Apagar este feedback?')) act(`/feedbacks/${remove.dataset.deleteFeedback}/delete`);
  }

  function onChange(event) {
    const field = event.target.closest('[data-decide-field]');
    const review = state.room?.review;
    if (field && review) act(`/reviews/${review.id}/decide`, {item_id: field.dataset.item, [field.dataset.decideField]: field.value || null});
  }

  function onInput(event) {
    if (event.target.matches('[data-draft]') && event.target.id) state.drafts[event.target.id] = event.target.value;
  }

  function flushPending() { if (!holding() && state.pending) render(); }

  function schedule() {
    clearInterval(state.roomTimer); clearInterval(state.lobbyTimer); clearInterval(state.clockTimer);
    if (!state.active) return;
    if (state.view === 'room') {
      state.roomTimer = setInterval(() => { if (!document.hidden && !state.busy) loadRoom(); }, POLL_MS);
      state.clockTimer = setInterval(tickClock, 1000);
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
  function leave() { state.active = false; schedule(); }
  function onDataUpdated() { if (!state.active) return; if (holding()) state.pending = true; else render(); }

  document.addEventListener('DOMContentLoaded', () => {
    const root = page();
    if (!root) return;
    root.addEventListener('click', onClick);
    root.addEventListener('change', onChange);
    root.addEventListener('input', onInput);
    root.addEventListener('focusout', () => setTimeout(flushPending, 0));
    root.addEventListener('pointerdown', () => { state.pointer = true; });
    document.addEventListener('pointerup', () => { if (state.pointer) setTimeout(() => { state.pointer = false; flushPending(); }, 0); });
    document.addEventListener('pointercancel', () => { state.pointer = false; });
  });

  return {enter, leave, onDataUpdated};
})();
