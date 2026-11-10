/* Planning poker como mesa de jogo. Entrada: últimas sprints com horas planejadas e executadas e o botão Planejar nova sprint
 * (Maestro Scrum). Mesa: o Maestro joga no centro uma carta da mão (Backlog do quadro Tarefas), o time lê o escopo, cada
 * pessoa joga a carta com as horas virada para baixo, o Maestro revela e escolhe entre a estimativa da IA e a média do time.
 * As regras ficam no servidor (PlanningService); a tela consulta a mesa a cada poucos segundos enquanto está aberta. */
window.Planning = (() => {
  const POLL_MS = 2500;
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const state = {active:false, data:null, history:null, error:'', busy:false, timer:null, request:0, view:null, openSprint:null, search:'', scopes:{}, note:''};

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
      // Quando a mesa abre, todos vão para ela; quando fecha, voltam às sprints.
      if (data.session && (!wasOpen || state.view === null)) state.view = 'table';
      if (!data.session) { if (wasOpen) loadHistory(); state.view = 'lobby'; }
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
    const table = state.view === 'table' && data?.session;
    el('pp-lobby').hidden = Boolean(table);
    el('pp-table-view').hidden = !table;
    el('pp-subtitle').textContent = table ? `Planning da ${data.session.sprintName} · conduzida por ${data.session.startedBy || 'Maestro Scrum'}` : 'Planejamento das sprints com o time na mesa';
    renderHeroActions(data, table);
    if (table) renderTable(data); else renderLobby(data);
  }

  function renderHeroActions(data, table) {
    const buttons = [];
    if (table) {
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
    el('pp-history').innerHTML = state.history.map(sprint => {
      const open = state.openSprint === sprint.number;
      const usage = sprint.plannedHours > 0 ? Math.round(sprint.executedHours / sprint.plannedHours * 100) : null;
      return `<div class="pp-sprint ${open ? 'open' : ''}">
        <button type="button" class="pp-sprint-row" data-sprint="${sprint.number}" aria-expanded="${open}">
          <span class="pp-sprint-name">${escape(sprint.name)}<small>${shortDate(sprint.start)} a ${shortDate(sprint.end)}</small></span>
          <span class="pp-sprint-fact"><small>Cards</small>${sprint.cards}</span>
          <span class="pp-sprint-fact"><small>Planejadas</small>${hours(sprint.plannedHours)}</span>
          <span class="pp-sprint-fact"><small>Executadas</small>${hours(sprint.executedHours)}</span>
          <span class="pp-sprint-fact"><small>Executado / planejado</small>${usage === null ? '—' : `${usage}%`}</span>
          <span class="pp-sprint-caret" aria-hidden="true">▾</span>
        </button>
        ${open ? renderSprintTasks(sprint) : ''}
      </div>`;
    }).join('');
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
      maestro.push(`<button type="button" class="pp-ghost" data-round-action="cancel" ${state.busy ? 'disabled' : ''}>Tirar carta da mesa</button>`);
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
    const locked = Boolean(data.round) || state.busy;
    el('pp-hand').innerHTML = !hand.available
      ? '<p class="pp-muted">As cartas do Backlog aparecem depois da próxima atualização dos dados do Monday (a cada 15 minutos).</p>'
      : cards.length ? cards.map(card => `<button type="button" class="pp-hand-card" data-play="${escape(card.Id)}" ${locked ? 'disabled' : ''} title="${locked ? 'Registre ou tire a carta da mesa antes de jogar outra' : 'Jogar esta carta na mesa'}">
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
    section.innerHTML = `<h2>Carga do time na ${escape(data.session.sprintName)} <small>horas dos cards já na sprint + estimadas nesta planning, contra a capacidade de cada um</small></h2>
      <div class="pp-load-list">${load.map(person => {
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
      }).join('')}</div>`;
  }

  function renderEstimated(data) {
    const section = el('pp-estimated');
    section.hidden = !data.estimated.length;
    if (!data.estimated.length) return;
    const total = data.estimated.reduce((sum, item) => sum + (item.finalEstimate || 0), 0);
    section.innerHTML = `<h2>Estimados nesta planning · ${data.estimated.length} card(s) · ${hours(total)}</h2>
      <div class="pp-estimated-list">${data.estimated.map(item => `<span class="pp-estimated-item">
        ${item.url ? `<a href="${escape(item.url)}" target="_blank" rel="noopener">${escape(item.name)}</a>` : escape(item.name)}
        <strong>${hours(item.finalEstimate)}</strong><small>${item.finalSource === 'ai' ? 'IA' : 'time'}</small></span>`).join('')}</div>`;
  }

  // ── Modais ───────────────────────────────────────────────────────────────────
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
    if (target.closest('[data-close-table]')) {
      if (confirm('Encerrar a planning desta sprint? Uma carta que ainda estiver na mesa sai sem gravar nada.')) act(`/sessions/${state.data.session.id}/close`, {});
      return;
    }
    const sprint = target.closest('[data-sprint]');
    if (sprint) { const number = Number(sprint.dataset.sprint); state.openSprint = state.openSprint === number ? null : number; render(); return; }
    const play = target.closest('[data-play]');
    if (play) { act(`/sessions/${state.data.session.id}/rounds`, {item:play.dataset.play}); return; }
    const scope = target.closest('[data-scope]');
    if (scope) { openScope(Number(scope.dataset.scope)); return; }
    if (target.closest('[data-empty-vote]')) { act(`/rounds/${state.data.round.id}/votes`, {hours:null}); return; }
    const decide = target.closest('[data-decide]');
    if (decide) {
      act(`/rounds/${state.data.round.id}/decision`, {source:decide.dataset.decide, note:state.note.trim() || null}).then(ok => { if (ok) state.note = ''; });
      return;
    }
    const action = target.closest('[data-round-action]')?.dataset.roundAction;
    if (action === 'cancel' && !confirm('Tirar esta carta da mesa? Nada é gravado no card.')) return;
    if (action) act(`/rounds/${state.data.round.id}/${action}`, {});
  }

  function onSubmit(event) {
    if (event.target.id === 'pp-sprint-form') { submitSprint(event); return; }
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

  return {enter, leave};
})();
