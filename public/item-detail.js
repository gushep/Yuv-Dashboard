/* Detalhe do card no estilo ClickUp: campos e comentários à esquerda; novo comentário com anexos e histórico à direita. */
window.ItemDetail = (() => {
  const state = {id:null, item:null, activity:null, activityError:'', showEmpty:false, saving:false, request:0,
    draft:'', files:[], sending:false, preparing:false, pendingUpdateId:null, composerMessage:'', composerKind:'',
    editing:null, editSaving:false, options:{}, fresh:false};
  /** Últimos detalhes vistos de cada card: reabrir mostra na hora e atualiza com o Monday em seguida. */
  const recent = new Map();
  const MAX_RECENT = 50;
  function remember(item) {
    recent.delete(item.id);
    recent.set(item.id, item);
    if (recent.size > MAX_RECENT) recent.delete(recent.keys().next().value);
  }
  /** Campos que a tela já tem do card, mostrados enquanto o Monday responde. */
  const PREVIEW_FIELDS = [['Status', 'Status'], ['Responsavel', 'Responsável'], ['Prioridade', 'Prioridade'], ['Tipo', 'Tipo'], ['Sprint', 'Sprint'],
    ['Epico', 'Épico'], ['Planejada', 'Planejada'], ['Cliente', 'Cliente'], ['EstH', 'Tempo estimado (h)'], ['RealH', 'Tempo real (h)'],
    ['Origem', 'Origem'], ['Modulo', 'Módulo'], ['Esforco', 'Esforço'], ['Solicitante', 'Solicitante']];
  const MAX_FILES = 10;
  const el = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const dateTime = value => value ? new Date(value).toLocaleString('pt-BR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '—';
  const AVATAR_COLORS = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#059669', '#0891b2', '#4f46e5', '#ca8a04'];
  const EVENTS = {
    create_pulse:'criou o card', update_name:'renomeou o card', move_pulse_into_group:'moveu o card de grupo',
    move_pulse_into_board:'moveu o card para este quadro', move_pulse_from_board:'moveu o card de quadro', duplicate_pulse:'duplicou o card',
    archive_pulse:'arquivou o card', restore_pulse:'restaurou o card', delete_pulse:'excluiu o card', subscribe:'passou a seguir o card',
    create_subitem:'criou um subitem'
  };
  /** Campos que o dashboard já destaca no topo, na ordem do ClickUp. */
  const HIGHLIGHT_TYPES = ['status', 'people', 'date', 'timeline', 'numbers', 'time_tracking'];

  /** Linhas já carregadas na tela: tarefas das sprints e itens das abas Roadmap e Backlog. */
  function knownRows() { return [...DATA, ...Object.values(window.RAW_BOARDS || {}).flatMap(board => board.rows)]; }

  function initials(name) { return String(name).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || '?'; }
  function avatarColor(name) { let hash = 0; for (const char of String(name)) hash = (hash * 31 + char.charCodeAt(0)) >>> 0; return AVATAR_COLORS[hash % AVATAR_COLORS.length]; }
  function avatar(name, size = '') { return `<span class="td-avatar ${size}" style="background:${avatarColor(name)}" title="${escape(name)}">${escape(initials(name))}</span>`; }
  function people(text) {
    const names = String(text || '').split(',').map(name => name.trim()).filter(Boolean);
    return names.length ? `<span class="td-people">${names.map(name => `<span class="td-person">${avatar(name)}${escape(name)}</span>`).join('')}</span>` : '<span class="td-empty">Vazio</span>';
  }
  function pill(label, color) {
    return label ? `<span class="td-pill" style="--pill:${escape(color || '#94a3b8')}">${escape(label)}</span>` : '<span class="td-empty">Vazio</span>';
  }
  function links(text) {
    return escape(text).replace(/https?:\/\/[^\s,]+/g, url => `<a href="${url}" target="_blank" rel="noopener">${url}</a>`);
  }

  const FILE_ICONS = {pdf:'📄', html:'🌐', htm:'🌐', mp4:'🎬', mov:'🎬', webm:'🎬', zip:'🗜️', rar:'🗜️', xls:'📊', xlsx:'📊', csv:'📊', doc:'📝', docx:'📝', txt:'📝', json:'🧾', log:'🧾'};
  const VIDEO = ['mp4', 'webm', 'mov'];

  /** Arquivo do Monday: imagem vira miniatura (abre ampliada); demais arquivos, um item com ícone, tamanho e link. */
  function attachment(file) {
    if (file.image) {
      return `<button type="button" class="td-thumb" data-preview="${escape(file.id)}" title="${escape(file.name)}"><img src="${escape(file.url)}" alt="${escape(file.name)}" loading="lazy"></button>`;
    }
    const icon = FILE_ICONS[file.extension] || '📎';
    const preview = VIDEO.includes(file.extension) ? `data-preview="${escape(file.id)}"` : '';
    return `<span class="td-file-chip">
        <span class="td-file-icon">${icon}</span>
        <span class="td-file-info"><b title="${escape(file.name)}">${escape(file.name)}</b><small>${escape((file.extension || 'arquivo').toUpperCase())}${file.size ? ` · ${Attachments.fileSize(file.size)}` : ''}</small></span>
        ${preview ? `<button type="button" class="td-file-open" ${preview}>Ver</button>` : ''}
        <a class="td-file-open" href="${escape(file.url)}" target="_blank" rel="noopener">Abrir ↗</a>
      </span>`;
  }

  function attachmentList(files) {
    const images = files.filter(file => file.image);
    const others = files.filter(file => !file.image);
    return `${images.length ? `<div class="td-gallery">${images.map(attachment).join('')}</div>` : ''}${others.length ? `<div class="td-file-list">${others.map(attachment).join('')}</div>` : ''}`;
  }

  /** Visualizador dentro do modal para imagens e vídeos; outros arquivos abrem em nova aba. */
  function preview(id) {
    const file = state.item.attachments.find(entry => entry.id === id);
    if (!file) return;
    const box = document.createElement('div');
    box.className = 'td-lightbox';
    box.innerHTML = `<div class="td-lightbox-bar"><span>${escape(file.name)}</span><a href="${escape(file.url)}" target="_blank" rel="noopener">Abrir em nova aba ↗</a><button type="button" data-lightbox-close aria-label="Fechar">✕</button></div>
      ${file.image ? `<img src="${escape(file.url)}" alt="${escape(file.name)}">` : `<video src="${escape(file.url)}" controls autoplay></video>`}`;
    el('item-modal').appendChild(box);
  }

  function display(column) {
    if (column.type === 'status') return pill(column.text, column.color);
    if (column.type === 'people') return people(column.text);
    if (!column.text) return '<span class="td-empty">Vazio</span>';
    if (column.type === 'file' && column.files?.length) return attachmentList(column.files);
    if (column.type === 'file' || column.type === 'link') return `<span class="td-text">${links(column.text)}</span>`;
    return `<span class="td-text">${escape(column.text)}</span>`;
  }

  /** Quem pode atualizar cards altera os campos que o Monday deixa gravar; os calculados (espelho, fórmula...) ficam só leitura. */
  const canEdit = column => Boolean(state.item.canUpdateItems && column.editable);

  function value(column) {
    if (column.type === 'status' && column.id === state.item.statusColumnId && state.item.canUpdateItems) return statusPicker(column);
    if (!canEdit(column)) return display(column);
    if (state.editing === column.id) return editor(column);
    // Checkbox grava ao marcar, sem abrir editor.
    if (column.type === 'checkbox') return `<label class="td-check-field"><input type="checkbox" data-check="${escape(column.id)}" ${column.current ? 'checked' : ''} ${state.editSaving ? 'disabled' : ''}> ${column.current ? 'Marcado' : 'Desmarcado'}</label>`;
    return `<div class="td-editable" data-edit="${escape(column.id)}" role="button" tabindex="0" title="Clique para alterar no Monday">${display(column)}<span class="td-edit-icon" aria-hidden="true">✎</span></div>`;
  }

  const editButtons = (clear = true) => `<div class="td-editor-actions">
      <button type="submit" class="td-send" ${state.editSaving ? 'disabled' : ''}>${state.editSaving ? 'Salvando...' : 'Salvar'}</button>
      <button type="button" class="td-link-btn" data-cancel-edit ${state.editSaving ? 'disabled' : ''}>Cancelar</button>
      ${clear ? `<button type="button" class="td-link-btn td-clear" data-clear-edit ${state.editSaving ? 'disabled' : ''}>Limpar campo</button>` : ''}
    </div>`;

  /** Lista marcável com busca (dropdown, pessoas, Sprint, Épico...); as marcadas aparecem primeiro. */
  function checklist(options, selected) {
    const sorted = [...options].sort((a, b) => Number(selected.includes(b.value)) - Number(selected.includes(a.value)));
    return `${options.length > 8 ? '<input type="search" class="td-input" data-option-search placeholder="Buscar..." aria-label="Buscar opção">' : ''}
      <div class="td-options">${sorted.map(option => `<label><input type="checkbox" name="v" value="${escape(option.value)}" ${selected.includes(option.value) ? 'checked' : ''}>${escape(option.label)}</label>`).join('') || '<span class="td-empty">Nenhuma opção</span>'}</div>`;
  }

  /** Editor de cada tipo de campo; o valor sai em collect() no mesmo formato que o servidor espera. */
  function editor(column) {
    const current = column.current;
    let body;
    switch (column.type) {
      case 'status':
        return `<div class="td-editor"><div class="td-status-menu td-inline-menu" role="listbox" aria-label="${escape(column.title)}">
            ${(column.options || []).map(option => `<button type="button" role="option" data-pick="${escape(option.label)}" aria-selected="${option.label === current}" ${state.editSaving ? 'disabled' : ''}>
              <span class="td-dot" style="background:${escape(option.color || '#94a3b8')}"></span>${escape(option.label)}${option.label === current ? ' <span class="td-check">✓</span>' : ''}</button>`).join('')}
          </div><div class="td-editor-actions"><button type="button" class="td-link-btn" data-cancel-edit>Cancelar</button>${current ? '<button type="button" class="td-link-btn td-clear" data-clear-edit>Limpar campo</button>' : ''}</div></div>`;
      case 'dropdown':
        body = checklist((column.options || []).map(option => ({value:option.label, label:option.label})), current || []);
        break;
      case 'people':
      case 'board_relation': {
        const loaded = state.options[column.id];
        if (!loaded) return '<div class="td-editor"><span class="td-empty">Carregando opções do Monday...</span></div>';
        if (loaded.error) return `<div class="td-editor"><span class="td-empty">${escape(loaded.error)}</span><div class="td-editor-actions"><button type="button" class="td-link-btn" data-cancel-edit>Fechar</button></div></div>`;
        body = checklist(loaded.map(option => ({value:option.id, label:option.name})), current || []);
        break;
      }
      case 'long_text':
        body = `<textarea class="td-input" name="v" rows="6">${escape(current)}</textarea>`;
        break;
      case 'date':
        body = `<input class="td-input" type="date" name="v" value="${escape(current)}">`;
        break;
      case 'timeline':
        body = `<div class="td-editor-row"><input class="td-input" type="date" name="from" value="${escape(current.from)}" aria-label="Início"><span>até</span><input class="td-input" type="date" name="to" value="${escape(current.to)}" aria-label="Fim"></div>`;
        break;
      case 'link':
        body = `<input class="td-input" type="url" name="url" value="${escape(current.url)}" placeholder="https://..." aria-label="Link">
          <input class="td-input" type="text" name="text" value="${escape(current.text)}" placeholder="Texto do link (opcional)" aria-label="Texto do link">`;
        break;
      default:
        body = `<input class="td-input" type="${column.type === 'email' ? 'email' : 'text'}" ${column.type === 'numbers' ? 'inputmode="decimal"' : ''} name="v" value="${escape(current)}">`;
    }
    return `<form class="td-editor" data-editor="${escape(column.id)}">${body}${editButtons()}</form>`;
  }

  /** Valor do editor aberto, no formato do tipo do campo. */
  function collect(form, type) {
    if (['dropdown', 'people', 'board_relation'].includes(type)) return [...form.querySelectorAll('input[name="v"]:checked')].map(input => input.value);
    if (type === 'timeline') return {from:form.elements.from.value, to:form.elements.to.value};
    if (type === 'link') return {url:form.elements.url.value, text:form.elements.text.value};
    return form.elements.v.value;
  }

  const emptyValue = type => (['dropdown', 'people', 'board_relation'].includes(type) ? [] : type === 'timeline' ? {from:'', to:''} : type === 'link' ? {url:'', text:''} : '');

  function refreshMain() {
    const main = el('item-modal').querySelector('.td-main');
    if (main && state.item) main.innerHTML = renderMain();
    el('item-modal').querySelector('.td-editor input:not([type=checkbox]):not([type=search]), .td-editor textarea')?.focus();
  }

  async function startEdit(columnId) {
    if (state.editSaving) return;
    // Só edita sobre a versão atual do Monday, para não gravar por cima de uma alteração feita por outra pessoa.
    if (!state.fresh) { message('Aguarde um instante: atualizando o card com o Monday antes de editar.'); return; }
    state.editing = columnId;
    refreshMain();
    const column = state.item.columns.find(entry => entry.id === columnId);
    if (!column || !['people', 'board_relation'].includes(column.type) || state.options[columnId]) return;
    const request = state.request;
    try {
      const result = await mondayRequest(`/api/items/${encodeURIComponent(state.id)}/columns/${encodeURIComponent(columnId)}/options`);
      if (request !== state.request) return;
      state.options[columnId] = result.options;
    } catch (error) {
      if (request !== state.request) return;
      state.options[columnId] = {error:error.message};
    }
    if (state.editing === columnId) refreshMain();
  }

  function cancelEdit() {
    state.editing = null;
    refreshMain();
  }

  /** Grava no Monday e recarrega o card, para o campo mostrar o valor como o Monday guardou. */
  async function saveColumn(columnId, newValue) {
    if (state.editSaving) return;
    const request = state.request;
    const title = columnId === 'name' ? 'Nome' : state.item.columns.find(column => column.id === columnId)?.title || 'Campo';
    state.editSaving = true;
    // Só trava o editor: redesenhar agora apagaria o que foi digitado, caso o Monday recuse.
    const controls = [...el('item-modal').querySelectorAll('.td-editor button, .td-editor input, .td-editor textarea, [data-check]')];
    controls.forEach(control => { control.disabled = true; });
    message(`Salvando “${title}” no Monday...`);
    try {
      await mondayRequest(`/api/items/${encodeURIComponent(state.id)}/columns`, {column:columnId, value:newValue});
      const item = await mondayRequest(`/api/items/${encodeURIComponent(state.id)}`).catch(() => null);
      if (request !== state.request) return;
      if (item) { state.item = item; remember(item); }
      state.editing = null;
      state.editSaving = false;
      refreshMain();
      message(`“${title}” alterado no Monday.`, 'success');
      state.activity = null;
      loadActivity(request);
      // As telas buscam o card atualizado: o servidor já marcou os dados como desatualizados.
      if (typeof syncMonday === 'function') syncMonday(false);
    } catch (error) {
      if (request !== state.request) return;
      state.editSaving = false;
      controls.forEach(control => { control.disabled = false; });
      message(error.message, 'error');
    }
  }

  function statusPicker(column) {
    const options = state.item.statusOptions.map(option => `<button type="button" role="option" data-status="${escape(option.label)}" aria-selected="${option.label === column.text}">
        <span class="td-dot" style="background:${escape(option.color || '#94a3b8')}"></span>${escape(option.label)}${option.label === column.text ? ' <span class="td-check">✓</span>' : ''}</button>`).join('');
    return `<details class="td-status-picker">
        <summary title="Alterar status no Monday">${pill(column.text || 'Sem status', column.color)}<span class="td-caret">▾</span></summary>
        <div class="td-status-menu" role="listbox" aria-label="Alterar status">${options}</div>
      </details>`;
  }

  function field(column) {
    return `<div class="td-field"><div class="td-field-label" title="${escape(column.title)}">${escape(column.title)}</div><div class="td-field-value">${value(column)}</div></div>`;
  }

  /** Título do card: quem pode atualizar cards renomeia clicando nele. */
  function renderTitle(item) {
    if (!item.canUpdateItems) return `<h2 class="td-title">${escape(item.name)}</h2>`;
    if (state.editing === 'name') {
      return `<form class="td-editor td-title-editor" data-editor="name"><input class="td-input td-title-input" name="v" value="${escape(item.name)}" maxlength="255" aria-label="Nome do card">${editButtons(false)}</form>`;
    }
    return `<h2 class="td-title td-editable" data-edit="name" role="button" tabindex="0" title="Clique para renomear no Monday">${escape(item.name)}<span class="td-edit-icon" aria-hidden="true">✎</span></h2>`;
  }

  /** Textos longos (Descrição, Requisitos...) preenchidos ganham seção própria, editável pelo botão Editar. */
  function renderDescription(column) {
    const editing = state.editing === column.id;
    return `<section class="td-section"><div class="td-section-title">${escape(column.title)}
        ${canEdit(column) && !editing ? `<button type="button" class="td-link-btn td-section-edit" data-edit="${escape(column.id)}">✎ Editar</button>` : ''}</div>
      ${editing ? editor(column) : `<div class="td-description">${links(column.text)}</div>`}</section>`;
  }

  function renderMain() {
    const item = state.item;
    // Texto longo vazio fica entre os campos vazios, para poder ser preenchido; preenchido, vira seção abaixo.
    const columns = item.columns.filter(column => column.type !== 'long_text' || !column.text);
    const isFilled = column => column.text !== '';
    const status = columns.find(column => column.id === item.statusColumnId);
    const ordered = [...columns].sort((a, b) => {
      if (a === status) return -1;
      if (b === status) return 1;
      const rank = column => { const index = HIGHLIGHT_TYPES.indexOf(column.type); return index < 0 ? HIGHLIGHT_TYPES.length : index; };
      return rank(a) - rank(b);
    });
    // O campo em edição continua visível mesmo vazio.
    const filled = ordered.filter(column => column === status || isFilled(column) || column.id === state.editing);
    const empty = ordered.filter(column => column !== status && !isFilled(column) && column.id !== state.editing);
    const descriptions = item.columns.filter(column => column.type === 'long_text' && column.text);
    const done = item.subitems.filter(subitem => /feito|conclu|done|finaliz|entreg/i.test(subitem.status)).length;
    const assets = item.attachments || [];

    return `${renderTitle(item)}
      <div class="td-meta-line">Criado por ${escape(item.creator || '—')} em ${dateTime(item.createdAt)} · Atualizado em ${dateTime(item.updatedAt)}</div>
      <div class="td-message" id="td-message" aria-live="polite"></div>
      <section class="td-fields">${filled.map(field).join('')}
        ${state.showEmpty ? empty.map(field).join('') : ''}
      </section>
      ${empty.length ? `<button type="button" class="td-link-btn" data-toggle-empty>${state.showEmpty ? 'Ocultar campos vazios' : `Mostrar ${empty.length} campo(s) vazio(s)`}</button>` : ''}
      ${descriptions.map(renderDescription).join('')}
      ${item.subitems.length ? `<section class="td-section">
        <div class="td-section-title">Subitens <span class="td-count">${done}/${item.subitems.length}</span></div>
        <div class="td-progress"><span style="width:${Math.round(done / item.subitems.length * 100)}%"></span></div>
        <div class="td-subitems">${item.subitems.map(subitem => `<div class="td-subitem">
            <span class="td-dot" style="background:${escape(subitem.color || '#94a3b8')}"></span>
            <span class="td-subitem-name">${escape(subitem.name)}</span>
            ${subitem.status ? pill(subitem.status, subitem.color) : ''}
            ${subitem.people ? `<span class="td-avatars">${subitem.people.split(',').map(name => avatar(name.trim(), 'sm')).join('')}</span>` : ''}
          </div>`).join('')}</div>
      </section>` : ''}
      ${assets.length ? `<section class="td-section"><div class="td-section-title">Anexos <span class="td-count">${assets.length}</span></div>
        ${attachmentList(assets)}</section>` : ''}
      <section class="td-section"><div class="td-section-title">Comentários <span class="td-count">${item.updates.length}</span></div>
        <div class="td-comments">${item.updates.map(entry => comment(entry)).join('') || '<div class="td-feed-note">Nenhum comentário neste card.</div>'}</div>
      </section>`;
  }

  function comment(entry, reply = false) {
    return `<div class="td-comment ${reply ? 'reply' : ''}">
        <div class="td-comment-head">${avatar(entry.author, 'sm')}<b>${escape(entry.author)}</b><span>${dateTime(entry.createdAt)}</span></div>
        <div class="td-comment-text">${entry.text ? links(entry.text) : '<span class="td-empty">(sem texto)</span>'}</div>
        ${entry.images?.length || entry.assets?.length ? attachmentList([...(entry.images || []), ...(entry.assets || [])]) : ''}
        ${(entry.replies || []).map(item => comment(item, true)).join('')}
      </div>`;
  }

  function historyLine(entry) {
    const action = entry.column ? `alterou <b>${escape(entry.column)}</b>` : escape(EVENTS[entry.event] || entry.event.replaceAll('_', ' '));
    const change = entry.column && (entry.from || entry.to)
      ? `<div class="td-change"><span class="from">${escape(entry.from || 'vazio')}</span> → <span class="to">${escape(entry.to || 'vazio')}</span></div>` : '';
    return `<div class="td-history"><span class="td-history-dot"></span><div><div class="td-history-text"><b>${escape(entry.author)}</b> ${action}<span>${dateTime(entry.createdAt)}</span></div>${change}</div></div>`;
  }

  const fileSize = bytes => Attachments.fileSize(bytes);

  function renderFiles() {
    return state.files.map(({file, error}, index) => `<div class="td-file ${file.size > uploadLimit() || error ? 'too-big' : ''}" title="${escape(error)}">
        <span class="td-file-name" title="${escape(file.name)}">📎 ${escape(file.name)}</span><span class="td-file-size">${fileSize(file.size)}</span>
        <button type="button" data-remove-file="${index}" aria-label="Remover ${escape(file.name)}">✕</button>
      </div>`).join('');
  }

  /** Campo para comentar e anexar evidências; só aparece para quem pode atualizar cards. */
  function renderComposer() {
    if (!state.item.canUpdateItems) return '<div class="td-composer-locked">Você pode ver os comentários, mas não tem permissão para comentar ou anexar arquivos.</div>';
    const retrying = Boolean(state.pendingUpdateId);
    const busy = state.sending || state.preparing;
    return `<form class="td-composer" data-composer>
        <div class="td-composer-title">${retrying ? 'Anexos pendentes' : 'Novo comentário'}</div>
        ${retrying
          ? '<div class="td-composer-note">O comentário já foi publicado. Reenvie os anexos que falharam ou descarte-os.</div>'
          : `<textarea name="text" rows="5" placeholder="Escreva o resultado do teste, passos, observações... (Ctrl+Enter envia)" ${busy ? 'disabled' : ''}>${escape(state.draft)}</textarea>`}
        <label class="td-drop" data-drop>
          <input type="file" multiple hidden data-file-input ${busy ? 'disabled' : ''}>
          <span>Arraste arquivos, cole um print (Ctrl+V) ou <u>clique para anexar</u></span>
          <small>Até ${MAX_FILES} arquivos · ${fileSize(uploadLimit())} cada · imagens grandes são reduzidas automaticamente</small>
        </label>
        <div class="td-files" data-files>${renderFiles()}</div>
        <div class="td-composer-foot">
          <span class="td-composer-msg ${state.composerKind}" aria-live="polite">${escape(state.composerMessage)}</span>
          ${retrying ? `<button type="button" class="td-link-btn" data-discard ${busy ? 'disabled' : ''}>Descartar</button>` : ''}
          <button type="submit" class="td-send" ${busy ? 'disabled' : ''}>${state.sending ? 'Enviando...' : retrying ? 'Reenviar anexos' : 'Comentar'}</button>
        </div>
      </form>`;
  }

  function renderHistory() {
    const history = state.activity || [];
    const waiting = state.activity === null
      ? `<div class="td-feed-note">${state.activityError ? escape(`Histórico indisponível: ${state.activityError}`) : 'Carregando histórico...'}</div>` : '';
    return `<div class="td-activity-head">Histórico${state.activity ? ` <span class="td-count">${history.length}</span>` : ''}</div>
      <div class="td-feed">${waiting}${history.map(historyLine).join('') || (waiting ? '' : '<div class="td-feed-note">Nenhuma alteração registrada.</div>')}</div>`;
  }

  function renderActivity() {
    return `${renderComposer()}<div class="td-history-box" data-history>${renderHistory()}</div>`;
  }

  function refreshActivity() {
    const aside = el('item-modal').querySelector('.td-activity');
    if (!aside || !state.item) return;
    aside.innerHTML = renderActivity();
  }

  /** Maior arquivo que o servidor aceita por envio (limite do PHP ou 50 MB). */
  function uploadLimit() { return state.item?.maxUploadBytes || Attachments.DEFAULT_LIMIT; }

  function tooBigMessage(files) { return Attachments.tooBigMessage(files, uploadLimit()); }

  async function addFiles(list) {
    if (!list.length) return;
    const request = state.request;
    state.preparing = true;
    composerMessage('Preparando anexos...');
    updateFileList();
    const prepared = await Attachments.prepare(list, uploadLimit());
    if (request !== state.request) return;
    state.preparing = false;
    state.files = [...state.files, ...prepared.map(file => ({file, error:''}))].slice(0, MAX_FILES);
    const tooBig = state.files.map(entry => entry.file).filter(file => file.size > uploadLimit());
    composerMessage(tooBig.length ? tooBigMessage(tooBig) : '', 'error');
    updateFileList();
  }

  /** Atualiza só a lista de anexos e o botão, sem tirar o foco do texto em digitação. */
  function updateFileList() {
    const modal = el('item-modal');
    const list = modal.querySelector('[data-files]');
    if (list) list.innerHTML = renderFiles();
    const send = modal.querySelector('.td-send');
    if (send) send.disabled = state.sending || state.preparing;
  }

  function composerMessage(text, kind = '') {
    state.composerMessage = text;
    state.composerKind = kind;
    const target = el('item-modal').querySelector('.td-composer-msg');
    if (target) { target.textContent = text; target.className = `td-composer-msg ${kind}`; }
  }

  function uploadFile(updateId, file) {
    return Attachments.upload(`/api/items/${encodeURIComponent(state.id)}/comments/${encodeURIComponent(updateId)}/files`, file);
  }

  async function sendComment() {
    if (state.sending || state.preparing) return;
    const text = state.draft.trim();
    if (!text && !state.files.length) { composerMessage('Escreva um comentário ou anexe um arquivo.', 'error'); return; }
    const tooBig = state.files.map(entry => entry.file).filter(file => file.size > uploadLimit());
    if (tooBig.length) { composerMessage(tooBigMessage(tooBig), 'error'); return; }
    const request = state.request;
    state.sending = true;
    composerMessage(state.pendingUpdateId ? '' : 'Publicando comentário...');
    refreshActivity();
    try {
      if (!state.pendingUpdateId) {
        const result = await mondayRequest(`/api/items/${encodeURIComponent(state.id)}/comments`, {text, hasAttachments:state.files.length > 0});
        if (request !== state.request) return;
        state.pendingUpdateId = result.updateId;
        state.draft = '';
      }
      const total = state.files.length;
      for (const [index, entry] of state.files.entries()) {
        composerMessage(`Enviando anexo ${index + 1} de ${total}: ${entry.file.name}...`);
        try { await uploadFile(state.pendingUpdateId, entry.file); entry.done = true; entry.error = ''; }
        catch (error) { entry.error = error.message; }
        if (request !== state.request) return;
      }
      state.files = state.files.filter(entry => !entry.done);
      if (state.files.length) {
        composerMessage(`Comentário publicado, mas ${state.files.length} anexo(s) falharam: ${state.files.map(entry => `${entry.file.name} (${entry.error})`).join('; ')}.`, 'error');
      } else {
        state.pendingUpdateId = null;
        composerMessage(total ? `Comentário e ${total} anexo(s) publicados no Monday.` : 'Comentário publicado no Monday.', 'success');
      }
      // Recarrega o card para mostrar o comentário novo; se falhar, o comentário já está no Monday.
      const item = await mondayRequest(`/api/items/${encodeURIComponent(state.id)}`).catch(() => null);
      if (request !== state.request) return;
      state.sending = false;
      if (item) { state.item = item; remember(item); render(); }
    } catch (error) {
      if (request !== state.request) return;
      composerMessage(error.message, 'error');
    } finally {
      if (request === state.request) { state.sending = false; refreshActivity(); }
    }
  }

  function render() {
    const modal = el('item-modal');
    const row = knownRows().find(entry => entry.Id === state.id) || {};
    const item = state.item;
    const group = item?.group;
    modal.innerHTML = `<div class="td-head">
        <div class="td-crumbs">${escape(item?.board || 'Monday')} <span>›</span> ${group ? `<span class="td-dot" style="background:${escape(group.color || '#94a3b8')}"></span>${escape(group.title)}` : `Sprint ${escape(row.Sprint ?? '—')}`} <span>›</span> <code>#${escape(state.id)}</code></div>
        <div class="td-head-actions">
          ${item?.url || row.Url ? `<a class="td-btn" href="${escape(item?.url || row.Url)}" target="_blank" rel="noopener">Abrir no Monday ↗</a>` : ''}
          <button type="button" class="kpi-modal-close" aria-label="Fechar" data-close>✕</button>
        </div>
      </div>
      <div class="td-body">
        <div class="td-main">${item ? renderMain() : renderPreview(row)}</div>
        <aside class="td-activity">${item ? renderActivity() : '<div class="td-feed-note">Carregando comentários e histórico do Monday...</div>'}</aside>
      </div>`;
  }

  /** Enquanto o Monday responde: os campos que a tela já tem do card, só para leitura. */
  function renderPreview(row) {
    const fields = PREVIEW_FIELDS.map(([key, label]) => [label, clean(row[key])]).filter(([, text]) => text !== '');
    const description = clean(row.Descricao);
    return `<h2 class="td-title">${escape(row.Nome || 'Card')}</h2>
      <div class="td-message ${state.error ? 'error' : ''}" id="td-message">${state.error ? escape(state.error) : 'Carregando todos os campos, anexos e comentários do Monday...'}</div>
      <section class="td-fields">${fields.map(([label, text]) => `<div class="td-field"><div class="td-field-label">${escape(label)}</div><div class="td-field-value">${
        label === 'Status' ? stBadge(text) : label === 'Responsável' ? people(text) : label === 'Sprint' ? `Sprint ${escape(text)}` : `<span class="td-text">${escape(text)}</span>`}</div></div>`).join('')}</section>
      ${description ? `<section class="td-section"><div class="td-section-title">Descrição</div><div class="td-description">${links(description)}</div></section>` : ''}`;
  }

  function message(text, kind = '') {
    const target = el('td-message');
    if (!target) return;
    target.textContent = text;
    target.className = `td-message ${kind}`;
  }

  async function loadActivity(request) {
    try {
      const result = await mondayRequest(`/api/items/${encodeURIComponent(state.id)}/activity`);
      if (request !== state.request) return;
      state.activity = result.activity;
    } catch (error) {
      if (request !== state.request) return;
      state.activityError = error.message;
    }
    // Só o bloco do histórico é refeito: o comentário em digitação não perde o foco.
    const box = el('item-modal').querySelector('[data-history]');
    if (box) box.innerHTML = renderHistory();
  }

  async function open(id) {
    const request = ++state.request;
    // Abre na hora: com a última versão vista do card ou com os campos que a tela já tem; o Monday completa em seguida.
    const seen = recent.get(String(id)) || null;
    Object.assign(state, {id, item:seen, activity:null, activityError:'', error:'', showEmpty:false, draft:'', files:[], sending:false, preparing:false, pendingUpdateId:null, composerMessage:'', composerKind:'',
      editing:null, editSaving:false, options:{}, fresh:false});
    render();
    el('item-modal').showModal();
    if (seen) message('Atualizando com o Monday...');
    loadActivity(request);
    try {
      const item = await mondayRequest(`/api/items/${encodeURIComponent(id)}`);
      if (request !== state.request) return;
      state.item = item;
      state.fresh = true;
      remember(item);
    } catch (error) {
      if (request !== state.request) return;
      state.error = error.message;
      if (seen) { message(`Mostrando a última versão vista: ${error.message}`, 'error'); return; }
    }
    // Com o card já na tela, refaz só os campos: o comentário em digitação não perde o foco.
    if (seen && state.item) { refreshMain(); return; }
    render();
  }

  async function changeStatus(label) {
    const item = state.item;
    const column = item.columns.find(entry => entry.id === item.statusColumnId);
    if (state.saving || !column || label === column.text) return;
    state.saving = true;
    message(`Alterando status para “${label}” no Monday...`);
    try {
      await mondayRequest(`/api/items/${encodeURIComponent(state.id)}/status`, {status:label});
      column.text = label;
      column.color = item.statusOptions.find(option => option.label === label)?.color || null;
      // Atualiza a tabela na hora; a próxima sincronização traz o card já com o novo status.
      knownRows().filter(row => row.Id === state.id).forEach(row => { row.Status = label; });
      if (currentPage === 'raw') renderRaw();
      else if (currentPage === 'dashboard') renderDashboard();
      else if (currentPage === 'sprint') renderSprint(currentSprint);
      render();
      message(`Status alterado para “${label}”.`, 'success');
      state.activity = null;
      loadActivity(state.request);
    } catch (error) {
      render();
      message(error.message, 'error');
    } finally {
      state.saving = false;
    }
  }

  /**
   * Nome do card nos modais de indicadores (Dashboard, Sprints, Torre de controle, Burndown): abre o detalhe aqui mesmo
   * para quem acessa a tela Tarefas, que é a permissão exigida pela API do detalhe; o ↗ ao lado abre no Monday.
   * Sem essa tela, fica só o link do Monday.
   */
  function link({id, name, url}) {
    const label = escape(name || 'Sem título');
    const monday = window.SprintPanel?.safeMondayUrl(url) || '';
    if (!id || !canAccessScreen('raw')) return monday ? `<a href="${escape(monday)}" target="_blank" rel="noopener noreferrer">${label} ↗</a>` : label;
    return `<button type="button" class="td-card-link" data-item-detail="${escape(id)}">${label}</button>${monday
      ? `<a class="td-card-monday" href="${escape(monday)}" target="_blank" rel="noopener noreferrer" title="Abrir no Monday" aria-label="Abrir no Monday">↗</a>` : ''}`;
  }

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-item-detail]');
    if (trigger) open(trigger.dataset.itemDetail);
  });

  document.addEventListener('DOMContentLoaded', () => {
    const modal = el('item-modal');
    if (!modal) return;
    modal.addEventListener('click', event => {
      const lightbox = modal.querySelector('.td-lightbox');
      if (lightbox && (event.target === lightbox || event.target.closest('[data-lightbox-close]'))) { lightbox.remove(); return; }
      const previewButton = event.target.closest('[data-preview]');
      if (previewButton) { preview(previewButton.dataset.preview); return; }
      if (event.target === modal || event.target.closest('[data-close]')) {
        if (!(state.draft.trim() || state.files.length) || confirm('Descartar o comentário que ainda não foi enviado?')) modal.close();
        return;
      }
      modal.querySelectorAll('.td-status-picker[open]').forEach(picker => { if (!picker.contains(event.target)) picker.open = false; });
      const remove = event.target.closest('[data-remove-file]');
      if (remove && !state.sending) {
        state.files.splice(Number(remove.dataset.removeFile), 1);
        if (!state.files.length && state.pendingUpdateId) state.pendingUpdateId = null;
        composerMessage('');
        refreshActivity();
        return;
      }
      if (event.target.closest('[data-discard]')) { state.files = []; state.pendingUpdateId = null; composerMessage(''); refreshActivity(); return; }
      if (event.target.closest('[data-toggle-empty]')) { state.showEmpty = !state.showEmpty; modal.querySelector('.td-main').innerHTML = renderMain(); return; }
      const option = event.target.closest('[data-status]');
      if (option) { option.closest('details').open = false; changeStatus(option.dataset.status); return; }
      // Edição dos campos: clicar no valor abre o editor; links dentro do valor continuam abrindo normalmente.
      if (event.target.closest('[data-cancel-edit]')) { cancelEdit(); return; }
      const column = state.item?.columns.find(entry => entry.id === state.editing);
      if (event.target.closest('[data-clear-edit]') && column) { saveColumn(column.id, emptyValue(column.type)); return; }
      const pick = event.target.closest('[data-pick]');
      if (pick) { saveColumn(state.editing, pick.dataset.pick); return; }
      const edit = event.target.closest('[data-edit]');
      if (edit && !event.target.closest('a') && !state.editSaving) startEdit(edit.dataset.edit);
    });
    modal.addEventListener('submit', event => {
      if (event.target.matches('[data-composer]')) { event.preventDefault(); sendComment(); return; }
      const form = event.target.closest('[data-editor]');
      if (!form) return;
      event.preventDefault();
      const columnId = form.dataset.editor;
      const type = columnId === 'name' ? 'text' : state.item.columns.find(entry => entry.id === columnId)?.type;
      saveColumn(columnId, collect(form, type));
    });
    modal.addEventListener('input', event => {
      if (event.target.matches('[data-composer] textarea')) state.draft = event.target.value;
      if (event.target.matches('[data-option-search]')) {
        const query = event.target.value.toLowerCase();
        event.target.closest('.td-editor').querySelectorAll('.td-options label').forEach(label => { label.hidden = !label.textContent.toLowerCase().includes(query); });
      }
    });
    modal.addEventListener('keydown', event => {
      if (event.target.matches('[data-composer] textarea') && event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); sendComment(); }
      if (event.target.matches('[data-edit]') && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); startEdit(event.target.dataset.edit); }
      // Ctrl+Enter salva textos longos; Enter na busca de opções não envia o editor.
      if (event.target.matches('.td-editor textarea') && event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); event.target.form.requestSubmit(); }
      if (event.target.matches('[data-option-search]') && event.key === 'Enter') event.preventDefault();
    });
    // Esc com um campo em edição fecha só o editor, não o card.
    modal.addEventListener('cancel', event => { if (state.editing) { event.preventDefault(); if (!state.editSaving) cancelEdit(); } });
    modal.addEventListener('change', event => {
      if (event.target.matches('[data-file-input]')) { addFiles(event.target.files); event.target.value = ''; return; }
      if (event.target.matches('[data-check]')) saveColumn(event.target.dataset.check, event.target.checked);
    });
    // Prints colados no comentário viram anexos.
    modal.addEventListener('paste', event => {
      if (!event.target.closest('[data-composer]') || state.sending) return;
      const files = [...(event.clipboardData?.files || [])];
      if (files.length) { event.preventDefault(); addFiles(files); }
    });
    modal.addEventListener('dragover', event => {
      const drop = event.target.closest('[data-composer]');
      if (!drop) return;
      event.preventDefault();
      drop.querySelector('[data-drop]').classList.add('over');
    });
    modal.addEventListener('dragleave', event => { if (!event.target.closest('[data-composer]')?.contains(event.relatedTarget)) modal.querySelector('[data-drop]')?.classList.remove('over'); });
    modal.addEventListener('drop', event => {
      const drop = event.target.closest('[data-composer]');
      if (!drop) return;
      event.preventDefault();
      drop.querySelector('[data-drop]').classList.remove('over');
      if (!state.sending) addFiles(event.dataTransfer.files);
    });
    modal.addEventListener('cancel', event => {
      // Esc fecha primeiro a imagem ampliada, depois o card.
      const lightbox = modal.querySelector('.td-lightbox');
      if (lightbox) { event.preventDefault(); lightbox.remove(); return; }
      if ((state.draft.trim() || state.files.length) && !confirm('Descartar o comentário que ainda não foi enviado?')) event.preventDefault();
    });
    // O evento chega depois do fechamento: se outro card já foi aberto nesse meio-tempo, não apaga.
    modal.addEventListener('close', () => { if (!modal.open) { state.request++; modal.replaceChildren(); } });
  });

  return {open, link};
})();
