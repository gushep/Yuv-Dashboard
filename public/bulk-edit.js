/* Alteração em massa da tela Tarefas (tabela e lista agrupada): marca itens e aplica o mesmo Status, Prioridade ou Cliente no Monday.
 * Só aparece para quem pode atualizar cards. A seleção vale para os itens visíveis com os filtros atuais e é limpa ao trocar de aba. */
window.BulkEdit = (() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const MAX_ITEMS = 200;
  const state = {selected:new Set(), visible:[], context:null, field:'Status', value:'', busy:false};

  const enabled = () => Boolean(state.context?.enabled);
  const field = () => state.context.fields.find(item => item.key === state.field) || state.context.fields[0];

  /** Caixa de seleção de uma linha (tabela ou lista); vazia quando a alteração em massa não está disponível. */
  function checkbox(row) {
    if (!enabled() || !row.Id) return '';
    return `<input type="checkbox" class="bulk-sel" data-id="${escape(row.Id)}" aria-label="Selecionar ${escape(row.Nome)}" ${state.selected.has(String(row.Id)) ? 'checked' : ''}>`;
  }

  /**
   * Chamado a cada renderização da tela, antes das linhas: guarda o contexto da aba, descarta da seleção
   * o que saiu dos filtros e redesenha a barra de ação.
   */
  function update(context, rows) {
    if (state.context?.key !== context.key) { state.selected.clear(); state.value = ''; }
    state.context = context;
    state.visible = [...new Set(rows.map(row => String(row.Id || '')).filter(Boolean))];
    const visible = new Set(state.visible);
    [...state.selected].forEach(id => { if (!visible.has(id)) state.selected.delete(id); });
    if (!context.fields.some(item => item.key === state.field)) { state.field = context.fields[0].key; state.value = ''; }
    renderBar();
  }

  function valueControl() {
    const current = field();
    const options = state.context.options[current.key] || [];
    if (current.key === 'Cliente' || !options.length) {
      return `<input type="text" id="bulk-value" class="bulk-input" list="bulk-values" maxlength="255" placeholder="Novo ${escape(current.label.toLowerCase())}" value="${escape(state.value)}">
        <datalist id="bulk-values">${options.map(option => `<option value="${escape(option)}">`).join('')}</datalist>`;
    }
    return `<select id="bulk-value" class="bulk-input"><option value="">Novo valor...</option>
      ${options.map(option => `<option value="${escape(option)}" ${option === state.value ? 'selected' : ''}>${escape(option)}</option>`).join('')}</select>`;
  }

  function renderBar() {
    const bar = document.getElementById('raw-bulk');
    if (!bar) return;
    bar.hidden = !enabled();
    if (!enabled()) return;
    const count = state.selected.size;
    const all = state.visible.length > 0 && state.visible.every(id => state.selected.has(id));
    bar.classList.toggle('active', count > 0);
    bar.innerHTML = `<label class="bulk-all"><input type="checkbox" id="bulk-all" ${all ? 'checked' : ''} ${state.visible.length ? '' : 'disabled'}>
        Selecionar todos (${state.visible.length})</label>
      ${count ? `<strong class="bulk-count">${count} ${count === 1 ? 'selecionado' : 'selecionados'}</strong>
        <select id="bulk-field" class="bulk-input" aria-label="Campo a alterar">${state.context.fields.map(item =>
          `<option value="${escape(item.key)}" ${item.key === state.field ? 'selected' : ''}>${escape(item.label)}</option>`).join('')}</select>
        ${valueControl()}
        <button type="button" id="bulk-apply" class="bulk-apply" ${state.busy || count > MAX_ITEMS ? 'disabled' : ''}>${state.busy ? 'Aplicando...' : 'Aplicar'}</button>
        <button type="button" id="bulk-clear" class="bulk-link" ${state.busy ? 'disabled' : ''}>Limpar seleção</button>
        ${count > MAX_ITEMS ? `<span class="bulk-warn">Selecione no máximo ${MAX_ITEMS} itens por vez.</span>` : ''}`
      : '<span class="tv-muted">Marque itens para alterar Status, Prioridade ou Cliente de uma vez.</span>'}`;
    const allBox = document.getElementById('bulk-all');
    allBox.indeterminate = count > 0 && !all;
  }

  function toggle(id, checked) {
    if (checked) state.selected.add(id); else state.selected.delete(id);
  }

  function syncCheckboxes() {
    document.querySelectorAll('.bulk-sel').forEach(box => { box.checked = state.selected.has(box.dataset.id); });
  }

  /** Grava no Monday, atualiza na tela só os itens confirmados e mantém selecionados os que falharam. */
  async function apply() {
    const current = field();
    const value = state.value.trim();
    const ids = [...state.selected];
    if (!value) { TaskViews.toast('Escolha o novo valor.', 'error'); return; }
    const noun = ids.length === 1 ? 'item' : 'itens';
    if (!confirm(`Alterar ${current.label} de ${ids.length} ${noun} para “${value}” no Monday?`)) return;
    state.busy = true;
    renderBar();
    try {
      const result = await mondayRequest('/api/items/bulk', {items:ids, field:current.key, value});
      const updated = new Set(result.updated.map(String));
      state.context.source.forEach(row => { if (updated.has(String(row.Id))) row[current.key] = result.value; });
      updated.forEach(id => state.selected.delete(id));
      const failed = Object.entries(result.failed || {});
      if (!failed.length) TaskViews.toast(`${current.label} alterado em ${updated.size} ${updated.size === 1 ? 'item' : 'itens'}.`, 'success');
      else TaskViews.toast(`${updated.size} alterado(s); ${failed.length} com erro (seguem selecionados): ${failed[0][1]}`, 'error');
    } catch (error) {
      TaskViews.toast(error.message, 'error');
    } finally {
      state.busy = false;
      state.context.onChange();
    }
  }

  document.addEventListener('change', event => {
    const target = event.target;
    if (target.classList?.contains('bulk-sel')) { toggle(target.dataset.id, target.checked); renderBar(); return; }
    if (target.id === 'bulk-all') {
      state.visible.forEach(id => toggle(id, target.checked));
      syncCheckboxes();
      renderBar();
      return;
    }
    if (target.id === 'bulk-field') { state.field = target.value; state.value = ''; renderBar(); return; }
    if (target.id === 'bulk-value') state.value = target.value;
  });
  document.addEventListener('input', event => { if (event.target.id === 'bulk-value') state.value = event.target.value; });
  document.addEventListener('click', event => {
    if (event.target.id === 'bulk-apply') apply();
    if (event.target.id === 'bulk-clear') { state.selected.clear(); syncCheckboxes(); renderBar(); }
  });

  return {checkbox, update, enabled};
})();
