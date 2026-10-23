/* Kanban da sprint: usa a mesma consulta autenticada do dashboard. */
window.SprintPanel = (() => {
  const CYCLE_MS = 60_000;
  const state = { active:false, timer:null, inFlight:false, rows:[], sprint:null, pendingSprint:null,
    boardName:'', lastSuccess:null, stale:false, error:'' };
  const el = id => document.getElementById(id);
  const normalized = value => String(value ?? '').trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g,'');

  function filterTasks(rows) {
    return rows.filter(row => String(row.Id ?? '').trim() && Number.isInteger(Number(row.Sprint)) && Number(row.Sprint) > 0 && normalized(row.Tipo) !== 'suporte');
  }
  function statusBucket(status) {
    if (window.SprintRules.isClosed(status)) return 'done';
    if (window.SprintRules.isNotStarted(status)) return 'backlog';
    return window.SprintRules.isInTest(status) ? 'test' : 'progress';
  }
  function selectedRows(rows, sprint) {
    return [...new Map(rows.filter(row => Number(row.Sprint) === sprint).map(row => [String(row.Id),row])).values()];
  }
  function sortByPriority(rows) { return window.Bombeiro.sortByPriority(rows); }
  function safeMondayUrl(value) {
    try {
      const url = new URL(value);
      return url.protocol === 'https:' && (url.hostname === 'monday.com' || url.hostname.endsWith('.monday.com')) ? url.href : '';
    } catch { return ''; }
  }
  function card(row, bucket) {
    const node = document.createElement('article');
    node.className = 'fire-task';
    const title = document.createElement('div');
    title.className = 'fire-task-title';
    title.append(document.createTextNode(String(row.Nome || 'Sem título')));
    if (bucket === 'progress' || bucket === 'test') {
      const status = document.createElement('span');
      status.className = 'fire-original-status';
      status.textContent = ` (${String(row.Status || '').trim() || 'Status não informado'})`;
      title.append(status);
    }
    node.append(title);
    const meta = document.createElement('div');
    meta.className = 'fire-task-meta';
    const priority = window.Bombeiro.priorityMeta(row.Prioridade);
    const badge = document.createElement('span');
    badge.className = `fire-priority ${priority.css}`;
    badge.textContent = priority.label;
    meta.append(badge);
    if (String(row.Responsavel || '').trim()) {
      const owner = document.createElement('span');
      owner.className = 'fire-owner';
      owner.textContent = String(row.Responsavel).trim();
      owner.title = owner.textContent;
      meta.append(owner);
    }
    const url = safeMondayUrl(row.Url);
    if (url) {
      const link = document.createElement('a');
      link.className = 'fire-task-link';
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Abrir no Monday ↗';
      meta.append(link);
    }
    node.append(meta);
    return node;
  }
  function updateSprintOptions() {
    const sprints = [...new Set(state.rows.map(row => Number(row.Sprint)))].sort((a,b) => b-a);
    const desired = state.pendingSprint && sprints.includes(state.pendingSprint) ? state.pendingSprint : state.sprint;
    state.sprint = sprints.includes(desired) ? desired : (sprints[0] ?? null);
    state.pendingSprint = null;
    const select = el('sp-sprint');
    select.replaceChildren(...sprints.map(sprint => new Option(`Sprint ${sprint}`,String(sprint))));
    if (state.sprint !== null) select.value = String(state.sprint);
  }
  function fitLanes() {
    const page = el('page-sprint-panel'), board = el('sp-board');
    if (!state.active || !page?.classList.contains('active') || !board) return;
    if (window.innerWidth <= 950) {
      board.style.removeProperty('--sp-board-height');
      for (const id of ['sp-backlog','sp-progress','sp-test','sp-done']) {
        const list = el(id);
        list.style.removeProperty('--sp-columns');
        list.style.removeProperty('--fire-fit');
      }
      return;
    }
    const footer = page.querySelector('.sprint-panel-note');
    const footerSpace = footer ? footer.getBoundingClientRect().height + 9 : 9;
    const available = Math.max(280,window.innerHeight - board.getBoundingClientRect().top - footerSpace);
    board.style.setProperty('--sp-board-height',`${available}px`);
    for (const id of ['sp-backlog','sp-progress','sp-test','sp-done']) {
      const list = el(id), count = list.querySelectorAll('.fire-task').length;
      const firstColumns = count >= 13 ? 2 : 1;
      let best = {columns:firstColumns,scale:.5};
      for (let columns = firstColumns; columns <= Math.min(4,Math.max(firstColumns,count)); columns++) {
        list.classList.toggle('sp-condensed',count >= 10 || columns > 1);
        list.style.setProperty('--sp-columns',String(columns));
        list.style.setProperty('--fire-fit','1');
        if (list.scrollHeight <= list.clientHeight + 1) { best = {columns,scale:1}; break; }
        let low = .5, high = 1;
        list.style.setProperty('--fire-fit',String(low));
        if (list.scrollHeight > list.clientHeight + 1) continue;
        for (let i = 0; i < 9; i++) {
          const middle = (low + high) / 2;
          list.style.setProperty('--fire-fit',String(middle));
          if (list.scrollHeight <= list.clientHeight + 1) low = middle;
          else high = middle;
        }
        if (low > best.scale) best = {columns,scale:low};
        if (low >= .82) break;
      }
      list.classList.toggle('sp-condensed',count >= 10 || best.columns > 1);
      list.style.setProperty('--sp-columns',String(best.columns));
      list.style.setProperty('--fire-fit',String(best.scale));
    }
  }
  function render() {
    if (!el('page-sprint-panel')) return;
    const rows = selectedRows(state.rows,state.sprint);
    el('sp-title').textContent = state.sprint === null ? 'Painel da Sprint' : `Sprint ${state.sprint}`;
    el('sp-context').textContent = state.lastSuccess
      ? `${state.boardName || 'Quadro de tarefas'} · atualização ${state.lastSuccess.toLocaleString('pt-BR')}`
      : 'Aguardando dados do Monday...';
    el('sp-last-sync').textContent = state.lastSuccess
      ? `Última atualização: ${state.lastSuccess.toLocaleString('pt-BR')}${state.stale ? ' · dados desatualizados' : ''}`
      : 'Aguardando primeira consulta';
    if (state.active) el('tb-right').textContent = state.sprint === null ? 'Sprint' : `S${state.sprint}`;
    for (const [bucket,id] of [['backlog','sp-backlog'],['progress','sp-progress'],['test','sp-test'],['done','sp-done']]) {
      const laneRows = sortByPriority(rows.filter(row => statusBucket(row.Status) === bucket));
      el(`${id}-count`).textContent = String(laneRows.length);
      const list = el(id);
      list.replaceChildren();
      if (laneRows.length) list.append(...laneRows.map(row => card(row,bucket)));
      else {
        const empty = document.createElement('div');
        empty.className = 'fire-empty';
        empty.textContent = state.lastSuccess ? 'Nenhuma demanda nesta coluna.' : 'Aguardando dados do Monday...';
        list.append(empty);
      }
    }
    const error = el('sp-error');
    error.textContent = state.error;
    error.hidden = !state.error;
    fitLanes();
  }
  async function refresh(manual = false) {
    if (!state.active || state.inFlight) return;
    const startedAt = Date.now();
    clearTimeout(state.timer);
    state.timer = null;
    state.inFlight = true;
    el('sp-refresh').disabled = true;
    try {
      const result = await mondayRequest('/api/sync',{sprintPanel:true,force:manual === true});
      if (!Array.isArray(result.rows)) throw new Error('Resposta sem lista de tarefas.');
      state.rows = filterTasks(result.rows);
      state.boardName = String(result.boardName || 'Quadro de tarefas');
      state.lastSuccess = new Date(result.syncedAt);
      state.stale = false;
      state.error = '';
      updateSprintOptions();
      render();
    } catch (error) {
      state.stale = true;
      state.error = `${state.lastSuccess ? 'Dados desatualizados. ' : 'Não foi possível carregar os dados. '}Falha na consulta ao Monday: ${error.message}. Nova tentativa no próximo ciclo de 60 segundos.`;
      render();
    } finally {
      state.inFlight = false;
      el('sp-refresh').disabled = false;
      if (state.active) state.timer = setTimeout(() => refresh(false),Math.max(1_000,CYCLE_MS-(Date.now()-startedAt)));
    }
  }
  function enter(sprintHint = null) {
    state.active = true;
    document.body.classList.add('sprint-panel-view');
    if (Number.isInteger(sprintHint)) state.pendingSprint = sprintHint;
    if (state.rows.length) updateSprintOptions();
    render();
    refresh(false);
  }
  function leave() {
    state.active = false;
    clearTimeout(state.timer);
    state.timer = null;
    document.body.classList.remove('sprint-panel-view');
  }
  document.addEventListener('DOMContentLoaded',() => {
    if (!el('page-sprint-panel')) return;
    el('sp-sprint').addEventListener('change',event => { state.sprint = Number(event.target.value); render(); });
    el('sp-refresh').addEventListener('click',() => refresh(true));
    window.addEventListener('resize',() => requestAnimationFrame(fitLanes));
    document.fonts?.ready.then(() => requestAnimationFrame(fitLanes));
    render();
  });
  return {enter,leave,refresh,render,filterTasks,statusBucket,selectedRows,safeMondayUrl};
})();
