/* Visualizações da tela Tarefas além da tabela: quadro Kanban e lista agrupada, sobre a mesma lista já filtrada.
 * Tarefas: por etapa, as colunas são as mesmas 4 dos painéis do Bombeiro e da Sprint (regra de SprintPanel.statusBucket);
 * arrastar para outra etapa abre a escolha do status do Monday. Roadmap e Backlog: as colunas são os status na ordem do fluxo,
 * e arrastar aplica o status da coluna. Só arrasta quem pode atualizar cards. Visão 360°: esteira só de consulta
 * (Roadmap → Implementados), com cada demanda numa coluna só. */
window.TaskViews = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const PRIORITY_ORDER = ['Crítica', 'Crítico', 'Alta', 'Média', 'Baixa'];
  const EFFORT_ORDER = ['Muito Alto', 'Alto', 'Média', 'Baixa'];
  const STAGES = [
    {key:'backlog', label:'Backlog', color:'#52638e'},
    {key:'progress', label:'Em andamento', color:'#fa6423'},
    {key:'test', label:'Em teste', color:'#7c3aed'},
    {key:'done', label:'Concluído', color:'#06a340'},
  ];
  const stageOf = status => window.SprintPanel.statusBucket(status);
  const state = {collapsed:new Set(), dragging:null, options:{}};
  const boardMode = () => state.options.mode === 'board';
  const GROUPS = {
    status:{empty:'Sem status', keys:row => [boardMode() ? clean(row.Status) : stageOf(row.Status)]},
    responsavel:{empty:'Sem responsável', keys:row => devPeople(row).length ? devPeople(row) : ['']},
    cliente:{empty:'Sem cliente', keys:row => [clean(row.Cliente)]},
    prioridade:{empty:'Sem prioridade', keys:row => [clean(row.Prioridade)]},
    tipo:{empty:'Sem tipo', keys:row => [clean(row.Tipo)]},
    epico:{empty:'Sem épico', keys:row => [clean(row.Epico)]},
    sprint:{empty:'Sem sprint', keys:row => [row.Sprint ? String(row.Sprint) : '']},
    origem:{empty:'Sem origem', keys:row => [clean(row.Origem)]},
    modulo:{empty:'Sem módulo', keys:row => [clean(row.Modulo)]},
    esforco:{empty:'Sem esforço', keys:row => [clean(row.Esforco)]},
  };

  /** Rótulos de status com cor: os das abas de quadro (Roadmap, Backlog) vêm nas opções; os das tarefas, da sincronização. */
  function statusOptions() { return state.options.statusOptions || window.MONDAY_STATUS_OPTIONS || []; }
  function statusColor(label) { return statusOptions().find(option => option.label === label)?.color || '#94a3b8'; }
  /** Status do Monday que pertencem a uma etapa, na ordem do quadro. */
  function stageStatuses(stage) { return (window.MONDAY_STATUS_OPTIONS || []).map(option => option.label).filter(label => stageOf(label) === stage); }

  /** Ordem das colunas: etapas (sempre as 4) ou o fluxo inteiro do quadro; prioridade e esforço do maior para o menor; sprint da mais recente. */
  function orderedKeys(groupBy, present) {
    if (groupBy === 'status' && !boardMode()) return STAGES.map(stage => stage.key);
    const known = groupBy === 'status' ? statusOptions().map(option => option.label)
      : (groupBy === 'prioridade' ? PRIORITY_ORDER : groupBy === 'esforco' ? EFFORT_ORDER : []).filter(key => present.has(key));
    const rest = [...present].filter(key => key !== '' && !known.includes(key));
    rest.sort(groupBy === 'sprint' ? (a, b) => Number(b) - Number(a) : (a, b) => a.localeCompare(b));
    return [...known, ...rest, ...(present.has('') ? [''] : [])];
  }

  function group(rows, groupBy) {
    const config = GROUPS[groupBy] || GROUPS.status;
    const buckets = new Map();
    rows.forEach(row => config.keys(row).forEach(key => {
      if (!buckets.has(key)) buckets.set(key, []);
      buckets.get(key).push(row);
    }));
    return orderedKeys(groupBy, new Set(buckets.keys())).map(key => {
      const stage = groupBy === 'status' && !boardMode() ? STAGES.find(item => item.key === key) : null;
      return {
        key,
        color:stage?.color || (groupBy === 'status' && key ? statusColor(key) : '#94a3b8'),
        label:stage ? stage.label : key === '' ? config.empty : groupBy === 'sprint' ? `Sprint ${key}` : key,
        rows:Bombeiro.sortByPriority(buckets.get(key) || []),
      };
    });
  }

  function initials(name) { return String(name).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase(); }
  function statusChip(row) { return `<span class="tv-status" style="--st:${escape(statusColor(row.Status))}">${escape(row.Status || 'Sem status')}</span>`; }

  function card(row, options) {
    const draggable = options.canMove && options.groupBy === 'status' && row.Id;
    const meta = [
      statusChip(row),
      row.Prioridade && options.groupBy !== 'prioridade' ? prioBadge(row.Prioridade) : '',
      row.Tipo && options.groupBy !== 'tipo' ? `<span class="b bo">${escape(row.Tipo)}</span>` : '',
      boardMode() && row.Modulo && options.groupBy !== 'modulo' ? `<span class="b bo">${escape(row.Modulo)}</span>` : '',
      options.showSprint && options.groupBy !== 'sprint' ? `<span class="b bo">S${escape(row.Sprint)}</span>` : '',
    ].join('');
    const people = devPeople(row).filter(person => !SprintRules.isExcludedPerson(person));
    const avatars = people.map(person => `<span class="tv-avatar" title="${escape(person)}">${escape(initials(person))}</span>`).join('');
    let foot;
    if (boardMode()) {
      // Quadros sem coluna de pessoas (ex.: Funil de Demandas) mostram quem pediu e de onde veio.
      const who = [row.Solicitante && `👤 ${escape(row.Solicitante)}`, row.Origem && options.groupBy !== 'origem' && escape(row.Origem)].filter(Boolean).join(' · ');
      foot = `${avatars ? `<span class="tv-people">${avatars}</span>` : `<span class="tv-muted" title="Solicitante · Origem">${who || '—'}</span>`}
        ${row.Esforco && options.groupBy !== 'esforco' ? `<span class="tv-hours" title="Esforço">${escape(row.Esforco)}</span>` : ''}`;
    } else {
      foot = `<span class="tv-people">${avatars || '<span class="tv-muted">Sem responsável</span>'}</span>
        ${row.EstH || row.RealH ? `<span class="tv-hours" title="Estimado / Real">${row.RealH || 0}h / ${row.EstH || '—'}h</span>` : ''}`;
    }
    return `<article class="tv-card" ${draggable ? `draggable="true" data-id="${escape(row.Id)}"` : ''}>
        <button type="button" class="tv-card-title" ${row.Id ? `data-open="${escape(row.Id)}"` : 'disabled'}>${escape(row.Nome)}</button>
        <div class="tv-card-meta">${meta}</div>
        ${row.Cliente && options.groupBy !== 'cliente' ? `<div class="tv-client" title="Cliente">🏢 ${escape(row.Cliente)}</div>` : ''}
        <div class="tv-card-foot">${foot}</div>
      </article>`;
  }

  function renderBoard(container, rows, options) {
    const columns = group(rows, options.groupBy);
    const byStatus = options.groupBy === 'status';
    const hint = !byStatus ? ''
      : !options.canMove ? 'Para mudar status arrastando, peça a permissão "Atualizar cards".'
      : boardMode() ? 'Arraste um item para outra coluna para mudar o status no Monday.'
      : 'Arraste um card para outra etapa e escolha o status do Monday a aplicar.';
    const noun = boardMode() ? 'item' : 'tarefa';
    container.innerHTML = `<div class="tv-toolbar">
        <span class="tv-muted">${hint}</span>
        ${byStatus ? `<span class="tv-muted">${boardMode() ? 'Colunas na ordem do fluxo do quadro.' : 'Mesmas etapas dos painéis do Bombeiro e da Sprint.'}</span>` : ''}
      </div>
      <div class="tv-board ${byStatus && !boardMode() ? 'tv-stages' : ''}">${columns.map(column => `<section class="tv-column"
          ${byStatus ? (boardMode() ? `data-status="${escape(column.key)}"` : `data-stage="${escape(column.key)}"`) : ''} style="--stage:${escape(column.color)}">
          <header><span class="tv-dot" style="background:${escape(column.color)}"></span><h3 title="${escape(column.label)}">${escape(column.label)}</h3><span class="tv-count">${column.rows.length}</span></header>
          <div class="tv-cards">${column.rows.map(row => card(row, options)).join('') || `<div class="tv-empty">Nenhuma ${noun}</div>`}</div>
        </section>`).join('') || `<div class="nd">Nenhuma ${noun} com esses filtros.</div>`}</div>`;
  }

  function renderList(container, rows, options) {
    const groups = group(rows, options.groupBy);
    // Com a alteração em massa disponível, cada linha começa pela caixa de seleção.
    const rowStart = item => BulkEdit.enabled() ? `<div class="tv-row tv-row-sel"><span>${BulkEdit.checkbox(item)}</span>` : '<div class="tv-row">';
    const row = item => boardMode()
      ? `${rowStart(item)}
          <button type="button" class="tv-row-title" ${item.Id ? `data-open="${escape(item.Id)}"` : 'disabled'} title="${escape(item.Nome)}">${escape(item.Nome)}</button>
          ${statusChip(item)}
          <span class="tv-cell" title="Cliente">${escape(item.Cliente || '—')}</span>
          <span class="tv-cell" title="Origem · Módulo">${escape([item.Origem, item.Modulo].filter(Boolean).join(' · ') || '—')}</span>
          <span>${item.Prioridade ? prioBadge(item.Prioridade) : ''}</span>
          <span class="tv-cell tv-muted" title="Solicitante">${escape(item.Solicitante || item.Tipo || '')}</span>
        </div>`
      : `${rowStart(item)}
          <button type="button" class="tv-row-title" ${item.Id ? `data-open="${escape(item.Id)}"` : 'disabled'} title="${escape(item.Nome)}">${escape(item.Nome)}</button>
          ${statusChip(item)}
          <span class="tv-cell" title="Responsável">${escape(devPeople(item).filter(person => !SprintRules.isExcludedPerson(person)).join(', ') || '—')}</span>
          <span class="tv-cell" title="Cliente">${escape(item.Cliente || '—')}</span>
          <span>${item.Prioridade ? prioBadge(item.Prioridade) : ''}</span>
          <span class="tv-cell tv-muted">${options.showSprint ? `Sprint ${escape(item.Sprint)}` : escape(item.Tipo || '')}</span>
        </div>`;
    const sections = groups.filter(section => section.rows.length);
    container.innerHTML = `<div class="tv-list">${sections.map(section => `<details class="tv-group" ${state.collapsed.has(`${options.groupBy}:${section.key}`) ? '' : 'open'} data-group="${escape(`${options.groupBy}:${section.key}`)}">
        <summary><span class="tv-dot" style="background:${escape(section.color)}"></span><strong>${escape(section.label)}</strong><span class="tv-count">${section.rows.length}</span></summary>
        <div class="tv-rows">${section.rows.map(row).join('')}</div>
      </details>`).join('') || `<div class="nd">${boardMode() ? 'Nenhum item' : 'Nenhuma tarefa'} com esses filtros.</div>`}</div>`;
  }

  const formatDate = date => date ? date.split('-').reverse().join('/') : '';

  /** Faixa do topo do card da esteira: entrega pela sprint (vermelha se o fim da sprint já passou) ou a sprint em que foi entregue. */
  function pipelineBanner(row, stage) {
    if (!row.Sprint) return '';
    if (stage === 'implementado') return `<div class="pl-banner done"><span>Entregue na</span><strong>Sprint ${escape(row.Sprint)}</strong></div>`;
    const end = window.MONDAY_SPRINT_DATES?.[row.Sprint]?.end;
    if (!end) return `<div class="pl-banner"><span>Sprint</span><strong>${escape(row.Sprint)}</strong></div>`;
    const late = end < SprintRules.today();
    return `<div class="pl-banner ${late ? 'late' : ''}"><span>Previsão de entrega · Sprint ${escape(row.Sprint)}${late ? ' · atrasado' : ''}</span><strong>${escape(formatDate(end))}</strong></div>`;
  }

  function pipelineCard(row, stage, options) {
    const people = devPeople(row).filter(person => !SprintRules.isExcludedPerson(person));
    const description = clean(row.Descricao);
    return `<article class="pl-card">
        ${pipelineBanner(row, stage)}
        <button type="button" class="pl-title" ${row.Id ? `data-open="${escape(row.Id)}"` : 'disabled'}>${escape(row.Nome)}</button>
        ${description ? `<p class="pl-desc">${escape(description)}</p>` : ''}
        <div class="tv-card-meta">
          <span class="tv-status" style="--st:${escape(options.statusColor(row))}" title="Status no Monday">${escape(row.Status || 'Sem status')}</span>
          ${row.Prioridade ? prioBadge(row.Prioridade) : ''}
        </div>
        ${row.Cliente || people.length ? `<div class="tv-card-foot">
          <span class="tv-client" title="Cliente">${row.Cliente ? `🏢 ${escape(row.Cliente)}` : ''}</span>
          <span class="tv-people">${people.map(person => `<span class="tv-avatar" title="${escape(person)}">${escape(initials(person))}</span>`).join('')}</span>
        </div>` : ''}
      </article>`;
  }

  /** Esteira da Visão 360°: uma coluna por etapa, na ordem do fluxo; cada card já chega na etapa em que está. */
  function renderPipeline(container, rows, options) {
    container.innerHTML = `<div class="pl-board">${options.stages.map(stage => {
      // Implementados reúne todas as sprints: as entregas mais recentes primeiro.
      const stageRows = stage.key === 'implementado'
        ? rows.filter(row => row.Etapa === stage.key).sort((a, b) => (Number(b.Sprint) || 0) - (Number(a.Sprint) || 0))
        : Bombeiro.sortByPriority(rows.filter(row => row.Etapa === stage.key));
      return `<section class="pl-column" style="--stage:${escape(stage.color)}">
          <header><h3>${escape(stage.label)}</h3><span class="tv-count">${stageRows.length}</span></header>
          <div class="pl-cards">${stageRows.map(row => pipelineCard(row, stage.key, options)).join('') || '<div class="tv-empty">Nenhuma demanda</div>'}</div>
        </section>`;
    }).join('')}</div>`;
  }

  function render(container, rows, options) {
    state.options = options;
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

  /** Lista de onde os itens vêm (tarefas ou itens das abas Roadmap e Backlog), para a mudança aparecer na hora. */
  function source() { return state.options.source || DATA; }

  /** Muda o status na tela na hora e no Monday em seguida; se o Monday recusar, volta ao status anterior. */
  async function move(id, label) {
    const rows = source().filter(row => row.Id === id);
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

  function closeMenu() { document.getElementById('tv-menu')?.remove(); }

  /** Menu com os status do Monday da etapa de destino; com um status só, aplica direto. */
  function chooseStatus(id, stage, x, y) {
    closeMenu();
    const statuses = stageStatuses(stage);
    const current = source().find(row => row.Id === id)?.Status;
    if (!statuses.length) { toast('Nenhum status do quadro corresponde a esta etapa.', 'error'); return; }
    if (statuses.length === 1) { move(id, statuses[0]); return; }
    const menu = document.createElement('div');
    menu.id = 'tv-menu';
    menu.className = 'tv-menu';
    menu.setAttribute('role', 'menu');
    menu.innerHTML = `<div class="tv-menu-title">Mover para ${escape(STAGES.find(item => item.key === stage)?.label)} como:</div>
      ${statuses.map(label => `<button type="button" role="menuitem" data-status="${escape(label)}" ${label === current ? 'disabled' : ''}>
        <span class="tv-dot" style="background:${escape(statusColor(label))}"></span>${escape(label)}</button>`).join('')}`;
    document.body.appendChild(menu);
    const {width, height} = menu.getBoundingClientRect();
    menu.style.left = `${Math.max(8, Math.min(x, innerWidth - width - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(y, innerHeight - height - 8))}px`;
    menu.addEventListener('click', event => {
      const option = event.target.closest('[data-status]');
      if (option) { closeMenu(); move(id, option.dataset.status); }
    });
    menu.querySelector('button:not([disabled])')?.focus();
  }

  document.addEventListener('click', event => { if (!event.target.closest('#tv-menu')) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });

  document.addEventListener('DOMContentLoaded', () => {
    const view = document.getElementById('raw-board-view');
    if (!view) return;
    const target = event => {
      const column = event.target.closest('.tv-column');
      return state.dragging && (column?.dataset.stage || column?.dataset.status) ? column : null;
    };
    view.addEventListener('click', event => {
      const open = event.target.closest('[data-open]');
      if (open) ItemDetail.open(open.dataset.open);
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
      const column = target(event);
      if (!column) return;
      event.preventDefault();
      view.querySelectorAll('.tv-column.over').forEach(other => other !== column && other.classList.remove('over'));
      column.classList.add('over');
    });
    view.addEventListener('drop', event => {
      const column = target(event);
      if (!column) return;
      event.preventDefault();
      const id = state.dragging;
      // Roadmap e Backlog: a coluna já é o status exato.
      if (column.dataset.status) { move(id, column.dataset.status); return; }
      // Tarefas: soltar na mesma etapa não muda nada; para trocar o status dentro dela, use o detalhe do card.
      if (stageOf(source().find(row => row.Id === id)?.Status) === column.dataset.stage) return;
      chooseStatus(id, column.dataset.stage, event.clientX, event.clientY);
    });
  });

  return {render, renderPipeline, toast};
})();
