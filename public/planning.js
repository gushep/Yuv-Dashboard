/* Planning poker como mesa de jogo. Entrada: últimas sprints com horas planejadas e executadas e o botão Planejar nova sprint
 * (Maestro Scrum). Mesa: o Maestro joga no centro uma carta da mão (Backlog do quadro Tarefas), o time lê o escopo, cada
 * pessoa joga a carta com as horas virada para baixo, o Maestro revela e escolhe entre a estimativa da IA e a média do time.
 * As regras ficam no servidor (PlanningService); a tela consulta a mesa a cada poucos segundos enquanto está aberta. */
window.Planning = (() => {
  const POLL_MS = 2500;
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const state = {active:false, data:null, history:null, review:null, error:'', busy:false, timer:null, request:0, view:null, openSprint:null, search:'', scopes:{}, note:''};

  const hours = value => value === null || value === undefined ? '—' : `${Number(value).toLocaleString('pt-BR', {maximumFractionDigits:2})}h`;
  const shortDate = value => value ? value.split('-').reverse().slice(0, 2).join('/') : '';
  const initials = name => String(name || '?').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
  const base = () => el('page-planning').dataset.url.replace(/\/$/, '');
  const conduct = () => Boolean(state.data?.canConduct);

  async function load() {
    const request = ++state.request;
    try {
      const data = await mondayRequest(el('page-planning').dataset.url);
      if (request !== state.request || state.busy) return;
      const wasOpen = Boolean(state.data?.session);
      state.data = data; state.error = '';
      // Quando a mesa abre, todos vão para ela; quando fecha, voltam às sprints. Quem está vendo uma planning pelo
      // olhinho continua nela até voltar.
      if (state.view !== 'review') {
        if (data.session && (!wasOpen || state.view === null)) state.view = 'table';
        if (!data.session) state.view = 'lobby';
      }
      if (!data.session && wasOpen) loadHistory();
    } catch (error) {
      if (request === state.request) state.error = error.message;
    }
    render();
  }

  async function loadHistory() {
    try {
      state.history = (await mondayRequest(el('page-planning').dataset.historyUrl)).sprints;
    } catch (error) {
      state.error = error.message;
    }
    render();
  }

  /** Ação na mesa: manda, recebe a mesa atualizada e redesenha; o erro fica no aviso da tela. */
  async function act(path, body) {
    if (state.busy) return false;
    state.busy = true; render();
    let ok = true;
    try {
      state.data = await mondayRequest(base() + path, body);
      state.error = '';
      state.view = state.data.session ? 'table' : 'lobby';
    } catch (error) {
      state.error = error.message; ok = false;
    } finally {
      state.busy = false; state.request++;
      render();
    }
    return ok;
  }

  function render() {
    if (!state.active) return;
    el('planning-error').textContent = state.error;
    el('planning-error').hidden = !state.error;
    const data = state.data;
    const reviewing = state.view === 'review' && state.review;
    const table = !reviewing && state.view === 'table' && data?.session;
    el('pp-lobby').hidden = Boolean(table || reviewing);
    el('pp-table-view').hidden = !(table || reviewing);
    el('pp-table-view').classList.toggle('reviewing', Boolean(reviewing));
    el('pp-subtitle').textContent = reviewing ? `Planning da Sprint ${state.review.sprint} · somente leitura`
      : table ? `Planning da ${data.session.sprintName} · conduzida por ${data.session.startedBy || 'Maestro Scrum'}` : 'Planejamento das sprints com o time na mesa';
    renderHeroActions(data, table, reviewing);
    if (reviewing) renderReview(state.review); else if (table) renderTable(data); else renderLobby(data);
  }

  function renderHeroActions(data, table, reviewing = false) {
    const buttons = [];
    if (reviewing) {
      buttons.push('<button type="button" class="pp-ghost" data-view="lobby">Voltar às últimas sprints</button>');
      if (data?.session?.sprint === state.review.sprint) buttons.push('<button type="button" class="pp-primary" data-view="table">Entrar na mesa</button>');
    } else if (table) {
      buttons.push('<button type="button" class="pp-ghost" data-view="lobby">Ver últimas sprints</button>');
      if (conduct()) buttons.push(`<button type="button" class="pp-danger" data-close-table ${state.busy ? 'disabled' : ''}>Encerrar planning</button>`);
    } else if (data?.session) {
      buttons.push(`<button type="button" class="pp-primary" data-view="table">Entrar na mesa da ${escape(data.session.sprintName)}</button>`);
    } else if (conduct()) {
      buttons.push('<button type="button" class="pp-primary" data-new-sprint>🃏 Planejar nova sprint</button>');
    }
    el('pp-hero-actions').innerHTML = buttons.join('');
  }

  // ── Entrada ──────────────────────────────────────────────────────────────────
  function renderLobby(data) {
    const banner = el('pp-open-banner');
    banner.hidden = !data?.session;
    if (data?.session) banner.innerHTML = `<strong>Planning da ${escape(data.session.sprintName)} em andamento.</strong> <button type="button" class="pp-primary" data-view="table">Entrar na mesa</button>`;
    else if (data && !conduct()) { banner.hidden = false; banner.innerHTML = 'Nenhuma planning em andamento. Quando o Maestro Scrum abrir a mesa, ela aparece aqui.'; }
    if (!state.history) { el('pp-history').innerHTML = '<p class="pp-muted">Carregando...</p>'; return; }
    if (!state.history.length) { el('pp-history').innerHTML = '<p class="pp-muted">Nenhuma sprint iniciada no cronograma.</p>'; return; }
    // Horas de cada sprint na mesma tabela da barra superior (Capacidade, Full stack, Bombeiro e Projetos × Planejado e
    // Realizado, com Disponível); a reserva do Bombeiro vem da tela Consumo de horas.
    const strip = window.HoursStrip;
    if (strip && !state.reservesRequested) { state.reservesRequested = true; strip.loadReserves().then(() => { if (state.active) render(); }); }
    el('pp-history').innerHTML = state.history.map(sprint => {
      const open = state.openSprint === sprint.number;
      const usage = sprint.plannedHours > 0 ? Math.round(sprint.executedHours / sprint.plannedHours * 100) : null;
      const table = strip?.tableHtml(sprint.number, {link: false}) || '';
      return `<div class="pp-sprint ${open ? 'open' : ''} ${sprint.current ? 'current' : ''}">
        <div class="pp-sprint-head">
          <button type="button" class="pp-sprint-row" data-sprint="${sprint.number}" aria-expanded="${open}" title="${open ? 'Esconder' : 'Ver'} as tarefas da sprint">
            <span class="pp-sprint-name">${escape(sprint.name)}${sprint.current ? ' <em class="pp-sprint-tag">em andamento</em>' : ''}<small>${shortDate(sprint.start)} a ${shortDate(sprint.end)} · ${sprint.cards} card(s)</small></span>
            ${table ? '' : `<span class="pp-sprint-fact"><small>Planejadas</small>${hours(sprint.plannedHours)}</span>
            <span class="pp-sprint-fact"><small>Executadas</small>${hours(sprint.executedHours)}</span>
            <span class="pp-sprint-fact"><small>Executado / planejado</small>${usage === null ? '—' : `${usage}%`}</span>`}
            <span class="pp-sprint-caret" aria-hidden="true">▾</span>
          </button>
          <div class="pp-sprint-actions">${sprintAction(sprint, data)}</div>
        </div>
        ${sprint.planning?.goal ? `<p class="pp-sprint-goal"><strong>🎯 Objetivo da Sprint</strong>${escape(sprint.planning.goal)}</p>` : ''}
        ${table ? `<div class="pp-sprint-hours">${table}</div>` : ''}
        ${open ? renderSprintTasks(sprint) : ''}
      </div>`;
    }).join('');
  }

  /**
   * Ação de cada sprint da lista. Em andamento: "Entrar na mesa" até a sprint terminar (o Maestro abre ou reabre a mesa;
   * o time entra quando ela está aberta). Encerrada: o olhinho mostra como foi a planning, só para leitura.
   */
  function sprintAction(sprint, data) {
    const planning = sprint.planning;
    // O olhinho abre a mesa da sprint só para leitura; sem planning no app, mostra a carga do time e os cards do Monday.
    const eye = `<button type="button" class="pp-eye" data-review="${sprint.number}" title="${planning ? `Ver como foi a planning (${planning.estimated} card(s) estimados)` : 'Ver a carga do time e os cards da sprint (planejada fora do app)'}" aria-label="Ver a planning da ${escape(sprint.name)}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>`;
    if (!sprint.current) return eye;
    // Em andamento: o olhinho fica ao lado de "Entrar na mesa" (ver sem entrar na mesa).
    const peek = eye;
    if (data?.session?.sprint === sprint.number) return `${peek}<button type="button" class="pp-primary" data-view="table">Entrar na mesa</button>`;
    if (conduct()) return `${peek}<button type="button" class="pp-primary" data-enter-sprint="${sprint.number}" ${state.busy || data?.session ? 'disabled' : ''} title="${data?.session ? `Encerre a planning da ${escape(data.session.sprintName)} antes` : planning ? 'Reabrir a mesa desta sprint, com as estimativas já feitas' : 'Abrir a mesa desta sprint'}">Entrar na mesa</button>`;
    return `${peek}<button type="button" class="pp-primary" disabled title="A mesa abre quando o Maestro Scrum entrar">Entrar na mesa</button>`;
  }

  /**
   * Olhinho: abre a mesa daquela sprint só para leitura, com a carga do time e cada card estimado (cartas do time,
   * média, IA e decisão). Nada pode ser jogado nem alterado nesse modo.
   */
  async function openReview(sprint) {
    state.review = {sprint, loading: true};
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
    el('pp-table-view').classList.add('reviewing');
    el('pp-seats').innerHTML = '';
    el('pp-controls').hidden = true;
    el('pp-hand-section').hidden = true;
    if (review.loading) {
      el('pp-center').innerHTML = '<p class="pp-review-banner">Carregando a planning...</p>';
      el('pp-load').hidden = true; el('pp-estimated').hidden = true;
      return;
    }
    const name = review.name || `Sprint ${review.sprint}`;
    const total = review.rounds.reduce((sum, round) => sum + (round.finalEstimate || 0), 0);
    const section = el('pp-estimated');
    section.hidden = false;
    renderLoad({session: {sprintName: name}, round: null, load: review.load || [], reserveHours: review.reserveHours || 0});
    // Objetivo da Sprint (registrado ao encerrar a planning); sprints antigas não têm.
    const goal = `<span class="pp-review-goal"><b>🎯 Objetivo da Sprint:</b> ${review.goal ? escape(review.goal) : '<em>sem objetivo registrado</em>'}</span>`;
    const dailies = window.Daily?.historyHtml(review.dailies || []) || '';
    if (!review.recorded) {
      // Planejada fora do app: sem cartas nem decisões; os cards da sprint com o Tempo estimado do Monday.
      const cards = review.cards || [];
      const estimate = cards.reduce((sum, card) => sum + (card.estimate || 0), 0);
      el('pp-center').innerHTML = `<p class="pp-review-banner"><strong>Planning da ${escape(name)} · somente leitura</strong>
        <span>Planejada fora do app: sem cartas nem decisões registradas · ${cards.length} card(s) na sprint · ${hours(estimate)} estimadas no Monday</span>${goal}</p>`;
      section.innerHTML = `<h2>Cards da sprint · ${cards.length} card(s) · ${hours(estimate)} <small class="pp-review-hint">Tempo estimado do Monday</small></h2>
        <div class="pp-estimated-list">${cards.length ? cards.map(card => `<span class="pp-estimated-item">
          ${card.url ? `<a href="${escape(card.url)}" target="_blank" rel="noopener">${escape(card.name)}</a>` : escape(card.name)}
          <strong>${card.estimate === null ? '—' : hours(card.estimate)}</strong><small>${escape(card.owner || 'sem responsável')}</small></span>`).join('') : '<p class="pp-muted">Nenhum card nesta sprint.</p>'}</div>${dailies}`;
      return;
    }
    el('pp-center').innerHTML = `<p class="pp-review-banner"><strong>Planning da ${escape(name)} · somente leitura</strong>
      <span>${review.rounds.length} card(s) estimados · ${hours(total)}${review.startedBy ? ` · conduzida por ${escape(review.startedBy)}` : ''}${review.open ? ' · mesa aberta agora' : review.closedAt ? ` · encerrada em ${new Date(review.closedAt).toLocaleDateString('pt-BR')}` : ''}</span>${goal}</p>`;
    section.innerHTML = `<h2>Estimados nesta planning · ${review.rounds.length} card(s) · ${hours(total)}</h2>
      <div class="pp-review-body">${reviewCards(review.rounds)}</div>${dailies}`;
  }

  /** Cada card estimado com a estimativa final, a IA, a média, quem decidiu, as cartas de cada pessoa e a discussão. */
  function reviewCards(rounds) {
    return rounds.length ? rounds.map(round => `<article class="pp-review-card">
          <header><strong>${round.url ? `<a href="${escape(round.url)}" target="_blank" rel="noopener">${escape(round.name)} ↗</a>` : escape(round.name)}</strong>${round.owner ? `<small>${escape(round.owner)}</small>` : ''}</header>
          <dl>
            <div><dt>Estimativa final</dt><dd><b>${hours(round.finalEstimate)}</b> ${round.finalSource === 'ai' ? '(IA)' : '(média do time)'}</dd></div>
            <div><dt>IA</dt><dd>${round.aiEstimate === null ? '—' : hours(round.aiEstimate)}</dd></div>
            <div><dt>Média do time</dt><dd>${round.average === null ? '—' : hours(round.average)}</dd></div>
            <div><dt>Decidido por</dt><dd>${escape(round.decidedBy || '—')}</dd></div>
          </dl>
          <p class="pp-review-votes">${round.votes.length ? round.votes.map(vote => `<span>${escape(vote.name)} <b>${vote.hours === null ? 'vazio' : hours(vote.hours)}</b></span>`).join('') : 'Sem cartas registradas.'}</p>
          ${round.note ? `<p class="pp-review-note">${escape(round.note)}</p>` : ''}
        </article>`).join('') : '<p class="pp-muted">Nenhum card ficou estimado nesta planning.</p>';
  }

  function renderSprintTasks(sprint) {
    if (!sprint.tasks.length) return '<p class="pp-muted pp-sprint-empty">Nenhuma tarefa nesta sprint.</p>';
    const rows = [...sprint.tasks].sort((a, b) => (b.planned || 0) - (a.planned || 0)).map(task => `<tr>
        <td>${task.url ? `<a href="${escape(task.url)}" target="_blank" rel="noopener">${escape(task.name)}</a>` : escape(task.name)}</td>
        <td>${escape(task.type || '—')}</td><td>${escape(task.status || '—')}</td><td>${escape(task.owner || '—')}</td>
        <td class="pp-num">${hours(task.planned)}</td><td class="pp-num">${hours(task.executed)}</td></tr>`).join('');
    return `<div class="tscroll"><table class="pp-sprint-table"><thead><tr><th>Tarefa</th><th>Tipo</th><th>Status</th><th>Responsável</th><th class="pp-num">Planejadas</th><th class="pp-num">Executadas</th></tr></thead>
      <tbody>${rows}</tbody><tfoot><tr><td colspan="4">Total · ${sprint.cards} tarefa(s)</td><td class="pp-num">${hours(sprint.plannedHours)}</td><td class="pp-num">${hours(sprint.executedHours)}</td></tr></tfoot></table></div>`;
  }

  // ── Mesa ─────────────────────────────────────────────────────────────────────
  function renderTable(data) {
    renderSeats(data);
    renderCenter(data);
    renderControls(data);
    renderLoad(data);
    renderHand(data);
    renderEstimated(data);
  }

  /**
   * Lugares em volta da mesa: a própria pessoa embaixo e os demais em sentido horário. Cada pessoa fica sentada do lado
   * de fora do feltro, e a carta dela fica no feltro, à frente do lugar.
   */
  function renderSeats(data) {
    const seats = [...data.seats];
    const meIndex = seats.findIndex(seat => seat.isMe);
    if (meIndex > 0) seats.unshift(...seats.splice(meIndex, 1));
    const total = Math.max(seats.length, 1);
    const round = data.round;
    el('pp-seats').innerHTML = seats.map((seat, index) => {
      const angle = Math.PI / 2 + index * 2 * Math.PI / total;
      const at = (rx, ry) => `left:${50 + rx * Math.cos(angle)}%;top:${50 + ry * Math.sin(angle)}%`;
      // Só o time de desenvolvimento tem lugar para carta; quem conduz e quem assiste não jogam.
      let card = '';
      if (seat.role === 'estimator') {
        card = '<span class="pp-slot" aria-hidden="true"></span>';
        if (round?.status === 'revealed' && seat.played) card = seat.hours !== null ? `<span class="pp-mini-card pp-face-up">${hours(seat.hours)}</span>` : '<span class="pp-mini-card pp-face-up pp-empty-vote" title="Sem contexto">vazio</span>';
        else if (seat.played) card = `<span class="pp-mini-card pp-face-down" title="${escape(seat.name)} jogou a carta"></span>`;
      }
      const role = {conductor:' <em>Conduzindo</em>', watcher:' <em class="pp-watch">Assistindo</em>'}[seat.role] || '';
      return `<div class="pp-seat ${seat.isMe ? 'me' : ''} pp-role-${seat.role}" style="${at(56, 64)}">
          <span class="pp-avatar">${escape(initials(seat.name))}</span>
          <span class="pp-seat-name">${escape(seat.name)}${role}</span>
        </div>
        ${round && card ? `<div class="pp-seat-card" style="${at(40, 39)}">${card}</div>` : ''}`;
    }).join('');
  }

  function renderCenter(data) {
    const round = data.round;
    const center = el('pp-center');
    if (!round) {
      center.innerHTML = `<p class="pp-table-msg">${conduct() ? 'Escolha uma carta da sua mão para jogar na mesa.' : 'Aguardando o Maestro Scrum jogar a próxima carta.'}</p>`;
      return;
    }
    if (round.status === 'revealed') {
      const choice = (source, label, value, detail) => conduct()
        ? `<button type="button" class="pp-big-card pp-choice-${source}" data-decide="${source}" ${state.busy || value === null ? 'disabled' : ''}><small>${label}</small><strong>${value === null ? '—' : hours(value)}</strong><span>${detail}</span></button>`
        : `<div class="pp-big-card pp-choice-${source}"><small>${label}</small><strong>${value === null ? '—' : hours(value)}</strong><span>${detail}</span></div>`;
      center.innerHTML = `<div class="pp-choices">
          ${choice('ai', 'Planejado pela IA', round.aiEstimate, round.aiEstimate === null ? 'Card sem estimativa' : 'Tempo estimado do card')}
          ${choice('planning', 'Média do time', round.average, round.pointed ? `${hours(round.sum)} ÷ ${round.pointed} que apontaram${round.empty ? ` · ${round.empty} vazio(s) fora` : ''}` : 'Ninguém apontou horas')}
        </div>
        <p class="pp-table-msg">${escape(round.item.name)}<br><span>${conduct() ? 'Escolha a carta que vai para o card.' : 'Aguardando o PO escolher a estimativa.'}</span></p>`;
      return;
    }
    const message = round.status === 'reading' ? 'Leitura do escopo · clique na carta para ler' : `Estimativa · ${round.votes} de ${round.estimators} carta(s) do time na mesa`;
    center.innerHTML = `<button type="button" class="pp-big-card pp-task-card" data-scope="${round.id}" title="Ler o escopo">
        <span class="pp-corner">♠</span><strong>${escape(round.item.name)}</strong>${round.item.owner ? `<span class="pp-owner">${escape(round.item.owner)}</span>` : ''}<span class="pp-read">${round.hasScope ? '📖 Ler escopo' : 'Sem escopo publicado'}</span><span class="pp-corner pp-corner-end">♠</span>
      </button>
      <p class="pp-table-msg">${message}</p>`;
  }

  function renderControls(data) {
    const round = data.round;
    const parts = [];
    if (round?.status === 'voting' && !data.canEstimate) {
      parts.push(`<span class="pp-muted">${conduct() ? 'Você conduz a planning: a estimativa é do time.' : 'Você está assistindo: só o time de desenvolvimento joga carta.'}</span>`);
    } else if (round?.status === 'voting') {
      const played = round.myVoted;
      const mine = !played ? 'Ninguém vê o seu valor até a revelação. Sem contexto da demanda? Vote vazio: o voto fica fora da média.'
        : round.myVote === null ? 'Seu voto: vazio (sem contexto), fora da média.' : `Sua carta: ${hours(round.myVote)}, virada para baixo.`;
      parts.push(`<form class="pp-bet" data-bet>
          <label>Suas horas <input type="text" inputmode="decimal" id="pp-hours" placeholder="ex.: 6" autocomplete="off" value="${round.myVote !== null ? escape(String(round.myVote).replace('.', ',')) : ''}"></label>
          <button type="submit" class="pp-primary" ${state.busy ? 'disabled' : ''}>${played ? 'Trocar carta' : 'Jogar carta'}</button>
          <button type="button" class="pp-ghost" data-empty-vote ${state.busy ? 'disabled' : ''}>Votar vazio (sem contexto)</button>
          <span class="pp-muted">${mine}</span>
        </form>`);
    } else if (round?.status === 'reading') {
      parts.push('<span class="pp-muted">Leiam o escopo. A estimativa começa quando o Maestro Scrum clicar em Estimar.</span>');
    }
    if (conduct() && round?.status === 'revealed') {
      parts.push(`<label class="pp-note">O que o time discutiu <small>(opcional · vai no comentário do card junto com a decisão)</small>
          <textarea id="pp-note" rows="2" maxlength="2000" placeholder="Ex.: o time considerou a integração com a Jimi mais simples que o previsto.">${escape(state.note)}</textarea></label>`);
    }
    if (conduct() && round) {
      const maestro = [];
      if (round.status === 'reading') maestro.push(`<button type="button" class="pp-primary" data-round-action="estimate" ${state.busy ? 'disabled' : ''}>Estimar</button>`);
      if (round.status === 'voting') maestro.push(`<button type="button" class="pp-primary" data-round-action="reveal" ${state.busy || !round.votes ? 'disabled' : ''}>Revelar cartas (${round.votes})</button>`);
      maestro.push(`<button type="button" class="pp-ghost" data-round-action="cancel" ${state.busy ? 'disabled' : ''} title="Descarta as cartas jogadas e devolve o card para a mão; nada é gravado no card">Interromper e devolver à mão</button>`);
      parts.push(`<div class="pp-maestro"><span>Maestro Scrum</span>${maestro.join('')}</div>`);
    }
    const controls = el('pp-controls');
    const focused = ['pp-hours', 'pp-note'].includes(document.activeElement?.id) ? document.activeElement : null;
    const typed = focused ? {id:focused.id, value:focused.value, start:focused.selectionStart, end:focused.selectionEnd} : null;
    controls.innerHTML = parts.join('');
    controls.hidden = !parts.length;
    // A consulta periódica redesenha a mesa: quem está digitando não perde o valor, o foco nem a posição do cursor.
    if (typed && el(typed.id)) {
      const field = el(typed.id);
      field.value = typed.value; field.focus();
      try { field.setSelectionRange(typed.start, typed.end); } catch {}
    }
  }

  function renderHand(data) {
    const section = el('pp-hand-section');
    section.hidden = !conduct();
    if (!conduct()) return;
    const hand = data.hand || {items:[], available:false};
    const estimated = new Map(data.estimated.map(item => [item.itemId, item]));
    const term = state.search.trim().toLowerCase();
    const cards = hand.items.filter(card => card.Id !== data.round?.item.id && !estimated.has(card.Id))
      .filter(card => !term || [card.Nome, card.Tipo, card.Responsavel, card.Cliente].some(value => String(value || '').toLowerCase().includes(term)));
    // Com uma carta na mesa, a mão continua liberada: jogar outra interrompe a estimativa atual (com confirmação).
    const title = data.round ? `Interromper a estimativa de "${data.round.item.name}" e jogar esta carta` : 'Jogar esta carta na mesa';
    el('pp-hand').innerHTML = !hand.available
      ? '<p class="pp-muted">As cartas do Backlog aparecem depois da próxima atualização dos dados do Monday (a cada 15 minutos).</p>'
      : cards.length ? cards.map(card => `<button type="button" class="pp-hand-card" data-play="${escape(card.Id)}" data-name="${escape(card.Nome)}" ${state.busy ? 'disabled' : ''} title="${escape(title)}">
          <span class="pp-hand-top"><span>${escape(card.Tipo || 'Card')}</span><span class="pp-prio">${escape(card.Prioridade || '')}</span></span>
          <strong>${escape(card.Nome)}</strong>
          <span class="pp-hand-foot">${escape(card.Cliente || '')}${card.Cliente && card.Responsavel ? ' · ' : ''}${escape(card.Responsavel || '')}</span>
        </button>`).join('') : '<p class="pp-muted">Nenhuma carta na mão.</p>';
  }

  /**
   * Carga de cada dev na sprint planejada: horas dos cards que já estão na sprint mais as estimadas nesta planning,
   * contra a capacidade da pessoa. O responsável pelo card que está na mesa fica destacado.
   */
  function renderLoad(data) {
    const section = el('pp-load');
    const load = data.load || [];
    section.hidden = !load.length;
    if (!load.length) return;
    const owners = String(data.round?.item.owner || '').split(',').map(name => name.trim().toLowerCase()).filter(Boolean);
    const sections = teamLoad(load, data.reserveHours || 0);
    const row = person => {
      const scale = Math.max(person.capacity, person.total, 1);
      const over = person.capacity > 0 && person.total > person.capacity;
      const level = person.percent === null ? '' : person.percent > 100 ? 'over' : person.percent >= 90 ? 'full' : '';
      return `<div class="pp-load-row ${owners.includes(person.name.toLowerCase()) ? 'owner' : ''} ${level}">
          <span class="pp-load-name">${escape(person.name)}${owners.includes(person.name.toLowerCase()) ? ' <em>responsável pela carta na mesa</em>' : ''}</span>
          <span class="pp-load-bar" role="img" aria-label="${escape(person.name)}: ${hours(person.total)} de ${hours(person.capacity)}">
            <span class="pp-load-sprint" style="width:${person.inSprint / scale * 100}%"></span><span class="pp-load-planned" style="width:${person.planned / scale * 100}%"></span>
            ${person.capacity > 0 && over ? `<span class="pp-load-cap" style="left:${person.capacity / scale * 100}%"></span>` : ''}
          </span>
          <span class="pp-load-value"><strong>${hours(person.total)}</strong> de ${hours(person.capacity)}${person.percent === null ? '' : ` · ${person.percent}%`}<small>${hours(person.inSprint)} na sprint · ${hours(person.planned)} nesta planning</small></span>
        </div>`;
    };
    // Duas partes no mesmo card: cada time com capacidade, planejado e disponível (em horas e %) e as suas pessoas.
    const pct = value => value === null ? '—' : `${value}%`;
    const part = team => `<section class="pp-load-team ${team.key} level-${team.level}" aria-label="${escape(team.label)}">
        <header class="pp-load-team-head">
          <h3><span class="team-badge ${team.key}">${escape(team.label)}</span> <small>${escape(team.note)}</small></h3>
          <dl class="pp-load-stats">
            <div><dt>Capacidade</dt><dd>${hours(team.capacity)}</dd></div>
            <div class="planned"><dt>Planejado</dt><dd>${hours(team.planned)} <b>${pct(team.plannedPercent)}</b></dd></div>
            <div class="available"><dt>${team.available < 0 ? 'Excedido' : 'Disponível'}</dt><dd>${hours(Math.abs(team.available))} <b>${pct(team.availablePercent === null ? null : Math.abs(team.availablePercent))}</b></dd></div>
          </dl>
          <span class="pp-load-team-bar" role="img" aria-label="${escape(team.label)}: ${pct(team.plannedPercent)} planejado"><span style="width:${Math.min(100, team.plannedPercent ?? 0)}%"></span></span>
        </header>
        <div class="pp-load-list">${team.people.length ? team.people.map(row).join('') : '<p class="pp-load-empty">Ninguém deste time com capacidade na sprint.</p>'}</div>
      </section>`;
    section.innerHTML = `<h2>Carga do time na ${escape(data.session.sprintName)} <small>horas dos cards já na sprint + estimadas nesta planning, contra a capacidade de cada time e de cada pessoa</small></h2>
      <div class="pp-load-teams">${sections.map(part).join('')}</div>`;
  }

  /**
   * Resumo por time, pela mesma regra do Capacity: Full stack = capacidade menos a reserva do Bombeiro, sem os cards de
   * Suporte (que contam no Bombeiro); Projetos = capacidade das pessoas de Projetos e todas as suas horas.
   * @returns {Array<{key: string, label: string, note: string, capacity: number, planned: number, available: number, plannedPercent: ?number, availablePercent: ?number, level: string, people: Array}>}
   */
  function teamLoad(load, reserveHours) {
    return [['fullstack', 'Full stack'], ['projects', 'Projetos (IA)']].map(([key, label]) => {
      const people = load.filter(person => person.team === key);
      const gross = people.reduce((sum, person) => sum + person.capacity, 0);
      const reserve = key === 'fullstack' ? reserveHours : 0;
      const capacity = Math.max(0, gross - reserve);
      const planned = people.reduce((sum, person) => sum + person.total - (key === 'fullstack' ? person.support || 0 : 0), 0);
      const available = capacity - planned;
      const plannedPercent = capacity > 0 ? Math.round(planned / capacity * 100) : null;
      const note = key === 'fullstack'
        ? (reserve ? `capacidade ${hours(gross)} − Bombeiro ${hours(reserve)} · sem os cards de Suporte` : 'sem desconto do Bombeiro enquanto os times não forem classificados')
        : 'demandas próprias, fora da sprint e da sustentação';
      return {key, label, note, capacity, planned, available, plannedPercent, availablePercent: capacity > 0 ? Math.round(available / capacity * 100) : null,
        level: window.SprintRules.capacityLevel(planned, capacity), people};
    });
  }

  function renderEstimated(data) {
    const section = el('pp-estimated');
    section.hidden = !data.estimated.length;
    if (!data.estimated.length) return;
    const total = data.estimated.reduce((sum, item) => sum + (item.finalEstimate || 0), 0);
    section.innerHTML = `<h2>Estimados nesta planning · ${data.estimated.length} card(s) · ${hours(total)}</h2>
      <div class="pp-estimated-list">${data.estimated.map(item => {
        const onTable = data.round?.item.id === item.itemId;
        // Reestimar: o card volta para a mesa; a estimativa atual continua valendo até o time registrar a nova.
        const again = conduct() ? (onTable ? '<em class="pp-reestimating">reestimando na mesa</em>'
          : `<button type="button" class="pp-reestimate" data-reestimate="${escape(item.itemId)}" data-name="${escape(item.name)}" data-estimate="${escape(hours(item.finalEstimate))}" ${state.busy ? 'disabled' : ''} title="Jogar este card de novo na mesa para o time reestimar">Reestimar</button>`) : '';
        return `<span class="pp-estimated-item ${onTable ? 'on-table' : ''}">
        ${item.url ? `<a href="${escape(item.url)}" target="_blank" rel="noopener">${escape(item.name)}</a>` : escape(item.name)}
        <strong>${hours(item.finalEstimate)}</strong><small>${item.finalSource === 'ai' ? 'IA' : 'time'}</small>${again}</span>`;
      }).join('')}</div>`;
  }

  /**
   * Joga um card na mesa. Com outra carta na mesa, pergunta antes: a estimativa atual é interrompida (cartas
   * descartadas, nada gravado) e o card volta para a mão.
   */
  function play(itemId, name, intro = '') {
    const current = state.data?.round;
    if (current && !confirm(`${intro}Interromper a estimativa de "${current.item.name}"? As cartas jogadas são descartadas, nada é gravado no card e ele volta para a mão.\n\nDepois, "${name}" vai para a mesa.`)) return;
    if (!current && intro && !confirm(intro.trim())) return;
    act(`/sessions/${state.data.session.id}/rounds`, {item: itemId, replace: Boolean(current)});
  }

  // ── Modais ───────────────────────────────────────────────────────────────────
  /**
   * Encerrar a planning: pede o Objetivo da Sprint (obrigatório; ao reabrir a mesa, vem o já registrado para revisar)
   * e avisa o que acontece com a carta na mesa e com os cards estimados.
   */
  function openCloseDialog() {
    const session = state.data.session, count = new Set(state.data.estimated.map(item => item.itemId)).size;
    el('pp-close-sub').textContent = session.sprintName;
    el('pp-goal').value = session.goal || '';
    el('pp-close-message').textContent = '';
    el('pp-close-notes').textContent = [
      state.data.round ? 'A carta que está na mesa sai sem gravar nada.' : '',
      count ? `Os ${count} card(s) estimados serão ligados à ${session.sprintName} na coluna Sprint do Monday.` : '',
      'A mesa pode ser reaberta pelo botão Entrar na mesa até a sprint terminar.'
    ].filter(Boolean).join(' ');
    el('pp-close-dialog').showModal();
    el('pp-goal').focus();
  }

  async function submitClose(event) {
    event.preventDefault();
    const goal = el('pp-goal').value.trim();
    if (!goal) { el('pp-close-message').textContent = 'Informe o objetivo da sprint para encerrar a planning.'; el('pp-goal').focus(); return; }
    const session = state.data.session, count = new Set(state.data.estimated.map(item => item.itemId)).size;
    if (!await act(`/sessions/${session.id}/close`, {goal})) { el('pp-close-message').textContent = state.error; return; }
    el('pp-close-dialog').close();
    state.view = 'lobby'; state.history = null; loadHistory();
    if (count) alert(`Planning encerrada: ${count} card(s) ligados à ${session.sprintName}.`);
  }

  function openSprintDialog() {
    const options = state.data?.sprintOptions || [];
    el('pp-sprint-select').replaceChildren(new Option('Selecione a sprint', ''), ...options.map(sprint => new Option(`${sprint.name} · ${shortDate(sprint.start)} a ${shortDate(sprint.end)}`, String(sprint.number))));
    el('pp-sprint-message').textContent = options.length ? '' : 'Não há sprint atual ou futura no cronograma de sprints do Monday.';
    el('pp-sprint-dialog').showModal();
  }

  async function submitSprint(event) {
    event.preventDefault();
    const sprint = el('pp-sprint-select').value;
    if (!sprint) { el('pp-sprint-message').textContent = 'Escolha a sprint que vai ser planejada.'; el('pp-sprint-select').focus(); return; }
    if (await act('/sessions', {sprint:Number(sprint)})) el('pp-sprint-dialog').close();
    else el('pp-sprint-message').textContent = state.error;
  }

  async function openScope(roundId) {
    const dialog = el('pp-scope-dialog');
    el('pp-scope-name').textContent = state.data?.round?.item.name || '';
    el('pp-scope-title').textContent = 'Carregando o escopo...';
    el('pp-scope-body').innerHTML = '';
    dialog.showModal();
    try {
      const scope = state.scopes[roundId] ||= await mondayRequest(`${base()}/rounds/${roundId}/scope`);
      el('pp-scope-name').textContent = scope.name;
      el('pp-scope-title').innerHTML = `${escape(scope.title || 'Este card ainda não tem escopo publicado.')}${scope.url ? ` · <a href="${escape(scope.url)}" target="_blank" rel="noopener">Abrir no Monday ↗</a>` : ''}`;
      // O servidor já entrega o escopo só com tags de texto, sem atributos.
      el('pp-scope-body').innerHTML = scope.html || '<p class="pp-muted">Publique o escopo no card para o time ler aqui.</p>';
    } catch (error) {
      el('pp-scope-title').textContent = error.message;
    }
  }

  function onClick(event) {
    const target = event.target;
    if (target.closest('[data-close]')) { target.closest('dialog')?.close(); return; }
    const view = target.closest('[data-view]');
    if (view) { state.view = view.dataset.view; if (state.view === 'lobby' && !state.history) loadHistory(); render(); return; }
    if (target.closest('[data-new-sprint]')) { openSprintDialog(); return; }
    if (target.closest('[data-close-table]')) { openCloseDialog(); return; }
    const enter = target.closest('[data-enter-sprint]');
    if (enter) { act('/sessions', {sprint: Number(enter.dataset.enterSprint)}).then(ok => { if (ok) { state.view = 'table'; render(); } }); return; }
    const review = target.closest('[data-review]');
    if (review) { openReview(Number(review.dataset.review)); return; }
    const sprint = target.closest('[data-sprint]');
    if (sprint) { const number = Number(sprint.dataset.sprint); state.openSprint = state.openSprint === number ? null : number; render(); return; }
    const card = target.closest('[data-play]');
    if (card) { play(card.dataset.play, card.dataset.name); return; }
    const again = target.closest('[data-reestimate]');
    if (again) { play(again.dataset.reestimate, again.dataset.name, `Reestimar "${again.dataset.name}"? A estimativa atual (${again.dataset.estimate}) continua valendo até o time registrar a nova. `); return; }
    const scope = target.closest('[data-scope]');
    if (scope) { openScope(Number(scope.dataset.scope)); return; }
    if (target.closest('[data-empty-vote]')) { act(`/rounds/${state.data.round.id}/votes`, {hours:null}); return; }
    const decide = target.closest('[data-decide]');
    if (decide) {
      act(`/rounds/${state.data.round.id}/decision`, {source:decide.dataset.decide, note:state.note.trim() || null}).then(ok => { if (ok) state.note = ''; });
      return;
    }
    const action = target.closest('[data-round-action]')?.dataset.roundAction;
    if (action === 'cancel' && !confirm('Interromper esta estimativa? As cartas jogadas são descartadas, nada é gravado no card e ele volta para a mão.')) return;
    if (action) act(`/rounds/${state.data.round.id}/${action}`, {});
  }

  function onSubmit(event) {
    if (event.target.id === 'pp-sprint-form') { submitSprint(event); return; }
    if (event.target.id === 'pp-close-form') { submitClose(event); return; }
    if (!event.target.matches('[data-bet]')) return;
    event.preventDefault();
    const value = Number(el('pp-hours').value.trim().replace(',', '.'));
    if (!(value > 0)) { state.error = 'Informe as horas em número, ex.: 6 ou 2,5. Sem contexto da demanda? Use Votar vazio.'; render(); return; }
    act(`/rounds/${state.data.round.id}/votes`, {hours:value});
  }

  function enter() {
    state.active = true;
    render();
    load();
    loadHistory();
    clearInterval(state.timer);
    state.timer = setInterval(() => { if (!document.hidden && !state.busy) load(); }, POLL_MS);
  }

  function leave() {
    state.active = false;
    clearInterval(state.timer);
    state.timer = null;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const page = el('page-planning');
    if (!page) return;
    page.addEventListener('click', onClick);
    page.addEventListener('submit', onSubmit);
    page.addEventListener('input', event => { if (event.target.id === 'pp-note') state.note = event.target.value; });
    el('pp-hand-search').addEventListener('input', event => { state.search = event.target.value; if (state.data?.session) renderHand(state.data); });
  });

  return {enter, leave, teamLoad, renderLoad};
})();
