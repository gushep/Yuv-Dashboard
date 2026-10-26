/* Visualizações da tela Tarefas além da tabela: quadro Kanban e lista agrupada, sobre a mesma lista já filtrada.
 * No Kanban por status, quem pode atualizar cards arrasta o card para outra coluna e o status muda no Monday. */
window.TaskViews = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const PRIORITY_ORDER = ['Crítica', 'Alta', 'Média', 'Baixa'];
  const GROUPS = {
    status:{label:'Status', empty:'Sem status', keys:row => [clean(row.Status)]},
    responsavel:{label:'Responsável', empty:'Sem responsável', keys:row => devPeople(row).length ? devPeople(row) : ['']},
    cliente:{label:'Cliente', empty:'Sem cliente', keys:row => [clean(row.Cliente)]},
    prioridade:{label:'Prioridade', empty:'Sem prioridade', keys:row => [clean(row.Prioridade)]},
    tipo:{label:'Tipo', empty:'Sem tipo', keys:row => [clean(row.Tipo)]},
    epico:{label:'Épico', empty:'Sem épico', keys:row => [clean(row.Epico)]},
    sprint:{label:'Sprint', empty:'Sem sprint', keys:row => [row.Sprint ? String(row.Sprint) : '']},
  };
  const state = {showEmpty:false, collapsed:new Set(), dragging:null, options:{}};

  /** Rótulos da coluna Status do quadro, na ordem e cor do Monday (vêm da sincronização). */
  function statusOptions() { return window.MONDAY_STATUS_OPTIONS || []; }
  function statusColor(label) { return statusOptions().find(option => option.label === label)?.color || '#94a3b8'; }

  /** Ordem das colunas: status como no Monday, prioridade da mais grave, sprint da mais recente, o resto em ordem alfabética. */
  function orderedKeys(groupBy, present) {
    const known = groupBy === 'status' ? statusOptions().map(option => option.label)
      : groupBy === 'prioridade' ? PRIORITY_ORDER : [];
    const shown = groupBy === 'status' && state.showEmpty ? known : known.filter(key => present.has(key));
    const rest = [...present].filter(key => key !== '' && !known.includes(key));
    rest.sort(groupBy === 'sprint' ? (a, b) => Number(b) - Number(a) : (a, b) => a.localeCompare(b));
    return [...shown, ...rest, ...(present.has('') ? [''] : [])];
  }

  function group(rows, groupBy) {
    const config = GROUPS[groupBy] || GROUPS.status;
    const buckets = new Map();
    rows.forEach(row => config.keys(row).forEach(key => {
      if (!buckets.has(key)) buckets.set(key, []);
      buckets.get(key).push(row);
    }));
    return orderedKeys(groupBy, new Set(buckets.keys())).map(key => ({key, label:key === '' ? config.empty : groupBy === 'sprint' ? `Sprint ${key}` : key, rows:buckets.get(key) || []}));
  }

  function initials(name) { return String(name).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase(); }

  function card(row, options) {
    const people = devPeople(row).filter(person => !SprintRules.isExcludedPerson(person));
    const hours = row.EstH || row.RealH ? `<span class="tv-hours" title="Estimado / Real">${row.RealH || 0}h / ${row.EstH || '—'}h</span>` : '';
    const draggable = options.canMove && options.groupBy === 'status' && row.Id;
    return `<article class="tv-card" ${draggable ? `draggable="true" data-id="${escape(row.Id)}"` : ''}>
        <button type="button" class="tv-card-title" ${row.Id ? `data-open="${escape(row.Id)}"` : 'disabled'}>${escape(row.Nome)}</button>
        <div class="tv-card-meta">
          ${options.groupBy !== 'status' ? `<span class="tv-status" style="--st:${escape(statusColor(row.Status))}">${escape(row.Status || 'Sem status')}</span>` : ''}
          ${row.Prioridade && options.groupBy !== 'prioridade' ? prioBadge(row.Prioridade) : ''}
          ${row.Tipo && options.groupBy !== 'tipo' ? `<span class="b bo">${escape(row.Tipo)}</span>` : ''}
          ${options.showSprint && options.groupBy !== 'sprint' ? `<span class="b bo">S${escape(row.Sprint)}</span>` : ''}
        </div>
        ${row.Cliente && options.groupBy !== 'cliente' ? `<div class="tv-client" title="Cliente">🏢 ${escape(row.Cliente)}</div>` : ''}
        <div class="tv-card-foot">
          <span class="tv-people">${people.map(person => `<span class="tv-avatar" title="${escape(person)}">${escape(initials(person))}</span>`).join('') || '<span class="tv-muted">Sem responsável</span>'}</span>
          ${hours}
        </div>
      </article>`;
  }

  function renderBoard(container, rows, options) {
    const columns = group(rows, options.groupBy);
    const movable = options.canMove && options.groupBy === 'status';
    container.innerHTML = `<div class="tv-toolbar">
        <span class="tv-muted">${movable ? 'Arraste um card para outra coluna para mudar o status no Monday.' : options.groupBy === 'status' ? 'Para mudar status arrastando, peça a permissão "Atualizar cards".' : ''}</span>
        ${options.groupBy === 'status' ? `<label class="tv-check"><input type="checkbox" data-show-empty ${state.showEmpty ? 'checked' : ''}> Mostrar status sem cards</label>` : ''}
      </div>
      <div class="tv-board">${columns.map(column => `<section class="tv-column" data-status="${options.groupBy === 'status' ? escape(column.key) : ''}">
          <header><span class="tv-dot" style="background:${escape(options.groupBy === 'status' ? statusColor(column.key) : '#94a3b8')}"></span><h3 title="${escape(column.label)}">${escape(column.label)}</h3><span class="tv-count">${column.rows.length}</span></header>
          <div class="tv-cards">${column.rows.map(row => card(row, options)).join('') || '<div class="tv-empty">Nenhuma tarefa</div>'}</div>
        </section>`).join('') || '<div class="nd">Nenhuma tarefa com esses filtros.</div>'}</div>`;
  }

  function renderList(container, rows, options) {
    const groups = group(rows, options.groupBy);
    container.innerHTML = `<div class="tv-list">${groups.map(section => `<details class="tv-group" ${state.collapsed.has(`${options.groupBy}:${section.key}`) ? '' : 'open'} data-group="${escape(`${options.groupBy}:${section.key}`)}">
        <summary><span class="tv-dot" style="background:${escape(options.groupBy === 'status' ? statusColor(section.key) : '#94a3b8')}"></span><strong>${escape(section.label)}</strong><span class="tv-count">${section.rows.length}</span></summary>
        <div class="tv-rows">${section.rows.map(row => `<div class="tv-row">
            <button type="button" class="tv-row-title" ${row.Id ? `data-open="${escape(row.Id)}"` : 'disabled'} title="${escape(row.Nome)}">${escape(row.Nome)}</button>
            ${options.groupBy !== 'status' ? `<span class="tv-status" style="--st:${escape(statusColor(row.Status))}">${escape(row.Status || 'Sem status')}</span>` : '<span></span>'}
            <span class="tv-cell" title="Responsável">${escape(devPeople(row).filter(person => !SprintRules.isExcludedPerson(person)).join(', ') || '—')}</span>
            <span class="tv-cell" title="Cliente">${escape(row.Cliente || '—')}</span>
            <span>${row.Prioridade ? prioBadge(row.Prioridade) : ''}</span>
            <span class="tv-cell tv-muted">${options.showSprint ? `Sprint ${escape(row.Sprint)}` : escape(row.Tipo || '')}</span>
          </div>`).join('')}</div>
      </details>`).join('') || '<div class="nd">Nenhuma tarefa com esses filtros.</div>'}</div>`;
  }

  function render(container, rows, options) {
    state.options = options;
    state.container = container;
    state.rows = rows;
    if (options.view === 'list') renderList(container, rows, options);
    else renderBoard(container, rows, options);
  }

  function toast(text, kind = '') {
    let box = document.getElementById('tv-toast');
    if (!box) { box = document.createElement('div'); box.id = 'tv-toast'; box.setAttribute('role', 'status'); document.body.appendChild(box); }
    box.textContent = text;
    box.className = `tv-toast show ${kind}`;
    clearTimeout(box.timer);
    box.timer = setTimeout(() => box.classList.remove('show'), 4000);
  }

  /** Muda o status na tela na hora e no Monday em seguida; se o Monday recusar, volta ao status anterior. */
  async function move(id, label) {
    const rows = DATA.filter(row => row.Id === id);
    const previous = rows.map(row => row.Status);
    if (!rows.length || previous.every(status => status === label)) return;
    rows.forEach(row => { row.Status = label; });
    renderRaw();
    try {
      await mondayRequest(`/api/items/${encodeURIComponent(id)}/status`, {status:label});
      toast(`Status alterado para “${label}”.`, 'success');
    } catch (error) {
      rows.forEach((row, index) => { row.Status = previous[index]; });
      renderRaw();
      toast(error.message, 'error');
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const view = document.getElementById('raw-board-view');
    if (!view) return;
    view.addEventListener('click', event => {
      const open = event.target.closest('[data-open]');
      if (open) ItemDetail.open(open.dataset.open);
    });
    view.addEventListener('change', event => {
      if (event.target.matches('[data-show-empty]')) { state.showEmpty = event.target.checked; renderRaw(); }
    });
    view.addEventListener('toggle', event => {
      const details = event.target.closest?.('[data-group]');
      if (!details) return;
      if (details.open) state.collapsed.delete(details.dataset.group); else state.collapsed.add(details.dataset.group);
    }, true);
    view.addEventListener('dragstart', event => {
      const cardEl = event.target.closest('.tv-card[draggable]');
      if (!cardEl) return;
      state.dragging = cardEl.dataset.id;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', cardEl.dataset.id);
      cardEl.classList.add('dragging');
    });
    view.addEventListener('dragend', event => {
      event.target.closest('.tv-card')?.classList.remove('dragging');
      view.querySelectorAll('.tv-column.over').forEach(column => column.classList.remove('over'));
      state.dragging = null;
    });
    view.addEventListener('dragover', event => {
      const column = event.target.closest('.tv-column');
      if (!state.dragging || !column || column.dataset.status === '') return;
      event.preventDefault();
      view.querySelectorAll('.tv-column.over').forEach(other => other !== column && other.classList.remove('over'));
      column.classList.add('over');
    });
    view.addEventListener('drop', event => {
      const column = event.target.closest('.tv-column');
      if (!state.dragging || !column || column.dataset.status === '') return;
      event.preventDefault();
      move(state.dragging, column.dataset.status);
    });
  });

  return {render};
})();
